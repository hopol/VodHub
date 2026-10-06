<?php
/**
 * 播放页：视频详情 + m3u8 播放器
 *
 * 数据分两层准备（1.5.0 起不再有第三层模型归一化）：
 *   1. 上游原始字段  —— VodClient::getDetail()
 *   2. 确定性解析    —— buildMeta()（includes/fields.php，不联网）
 *
 * 整个渲染过程**不发起任何模型请求**，播放页的耗时只由上游接口决定。
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/fields.php';
require_once __DIR__ . '/includes/template.php';

requireAccess();

// 整页出站墙钟起表（2026-09-30 线上 502 根治）：
// 从页面进来到整页出站锁在 client.php 的 PAGE_DEADLINE_BUDGET（2.5s）内，
// 稳压在 OpenResty ~3s 网关，绝不因某个慢源把整页拖到 502。
VodClient::pageBudgetStart();

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

$meta    = buildMeta($detail, $sourceId, $types);

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
