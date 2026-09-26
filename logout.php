<?php
/**
 * 退出登录
 */

require_once __DIR__ . '/includes/auth.php';
sessionStart();

// 清除访问凭证
unset($_SESSION[SESS_ACCESS_OK]);

// 若来自后台，则同时清除后台登录态
if (isset($_GET['admin'])) {
    unset($_SESSION[SESS_ADMIN_OK]);
    header('Location: admin.php');
    exit;
}

header('Location: login.php');
exit;
