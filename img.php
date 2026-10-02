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
require_once __DIR__ . '/includes/guard.php';      // 磁盘 GC + 按 IP 限流
require_once __DIR__ . '/includes/imgcache.php';   // 本地图片副本（支柱二）

/**
 * 统一错误出口：**所有 4xx/5xx 一律 no-store**。
 *
 * 否则浏览器的启发式缓存、或 .htaccess 里按 Content-Type 生效的 ExpiresByType
 * 可能把故障页「保鲜」30 天 —— 表现就是封面莫名其妙地长期全裂，极难排查。
 */
function imgFail(int $code, string $msg): void {
    http_response_code($code);
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
    }
    exit($msg);
}

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
// 支柱六：按 IP 限流（默认 120 次/分钟，一个列表页 20 张图绰绰有余）。
// 超限返回 429 而不是 503 —— 日志里才能分清「被限流」和「EP 打满」。
guardCheck('img', 120);
// 顺带做磁盘 GC（内部按 5 分钟节流）
guardTick();

$qs     = (string) ($_SERVER['QUERY_STRING'] ?? '');
$raw    = imgQs($qs, 'u');
$srcId  = intval(imgQs($qs, 's'));
if ($raw === '' && isset($_GET['u'])) { $raw = (string) $_GET['u']; }  // 兜底

if ($raw === '') {
    imgFail(400, 'missing url');
}

// 万一 s 被并进了 u 的值（arg_separator.input 异常时会出现），这里再拆出来。
if ($srcId <= 0 && preg_match('/^(.*?)&(?:amp;)?s=(\d+)/', $raw, $m)) {
    $srcId  = intval($m[2]);
    $raw    = $m[1];
}
$src = str_contains($raw, '://') ? $raw : rawurldecode($raw);
$parts = parse_url($src);
if ($parts === false || empty($parts['host']) || empty($parts['scheme'])) {
    imgFail(400, 'invalid url');
}

$scheme = strtolower((string) $parts['scheme']);
if (!in_array($scheme, ['http', 'https'], true)) {
    imgFail(400, 'scheme not allowed');
}

$host = strtolower((string) $parts['host']);

// --- 1. 数据源白名单校验 ---
// 三种失败原因分开报，避免再出现「只知道没开、不知道是缺参数还是真没开」
// —— 同一句 proxy disabled 曾让人分不清是 s 丢了，还是这个源确实没勾选。
if ($srcId <= 0) {
    imgFail(400, 'missing source param (s)');
}
$source = getSource($srcId);
if (!$source) {
    imgFail(404, 'source not found (s=' . $srcId . ')');
}
if ((int) ($source['img_proxy'] ?? 0) !== 1) {
    imgFail(403, 'proxy disabled for source ' . $srcId . ' [' . $source['name'] . ']');
}

// 白名单【可选】：留空表示不限制主机（很多人并不知道图片域名是什么）。
// 即便留空，第 2 层的内网/保留地址拦截仍然始终生效，SSRF 仍有兜底。
// 校验逻辑在 imgAssertWhitelistHost() 里 —— 重定向每跳也要重跑，故已抽出。
$allowed = array_filter(array_map('trim', explode(',', strtolower((string) $source['img_hosts']))));
imgAssertWhitelistHost($host);

/**
 * 断言目标主机解析后**全部**落在公网地址上。
 *
 * ⚠ **为什么要抽成函数、且每跳重定向都要重跑（1.3.4）**：
 *   原来这段校验只对**原始 URL 的 host** 做一次，随后
 *   `CURLOPT_FOLLOWLOCATION => true` 让 curl 自动跟随最多 3 次 302。
 *   **重定向的目标从未被校验** —— 校验的是 A，执行的是 A 后面那个
 *   attacker 完全可控的 B。于是「白名单内的公网域名 → 302 → 127.0.0.1」
 *   这条路畅通无阻：三重校验全部通过（它只看过那个公网域名），
 *   curl 却抓回了内网资源。本地复现实验已证实：内网 canary 命中。
 *
 *   修法不是「关掉跟随」（不少图床用 302 换 CDN 域名，关掉等于打断正常源），
 *   而是**把校验和执行重新绑在一起**：关掉 curl 的自动跟随，
 *   由本文件自己处理每一跳的 Location，并对新目标**重跑全套校验**。
 *
 * @param string $host 已小写的目标主机名或 IP 字面量
 * @param string $why  失败时写进日志的原因前缀（区分第 2 层与重定向中途）
 */
function imgAssertPublicHost(string $host, string $why = ''): void {
    $tag = $why !== '' ? $why . ' ' : '';

    if (filter_var($host, FILTER_VALIDATE_IP)) {
        if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            imgFail(403, $tag . 'private address blocked');
        }
        return;
    }

    // 域名先解析，再校验 IP，防止通过域名解析到内网。
    // gethostbynamel 也可能被 disable_functions 禁用 —— @ 只能抑制 Warning，
    // 抑制不了 Error。禁用时无法做 DNS→IP 校验，宁可**拒绝代理**也不能放行，
    // 否则 SSRF 防护会出现缺口（宁可图片不显示，不可打开内网入口）。
    if (!function_exists('gethostbynamel')) {
        imgFail(503, 'resolver disabled on this host');
    }
    $ips = @gethostbynamel($host);
    if (empty($ips)) {
        imgFail(502, $tag . 'resolve failed');
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            imgFail(403, $tag . 'private address blocked after redirect');
        }
    }
}

/**
 * 解析并校验一个**重定向目标**，返回 [url, host, scheme]。
 *
 * 白名单也一起重查：302 把站点带到另一个域，那已经超出了站长授权的
 * 「图片域名」范围 —— 只查内网地址不够。
 *
 * @return array{0:string,1:string,2:string} [绝对URL, 小写host, 小写scheme]
 */
function imgCheckRedirectTarget(string $location, string $currentUrl, int $hop): array {
    // 相对 Location 要按当前 URL 绝对化（RFC 7231 允许相对重定向）
    $abs = imgAbsolutizeUrl($location, $currentUrl);
    $p   = parse_url($abs);
    if ($p === false || empty($p['host']) || empty($p['scheme'])) {
        imgFail(502, 'redirect hop ' . $hop . ': invalid location');
    }

    $scheme = strtolower((string) $p['scheme']);
    if (!in_array($scheme, ['http', 'https'], true)) {
        imgFail(403, 'redirect hop ' . $hop . ': scheme not allowed (' . $scheme . ')');
    }

    $host = strtolower((string) $p['host']);
    imgAssertWhitelistHost($host, 'redirect hop ' . $hop . ': ');
    imgAssertPublicHost($host, 'redirect hop ' . $hop . ': ');

    return [$abs, $host, $scheme];
}

/** 把可能是相对路径的 Location 绝对化 */
function imgAbsolutizeUrl(string $location, string $base): string {
    $location = trim($location);
    if ($location === '') {
        imgFail(502, 'empty location');
    }
    // 已经是绝对 URL
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location)) {
        return $location;
    }
    $b = parse_url($base);
    if ($b === false || empty($b['scheme']) || empty($b['host'])) {
        imgFail(502, 'cannot resolve relative location');
    }
    $scheme = strtolower((string) $b['scheme']);
    $host   = (string) $b['host'];
    $port   = isset($b['port']) ? ':' . (int) $b['port'] : '';
    $root   = $scheme . '://' . $host . $port . '/';

    if (str_starts_with($location, '//')) {
        return $scheme . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $root . ltrim($location, '/');
    }
    $dir = rtrim(dirname((string) ($b['path'] ?? '/')), '/');
    return $root . ltrim($dir . '/' . $location, '/');
}

/**
 * 白名单校验（抽成函数，供原始请求与每跳重定向共用）。
 *
 * 白名单**可选**：留空 = 不限制主机（很多人并不知道图片域名是什么）。
 * 即便留空，内网/保留地址那层也始终生效，SSRF 仍有兜底。
 */
function imgAssertWhitelistHost(string $host, string $why = ''): void {
    global $source, $allowed;

    // 「留空 = 不限制主机」是项目的有意设计，不在此处报错
    if (!$allowed) {
        return;
    }
    $tag    = $why !== '' ? $why : '';
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
        imgFail(403, $tag . 'host not allowed (' . $host . ')');
    }
}

// --- 1.5 本地已有副本 → 直接回吐，不做 DNS、不出站 ---
// 这张图此前必然通过了下面完整的三重校验（文件只能由通过校验的 URL 生成），
// 且这里**不发起任何新请求**，所以即使域名此后被改指向内网也不会造成 SSRF ——
// 只是回吐已经下载好的字节。这是支柱二在 img.php 这一侧的收口。
$localRel  = imgCacheHit($src);              // 可能带任意已知扩展名，不能只认 exact
$localFile = $localRel !== null ? __DIR__ . '/' . $localRel : '';
if ($localFile !== '' && is_file($localFile)) {
    $localType = imgCacheMimeForPath($localFile);
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: ' . $localType);
    header('Content-Length: ' . (string) filesize($localFile));
    header('Cache-Control: public, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    header('X-Img-Proxy: ' . IMG_PROXY_VER . '-local');
    readfile($localFile);
    exit;
}

// --- 2. 内网 / 保留地址拦截（白名单被误配的兜底） ---
// ⚠ 1.3.4：这段已抽成 imgAssertPublicHost()，**每跳重定向都要重跑一次**。
//   原因见该函数说明 —— 校验与执行之间隔着 attacker 可控的 302。
imgAssertPublicHost($host);

// --- 2.5 重定向上限 ---
const IMG_MAX_REDIRS = 3;

// --- 3. 拉取图片（含**手动**重定向跟随） ---
//
// ⚠ 1.3.4 安全修复：`CURLOPT_FOLLOWLOCATION` 改为 false，改由本文件自己跟随。
//   原因：curl 自动跟随时**重定向目标不经过上面那三重校验**，
//   于是「白名单内的公网域名 → 302 → 内网地址」这条路畅通无阻，
//   内网资源会被原样抓回来（本地复现实验已证实）。
//   关掉自动跟随后，每一跳的 Location 都要过 imgCheckRedirectTarget()：
//   协议 → 白名单 → DNS 解析后逐 IP 校验，三样全过才继续。
//
//   为什么不用「直接关掉跟随」：不少图床用 302 换 CDN 域名，
//   一刀切会把正常源的图也弄挂 —— 那是用可用性换安全，不该由我们单方面决定。
$referer = $scheme . '://' . $host . '/';

$fetchUrl  = $src;
$fetchHost = $host;
$body      = false;
$code      = 0;
$type      = '';
$remaining = IMG_MAX_REDIRS;

do {
    $referer = $fetchUrl === $src ? ($scheme . '://' . $host . '/')
                                 : (parse_url($fetchUrl, PHP_URL_SCHEME) . '://'
                                    . (parse_url($fetchUrl, PHP_URL_HOST) ?: $fetchHost) . '/');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $fetchUrl,
        CURLOPT_RETURNTRANSFER => true,
        // 支柱四：12 s → 4 s。图片请求是全站最高频的，慢 8 秒换不到任何好处，
        // 只会白占 EP 槽位（EP 是并发计数，槽位被占越久越容易触顶）。
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_CONNECTTIMEOUT => 3,
        // ★ 不让 curl 自动跟随：跟随逻辑在上面，逐跳校验过才继续
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADER         => false,
        // 1.3.4：默认校验证书（此前写死 false，信任链完全敞开）。
        // 主机 CA 链不完整时可显式降级 —— 见 config.php 的 VODHUB_TLS_VERIFY。
        CURLOPT_SSL_VERIFYPEER => TLS_VERIFY,
        CURLOPT_SSL_VERIFYHOST => TLS_VERIFY ? 2 : 0,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
            . 'Chrome/120 Safari/537.36 ImageProxy/1.0',
        CURLOPT_REFERER        => $referer,
    ]);
    // 关键：目标站若按 Referer 校验，这里带上同域 Referer 通常可过
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Referer: ' . $referer,
        'Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
    ]);

    $respBody   = curl_exec($ch);
    $code       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type       = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $redirectTo = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);

    if ($code >= 300 && $code < 400 && $redirectTo !== '') {
        if ($remaining <= 0) {
            imgFail(502, 'too many redirects (>' . IMG_MAX_REDIRS . ')');
        }
        $remaining--;
        // ★ 这一行是本次修复的核心：新目标过完三重校验才继续
        [$fetchUrl, $fetchHost] = imgCheckRedirectTarget($redirectTo, $fetchUrl, IMG_MAX_REDIRS - $remaining);
        $body = false;      // 还没拿到图，继续循环
        $code = 0;
        continue;
    }

    $body = $respBody;
    break;
} while (true);

// 重定向链走完后，落盘与「自动学习」用**最终**那个 host ——
// 中途跳过的域名才是图床真正在用的（原始 URL 往往只是 CDN 的入口）
if ($fetchUrl !== $src) {
    $host = $fetchHost;
}

if ($body === false || $code !== 200 || $body === '') {
    imgFail(502, 'fetch failed' . ($code ? ' (http ' . $code . ')' : ''));
}

// 只放行图片类响应，避免被当成内容入口
if ($type !== '' && !str_starts_with($type, 'image/')) {
    imgFail(415, 'not an image');
}
if ($type === '') {
    $type = 'image/jpeg';
}

// 自动学习：把本次实际用到的图片域名记进白名单，便于后台查看，
// 也便于以后改成"白名单加严"模式。已存在则不动，不影响已有配置。
learnImgHost((int) $source['id'], $host);

// 支柱二：把代理结果落盘。下一次 coverUrl() 就会直出 static/imgcache/...，
// 这张图一生只产生这一次 EP。落盘失败（磁盘满/无权限）静默 ——
// 本次照常返回图片字节，功能不受影响，只是没能本地化。
imgCachePut($src, $body, $type);

// 缓存 7 天：图片基本不变，靠数据源开关控制是否走代理
header('Content-Type: ' . $type);
header('Content-Length: ' . strlen($body));
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');

echo $body;
exit;
