<?php
/**
 * default 模板 - 首页：展示各数据源及其分类
 * 变量：$sources, $sourceId, $siteTitle, $pageTitle
 */
require tplInclude('header.php', $tplName);
?>

<?php if (!$sources): ?>
    <div class="empty">
        <div class="empty-icon">📭</div>
        <h2>暂无数据源</h2>
        <p>请先在后台添加 API 接口地址。</p>
        <a class="btn btn-primary" href="admin.php">前往后台</a>
    </div>
<?php else: ?>
    <?php // 只渲染选中源；页头仍用全部 $sources（header.php 里的标签栏用 $sources）
    ?>
    <?php foreach (($pageSources ?? $sources) as $src): ?>
        <?php
        $client = new VodClient($src['api_url']);
        $types = $client->getTypesLocal();   // 本地有分类缓存就只读本地（零出站）；一条都没有时自动补拉一次 ——
        // 首页是分类的唯一展示入口：拿不到分类时连「浏览全部 →」都不渲染，
        // 站内再没有路径能把这份缓存填回来（后台「测试连接」走 probe()，不落盘）
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
                <p class="muted">无法获取分类：<?= $client->lastMsg() !== ''
                    ? h($client->lastMsg())
                    : '接口不可用或尚未返回分类数据' ?>。</p>
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

<?php require tplInclude('footer.php', $tplName); ?>
