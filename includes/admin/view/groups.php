<?php
/**
 * 数据源分组（列表 + 改名/新增表单）（admin.php 视图片段）
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
