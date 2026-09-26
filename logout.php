<?php
/**
 * 退出登录
 *
 * 前台 / 后台各清各的会话，互不干扰；
 * 退出后换发会话 ID，旧 cookie 立即失效（防会话固定）。
 */

require_once __DIR__ . '/includes/auth.php';
sessionStart();

// 后台退出：只清后台登录态，不波及前台"访问密码"会话
if (isset($_GET['admin'])) {
    unset($_SESSION[SESS_ADMIN_OK], $_SESSION['admin_fp']);
    session_regenerate_id(true);
    header('Location: admin.php?logout=1');
    exit;
}

// 前台退出：清访问密码凭证
unset($_SESSION[SESS_ACCESS_OK]);
session_regenerate_id(true);
header('Location: login.php');
exit;
