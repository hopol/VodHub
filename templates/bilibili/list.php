<?php
/**
 * default 模板 - 内容列表页
 * 变量：$source, $sourceId, $typeId, $page, $data, $types, $curName
 */
require_once __DIR__ . '/header.php';
?>

<div class="list-head">
    <h1 class="list-title">
        <?= h($curName) ?>
        <span class="list-meta"><?= h(($source['name'] ?? '') ?: '数据源不存在') ?> · 共 <?= formatNumber($total) ?> 部</span>
    </h1>
    <div class="type-filter">
        <a class="chip <?= $typeId === 0 ? 'active' : '' ?>"
           href="list.php?source=<?= $sourceId ?>&type=0">全部</a>
        <?php foreach ($types as $t): ?>
            <a class="chip <?= intval($t['type_id'] ?? -1) === $typeId ? 'active' : '' ?>"
               href="list.php?source=<?= $sourceId ?>&type=<?= intval($t['type_id'] ?? 0) ?>">
                <?= h($t['type_name'] ?? '') ?>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if ($list): ?>
    <div class="list-filter">
        <input type="search" id="listFilter" autocomplete="off"
               placeholder="本页筛选：片名 / 别名 / 拼音 / 演员 / 导演 / 标签">
        <span class="filter-count" id="filterCount"></span>
    </div>
    <?php endif; ?>
</div>

<?php if (!$list): ?>
    <div class="empty">
        <div class="empty-icon">🎞️</div>
        <h2>暂无内容</h2>
        <p>该分类下没有影片，或接口暂时无法访问。</p>
        <a class="btn btn-ghost" href="index.php">返回分类</a>
    </div>
<?php else: ?>
    <?php tplPartial('vod_grid', $tplName, ['list' => $list, 'sourceId' => $sourceId]); ?>
    <?php if ($total > $limit): ?>
        <div class="list-stats">第 <?= $page ?> / <?= $pagecount ?> 页</div>
    <?php endif; ?>
    <?= renderPagination($page, $pagecount, "list.php?source={$sourceId}&type={$typeId}") ?>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
