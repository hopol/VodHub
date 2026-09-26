<?php
/**
 * 前台登录页：访问密码校验
 *
 * 登录页的模板由站点默认模板决定（与数据源无关）
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/template.php';

// 未启用访问密码，直接进首页
if (setting('access_enabled') !== '1') {
    header('Location: index.php');
    exit;
}

sessionStart();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $password = (string) ($_POST['password'] ?? '');
    $redirect = (string) ($_POST['redirect'] ?? 'index.php');
    $hash = setting('access_password');

    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $error = '表单已过期，请重试';
    } elseif ($hash === '') {
        // 密码为空视为放行（兜底，正常不会出现）
        $_SESSION[SESS_ACCESS_OK] = true;
        header('Location: ' . $redirect);
        exit;
    } elseif (password_verify($password, $hash)) {
        $_SESSION[SESS_ACCESS_OK] = true;
        session_regenerate_id(true);
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = '密码错误，请重试';
    }
}

$redirect = $_GET['redirect'] ?? 'index.php';
$siteTitle = setting('site_title', APP_NAME);

renderTemplate('login', [
    'siteTitle'  => $siteTitle,
    'pageTitle'  => '访问验证',
    'error'      => $error,
    'redirect'   => $redirect,
    'csrfToken'  => csrfToken(),
    'sources'    => getSources(true),
    'source'     => null,
    'sourceId'   => 0,
    'currentSourceId' => 0,
]);
