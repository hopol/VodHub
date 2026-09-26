<?php
/**
 * bilibili 模板 - 视频卡片网格片段
 * 与 default 的差异：封面为 16:9 宽图，角标沿用 B 站风格（.bili-remarks / .bili-hits），
 * 并保留本模板原有的「播放量优先、评分兜底」角标策略。
 * 变量：$list（视频数组）, $sourceId
 *
 * 字段与 default 片段一致：角标按 vod_remarks > vod_state > vod_duration 取，
 * 评分按 vod_score > vod_douban_score 取，data-s 供「本页筛选」匹配。
 */
?>
<div class="vod-grid" id="vodGrid">
    <?php foreach ($list as $item): ?>
        <?php
        if (intval($item['vod_status'] ?? 1) === 0) {
            continue;
        }
        $vid     = intval($item['vod_id'] ?? 0);
        $name    = cleanTitle($item['vod_name'] ?? '');
        $pic     = coverUrl($item['vod_pic'] ?? '', $sourceId);
        $score   = scoreOf($item);
        $hits    = intval($item['vod_hits'] ?? 0);
        $badge   = badgeOf($item);
        $alias   = implode(' / ', splitList($item['vod_sub'] ?? ''));
        $type    = trim((string) ($item['type_name'] ?? ''));
        $cls     = trim(decodeEntities((string) ($item['vod_class'] ?? '')));
        $year    = trim((string) ($item['vod_year'] ?? ''));

        $subParts = [];
        if ($cls !== '')                  { $subParts[] = $cls; }
        elseif ($type !== '')              { $subParts[] = $type; }
        if ($year !== '')                  { $subParts[] = $year; }
        if ($alias !== '' && $alias !== $name) { $subParts[] = $alias; }
        ?>
        <a class="vod-card" href="play.php?source=<?= $sourceId ?>&id=<?= $vid ?>"
           data-id="<?= $vid ?>" data-name="<?= h($name) ?>" data-pic="<?= h($pic) ?>"
           data-s="<?= h(searchHaystack($item)) ?>">
            <div class="cover">
                <img src="<?= h($pic) ?>" alt="<?= h($name) ?>" loading="lazy">
                <?php if ($badge !== ''): ?>
                    <span class="badge-time bili-remarks"><?= h($badge) ?></span>
                <?php endif; ?>
                <?php if ($hits > 0): ?>
                    <span class="badge-score bili-hits">▶ <?= h(formatNumber($hits)) ?></span>
                <?php elseif ($score > 0): ?>
                    <span class="badge-score bili-hits"><?= h(rtrim(rtrim(number_format($score, 1, '.', ''), '0'), '.')) ?> 分</span>
                <?php endif; ?>
            </div>
            <div class="vod-info">
                <h3><?= h($name) ?></h3>
                <p class="vod-sub"><?= h(implode(' · ', $subParts)) ?></p>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<div class="filter-empty" id="filterEmpty">没有匹配的影片 —— 换个关键词试试</div>
