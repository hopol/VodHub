<?php
/**
 * 访问设置 + 修改管理密码（admin.php 视图片段）
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
