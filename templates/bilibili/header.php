<?php
/**
 * bilibili 模板 - 公共头部
 * 与 default 结构一致，额外引入模板自带样式覆盖默认深色主题
 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="referrer" content="no-referrer">
    <title><?= h($pageTitle ?? $siteTitle) ?> - <?= h($siteTitle) ?></title>
    <link rel="stylesheet" href="static/style.css">
    <?php if (intval($tplMeta['adminColumns']) > 0): ?>
    <!-- 站点设置覆盖列数：优先级最高 -->
    <style>:root { --list-columns: <?= h((string) $tplMeta['adminColumns']) ?>; }</style>
    <?php endif; ?>
    <!-- 否则不注入，交给模板自己的 --xxx-columns（由后台"模板编辑"写入）作为回退值 -->
    <link rel="stylesheet" href="templates/bilibili/style.css">
    <?php if (!empty($tplMeta['vars'])): ?>
    <!-- 后台模板编辑器写入的 CSS 变量覆盖 -->
    <style>:root { <?php foreach ($tplMeta['vars'] as $k => $v): echo h($k), ':', h($v), ';'; endforeach; ?> }</style>
    <?php endif; ?>
</head>
<body>
<header class="topbar">
    <div class="topbar-inner">
        <a class="brand" href="index.php">
            <span class="brand-icon">▶</span>
            <span class="brand-text"><?= h($siteTitle) ?></span>
        </a>
        <form class="search-box" action="search.php" method="get" role="search">
            <input type="text" name="wd" placeholder="搜索影片名称…"
                   value="<?= h($_GET['wd'] ?? '') ?>" autocomplete="off">
            <button type="submit" aria-label="搜索">🔍</button>
        </form>
        <nav class="topbar-nav">
            <a href="index.php">分类</a>
            <a href="history.php">历史</a>
            <a href="admin.php">后台</a>
        </nav>
    </div>
    <?php
    if ($sources && count($sources) > 1):
        $groups = getGroups();
        // 按 group_id 分桶，未分组（group_id = 0）放最后
        $buckets = [];
        foreach ($sources as $s) {
            $g = intval($s['group_id'] ?? 0);
            $buckets[$g][] = $s;
        }
        // 按分组排序：有组的按组 sort，未分组固定放末尾
        $order = [];
        foreach ($groups as $g) {
            $gid = intval($g['id']);
            if (!empty($buckets[$gid])) {
                $order[] = ['name' => $g['name'], 'items' => $buckets[$gid]];
            }
        }
        if (!empty($buckets[0])) {
            $order[] = ['name' => '全部', 'items' => $buckets[0]];
        }
        // 若所有源都没分组，退化为不分组显示（避免多出一个"全部"标签）
        if (count($groups) === 0) {
            $order = [['name' => '', 'items' => $sources]];
        }
    ?>
        <div class="source-tabs">
            <?php foreach ($order as $g): ?>
                <?php if ($g['name'] !== ''): ?>
                    <span class="source-group-label"><?= h($g['name']) ?></span>
                <?php endif; ?>
                <?php foreach ($g['items'] as $s): ?>
                    <a class="source-tab <?= $currentSourceId === intval($s['id']) ? 'active' : '' ?>"
                       href="index.php?source=<?= intval($s['id']) ?>"><?= h($s['name']) ?></a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</header>
<main class="container">
