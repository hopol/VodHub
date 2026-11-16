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
    // ★ 1.5.6：残页判定（P0）。上游抖一下时 getDetail() 走到 emptyResult()，
    //   这一页渲染成「影片不存在」，而 renderTemplate() 会把它写进 c/<时间桶>/ ——
    //   **接下来一整小时所有访客都看到「影片不存在」**，即使上游 1 秒后恢复。
    //
    //   ⚠ 判据用「上游有没有报错」而不是「detail 是否为空」：detail 为空也可能是
    //     「这 id 真的不存在」，那是正常状态、该缓存（也省得每次重问上游）。
    if ($source && $client->lastMsg() !== '') {
        pcVolatile();
    }

    // ★ 1.5.6：空态返回 404（原来是 200），理由同 list.php
    http_response_code(404);

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

// ★ 1.5.6 残页判定续：详情拿到了、但 `vod_play_url` 解析出 0 集。
//   页面写「该影片暂无可播放地址」，同样不该被冻一小时 ——
//   上游可能只是这一批没给播放地址（或用了我们不认识的线路分隔符）。
if (!$playUrls) {
    pcVolatile();
}
// 分类拿不到 → 分类名/chips 缺失，同样是残页
if (!$types) {
    pcVolatile();
}

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
