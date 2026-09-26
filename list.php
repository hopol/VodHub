<?php
/**
 * 内容列表页：某数据源某分类下的影片列表，支持分页
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/template.php';

requireAccess();

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

$siteTitle = setting('site_title', APP_NAME);
$pageTitle = ($curName !== '全部' ? $curName . ' - ' : '') . ($source ? $source['name'] : '数据源不存在');

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
