<?php
/**
 * 播放页 · 信息 chips（default 及其回退模板共用）
 *
 * 变量：$meta（buildMeta() 的返回值）, $source, $sourceId
 *
 * 为什么单独抽片段：5 套模板的 play.php 原本是逐字节相同的拷贝，
 * 字段一多就得改 5 遍、漏一个就出现「有的模板显示有的不显示」。
 * 片段缺失时 tplPartial() 会回退到 default，所以新模板写漏也不会白屏。
 *
 * 渲染约定：**值为空就不渲染**，而不是渲染「未知」。
 * 上游各字段填充率从 12%（vod_score）到 100%（vod_area）不等，
 * 占位文案会让播放页常年挂着一排「未知」。
 */
?>
<div class="detail-meta" id="detailMeta"
     data-enrich="<?= ($enrichPending ?? false) ? 'pending' : 'done' ?>">
    <?php if (!empty($meta['adult_warn'])): ?>
        <span class="chip chip-warn" title="模型判定为成人向内容">⚠ 成人内容</span>
    <?php endif; ?>

    <?php if ($meta['type_name'] !== ''): ?>
        <span class="chip chip-plain"><?= h($meta['type_name']) ?></span>
    <?php endif; ?>

    <?php if ($meta['type_name_1'] !== '' && $meta['type_name_1'] !== $meta['type_name']): ?>
        <a class="chip chip-plain"
           href="list.php?source=<?= intval($sourceId) ?>&type=<?= intval($meta['type_id_1']) ?>">
            <?= h($meta['type_name_1']) ?> ▸
        </a>
    <?php endif; ?>

    <?php if ($meta['year'] !== ''): ?>
        <span class="chip chip-plain"><?= h($meta['year']) ?></span>
    <?php endif; ?>

    <?php if ($meta['region'] !== ''): ?>
        <span class="chip chip-plain"><?= h($meta['region']) ?></span>
    <?php endif; ?>

    <?php if ($meta['lang'] !== ''): ?>
        <span class="chip chip-plain"><?= h($meta['lang']) ?></span>
    <?php endif; ?>

    <?php if ($meta['status'] !== ''): ?>
        <span class="chip chip-plain"><?= h($meta['status']) ?></span>
    <?php elseif ($meta['remarks'] !== ''): ?>
        <span class="chip chip-plain"><?= h($meta['remarks']) ?></span>
    <?php endif; ?>

    <?php if ($meta['state'] !== ''): ?>
        <span class="chip chip-plain"><?= h($meta['state']) ?></span>
    <?php endif; ?>

    <?php if ($meta['version'] !== ''): ?>
        <span class="chip chip-plain"><?= h($meta['version']) ?></span>
    <?php endif; ?>

    <?php if ($meta['duration'] !== ''): ?>
        <span class="chip chip-plain"><?= h($meta['duration']) ?></span>
    <?php endif; ?>

    <?php if ($meta['score'] > 0): ?>
        <span class="chip chip-score">评分 <?= h(rtrim(rtrim(number_format($meta['score'], 1, '.', ''), '0'), '.')) ?></span>
    <?php endif; ?>

    <?php if ($meta['hits'] > 0): ?>
        <span class="chip chip-plain">播放 <?= h(formatNumber($meta['hits'])) ?></span>
    <?php endif; ?>

    <?php if ($meta['status_code'] === 0): ?>
        <span class="chip chip-warn">已下架</span>
    <?php endif; ?>
</div>
