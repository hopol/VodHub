<?php
/**
 * 测试引导：零依赖的极简断言框架 + 被测模块加载
 *
 * 为什么自己写而不是用 PHPUnit / Pest：
 *   本项目零 Composer、零 vendor/，且明确要部署到「不懂技术的免费主机用户」手里。
 *   引入 PHPUnit 就等于给每个部署实例凭空加一个 vendor/ 目录 —— 与 1.2 节的
 *   零依赖战略直接冲突。所以测试也必须零依赖：**一个能 php 直接跑的脚本**。
 *
 * 隔离原则：
 *   测试只碰**纯函数**（无 I/O、无网络、无全局状态），因此不建库、不出站。
 *   `VODHUB_DATA_DIR` 仍指向一个临时目录，防止某个被测函数若不慎触发
 *   db() 时把开发者的真实 runtime/ 污染掉 —— 宁可它建在临时目录里。
 */

declare(strict_types=1);

// ---- 隔离数据目录：绝不让测试写出到仓库的 runtime/ ----
$__vhTestTmp = sys_get_temp_dir() . '/vodhub-test-' . getmypid();
if (!is_dir($__vhTestTmp)) {
    @mkdir($__vhTestTmp, 0755, true);
}
// ⚠ putenv 也可能在 disable_functions 里，而被移除的函数是**致命错误**不是返回 false。
//   测试工具应该比它测试的代码更耐用，所以这里做存在性判断；
//   万一不可用，退回用「站点根外的兄弟目录」，同样不会污染仓库里的 runtime/。
if (function_exists('putenv')) {
    putenv('VODHUB_DATA_DIR=' . $__vhTestTmp);
} else {
    define('VODHUB_DATA_DIR', $__vhTestTmp);
}
define('VODHUB_TEST_TMP', $__vhTestTmp);

register_shutdown_function(static function () use ($__vhTestTmp): void {
    if (is_dir($__vhTestTmp)) {
        foreach (glob($__vhTestTmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($__vhTestTmp);
    }
});

require_once __DIR__ . '/../includes/fields.php';
require_once __DIR__ . '/report.php';   // CLI / Web 双模式渲染

// ==================================================================
// 极简断言框架
// ==================================================================

/** @var array<int, array{name:string, ok:bool, msg:string, trace:string}> */
$GLOBALS['__tests']       = [];
$GLOBALS['__group']       = '';
$GLOBALS['__suite']       = basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? 'tests'), '.php');
$GLOBALS['__suite_start'] = microtime(true);

function group(string $name): void {
    $GLOBALS['__group'] = $name;
}

function t(string $name, callable $fn): void {
    $GLOBALS['__group'] = $GLOBALS['__group'] ?: '(未分组)';
    try {
        $fn();
        $GLOBALS['__tests'][] = ['name' => $name, 'ok' => true, 'msg' => '', 'trace' => ''];
    } catch (Throwable $e) {
        $GLOBALS['__tests'][] = [
            'name'  => $name,
            'ok'    => false,
            'msg'   => $e->getMessage(),
            'trace' => $e->getFile() . ':' . $e->getLine(),
        ];
    }
}

function fail(string $msg): void {
    throw new RuntimeException($msg);
}

function eq($expected, $actual, string $note = ''): void {
    if ($expected !== $actual) {
        fail(sprintf(
            "%s期望 %s，实际 %s",
            $note !== '' ? $note . '：' : '',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function same($expected, $actual, string $note = ''): void {
    if ($expected != $actual) {
        fail(sprintf(
            "%s期望 %s，实际 %s（宽松比较）",
            $note !== '' ? $note . '：' : '',
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

function ok($cond, string $msg = ''): void {
    if (!$cond) {
        fail($msg !== '' ? $msg : '条件不成立');
    }
}

/** 断言「必须返回 null」—— 归一化层的契约里 null 与 '' 含义完全不同 */
function isNull($v, string $note = ''): void {
    if ($v !== null) {
        fail(sprintf('%s期望 null，实际 %s', $note !== '' ? $note . '：' : '', var_export($v, true)));
    }
}

function contains(string $haystack, string $needle, string $note = ''): void {
    if (!str_contains($haystack, $needle)) {
        fail(sprintf(
            '%s期望包含「%s」，实际「%s」',
            $note !== '' ? $note . '：' : '',
            $needle,
            $haystack
        ));
    }
}

function notContains(string $haystack, string $needle, string $note = ''): void {
    if (str_contains($haystack, $needle)) {
        fail(sprintf(
            '%s期望不包含「%s」，实际「%s」',
            $note !== '' ? $note . '：' : '',
            $needle,
            $haystack
        ));
    }
}

/** 数组是否含某值（严格） */
function hasValue(array $arr, $v, string $note = ''): void {
    if (!in_array($v, $arr, true)) {
        fail(sprintf(
            '%s期望数组含 %s，实际 %s',
            $note !== '' ? $note . '：' : '',
            var_export($v, true),
            var_export($arr, true)
        ));
    }
}

function hasNoValue(array $arr, $v, string $note = ''): void {
    if (in_array($v, $arr, true)) {
        fail(sprintf(
            '%s期望数组不含 %s，实际 %s',
            $note !== '' ? $note . '：' : '',
            var_export($v, true),
            var_export($arr, true)
        ));
    }
}

/**
 * 收尾：独立运行时 exit，被 runner 以 require 方式加载时 return。
 *
 * 为什么需要区分：测试文件末尾若写死 `exit(runAll())`，
 * 一旦被同进程 require（exec 被禁用时的降级路径），
 * **exit 会直接终止整个进程** —— 后面的测试文件永远执行不到，
 * 表现为「152 项只跑出 12 项，却仍然显示全部通过」。
 * 这比跑不起来更糟：它会给人「测过了」的错觉。
 */
function finish(): void {
    $rc = runAll();
    if (defined('VHTEST_EMBEDDED') && VHTEST_EMBEDDED) {
        // 被 runner 内嵌调用：把退出码交回给 runner，自己不终止进程
        $GLOBALS['__vhtest_rc'] = $rc;
        return;
    }
    exit($rc);
}

function runAll(): int {
    $list  = $GLOBALS['__tests'];
    $total = count($list);
    $bad   = array_values(array_filter($list, static fn ($x) => !$x['ok']));

    $title = (string) ($GLOBALS['__suite'] ?? 'tests');
    $ms    = round(((float) ($GLOBALS['__suite_start'] ?? microtime(true))) <= 0
            ? 0.0
            : (microtime(true) - (float) $GLOBALS['__suite_start']) * 1000, 1);

    // 同一份数据，两种呈现：终端留颜色，网页出 HTML。
    if (defined('VHTEST_EMBEDDED') && VHTEST_EMBEDDED) {
        // 被 run.php 内嵌调用（本项目全程同进程，见 run.php 文件头）：
        // 结果交回给 run.php 汇总，**本文件不直接输出**。
        $GLOBALS['__vhtest_out'] = vhTestRenderCli($list, $title, $ms);
    } elseif (vhTestIsCli()) {
        echo vhTestRenderCli($list, $title, $ms);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        // 报告不该被浏览器缓存 —— 每次刷新都该看到刚跑的结果
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo vhTestRenderHtml($list, $title, $ms, ['php' => PHP_VERSION]);
    }

    // 把结构化结果交给 run.php（网页汇总要用）。
    // 同进程下直接写内存全局即可，不必经临时文件 ——
    // 后者是为「子进程回传」设计的，方案改成同进程后已不再需要。
    $structured = array_map(static fn ($x) => [
        'ok'    => (bool) $x['ok'],
        'name'  => (string) $x['name'],
        'msg'   => (string) $x['msg'],
        'trace' => (string) $x['trace'],
    ], $list);
    if (defined('VHTEST_EMBEDDED') && VHTEST_EMBEDDED) {
        $GLOBALS['__vhtest_json'] = $structured;
    }

    // 仍保留文件通道：万一将来又用回子进程，这条路不用重写。
    $jsonPath = (string) vhGetEnv('VHTEST_JSON');
    if ($jsonPath !== '') {
        @file_put_contents($jsonPath, json_encode([
            'suite' => $title,
            'cases' => array_map(static fn ($x) => [
                'ok'    => (bool) $x['ok'],
                'name'  => (string) $x['name'],
                'msg'   => (string) $x['msg'],
                'trace' => (string) $x['trace'],
            ], $list),
        ], JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    // 内嵌模式（exec 被禁用时的降级路径）下，下一个测试文件还会 require
    // 同一个 bootstrap.php。必须在这里把本轮用例清掉，
    // 否则第 2 个文件会打印「前 2 个文件的总数」，
    // 让人误以为某个文件自己有 43 项断言。
    if (defined('VHTEST_EMBEDDED') && VHTEST_EMBEDDED) {
        $GLOBALS['__tests']  = [];
        $GLOBALS['__group']  = '';
        $GLOBALS['__suite']  = '';
    }

    return $bad === [] ? 0 : 1;
}
