<?php
/**
 * 搜索页：通过接口的 wd 参数搜索影片
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/client.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/template.php';

requireAccess();

// 支柱六：搜索是最容易被爬虫放大的入口（每次搜索 = 1 个 EP + 1 次上游调用）。
// 20 次/分钟/IP 对真人绰绰有余；超限返回 429 而不是 503，
// 日志里就能分清「被限流」和「EP 打满」。
guardCheck('search', 20);

// ★ 1.5.6：`wd` 钳到 50 字符 —— 原来无上限，超长 URL 会让上游 414、
//   也会在缓存文件名里制造异常。50 字对片名搜索绰绰有余。
$wd       = mb_substr(trim((string) (is_scalar($_GET['wd'] ?? null) ? $_GET['wd'] : '')), 0, 50, 'UTF-8');
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
    // ⚠ 1.5.6：直接用上游 `wd`，其语义是**片名前缀匹配**（见 docs/api-sources.md）。
    //   实测（vodhub.gt.tc / source=1）：
    //     wd=理想        → 10 条（全是「理想X」开头）
    //     wd=理想照耀     →  1 条 ✅ 理想照耀中国
    //     wd=照耀        →  0 条（「照耀」在片名中间，前缀匹配命中不到）
    //     wd=中国        → 105 条，但**全是「中国X」开头**，不含「理想照耀中国」
    //   这是上游接口的语义限制（MacCMS 系 `LIKE '关键词%'`），站内无法通过换参数绕过。
    //   真正能搜到「照耀」的方案见 docs/api-sources.md「搜索」一节的 A / B 两条。
    $data = $client->getList(0, $page, $wd);
    $searchMsg = '';
} else {
    $data = ['list' => [], 'pagecount' => 1, 'total' => 0, 'limit' => 20, 'page' => $page];
    $searchMsg = '';
}

$list      = $data['list'] ?? [];
$pagecount = max(1, intval($data['pagecount'] ?? 1));
$total     = intval($data['total'] ?? 0);
$searchMsg = $searchMsg ?? '';

$siteTitle = setting('site_title', APP_NAME);
$pageTitle = $wd !== '' ? '搜索：' . $wd : '搜索';

renderTemplate('search', [
    'wd'        => $wd,
    'searchMsg' => $searchMsg,
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
