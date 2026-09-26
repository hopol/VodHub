<?php
/**
 * default 模板 - 搜索页
 * 变量：$wd, $sources, $source, $sourceId, $page, $data
 */
require_once __DIR__ . '/header.php';
?>

<div class="list-head">
    <h1 class="list-title">
        <?php if ($wd !== ''): ?>
            搜索「<?= h($wd) ?>」
        <?php else: ?>
            搜索影片
        <?php endif; ?>
    </h1>
    <div class="type-filter">
        <?php foreach ($sources as $s): ?>
            <?php if ($wd === '') continue; ?>
            <a class="chip <?= intval($s['id']) === $sourceId ? 'active' : '' ?>"
               href="search.php?wd=<?= urlencode($wd) ?>&source=<?= intval($s['id']) ?>">
                <?= h($s['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($wd === ''): ?>
    <div class="empty">
        <div class="empty-icon">🔍</div>
        <h2>输入影片名称开始搜索</h2>
        <p>搜索结果来自上游接口，不同数据源的结果可能不同。</p>
    </div>
<?php elseif (!$source): ?>
    <div class="empty">
        <div class="empty-icon">📭</div>
        <h2>暂无可用数据源</h2>
        <a class="btn btn-primary" href="admin.php">前往后台添加</a>
    </div>
<?php else: ?>
    <p class="muted search-meta">在「<?= h($source['name']) ?>」中找到 <?= formatNumber($total) ?> 条结果</p>

    <?php if (!$list): ?>
        <div class="empty">
            <div class="empty-icon">🕳️</div>
            <h2>没有找到相关影片</h2>
            <p>试试更换关键词，或切换到其他数据源。</p>
        </div>
    <?php else: ?>
        <div class="list-filter">
            <input type="search" id="listFilter" autocomplete="off"
                   placeholder="本页筛选：片名 / 别名 / 拼音 / 演员 / 导演 / 标签">
            <span class="filter-count" id="filterCount"></span>
        </div>
        <?php tplPartial('vod_grid', $tplName, ['list' => $list, 'sourceId' => $sourceId]); ?>
        <?= renderPagination($page, $pagecount, "search.php?wd=" . urlencode($wd) . "&source={$sourceId}") ?>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
