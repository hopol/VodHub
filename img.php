<?php
/**
 * 图片代理：解决上游防盗链与跨域加载问题
 *
 * 用法：img.php?u=<urlencode(图片地址)>&s=<数据源ID>
 *
 * 安全设计（三重校验，防 SSRF）：
 *   1. 只允许 http / https 协议
 *   2. 目标主机需命中 img_hosts 白名单——但白名单是【可选】的：
 *      留空 = 不限制主机（靠第 3 层兜底），填了 = 加严白名单
 *   3. 拦截内网 / 回环 / 保留地址（域名也解析后逐个 IP 校验），这层始终生效
 *
 * 自动学习：代理成功后，会把实际用到的域名追加进 img_hosts，
 * 之后后台就能看到"这个源实际用的图片域名是什么"，也便于以后改成加严白名单。
 *
 * 代理请求时按需带上 Referer（部分源站靠它判断防盗链）。
 */

// ---- 前置输出防护：必须放在任何 require 之前 ----
// 文件开头若有 UTF-8 BOM，或 <?php 之前有空行/空格，PHP 会在执行任何代码前
// 先把那些字节当输出吐出去，后果分两种，都会让「接口 200 但图片不显示」：
//   · 开着 output_buffering：垃圾字节留在缓冲里，最后和图片数据一起刷出 →
//     图片头被污染（RIFF 前面多了 \xEF\xBB\xBF 或 \n\n），浏览器解码失败
//   · 没开缓冲：headers 已发送，后面所有 header() 静默失效 →
//     Content-Type 不是 image/*，浏览器按文本处理
// 两种情况都要在发图之前处理掉，否则怎么修上游都白搭。
$lead = (string) @file_get_contents(__FILE__, false, null, 0, 64);
if (!str_starts_with($lead, '<?php') && !str_starts_with($lead, '<?=')) {
    if (headers_sent($hFile, $hLine)) {
        echo 'img.php 开头有多余输出（BOM 或 <?php 前的空白），头信息已全部失效。'
           . '位置：' . $hFile . ':' . $hLine
           . ' —— 请用「UTF-8 无 BOM」重新保存 img.php，并删掉 <?php 前后的空行';
        exit;
    }
    // 此刻缓冲里只有上面那些垃圾（本文件还没 echo 过任何东西），整体丢弃
    while (ob_get_level() > 0) { @ob_end_clean(); }
}

// learnImgHost() / cleanHostList() 定义在 functions.php —— 漏了它，图片抓回来后
// 在自动学习那一行直接 Fatal error，代理 100% 失效（v1.0.0 起就有）。
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

/**
 * 按字面 '&' 切分 QUERY_STRING 取参数。
 *
 * 与 parse_str()/$_GET 的区别：不看 ini 的 arg_separator.input，也不受它影响。
 * 同时容忍从网页源码复制出来的地址 —— 那里的 & 是字面的 &amp;，
 * 所以 key 可能是 amp;s 而不是 s。
 */
function imgQs(string $qs, string $key): string {
    foreach (explode('&', $qs) as $pair) {
        if ($pair === '') {
            continue;
        }
        $eq  = strpos($pair, '=');
        $k   = urldecode($eq === false ? $pair : substr($pair, 0, $eq));
        $v   = $eq === false ? '' : substr($pair, $eq + 1);
        if ($k === $key || $k === 'amp;' . $key) {
            return rawurldecode($v);
        }
    }
    return '';
}

// 部署确认标记：curl -I 看这个头，就能确认你上传的文件真的生效了
// （很多主机会开 OPcache，传完文件不重启仍跑旧代码，是最常见的「传了没用」）
define('IMG_PROXY_VER', '1.2.0');

header('Content-Type: text/plain; charset=utf-8');
header('X-Img-Proxy: ' . IMG_PROXY_VER);

// ---- 自己解析查询串 ----
// 不用 $_GET：PHP 按 ini 的 arg_separator.input 切分查询串，该值因主机而异。
// 实测 arg_separator.input=';' 时，s 会整个丢掉（u 的值里反而带着 &s=1），
// 表现就是老代码那句笼统的 proxy disabled —— 图片全裂却查不出原因。
$qs     = (string) ($_SERVER['QUERY_STRING'] ?? '');
$raw    = imgQs($qs, 'u');
$srcId  = intval(imgQs($qs, 's'));
if ($raw === '' && isset($_GET['u'])) { $raw = (string) $_GET['u']; }  // 兜底

if ($raw === '') {
    http_response_code(400);
    exit('missing url');
}

// 万一 s 被并进了 u 的值（arg_separator.input 异常时会出现），这里再拆出来。
if ($srcId <= 0 && preg_match('/^(.*?)&(?:amp;)?s=(\d+)/', $raw, $m)) {
    $srcId  = intval($m[2]);
    $raw    = $m[1];
}
$src = str_contains($raw, '://') ? $raw : rawurldecode($raw);
$parts = parse_url($src);
if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
    http_response_code(400);
    exit('invalid url');
}

$scheme = strtolower((string) $parts['scheme']);
if (!in_array($scheme, ['http', 'https'], true)) {
    http_response_code(400);
    exit('scheme not allowed');
}

$host = strtolower((string) $parts['host']);

// --- 1. 数据源白名单校验 ---
// 三种失败原因分开报，避免再出现「只知道没开、不知道是缺参数还是真没开」
// —— 同一句 proxy disabled 曾让人分不清是 s 丢了，还是这个源确实没勾选。
if ($srcId <= 0) {
    http_response_code(400);
    exit('missing source param (s)');
}
$source = getSource($srcId);
if (!$source) {
    http_response_code(404);
    exit('source not found (s=' . $srcId . ')');
}
if ((int) ($source['img_proxy'] ?? 0) !== 1) {
    http_response_code(403);
    exit('proxy disabled for source ' . $srcId . ' [' . $source['name'] . ']');
}

$allowed = array_filter(array_map('trim', explode(',', strtolower((string) $source['img_hosts']))));

// 白名单【可选】：留空表示不限制主机（很多人并不知道图片域名是什么）。
// 即便留空，下面第 2 层的内网/保留地址拦截仍然始终生效，SSRF 仍有兜底。
if ($allowed) {
    $hostOk = false;
    foreach ($allowed as $pattern) {
        $pattern = trim($pattern, '. ');
        if ($pattern === '') {
            continue;
        }
        if ($host === $pattern || str_ends_with($host, '.' . $pattern)) {
            $hostOk = true;
            break;
        }
    }
    if (!$hostOk) {
        http_response_code(403);
        exit('host not allowed');
    }
}

// --- 2. 内网 / 保留地址拦截（白名单被误配的兜底） ---
if (filter_var($host, FILTER_VALIDATE_IP)) {
    if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        http_response_code(403);
        exit('private address blocked');
    }
} else {
    // 域名先解析，再校验 IP，防止通过域名解析到内网
    $ips = @gethostbynamel($host);
    if (empty($ips)) {
        http_response_code(502);
        exit('resolve failed');
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            http_response_code(403);
            exit('private address blocked');
        }
    }
}

// --- 3. 拉取图片 ---
$referer = '';
if (!empty($parts['host'])) {
    $referer = $scheme . '://' . $parts['host'] . '/';
}

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $src,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 12,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . 'Chrome/120 Safari/537.36 ImageProxy/1.0',
    CURLOPT_REFERER        => $referer,
]);
// 关键：目标站若按 Referer 校验，这里带上同域 Referer 通常可过
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Referer: ' . $referer,
    'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
]);
$body = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($body === false || $code !== 200 || $body === '') {
    http_response_code(502);
    exit('fetch failed');
}

// 只放行图片类响应，避免被当成内容入口
if ($type !== '' && !str_starts_with($type, 'image/')) {
    http_response_code(415);
    exit('not an image');
}
if ($type === '') {
    $type = 'image/jpeg';
}

// 自动学习：把本次实际用到的图片域名记进白名单，便于后台查看，
// 也便于以后改成"白名单加严"模式。已存在则不动，不影响已有配置。
learnImgHost((int) $source['id'], $host);

// 缓存 7 天：图片基本不变，靠数据源开关控制是否走代理
header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($body));
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');

echo $body;
exit;
