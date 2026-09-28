<?php
/**
 * 工具函数
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/imgcache.php';   // coverUrl() 要判断本地是否已有该图

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
function parsePlayUrl(?string $playUrl): array {
    $result = [];
    $groups = preg_split('/[#\r\n]+/', (string) $playUrl);
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
            $result[] = [
                'label' => trim(substr($group, 0, $pos)),
                'url'   => trim(substr($group, $pos + 1)),
            ];
        }
    }
    return $result;
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
    return true;
}
