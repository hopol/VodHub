<?php
/**
 * 首页：展示各数据源及其分类，点击分类进入内容列表
 *
 * 页面本身只负责准备数据，渲染交给模板系统。
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/template.php';

requireAccess();

$sources = getSources(true);

// 指定了源就只展示该源，否则展示全部
$sourceId = intval($_GET['source'] ?? 0);
if ($sourceId > 0) {
    $sources = array_values(array_filter(
        $sources,
        static fn (array $s): bool => intval($s['id']) === $sourceId
    ));
}

// 当前选中的数据源行。必须传给模板系统，否则 resolveTemplate() 收到 null，
// 会永远回落到「站点默认模板」——用户给数据源绑定的模板在首页就永远不生效。
// 用 getSource() 直接查库，避免上面的过滤把被选中的源剔除后拿不到行。
$source = $sourceId > 0 ? getSource($sourceId) : null;

$siteTitle = setting('site_title', APP_NAME);
$pageTitle = '分类浏览';

renderTemplate('index', [
    'source'          => $source,
    'sources'         => $sources,
    'sourceId'        => $sourceId,
    'siteTitle'       => $siteTitle,
    'pageTitle'       => $pageTitle,
    'currentSourceId' => $sourceId,
]);
