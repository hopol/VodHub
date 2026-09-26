<?php
/**
 * 搜索页：通过接口的 wd 参数搜索影片
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/template.php';

requireAccess();

$wd       = trim((string) ($_GET['wd'] ?? ''));
$sourceId = intval($_GET['source'] ?? 0);
$page     = max(1, intval($_GET['page'] ?? 1));

$sources = getSources(true);
$source = getSource($sourceId);

// 未指定源时默认取第一个
if ((!$source || !$source['enabled']) && $sources) {
    $source = $sources[0];
    $sourceId = intval($source['id']);
}

if ($source && $wd !== '') {
    $client = new VodClient($source['api_url']);
    $data = $client->getList(0, $page, $wd);
} else {
    $data = ['list' => [], 'pagecount' => 1, 'total' => 0, 'limit' => 20, 'page' => $page];
}

$list      = $data['list'] ?? [];
$pagecount = max(1, intval($data['pagecount'] ?? 1));
$total     = intval($data['total'] ?? 0);

$siteTitle = setting('site_title', APP_NAME);
$pageTitle = $wd !== '' ? '搜索：' . $wd : '搜索';

renderTemplate('search', [
    'wd'        => $wd,
    'sources'   => $sources,
    'source'    => $source,
    'sourceId'  => $sourceId,
    'page'      => $page,
    'data'      => $data,
    'list'      => $list,
    'pagecount' => $pagecount,
    'total'     => $total,
    'siteTitle' => $siteTitle,
    'pageTitle' => $pageTitle,
    'currentSourceId' => $sourceId,
]);
