<?php
/**
 * default 模板 - 播放页
 * 变量：$source, $sourceId, $vodId, $detail, $name, $pic, $playUrls,
 *       $types, $typeName, $desc, $autoplay
 */
require_once __DIR__ . '/header.php';
?>

<div class="play-layout">
    <div class="play-main">
        <div class="player-wrap" id="playerWrap">
            <?php if (!$playUrls): ?>
                <div class="player-empty">该影片暂无可播放地址</div>
            <?php else: ?>
                <video id="player" class="player" controls
                       playsinline <?= $autoplay ? 'autoplay' : '' ?>
                       poster="<?= h($pic) ?>"></video>
            <?php endif; ?>
        </div>

        <?php if ($playUrls): ?>
        <div class="episodes-block" id="episodesBlock">
            <div class="episodes-title">选集 <span class="episodes-count"><?= count($playUrls) ?> 集</span></div>
            <div class="player-sources" id="playerSources">
                <?php foreach ($playUrls as $i => $p): ?>
                    <button class="chip <?= $i === 0 ? 'active' : '' ?>"
                            data-url="<?= h($p['url']) ?>"><?= h($p['label']) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="detail-card">
            <h1 class="detail-name"><?= h($name) ?></h1>
            <div class="detail-meta">
                <span class="chip chip-plain"><?= h($typeName) ?></span>
                <?php if (!empty($detail['vod_year'])): ?>
                    <span class="chip chip-plain"><?= h($detail['vod_year']) ?></span>
                <?php endif; ?>
                <?php if (!empty($detail['vod_area'])): ?>
                    <span class="chip chip-plain"><?= h($detail['vod_area']) ?></span>
                <?php endif; ?>
                <span class="chip chip-plain"><?= h(formatDuration($detail['vod_duration'] ?? '')) ?></span>
                <?php if (floatval($detail['vod_score'] ?? 0) > 0): ?>
                    <span class="chip chip-score">评分 <?= h($detail['vod_score']) ?></span>
                <?php endif; ?>
                <span class="chip chip-plain">播放 <?= h(formatNumber(intval($detail['vod_hits'] ?? 0))) ?></span>
            </div>

            <div class="detail-desc">
                <strong>简介</strong>
                <p><?= h($desc) ?></p>
            </div>

            <div class="detail-actions">
                <button class="btn btn-ghost btn-sm" id="btnFavorite">⭐ 收藏</button>
                <a class="btn btn-ghost btn-sm"
                   href="list.php?source=<?= $sourceId ?>&type=<?= intval($detail['type_id'] ?? 0) ?>">
                    更多同分类 →
                </a>
            </div>
        </div>
    </div>

    <aside class="play-side">
        <h2 class="side-title">影片信息</h2>
        <dl class="info-list">
            <dt>编号</dt><dd><?= h($detail['vod_id'] ?? '') ?></dd>
            <dt>分类</dt><dd><?= h(($detail['vod_class'] ?? '') ?: $typeName) ?></dd>
            <dt>地区</dt><dd><?= h(($detail['vod_area'] ?? '') ?: '未知') ?></dd>
            <dt>语言</dt><dd><?= h(($detail['vod_lang'] ?? '') ?: '未知') ?></dd>
            <dt>清晰度</dt><dd><?= h(($detail['vod_remarks'] ?? '') ?: ($detail['vod_version'] ?? '') ?: '未知') ?></dd>
            <dt>添加时间</dt><dd><?= h($detail['vod_time'] ?? '未知') ?></dd>
            <dt>数据源</dt><dd><?= h($source['name'] ?? '未知') ?></dd>
        </dl>
    </aside>
</div>

<?php require_once __DIR__ . '/footer.php'; ?>

<?php require_once __DIR__ . '/player_script.php'; ?>
