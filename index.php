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

// 整页出站墙钟起表（2026-09-30 线上 502 根治）：
// 从页面进来到整页出站锁在 client.php 的 PAGE_DEADLINE_BUDGET（2.5s）内，
// 稳压在 OpenResty ~3s 网关，绝不因某个慢源把整页拖到 502。
VodClient::pageBudgetStart();

$sources = getSources(true);

// 指定了源就只展示该源，否则展示全部。
// ⚠ 页头的「数据源」标签栏是按 $sources 渲染的（count($sources) > 1 才显示），
//   所以**过滤后的列表绝不能直接传给模板** —— 否则 ?source=N 页面上标签栏整个消失，
//   点进去就再也切不回别的源（1.3.1 及更早一直是这样，2026-09-29 修）。
//   现在：$sources = 全部源（页头导航用），$pageSources = 过滤后的（内容区用）。
$sourceId = intval($_GET['source'] ?? 0);
$pageSources = $sources;
if ($sourceId > 0) {
    $pageSources = array_values(array_filter(
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
    'sources'         => $sources,        // 页头数据源标签栏要用**全部**
    'pageSources'     => $pageSources,    // 内容区只渲染选中的那个源
    'sourceId'        => $sourceId,
    'siteTitle'       => $siteTitle,
    'pageTitle'       => $pageTitle,
    'currentSourceId' => $sourceId,
]);
