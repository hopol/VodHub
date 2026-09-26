<?php
/**
 * 播放历史与收藏（纯前端 localStorage，服务端只提供壳页面）
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/template.php';

requireAccess();

$siteTitle = setting('site_title', APP_NAME);
$pageTitle = '历史与收藏';

renderTemplate('history', [
    'siteTitle' => $siteTitle,
    'pageTitle' => $pageTitle,
    'sources'   => getSources(true),
    'source'    => null,
    'sourceId'  => 0,
    'currentSourceId' => intval($_GET['source'] ?? 0),
]);
