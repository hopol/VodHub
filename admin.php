<?php
/**
 * 后台管理入口：登录分支 + 数据准备 + 渲染 HTML。
 *
 * 所有 POST 处理逻辑已拆分到 includes/admin-actions.php（2026-09-25 重构）。
 * 本文件职责：
 *   1. 处理后台登录（POST action=login）
 *   2. 鉴权 + CSRF 校验
 *   3. 调用 adminHandlePost() 分发其他操作
 *   4. 准备视图数据
 *   5. 渲染 HTML
 */

// ---------------------------------------------------------------- 致命错误可见化
// 必须在**任何 require 之前**注册 —— 它要接住的恰恰是 require 阶段的失败。
//
// 为什么需要它：生产环境 display_errors=Off（见 includes/guard.php），
// 于是 PHP 致命错误不再打印，**页面会无声无息地截断** —— 后台看起来「正常打开」，
// 但下半部分没了。最典型的后果就是「清理 OPcache 的按钮不见了」，
// 而那个按钮正是「传了文件没变化」时唯一的救场工具。
// 静默 + 命门重合，是最坏的一种故障形态，所以这里把它变成明确提示。
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    if (!in_array((int) $err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;   // 只接管致命错误；Warning/Notice 由正常错误通道处理
    }
    // 头早发了（已经吐出半截 HTML），也不能清缓冲 —— 保留已渲染部分才有诊断上下文。
    // 在末尾追加一条浏览器仍会渲染的提示。
    $esc = static fn (string $x): string => htmlspecialchars($x, ENT_QUOTES, 'UTF-8');
    // 注意：HTML 属性里的双引号必须写成 \" —— 用单引号 PHP 串拼，避免转义踩坑。
    echo "\n"
       . '<div style="margin:24px auto;max-width:760px;padding:18px 20px;border:2px solid #c0392b;'
       . 'border-radius:8px;background:#fff5f5;color:#7b241c;font:14px/1.75 -apple-system,system-ui,sans-serif">'
       . '<div style="font-size:16px;font-weight:700;margin-bottom:8px">⛔ 后台在此处中断：PHP 致命错误</div>'
       . '<code style="display:block;margin:6px 0 10px;padding:8px 10px;background:#fff;'
       . 'border:1px solid #f5c6cb;border-radius:4px;white-space:pre-wrap;word-break:break-all">'
       . $esc((string) $err['message']) . '</code>'
       . '<div>位置：<code>' . $esc((string) $err['file']) . '</code> 第 <b>'
       . (int) $err['line'] . '</b> 行</div>'
       . '<div style="margin-top:10px"><b>最常见原因：升级包没有完整覆盖。</b>1.3.0 新增了 '
       . '<code>includes/guard.php</code>、<code>includes/pagecache.php</code>、<code>includes/imgcache.php</code>，'
       . '并且 <code>config.php</code> 也改过（新增 4 个常量）—— <b>升级包里的文件必须一次传完</b>，漏一个就会断在这里。</div>'
       . '<div style="margin-top:6px">按升级包 <code>升级说明.md</code> 第二节的清单补齐后，先清一次 OPcache 再刷新本页。'
       . '详见升级包内的 <code>升级说明.md</code> 第二节。</div>'
       . '</div>\n';
});

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/template.php';
require_once __DIR__ . '/includes/config-io.php';   // 系统缓存 + 配置导入导出
require_once __DIR__ . '/includes/admin-actions.php';

sessionStart();

// ---------------------------------------------------------------- 处理 POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    // 后台登录（登录表单页也提交到此处）
    if ($action === 'login') {
        $pwd = (string) ($_POST['admin_password'] ?? '');
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            $GLOBALS['admin_login_error'] = '表单已过期，请重试';
        } elseif (password_verify($pwd, setting('admin_password'))) {
            $_SESSION[SESS_ADMIN_OK] = true;
            // 记录登录时的密码指纹：改密码 / 重装后，旧 cookie 会话立即失效
            $_SESSION['admin_fp'] = (string) setting('admin_password');
            session_regenerate_id(true);
            header('Location: admin.php');
            exit;
        } else {
            $GLOBALS['admin_login_error'] = '管理密码错误';
        }
        requireAdmin(); // 渲染登录表单
        exit;
    }









    // 以下操作均需登录
    requireAdmin();

    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $msg = '⚠️ 表单令牌已过期，请重试';
    } else {
        $msg = adminHandlePost();
    }
}

// ---------------------------------------------------------------- 鉴权
// 提示必须在 requireAdmin() 之前设置：未登录时它会直接渲染登录页并退出。
if (isset($_GET['logout'])) {
    $GLOBALS['admin_login_notice'] = '✅ 已安全退出后台登录，请重新输入管理密码';
}

// GET 与 POST 一律需要登录。
// 【严重】此前 requireAdmin() 只写在上面的 POST 分支内，GET 直接落到渲染段，
// 导致任何人打开 admin.php 都能看到完整后台（含全部数据源接口地址、站点设置），
// 且"退出"重定向回来后又渲染出整页，表现为点了没反应。
requireAdmin();

// 配置导出：admin.php?export=1[&secrets=1] → 直接下载 JSON。
// 放在渲染之前 —— header() 一旦有 HTML 输出就失效；鉴权已由上面的 requireAdmin() 挡住。
if (isset($_GET['export'])) {
    configExportDownload(isset($_GET['secrets']) && $_GET['secrets'] !== '');
}

// ---------------------------------------------------------------- 渲染
$allSources = getSources(false);
$allGroups = getGroups();
$accessEnabled = setting('access_enabled') === '1';
$siteTitle = setting('site_title', APP_NAME);
$templates = listTemplates();
$siteTemplate = setting('site_template', 'default');
$editTpl = trim((string) ($_GET['tpl'] ?? $siteTemplate));
if (!tplExists($editTpl)) {
    $editTpl = 'default';
}
$editTplMeta = tplMeta($editTpl);
$editTplVars = $editTplMeta['vars'];
// 后台样式编辑器需要的"可调变量"默认值（来自模板自身的 :root 块），
// 用户已保存的值优先，未保存的用默认值
foreach (tplEditorVars($editTpl) as $varName => $info) {
    if (!isset($editTplVars[$varName])) {
        $editTplVars[$varName] = $info['value'];
    }
}
$msg = $msg ?? '';
// POST 提交后地址栏的 ?edit= / ?editgroup= 会丢失。若不补回，保存成功后
// 表单会跳回「新增」态，看起来像"没保存成功"。这里用提交的 id 维持编辑态。
$postId = 0;
$postGid = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string) ($_POST['action'] ?? '');
    if ($postAction === 'update_source') {
        $postId = intval($_POST['id'] ?? 0);
    } elseif ($postAction === 'update_group') {
        $postGid = intval($_POST['id'] ?? 0);
    }
}
$editId = intval($_GET['edit'] ?? 0);
if ($editId <= 0) { $editId = $postId; }
$editing = $editId > 0 ? getSource($editId) : null;

$editGid = intval($_GET['editgroup'] ?? 0);
if ($editGid <= 0) { $editGid = $postGid; }
$editingGroup = $editGid > 0 ? getGroup($editGid) : null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>后台管理 - <?= h(APP_NAME) ?></title>
    <link rel="stylesheet" href="static/style.css">
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="index.php"><span class="brand-icon">▶</span><span class="brand-text">后台管理</span></a>
        <?php
        // 当前源码版本号 —— 常量来自 config.php 的 APP_VERSION，与 CHANGELOG.md 同步。
        // APP_VERSION 没定义只有一种可能：config.php 是旧版（漏传），
        // 这里必须**如实说版本未知**，而不是显示一个猜的号 —— 这行本身就是个诊断点。
        $verOk = defined('APP_VERSION');
        ?>
        <span class="nav-version<?= $verOk ? '' : ' nav-version-warn' ?>"
              title="当前源码版本（与 CHANGELOG.md 一致）">
            当前 <?= $verOk ? h(APP_VERSION) : '未知' ?><?= $verOk ? '' : ' · config.php 是旧版' ?>
        </span>
        <nav class="topbar-nav">
            <a href="index.php">前台</a>
            <a href="admin.php">数据源</a>
            <a href="admin.php#tab-group">分组</a>
            <a href="admin.php#tab-template">模板</a>
            <a href="admin.php#tab-access">访问设置</a>
            <a href="logout.php?admin=1">退出</a>
        </nav>
    </div>
</header>

<main class="container admin">
    <?php if ($msg !== ''): ?>
        <div class="alert" id="adminMsg"><?= h($msg) ?></div>
    <?php endif; ?>

    <?php
    // ---------------------------------------------------------------- 安全与环境自检
    // （原「.htaccess 自检」的扩展版）
    //
    // 【为什么必须常驻第一屏】
    //   `.htaccess` 是点文件，传输链路上最容易丢：FTP 过滤隐藏文件、zip 解压
    //   跳过点文件、「镜像同步」甚至会把服务器上那份一起删掉 —— 1.3.1 上线当天
    //   连续踩到两次（vodhub.ct.ws 从未传上去；hop.free.je 传上去之后又没了）。
    //   这种事**不能等站长去跑 vp.php 才发现**，所以它必须常驻在后台第一屏。
    //
    // 【相对 1.3.2 的三处升级】
    //   ① 不再无条件要求 `.htaccess` —— 数据目录已迁到 Web 根之外时，
    //      它缺失只是「慢」，不再「泄露」，告警自动降级。
    //   ② 新增**越权指令自检**：`.htaccess` 里混进 `Options` / `php_value` 会让
    //      **整站 500**（那两条需要 AllowOverride Options，多数免费空间只给 FileInfo）。
    //      白屏比「文件丢了」更严重也更难查 —— 站长只会看到一片空白。
    //      ⚠️ `<IfModule>` 救不了它们：它只看模块在不在，不看 AllowOverride。
    //   ③ 新增**会话目录泄露面**：`sess_<ID>` 的文件名就是会话 ID，目录可列 +
    //      文件可下载 = 直接伪造 `vodsite_sid` 进后台，**完全绕过密码**。
    $vhHt   = guardHtAudit();
    $vhSess = guardSessionExposure();
    $vhTls  = guardTlsAudit();      // 出站 TLS 校验状态（1.3.5 加函数，V2 补接线）
    $vhNeedHt = !DATA_DIR_OUTSIDE;   // 数据在 Web 根内 → .htaccess 是唯一防线
    ?>
    <?php if ($vhHt['unsafe']): ?>
    <!-- 最高优先级：这类问题的表现是「整站白屏」，站长根本进不到这一屏 -->
    <div class="alert alert-error" style="line-height:1.7">
        🛑 <b>`.htaccess` 里有 <?= count($vhHt['unsafe']) ?> 条指令会让整站 500（白屏）</b>
        —— 它们需要主机开 <code>AllowOverride Options</code>，而多数免费虚拟空间只给
        <code>FileInfo</code>：<br>
        <?php foreach ($vhHt['unsafe'] as $vhU): ?>
            <?php [$vhLn, $vhNm, $vhWhy] = array_pad(explode('|', (string) $vhU, 3), 3, ''); ?>
            &nbsp;&nbsp;· 第 <b><?= h($vhLn) ?></b> 行 <code><?= h($vhNm) ?></code> —— <?= h($vhWhy) ?><br>
        <?php endforeach; ?>
        <b>处理：</b>把上面这几行**整段删掉**（连同它所在的 <code>&lt;IfModule&gt;</code> 块），
        然后刷新本页。<b>站点功能不受影响</b> —— 它们的职责已经改由不需要任何服务器配置的东西兜底：<br>
        &nbsp;&nbsp;· <code>Options -Indexes</code> → 各目录下的空 <code>index.html</code>
        （<code>DirectoryIndex index.html</code> 是 Apache <b>主配置的默认值</b>，不要求 AllowOverride）<br>
        &nbsp;&nbsp;· <code>php_value</code> / <code>php_flag</code> → <code>includes/guard.php</code>
        的运行时 <code>ini_set()</code>（display_errors / log_errors / error_log，<b>覆盖全部 SAPI</b>）
    </div>
    <?php endif; ?>

    <?php if (!$vhHt['unsafe'] && $vhHt['risky']): ?>
    <div class="alert" style="line-height:1.7;border-left-color:#f0ad4e;background:#fcf8e3">
        🟡 <b>`.htaccess` 里有 <?= count($vhHt['risky']) ?> 条指令需要 <code>AllowOverride Indexes</code></b>
        —— 本机现在是通的（否则你连这一页都看不到），但**换一台只给 <code>FileInfo</code>
        的免费空间就会整站 500**（而 FileInfo 恰恰是 <code>RewriteEngine</code> 必需的那一类）：<br>
        <?php foreach ($vhHt['risky'] as $vhU): ?>
            <?php [$vhLn, $vhNm, $vhWhy] = array_pad(explode('|', (string) $vhU, 3), 3, ''); ?>
            &nbsp;&nbsp;· 第 <b><?= h($vhLn) ?></b> 行 <code><?= h($vhNm) ?></code> —— <?= h($vhWhy) ?><br>
        <?php endforeach; ?>
        <b>处理：</b>删掉这几行即可 —— 静态资源缓存由下方
        <code>&lt;IfModule mod_headers.c&gt;</code> 的 <code>Cache-Control</code> 承担
        （<b>只要 FileInfo</b>，现代浏览器以它为准，实测功能无差别）。
        本项目自带的 <code>.htaccess</code> 已经不含这一段。
    </div>
    <?php endif; ?>

    <?php if ($vhSess['inside']): ?>
    <div class="alert alert-error" style="line-height:1.7">
        🛑 <b>会话目录落在 Web 根之内</b>：<code><?= h($vhSess['path']) ?></code><br>
        <code>sess_&lt;ID&gt;</code> 的<b>文件名就是会话 ID</b> —— 目录能列、文件能下 =
        拿到任意一个就能把 <code><?= h(SESSION_NAME) ?></code> cookie 设成它，
        <b>直接进后台，完全绕过密码</b>。这比 <code>data.db</code> 泄露更直接。<br>
        <b>处理（按顺序试）：</b>
        ① 面板「PHP 设置」里把 <code>session.save_path</code> 改到 Web 根之外（首选）；
        ② 改不了就在 <code>.user.ini</code> 里加一行
        <code>session.save_path = ../vodhub-data/sessions</code>（CGI/FPM 有效，<b>不依赖 .htaccess</b>）；
        ③ 两条都不行 → 至少把会话目录权限设为 700，并在本机外定期改后台密码。
    </div>
    <?php endif; ?>

    <?php if ($vhNeedHt && !$vhHt['rewrite']): ?>
    <div class="alert alert-error" style="line-height:1.7">
        ⚠️ <b>`.htaccess` 不在站点根（或里面没有 rewrite 规则）</b>，
        而数据目录仍在 Web 根之内 —— 三件事同时失效：<br>
        ① <b><code>data.db</code> 现在可以被任何人下载</b>（含管理密码哈希与全部接口地址）；<br>
        ② <code>templates/</code> 下的片段可被直接执行；③ 页面静态直出失效，每一页都要跑 PHP。<br>
        <b>两条出路，任选其一：</b><br>
        &nbsp;&nbsp;<b>A（推荐）</b> —— 把数据目录迁到 Web 根之外，从此不再依赖
        <code>.htaccess</code>：见 <code>docs/deployment.md</code> 的「数据目录」一节；<br>
        &nbsp;&nbsp;<b>B</b> —— 把升级包里的 <code>.htaccess</code> <b>单独上传</b>到站点根，
        上传后回来刷新确认本条消失。<br>
        <b>别用：</b>zip 解压（多数解压器跳过点文件）、镜像/同步上传（本地缺它就会把服务器上那份删掉）、
        「显示隐藏文件」没打开的 FTP。
    </div>
    <?php elseif (!$vhHt['rewrite']): ?>
    <div class="alert" style="line-height:1.7;border-left-color:#f0ad4e;background:#fcf8e3">
        ℹ️ <b>`.htaccess` 缺失或没有 rewrite 规则</b>，但数据目录已在 Web 根之外
        → <b>不会泄露任何东西</b>，只是页面静态直出与静态资源缓存头不生效
        （每页多 1 个 PHP 进程，约 3 ms，功能完全正常）。<br>
        想启用加速就单独上传 <code>.htaccess</code>；不传也能跑。
    </div>
    <?php endif; ?>

    <p class="muted" style="font-size:13px">
        🔍 安全基线 ——
        数据目录 <b><?= DATA_DIR_OUTSIDE ? 'Web 根之外（不依赖 .htaccess）' : 'Web 根内（靠 .htaccess 拦）' ?></b>
        <code style="font-size:11px"><?= h(DATA_DIR) ?></code> ·
        .htaccess
        <b><?php
            echo $vhHt['unsafe'] ? '⛔ 含确定会 500 的指令'
                : ($vhHt['risky'] ? '🟡 含需 Indexes 权限的指令'
                : ($vhHt['verdict'] === 'ok' ? '✅ 正常（仅 FileInfo 类）'
                : ($vhHt['exists'] ? '⚠️ 无 rewrite' : '❌ 文件不存在')));
        ?></b>
        <?= $vhHt['static'] ? '· 静态直出已启用' : '· 静态直出未启用' ?> ·
        会话目录 <b><?= $vhSess['inside'] ? '⛔ Web 根内' : '✅ Web 根外' ?></b>
        <code style="font-size:11px"><?= h($vhSess['path']) ?></code> ·
        目录列表 <b><?= is_file(__DIR__ . '/static/index.html') ? '✅ 已用 index.html 挡住' : '⚠️ 缺 index.html' ?></b> ·
        出站证书 <b><?= $vhTls['verify'] ? '✅ 已校验' : '⛔ 未校验' ?></b>
    </p>

    <?php if (!$vhTls['verify']): ?>
    <div class="alert alert-error">
        <b>⚠️ 当前已关闭上游 HTTPS 证书校验</b><br>
        数据源地址（常含采集密钥）、影片元数据与播放地址的往来流量
        <b>可被中间人读取与篡改</b>。<br>
        恢复方式：删除或改回 <code>config.php</code> 里的
        <code>define('VODHUB_TLS_VERIFY', false);</code>
        （即取消该行注释），然后重启 PHP。
        排障详见 <code>docs/troubleshooting.md</code> 的「出站连接」章节。
    </div>
    <?php endif; ?>

    <!-- ===================== 各区块视图（1.5.3 拆出）===================== -->
    <?php
    // 原来这 5 段（共 852 行 HTML）直接写在 admin.php 里，与上面 160 行逻辑混在一起。
    // 1.5.3 拆出后 admin.php 只剩「逻辑 + 外壳」，区块各自成文件、便于定位。
    //
    // ⚠ **必须在这里用顶层 require、且顺序固定**（详见 includes/admin/view/ 的头注释）：
    //   五个片段共用 admin.php 的作用域，靠**顺序**保证中间变量与拆分前逐字节等价。
    //   改 require 顺序 = 改行为，不要动。
    require __DIR__ . '/includes/admin/view/sources.php';
    require __DIR__ . '/includes/admin/view/groups.php';
    require __DIR__ . '/includes/admin/view/templates.php';
    require __DIR__ . '/includes/admin/view/access.php';
    require __DIR__ . '/includes/admin/view/maintenance.php';
    ?>
</main>

<footer class="footer">
    <p>单用户模式 · 数据存于本机 SQLite · 所有内容来自第三方接口</p>
</footer>
</body>
</html>
