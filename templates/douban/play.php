<?php
/**
 * default 模板 - 播放页
 * 变量：$source, $sourceId, $vodId, $detail, $name, $pic, $playUrls, $playCount,
 *       $types, $typeName, $desc, $meta, $autoplay
 *
 * 字段渲染拆到两个共享片段（全站 5 套模板共用，见片段内注释）：
 *   - partials/vod_meta.php  信息 chips
 *   - partials/vod_side.php  侧栏影片信息
 * 演职员与简介留在本文件，因为它们决定主栏排版。
 */
require_once __DIR__ . '/header.php';
?>

<?php if (!$detail): ?>
    <div class="empty">
        <div class="empty-icon">🕳️</div>
        <h2><?= h($name) ?></h2>
        <p>影片不存在，或该数据源已停用。</p>
        <a class="btn btn-primary" href="index.php">返回首页</a>
    </div>
<?php else: ?>
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

            <?php if (($meta['alias'] ?? '') !== '' && $meta['alias'] !== $name): ?>
                <p class="detail-sub"><?= h($meta['alias']) ?></p>
            <?php endif; ?>

            <?php tplPartial('vod_meta', $tplName, [
                'meta' => $meta, 'source' => $source, 'sourceId' => $sourceId,
            ]); ?>

            <?php if ($desc !== ''): ?>
                <div class="detail-desc">
                    <strong>简介</strong>
                    <p><?= h($desc) ?></p>
                </div>
            <?php endif; ?>

            <?php
            // 演职员：`vod_actor` 79% 填充但动辄十几人，`vod_director` / `vod_writer`
            // 分别 58% / 41%。主演默认折叠，避免把简介挤出首屏。
            $actors   = $meta['actors'] ?? [];
            $director = $meta['director'] ?? '';
            $writer   = $meta['writer'] ?? '';
            ?>
            <?php if ($director !== '' || $writer !== '' || $actors): ?>
            <div class="credits">
                <strong>演职员</strong>
                <?php if ($director !== ''): ?>
                    <p class="credit-row"><span class="credit-k">导演</span><?= h($director) ?></p>
                <?php endif; ?>
                <?php if ($writer !== ''): ?>
                    <p class="credit-row"><span class="credit-k">编剧</span><?= h($writer) ?></p>
                <?php endif; ?>
                <?php if ($actors): ?>
                    <details class="credit-more">
                        <summary>主演（<?= count($actors) ?> 人）</summary>
                        <p><?= h(implode('、', $actors)) ?></p>
                    </details>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="detail-actions">
                <button class="btn btn-ghost btn-sm" id="btnFavorite">⭐ 收藏</button>
                <?php if (($meta['type_id'] ?? 0) > 0): ?>
                    <a class="btn btn-ghost btn-sm"
                       href="list.php?source=<?= $sourceId ?>&type=<?= intval($meta['type_id']) ?>">
                        更多同分类 →
                    </a>
                <?php endif; ?>
                <?php if (($meta['type_id_1'] ?? 0) > 0 && $meta['type_id_1'] !== ($meta['type_id'] ?? 0)): ?>
                    <a class="btn btn-ghost btn-sm"
                       href="list.php?source=<?= $sourceId ?>&type=<?= intval($meta['type_id_1']) ?>">
                        <?= h($meta['type_name_1'] !== '' ? $meta['type_name_1'] : '更多同大类') ?> →
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php tplPartial('vod_side', $tplName, [
        'meta' => $meta, 'detail' => $detail, 'source' => $source,
        'sourceId' => $sourceId, 'playCount' => $playCount,
    ]); ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>

<?php require_once __DIR__ . '/player_script.php'; ?>
