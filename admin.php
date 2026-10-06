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

    <!-- ============================= 数据源管理 ============================= -->
    <section class="admin-card">
        <h2 class="admin-title">📡 数据源管理</h2>
        <p class="muted">
            接口需为苹果 CMS（MacCMS）标准的 <code>provide/vod</code> 格式。
            填写到 <code>at/json/</code> 这一级即可，程序会自动拼接参数。
            <b>关闭的数据源不会在前台展示，但配置保留。</b>
        </p>

        <table class="admin-table">
            <thead>
                <tr>
                    <th>ID</th><th>名称</th><th>接口地址</th>
                    <th>排序</th><th>分组</th><th>模板</th><th>状态</th><th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$allSources): ?>
                    <tr><td colspan="8" class="muted center">暂无数据源，请在下方添加</td></tr>
                <?php else: ?>
                    <?php foreach ($allSources as $s): ?>
                        <?php
                        $sTpl = trim((string) ($s['template'] ?? ''));
                        $sGid = intval($s['group_id'] ?? 0);
                        $sGname = '';
                        if ($sGid > 0) {
                            $g = getGroup($sGid);
                            $sGname = $g ? $g['name'] : '';
                        }
                        ?>
                        <tr>
                            <td><?= intval($s['id']) ?></td>
                            <td><?= h($s['name']) ?></td>
                            <td class="url-cell" title="<?= h($s['api_url']) ?>"><?= h($s['api_url']) ?></td>
                            <td><?= intval($s['sort']) ?></td>
                            <td>
                                <?= $sGname === ''
                                    ? '<span class="muted">未分组</span>'
                                    : h($sGname) ?>
                            </td>
                            <td>
                                <?= $sTpl === ''
                                    ? '<span class="muted">站点默认</span>'
                                    : h(tplMeta($sTpl)['title']) ?>
                            </td>
                            <td>
                                <?php if ($s['enabled']): ?>
                                    <span class="badge badge-ok">启用中</span>
                                <?php else: ?>
                                    <span class="badge badge-off">已禁用</span>
                                <?php endif; ?>
                            </td>
                            <td class="row-actions">
                                <form method="post" action="admin.php">
                                    <input type="hidden" name="action" value="test_source">
                                    <input type="hidden" name="id" value="<?= intval($s['id']) ?>">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <button class="btn btn-ghost btn-xs" type="submit">测试</button>
                                </form>
                                <form method="post" action="admin.php">
                                    <input type="hidden" name="action" value="toggle_source">
                                    <input type="hidden" name="id" value="<?= intval($s['id']) ?>">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <button class="btn <?= $s['enabled'] ? 'btn-danger' : 'btn-primary' ?> btn-xs"
                                            type="submit"
                                            onclick="return confirm('确定<?= $s['enabled'] ? '关闭' : '启用' ?>「<?= h($s['name']) ?>」吗？')">
                                        <?= $s['enabled'] ? '关闭' : '启用' ?>
                                    </button>
                                </form>
                                <form method="post" action="admin.php">
                                    <input type="hidden" name="action" value="detect_img_host">
                                    <input type="hidden" name="id" value="<?= intval($s['id']) ?>">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <button class="btn btn-ghost btn-xs" type="submit"
                                            title="从该源最近的内容里识别出图片域名，写入白名单">识别图域</button>
                                </form>
                                <a class="btn btn-ghost btn-xs"
                                   href="admin.php?edit=<?= intval($s['id']) ?>">编辑</a>
                                <form method="post" action="admin.php"
                                      onsubmit="return confirm('确定删除「<?= h($s['name']) ?>」吗？')">
                                    <input type="hidden" name="action" value="delete_source">
                                    <input type="hidden" name="id" value="<?= intval($s['id']) ?>">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <button class="btn btn-danger btn-xs" type="submit">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <h3 class="admin-subtitle"><?= $editing ? '✏️ 编辑数据源' : '➕ 添加数据源' ?></h3>
        <form class="admin-form" method="post" action="admin.php">
            <input type="hidden" name="action" value="<?= $editing ? 'update_source' : 'add_source' ?>">
            <?php if ($editing): ?>
                <input type="hidden" name="id" value="<?= intval($editing['id']) ?>">
            <?php endif; ?>
            <label class="field">
                <span>名称</span>
                <input type="text" name="name" required maxlength="50"
                       value="<?= h($editing['name'] ?? '') ?>" placeholder="如：站点 A">
            </label>
            <label class="field field-wide">
                <span>接口地址</span>
                <input type="url" name="api_url" required
                       value="<?= h($editing['api_url'] ?? '') ?>"
                       placeholder="https://example.com/api.php/provide/vod/at/json/">
            </label>
            <label class="field">
                <span>排序（越小越靠前）</span>
                <input type="number" name="sort" min="0" max="9999"
                       value="<?= h($editing['sort'] ?? 0) ?>">
            </label>
            <label class="field field-wide">
                <span>备注（可选）</span>
                <input type="text" name="note" maxlength="100"
                       value="<?= h($editing['note'] ?? '') ?>" placeholder="如：备用线路">
            </label>
            <label class="field">
                <span>使用模板</span>
                <select name="template">
                    <option value="">站点默认（<?= h(tplMeta($siteTemplate)['title']) ?>）</option>
                    <?php foreach ($templates as $tName => $tMeta): ?>
                        <option value="<?= h($tName) ?>"
                            <?= ($editing['template'] ?? '') === $tName ? 'selected' : '' ?>>
                            <?= h($tMeta['title']) ?>（<?= $tMeta['cover_mode'] === 'wide' ? '宽图' : '竖图' ?>）
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">
                <span>所属分组</span>
                <select name="group_id">
                    <option value="0">未分组</option>
                    <?php foreach ($allGroups as $g): ?>
                        <option value="<?= intval($g['id']) ?>"
                            <?= intval($editing['group_id'] ?? 0) === intval($g['id']) ? 'selected' : '' ?>>
                            <?= h($g['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field field-check">
                <input type="checkbox" name="img_proxy" value="1"
                    <?= intval($editing['img_proxy'] ?? 0) === 1 ? 'checked' : '' ?>>
                <span>启用图片代理（绕过上游防盗链）</span>
            </label>
            <label class="field field-wide">
                <span>图片域名白名单（可选，逗号分隔）</span>
                <input type="text" name="img_hosts" maxlength="300"
                       value="<?= h($editing['img_hosts'] ?? '') ?>"
                       placeholder="留空 = 不限制域名（不知道图片域名时留空即可）">
                <small class="muted">
                    不知道图片域名也没关系：<b>留空即可使用</b>，内网地址仍会被自动拦截。
                    代理跑通后系统会自动把实际用到的域名记到这里，
                    你也可以在这里手动填写来<b>加严限制</b>。
                </small>
            </label>
            <?php if ($editing): ?>
                <label class="field field-check">
                    <input type="checkbox" name="enabled" value="1"
                        <?= $editing['enabled'] ? 'checked' : '' ?>>
                    <span>启用该数据源</span>
                </label>
            <?php endif; ?>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-primary" type="submit">
                <?= $editing ? '保存修改' : '添加数据源' ?>
            </button>
            <?php if ($editing): ?>
                <a class="btn btn-ghost" href="admin.php">取消编辑</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- ============================= 数据源分组 ============================= -->
    <section class="admin-card" id="tab-group">
        <h2 class="admin-title">🗂 数据源分组</h2>
        <p class="muted">
            源多到 5 个以上时，顶部标签会很挤。给数据源分组（如「影视」「短视频」）后，
            前台会按组归类显示。<b>组名可随时改</b>；删除分组时组内数据源自动归入"未分组"。
            数据源指派分组请在上方「数据源管理」表单里选择「所属分组」。
        </p>

        <?php if ($allGroups): ?>
            <table class="admin-table">
                <thead>
                    <tr><th>ID</th><th>组名</th><th>排序</th><th>组内源数</th><th>操作</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($allGroups as $g): ?>
                        <tr>
                            <td><?= intval($g['id']) ?></td>
                            <td><?= h($g['name']) ?></td>
                            <td><?= intval($g['sort']) ?></td>
                            <td>
                                <?php
                                $cnt = 0;
                                foreach ($allSources as $s) {
                                    if (intval($s['group_id'] ?? 0) === intval($g['id'])) {
                                        $cnt++;
                                    }
                                }
                                echo $cnt;
                                ?>
                            </td>
                            <td class="row-actions">
                                <a class="btn btn-ghost btn-xs"
                                   href="admin.php?editgroup=<?= intval($g['id']) ?>#tab-group">改名</a>
                                <form method="post" action="admin.php"
                                      onsubmit="return confirm('删除分组「<?= h($g['name']) ?>」？组内数据源会归入未分组。')">
                                    <input type="hidden" name="action" value="delete_group">
                                    <input type="hidden" name="id" value="<?= intval($g['id']) ?>">
                                    <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
                                    <button class="btn btn-danger btn-xs" type="submit">删除</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="muted">还没有分组。分组后，前台的源切换标签会按组归类显示。</p>
        <?php endif; ?>

        <h3 class="admin-subtitle"><?= $editingGroup ? '✏️ 改分组名' : '➕ 新增分组' ?></h3>
        <form class="admin-form" method="post" action="admin.php">
            <input type="hidden" name="action" value="<?= $editingGroup ? 'update_group' : 'add_group' ?>">
            <?php if ($editingGroup): ?>
                <input type="hidden" name="id" value="<?= intval($editingGroup['id']) ?>">
            <?php endif; ?>
            <label class="field">
                <span>分组名称</span>
                <input type="text" name="group_name" required maxlength="30"
                       value="<?= h($editingGroup['name'] ?? '') ?>" placeholder="如：影视 / 短视频">
            </label>
            <label class="field">
                <span>排序（越小越靠前）</span>
                <input type="number" name="group_sort" min="0" max="9999"
                       value="<?= h($editingGroup['sort'] ?? 0) ?>">
            </label>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-primary" type="submit">
                <?= $editingGroup ? '保存修改' : '新增分组' ?>
            </button>
            <?php if ($editingGroup): ?>
                <a class="btn btn-ghost" href="admin.php#tab-group">取消</a>
            <?php endif; ?>
        </form>
    </section>

    <!-- ============================= 模板管理 ============================= -->
    <section class="admin-card" id="tab-template">
        <h2 class="admin-title">🎨 模板管理</h2>

        <h3 class="admin-subtitle">站点默认模板</h3>
        <p class="muted">
            所有未单独指定模板的数据源，都会使用这里选中的模板。
            当前已安装 <b><?= count($templates) ?></b> 套模板。
        </p>
        <form class="admin-form" method="post" action="admin.php">
            <input type="hidden" name="action" value="site_template">
            <label class="field field-wide">
                <span>站点默认模板</span>
                <select name="site_template">
                    <?php foreach ($templates as $tName => $tMeta): ?>
                        <option value="<?= h($tName) ?>" <?= $siteTemplate === $tName ? 'selected' : '' ?>>
                            <?= h($tMeta['title']) ?>
                            （<?= $tMeta['cover_mode'] === 'wide' ? '宽图 16:9' : '竖图 2:3' ?>）
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-primary" type="submit">设为默认</button>
        </form>

        <h3 class="admin-subtitle">已安装模板</h3>
        <table class="admin-table">
            <thead>
                <tr>
                    <th>模板目录</th><th>名称</th><th>封面模式</th>
                    <th>说明</th><th>当前状态</th><th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $tName => $tMeta): ?>
                    <tr>
                        <td class="url-cell"><?= h($tName) ?></td>
                        <td><?= h($tMeta['title']) ?></td>
                        <td>
                            <span class="chip chip-plain">
                                <?= $tMeta['cover_mode'] === 'wide' ? '🖼 宽图 16:9' : '📱 竖图 2:3' ?>
                            </span>
                        </td>
                        <td class="muted"><?= h($tMeta['description']) ?></td>
                        <td>
                            <?php if ($siteTemplate === $tName): ?>
                                <span class="badge badge-ok">站点默认</span>
                            <?php else: ?>
                                <span class="badge badge-off">可选</span>
                            <?php endif; ?>
                        </td>
                        <td class="row-actions">
                            <a class="btn btn-ghost btn-xs"
                               href="admin.php?tpl=<?= urlencode($tName) ?>#tpl-editor">
                                编辑样式
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h3 class="admin-subtitle" id="tpl-editor">✏️ 简单样式编辑：<?= h($editTplMeta['title']) ?></h3>
        <p class="muted">
            只调整几个关键样式（主题色、圆角、封面比例、列数），不改动模板结构，保存后立即生效。
            封面比例说明：<b>56.25% = 宽图 16:9</b>，<b>150% = 竖图 2:3</b>。
        </p>
        <form class="admin-form" method="post" action="admin.php">
            <input type="hidden" name="action" value="edit_template">
            <input type="hidden" name="template" value="<?= h($editTpl) ?>">
            <label class="field">
                <span>模板</span>
                <select name="_tpl_picker"
                        onchange="if(this.value)location.href='admin.php?tpl='+encodeURIComponent(this.value)+'#tpl-editor'">
                    <?php foreach ($templates as $tName => $tMeta): ?>
                        <option value="<?= h($tName) ?>" <?= $editTpl === $tName ? 'selected' : '' ?>>
                            <?= h($tMeta['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php foreach (tplEditorVars($editTpl) as $varName => $info):
                $kind = tplVarKind($info['value']);
                $raw  = $info['value'];
                $disp = in_array($kind, ['px', 'percent'], true) ? rtrim($raw, 'px%') : $raw;
            ?>
            <label class="field">
                <span><?= h(tplVarLabel($varName)) ?>（如 <?= h($disp) ?>）</span>
                <input type="text" name="vars[<?= h($varName) ?>]" maxlength="9"
                       value="<?= h($disp) ?>" placeholder="<?= h($disp) ?>">
            </label>
            <?php endforeach; ?>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-primary" type="submit">保存样式</button>
        </form>
        <form method="post" action="admin.php"
              onsubmit="return confirm('确定恢复「<?= h($editTplMeta['title']) ?>」的默认样式吗？')">
            <input type="hidden" name="action" value="reset_template">
            <input type="hidden" name="template" value="<?= h($editTpl) ?>">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-danger btn-sm" type="submit">恢复默认样式</button>
        </form>
    </section>

    <!-- ============================= 访问设置 ============================= -->
    <section class="admin-card" id="tab-access">
        <h2 class="admin-title">🔐 访问设置</h2>
        <p class="muted">
            开启后，任何人打开站点都需要先输入访问密码。<b>留空保存表示保持原密码不变。</b>
        </p>
        <form class="admin-form" method="post" action="admin.php">
            <input type="hidden" name="action" value="access_settings">
            <label class="field field-check">
                <input type="checkbox" name="access_enabled" value="1" <?= $accessEnabled ? 'checked' : '' ?>>
                <span>启用访问密码保护</span>
            </label>
            <label class="field field-wide">
                <span>访问密码<?= $accessEnabled ? '（留空保持不变）' : '' ?></span>
                <input type="password" name="access_password" autocomplete="new-password"
                       placeholder="<?= $accessEnabled ? '已设置，留空保持不变' : '设置访问密码' ?>">
            </label>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-primary" type="submit">保存设置</button>
        </form>

        <h3 class="admin-subtitle">🔑 修改管理密码</h3>
        <form class="admin-form" method="post" action="admin.php">
            <input type="hidden" name="action" value="change_admin_password">
            <label class="field">
                <span>原密码</span>
                <input type="password" name="old_password" required>
            </label>
            <label class="field">
                <span>新密码（至少 6 位）</span>
                <input type="password" name="new_password" required minlength="6">
            </label>
            <label class="field">
                <span>确认新密码</span>
                <input type="password" name="new_password2" required minlength="6">
            </label>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-primary" type="submit">修改管理密码</button>
        </form>
    </section>

    <!-- ============================= 站点与维护 ============================= -->
    <section class="admin-card">
        <h2 class="admin-title">⚙️ 站点与维护</h2>
        <form class="admin-form" method="post" action="admin.php">
            <input type="hidden" name="action" value="site_settings">
            <label class="field field-wide">
                <span>站点名称</span>
                <input type="text" name="site_title" required maxlength="50" value="<?= h($siteTitle) ?>">
            </label>
            <label class="field">
                <span>列表列数（PC 端）</span>
                <select name="list_columns">
                    <?php $curCols = intval(setting('list_columns', '0')); ?>
                    <option value="0" <?= $curCols === 0 ? 'selected' : '' ?>>跟随模板默认</option>
                    <?php for ($c = 2; $c <= 8; $c++): ?>
                        <option value="<?= $c ?>" <?= $curCols === $c ? 'selected' : '' ?>>
                            <?= $c ?> 列<?= $c === 5 ? '（推荐）' : '' ?>
                        </option>
                    <?php endfor; ?>
                </select>
                <small class="muted">
                    控制列表页每行卡片数。选「跟随模板默认」时按当前模板的列数显示；
                    手机端始终自适应，不受此设置影响。
                </small>
            </label>
            <label class="field">
                <span>容量档位（极致低功耗）</span>
                <select name="lowpower_tier">
                    <?php $curTier = (string) setting('lowpower_tier', 'auto'); ?>
                    <option value="auto" <?= $curTier === 'auto' ? 'selected' : '' ?>>自动探测</option>
                    <option value="compact" <?= $curTier === 'compact' ? 'selected' : '' ?>>紧凑（1 GB 空间）</option>
                    <option value="standard" <?= $curTier === 'standard' ? 'selected' : '' ?>>标准（5 GB 及以上空间）</option>
                </select>
                <small class="muted">
                    决定页面缓存、图片缓存、接口缓存的硬上限：紧凑档合计约 105 MB，标准档约 340 MB。
                    <b>磁盘 ≤1 GB 请选「紧凑」</b> —— 不少主机的自动探测拿到的是整机磁盘而不是你的配额。
                </small>
            </label>
            <label class="field">
                <span>首页补拉预算（秒）</span>
                <select name="warm_budget">
                    <?php $curWb = max(0, intval(setting('warm_budget', '6'))); ?>
                    <option value="0" <?= $curWb === 0 ? 'selected' : '' ?>>0（不自动补拉）</option>
                    <?php foreach ([1, 2, 3, 4, 5, 6, 8, 10] as $w): ?>
                        <option value="<?= $w ?>" <?= $curWb === $w ? 'selected' : '' ?>><?= $w ?> 秒<?= $w === 6 ? '（推荐）' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="muted">
                    清空接口缓存后，首页要为**每个数据源**各请求一次上游取分类。
                    这里限制的是**单次请求最多花多少秒**，用满就不再拉 ——
                    拉不完的下次访问继续，已成功的都已落盘、进度不丢。
                    <b>先确认你的网关超时</b>：3 秒超时的主机请选 ≤2 秒，否则照样 502；
                    选 0 = 不自动补拉，首页只显示本地已缓存的分类（没有就提示「无法获取分类」）。
                </small>
            </label>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-primary" type="submit">保存</button>
        </form>

        <?php
        $cacheStat = cacheStats(); $opInfo = opcacheInfo();

        /**
         * 把异常翻译成「用户该做什么」。
         *
         * ⚠ 不要一律归因为「升级包没传全」—— 2026-09-28 线上事故：
         * InfinityFree 把 disk_free_space / disk_total_space 放进了 disable_functions，
         * 抛的是 `Call to undefined function disk_total_space()`，**与升级包完全无关**，
         * 而旧文案写死了 config.php 归因，把人往错方向带。
         * 归因必须跟着错误类型走。
         */
        $lpDiagnose = static function (string $msg): array {
            $m = strtolower($msg);
            if (str_contains($m, 'disk_total_space') || str_contains($m, 'disk_free_space')) {
                return ['主机禁用了 disk_free_space / disk_total_space（disable_functions）',
                        '这与升级包无关。水位探测已自动降级 —— 请在上面「容量档位」手动选紧凑或标准档；硬上限仍然生效。'];
            }
            if (str_contains($m, 'undefined function')) {
                return ['主机禁用了某个函数：' . $msg,
                        '跑 vp.php 的 H 节看禁了哪些；前台会自动降级，不影响访问。'];
            }
            if (str_contains($m, 'constant') || str_contains($m, 'page_cache_dir') || str_contains($m, 'app_version')) {
                return [$msg, 'config.php 是旧版 —— 补传升级包里的 config.php。'];
            }
            if (str_contains($m, 'no such file') || str_contains($m, 'failed opening required')) {
                return [$msg, '升级包缺文件 —— 按 vp.php 的 B 节标「缺!」的补传。'];
            }
            return [$msg, '把上面这行原样贴出来提 Issue。'];
        };

        // ---------------------------------------------------------------- 故障隔离
        // 这一段依赖 1.3.0 新增的函数与常量，而它下面**紧跟着**「清理系统缓存 · OPcache」——
        // 那是「传了文件没变化」时唯一的救场按钮。
        //
        // 所以：坏掉一个新功能可以接受，**把它一起带下水不行**。
        // 只要文件没传齐（典型是漏了改过的 config.php），这里必须跳过而不是 fatal，
        // 否则会出现「页面看着正常、但 OPcache 按钮整段消失」这种最难诊断的故障。
        $lp      = null;
        $lpWhy   = '';
        try {
            foreach (['guardDisk', 'guardCaps', 'pcCount', 'imgCacheBytes', 'pcEnabled', 'bytesHuman'] as $fn) {
                if (!function_exists($fn)) {
                    $lpWhy = '函数 ' . $fn . '() 不存在';
                    break;
                }
            }
            // 用 APP_VERSION 判「config.php 是不是新版」——它**没有**兜底默认值，
            // 只有 1.3.0 的 config.php 才 define 它。其余常量已在 guard/client 里
            // 自带默认值（漏传 config.php 不再让前台 500），所以不再拿它们当判据。
            if ($lpWhy === '' && !defined('APP_VERSION')) {
                $lpWhy = 'config.php 是旧版（缺 APP_VERSION 等 1.3.0 新常量）';
            }
            if ($lpWhy === '') {
                $lp = [
                    'disk'    => guardDisk(),
                    'caps'    => guardCaps(),
                    'pages'   => pcCount(),
                    'imgs'    => imgCacheBytes(),
                    'enabled' => pcEnabled(),
                    'access'  => setting('access_enabled') === '1',
                ];
            }
        } catch (Throwable $e) {
            $lpWhy = $e->getMessage();
        }
        ?>
        <?php if ($lp !== null): ?>
        <h3 class="admin-subtitle">🔋 极致低功耗状态</h3>
        <?php if (($lp['disk']['probe'] ?? 'ok') !== 'ok'): ?>
        <p class="muted" style="color:#8a6d3b;background:#fcf8e3;padding:6px 10px;border-radius:4px">
            ℹ️ 本机<b>磁盘探测不可用</b>（<code>disk_free_space</code> / <code>disk_total_space</code>
            被主机 <code>disable_functions</code> 禁用，免费主机常见）——
            <b>与升级包无关</b>，水位控制已自动停用，档位按上面「容量档位」的设置运行，
            <b>各目录硬上限仍然生效</b>。1 GB 空间请手动选「紧凑」。
        </p>
        <?php endif; ?>
        <p class="muted">
            <?php $probeOk = (($lp['disk']['probe'] ?? 'ok') === 'ok'); ?>
            磁盘可用 <?php if ($probeOk): ?>
                <b><?= round($lp['disk']['pct'], 1) ?>%</b>
                （<?= h(bytesHuman((int) $lp['disk']['free'])) ?> / <?= h(bytesHuman((int) $lp['disk']['total'])) ?>）
            <?php else: ?>
                <b>探测不可用</b>（见上方说明）
            <?php endif; ?>；
            容量档位 <b><?= (($lp['caps']['base'] ?? '') === 'compact') ? '紧凑（约 105 MB）' : '标准（约 340 MB）' ?></b>
            <?php if (($lp['caps']['probe'] ?? 'ok') !== 'ok'): ?>
                · 水位<b>未收紧</b>（探测不可用）
            <?php else: ?>
                · 水位 <b><?= h((string) $lp['caps']['tier']) ?></b><?= ((string) ($lp['caps']['tier'] ?? 'normal')) !== 'normal' ? '（上限已按水位缩放）' : '' ?>
            <?php endif; ?>
            <?php if ((int) ($lp['caps']['pct'] ?? 100) < GUARD_REDLINE): ?>
                <span class="alert alert-error" style="padding:2px 6px">低于 <?= GUARD_REDLINE ?>% 红线，已停止写入页面与图片缓存</span>
            <?php endif; ?>
            ；本档上限：页面缓存 <b><?= intval($lp['caps']['page_files']) ?> 个 / <?= round($lp['caps']['page_bytes'] / 1048576) ?> MB</b>、
            图片缓存 <b><?= round($lp['caps']['img_bytes'] / 1048576) ?> MB</b>、
            接口缓存 <b><?= intval($lp['caps']['cache_files']) ?> 个 / <?= round($lp['caps']['cache_bytes'] / 1048576) ?> MB</b>。
        </p>
        <p class="muted">
            页面静态缓存 <b><?= intval($lp['pages']) ?> 个 HTML</b>；
            图片本地缓存 <b><?= h(bytesHuman((int) $lp['imgs'])) ?></b>；
            页面静态化 <b><?= $lp['enabled'] ? '已启用' : '未启用' ?></b>
            <?= $lp['access'] ? '（已开启访问密码 → 按安全要求自动停用）' : '' ?>
            —— 每小时自动换桶过期，配置变更时全量作废，超过 2 小时的桶自动回收。
        </p>
        <?php else: ?>
        <?php [$why, $hint] = $lpDiagnose((string) $lpWhy); ?>
        <h3 class="admin-subtitle">🔋 极致低功耗状态</h3>
        <p class="muted" style="color:#a94442">
            ⚠️ 无法显示 —— <b><?= h($why) ?></b><br>
            <b>处理：</b><?= $hint ?><br>
            <b>影响面：</b>仅本状态栏；下方「系统缓存」照常可用，
            <b>前台也能正常打开</b>（新文件已自带默认值兜底）。
        </p>
        <?php endif; ?>

        <h3 class="admin-subtitle">🧹 系统缓存</h3>
        <p class="muted">
            接口缓存 <b><?= $cacheStat['files'] ?> 个文件 / <?= h($cacheStat['size']) ?></b>；
            列表/详情 <?= intval(CACHE_TTL / 60) ?> 分钟、分类 <?= intval(CACHE_TTL_TYPE / 3600) ?> 小时，
            上游故障时自动降级用旧缓存。
        </p>
        <p class="muted">
            <b>OPcache：</b><?= h($opInfo['msg']) ?><?= $opInfo['available'] ? '；已缓存 ' . intval($opInfo['scripts']) . ' 个脚本 / ' . intval($opInfo['keys']) . ' 个键（上限 ' . intval($opInfo['max_keys']) . '）；' . h($opInfo['memory']) : '' ?>。
        </p>

        <form class="admin-form" method="post" action="admin.php"
              onsubmit="return confirm('确定清理勾选的缓存吗？\n'
                  + '· 接口缓存：清掉后下次访问要重新请求上游，\n'
                  + '  首页可能变慢几秒（每个源各撞一次超时），上游不通时会显示「无法获取分类」\n'
                  + '· 页面缓存 / 图片缓存：清掉后下次访问重新生成，只是慢一点\n'
                  + '· OPcache：上传文件后没变化时才需要勾，否则别勾')">
            <input type="hidden" name="action" value="clear_cache">
            <label class="field-check">
                <input type="checkbox" name="clear_api" value="1">
                <span>接口响应缓存（<code>runtime/cache/*.json</code>）</span>
            </label>
            <label class="field-check">
                <input type="checkbox" name="clear_opcache" value="1" checked>
                <span><b>OPcache（PHP 脚本缓存）</b> —— 上传文件后前台没变化，就是它在跑旧代码</span>
            </label>
            <label class="field-check">
                <input type="checkbox" name="clear_page" value="1">
                <span>页面静态缓存（<code>c/&lt;时间桶&gt;/*.html</code>）—— 改完样式/模板没变化时勾上</span>
            </label>
            <label class="field-check">
                <input type="checkbox" name="clear_img" value="1">
                <span>图片本地缓存（<code>static/imgcache/</code>）—— 封面需要重新从上游取时勾上</span>
            </label>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-danger" type="submit">清理系统缓存</button>
        </form>

        <h3 class="admin-subtitle">📦 配置导入导出</h3>
        <p class="muted">
            导出的是<b>配置</b>（站点设置、模板样式、分组、数据源），
            <b>不含</b>接口响应缓存 —— 那是可重建的缓存。
            密码哈希与 API 密钥<b>默认不导出</b>，需要迁移密码时再勾选。
        </p>

        <form class="admin-form" method="get" action="admin.php">
            <label class="field-check">
                <input type="checkbox" name="secrets" value="1">
                <span>同时导出密码哈希与 API 密钥（⚠️ 文件含敏感信息，勿外传）</span>
            </label>
            <button class="btn btn-primary" type="submit" name="export" value="1">⬇ 导出 JSON</button>
        </form>

        <form class="admin-form" method="post" action="admin.php" enctype="multipart/form-data"
              onsubmit="return confirm('确定导入配置吗？选「覆盖」会清空现有分组与数据源，不可撤销。')">
            <input type="hidden" name="action" value="import_config">
            <label class="field field-wide">
                <span>配置文件（.json）</span>
                <input type="file" name="config_file" accept=".json,application/json,text/plain">
                <small class="muted">也可以不选文件，直接把 JSON 粘贴到下面的框里（文件优先）。</small>
            </label>
            <label class="field field-wide">
                <span>或粘贴 JSON</span>
                <textarea name="config_text" rows="5" spellcheck="false"
                          placeholder='{"format":"vodhub-config","version":1,...}'></textarea>
            </label>
            <label class="field">
                <span>导入方式</span>
                <select name="mode">
                    <option value="merge" selected>合并 —— 按接口地址匹配，只新增/更新，不删除</option>
                    <option value="replace">覆盖 —— 清空现有分组与数据源，完全按文件重建</option>
                </select>
                <small class="muted">
                    两种方式都会写入设置项；文件里没带密钥时，后台密码与访问密码保持不变。
                </small>
            </label>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-primary" type="submit">⬆ 导入配置</button>
        </form>
    </section>
</main>

<footer class="footer">
    <p>单用户模式 · 数据存于本机 SQLite · 所有内容来自第三方接口</p>
</footer>
</body>
</html>
