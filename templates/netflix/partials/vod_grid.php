<?php
/**
 * default 模板 - 视频卡片网格片段
 * 变量：$list（视频数组）, $sourceId
 */
?>
<div class="vod-grid">
    <?php foreach ($list as $item): ?>
        <?php
        $vid   = intval($item['vod_id'] ?? 0);
        $name  = cleanTitle($item['vod_name'] ?? '');
        $pic   = coverUrl($item['vod_pic'] ?? '', $sourceId);
        $score = (string) ($item['vod_score'] ?? '');
        ?>
        <a class="vod-card" href="play.php?source=<?= $sourceId ?>&id=<?= $vid ?>"
           data-id="<?= $vid ?>" data-name="<?= h($name) ?>" data-pic="<?= h($pic) ?>">
            <div class="cover">
                <img src="<?= h($pic) ?>" alt="<?= h($name) ?>" loading="lazy">
                <span class="badge-time"><?= h(formatDuration($item['vod_duration'] ?? '')) ?></span>
                <?php if (floatval($score) > 0): ?>
                    <span class="badge-score"><?= h($score) ?> 分</span>
                <?php endif; ?>
            </div>
            <div class="vod-info">
                <h3><?= h($name) ?></h3>
                <p class="vod-sub">
                    <?= h(($item['vod_class'] ?? '') ?: ($item['type_name'] ?? '')) ?>
                    · <?= h($item['vod_year'] ?? '') ?>
                </p>
            </div>
        </a>
    <?php endforeach; ?>
</div>
