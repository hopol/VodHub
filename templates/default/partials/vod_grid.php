<?php
/**
 * default 模板 - 视频卡片网格片段
 * 变量：$list（视频数组）, $sourceId
 *
 * 卡片用到的源字段（改造前只读了 6 个）：
 *   vod_id / vod_name / vod_pic          基础
 *   vod_remarks / vod_state / vod_duration  角标，按实测填充率 100% / 23% / 22% 排优先级
 *   vod_score / vod_douban_score         评分，本源两者互补（12% / 11% 非零）
 *   vod_class / type_name / vod_year     副标题
 *   vod_sub                              别名，接在副标题后
 *   vod_en / vod_letter / vod_actor / vod_director / vod_writer / vod_tag / vod_name
 *                                        拼进 data-s，供「本页筛选」匹配
 *   vod_status                           为 0 表示上游已下架，不出卡片
 */
?>
<div class="vod-grid" id="vodGrid">
    <?php foreach ($list as $item): ?>
        <?php
        if (intval($item['vod_status'] ?? 1) === 0) {
            continue;   // 上游标记为停用的内容不进列表
        }
        $vid    = intval($item['vod_id'] ?? 0);
        $name   = cleanTitle($item['vod_name'] ?? '');
        $pic    = coverUrl($item['vod_pic'] ?? '', $sourceId);
        $score  = scoreOf($item);
        $badge  = badgeOf($item);
        $alias  = implode(' / ', splitList($item['vod_sub'] ?? ''));
        $type   = trim((string) ($item['type_name'] ?? ''));
        $cls    = trim(decodeEntities((string) ($item['vod_class'] ?? '')));
        $year   = trim((string) ($item['vod_year'] ?? ''));

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
                <img src="<?= h($pic) ?>" alt="<?= h($name) ?>" loading="lazy" decoding="async"
                     referrerpolicy="no-referrer">
                <?php if ($badge !== ''): ?>
                    <span class="badge-time"><?= h($badge) ?></span>
                <?php endif; ?>
                <?php if ($score > 0): ?>
                    <span class="badge-score"><?= h(rtrim(rtrim(number_format($score, 1, '.', ''), '0'), '.')) ?> 分</span>
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
