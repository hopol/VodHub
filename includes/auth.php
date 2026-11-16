<?php
/**
 * 鉴权层：前台访问密码 + 后台登录 + CSRF 令牌
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/guard.php';   // 护栏（磁盘 GC + 限流），见 includes/guard.php

function sessionStart(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        return;   // 已经在跑，直接用
    }
    // ---- 头已发出就起不来了，硬调只会往日志里灌 Warning ----
    //
    // 触发路径：requireAdmin()/requireAccess() 在鉴权通过后调 sessionRelease()
    // （放锁，别把锁拖过整页渲染与上游 curl），于是 session_status() 回到 NONE；
    // 紧接着页面开始输出，而模板里的 csrfToken() 又要 sessionStart() ——
    // 后台一个页面有十来个 <input name="csrf">，**一次请求就是 20 行 Warning**。
    //
    // 这不是「可有可无的噪音」：
    //   · 错误日志被 guardGcLog() 截断在 2 MB —— 这种刷屏会让日志反复被截，
    //     真正的故障反而出现在被冲掉的那一段里；
    //   · 开着 display_errors 时（VODHUB_DEBUG=1 排障），这些字节会**直接混进
    //     HTML**，把页面冲坏。
    //
    // 此时 $_SESSION 里是 release 之前留下的副本，**读它完全够用**；
    // 真正需要写入的 CSRF 令牌已由 requireAccess()/requireAdmin()
    // 在放锁之前调 csrfToken() 落盘（见那两个函数），所以渲染阶段不再需要写。
    if (headers_sent()) {
        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];   // 本请求从未成功开过会话，给个空数组避免后面读到 undefined
        }
        return;
    }
    // ---- Cookie 安全属性（1.3.4 起）----
    //
    // 此前 session_start() 前什么都没设，三项属性全缺：
    //   · HttpOnly 缺 → JS 可读会话 ID。任何 XSS（含 4.3 那个 SVG 落盘面）
    //                   都能直接偷走会话 —— 单修 SVG 不够，这一条必须一起修。
    //   · SameSite 缺 → 依赖 PHP 默认（""），不阻止跨站发送，
    //                   于是 verifyCsrf() 那道令牌校验少了一层纵深。
    //   · Secure 缺   → HTTP 访问时 Cookie 明文传输。
    //
    // ⚠ **secure 绝不能写死 true**：本项目大量部署在纯 HTTP 的免费主机上，
    //   写死会导致 Cookie 发不出去、**登录完全失效**（表现为「密码对但进不去」，
    //   极难查）。必须按当前请求动态判断，这正是本项目反复强调的
    //   「免费主机什么都可能」。
    //
    // ⚠ 必须放在 session_start() **之前** —— 之后设置无效。
    // ★ 1.5.6：拒绝「被塞进来的会话 ID」（会话固定攻击）。
    //   PHP 默认 `session.use_strict_mode=0` —— 会接受攻击者预设的 SID。
    //   `session_regenerate_id()` 在登录时能兜住一部分，但严格模式是根治：
    //   未由本站生成的 SID 会被丢弃、换发新的。必须在 session_start() 之前设。
    @ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'httponly' => true,                                   // 防 JS 读取会话 ID
        'samesite' => 'Lax',                                  // 防跨站发送；POST 天然被阻断
        'secure'   => vhIsHttpsRequest(),                  // 仅 HTTPS 下加
        'path'     => '/',
    ]);
    session_name(SESSION_NAME);
    session_start();
}

/**
 * 当前请求是否走 HTTPS。
 *
 * 逐项判断而不是只看 $_SERVER['HTTPS']：
 *   · 有的主机把它置成 'off'；
 *   · 反向代理（Nginx / openresty / Cloudflare）会在
 *     X-Forwarded-Proto 里透传真实协议 —— 免费主机的 openresty 前置很常见。
 */
function vhIsHttpsRequest(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    if (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) {
        return true;
    }
    // 反向代理透传：只在明确写着 https 时才认，避免伪造头把 Cookie 降级成不加密
    $xfp = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    if ($xfp !== '' && explode(',', $xfp)[0] === 'https') {
        return true;
    }
    return false;
}

/**
 * 释放会话锁。
 *
 * PHP 的 files 处理器会对会话文件加**排他锁**直到脚本结束，
 * 包括持有到上游 curl 返回（最坏 12 秒）之后。同会话的第二个请求会被
 * 串行阻塞 —— 而阻塞期间它照样算一个 EP。
 *
 * 鉴权判定完成后立刻关锁，锁持有时长从「整页」缩到约 1 ms。
 * $_SESSION 数组在关闭后仍然可读，所以后续逻辑不受影响；
 * POST/CSRF 路径若再需要写会话，csrfToken()/verifyCsrf() 会重新打开。
 */
function sessionRelease(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
}

/**
 * 前台是否已通过访问密码校验。
 *
 * ★ 1.5.6：与后台同构，加**会话指纹**（= 访问密码哈希）。
 *   原来只看 `$_SESSION[SESS_ACCESS_OK]` 这个布尔值，于是
 *   **改访问密码、或「关闭再启用」之后，所有旧会话继续有效**
 *   （最长到 `session.gc_maxlifetime=86400`，即 1 天）。
 *   后台对管理密码早就做了指纹绑定，前台这层一直缺。
 *
 * ★ 1.5.6：**先判设置再开会话**（性能，见下方说明）。
 *   原来是「先 sessionStart() 再判断是否启用访问密码」，于是
 *   公开站点也给**每个匿名访客**建会话文件 + 发 Set-Cookie。
 *   前台没有任何页面用 `$_SESSION`（已 grep 确认，只有 login/admin/logout 用），
 *   这是纯开销，也让「页面静态化 0 EP」在缓存未命中时打了折扣。
 */
function isAccessOk(): bool {
    if (setting('access_enabled') !== '1') {
        return true; // 未启用访问密码，直接放行（**不开会话**）
    }
    sessionStart();
    if (empty($_SESSION[SESS_ACCESS_OK])) {
        return false;
    }
    // 指纹必须与当前访问密码哈希一致，否则是旧会话（改过密码/重开过保护）→ 作废
    $fp = (string) ($_SESSION['access_fp'] ?? '');
    if ($fp === '' || !hash_equals((string) setting('access_password'), $fp)) {
        unset($_SESSION[SESS_ACCESS_OK], $_SESSION['access_fp']);
        return false;
    }
    return true;
}

/** 前台访问拦截：未通过则跳转登录页 */
function requireAccess(): void {
    guardTick();          // 顺带做磁盘 GC（内部按 5 分钟节流）
    if (isAccessOk()) {
        // ★ 1.5.6：CSRF 令牌**只有开了访问密码才需要**（登录页是前台唯一用 CSRF 的地方）。
        //   原来无条件 csrfToken() → 每个匿名访客都建会话文件，纯开销。
        if (setting('access_enabled') === '1') {
            csrfToken();  // ★ 放锁前先把 CSRF 落盘 —— 渲染阶段不会再写会话
        }
        sessionRelease(); // 鉴权通过 → 立刻放锁，别把锁拖到渲染与上游结束
        return;
    }
    $here = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: login.php?redirect=' . $here);
    exit;
}

/**
 * 后台是否已登录
 *
 * 除标记外还校验「会话指纹」= 登录时的管理密码哈希。
 * 这样在以下情况会话会立即失效，避免 cookie 残留导致"重装后免密进后台"：
 *   - 重新安装（数据库重建、管理密码哈希变化）
 *   - 后台修改了管理密码（所有旧会话同时作废）
 */
function isAdminOk(): bool {
    sessionStart();
    if (empty($_SESSION[SESS_ADMIN_OK])) {
        return false;
    }
    $fp = (string) ($_SESSION['admin_fp'] ?? '');
    if ($fp === '' || !hash_equals((string) setting('admin_password'), $fp)) {
        // 指纹不匹配：密码已变更或这是旧安装残留的会话，直接作废
        unset($_SESSION[SESS_ADMIN_OK], $_SESSION['admin_fp']);
        return false;
    }
    return true;
}

/** 后台访问拦截：未登录则显示登录表单 */
function requireAdmin(): void {
    guardTick();
    if (isAdminOk()) {
        csrfToken();      // ★ 放锁前先把 CSRF 落盘 —— 否则渲染阶段那句
                          //   sessionStart() 会在「头已发」的情况下静默失败，
                          //   令牌只存在于内存里，下一次 POST 校验就会「表单已过期」
        sessionRelease(); // 后台页面渲染本身很重，更不该全程持锁
        return;
    }
    renderAdminLogin();   // 登录表单要 CSRF，这条路径不放锁
    exit;
}

/**
 * 生成 / 校验 CSRF 令牌。
 *
 * ★ 1.5.6：**生成后缓存在进程里，渲染阶段不再 `session_start()`**。
 *
 *   `requireAdmin()` 在放锁（`sessionRelease()`）之后，后台第一个
 *   `<input name="csrf">` 就调 `csrfToken()` → `session_start()` →
 *   **锁被重新拿回来并一直持有到脚本结束**（包括全部 DB 查询与文件读取）。
 *   注释里那句「渲染阶段不再需要写会话」只对前台成立 —— 后台 5 个视图里
 *   有 10+ 个 `csrfToken()`，每个都会把锁拉回来。
 *
 *   现在：令牌生成后存进静态变量，后续调用直接返回，不再碰会话。
 *   `sessionRelease()` 之后 `$_SESSION` 仍可读，所以也不需要重开。
 */
function csrfToken(): string {
    static $cached = '';
    if ($cached !== '') {
        return $cached;
    }
    // 会话已经关掉时，直接读 $_SESSION（释放后仍可读）—— 不重开、不占锁
    if (session_status() !== PHP_SESSION_ACTIVE && !empty($_SESSION[SESS_CSRF])) {
        return $cached = (string) $_SESSION[SESS_CSRF];
    }
    sessionStart();
    if (empty($_SESSION[SESS_CSRF])) {
        $_SESSION[SESS_CSRF] = bin2hex(random_bytes(16));
    }
    return $cached = (string) $_SESSION[SESS_CSRF];
}

function verifyCsrf(?string $token): bool {
    sessionStart();
    return !empty($token)
        && !empty($_SESSION[SESS_CSRF])
        && hash_equals($_SESSION[SESS_CSRF], (string) $token);
}

/**
 * 管理密码是否仍是出厂默认值（`admin123`）。
 *
 * 1.5.6 加：后台第一屏要能一眼看出这件事 —— 否则「改了密码」和「没改」
 * 在界面上完全一样，而攻击者只需试一个密码。
 *
 * @return bool true = 仍是 admin123，需要告警
 */
function isAdminPasswordDefault(): bool {
    if (!function_exists('setting')) {
        return false;
    }
    $hash = (string) setting('admin_password');
    return $hash !== '' && password_verify('admin123', $hash);
}

/** 后台登录表单（未登录时直接渲染，不再额外建文件） */
function renderAdminLogin(): void {
    $error  = $GLOBALS['admin_login_error'] ?? '';
    $notice = $GLOBALS['admin_login_notice'] ?? '';
    ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>后台登录 - <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="static/style.css">
</head>
<body class="login-body">
    <form class="login-card" method="post" action="admin.php">
        <h1>后台管理</h1>
        <?php if ($error !== ''): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php elseif ($notice !== ''): ?>
            <div class="alert"><?= h($notice) ?></div>
        <?php endif; ?>
        <label class="field">
            <span>管理密码</span>
            <input type="password" name="admin_password" autofocus required
                   placeholder="请输入后台管理密码">
        </label>
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
        <button class="btn btn-primary btn-block" type="submit">登录</button>
        <?php
        // ★ 1.5.6：默认密码提示**只在仍是出厂值时**显示。
        //   原来永远显示「默认密码 admin123」——对没改过的人是提示攻击面，
        //   对改过的人是误导（还让人以为「是不是没保存」）。
        $vhStillDefault = function_exists('setting')
            && password_verify('admin123', (string) setting('admin_password'));
        ?>
        <?php if ($vhStillDefault): ?>
            <p class="login-hint">默认密码 admin123，登录后请立即修改。</p>
        <?php endif; ?>
    </form>
</body>
</html>
    <?php
}
