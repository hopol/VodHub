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

/**
 * 校验 `redirect` 参数：**只允许站内相对路径**（1.5.6 修开放重定向）。
 *
 * ⚠ 1.5.6 之前 `$_GET['redirect']` / `$_POST['redirect']` 原样进
 *   `header('Location: ...')`，于是
 *   `login.php?redirect=https://evil.com` 让输完访问密码的访客被 302 到攻击者站点，
 *   `//evil.com`（协议相对）同样生效。开站访问密码的站点会把「输密码」
 *   这个动作变成钓鱼链路的收口。
 *
 * ⚠ 同时挡住数组：`?redirect[]=x` 会让 `h($redirect)` 抛 TypeError（见 h() 说明）。
 *
 * 判据（白名单而不是黑名单）：
 *   · 必须是字符串（数组/对象一律回退）
 *   · 不能以 `//` 开头（协议相对）
 *   · 不能含空白、反斜杠、引号、控制字符
 *   · 只允许 `字母 数字 _ - . / ? = & %`，且必须以 `/` 或字母开头
 * 不满足 → 回退 `index.php`（站点首页，永远安全）。
 */
function vhSafeRedirect($raw): string {
    if (!is_string($raw)) {
        return 'index.php';
    }
    $r = trim($raw);
    if ($r === '' || str_starts_with($r, '//') || str_contains($r, '\\')) {
        return 'index.php';
    }
    if (!preg_match('#^(/|[A-Za-z])[A-Za-z0-9_./?=&%~-]*$#', $r)) {
        return 'index.php';
    }
    // 双保险：绝对 URL（带协议）一律拒绝
    if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $r)) {
        return 'index.php';
    }
    return $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ★ 1.5.6：前台访问密码同样不限速就是裸奔（与后台登录同一类问题）
    guardCheck('access_login', 10);
    $password = (string) (is_scalar($_POST['password'] ?? null) ? $_POST['password'] : '');
    $redirect = vhSafeRedirect($_POST['redirect'] ?? 'index.php');
    $hash = setting('access_password');

    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $error = '表单已过期，请重试';
    } elseif ($hash === '') {
        // 密码为空视为放行（兜底，正常不会出现）
        $_SESSION[SESS_ACCESS_OK] = true;
        $_SESSION['access_fp'] = '';            // 空哈希 → 指纹留空（isAccessOk 会按空判）
        session_regenerate_id(true);            // 1.5.6：与另两个分支一致，防会话固定
        header('Location: ' . $redirect);
        exit;
    } elseif (password_verify($password, $hash)) {
        $_SESSION[SESS_ACCESS_OK] = true;
        // ★ 1.5.6：记录会话指纹（= 当前访问密码哈希）。
        //   改密码后旧会话会因指纹不匹配被作废（见 isAccessOk）。
        $_SESSION['access_fp'] = $hash;
        session_regenerate_id(true);
        header('Location: ' . $redirect);
        exit;
    } else {
        $error = '密码错误，请重试';
    }
}

$redirect = vhSafeRedirect($_GET['redirect'] ?? 'index.php');
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
