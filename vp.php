<?php
/**
 * vp.php —— VodHub 1.3.0 升级体检（只读诊断 + 一次 OPcache 重置）
 *
 * 用法：
 *   1) 把本文件传到站点根目录
 *   2) 浏览器打开  https://你的域名/vp.php
 *   3) 看输出，按末尾「建议动作」处理
 *   4) ★ 用完立刻删除本文件 —— 它会暴露服务器配置，不要常驻
 *
 * 不修改任何站点文件、不读取任何密码；唯一的写操作是一次 opcache_reset()。
 * 本文件自身做了充分容错：缺文件、常量缺失、函数缺失都会被报出来而不是让它自己崩。
 * 若它自己也打不开 → PHP 根本没起来，那是主机层面的问题，与本项目无关。
 */
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

/** v1.3.0（标签 v1.3.0 / 提交 da575cc）关键文件的字节数与 md5 前 12 位 */
const EXPECTED = [
    'config.php' => [4274, '2121bb34a56b'],
    'admin.php' => [43224, 'bb4860e3aae2'],
    'includes/guard.php' => [16213, 'cdb2d069a224'],
    'includes/pagecache.php' => [9353, '4d8cbc6ac194'],
    'includes/imgcache.php' => [6154, '4e96474270d0'],
    'includes/client.php' => [11584, '7b5e3e4f2f37'],
    'includes/template.php' => [12258, '312dd044db2c'],
    'includes/auth.php' => [4686, 'c1c30eb11f2e'],
    'includes/functions.php' => [9032, '5256d608e716'],
    '.htaccess' => [9568, 'cc14190b8b33'],
    '.user.ini' => [1735, '8584fe82fcfb'],
    'img.php' => [10946, '3d40b8c6708d'],
    'robots.txt' => [1074, '6a0bd9ddff71'],
];

function L(string $k, string $v, string $flag = ''): void {
    printf("%-5s %-32s %s\n", $flag, $k, $v);
}
function HR(string $t): void {
    echo "\n== ", $t, " ", str_repeat('=', 58), "\n";
}

// ================================================================ A 环境
HR('A 环境');
L('PHP 版本', PHP_VERSION, PHP_VERSION_ID >= 80000 ? 'OK' : 'NG!');
$sapi = (string) php_sapi_name();
L('SAPI', $sapi, $sapi === 'apache2handler' ? '!!' : 'OK');
L('时区 ini(date.timezone)', trim((string) ini_get('date.timezone')) ?: '(空)');
L('站点时区', date_default_timezone_get());
L('opcache 扩展', function_exists('opcache_get_status') ? '已装' : '未装');
echo "\n  ↑ SAPI 决定 PHP 配置从哪来：\n";
echo "      apache2handler(mod_php) → .user.ini **无效**，只有 .htaccess 的 php_value 生效\n";
echo "      cgi-fpm / fpm-fcgi / litespeed → .htaccess 的 php_value **无效**，只有 .user.ini 生效\n";
echo "      两边都不生效时，靠 includes/guard.php 的运行时 ini_set 兜底\n";

// ================================================================ B 文件指纹（不 require，不会崩）
HR('B 关键文件指纹（线上 vs v1.3.0 应有值）');
printf("  %-5s %-30s %9s %14s  %s\n", '判定', '文件', '字节', 'md5前12位', 'v1.3.0 应为');
$bad = [];
foreach (EXPECTED as $f => $want) {
    $f = (string) $f;
    $expSize = (int) $want[0];
    $expMd5  = (string) $want[1];
    if (!is_file($f)) {
        printf("  %-5s %-30s %9s %14s  %d / %s\n", '缺!', $f, '-', '-', $expSize, $expMd5);
        $bad[] = $f;
        continue;
    }
    $sz  = (int) @filesize($f);
    $md  = (string) @substr((string) @md5_file($f), 0, 12);
    $ok  = ($sz === $expSize && $md === $expMd5);
    printf("  %-5s %-30s %9d %14s  %d / %s\n", $ok ? 'OK' : '旧!', $f, $sz, $md, $expSize, $expMd5);
    if (!$ok) { $bad[] = $f; }
}
echo "\n  => ", $bad ? ('这些不是 v1.3.0 最新版，需要重新覆盖：' . implode('、', $bad))
                    : '全部是 v1.3.0 最新版', "\n";

// ================================================================ C 代码层
HR('C 代码层（需要 require，放最后并全程捕获）');
$must = ['config.php', 'includes/guard.php', 'includes/pagecache.php', 'includes/imgcache.php',
         'includes/functions.php', 'includes/db.php', 'includes/template.php',
         'includes/auth.php', 'includes/fields.php', 'includes/enrich.php', 'includes/config-io.php'];
$miss = [];
foreach ($must as $f) { if (!is_file($f)) { $miss[] = $f; } }
if ($miss) {
    echo "  跳过 —— 缺文件：", implode('、', $miss), "\n";
} else {
    try {
        foreach ($must as $f) { require_once __DIR__ . '/' . $f; }
    } catch (Throwable $e) {
        echo "  require 失败：", get_class($e), ': ', $e->getMessage(), "\n";
        $miss[] = '(require)';
    }
}
if (!$miss) {
    foreach (['APP_VERSION', 'PAGE_CACHE_DIR', 'IMG_CACHE_DIR', 'CACHE_TTL_NEG'] as $c) {
        L($c, defined($c) ? ('= ' . (string) constant($c)) : '未定义', defined($c) ? 'OK' : 'NG!');
    }
    echo "\n  ↑ APP_VERSION 未定义 = config.php 是旧版；\n";
    echo "    PAGE_CACHE_DIR 未定义 = 首页 500 的直接死因（除非 guard.php 已带兜底）\n";
    foreach (['pcSyncGate', 'pcRead', 'pcWrite', 'pcEnabled', 'pcKey', 'guardTick',
              'imgCacheHit', 'sessionRelease', 'clearSystemCache'] as $fn) {
        L($fn . '()', function_exists($fn) ? '存在' : '缺失', function_exists($fn) ? 'OK' : 'NG!');
    }
    echo "\n  ** 致命点实测 —— 线上首页 500 就是死在这里 **\n";
    try {
        pcSyncGate();
        L('pcSyncGate()', '未抛错', 'OK');
        echo "    若上面 B 节 config.php 显示「旧!」却这里仍 OK，\n";
        echo "    说明 includes/guard.php 已带常量兜底 —— 站点不会因此 500。\n";
    } catch (Throwable $e) {
        L('pcSyncGate()', get_class($e) . ': ' . $e->getMessage(), 'NG!');
        echo "    ↑ 这就是首页 500 的直接原因；根因看 B 节「config.php 是否旧!」\n";
    }
    try {
        $k3 = pcKey('list', ['sourceId' => 1, 'typeId' => 2, 'page' => 3]);
        $k1 = pcKey('list', ['sourceId' => 1, 'typeId' => 2, 'page' => 1]);
        L("pcKey(list,page=3)", (string) $k3, $k3 === 'list-1-2-3.html' ? 'OK' : 'NG!');
        L("pcKey(list,page=1)", (string) $k1, $k1 === 'list-1-2-1.html' ? 'OK' : 'NG!');
        $ks = pcKey('search', ['wd' => 'x']);
        L('pcKey(search) 应为 null', var_export($ks, true), $ks === null ? 'OK' : 'NG!');
    } catch (Throwable $e) {
        L('pcKey()', get_class($e) . ': ' . $e->getMessage(), 'NG!');
    }
}

// ================================================================ D 目录
HR('D 目录可写性');
foreach (['c', 'static/imgcache', 'runtime'] as $d) {
    $exists = is_dir($d);
    $can    = $exists ? @is_writable($d) : @is_writable('.');
    printf("  %-6s %-20s %s\n", $exists ? ($can ? 'OK' : '只读') : ($can ? '可自建' : '不可建'),
           $d . '/', $exists ? '' : '(尚不存在，运行时自建)');
}
echo "  ↑ 「不可建」时功能**静默降级**（页面走 PHP、图片走 img.php），不报错 —— 刻意设计\n";

// ================================================================ E OPcache
HR('E OPcache');
if (!function_exists('opcache_get_status')) {
    echo "  未安装 opcache 扩展 —— 不存在「传了文件不生效」的问题\n";
} else {
    $st = @opcache_get_status(false);
    if (!is_array($st) || empty($st['opcache_enabled'])) {
        echo "  OPcache 未启用\n";
    } else {
        $mem   = is_array($st['memory_usage'] ?? null) ? $st['memory_usage'] : [];
        $free  = (int) ($mem['free_memory'] ?? 0);
        $used  = (int) ($mem['used_memory'] ?? 0);
        $full  = !empty($st['full']);
        $scripts = (int) ($st['opcache_statistics']['num_cached_scripts'] ?? 0);
        L('已用 / 空闲', round($used / 1048576, 2) . ' MB / ' . round($free / 1048576, 2) . ' MB',
          $free < 8 * 1048576 ? 'NG!' : 'OK');
        L('缓存已满', $full ? '** 是 **' : '否', $full ? 'NG!' : 'OK');
        L('已缓存脚本数', (string) $scripts, $scripts > 5000 ? '!!' : 'OK');
        L('validate_timestamps', (string) ini_get('opcache.validate_timestamps'),
          (string) ini_get('opcache.validate_timestamps') === '0' ? 'NG!' : 'OK');
        L('revalidate_freq(秒)', (string) ini_get('opcache.revalidate_freq'));
        echo "\n  validate_timestamps=0 ⇒ 永不检查文件修改时间，传了新文件也跑旧代码，\n";
        echo "  只能靠 opcache_reset() —— 而它在下面这条限制下并不可靠：\n";
        echo "\n  ⚠ Apache mpm_prefork + mod_php 下，**每个子进程有独立 OPcache**。\n";
        echo "     一次 opcache_reset() 只清「处理你这一次请求的那一个」子进程。\n";
        echo "     ⇒ 彻底生效请用**面板的「重启 PHP / 重启站点 / 重启 Apache」**；\n";
        echo "     或在面板 php.ini 设 opcache.validate_timestamps=1、revalidate_freq=0。\n";
        echo "\n  尝试 opcache_reset() ... ";
        $r = @opcache_reset();
        echo $r ? "已返回 true（但见上面的多进程限制）" : "失败（返回 false）", "\n";
        if ($full || $free < 8 * 1048576) {
            echo "\n  ⛔ OPcache 内存已满 —— 新脚本的编译结果可能写不进去，\n";
            echo "     表现就是「文件明明传上去了，跑的还是旧代码」。\n";
            echo "     脚本数 ", $scripts, " 说明这个 OPcache 被同机多个站点共用。\n";
            echo "     处理：面板把 opcache.memory_consumption 调大（512 → 1024），或重启 PHP。\n";
        }
    }
}

// ================================================================ F 结论
HR('F 建议动作');
$i = 1;
if ($bad) {
    echo "  ", $i++, ". **用最新升级包整体覆盖**（VodHub_v1.3.0_升级包.zip），不要只传单个文件\n";
    echo "      —— B 节标「旧!」或「缺!」的每一个都要更新。\n";
}
echo "  ", $i++, ". ", ($sapi === 'apache2handler')
    ? "**OPcache 请在面板重启 PHP/Apache**，不要只点「清理」按钮（prefork 下每子进程独立 OPcache）"
    : "确认 .user.ini 生效：E 节 validate_timestamps 应为 1；为 0 就去面板 php.ini 设 1 / revalidate_freq=0", "\n";
echo "  ", $i++, ". 传完 / 重启后按顺序验证：`/static/style.css` 200 → `/admin.php` 200 → `/index.php` 200\n";
echo "  ", $i++, ". 全部通过后，**立刻删除本文件 vp.php**。\n";
echo "\n", str_repeat('-', 72), "\n用完请删除本文件。\n";
