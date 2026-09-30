<?php
/**
 * 护栏：磁盘水位 + 缓存 GC + 按 IP 限流
 *
 * 极致低功耗模式 · 支柱六。磁盘是免费主机上唯一决定性的容量配额
 * （1 GB / 5 GB 两档），而「磁盘满」不会表现为变慢，而是逐层崩坏：
 *   图片落盘失败 → 页面缓存写不进 → 上游缓存写不进（每次访问重新出站）
 *   → SQLite 写失败（白屏）→ 会话文件写不进（登录态失效）
 * 所以这里的一切都是「生存必需」，不是优化。
 *
 * 设计约束：
 *   - GC 只在「距上次运行超过 GUARD_GC_INTERVAL」时执行一次，
 *     避免每请求 glob 全量遍历（后台 cacheStats() 已经吃过这个亏：
 *     文件失控时 glob 本身会把后台卡住）
 *   - 水位探测在本请求内只算一次（disk_free_space 是系统调用，但别每请求都调）
 *   - 任何一项 GC 失败都静默降级，绝不影响正常渲染
 */

require_once __DIR__ . '/../config.php';
// ⚠️ 不能在顶层 require db.php：db.php 会引入 pagecache.php，而后者引入本文件。
// setting() 的调用点都在函数体内（GC 触发时才会走到），届时 db.php 必已加载。

// ---------------------------------------------------------------- 常量兜底
// 这两个常量是 1.3.0 在 config.php 里新增的。**升级包漏传 config.php 时，
// 绝不能让前台直接 500** —— 一个「可选的性能优化」不该有把整站打死的权限。
//
// 实测复现（2026-09-28，线上 tv.ieo.de5.net）：config.php 是 1.2.0 旧版时，
// renderTemplate() 第一行 pcSyncGate() 就抛 `Undefined constant "PAGE_CACHE_DIR"`，
// 而 display_errors=Off 让它变成**空体 500** —— 静态文件全 200、admin 正常、
// 只有 5 个前台页 500，极难从表象定位到「漏传一个配置文件」。
//
// 所以：新文件自带默认值，旧 config.php 降级为「无害」；
// 后台的状态栏改用 APP_VERSION 判断（那个只有新版 config.php 才有）来提示补传。
if (!defined('PAGE_CACHE_DIR')) {
    define('PAGE_CACHE_DIR', dirname(__DIR__) . '/c');
}
if (!defined('IMG_CACHE_DIR')) {
    define('IMG_CACHE_DIR', dirname(__DIR__) . '/static/imgcache');
}

// ---------------------------------------------------------------- 生产环境错误输出
// 三层兜底，缺一层都可能失效：
//   ① .user.ini     → 覆盖 CGI / FPM / LSAPI
//   ② .htaccess     → 覆盖 mod_php（php_module 未加载时被 <IfModule> 跳过）
//   ③ 这里（运行时）→ 不依赖任何主机配置，只要 guard.php 被加载就生效
// config.php 里那句 ini_set('display_errors','1') 是给本地排障留的，**故意不改它** ——
// 这里在其之后把它关掉即可。需要排障时设环境变量 VODHUB_DEBUG=1 重新打开。
if (getenv('VODHUB_DEBUG') !== '1') {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('log_errors', '1');
}

/** 单位 */
const GUARD_KB = 1024;
const GUARD_MB = 1048576;
const GUARD_GB = 1073741824;

/** 两次 GC 之间的最小间隔（秒） */
const GUARD_GC_INTERVAL = 300;

/** 磁盘水位红线（百分比）：低于此值停止写入页面缓存与图片缓存 */
const GUARD_REDLINE = 8;

// ==================================================================
// 磁盘水位
// ==================================================================

/**
 * 探测磁盘水位。同一只请求内只算一次。
 *
 * 探测路径用站点根目录而不是 DATA_DIR —— 后者在首次运行时还不存在，
 * disk_free_space() 对不存在的路径返回 false。
 *
 * @return array{total:int,free:int,pct:float,tier:string}
 *         tier: normal / tight / compact / protect
 */
/**
 * 磁盘水位探测。
 *
 * ⚠ 这是**可选增强**，必须在任何主机上都拿得到安全默认值 ——
 * 2026-09-28 线上事故（InfinityFree，hop.free.je）：
 * 该主机把 disk_free_space / disk_total_space 放进了 disable_functions，
 * 调用时抛 `Error: Call to undefined function disk_total_space()`。
 *
 * 关键点：**`@` 只能抑制 Warning，抑制不了 Error** —— 所以原来的
 * `@disk_total_space()` 没有任何保护作用，Error 直接冒到 renderTemplate()，
 * 把整站前台打死成空体 500（后台因有 try/catch 反而没事）。
 *
 * 两层防护缺一不可：
 *   1) function_exists —— 覆盖 disable_functions（表现为 undefined function）
 *   2) try/catch(Throwable) —— 覆盖其余一切
 *
 * 探测不可用时返回 `probe !== 'ok'`，由调用方按**后台手动设置的容量档位**
 * 决定上限（此时水位控制失效，但硬上限仍然生效 —— 那才是防爆的主力）。
 *
 * @return array{total:int,free:int,pct:float,tier:string,probe:string}
 *         probe: ok / disabled / failed
 */
function guardDisk(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    // ① 函数可能被 disable_functions 禁用（免费主机常见）
    if (!function_exists('disk_free_space') || !function_exists('disk_total_space')) {
        return $cached = [
            'total' => 0, 'free' => 0, 'pct' => 100.0, 'tier' => 'normal',
            'probe' => 'disabled',
        ];
    }

    // ② 其余一切异常都兜住 —— 水位探测绝不能把站点打死
    try {
        $root  = dirname(__DIR__);
        $free  = @disk_free_space($root);
        $total = @disk_total_space($root);

        if ($free === false || $total === false || $total <= 0) {
            // 探测失败一律按「正常」处理 —— 宁可不收紧，也不要误进保护模式把功能停掉
            return $cached = [
                'total' => 0, 'free' => 0, 'pct' => 100.0, 'tier' => 'normal',
                'probe' => 'failed',
            ];
        }

        $pct  = $free / $total * 100;
        $tier = $pct < GUARD_REDLINE ? 'protect'
              : ($pct < 15           ? 'compact'
              : ($pct < 30           ? 'tight' : 'normal'));

        return $cached = [
            'total' => (int) $total,
            'free'  => (int) $free,
            'pct'   => round($pct, 2),
            'tier'  => $tier,
            'probe' => 'ok',
        ];
    } catch (Throwable $e) {
        return $cached = [
            'total' => 0, 'free' => 0, 'pct' => 100.0, 'tier' => 'normal',
            'probe' => 'failed',
        ];
    }
}

/**
 * 当前档位的各目录上限。
 *
 * 基础档按**总容量**判定（1 GB 档 / 5 GB 档），再按**水位**整体缩放：
 *   normal  ×1.0   tight ×0.6   compact ×0.5   protect ×0（停写）
 *
 * @return array
 */
function guardCaps(): array {
    $d = guardDisk();
    $small = guardIsSmall($d['total']);

    $caps = $small
        ? [
            'cache_files' => 600,
            'cache_bytes' => 30  * GUARD_MB,
            'page_files'  => 300,
            'page_bytes'  => 15  * GUARD_MB,
            'img_bytes'   => 60  * GUARD_MB,
            'enrich_days' => 15,
          ]
        : [
            'cache_files' => 2000,
            'cache_bytes' => 100 * GUARD_MB,
            'page_files'  => 1000,
            'page_bytes'  => 40  * GUARD_MB,
            'img_bytes'   => 200 * GUARD_MB,
            'enrich_days' => 30,
          ];

    $factor = ['normal' => 1.0, 'tight' => 0.6, 'compact' => 0.5, 'protect' => 0.0][$d['tier']] ?? 1.0;

    $caps['cache_files'] = (int) ceil($caps['cache_files'] * $factor);
    $caps['cache_bytes'] = (int) ceil($caps['cache_bytes'] * $factor);
    $caps['page_files']  = (int) ceil($caps['page_files']  * $factor);
    $caps['page_bytes']  = (int) ceil($caps['page_bytes']  * $factor);
    $caps['img_bytes']   = (int) ceil($caps['img_bytes']   * $factor);

    $caps['log_bytes']   = 2 * GUARD_MB;
    $caps['rl_files']    = 500;
    $caps['queue_files'] = 50;
    $caps['sess_days']   = 1;
    $caps['bucket_hours'] = 2;   // 页面静态桶保留 2 小时

    // base = 由容量档位决定的**预算档**（紧凑 132 MB / 标准 387 MB）
    // tier = 由**水位**决定的缩放档（normal 不缩放 / tight ×0.6 / compact ×0.5 / protect 停写）
    // 两者是不同维度：探测不到水位时 tier=normal（不缩放），但 base 仍是紧凑档 ——
    // 后台必须分开显示，否则会出现「档位 normal 却是 15 MB 上限」这种自相矛盾。
    $caps['base']      = $small ? 'compact' : 'standard';
    $caps['tier']      = $d['tier'];
    $caps['probe']     = $d['probe'] ?? 'ok';   // 水位探测状态：ok / disabled / failed
    $caps['pct']       = $d['pct'];
    $caps['free']      = $d['free'];
    $caps['total']     = $d['total'];
    $caps['small']     = $small;
    $caps['writable']  = $d['tier'] !== 'protect';
    return $caps;
}

/**
 * 是否按「1 GB 档」的紧凑预算运行。
 *
 * 档位来源（后台「站点与维护 → 容量档位」）：
 *   auto      按 disk_total_space 判定，≤1.5 GB 视为 1 GB 档
 *   compact   强制 1 GB 档预算（132 MB）
 *   standard  强制 5 GB 档预算（387 MB）
 *
 * ⚠ 为什么要有手动档位：不少共享主机的 disk_total_space() 返回的是
 *   **整台服务器的磁盘**而不是本账户配额（cPanel 系常见），auto 会误判成大空间。
 *   误判的后果有限 —— 两档预算都远小于 1 GB（387 MB 也只占 1 GB 的 38%），
 *   真正兜底的是「每项目录都有硬上限」，但 1 GB 档仍建议手动指定为紧凑。
 */
function guardIsSmall(int $totalBytes): bool {
    require_once __DIR__ . '/db.php';   // setting() 定义在这里（惰性引入，避免环）
    $mode = (string) setting('lowpower_tier', 'auto');
    if ($mode === 'compact') {
        return true;
    }
    if ($mode === 'standard') {
        return false;
    }
    // auto：探测得到就按总容量判；**探测不到（disk_* 被禁或失败）就取保守的紧凑档** ——
    // 猜大了会让 1 GB 主机拿到 387 MB 预算，猜小了只损失一点缓存容量，
    // 两害相权取其轻。
    if ($totalBytes <= 0) {
        return true;
    }
    return $totalBytes <= (int) (1.5 * GUARD_GB);
}

// ==================================================================
// 定时触发
// ==================================================================

/**
 * 每次入口调用一次；内部按 GUARD_GC_INTERVAL 节流。
 *
 * 用 mtime 标记而不是 random_int(1,N)：低流量站点靠概率触发会几乎永远不跑，
 * 而磁盘增长恰恰在「没人管」的时候最危险。
 */
function guardTick(): void {
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    if (!is_dir(DATA_DIR)) {
        return;   // 还没有任何运行期数据，没什么可清的
    }
    // 错误日志固定落在 runtime/ 下，由 guardGcLog() 截断到 2 MB ——
    // 只开 log_errors 不设路径与截断，等于「修好了显示问题却造了个日志炸弹」
    @ini_set('error_log', DATA_DIR . '/php-errors.log');
    $marker = DATA_DIR . '/gc.last';
    if (is_file($marker) && (time() - (int) @filemtime($marker)) < GUARD_GC_INTERVAL) {
        return;
    }
    @touch($marker);

    guardGcAll();
}

/** 顺序执行全部 GC。任何一项抛异常都不影响其余项与本次请求。 */
function guardGcAll(): void {
    $caps = guardCaps();

    $steps = [
        fn () => function_exists('glob')
            ? guardGcFiles(CACHE_DIR, (int) $caps['cache_files'], (int) $caps['cache_bytes'])
            : null,
        fn () => guardGcPageBuckets((int) $caps['bucket_hours'], (int) $caps['page_files'], (int) $caps['page_bytes']),
        fn () => guardGcFiles(IMG_CACHE_DIR, PHP_INT_MAX, (int) $caps['img_bytes']),
        fn () => guardGcFiles(DATA_DIR . '/rl',  (int) $caps['rl_files'],  GUARD_MB),
        fn () => guardGcFiles(DATA_DIR . '/queue', (int) $caps['queue_files'], GUARD_MB),
        fn () => guardGcLog(DATA_DIR . '/php-errors.log', (int) $caps['log_bytes']),
        fn () => guardGcSessions((int) $caps['sess_days']),
    ];

    foreach ($steps as $step) {
        try {
            $step();
        } catch (Throwable $e) {
            // GC 失败不能影响正常渲染
        }
    }
}

// ==================================================================
// 各项 GC
// ==================================================================

/**
 * 通用 LRU：超数量或超字节时，按 mtime 从旧到新删除。
 *
 * @param string $dir       目录
 * @param int    $maxFiles  数量上限（PHP_INT_MAX 表示不限数量）
 * @param int    $maxBytes  字节上限
 */
function guardGcFiles(string $dir, int $maxFiles, int $maxBytes): void {
    if (!is_dir($dir) || $maxBytes <= 0) {
        return;
    }
    $files = [];
    $total = 0;
    foreach ((glob($dir . '/*') ?: []) as $f) {
        if (!is_file($f)) {
            continue;
        }
        $size = (int) @filesize($f);
        if ($size < 0) {
            continue;
        }
        $files[$f] = ['m' => (int) @filemtime($f), 's' => $size];
        $total += $size;
    }
    if (!$files) {
        return;
    }

    $count = count($files);
    if ($count <= $maxFiles && $total <= $maxBytes) {
        return;
    }

    // 按 mtime 升序（最旧在前）
    uasort($files, static fn (array $a, array $b): int => $a['m'] <=> $b['m']);

    foreach ($files as $f => $info) {
        if ($count <= $maxFiles && $total <= $maxBytes) {
            break;
        }
        if (@unlink($f)) {
            $count--;
            $total -= $info['s'];
        }
    }
}

/**
 * 页面静态桶 GC：删除超过 $hours 小时的桶目录（连目录一起删，回收 inode）。
 *
 * 没有这条，c/ 会以「24 桶 × 200 页 × 40 KB ≈ 192 MB/天」增长，
 * 1 GB 磁盘 5 天就满。
 */
function guardGcPageBuckets(int $hours, int $maxFiles, int $maxBytes): void {
    // glob 也可能在某些主机上被 disable_functions 禁用 —— 禁了就不 GC，绝不能 fatal
    if (!function_exists('glob')) {
        return;
    }
    $dir = PAGE_CACHE_DIR;
    if (!is_dir($dir) || $hours <= 0) {
        return;
    }
    $cutoff = time() - $hours * 3600;
    foreach ((glob($dir . '/*', GLOB_ONLYDIR) ?: []) as $bucket) {
        $mtime = (int) @filemtime($bucket);
        if ($mtime > 0 && $mtime < $cutoff) {
            guardRmDir($bucket);
        }
    }
    // 再按总量兜底（同一小时内文件过多时）
    if ($maxBytes > 0) {
        guardGcFiles($dir, PHP_INT_MAX, $maxBytes);
    }
    // 子桶内的文件也各自限量
    foreach ((glob($dir . '/*', GLOB_ONLYDIR) ?: []) as $bucket) {
        guardGcFiles($bucket, $maxFiles, $maxBytes);
    }
}

/** 清空目录下所有文件（保留目录本身）。后台「清理图片本地缓存」用。 */
function guardClearFiles(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    foreach ((glob($dir . '/*') ?: []) as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
}

/** 递归删除目录（只删自己创建的静态缓存目录，不跟随符号链接） */
function guardRmDir(string $dir): void {
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach ((scandir($dir) ?: []) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            guardRmDir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** 错误日志超限截断（保留尾部，排障信息比开头重要） */
function guardGcLog(string $file, int $maxBytes): void {
    if ($maxBytes <= 0 || !is_file($file)) {
        return;
    }
    $size = (int) @filesize($file);
    if ($size <= $maxBytes) {
        return;
    }
    $keep = 64 * GUARD_KB;
    $fp = @fopen($file, 'rb');
    if ($fp === false) {
        return;
    }
    @fseek($fp, -$keep, SEEK_END);
    $tail = (string) @fread($fp, $keep);
    @fclose($fp);
    @file_put_contents($file, "--- log truncated by guard (was {$size} bytes) ---\n" . $tail, LOCK_EX);
}

/**
 * 会话文件回收。
 *
 * 只在「会话目录在本站点内」时才动手 —— 免费主机常把 session.save_path
 * 指向全站共享目录，删那里会误伤别人的会话。
 * 同时只在 PHP 自带 GC 明显不工作时才补位（gc_probability = 0）。
 */
function guardGcSessions(int $days): void {
    if ($days <= 0) {
        return;
    }
    $path = (string) @ini_get('session.save_path');
    if ($path === '') {
        $path = sys_get_temp_dir();
    }
    $root = realpath(dirname(__DIR__));
    $real = realpath($path);
    // 只处理站点目录内部的会话存储
    if ($root === false || $real === false || strpos($real, $root . DIRECTORY_SEPARATOR) !== 0) {
        return;
    }
    // PHP 自带 GC 正常工作就不插手
    $prob = (int) @ini_get('session.gc_probability');
    if ($prob > 0) {
        return;
    }
    $cutoff = time() - $days * 86400;
    foreach ((glob($real . '/sess_*') ?: []) as $f) {
        $mtime = (int) @filemtime($f);
        if ($mtime > 0 && $mtime < $cutoff) {
            @unlink($f);
        }
    }
}

// ==================================================================
// 限流
// ==================================================================

/**
 * 按 IP + 分钟窗口限流。
 *
 * @param string $bucket     端点名（search / play / img）
 * @param int    $perMinute  每 IP 每分钟上限，<=0 表示不限
 * @return bool  true = 放行，false = 超限
 */
function guardRateLimit(string $bucket, int $perMinute): bool {
    if ($perMinute <= 0) {
        return true;
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    if ($ip === '') {
        $ip = '0.0.0.0';
    }

    $dir = DATA_DIR . '/rl';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        vhGuardIndex($dir);       // 限流/队列文件不列目录

    }
    if (!is_dir($dir)) {
        return true;   // 连限流目录都建不出来，别把站点一起拖死
    }

    $slot = intdiv(time(), 60);
    $file = $dir . '/' . preg_replace('/[^a-z0-9_\-]/i', '', $bucket) . '-' . $slot . '-' . sha1($ip) . '.cnt';

    $n = 0;
    if (is_file($file)) {
        $n = (int) @file_get_contents($file);
    }
    if ($n >= $perMinute) {
        return false;
    }
    @file_put_contents($file, (string) ($n + 1), LOCK_EX);
    return true;
}

/** 超限时统一出口：429 + Retry-After。区分于 503（EP 打满），便于日志判因。 */
function guardDeny(string $bucket): void {
    if (!headers_sent()) {
        header('HTTP/1.1 429 Too Many Requests');
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: 60');
        header('Cache-Control: no-store');
    }
    exit("{$bucket}: too many requests");
}

/**
 * 便捷入口：超限则直接 429 退出。
 */
function guardCheck(string $bucket, int $perMinute): void {
    if (!guardRateLimit($bucket, $perMinute)) {
        guardDeny($bucket);
    }
}

// ==================================================================
// 环境体检：.htaccess 权限类别 + 会话目录泄露面
//
// 这两件事都属于「**主机配置层**」，PHP 代码本身管不着，
// 但必须能被**准确地说出来** —— 否则站长只会看到「站点挂了」或
// 「好像不太安全」，不知道该改哪一行。
// ==================================================================

/**
 * 逐行解析 `.htaccess`，按 Apache 的 `AllowOverride` 权限类别归类。
 *
 * 【为什么必须逐行解析】
 *   免费虚拟主机上 `.htaccess` 报 500 的头号原因，是文件里混进了需要
 *   `AllowOverride **Options**` 的指令，而主机只给了 `FileInfo`
 *   （`RewriteEngine` 自己要的那一类）。混进去的通常是这两条：
 *       Options -Indexes
 *       php_value / php_flag
 *
 *   ⚠️ `<IfModule>` **救不了它们**：它只检查「模块是否加载」，
 *      不检查「AllowOverride 是否允许这条指令」。`mod_autoindex` 与
 *      `php_module` 几乎总是加载着，所以包在 IfModule 里的这两条
 *      看起来很安全，实际是最常见的 500 来源 —— 这是它最坑人的地方。
 *
 *   所以这里**不看 IfModule，只看指令名**，把越权指令的行号直接报出来。
 *
 * 【权限类别速查】（Apache `AllowOverride` 的五类）
 *   AuthConfig / FileInfo / Indexes / Limit / Options
 *   · RewriteEngine、RewriteCond、RewriteRule、Header  → FileInfo ✅ 本项目只用这类
 *   · Options、php_value、php_flag（mod_php 的 override = OPT_OPTIONS）→ Options ❌
 *   · php_admin_value、php_admin_flag → **根本禁止写在 .htaccess 里**，必定 500 ❌
 *   · **ExpiresActive、ExpiresByType、ExpiresDefault → Indexes** ⚠️
 *
 * ⚠️ 最后那条是 2026-09-29 用 Apache/2.4.58 **逐条实测**出来的，与不少教程的
 *    「Expires 属于 FileInfo」说法不符，实测数据（同一份 .htaccess，只改主配置）：
 *        AllowOverride=FileInfo  → ExpiresActive **500**「not allowed here」
 *        AllowOverride=Indexes   → ExpiresActive 200
 *        AllowOverride=FileInfo  → RewriteEngine 200 / Header 200
 *        AllowOverride=Options   → Options 200 / php_flag 200
 *    所以本项目的 .htaccess **刻意不含 expires 段** —— 静态资源缓存头由
 *    `Header set Cache-Control`（纯 FileInfo）承担，现代浏览器以它为准。
 *
 * @return array{
 *   exists: bool, size: int, rewrite: bool, static: bool,
 *   unsafe: list<string>,   // 确定会 500（需要 Options 类）："行号|指令|说明"
 *   risky: list<string>,    // 视主机而定（需要 Indexes 类）："行号|指令|说明"
 *   verdict: string         // ok / missing / unsafe / risky / no_rewrite
 * }
 */
function guardHtAudit(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $r = [
        'exists' => false, 'size' => 0, 'rewrite' => false, 'static' => false,
        'unsafe' => [], 'risky' => [], 'verdict' => 'missing',
    ];

    $file = dirname(__DIR__) . '/.htaccess';
    if (!is_file($file)) {
        return $cached = $r;
    }
    $src = (string) @file_get_contents($file);
    $r['exists'] = true;
    $r['size']   = strlen($src);

    foreach (explode("\n", str_replace("\r\n", "\n", $src)) as $i => $line) {
        $t = trim($line);
        if ($t === '' || $t[0] === '#') {
            continue;   // 注释里的 php_value 不算数（否则注释本身就会被误判）
        }
        $name = (string) (preg_split('/\s+/', $t, 2)[0] ?? '');
        if ($name === '') {
            continue;
        }

        if (str_starts_with($name, 'Rewrite')) {
            $r['rewrite'] = true;
        }
        if (str_contains($t, '/c/') && str_starts_with($name, 'RewriteCond')) {
            $r['static'] = true;   // 页面静态直出（纯性能，缺失只是变慢）
        }

        switch ($name) {
            case 'php_admin_value':
            case 'php_admin_flag':
                $r['unsafe'][] = $i + 1 . '|' . $name
                    . '|**任何情况下都不允许写在 .htaccess 里**，必定 500';
                break;
            case 'Options':
                $r['unsafe'][] = $i + 1 . '|' . $name
                    . '|需要 `AllowOverride Options`，多数免费主机只给 `FileInfo` → 整站 500';
                break;
            case 'php_value':
            case 'php_flag':
                $r['unsafe'][] = $i + 1 . '|' . $name
                    . '|同属 Options 类 → 500；错误输出改由 `includes/guard.php` 的 `ini_set()` 兜底';
                break;
            case 'ExpiresActive':
            case 'ExpiresByType':
            case 'ExpiresDefault':
                // 不是「必 500」：主机给了 Indexes 或 All 就没事。
                // 但只给 FileInfo（rewrite 必需的那一类）的主机会 500 ——
                // 而这恰恰是「能用 rewrite」最常见的那种配置，风险很高。
                $r['risky'][] = $i + 1 . '|' . $name
                    . '|需要 `AllowOverride **Indexes**`（实测 FileInfo 不够）；'
                    . '只给 FileInfo 的主机会 500。本项目改用 `Header set Cache-Control` 承担缓存头';
                break;
        }
    }

    if ($r['unsafe']) {
        $r['verdict'] = 'unsafe';
    } elseif ($r['risky']) {
        $r['verdict'] = 'risky';
    } elseif (!$r['rewrite']) {
        $r['verdict'] = 'no_rewrite';
    } else {
        $r['verdict'] = 'ok';
    }
    return $cached = $r;
}

/**
 * 会话目录是否落在 Web 根之内 —— 一个比 `data.db` 更隐蔽的泄露面。
 *
 * `sess_<id>` 的**文件名就是会话 ID**。目录可列 + 文件可下载 =
 * 拿到任意一个文件名就能把 `vodsite_sid` cookie 设成它，
 * 于是 `admin_ok` / `access_ok` 都到手 —— **等价于直接登录**，
 * 而且完全绕过密码。这比 `data.db` 泄露更直接。
 *
 * `guardGcSessions()` 早就检测了同一件事，但只把它当成 GC 的触发条件，
 * 没有当成泄露面 —— 这里把告警补上。
 *
 * @return array{path:string, inside:bool, source:string}
 *         inside: true = 在 Web 根内（需要处置）；source: ini / temp / unknown
 */
function guardSessionExposure(): array {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $raw  = (string) @ini_get('session.save_path');
    $src  = $raw === '' ? 'temp' : 'ini';
    $path = $raw;

    // files 处理器的写法有两种：`N;/path`（N = 子目录深度）与 `N;mode;/path`
    // —— 取最后一个分号之后的那段才是真正的路径。
    if (str_contains($path, ';')) {
        $parts = explode(';', $path);
        $path  = (string) end($parts);
    }
    if (trim($path) === '') {
        $path = sys_get_temp_dir();
        $src  = 'temp';
    }

    $root = realpath(dirname(__DIR__));
    $real = realpath($path);

    // 【为什么不能只靠 realpath】
    //   realpath() 对**不存在的路径返回 false** —— 而会话目录恰恰常常还没建：
    //   PHP 要到第一次 session_start() 才创建它。只判 realpath 会把
    //   「现在还没建、一建就在站点根里」误报成安全，正是最需要拦的那种情况。
    //   所以 realpath 拿不到时，退回**字面路径**比对（并把相对路径按 cwd 展开）。
    $inside = false;
    $norm   = str_replace('\\', '/', $path);
    if ($norm !== '' && !str_starts_with($norm, '/')) {
        $norm = rtrim(str_replace('\\', '/', (string) getcwd()), '/') . '/' . $norm;
    }
    $norm = rtrim($norm, '/');

    if ($root !== false) {
        $nroot = rtrim(str_replace('\\', '/', (string) $root), '/');
        if ($real !== false) {
            $rnorm = rtrim(str_replace('\\', '/', (string) $real), '/');
            $inside = ($rnorm === $nroot) || str_starts_with($rnorm, $nroot . '/');
        } else {
            // 字面比对：**等于站点根本身也算**（session.save_path 直接指向站点根）
            $inside = ($norm !== '' && ($norm === $nroot || str_starts_with($norm, $nroot . '/')));
        }
        // realpath 成功但与字面不同（软链）时以 realpath 为准；否则用字面结果
        if ($real === false) {
            $real = $norm !== '' ? $norm : $path;
        }
    }

    return $cached = [
        'path'   => (string) ($real !== false ? $real : $path),
        'inside' => $inside,
        'source' => $src,
    ];
}
