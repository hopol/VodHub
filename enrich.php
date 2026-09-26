<?php
/**
 * 字段归一化接口（播放页异步调用）
 *
 * 为什么是异步的：
 *   System One 单次往返实测 1.2~1.5 秒，同步塞进播放页会把它拖到 2.5 秒以上，
 *   而且外网端点一旦变慢，用户点开的每个新影片都会跟着卡。
 *   所以播放页只读本地缓存先渲染，再由 static/app.js 请求本接口补齐归一化结果；
 *   缓存命中时本接口几乎无延迟，未命中才真正联网，失败则静默保持原始字段。
 *
 * 返回两段 HTML（对应播放页的两个容器），而不是 JSON 字段表：
 *   片段的排版属于模板，前端只负责整体替换，避免 JS 里复刻一遍渲染逻辑。
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/fields.php';
require_once __DIR__ . '/includes/enrich.php';
require_once __DIR__ . '/includes/template.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** 统一失败出口 */
function enrichFail(int $code, string $msg): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

requireAccess();

if (!enrichEnabled()) {
    enrichFail(200, 'disabled');   // 后台关掉了，前端不必重试
}

$sourceId = intval($_GET['source'] ?? 0);
$vodId    = intval($_GET['id'] ?? 0);
if ($sourceId <= 0 || $vodId <= 0) {
    enrichFail(400, '参数缺失');
}

$source = getSource($sourceId);
if (!$source || !$source['enabled']) {
    enrichFail(404, '数据源不存在');
}

$client = new VodClient($source['api_url']);
$detail = $client->getDetail($vodId);
if (!$detail) {
    enrichFail(404, '影片不存在');
}

// 缓存未命中时在这里才真正调模型；失败会写负缓存并返回空结果
$enrich = enrichRun($sourceId, $vodId, $detail);
if ($enrich === []) {
    $enrich = null;
}

$types = $client->getTypes();
$meta  = buildMeta($detail, $sourceId, $types, $enrich);
$playUrls = parsePlayUrl($detail['vod_play_url'] ?? '');
$tplName  = resolveTemplate($source);

ob_start();
tplPartial('vod_meta', $tplName, ['meta' => $meta, 'source' => $source, 'sourceId' => $sourceId]);
$chips = (string) ob_get_clean();

ob_start();
tplPartial('vod_side', $tplName, [
    'meta' => $meta, 'detail' => $detail, 'source' => $source,
    'sourceId' => $sourceId, 'playCount' => count($playUrls),
]);
$side = (string) ob_get_clean();

// 只回传**依赖富化**的两段。别名、演职员、简介由 buildMeta() 纯代码算出，
// 播放页首屏就已渲染正确，没必要让前端再等一次网络。
echo json_encode([
    'ok'      => true,
    'chips'   => $chips,
    'side'    => $side,
    'enriched' => $meta['enriched'],
], JSON_UNESCAPED_UNICODE);
