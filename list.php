<?php
/**
 * 内容列表页：某数据源某分类下的影片列表，支持分页
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/template.php';

requireAccess();

// 整页出站墙钟起表（2026-09-30 线上 502 根治）：
// 从页面进来到整页出站锁在 client.php 的 PAGE_DEADLINE_BUDGET（2.5s）内，
// 稳压在 OpenResty ~3s 网关，绝不因某个慢源把整页拖到 502。
VodClient::pageBudgetStart();

$sourceId = intval($_GET['source'] ?? 0);
$typeId   = intval($_GET['type'] ?? 0);
$page     = max(1, intval($_GET['page'] ?? 1));

$source = getSource($sourceId);
if (!$source || !$source['enabled']) {
    // 数据源不存在时仍走模板，由模板展示空状态
    $source = null;
}

if ($source) {
    $client = new VodClient($source['api_url']);
    $data   = $client->getList($typeId, $page);
    $types  = $client->getTypes();
} else {
    $data = ['list' => [], 'pagecount' => 1, 'total' => 0, 'limit' => 20, 'page' => $page];
    $types = [];
}

$list      = $data['list'] ?? [];
$pagecount = max(1, intval($data['pagecount'] ?? 1));
$total     = intval($data['total'] ?? 0);
$limit     = max(1, intval($data['limit'] ?? 20));
$curName   = typeName($types, $typeId);

// ★ 1.5.6：残页判定（P0）。上游抖一下，这一页渲染出来就是「暂无内容」，
//   而 renderTemplate() 会把它写进 c/<时间桶>/ —— **接下来一整小时内所有访客
//   都看到空页面**，即使上游 1 秒后就恢复了。
//
//   这等于把「临时故障」变成「一小时的持久故障」，而且用户会把「暂无内容」
//   理解成「这站没片」。免费主机 + 第三方上游的组合里，上游抖动是常态不是异常。
//
//   ⚠ 残页判据此前**只装在首页的 getTypesLocal()**，列表页/播放页完全没有 ——
//     而 `client->lastMsg()` 明明是为此准备的对外接口，一次都没用过。
//   ⚠ 判据用「上游有没有报错」而不是「list 是否为空」：空列表也可能是
//     「这个分类本来就 0 部片」，那是正常状态、该缓存。
if ($source && $client->lastMsg() !== '') {
    pcVolatile();
}
// 分类拿不到 → 筛选条消失，同样是残页（分类是 24h 缓存的，正常不该空）
if ($source && !$types) {
    pcVolatile();
}

$siteTitle = setting('site_title', APP_NAME);
$pageTitle = ($curName !== '全部' ? $curName . ' - ' : '') . ($source ? $source['name'] : '数据源不存在');

// ★ 1.5.6：源不存在/已禁用时返回 404（原来是 200）。
//   空态页面返回 200 会让搜索引擎把它当正常页收录、也让可用性监控看不出问题。
//   ⚠ 只在「源真的不存在」时 404；「分类下没片」是正常状态，仍是 200。
if (!$source) {
    http_response_code(404);
}

renderTemplate('list', [
    'source'         => $source,
    'sourceId'       => $sourceId,
    'typeId'         => $typeId,
    'page'           => $page,
    'data'           => $data,
    'types'          => $types,
    'list'           => $list,
    'pagecount'      => $pagecount,
    'total'          => $total,
    'limit'          => $limit,
    'curName'        => $curName,
    'siteTitle'      => $siteTitle,
    'pageTitle'      => $pageTitle,
    'sources'        => getSources(true),
    'currentSourceId' => $sourceId,
]);
