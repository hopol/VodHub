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

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/template.php';
require_once __DIR__ . '/includes/enrich.php';
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

// ---------------------------------------------------------------- 渲染
$allSources = getSources(false);
$allGroups = getGroups();
$accessEnabled = setting('access_enabled') === '1';
$siteTitle = setting('site_title', APP_NAME);
$templates = listTemplates();
$siteTemplate = setting('site_template', 'default');
$enrichEnabled = setting('enrich_enabled', '1') === '1';
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
            <label class="field field-check">
                <input type="checkbox" name="enrich_enabled" value="1" <?= $enrichEnabled ? 'checked' : '' ?>>
                <span>播放页字段智能归一化（TypeSafe）</span>
            </label>
            <label class="field field-wide">
                <small class="muted">
                    把地区/语言/类型/更新状态这类「同一份数据有多种写法」的字段统一成规范值，
                    并判定内容分级。首次打开某影片时请求一次（约 1 秒），结果缓存 30 天；
                    关闭后全部按上游原始值展示，页面照常工作。
                </small>
            </label>
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-primary" type="submit">保存</button>
        </form>

        <h3 class="admin-subtitle">🧹 缓存管理</h3>
        <p class="muted">
            当前缓存占用：<b><?= h(cacheSize()) ?></b>；
            字段归一化记录 <b><?= h(enrichCacheCount()) ?></b> 条。
            列表/详情缓存 <?= intval(CACHE_TTL / 60) ?> 分钟；
            分类数据走长缓存 <?= intval(CACHE_TTL_TYPE / 3600) ?> 小时（分类几乎不变，减少上游请求）。
            上游故障时会自动降级使用旧缓存。
        </p>
        <form method="post" action="admin.php"
              onsubmit="return confirm('确定清空全部缓存吗？下次访问会重新请求接口。')">
            <input type="hidden" name="action" value="clear_cache">
            <input type="hidden" name="csrf" value="<?= csrfToken() ?>">
            <button class="btn btn-danger" type="submit">清空全部缓存</button>
        </form>
    </section>
</main>

<footer class="footer">
    <p>单用户模式 · 数据存于本机 SQLite · 所有内容来自第三方接口</p>
</footer>
</body>
</html>
