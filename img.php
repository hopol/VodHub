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
//
// ⚠⚠ **它必须跟随 APP_VERSION，不能自己写一个版本号。**
//
//   1.3.13 之前这里是写死的 '1.2.0' —— 而真实版本早已到 1.3.10，
//   于是这个「确认机制」**确认的是 1.2.0 那个版本**：
//   站长 curl 一下看到 `X-Img-Proxy: 1.2.0`，无论传的是哪一版，
//   得到的都是同一个答案。**这个头等于没有信息量。**
//
//   本项目过去 12 次里有 8 次根因是「两个地方各写各的版本号，忘了同步」，
//   所以这里从根上消掉第二个来源 ——
//   少一个能漂移的地方，就少一类「A 改了 B 忘了」的事故。
//
// ⚠ 兜底：如果 config.php 是旧版（漏传），APP_VERSION 未定义，
//   仍然给一个值而不是让这个头空掉 —— 它是诊断信息，空掉就没有诊断价值了。
//   用 APP_NAME 之外的常量名做判据是因为 config.php 漏传时其它常量也一起没了。
defined('APP_VERSION') || define('APP_VERSION', '未知（config.php 可能是旧版）');
define('IMG_PROXY_VER', APP_VERSION);

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

// ★ 1.5.6：端口白名单（原始 URL）
imgAssertPort($parts['port'] ?? false, '');

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

// --- 1.5 本地已有副本 → 直接回吐，不做 DNS、不出站 ---
// ⚠⚠ **本地命中必须排在白名单校验之前（1.5.6 修）**：
//
//   这张图此前必然通过了下面完整的三重校验（文件只能由通过校验的 URL 生成），
//   且这里**不发起任何新请求**，所以即使域名此后被改指向内网也不会造成 SSRF ——
//   只是回吐已经下载好的字节。这是支柱二在 img.php 这一侧的收口。
//
//   原来白名单校验在它**前面**，于是出现一个隐蔽的永久故障：
//     ① 上游封面 URL 被 302 到**另一个域名**（CDN 切域很常见）；
//     ② 首次请求白名单为空 → 放行 → 取图 → 落盘 →
//        `learnImgHost()` 把**重定向后的域名**写进白名单；
//     ③ 之后每次请求同一张图，先拿**原始 host** 对白名单校验 ——
//        原始 host 不在白名单里 → **403**；
//     ④ 即使图已经躺在本地缓存里，也走不到本地命中分支（白名单在前面）。
//
//   结果：开着图片代理的源，封面从「正常」变成**稳定 403**，后台看不出原因。
//   **已经通过校验落盘的字节，不需要重新授权。**
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
/**
 * 端口白名单（1.5.6）：只允许 80 / 443 / 未指定。
 *
 * ⚠ 原来**只校验 host 是否公网，不看端口**，于是
 *   `http://公网IP:6379/` 这类可以被当作**盲 SSRF 探针**（公网 IP 上的非 HTTP 端口）。
 *   内网地址那层拦住了 `127.0.0.1:6379`，但公网 IP 的任意端口是敞开的。
 *
 *   校验位置：原始 URL 与**每一跳重定向**都查（与白名单/内网校验同一套节奏）。
 *
 * @param string|false $port parse_url 的 port（未指定时为 false）
 * @param string       $why  失败时写进消息的原因前缀
 */
function imgAssertPort($port, string $why = ''): void {
    if ($port === false || $port === null || $port === '') {
        return;   // 未指定端口 → 默认 80/443，放行
    }
    $p = (int) $port;
    if ($p !== 80 && $p !== 443) {
        imgFail(403, ($why !== '' ? $why . ' ' : '')
            . 'port not allowed (' . $p . ') — 只允许 80 / 443');
    }
}

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
    imgAssertPort($p['port'] ?? false, 'redirect hop ' . $hop . ':');   // 1.5.6：每跳也查端口
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

// --- 2. 内网 / 保留地址拦截（白名单被误配的兜底） ---
// ⚠ 1.3.4：这段已抽成 imgAssertPublicHost()，**每跳重定向都要重跑一次**。
//   原因见该函数说明 —— 校验与执行之间隔着 attacker 可控的 302。
imgAssertPublicHost($host);

// --- 2.5 重定向上限 ---
const IMG_MAX_REDIRS = 3;

// --- 2.6 单张图片体积上限（1.5.6）---
//
// ⚠⚠ 原来**完全不看大小**：`CURLOPT_RETURNTRANSFER` 整体读进内存、
//   也不判 Content-Length 就落盘。于是一个 200 MB 的「图片」可以直接
//   吃掉 1 GB 配额的一半 / 触发内存耗尽，而 GC 5 分钟才跑一次。
//
//   两道防线：
//     ① `Content-Length` 超限 → 直接 413（不下载）；
//     ② 下载过程中累加，超限即中止（应对 chunked / 伪造 Content-Length）。
//   封面图正常在 100 KB 量级，5 MB 已经是极宽松的上限。
const IMG_MAX_BYTES = 5 * 1024 * 1024;

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
// ★ 1.5.6：整条重定向链的**总时长预算**。
//   原来每跳各自 4s、最多 3 跳 → 最坏 16s 占住 EP 槽位
//   （网关 3s 的主机会直接 502）。现在把总时长锁在 8s 内。
$deadline  = microtime(true) + 8.0;

do {
    $referer = $fetchUrl === $src ? ($scheme . '://' . $host . '/')
                                 : (parse_url($fetchUrl, PHP_URL_SCHEME) . '://'
                                    . (parse_url($fetchUrl, PHP_URL_HOST) ?: $fetchHost) . '/');

    // ★ 1.5.6：**把已校验的 IP 钉死**（防 DNS 重绑定）。
    //
    //   原来 `imgAssertPublicHost()` 用 `gethostbynamel()` 解析校验后，
    //   curl 又**重新解析一次**——中间那点时间里 DNS 可以改成 127.0.0.1
    //   （TOCTOU）：校验的是公网 IP，执行的是内网 IP。
    //
    //   修法不是「关掉 DNS」（做不到），而是让 curl 用**校验过的那个 IP**：
    //   `CURLOPT_RESOLVE` 把 host:port:ip 钉死，校验与执行共用一份解析结果。
    //   `imgAssertPublicHost()` 已经把全部 IP 都校验过了，这里取第一个。
    $pinIp = '';
    if (!filter_var($fetchHost, FILTER_VALIDATE_IP)) {
        $ips = function_exists('gethostbynamel') ? (@gethostbynamel($fetchHost) ?: []) : [];
        $pinIp = (string) ($ips[0] ?? '');
    }

    $ch = curl_init();
    $curlOpts = [
        CURLOPT_URL            => $fetchUrl,
        CURLOPT_RETURNTRANSFER => true,
        // 支柱四：12 s → 4 s。图片请求是全站最高频的，慢 8 秒换不到任何好处，
        // 只会白占 EP 槽位（EP 是并发计数，槽位被占越久越容易触顶）。
        CURLOPT_TIMEOUT        => 4,
        // ★ 1.5.6：体积上限（见 IMG_MAX_BYTES 说明）
        CURLOPT_MAXFILESIZE    => IMG_MAX_BYTES,
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
    ];
    // DNS 钉死：host:port:ip（仅在域名且解析得到 IP 时）
    if ($pinIp !== '') {
        $port = (int) (parse_url($fetchUrl, PHP_URL_PORT) ?: (str_starts_with($fetchUrl, 'https:') ? 443 : 80));
        $curlOpts[CURLOPT_RESOLVE] = [$fetchHost . ':' . $port . ':' . $pinIp];
    }
    curl_setopt_array($ch, $curlOpts);
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
        if (microtime(true) > $deadline) {
            imgFail(502, 'redirect chain exceeded total budget');
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

// ★ 1.5.6：下载完再验一次体积（chunked 响应 / 伪造 Content-Length 都能绕过预检）
if (strlen((string) $body) > IMG_MAX_BYTES) {
    imgFail(413, 'image too large (> ' . IMG_MAX_BYTES . ' bytes)');
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
//
// ⚠ 1.5.6：**原始 host 也要记**（纵深，与上面「本地命中提前」配套）。
//   只记重定向后的 host，会让原始 host 在白名单变非空后被挡（见上文 ①~④）。
//   两个都记，即使将来有人把「本地命中」的位置改回去，也不会立刻复发。
learnImgHost((int) $source['id'], $host);
if ($fetchUrl !== $src) {
    // 重定向发生过：原始 host 也该在白名单里
    learnImgHost((int) $source['id'], strtolower((string) parse_url($src, PHP_URL_HOST)));
}

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
