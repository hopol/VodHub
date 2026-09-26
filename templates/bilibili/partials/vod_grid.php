<?php
/**
 * bilibili 模板 - 视频卡片网格片段
 * 与 default 的差异：封面为 16:9 宽图，左下角显示播放量，角标样式按 B 站风格
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
        $hits  = intval($item['vod_hits'] ?? 0);
        $remarks = (string) ($item['vod_remarks'] ?? '');
        ?>
        <a class="vod-card" href="play.php?source=<?= $sourceId ?>&id=<?= $vid ?>">
            <div class="cover">
                <img src="<?= h($pic) ?>" alt="<?= h($name) ?>" loading="lazy">
                <?php if ($remarks !== ''): ?>
                    <span class="badge-time bili-remarks"><?= h($remarks) ?></span>
                <?php else: ?>
                    <span class="badge-time bili-remarks"><?= h(formatDuration($item['vod_duration'] ?? '')) ?></span>
                <?php endif; ?>
                <?php if ($hits > 0): ?>
                    <span class="badge-score bili-hits">▶ <?= h(formatNumber($hits)) ?></span>
                <?php elseif (floatval($score) > 0): ?>
                    <span class="badge-score bili-hits"><?= h($score) ?> 分</span>
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
