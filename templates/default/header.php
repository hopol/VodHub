<?php
/**
 * default 模板 - 公共头部（**全站唯一一份**，1.5.1 起）
 *
 * 变量：$siteTitle, $pageTitle, $sources, $currentSourceId, $tplMeta
 *
 * 1.5.1 之前这里有 5 份几乎相同的 header.php，各模板只改两处：
 * 主题样式表与搜索框 placeholder。现在这两处收进 theme.json 的
 * `style` / `search_ph`，各模板的 header.php 已删除，由 tplInclude() 回退到本文件。
 *
 * ⚠ 本文件是那 4 套模板**唯一的头部来源** —— 改它等于改全站 5 套主题的头部。
 *   要改某一套的主题外观，改它的 theme.json 或 style.css，不要复制本文件。
 *
 * ⚠ 三段注入的**顺序不能调**（CSS 层叠）：
 *   ① 站点设置覆盖列数  ② 主题样式表  ③ 后台模板编辑器写入的 CSS 变量
 *   ③ 在最后才能压过主题自己的 --xxx-columns，这正是「模板编辑器优先级最高」的实现。
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
    <?php if (!empty($tplMeta['style'])): ?>
    <!-- 主题样式表（theme.json 的 style 键）。必须在①之后、③之前：它要压过
         站点设置的列数，但又要被后台模板编辑器写入的变量压过。 -->
    <link rel="stylesheet" href="<?= h($tplMeta['style']) ?>">
    <?php endif; ?>
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
            <input type="text" name="wd" placeholder="<?= h($tplMeta['search_ph'] !== '' ? $tplMeta['search_ph'] : '搜索…') ?>"
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
