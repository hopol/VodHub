<?php
/**
 * CI 的机器可读校验入口
 *
 * 为什么单独一个文件而不是写在 ci.yml 里：
 * YAML 里嵌多行 PHP，缩进规则会把代码搞乱（本项目已踩过一次），
 * 而且改起来难读。**把逻辑放进文件，YAML 只负责调用。**
 *
 * 做两件事：
 *   ① 读 tests/run.php --json 的输出，核对 pass + fails == total；
 *   ② 验证「测试本身有效」——故意改坏一处，确认测试会变红。
 *
 * 第 ② 条的理由：**一个不会变红的测试等于没有测试。**
 * 本项目踩过：`global $_ADMIN_ACTION_MAP;` 被注释掉时，
 * 那条回归测试**依然是绿的**（正则没剥注释，匹配到了说明文字）。
 * 「测试会不会变红」这件事本身必须被验证，且固化为流程。
 *
 * 退出码：0 全部通过 / 1 有问题
 */

declare(strict_types=1);

// ---- 前置检查：本脚本需要进程创建能力 ----
//
// 它只在 **CI 上**运行（那里 exec 可用）。但若有人手动在免费主机上跑它，
// 禁用函数会直接抛致命错误并留下一堆栈信息 —— 那很难看懂。
// 所以先说清楚「这里是 CI 用的，本地请用 php tests/run.php」。
if (!function_exists('exec')) {
    echo "ℹ️ 本脚本用于 CI（含注入回归，需要创建子进程）。\n";
    echo "   本主机禁用了 exec()，无法做注入回归 —— **这不影响测试本身**。\n";
    echo "   请改用： php tests/run.php        （输出人可读报告）\n";
    echo "              php tests/run.php --json （输出机器可读结果）\n";
    exit(0);
}

$root = dirname(__DIR__);
$aa   = $root . '/includes/admin-actions.php';

/** 跑一次测试，返回解析后的 JSON */
function runTests(string $root): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/run.php') . ' --json';
    $out = [];
    $rc  = 0;
    exec($cmd . ' 2>/dev/null', $out, $rc);
    $raw = implode("\n", $out);
    $d   = json_decode($raw, true);
    if (!is_array($d)) {
        fwrite(STDERR, "⛔ 无法解析 --json 输出：\n" . substr($raw, 0, 400) . "\n");
        exit(1);
    }
    $d['_rc'] = $rc;
    return $d;
}

$fail = 0;

// ==================================================================
// ① 数字自检
// ==================================================================

echo "── ① 收集器自检 ──\n";
$d = runTests($root);

if (empty($d['sound'])) {
    fwrite(STDERR, "⛔ 测试框架故障：" . ($d['why'] ?? '?') . "\n");
    echo "❌ 收集器不可信，报告结论无效\n";
    exit(1);
}
if (($d['pass'] + $d['fails']) !== $d['total']) {
    fwrite(STDERR, "⛔ 算术不符：{$d['pass']} + {$d['fails']} ≠ {$d['total']}\n");
    $fail = 1;
} else {
    printf("✅ %d / %d 项断言（%d 个文件，%.0f ms）\n",
        $d['pass'], $d['total'], $d['files'], (float) $d['elapsedMs']);
}

// 有失败项就逐条列出来——**不靠人眼扫终端**，直接读 JSON
if ($d['fails'] > 0) {
    foreach ($d['cases'] as $c) {
        if (empty($c['ok'])) {
            printf("  ✗ [%s] %s\n", $c['suite'], $c['name']);
            printf("      %s\n", $c['msg']);
            if (!empty($c['trace'])) {
                printf("      %s\n", $c['trace']);
            }
        }
    }
    $fail = 1;
}

// ==================================================================
// ② 注入回归：确认测试会变红
// ==================================================================

echo "\n── ② 注入回归自检（确认测试有效）──\n";

if (!is_file($aa)) {
    fwrite(STDERR, "⛔ 找不到 " . $aa . "\n");
    exit(1);
}

$backup = (string) file_get_contents($aa);
$restore = static function () use ($aa, $backup): void {
    file_put_contents($aa, $backup);
};
register_shutdown_function($restore);   // 无论如何都还原，绝不把改坏的源码留在仓库

$injections = [
    [
        'name' => '注释掉 adminHandlePost() 的 global（对应一次真实 500 白屏事故）',
        'apply' => static function (string $src): string {
            $out = preg_replace(
                '/^(\s*)global (\$_ADMIN_ACTION_MAP;)/m',
                '$1// [injected] $2',
                $src,
                1,
                $n
            );
            return $n > 0 ? (string) $out : '';
        },
    ],
    [
        'name' => '把 img.php 的 FOLLOWLOCATION 改回 true（SSRF 绕过回归）',
        'apply' => null,   // 跨文件，单独处理
        'file'  => 'img.php',
        'find'  => 'CURLOPT_FOLLOWLOCATION => false',
        'repl'  => 'CURLOPT_FOLLOWLOCATION => true',
    ],
    [
        // ⚠ 这条来自 1.3.9 的真实事故：加 --json 时用了 array_slice($argv, 1)，
        //   而 **$argv 只在 CLI SAPI 存在** —— 命令行测得通，浏览器直接 500。
        //   「CLI 测通 ≠ Web 跑通」这个教训，必须由 CI 钉住。
        'name'  => '去掉 run.php 的 $argv 守卫（复现 1.3.9 的 Web 500）',
        'apply' => null,
        'file'  => 'tests/run.php',
        'find'  => "&& isset(\$argv) && is_array(\$argv)",
        'repl'  => "&& is_array(\$argv)",
    ],
    [
        'name'  => '去掉 report.php 的 STDOUT 守卫（Web 下 Undefined constant）',
        'apply' => null,
        'file'  => 'tests/report.php',
        'find'  => "    if (!defined('STDOUT')) {\n        return false;\n    }\n",
        'repl'  => '',
    ],
];

foreach ($injections as $inj) {
    if ($inj === null) {
        continue;
    }

    // 情形 A：同文件内的文本替换
    if (isset($inj['file'])) {
        $target = $root . '/' . $inj['file'];
        if (!is_file($target)) {
            printf("⏭  跳过「%s」：找不到 %s\n", $inj['name'], $inj['file']);
            continue;
        }
        $orig = (string) file_get_contents($target);
        if (!str_contains($orig, (string) $inj['find'])) {
            printf("⏭  跳过「%s」：目标文本未找到 —— **这条回归测试可能已失效**\n", $inj['name']);
            $fail = 1;
            continue;
        }
        file_put_contents($target, str_replace((string) $inj['find'], (string) $inj['repl'], $orig));

        $r = runTests($root);
        file_put_contents($target, $orig);   // 立刻还原

        if (($r['_rc'] ?? 1) === 0) {
            fwrite(STDERR, "⛔ 注入后测试仍然全绿 —— 回归测试失效：{$inj['name']}\n");
            $fail = 1;
        } else {
            printf("✅ 注入「%s」→ 测试如期变红\n", $inj['name']);
        }
        continue;
    }

    // 情形 B：回调式替换
    $patched = $inj['apply']($backup);
    if ($patched === '') {
        printf("⏭  跳过「%s」：目标行未找到 —— **这条回归测试可能已失效**\n", $inj['name']);
        $fail = 1;
        continue;
    }
    file_put_contents($aa, $patched);

    $r = runTests($root);
    file_put_contents($aa, $backup);       // 立刻还原

    if (($r['_rc'] ?? 1) === 0) {
        fwrite(STDERR, "⛔ 注入后测试仍然全绿 —— 回归测试失效：{$inj['name']}\n");
        $fail = 1;
    } else {
        printf("✅ 注入「%s」→ 测试如期变红\n", $inj['name']);
    }
}

// 还原后必须恢复全绿
$after = runTests($root);
if (($after['_rc'] ?? 1) !== 0) {
    fwrite(STDERR, "⛔ 注入流程结束后测试未恢复全绿 —— 注入破坏了源码\n");
    foreach ($after['cases'] as $c) {
        if (empty($c['ok'])) {
            printf("  ✗ [%s] %s\n    %s\n", $c['suite'], $c['name'], $c['msg']);
        }
    }
    $fail = 1;
} else {
    echo "✅ 还原后恢复全绿\n";
}

// ==================================================================

echo "\n";
if ($fail === 0) {
    echo "\033[32m✅ 验证闭环自检通过\033[0m\n";
    exit(0);
}
echo "\033[31m❌ 验证闭环自检未通过\033[0m\n";
exit(1);
