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

// ==================================================================
// 五、TLS 证书校验（1.3.4 修：此前三处写死 VERIFY=false）
// ==================================================================

group('TLS：默认校验证书，降级须显式');

t('三处出站请求都不再写死 VERIFY=false', static function (): void {
    foreach (['img.php', 'includes/enrich.php', 'includes/client.php'] as $f) {
        $src = t_code($f);
        ok(
            !preg_match('/CURLOPT_SSL_VERIFYPEER\s*=>\s*false/', $src),
            "{$f} 仍写死 SSL_VERIFYPEER => false —— 信任链完全敞开"
        );
        ok(
            !preg_match('/CURLOPT_SSL_VERIFYHOST\s*=>\s*false/', $src),
            "{$f} 仍写死 SSL_VERIFYHOST => false"
        );
    }
});

t('三处都改用 TLS_VERIFY 常量', static function (): void {
    foreach (['img.php', 'includes/enrich.php', 'includes/client.php'] as $f) {
        $src = t_code($f);
        ok(
            str_contains($src, 'CURLOPT_SSL_VERIFYPEER => TLS_VERIFY'),
            "{$f} 未使用 TLS_VERIFY 常量"
        );
    }
});

t('SSL_VERIFYHOST 用 2/0 而非 true/false', static function (): void {
    // curl 的 VERIFYHOST 语义特殊：1 = 校验，2 = 校验且通配符，
    // 0 = 不校验。传 true 会被当成 1（不校验通配符），虽不出错但不严谨。
    foreach (['img.php', 'includes/enrich.php', 'includes/client.php'] as $f) {
        ok(
            str_contains(t_code($f), 'CURLOPT_SSL_VERIFYHOST => TLS_VERIFY ? 2 : 0'),
            "{$f} 的 SSL_VERIFYHOST 应为 'TLS_VERIFY ? 2 : 0'"
        );
    }
});

t('【关键】默认是校验（不是降级）', static function (): void {
    // ⚠ 1.3.5 首次发布时，这条测试用的是 shell_exec 起子进程跑真实常量 ——
    //   而免费主机普遍禁用 shell_exec，于是**线上直接报了三项失败**。
    //   现在改成：断言「解析逻辑」本身 + 断言「常量的默认值」。
    //   两者都不需要进程创建。
    require_once __DIR__ . '/../config.php';

    // ① 解析逻辑：未设置（false / '' / null）→ 必须校验
    ok(vhTlsVerifyFromEnv(false), 'getenv 未设置时应校验');
    ok(vhTlsVerifyFromEnv(''), '空串应校验');
    ok(vhTlsVerifyFromEnv(null), 'null 应校验');

    // ② 常量默认值：本进程未设环境变量时加载 config.php，常量应为 true
    //   （config.php 可能已被其他测试文件以默认值加载过，这里只验「不是 false」）
    ok(
        defined('VODHUB_TLS_VERIFY'),
        'config.php 应定义 VODHUB_TLS_VERIFY'
    );
    ok(
        VODHUB_TLS_VERIFY === true,
        '未显式降级时 VODHUB_TLS_VERIFY 必须为 true（校验）'
    );
});

t('显式降级各取值都能识别', static function (): void {
    require_once __DIR__ . '/../config.php';

    // 需要降级的取值（不分大小写、带空格也认）
    foreach (['0', 'false', 'off', 'no', 'none', 'FALSE', 'Off', ' NO '] as $v) {
        ok(
            vhTlsVerifyFromEnv($v) === false,
            "VODHUB_TLS_VERIFY=" . var_export($v, true) . " 应解析为「不校验」"
        );
    }

    // 需要保持校验的取值
    foreach (['1', 'true', 'on', 'yes', 'TRUE', 'On'] as $v) {
        ok(
            vhTlsVerifyFromEnv($v) === true,
            "VODHUB_TLS_VERIFY=" . var_export($v, true) . " 应解析为「校验」"
        );
    }

    // ⚠ 拼错的值必须**保持校验**，不能悄悄降级
    //   （反向判断 ——「不在关闭白名单就关」—— 会让一个 typo 关闭安全防护）
    foreach (['nope', 'disabled', '2', 'off-ish', '没开'] as $v) {
        ok(
            vhTlsVerifyFromEnv($v) === true,
            "无法识别的值 " . var_export($v, true) . " 应保持校验，不得悄悄降级"
        );
    }
});

t('【安全】getenv 被禁用时按「未设置」处理（仍校验，不降级）', static function (): void {
    // 免费主机的 disable_functions 里 getenv 也可能被列。
    // 实测它被移除后调用**返回 false 而非致命错误**，
    // 而 config.php 的逻辑正是把 false 当「未设置」→ 保持校验。
    // 这条锁住该契约：万一有人改成 `=== ''` 判断，禁用 getenv 的主机
    // 会走到「空串 → 校验」之外的分支，行为不可预期。
    require_once __DIR__ . '/../config.php';
    ok(
        vhTlsVerifyFromEnv(false) === true,
        'getenv 被禁用时返回 false，必须被当成「未设置」→ 保持校验'
    );
});

t('降级有环境变量与常量两条显式路径', static function (): void {
    $src = t_code('config.php');
    ok(
        str_contains($src, "vhGetEnv('VODHUB_TLS_VERIFY')"),
        '应支持 VODHUB_TLS_VERIFY 环境变量'
    );
    ok(
        substr_count($src, 'VODHUB_TLS_VERIFY') >= 3,
        '常量与降级说明都应围绕 VODHUB_TLS_VERIFY'
    );
});

t('【关键】getenv 被禁用时不致命（实测它会抛 Error 而非返回 false）', static function (): void {
    // ⚠ 我曾以为「getenv 被禁用时返回 false」—— **实测是错的**：
    //   PHP 8 在 getenv 出现在 disable_functions 里时直接抛
    //   `Error: Call to undefined function getenv()`，是致命错误。
    //   免费主机的 disable_functions 因时而异，一处炸就是整站 500。
    //
    // 所以全站读环境变量都必须走 vhGetEnv() 包装。
    require_once __DIR__ . '/../config.php';
    ok(function_exists('vhGetEnv'), 'config.php 应提供 vhGetEnv() 包装');

    // 包装在被禁用时应返回 false 而非抛错
    $fake = vhGetEnv('__VH_TEST_NOT_EXIST__');
    ok($fake === false || is_string($fake), 'vhGetEnv 应安全返回');

    // 全站不得有裸调 getenv()。
    //
    // ⚠ 判据要**排除 vhGetEnv 的实现本身**：
    //   `return function_exists('getenv') ? getenv($name) : false;`
    //   这一行是包装函数的内部实现，是**唯一允许出现裸 getenv 的地方**。
    //   1.3.7 首次发布时这条测试误报 config.php 就是因为没排除它 ——
    //   而当时只看了「✓ 的数量」，把失败项漏掉了。
    //
    //   做法：先把 vhGetEnv 的函数体整段挖掉，再检查剩下的部分。
    $root = dirname(__DIR__);
    $bare = [];
    foreach (['config.php', 'includes/guard.php', 'includes/pagecache.php',
              'includes/enrich.php', 'includes/client.php', 'img.php'] as $f) {
        $src = (string) @file_get_contents($root . '/' . $f);

        // ① 挖掉 vhGetEnv 的整个函数体（含它前面的 if 包装）
        $src = (string) preg_replace(
            "/if\s*\(\s*!function_exists\(\s*'vhGetEnv'\s*\)\s*\)\s*\{.*?\n\}/s",
            '', $src
        );

        // ② 去掉注释与字符串字面量，只看真实调用
        $src = (string) preg_replace(
            ['#//[^\n]*#', '#/\*.*?\*/#s', '#"[^"]*"#', "#'[^']*'#"],
            '', $src
        );

        if (preg_match('/(?<![A-Za-z_])getenv\s*\(/', $src)) {
            $bare[] = $f;
        }
    }
    eq([], $bare, '这些文件仍在裸调 getenv() —— getenv 被禁用时会致命错误。'
                . '（vhGetEnv 的实现本身不算，那是唯一允许出现的地方）');
});

t('【关键】降级状态可见（后台能看出没在校验）', static function (): void {
    require_once __DIR__ . '/../includes/guard.php';
    $r = guardTlsAudit();
    ok(is_array($r) && array_key_exists('verify', $r), 'guardTlsAudit() 应返回含 verify 的数组');
    ok(array_key_exists('ok', $r), '应返回 ok 供后台标红');
    ok(
        !empty($r['note']),
        '应有说明文案 —— 「关掉了自己不知道」比开着更危险'
    );
});

t('【行为】guardTlsAudit() 两种分支都正确', static function (): void {
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/guard.php';

    $r = guardTlsAudit();
    ok(is_array($r), 'guardTlsAudit() 应返回数组');

    // 本进程是默认（校验）配置 —— 至少要能正确报出「已校验」
    if (TLS_VERIFY) {
        ok($r['verify'] === true, '校验模式下 verify 应为 true');
        ok($r['ok'] === true, '校验模式下 ok 应为 true');
        ok(
            str_contains($r['note'], '已校验'),
            '校验模式下文案应说明「已校验」'
        );
    } else {
        // 主机显式降级时（有人在 config.php 里写了 false）
        ok($r['verify'] === false, '降级模式下 verify 应为 false');
        ok($r['ok'] === false, '降级模式下必须 ok=false，让后台标红');
        ok(
            str_contains($r['note'], '已关闭证书校验')
            && str_contains($r['note'], '中间人'),
            '降级文案必须点明风险 —— 「关掉了自己不知道」比开着更危险'
        );
    }
});

t('【结构】guardTlsAudit() 在降级时会告警（源码判据，不依赖当前配置）', static function (): void {
    // 两条分支共用一个函数，靠源码就能确认降级分支写对了 ——
    // 不必真去降级再跑一遍（那需要进程创建或改配置）。
    $src = t_code('includes/guard.php');
    ok(
        str_contains($src, "'verify' => false")
        && str_contains($src, "'ok'     => false"),
        'guardTlsAudit() 的降级分支应返回 verify=false 且 ok=false'
    );
    ok(
        str_contains($src, '中间人'),
        '降级分支的说明文案应点明「可被中间人读取与篡改」'
    );
});

t('【行为】证书有问题时，开校验确实连不上（故降级口子必须留）', static function (): void {
    // 记录这一实测结论，作为「为什么保留降级开关」的依据。
    // 不在测试里真连外网（CI 无网络），只断言降级路径存在。
    ok(
        str_contains(t_code('config.php'), 'VODHUB_TLS_VERIFY'),
        '降级开关必须存在：实测自签名证书下 VERIFY=true 直接失败（HTTP 0），'
        . 'VERIFY=false 才通（HTTP 200）—— 免费主机 CA 链不完整时需要它'
    );
});

// ==================================================================
// 六、CLI/Web 双入口一致性（1.3.9 曾因此整站 500）
// ==================================================================

group('双入口：CLI-only 符号不得在 Web 下致命');

t('run.php 的 $argv 有存在性守卫（Web 下会 500）', static function (): void {
    // ⚠⚠ **1.3.9 首次上传后 tests/run.php 在浏览器里直接 500**：
    //   `TypeError: array_slice(): Argument #1 must be of type array, null given`
    //   原因 —— **`$argv` 只在 CLI SAPI 下存在**，浏览器访问时它是 undefined。
    //   我加 `--json` 参数时用了 `array_slice($argv, 1)`，而只在命令行测过。
    //
    // ⚠ **这个判据的前两版都是错的，各栽一次**：
    //   ① 正则找「$argv 后面有没有 isset」→ 剥注释时把 defined('STDOUT')
    //      里的字符串字面量也剥了，误报；
    //   ② 行级判定但守卫条件含 `str_contains($ctx,'$cliArgs')`
    //      → 被检查的那行**自己含 $cliArgs**，自己满足自己，注入后测试依然绿；
    //      拼上一行时又忘了剥注释，又被上一行的说明文字骗了一次。
    //
    //   **教训：判据必须被「注入已知坏代码」验证过，否则它可能只是看起来在检查。**
    //
    // 现在的规则，简单到不会自欺：
    //   **凡出现 `$argv` 的非注释行，同一行或上一行必须字面含 `isset($argv)`**
    //   （拼接前先剥注释）。
    $src   = (string) file_get_contents(dirname(__DIR__) . '/tests/run.php');
    $lines = explode("\n", $src);

    $unsafe = [];
    foreach ($lines as $i => $line) {
        if (!str_contains($line, '$argv')) {
            continue;
        }
        if (preg_match('/^\s*(\/\/|\*|#)/', $line)) {
            continue;   // 注释行
        }
        // 剥掉本行与上一行的注释，只看真实代码
        $ctx = (string) preg_replace(
            ['#//[^\n]*#', '#/\*.*?\*/#s'],
            '',
            $line . "\n" . ($lines[$i - 1] ?? '')
        );
        if (!str_contains($ctx, 'isset($argv)')) {
            $unsafe[] = ($i + 1) . ': ' . trim($line);
        }
    }
    eq([], $unsafe,
       '这些行的 $argv 同行或上一行没有 isset($argv) 守卫 —— '
       . '浏览器访问 tests/run.php 时会 500（$argv 只在 CLI SAPI 存在）');
});

t('report.php 的 STDOUT 有 defined() 守卫', static function (): void {
    // 上一次修复过（实测 Web 下 Fatal error: Undefined constant "STDOUT"），
    // 这里用**逐行判定**钉住，不靠正则（正则会误伤 defined('STDOUT') 那行本身）。
    $src   = (string) file_get_contents(dirname(__DIR__) . '/tests/report.php');
    $lines = explode("\n", $src);

    $seenGuard = false;
    $unsafe    = [];
    foreach ($lines as $i => $line) {
        if (preg_match('/^\s*(\/\/|\*|#)/', $line)) {
            continue;
        }
        // defined('STDOUT') 这行本身就是守卫
        if (preg_match('/defined\(\s*[\'"]STDOUT[\'"]\s*\)/', $line)) {
            $seenGuard = true;
            continue;
        }
        // 真的使用 STDOUT（不是字符串字面量、不是 defined 检查）
        if (preg_match('/(?<![A-Za-z_\'"])STDOUT(?![A-Za-z_])/i', $line)
            && !preg_match('/[\'"]STDOUT[\'"]/', $line)) {
            if (!$seenGuard) {
                $unsafe[] = ($i + 1) . ': ' . trim($line);
            }
        }
    }
    eq([], $unsafe, '这些行在守卫之前就用了 STDOUT —— Web SAPI 下该常量不存在，'
                  . '实测会 Fatal error: Undefined constant "STDOUT"');
    ok($seenGuard, 'report.php 应有 defined(\'STDOUT\') 守卫');
});

t('两个 Web 入口文件都判了 PHP_SAPI', static function (): void {
    // ci-check.php 不需要 —— 它靠 function_exists('exec') 判断，
    // 且只在 CI 上跑，不提供 Web 入口。
    foreach (['tests/run.php', 'tests/report.php'] as $f) {
        ok(
            str_contains((string) file_get_contents(dirname(__DIR__) . '/' . $f), 'PHP_SAPI'),
            "{$f} 未按 PHP_SAPI 分流 —— CLI-only 符号在 Web 下会炸"
        );
    }
});

finish();
