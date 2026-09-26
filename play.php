<?php
/**
 * 播放页：视频详情 + m3u8 播放器
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/template.php';

requireAccess();

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
        'autoplay'  => false,
        'siteTitle' => $siteTitle,
        'pageTitle' => '页面不存在',
        'sources'   => getSources(true),
        'currentSourceId' => $sourceId,
    ]);
    exit;
}

$name     = cleanTitle($detail['vod_name'] ?? '');
$pic      = coverUrl($detail['vod_pic'] ?? '', $sourceId);
$playUrls = parsePlayUrl($detail['vod_play_url'] ?? '');
$types    = $client->getTypes();
$typeName = typeName($types, intval($detail['type_id'] ?? 0));
$autoplay = setting('player_autoplay', '1') === '1';

// 简介优先级：vod_content > vod_blurb > 占位文字
$contentText = trim((string) ($detail['vod_content'] ?? ''));
$blurbText   = trim((string) ($detail['vod_blurb'] ?? ''));
if ($contentText !== '' && $contentText !== '暂无') {
    $desc = $contentText;
} elseif ($blurbText !== '' && $blurbText !== '暂无') {
    $desc = $blurbText;
} else {
    $desc = '暂无简介';
}

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
    'types'     => $types,
    'typeName'  => $typeName,
    'desc'      => $desc,
    'autoplay'  => $autoplay,
    'siteTitle' => $siteTitle,
    'pageTitle' => $pageTitle,
    'sources'   => getSources(true),
    'currentSourceId' => $sourceId,
]);
