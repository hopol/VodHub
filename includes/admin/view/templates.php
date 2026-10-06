<?php
/**
 * 模板管理（站点默认模板 / 已安装模板 / 样式编辑器）（admin.php 视图片段）
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
