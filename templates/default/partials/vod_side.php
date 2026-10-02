<?php
/**
 * 播放页 · 侧栏影片信息（default 及其回退模板共用）
 *
 * 变量：$meta（buildMeta()）, $detail（原始记录，算集数要用）, $source,
 *       $sourceId, $playCount（已解析出的可播集数）
 *
 * 这里是「最大化利用源字段」的主战场：别名、上映日期、导演、编剧、主类型、
 * 集数、豆瓣、拼音/首字母、收录时间、播放来源 —— 这些字段原先在全站
 * 一处都没被读过，现在集中在这里按「有值才出现」渲染。
 */
$ep = episodes(is_array($detail ?? null) ? $detail : [], intval($playCount ?? 0));
?>
<aside class="play-side" id="playSide"
       data-enrich="<?= ($enrichPending ?? false) ? 'pending' : 'done' ?>">
    <h2 class="side-title">影片信息</h2>
    <dl class="info-list">
        <dt>编号</dt><dd><?= h($meta['vod_id']) ?></dd>

        <?php if ($meta['alias'] !== ''): ?>
            <dt>别名</dt><dd><?= h($meta['alias']) ?></dd>
        <?php endif; ?>

        <dt>分类</dt><dd><?= h($meta['genres'] ? implode(' / ', $meta['genres']) : ($meta['type_name'] ?: '未知')) ?></dd>

        <?php
        // 主类型行（1.3.4 改）：
        // 此前条件是 `genre_main !== '' && !in_array($genre_main, $genres)` ——
        // 实测两道闸门叠加后，模型辛苦归一出的结果在 8/9 的情况下**什么都不显示**：
        //   闸门一 confidence ≥ 0.7      → 6/9 通过（criteria 重叠导致分布被摊薄）
        //   闸门二 不在原始 genres 里      → 6 个里只剩 1 个（归一标签恰好就是 vod_class 原词）
        //
        // 现在改成**只展示真正有增量的信息**：
        //   · 归一结果（genre_main）优先；
        //   · 模型判不出来时用原始 vod_class 兜底（genre_raw），不再留空；
        //   · 两者都没有才不渲染 —— 而「有值才渲染」是本项目的既定约定。
        $genreShow = '';
        $genreSrc  = '';
        if ($meta['genre_main'] !== '') {
            $genreShow = $meta['genre_main'];
            $genreSrc  = '归一';
        } elseif (($meta['genre_raw'] ?? '') !== '') {
            $genreShow = $meta['genre_raw'];
            $genreSrc  = '原始';
        }
        ?>
        <?php if ($genreShow !== ''): ?>
            <dt>主类型</dt><dd><?= h($genreShow) ?><span class="dim"><?= h($genreSrc) ?></span></dd>
        <?php endif; ?>

        <?php if ($meta['region'] !== ''): ?>
            <dt>地区</dt><dd><?= h($meta['region']) ?></dd>
        <?php endif; ?>

        <?php if ($meta['lang'] !== ''): ?>
            <dt>语言</dt><dd><?= h($meta['lang']) ?></dd>
        <?php endif; ?>

        <?php if ($meta['pubdate'] !== ''): ?>
            <dt>上映</dt><dd><?= h($meta['pubdate']) ?><?= $meta['pubcountry'] !== '' ? '（' . h($meta['pubcountry']) . '）' : '' ?></dd>
        <?php endif; ?>

        <?php if ($meta['duration'] !== ''): ?>
            <dt>时长</dt><dd><?= h($meta['duration']) ?></dd>
        <?php endif; ?>

        <?php if ($ep['label'] !== ''): ?>
            <dt>集数</dt><dd><?= h($ep['label']) ?></dd>
        <?php endif; ?>

        <?php if ($meta['director'] !== ''): ?>
            <dt>导演</dt><dd><?= h($meta['director']) ?></dd>
        <?php endif; ?>

        <?php if ($meta['writer'] !== ''): ?>
            <dt>编剧</dt><dd><?= h($meta['writer']) ?></dd>
        <?php endif; ?>

        <?php if ($meta['state'] !== ''): ?>
            <dt>片源</dt><dd><?= h($meta['state']) ?></dd>
        <?php endif; ?>

        <?php if ($meta['version'] !== ''): ?>
            <dt>版本</dt><dd><?= h($meta['version']) ?></dd>
        <?php endif; ?>

        <?php if ($meta['status'] !== ''): ?>
            <dt>状态</dt><dd><?= h($meta['status']) ?></dd>
        <?php endif; ?>

        <?php if ($meta['score'] > 0): ?>
            <dt>评分</dt><dd><?= h(rtrim(rtrim(number_format($meta['score'], 1, '.', ''), '0'), '.')) ?></dd>
        <?php endif; ?>

        <?php if ($meta['douban_url'] !== ''): ?>
            <dt>豆瓣</dt><dd>
                <a href="<?= h($meta['douban_url']) ?>" target="_blank" rel="noopener nofollow">
                    条目<?= $meta['douban_score'] > 0 ? ' · ' . h($meta['douban_score']) : '' ?> ↗
                </a>
            </dd>
        <?php endif; ?>

        <?php if ($meta['pinyin'] !== ''): ?>
            <dt>拼音</dt><dd><?= h($meta['pinyin']) ?><?= $meta['letter'] !== '' ? '（' . h($meta['letter']) . '）' : '' ?></dd>
        <?php endif; ?>

        <?php if ($meta['time_add'] !== ''): ?>
            <dt>收录</dt><dd><?= h($meta['time_add']) ?></dd>
        <?php elseif ($meta['time'] !== ''): ?>
            <dt>更新</dt><dd><?= h($meta['time']) ?></dd>
        <?php endif; ?>

        <?php
        // 同 vod_meta：vod_play_from 是逗号分隔的来源名，必须先切分再展示。
        $pfList = playFromList($meta['play_from'] ?? '');
        if ($pfList): ?>
            <dt>播放来源</dt>
            <dd><?= implode('、', array_map('h', $pfList)) ?>
                <?= $meta['play_server'] !== '' && $meta['play_server'] !== 'no' ? ' · ' . h($meta['play_server']) : '' ?></dd>
        <?php endif; ?>

        <?php if ($meta['letter'] !== '' && $meta['pinyin'] === ''): ?>
            <dt>首字母</dt><dd><?= h($meta['letter']) ?></dd>
        <?php endif; ?>

        <dt>数据源</dt><dd><?= h(($source['name'] ?? '') ?: '未知') ?></dd>
    </dl>
</aside>
