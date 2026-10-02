<?php
/**
 * 测试统一入口
 *
 * 用法：
 *   网页：  https://你的站点/tests/run.php          跑全套
 *          https://你的站点/tests/run.php?f=fields  只跑指定文件
 *   命令行：php tests/run.php            同上
 *           php tests/run.php fields     只跑文件名含 fields 的
 *
 * **网页与终端读同一份数据**，输出各自适配（终端 ANSI / 网页 HTML）。
 *
 * ⚠ **为什么全程同进程、不开子进程**：
 *   本项目的目标用户是免费虚拟主机用户，而那里 `exec()` 与 `proc_open`
 *   常在 `disable_functions` 里。早期版本靠子进程隔离，实测在那类主机上
 *   **完全跑不出来**（页面只显示"无法创建独立进程"）—— 对用户毫无意义。
 *
 *   改成同进程后，曾担心被包含文件的顶层全局赋值（如 admin-actions.php 的
 *   `$_ADMIN_ACTION_MAP`）会丢失。**实测不会**：
 *   include 在**全局作用域**执行时，顶层赋值就是全局的，
 *   四个测试文件连跑 152 项全绿（见 tests/README.md「为什么不需要独立进程」）。
 *
 *   真正需要小心的只有两点，都已处理：
 *     ① require_once 只在首次生效 → 用 include 而非 require；
 *     ② 各文件顶层的 finish() 会清空收集器 → 由 run.php 在每次 include
 *        前后重置状态，并从 __vhtest_out 取回该文件的结果。
 *
 * 退出码：0 全通过 / 1 有失败（CI 直接用这个判成败）
 */

declare(strict_types=1);

require_once __DIR__ . '/report.php';
require_once __DIR__ . '/bootstrap.php';

if (!defined('VHTEST_EMBEDDED')) {
    define('VHTEST_EMBEDDED', true);   // 让 finish() 不 exit，只交回结果
}

$dir    = __DIR__;
$isCli  = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
$filter = $argv[1] ?? (isset($_GET['f']) ? (string) $_GET['f'] : '');

$files = glob($dir . '/test_*.php') ?: [];
sort($files);

if ($filter !== '') {
    $files = array_values(array_filter(
        $files,
        static fn ($f) => str_contains(basename($f), $filter)
    ));
}

if ($files === []) {
    if ($isCli) {
        fwrite(STDERR, "没有匹配到测试文件\n");
    } else {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo vhTestRenderHtml([], '没有匹配到测试文件', 0.0, ['php' => PHP_VERSION]);
    }
    exit(1);
}

$notices = [];

// ---- 逐个文件：重置状态 → 顶层 include → 取回结果 ----
$results = [];   // name => ['cases'=>array, 'ok'=>bool]
$failed  = [];
$started = microtime(true);

foreach ($files as $file) {
    $name = basename($file, '.php');

    // 每个文件跑在干净的收集状态里
    $GLOBALS['__tests']       = [];
    $GLOBALS['__group']       = '';
    $GLOBALS['__suite']       = $name;
    $GLOBALS['__suite_start'] = microtime(true);
    $GLOBALS['__vhtest_rc']   = 0;
    $GLOBALS['__vhtest_out']  = '';
    $GLOBALS['__vhtest_json'] = '';      // 收集该文件的结构化用例

    // ★ include 必须出现在**全局作用域**：写在函数里时，
    //   被包含文件的顶层赋值会落进函数局部，$GLOBALS 里查不到。
    include $file;

    $rc    = (int) ($GLOBALS['__vhtest_rc'] ?? 0);
    $cases = $GLOBALS['__vhtest_json'] ?? [];
    $text  = (string) ($GLOBALS['__vhtest_out'] ?? '');

    // 某文件若直接 return 而没走到 finish()，兜底给一条说明
    if ($cases === [] && trim($text) === '') {
        $cases = [[
            'ok'    => false,
            'name'  => '（' . $name . ' 未产生任何结果）',
            'msg'   => '该测试文件没有调用 finish()，或全部用例被提前跳过。',
            'trace' => $file,
        ]];
        $rc = 1;
    }

    $results[$name] = ['cases' => $cases, 'rc' => $rc, 'text' => $text];
    if ($rc !== 0) {
        $failed[] = $name;
    }
}

$elapsed = round((microtime(true) - $started) * 1000, 1);

// ---- CLI：各文件自己的彩色输出 + 末尾汇总 ----
if ($isCli) {
    foreach ($results as $name => $r) {
        echo "\n\033[1m── {$name} \033[0m\n" . $r['text'] . "\n";
    }
    echo "\n" . str_repeat('─', 60) . "\n";
    if ($failed === []) {
        echo "\033[32m✅ 全部通过：" . count($files) . " 个测试文件，{$elapsed} ms\033[0m\n";
    } else {
        echo "\033[31m❌ 失败文件：" . implode(', ', $failed)
           . "（共 " . count($files) . " 个，{$elapsed} ms）\033[0m\n";
    }
    exit($failed === [] ? 0 : 1);
}

// ---- 网页：汇总成一份 HTML ----
$all = [];
foreach ($results as $name => $r) {
    foreach ($r['cases'] as $c) {
        $all[] = ['suite' => $name] + $c;
    }
}

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');   // 刷新即重跑
echo vhTestRenderSuite($results, $all, $failed, $notices, $elapsed);
exit($failed === [] ? 0 : 1);
