<?php
/**
 * 图片代理本地缓存（极致低功耗模式 · 支柱二）
 *
 * 目标：**代理照常工作，但只付一次钱。**
 *
 * 图片代理的本质需求是「这张图在本站域下能被浏览器取到」，
 * 而「每次都要 PHP 现场去取」只是实现手段。所以：
 *   第一次：coverUrl() 输出 img.php?u=...  → 1 个 EP + SSRF 三重校验 + 出站取图 + 落盘
 *   之后  ：coverUrl() 输出 static/imgcache/<hash>.<ext> → Apache 静态服务，0 EP、0 出站
 *
 * SSRF 校验只在**第一次**执行，且**本地已有文件不提供任何校验入口** ——
 * imgcache 里的文件永远只可能来自「已经通过三重校验的 URL」。
 *
 * 磁盘账（1 GB / 5 GB 两档的关键项）：
 *   单张约 100 KB，1 GB 档上限 60 MB（约 600 张）、5 GB 档 200 MB（约 2,000 张）。
 *   超限按 mtime LRU 淘汰；被淘汰的图下次访问会重新走一次 img.php 落盘 ——
 *   **自愈，功能永不缺失**，只是那一张图再付一次 EP。
 *
 * 落盘失败（磁盘满 / 无权限）静默降级为继续走 img.php 转发，功能不受影响。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/guard.php';

/** 图片缓存相对路径（浏览器可见） */
function imgCacheRelPath(string $url): string {
    return 'static/imgcache/' . imgCacheFileName($url);
}

/** 图片缓存绝对路径 */
function imgCachePath(string $url): string {
    return dirname(__DIR__) . '/' . imgCacheRelPath($url);
}

/**
 * 文件名 = sha1(原始URL) + 由 Content-Type 推出的扩展名。
 * 与 URL 一一对应 ⇒ 天然幂等，重复拉取覆盖同一文件。
 *
 * 扩展名走白名单：上游 Content-Type 只要不是已知图片类型就落 .bin，
 * 避免出现可被 Apache 当脚本执行的后缀。
 */
function imgCacheFileName(string $url, string $mime = ''): string {
    $ext = $mime !== '' ? imgCacheExt($mime) : '';
    if ($ext === '') {
        // 直出路径尚不知道 mime，用 URL 里的后缀猜；猜不出就留空，
        // 由 imgCacheHas() 按「前缀 + 任意已知扩展名」匹配。
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $guess = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($guess, imgCacheExts(), true)) {
            $ext = $guess;
        }
    }
    return sha1($url) . ($ext !== '' ? '.' . $ext : '');
}

/** 已知安全的图片扩展名白名单 */
function imgCacheExts(): array {
    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'svg', 'ico'];
}

/** Content-Type → 扩展名；未知类型返回 bin（无扩展名则无法被当脚本执行） */
function imgCacheExt(string $mime): string {
    $mime = strtolower(trim(explode(';', $mime)[0]));
    $map = [
        'image/jpeg' => 'jpg', 'image/jpg' => 'jpg',
        'image/png'  => 'png', 'image/gif' => 'gif',
        'image/webp' => 'webp', 'image/avif' => 'avif',
        'image/bmp'  => 'bmp', 'image/svg+xml' => 'svg',
        'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico',
    ];
    return $map[$mime] ?? 'bin';
}

/**
 * 本地是否已有该图。
 *
 * 找不到精确文件名时按 sha1 前缀扫一遍（扩展名可能因 URL 里没有后缀而不同），
 * 但只认白名单扩展名，绝不返回 .bin 之外的可疑文件。
 */
function imgCacheHas(string $url): bool {
    $exact = imgCachePath($url);
    if (is_file($exact)) {
        return true;
    }
    $dir = dirname(__DIR__) . '/static/imgcache';
    $prefix = sha1($url);
    foreach (imgCacheExts() as $ext) {
        if (is_file($dir . '/' . $prefix . '.' . $ext)) {
            return true;
        }
    }
    return false;
}

/**
 * 返回本地已存在的相对路径（不存在返回 null）。
 * coverUrl() 用它决定直出静态 URL 还是走 img.php。
 */
function imgCacheHit(string $url): ?string {
    $exact = imgCacheRelPath($url);
    if (is_file(dirname(__DIR__) . '/' . $exact)) {
        return $exact;
    }
    $dir = dirname(__DIR__) . '/static/imgcache';
    $prefix = sha1($url);
    foreach (imgCacheExts() as $ext) {
        $rel = 'static/imgcache/' . $prefix . '.' . $ext;
        if (is_file($dir . '/' . $prefix . '.' . $ext)) {
            return $rel;
        }
    }
    return null;
}

/**
 * 落盘。返回是否成功（失败时调用方继续走 img.php 转发即可）。
 *
 * 必须由调用方在**完成 SSRF 三重校验之后**调用；
 * 磁盘进保护模式时直接拒绝写入（guardCaps()['writable']）。
 */
function imgCachePut(string $url, string $body, string $mime): bool {
    if ($body === '' || !guardCaps()['writable']) {
        return false;
    }
    $dir = dirname(__DIR__) . '/static/imgcache';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return false;
    }
    $file = $dir . '/' . imgCacheFileName($url, $mime);
    $tmp  = $file . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * 按文件路径推断 Content-Type。
 *
 * 只认白名单扩展名；认不出就当 JPEG —— 落盘时的扩展名本来就来自通过校验的
 * image/* Content-Type，所以这里猜错的概率极低，且 nosniff 已经兜住解析风险。
 */
function imgCacheMimeForPath(string $path): string {
    $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    $map = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',  'gif' => 'image/gif',
        'webp' => 'image/webp', 'avif' => 'image/avif',
        'bmp' => 'image/bmp',  'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
    ];
    return $map[$ext] ?? 'image/jpeg';
}

/** 图片缓存当前字节数（后台状态展示用） */
function imgCacheBytes(): int {
    $n = 0;
    foreach ((glob(dirname(__DIR__) . '/static/imgcache/*') ?: []) as $f) {
        if (is_file($f)) {
            $n += (int) @filesize($f);
        }
    }
    return $n;
}
