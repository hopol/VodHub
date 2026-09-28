<?php
/**
 * 播放页：视频详情 + m3u8 播放器
 *
 * 数据分三层准备：
 *   1. 上游原始字段        —— VodClient::getDetail()
 *   2. 确定性解析          —— buildMeta()（includes/fields.php，不联网）
 *   3. 语义归一化          —— enrichCached()（只读缓存，不联网）
 *
 * 第 3 层刻意**不在这里调用模型**：它要 1 秒多，会把播放页拖慢。
 * 页面先用 1、2 层渲染，随后由 static/app.js 请求 enrich.php 异步取回，
 * 命中缓存时是同一次请求内返回，未命中时才真正去问 System One。
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/fields.php';
require_once __DIR__ . '/includes/enrich.php';
require_once __DIR__ . '/includes/template.php';

requireAccess();

// 支柱六：播放页每个请求还会跟一次上游 detail，30 次/分钟/IP 兜底
guardCheck('play', 30);

$sourceId = intval($_GET['source'] ?? 0);
$vodId    = intval($_GET['id'] ?? 0);

$source = getSource($sourceId);
if ($source && !$source['enabled']) {
    $source = null;
}

$detail = null;
if ($source && $vodId > 0) {
    $client = new VodClient($source['api_url']);
    $detail = $client->getDetail($vodId);
}

if (!$source || !$detail) {
    // 交给模板展示空状态，模板内部会判断 $detail 是否为空
    $siteTitle = setting('site_title', APP_NAME);
    renderTemplate('play', [
        'source'    => null,
        'sourceId'  => $sourceId,
        'vodId'     => $vodId,
        'enrichPending' => false,          // 空详情页没有可富化的东西
        'detail'    => null,
        'name'      => '影片不存在',
        'pic'       => '',
        'playUrls'  => [],
        'types'     => [],
        'typeName'  => '',
        'desc'      => '',
        'meta'      => [],
        'playCount' => 0,
        'autoplay'  => false,
        'siteTitle' => $siteTitle,
        'pageTitle' => '页面不存在',
        'sources'   => getSources(true),
        'currentSourceId' => $sourceId,
    ]);
    exit;
}

$playUrls = parsePlayUrl($detail['vod_play_url'] ?? '');
$types    = $client->getTypes();

// 只读缓存：命中就直接用，没命中也不在这里联网（见文件头注释）
$enrich = enrichCached($sourceId, $vodId);
if ($enrich === []) {
    $enrich = null;   // 负缓存：上次失败，等同「没富化」
}

$meta    = buildMeta($detail, $sourceId, $types, $enrich);

// 支柱三：富化结果**已在缓存里**时，首屏直接就是归一化的字段，
// 于是不再让浏览器去请求 enrich.php（那是一次纯浪费的 EP —— 数据早就在
// 服务端手上了）。只有真的没有可用缓存时才标 pending，由 app.js 异步回填。
$enrichPending = ($enrich === null);
$name    = $meta['name'] !== '' ? $meta['name'] : cleanTitle($detail['vod_name'] ?? '');
$pic     = $meta['pic'];
$typeName = typeName($types, intval($detail['type_id'] ?? 0));
$autoplay = setting('player_autoplay', '1') === '1';
$desc    = $meta['desc'] !== '' ? $meta['desc'] : '暂无简介';

$siteTitle = setting('site_title', APP_NAME);
$pageTitle = $name;

renderTemplate('play', [
    'source'    => $source,
    'sourceId'  => $sourceId,
    'vodId'     => $vodId,
    'enrichPending' => $enrichPending,
    'detail'    => $detail,
    'name'      => $name,
    'pic'       => $pic,
    'playUrls'  => $playUrls,
    'playCount' => count($playUrls),
    'types'     => $types,
    'typeName'  => $typeName,
    'desc'      => $desc,
    'meta'      => $meta,
    'autoplay'  => $autoplay,
    'siteTitle' => $siteTitle,
    'pageTitle' => $pageTitle,
    'sources'   => getSources(true),
    'currentSourceId' => $sourceId,
]);
