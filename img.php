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

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

$raw   = (string) ($_GET['u'] ?? '');
$srcId = intval($_GET['s'] ?? 0);

if ($raw === '') {
    http_response_code(400);
    exit('missing url');
}

// 允许站长在后台贴完整地址，也允许贴已编码地址
$src = urldecode($raw);
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
$source = $srcId > 0 ? getSource($srcId) : null;
if (!$source || (int) ($source['img_proxy'] ?? 0) !== 1) {
    http_response_code(403);
    exit('proxy disabled');
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
