<?php
/**
 * default 模板 - 首页：展示各数据源及其分类
 * 变量：$sources, $sourceId, $siteTitle, $pageTitle
 */
require_once __DIR__ . '/header.php';
?>

<?php if (!$sources): ?>
    <div class="empty">
        <div class="empty-icon">📭</div>
        <h2>暂无数据源</h2>
        <p>请先在后台添加 API 接口地址。</p>
        <a class="btn btn-primary" href="admin.php">前往后台</a>
    </div>
<?php else: ?>
    <?php foreach ($sources as $src): ?>
        <?php
        $client = new VodClient($src['api_url']);
        $types = $client->getTypesLocal();   // 只读本地分类缓存，永不出站 —— 首页对每个源都要取一次分类，
        // 串行冷缓存最坏 N × 4 s（改前 N × 12 s），5 个源就可能超过网关超时；
        // 分类 24 小时不变，显示旧一点无感
        $sid = intval($src['id']);
        ?>
        <section class="source-block">
            <h2 class="source-title">
                <?= h($src['name']) ?>
                <span class="source-count"><?= count($types) ?> 个分类</span>
            </h2>
            <?php if ($src['note'] !== ''): ?>
                <p class="source-note"><?= h($src['note']) ?></p>
            <?php endif; ?>

            <?php if (!$types): ?>
                <p class="muted">无法获取分类（接口不可用或尚未返回分类数据）。</p>
            <?php else: ?>
                <div class="type-grid">
                    <?php foreach ($types as $t): ?>
                        <a class="type-chip"
                           href="list.php?source=<?= $sid ?>&type=<?= intval($t['type_id'] ?? 0) ?>">
                            <span class="type-name"><?= h($t['type_name'] ?? '未命名') ?></span>
                            <?php if (!empty($t['type_pid']) && intval($t['type_pid']) !== 0): ?>
                                <span class="type-sub">子分类</span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="source-actions">
                    <a class="btn btn-ghost btn-sm"
                       href="list.php?source=<?= $sid ?>&type=0">浏览全部 →</a>
                </div>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
