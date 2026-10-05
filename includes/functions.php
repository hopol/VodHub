<?php
/**
 * 工具函数
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/imgcache.php';   // coverUrl() 要判断本地是否已有该图
require_once __DIR__ . '/pagecache.php';  // learnImgHost() 要作废页面静态缓存

if (!function_exists('h')) {
    /** HTML 转义，防止 XSS */
    function h(?string $s): string {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/** 标题清洗：去除人为断词符（如 "刚认.识" → "刚认识"） */
function cleanTitle(?string $title): string {
    $t = (string) $title;
    $t = preg_replace('/[\s._\-]+/u', '', $t);
    return trim($t);
}

/**
 * 时长 → 分钟数。
 *
 * 兼容 `45`、`45分钟`、`1小时30分`、`1h30m`、`95 分钟`。
 * 纯 `intval()` 会把 `1小时30分` 读成 1 分钟，所以要先认单位。
 */
function parseDuration(?string $s): int {
    $s = trim((string) $s);
    if ($s === '') {
        return 0;
    }
    // 显式小时 + 分钟
    if (preg_match('/(\d+)\s*(?:小时|時|h)\s*(?:(\d+)\s*(?:分|分钟|m))?/iu', $s, $m)) {
        return intval($m[1]) * 60 + intval($m[2] ?? 0);
    }
    // 显式分钟
    if (preg_match('/(\d+)\s*(?:分钟|分鐘|分|min)/iu', $s, $m)) {
        return intval($m[1]);
    }
    // 纯数字：当作分钟
    if (preg_match('/^\d+$/', $s)) {
        return intval($s);
    }
    return 0;
}

/**
 * 时长文案。查不到返回空串（调用方自行决定是否渲染），
 * 不再返回「未知」—— 否则列表页会满屏占位角标。
 */
function formatDuration(?string $minutes): string {
    $mins = parseDuration($minutes);
    if ($mins <= 0) {
        return '';
    }
    if ($mins < 60) {
        return $mins . '分钟';
    }
    // 整点小时不要写成「1小时00分」
    if ($mins % 60 === 0) {
        return intdiv($mins, 60) . '小时';
    }
    return sprintf('%d小时%02d分', intdiv($mins, 60), $mins % 60);
}

/** 数字简化：609 → 609，12345 → 1.2万 */
function formatNumber(?int $n): string {
    $n = intval((string) $n);
    if ($n < 10000) {
        return (string) $n;
    }
    return round($n / 10000, 1) . '万';
}

/**
 * 解析 vod_play_url
 * 格式："正片$https://xxx/index.m3u8"，多组以 # 或换行分隔
 * 返回：[ ['label' => '正片', 'url' => 'https://...'], ... ]
 */
/**
 * 把 `vod_play_from` 归一化成来源名数组。
 *
 * 与 `vod_play_url`（用 `$` 分隔「名$地址」、用 `#` 分隔多组）**不是同一种格式**：
 * `vod_play_from` 是**逗号分隔的来源名**（"蓝光,高清,4K"）。
 *
 * 1.3.3 之前直接把原始串输出到页面，于是：
 *   · "蓝光,高清" 整串当一个 chip，访客看不出是两个源；
 *   · "蓝光$$1080P" 这种上游脏数据（多一个 `$`）会被当成「名=蓝光、地址=$1080P」。
 *
 * 切分后去空、去重、去纯符号，保留原顺序。
 *
 * @return string[]
 */
function playFromList(?string $playFrom): array {
    if ($playFrom === null) {
        return [];
    }
    $raw = trim((string) $playFrom);
    if ($raw === '') {
        return [];
    }
    // 兼容三种分隔：逗号、井号、竖线（部分源用 | 分隔）
    // 先按来源名分隔符切：逗号 / 井号 / 竖线
    $parts = preg_split('/[,#|]+/', $raw) ?: [];
    $seen = [];
    $out  = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        // ⚠️ 上游脏数据常见「蓝光$1080P」「蓝光$$1080P」—— 它把**清晰度**
        //    也写进了来源名（本该在 vod_play_url 里用 `$` 分隔）。
        //    来源名里不该出现 `$`，把尾部的 `$xxx`（一个或多个 `$` + 非分隔符）
        //    整段剥掉，直到没有 `$` 为止。
        $prev = null;
        while ($p !== $prev) {
            $prev = $p;
            $p    = (string) preg_replace('/\$+[^,#|]*$/', '', $p);
        }
        $p = trim($p);
        if ($p === '') {
            continue;          // 整段都是 `$` 的脏数据
        }
        if (preg_match('/^[\$#|,]+$/', $p)) {
            continue;          // 纯符号残留
        }
        if (isset($seen[$p])) {
            continue;
        }
        $seen[$p] = true;
        $out[] = $p;
    }
    return $out;
}

/**
 * 按**播放线路**切开上游的 `vod_play_url`。
 *
 * ⚠️ `$$$` 有两种含义，形状完全一样，必须靠位置区分：
 *   1. MacCMS 的**线路分隔符**（上游用它并接多套播放器）—— 前面已经有完整的「集$地址」；
 *   2. 「名$$$地址」脏数据里那个分隔符（1.3.3 修过的那类）—— 前面只有一个集名。
 * 判据就是**前面那段里有没有 `$`**：没有 `$` 说明前面连地址都还没开始，
 * 那它不可能是「线路之间的分隔」，只能是标签和地址之间的那个。
 *
 * 关键在**切的顺序**：必须先按线路切、再按集数切。反过来会切出垃圾——
 * 上游形如 `线路1的各集#…#线路1最后一集$地址N$$$线路2各集#…`，
 * 先按 `#` 切的话 `$$$` 会落进「线路1最后一集」那一组，
 * 于是那一集的地址变成 `地址N$$$线路2第1集$地址1`，播放器拿到一段不存在的 URL。
 * 实测症状：整部片子只有**这一集**（多集片）或**整部**（单集片）播不出来。
 */
function splitPlayLines(string $raw): array {
    $raw   = trim($raw);
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/\$\$\$(?=[^$])/', $raw) ?: [$raw];
    $lines = [];
    $cur   = '';
    foreach ($parts as $p) {
        if ($cur !== '' && !str_contains($cur, '$')) {
            // 上一段还没有「标签$地址」结构 → 这是标签后的分隔符，并回上一段
            $cur .= '$$$' . $p;
            continue;
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        $cur = $p;
    }
    if ($cur !== '') {
        $lines[] = $cur;
    }
    return $lines;
}

/** 一条线路内的集数：`标签$地址`，集数之间用 `#` 或换行分隔 */
function parsePlayEpisodes(string $line): array {
    $result = [];
    $groups = preg_split('/[#\r\n]+/', $line);
    foreach ($groups as $group) {
        $group = trim($group);
        if ($group === '') {
            continue;
        }
        $pos = strpos($group, '$');
        if ($pos === false) {
            // 没有分隔符，整串当作地址
            $result[] = ['label' => '播放', 'url' => $group];
        } else {
            $label = trim(substr($group, 0, $pos));
            // ⚠️ 上游脏数据常见「名$$地址」（多一个 `$`）—— 若按第一个 `$` 切，
            // 地址段会变成 `$地址`，播放器拿去拼 URL 就坏了。
            // 这里把**连续的 `$` 当作一个分隔符**处理，地址段从第一个非 `$` 字符取起。
            $rest = substr($group, $pos + 1);
            $rest = preg_replace('/^\$+/', '', $rest) ?? '';
            $result[] = [
                'label' => $label !== '' ? $label : '播放',
                'url'   => trim($rest),
            ];
        }
    }
    return $result;
}

/** hls.js / 原生能直接播的直链。分享页那种 HTML 页面不在内——喂给播放器只会静默失败 */
function isDirectPlayUrl(string $url): bool {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
    return (bool) preg_match('/\.(m3u8|mp4)$/i', $path);
}

/**
 * 解析 `vod_play_url`。
 *
 * 上游一条影片常给**多套线路**，第一套给的是分享页地址（`/share/xxx` 或
 * `/play/xxx`，浏览器打开是个 HTML 播放页，不是流），后面几套才是 m3u8 直链。
 * 播放器只吃直链，所以这里在多套线路里挑**可直接播的比例最高**的那一套；
 * 一套都不可播时保留第一条，行为与从前一致（不制造新的空状态）。
 */
function parsePlayUrl(?string $playUrl): array {
    $best      = [];
    $bestScore = -1.0;
    foreach (splitPlayLines((string) $playUrl) as $line) {
        $eps = parsePlayEpisodes($line);
        if ($eps === []) {
            continue;
        }
        $direct = count(array_filter($eps, static fn (array $e): bool => isDirectPlayUrl($e['url'])));
        $score  = $direct / count($eps);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best      = $eps;
        }
    }
    return $best;
}

/** 分页组件 */
function renderPagination(int $page, int $pagecount, string $baseUrl): string {
    if ($pagecount <= 1) {
        return '';
    }
    $sep = str_contains($baseUrl, '?') ? '&' : '?';
    $url = fn (int $p) => $baseUrl . $sep . 'page=' . $p;

    $start = max(1, $page - 4);
    $end = min($pagecount, $page + 4);
    if ($end - $start < 8) {
        $start = max(1, $end - 8);
    }

    $html = '<div class="pagination">';
    if ($page > 1) {
        $html .= '<a class="pg" href="' . h($url($page - 1)) . '">‹ 上一页</a>';
    }
    if ($start > 1) {
        $html .= '<a class="pg" href="' . h($url(1)) . '">1</a>';
        if ($start > 2) {
            $html .= '<span class="pg-ellipsis">…</span>';
        }
    }
    for ($i = $start; $i <= $end; $i++) {
        $cls = $i === $page ? ' active' : '';
        $html .= '<a class="pg' . $cls . '" href="' . h($url($i)) . '">' . $i . '</a>';
    }
    if ($end < $pagecount) {
        if ($end < $pagecount - 1) {
            $html .= '<span class="pg-ellipsis">…</span>';
        }
        $html .= '<a class="pg" href="' . h($url($pagecount)) . '">' . $pagecount . '</a>';
    }
    if ($page < $pagecount) {
        $html .= '<a class="pg" href="' . h($url($page + 1)) . '">下一页 ›</a>';
    }
    return $html . '</div>';
}

/** 取封面图，空则返回占位图 */
/**
 * 取封面图地址
 *
 * 三级策略（极致低功耗模式 · 支柱二）：
 *   1) 该源未开图片代理 → 直接返回上游原始地址（浏览器直连，本站零开销）
 *   2) 开了代理，且本地已有副本 → 直出 static/imgcache/<hash>.<ext>
 *      Apache 静态服务：**0 EP、0 出站**，这是本函数存在的全部意义
 *   3) 开了代理，本地还没有 → 走 img.php（第一次会完成 SSRF 三重校验、
 *      带 Referer 取图并落盘；此后这张图一生只产生 1 次 EP）
 *
 * 代理功能 100% 保留：防盗链照样绕过（图已在本站域下）、Referer 照样带上、
 * SSRF 三重校验照样执行 —— 只是从「每次」变成「首次」。
 *
 * @param string|null $pic      原始图片地址
 * @param int         $sourceId 该条数据所属的数据源 id，0 表示不走代理
 */
function coverUrl(?string $pic, int $sourceId = 0): string {
    $pic = trim((string) $pic);
    if ($pic === '') {
        return 'static/img/no-cover.svg';
    }

    if ($sourceId > 0) {
        $stmt = db()->prepare('SELECT img_proxy FROM sources WHERE id = ?');
        $stmt->execute([$sourceId]);
        $row = $stmt->fetch();
        if ($row && (int) $row['img_proxy'] === 1) {
            $local = imgCacheHit($pic);
            if ($local !== null) {
                return $local;   // ← 已本地化：Apache 静态直出，0 EP
            }
            return 'img.php?u=' . rawurlencode($pic) . '&s=' . $sourceId;
        }
    }
    return $pic;
}

/** 按分类 ID 查分类名 */
function typeName(array $types, int $typeId): string {
    foreach ($types as $t) {
        if (intval($t['type_id'] ?? -1) === $typeId) {
            return (string) ($t['type_name'] ?? '');
        }
    }
    return '全部';
}

/** 缓存目录占用大小（后台展示用） */
function cacheSize(): string {
    $bytes = 0;
    foreach ((glob(CACHE_DIR . '/*') ?: []) as $f) {
        if (is_file($f)) {
            $bytes += filesize($f);
        }
    }
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
}

/**
 * 清洗图片代理域名白名单
 * 允许逗号分隔；只保留形如 host 或 *.host 的片段，去掉协议、端口、路径。
 * 返回小写、去重、逗号连接的字符串。
 */
function cleanHostList(string $raw): string {
    $parts = preg_split('/[\s,;]+/', strtolower($raw)) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim($p, '. ');
        if ($p === '') {
            continue;
        }
        // 去掉误粘贴的协议与路径
        $p = preg_replace('#^https?://#', '', $p);
        $p = preg_replace('#^.*?/#', '', $p);
        $p = preg_replace('#:\d+$#', '', $p);  // 去端口
        $p = trim($p, '. ');
        if ($p === '' || !preg_match('/^[a-z0-9.\-]+$/', $p)) {
            continue;
        }
        if (!in_array($p, $out, true)) {
            $out[] = $p;
        }
    }
    return implode(',', $out);
}

/**
 * 自动学习图片域名：把实际代理到的域名追加进数据源的 img_hosts 白名单。
 *
 * 用途：很多人并不知道图片域名是什么，代理跑通后自动记录，
 * 后台就能看到"这个源实际用的图片域名"，也能切换成白名单加严模式。
 *
 * @return bool 是否真的写入了新域名
 */
function learnImgHost(int $sourceId, string $host): bool {
    $host = strtolower(trim($host, '. '));
    if ($sourceId <= 0 || $host === '' || !preg_match('/^[a-z0-9.\-]+$/', $host)) {
        return false;
    }
    // 已在白名单（含通配）则不重复写
    $row = getSource($sourceId);
    if (!$row) {
        return false;
    }
    $existing = array_filter(array_map('trim', explode(',', strtolower((string) $row['img_hosts']))));
    foreach ($existing as $pattern) {
        $pattern = trim($pattern, '. ');
        if ($pattern === '') {
            continue;
        }
        if ($host === $pattern || str_ends_with($host, '.' . $pattern)) {
            return false;  // 已覆盖，无需追加
        }
    }
    // 追加新域名（同时带上主域，让同源子域名也覆盖）
    $parts = explode('.', $host);
    if (count($parts) >= 3) {
        $suffix = implode('.', array_slice($parts, -2));
        $existing[] = $suffix;      // 如 example.com
    }
    $existing[] = $host;            // 如 img.example.com
    $existing = array_values(array_unique($existing));

    $stmt = db()->prepare('UPDATE sources SET img_hosts = ? WHERE id = ?');
    $stmt->execute([implode(',', $existing), $sourceId]);
    // 契约：改的是 img_hosts 白名单，走的是裸 SQL，绕过了 updateSource()。
    // 前台封面是否走 img.php 代理正是由它决定（coverUrl() 读 img_proxy，
    // img.php 按 img_hosts 放行），白名单变了却留着旧静态页，
    // 表现就是「后台识别到图片域名了，前台封面还是裂图」。
    pcClear();
    return true;
}
