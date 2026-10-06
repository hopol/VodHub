<?php
/**
 * 数据源管理（列表 + 编辑/新增表单）（admin.php 视图片段）
 *
 * 1.5.3 起从 admin.php 拆出 —— 原文件 982 行，逻辑与 HTML 混在一起。
 *
 * ⚠ 由 admin.php **顶层**按固定顺序 require，与 admin.php 共用同一份作用域；
 *   **顺序不能调**：这份片段里有 $tName / $curCols / $curTier / $curWb /
 *   $probeOk 这类中间变量，改变顺序会让后面的片段读到前一份的残留值。
 *   拆分只改变文件归属，不改变执行顺序 —— 与原来逐字节等价。
 *
 * ⚠ 这类会 echo 的视图**不能被 HTTP 直接取到**：`.htaccess` 已加
 *   `RewriteRule ^includes/ - [F,L]`。同 templates/ 被保护的道理 ——
 *   直接取会吐出未初始化变量的页面骨架与路径信息。
 */
?>
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
