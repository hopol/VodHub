<?php
/**
 * default 模板 - 访问密码登录页
 * 变量：$siteTitle, $error, $redirect, $csrfToken
 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>访问验证 - <?= h($siteTitle) ?></title>
    <link rel="stylesheet" href="static/style.css">
</head>
<body class="login-body">
    <form class="login-card" method="post" action="login.php">
        <h1>🔒 访问验证</h1>
        <p class="login-sub">本站为私人站点，请输入访问密码</p>
        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= h($error) ?></div>
        <?php endif; ?>
        <label class="field">
            <span>访问密码</span>
            <input type="password" name="password" autofocus required placeholder="请输入访问密码">
        </label>
        <input type="hidden" name="redirect" value="<?= h($redirect) ?>">
        <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
        <button class="btn btn-primary btn-block" type="submit">进入站点</button>
    </form>
</body>
</html>
