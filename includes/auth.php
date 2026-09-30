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
    session_name(SESSION_NAME);
    session_start();
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

/** 前台是否已通过访问密码校验 */
function isAccessOk(): bool {
    sessionStart();
    if (setting('access_enabled') !== '1') {
        return true; // 未启用访问密码，直接放行
    }
    return !empty($_SESSION[SESS_ACCESS_OK]);
}

/** 前台访问拦截：未通过则跳转登录页 */
function requireAccess(): void {
    guardTick();          // 顺带做磁盘 GC（内部按 5 分钟节流）
    if (isAccessOk()) {
        csrfToken();      // ★ 放锁前先把 CSRF 落盘 —— 渲染阶段不会再写会话
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

/** 生成 / 校验 CSRF 令牌 */
function csrfToken(): string {
    sessionStart();
    if (empty($_SESSION[SESS_CSRF])) {
        $_SESSION[SESS_CSRF] = bin2hex(random_bytes(16));
    }
    return $_SESSION[SESS_CSRF];
}

function verifyCsrf(?string $token): bool {
    sessionStart();
    return !empty($token)
        && !empty($_SESSION[SESS_CSRF])
        && hash_equals($_SESSION[SESS_CSRF], (string) $token);
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
        <p class="login-hint">默认密码 admin123，登录后请立即修改。</p>
    </form>
</body>
</html>
    <?php
}
