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

$dir   = __DIR__;
$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';

// ---- 参数解析：--json（机器可读）/ 可选的筛选词 ----
//
// 为什么要有 --json：**验证不该靠人眼看**。
// 1.3.7 的教训是「失败项不打勾，汇总又不报总数」——
// 眼睛只会数有颜色的那些，于是漏报了一次失败。
// 有了 JSON，CI 与脚本能读到确切的 pass/fail/total，
// 不再需要任何人（或任何模型）从终端文本里推断结论。
// ⚠⚠ **`$argv` 只在 CLI SAPI 下存在。**
//   浏览器访问 tests/run.php 时它是 undefined —— 直接 array_slice() 会
//   `TypeError: array_slice(): Argument #1 must be of type array, null given`
//   整站 500。**1.3.9 首次上传就撞上了这个。**
//
//   两侧都要判，不能只判 SAPI：register_argc_argv 被关闭时 CLI 下也没有 $argv。
//   教训与 1.3.5 的 shell_exec 同类：**CLI 测得通 ≠ Web 跑得通**。
$asJson = false;
$filter = '';
$cliArgs = (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') && isset($argv) && is_array($argv)
    ? array_slice($argv, 1)
    : [];
foreach ($cliArgs as $arg) {
    if ($arg === '--json') {
        $asJson = true;
    } else {
        $filter = (string) $arg;
    }
}
if (!$isCli && isset($_GET['json'])) {
    $asJson = (string) $_GET['json'] !== '0';
}
if ($filter === '' && isset($_GET['f'])) {
    $filter = (string) $_GET['f'];
}

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

/**
 * 统计总项数与失败项数，并**自检收集器是否可信**。
 *
 * ⚠⚠ **这段自检是「验证闭环」的核心，理由来自一次真实误报**：
 *
 *   1.3.7 上线后测试报告有一项失败，而我在本地说「195 项全绿」。
 *   原因：失败项**不打勾**，而当时的汇总行只报「全部通过」不报总数 ——
 *   我数了 ✓ 的个数（195），当成总数，于是漏掉了那个 ✗。
 *   **195 是通过数，196 才是总数。**
 *
 *   这类错误**人眼天然查不出**：眼睛只数有颜色的那些。
 *   所以必须让程序自己数，并在这里断言「通过数 + 失败数 == 总数」——
 *   一旦不等，说明收集器漏了用例，**整个报告不可信**，
 *   此时必须报「框架故障」而不是「全部通过」。
 *
 * @return array{total:int, fails:int, pass:int, sound:bool, why:string}
 */
function vhTally(array $results): array {
    $total = 0;
    $fails = 0;
    $pass  = 0;
    $empty = [];

    foreach ($results as $name => $r) {
        if (!is_array($r['cases'] ?? null)) {
            $empty[] = $name;
            continue;
        }
        foreach ($r['cases'] as $c) {
            $total++;
            if (empty($c['ok'])) {
                $fails++;
            } else {
                $pass++;
            }
        }
    }

    // 自检一：每个文件都必须交回用例数组（空数组是合法的，但不能缺失）
    if ($empty !== []) {
        return ['total' => $total, 'fails' => $fails, 'pass' => $pass, 'sound' => false,
                'why' => '这些文件没有交回用例明细：' . implode(', ', $empty)];
    }

    // 自检二：算术恒等 —— 通过数 + 失败数 必须等于总数
    if ($pass + $fails !== $total) {
        return ['total' => $total, 'fails' => $fails, 'pass' => $pass, 'sound' => false,
                'why' => "收集器算术不符：通过 {$pass} + 失败 {$fails} ≠ 总数 {$total}"];
    }

    // 自检三：不能一个用例都没收集到（全部文件被跳过 / glob 失配 / require 静默失败）
    if ($total === 0) {
        return ['total' => 0, 'fails' => 0, 'pass' => 0, 'sound' => false,
                'why' => '一个用例都没收集到 —— 测试可能被整体跳过了'];
    }

    return ['total' => $total, 'fails' => $fails, 'pass' => $pass, 'sound' => true, 'why' => ''];
}

$tally      = vhTally($results);
$suiteTotal = $tally['total'];
$suiteFails = $tally['fails'];

// ---- --json：机器可读输出（供 CI 与脚本消费）----
if ($asJson) {
    header('Content-Type: application/json; charset=utf-8');
    $out = [
        'sound'       => $tally['sound'],
        'why'         => $tally['why'],
        'total'       => $tally['total'],
        'pass'        => $tally['pass'],
        'fails'       => $tally['fails'],
        'files'       => count($files),
        'failedFiles' => $failed,
        'elapsedMs'   => $elapsed,
        'php'         => PHP_VERSION,
        'notices'     => $notices,
        'cases'       => [],
    ];
    foreach ($results as $name => $r) {
        foreach (($r['cases'] ?? []) as $c) {
            $out['cases'][] = [
                'suite' => $name,
                'ok'    => (bool) ($c['ok'] ?? false),
                'name'  => (string) ($c['name'] ?? ''),
                'msg'   => (string) ($c['msg'] ?? ''),
                'trace' => (string) ($c['trace'] ?? ''),
            ];
        }
    }
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit($tally['sound'] && $tally['fails'] === 0 ? 0 : 1);
}

// ---- CLI：各文件自己的彩色输出 + 末尾汇总 ----
if ($isCli) {
    foreach ($results as $name => $r) {
        echo "\n\033[1m── {$name} \033[0m\n" . $r['text'] . "\n";
    }
    echo "\n" . str_repeat('─', 60) . "\n";
    // ⚠ 三层判定，**框架故障优先于测试失败** ——
    //   收集器不可信时，「全部通过」这句话本身就是没根据的。
    if (!$tally['sound']) {
        echo "\033[41;97m ⛔ 测试框架故障：报告不可信 \033[0m\n";
        echo "\033[31m   " . $tally['why'] . "\033[0m\n";
        echo "\033[90m   （收集器出了问题，此时任何「通过/失败」结论都不可采信）\033[0m\n";
    } elseif ($suiteFails === 0) {
        echo "\033[32m✅ 全部通过：" . count($files) . " 个测试文件，"
           . "{$suiteTotal} 项断言，{$elapsed} ms\033[0m\n";
    } else {
        echo "\033[31m❌ {$suiteFails} / {$suiteTotal} 项失败"
           . "（" . count($files) . " 个测试文件，{$elapsed} ms）\033[0m\n";
        if ($failed !== []) {
            echo "\033[31m   涉及文件：" . implode(', ', $failed) . "\033[0m\n";
        }
    }
    exit($tally['sound'] && $suiteFails === 0 ? 0 : 1);
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
echo vhTestRenderSuite($results, $all, $failed, $notices, $elapsed, $tally);
exit($tally['sound'] && $failed === [] ? 0 : 1);
