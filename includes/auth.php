<?php
/**
 * 鉴权层：前台访问密码 + 后台登录 + CSRF 令牌
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

function sessionStart(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name(SESSION_NAME);
        session_start();
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
    if (isAccessOk()) {
        return;
    }
    $here = urlencode($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: login.php?redirect=' . $here);
    exit;
}

/** 后台是否已登录 */
function isAdminOk(): bool {
    sessionStart();
    return !empty($_SESSION[SESS_ADMIN_OK]);
}

/** 后台访问拦截：未登录则显示登录表单 */
function requireAdmin(): void {
    if (isAdminOk()) {
        return;
    }
    renderAdminLogin();
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
    $error = $GLOBALS['admin_login_error'] ?? '';
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
