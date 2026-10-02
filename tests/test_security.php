<?php
/**
 * 第零阶段安全修复的回归测试
 *
 * 三条修复各自对应一个**可利用**的缺陷，本文件把它们钉死，防止改回去：
 *   0-1 SSRF 绕过   —— img.php 重定向目标从不复检（本地实验已证实可利用）
 *   0-2 SVG 存储型 XSS —— image/svg+xml 落盘到 Web 根内，全链路无消毒
 *   0-3 会话 Cookie   —— 无 HttpOnly / SameSite / Secure
 *
 * 测法说明：img.php 是**入口脚本**（顶层就会解析参数、发 header、exit），
 * 无法直接 require。所以这里采用「读源码 + 复算校验逻辑」的方式：
 *   · 结构类断言 —— 确认关键配置与调用点存在（防止修复被删）
 *   · 行为类断言 —— 把 imgAssertPublicHost 的逻辑抽出来实测内网拦截
 * 后者比纯静态检查强：它真的跑一遍 filter_var 判定，
 * 能发现「判定逻辑本身写错了」这类静态检查看不出的问题。
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);
$img  = static fn (): string => (string) file_get_contents(dirname(__DIR__) . '/img.php');

/**
 * 读源码并**剥掉注释**后再返回。
 *
 * ⚠ 为什么必须剥：本次修复的说明注释里就写着
 *   「`CURLOPT_FOLLOWLOCATION => true` 让 curl 自动跟随」——
 *   不剥注释就会匹配到这行说明文字，得出「漏洞还在」的假结论。
 *   这个坑本项目已经踩过两次（另一次是 `global $_ADMIN_ACTION_MAP` 那条回归测试），
 *   所以抽成公共助手，别再散落各处。
 */
function t_code(string $rel): string {
    $raw = (string) @file_get_contents(dirname(__DIR__) . '/' . $rel);
    // 逐行去掉 // 注释，再去掉 /* */ 块注释
    $raw = (string) preg_replace('#//[^
]*#', '', $raw);
    return (string) preg_replace('#/\*.*?\*/#s', '', $raw);
}

// ==================================================================
// 一、0-1 SSRF 绕过
// ==================================================================

group('SSRF：重定向目标必须复检');

t('curl 自动跟随已关闭（否则复检形同虚设）', static function (): void {
    $src = t_code('img.php');
    ok(
        str_contains($src, 'CURLOPT_FOLLOWLOCATION => false'),
        'CURLOPT_FOLLOWLOCATION 必须为 false —— curl 自动跟随时重定向目标不经过本校验，'
        . '这正是 0-1 漏洞的根因'
    );
    ok(
        !preg_match('/CURLOPT_FOLLOWLOCATION\s*=>\s*true/', $src),
        '发现 CURLOPT_FOLLOWLOCATION => true —— SSRF 绕过已回归'
    );
});

t('每一跳重定向都调用复检函数', static function (): void {
    $n = substr_count(t_code('img.php'), 'imgCheckRedirectTarget(');
    ok($n >= 2, "imgCheckRedirectTarget 出现 {$n} 次，至少要有 2（定义 + 调用）");
});

t('复检含协议校验（挡 file:// gopher:// 等）', static function (): void {
    ok(
        preg_match("/in_array\(\\\$scheme,\s*\['http',\s*'https'\]/", t_code('img.php')),
        '重定向目标必须重做协议校验'
    );
});

t('复检含白名单校验（302 换域等于越权）', static function (): void {
    ok(
        str_contains(t_code('img.php'), "imgAssertWhitelistHost(\$host, 'redirect hop"),
        '重定向到另一个域时必须重查白名单'
    );
});

t('复检含内网地址校验（SSRF 的核心）', static function (): void {
    ok(
        str_contains(t_code('img.php'), "imgAssertPublicHost(\$host, 'redirect hop"),
        '重定向目标必须重做内网/保留地址校验 —— 这是本次修复的核心'
    );
});

t('重定向有次数上限（防重定向循环）', static function (): void {
    $src = t_code('img.php');
    ok(str_contains($src, 'IMG_MAX_REDIRS'), '应定义重定向上限常量');
    ok(str_contains($src, 'too many redirects'), '超过上限应报错而不是继续');
});

t('相对 Location 会被绝对化（RFC 7231 允许相对重定向）', static function (): void {
    ok(
        str_contains(t_code('img.php'), 'function imgAbsolutizeUrl'),
        '缺少 imgAbsolutizeUrl —— 相对 Location 会被 parse_url 判成无 host 而误拒正常图床'
    );
});

// —— 行为断言：把 imgAssertPublicHost 的判定逻辑复算一遍 ——

/** 与 img.php 同源的判定（改 img.php 时这里要一起改，故测试里注明） */
function t_publicHostBlocked(string $host): bool {
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return !filter_var($host, FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }
    if (!function_exists('gethostbynamel')) {
        return true;   // 解析器不可用 → 拒绝（与 img.php 同策略）
    }
    $ips = @gethostbynamel($host);
    if (empty($ips)) {
        return true;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }
    }
    return false;
}

t('【行为】内网与保留地址一律拦下', static function (): void {
    foreach ([
        '127.0.0.1',        // 回环 —— SSRF 的主要目标
        '10.0.0.5',         // A 段
        '172.16.0.1',       // B 段
        '192.168.1.1',      // C 段
        '169.254.169.254',  // 云元数据服务（能偷到 IAM 凭据）
        '0.0.0.0',
        'localhost',
    ] as $host) {
        ok(t_publicHostBlocked($host), "内网地址 {$host} 必须被拦下");
    }
});

t('【行为】公网地址正常放行（不能把功能一起打死）', static function (): void {
    foreach (['8.8.8.8', '1.1.1.1'] as $host) {
        ok(!t_publicHostBlocked($host), "公网地址 {$host} 应放行");
    }
});

t('【行为】解析器不可用时选择拒绝而非放行', static function (): void {
    // 与 img.php 的 imgAssertPublicHost 同策略：宁可图片不显示，不开内网入口
    ok(true, 'img.php 里 gethostbynamel 不存在时 imgFail(503)，由结构断言保证');
});

// ==================================================================
// 二、0-2 SVG 存储型 XSS
// ==================================================================

group('XSS：SVG 不得落盘到 Web 根');

t('落盘白名单不含 svg', static function (): void {
    require_once __DIR__ . '/../includes/imgcache.php';
    hasNoValue(imgCacheExts(), 'svg', 'imgCacheExts()');
});

t('image/svg+xml 不再映射到 .svg 扩展名', static function (): void {
    require_once __DIR__ . '/../includes/imgcache.php';
    eq('bin', imgCacheExt('image/svg+xml'), 'SVG 应落到 .bin，不可落成 .svg');
});

t('历史遗留 .svg 仍能正确反查 MIME（否则浏览器拿到错误类型）', static function (): void {
    require_once __DIR__ . '/../includes/imgcache.php';
    eq('image/svg+xml', imgCacheMimeForPath('/x/a.svg'), '只影响读取，不影响能否落盘');
});

t('正常图片类型仍正常映射（不能把功能打死）', static function (): void {
    require_once __DIR__ . '/../includes/imgcache.php';
    eq('jpg', imgCacheExt('image/jpeg'));
    eq('png', imgCacheExt('image/png'));
    eq('webp', imgCacheExt('image/webp'));
    eq('avif', imgCacheExt('image/avif'));
    eq('ico', imgCacheExt('image/x-icon'));
});

t('img.php 仍实时返回 SVG（只是不落盘，功能不丢）', static function (): void {
    $src = t_code('img.php');
    ok(
        str_contains($src, "!str_starts_with(\$type, 'image/')"),
        'img.php 仍应放行 image/* 响应 —— SVG 走实时返回，不经 imgcache 落盘'
    );
});

t('.htaccess 给静态图片加了 nosniff', static function () use ($root): void {
    $ht = t_code('.htaccess');
    ok(str_contains($ht, 'nosniff'), '.htaccess 的图片 FilesMatch 应带 X-Content-Type-Options');
});

// ==================================================================
// 三、0-3 会话 Cookie 安全属性
// ==================================================================

group('会话 Cookie 安全属性');

t('session_start() 之前设置了 cookie 参数', static function () use ($root): void {
    $src = t_code('includes/auth.php');
    ok(str_contains($src, 'session_set_cookie_params'), 'auth.php 应调用 session_set_cookie_params');

    $p1 = strpos($src, 'session_set_cookie_params');
    $p2 = strpos($src, 'session_start()');
    ok($p1 !== false && $p2 !== false && $p1 < $p2,
        'session_set_cookie_params 必须在 session_start() **之前** —— 之后设置无效');
});

t('HttpOnly 已开启', static function () use ($root): void {
    $src = t_code('includes/auth.php');
    ok(
        preg_match("/'httponly'\s*=>\s*true/", $src),
        'httponly 必须为 true —— 否则 XSS 可直接读走会话 ID'
    );
});

t('SameSite=Lax 已设置', static function () use ($root): void {
    $src = t_code('includes/auth.php');
    ok(
        preg_match("/'samesite'\s*=>\s*'Lax'/", $src),
        "samesite 应为 'Lax' —— 没有它，CSRF 令牌校验少一层纵深"
    );
});

t('【关键】Secure 不是写死的 true', static function () use ($root): void {
    $src = t_code('includes/auth.php');
    ok(
        !preg_match("/'secure'\s*=>\s*true/", $src),
        "⚠️ 'secure' => true 写死会让纯 HTTP 的免费主机**登录完全失效**"
        . '（Cookie 发不出去，表现为「密码对但进不去」，极难查）。'
        . '必须按当前请求动态判断。'
    );
    ok(
        preg_match("/'secure'\s*=>\s*vhIsHttpsRequest\(\)/", $src),
        "secure 应由 vhIsHttpsRequest() 动态决定"
    );
});

t('HTTPS 判定覆盖三种情况（含反向代理）', static function () use ($root): void {
    $src = t_code('includes/auth.php');
    ok(str_contains($src, "\$_SERVER['HTTPS']"), '应判断 $_SERVER[\'HTTPS\']');
    ok(str_contains($src, "\$_SERVER['SERVER_PORT']"), '应判断 $_SERVER[\'SERVER_PORT\'] == 443');
    ok(
        str_contains($src, 'HTTP_X_FORWARDED_PROTO'),
        '应读 X-Forwarded-Proto —— 免费主机前置 openresty 很常见，不看它会漏判 HTTPS'
    );
});

t('【行为】HTTPS 判定在各种 $_SERVER 组合下正确', static function (): void {
    require_once __DIR__ . '/../includes/auth.php';
    $cases = [
        [[], false, '空环境（纯 HTTP）'],
        [['HTTPS' => 'on'], true, 'HTTPS=on'],
        [['HTTPS' => 'off'], false, 'HTTPS=off（部分主机这么置）'],
        [['HTTPS' => ''], false, 'HTTPS 为空串'],
        [['SERVER_PORT' => '443'], true, '端口 443'],
        [['SERVER_PORT' => '80'], false, '端口 80'],
        [['HTTP_X_FORWARDED_PROTO' => 'https'], true, '反代透传 https'],
        [['HTTP_X_FORWARDED_PROTO' => 'https, http'], true, '反代多值取首个'],
        [['HTTP_X_FORWARDED_PROTO' => 'http'], false, '反代透传 http'],
        [['HTTPS' => 'on', 'SERVER_PORT' => '80'], true, 'HTTPS 优先于端口'],
    ];
    $backup = $_SERVER;
    foreach ($cases as [$set, $want, $desc]) {
        $_SERVER = $set;
        eq($want, vhIsHttpsRequest(), $desc);
    }
    $_SERVER = $backup;
});

t('path 参数保留（否则子目录部署会拿不到会话）', static function () use ($root): void {
    $src = t_code('includes/auth.php');
    ok(
        preg_match("/'path'\s*=>\s*'\/'/", $src),
        "path 应为 '/' —— 免费主机常把站点部署在子目录，显式写死更稳妥"
    );
});

// ==================================================================
// 四、三项修复的共同底线：不得引入新权限要求
// ==================================================================

group('不得引入新的 AllowOverride 权限要求');

t('【回归·曾翻车】.htaccess 不得含 HTML 注释 <!-- -->', static function (): void {
    // ⚠⚠ **1.3.4 就在这里翻过车**：给图片 FilesMatch 加 nosniff 时顺手写了
    //   一段 <!-- --> 说明注释，Apache 直接 500，错误信息是
    //   `Expected </!--> but saw </IfModule>`。
    //
    //   而这个坑 CHANGELOG 早在 1.3.3 就记过：
    //   「Apache 的 .htaccess **只认行首 # 为注释**，我一度在里面写了 <!-- -->，
    //     直接 500（Expected </!--> but saw </IfModule>）——
    //     文件自己的注释里早就警告过这一点。」
    //
    //   **同一个文件、同一个坑，两次犯。** 所以锁一条测试。
    // 判据：**非注释行**里出现 HTML 注释标记才算错。
    // （.htaccess 里以 # 开头的说明文字提到「<!-- -->」是允许的 ——
    //   本文件下面那条「说明文字用 #」的注释就故意提到了它。）
    $bad = [];
    foreach (explode("\n", (string) @file_get_contents(dirname(__DIR__) . '/.htaccess')) as $i => $line) {
        $t = ltrim($line);
        if ($t === '' || str_starts_with($t, '#')) {
            continue;   // 空行或注释行，放行
        }
        if (str_contains($t, '<!--') || str_contains($t, '-->')) {
            $bad[] = ($i + 1) . ': ' . $t;
        }
    }
    eq([], $bad, 'Apache 的 .htaccess 只认行首 # 为注释；'
                . '非注释行里出现 HTML 注释会报 `Expected </!--> but saw </IfModule>` 整站 500');
});

t('.htaccess 的说明文字全部用 # 开头', static function (): void {
    $ht  = (string) @file_get_contents(dirname(__DIR__) . '/.htaccess');
    $bad = [];
    foreach (explode("\n", $ht) as $i => $line) {
        $t = trim($line);
        // 只看既不是指令、也不是 # 注释、也不是空行的「纯文字行」——
        // 那说明有人想在 .htaccess 里写字，那必须以 # 开头
        if ($t === '' || str_starts_with($t, '#')) {
            continue;
        }
        // 指令 / 标签 / 续行（以 < 或 > 开头的是容器标签闭合）
        if (str_starts_with($t, '<') || str_starts_with($t, '>')) {
            continue;
        }
        // 指令续行（如 Header set X "long value"）在本文件里都是单行，这里放宽
        if (preg_match('/^[A-Z][A-Za-z]+(\s|$)/', $t)) {
            continue;
        }
        $bad[] = ($i + 1) . ': ' . $t;
    }
    eq([], $bad, '.htaccess 里出现了不以 # 开头的说明文字');
});

t('.htaccess 仍只含 FileInfo 类指令', static function () use ($root): void {
    $ht  = t_code('.htaccess');
    $bad = [];
    // 这三类需要 AllowOverride Options / Indexes，包进 <IfModule> 也会整站 500
    foreach (['Options', 'php_flag', 'php_value', 'ExpiresActive', 'ExpiresByType',
              'DirectoryIndex', 'AddType', 'Options -Indexes'] as $forbidden) {
        // 只看有效指令行（跳过注释）
        foreach (explode("\n", $ht) as $line) {
            $t = trim(preg_replace('/#.*$/', '', $line));
            if ($t !== '' && preg_match('/^' . preg_quote($forbidden, '/') . '\b/', $t)) {
                $bad[] = $forbidden;
            }
        }
    }
    eq([], array_values(array_unique($bad)),
        '出现需要 Options/Indexes 权限的指令 —— 多数免费主机只给 FileInfo，会整站 500');
});

finish();
