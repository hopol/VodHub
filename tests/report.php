<?php
/**
 * 测试结果渲染器：**同一份结果，两种输出**
 *
 * 为什么需要它：
 *   原来 bootstrap 只吐 ANSI 转义码（`\033[32m✓`）。终端里好看，
 *   但浏览器里 `https://站点/tests/run.php` 看到的是一串
 *   `^[[32mM-bM-^\M-^S^[[0m` 这样的裸字节 —— 既没有颜色，也没有结构，
 *   152 行糊成一片，根本没法读。
 *
 * 现在按 SAPI 分流：
 *   - **CLI** → 保留 ANSI 颜色与紧凑排版（终端体验不变）
 *   - **Web** → 输出完整 HTML 页面：分组、统计卡片、失败项高亮、可折叠详情
 *
 * 两边**读的是同一份数据**（$GLOBALS['__tests']），所以不会出现
 * 「网页说全过、终端说失败」这种对不上的情况。
 */

declare(strict_types=1);

/** 当前是否是命令行执行 */
function vhTestIsCli(): bool {
    return PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
}

/** 终端是否支持颜色（NO_COLOR 是社区通用约定） */
function vhTestColorOn(): bool {
    // ⚠ 用 vhGetEnv 而非 getenv：实测 getenv 被列入 disable_functions 时
    //   PHP 8 抛**致命错误**（不是返回 false），会让整个测试报告挂掉。
    if (vhGetEnv('NO_COLOR') !== false) {
        return false;
    }
    if (DIRECTORY_SEPARATOR === '\\') {
        return vhGetEnv('ANSICON') !== false || vhGetEnv('WT_SESSION') !== false;
    }
    // ⚠ STDOUT 常量**只在 CLI SAPI 下存在**。Web SAPI（浏览器访问测试报告）
    //   调用到这里会抛「Undefined constant STDOUT」的致命错误 —— 实测踩过。
    //   所以先判 SAPI，再判常量存在性，两道都不能省。
    if (!vhTestIsCli()) {
        return false;
    }
    if (!defined('STDOUT')) {
        return false;
    }
    return function_exists('posix_isatty') ? @posix_isatty(STDOUT) : true;
}

// ==================================================================
// 终端输出（保持原有观感）
// ==================================================================

function vhTestRenderCli(array $list, string $title, float $ms): string {
    $c   = vhTestColorOn();
    $dim = static fn (string $s) => $c ? "\033[90m{$s}\033[0m" : $s;
    $ok  = static fn (string $s) => $c ? "\033[32m{$s}\033[0m" : $s;
    $bad = static fn (string $s) => $c ? "\033[31m{$s}\033[0m" : $s;
    $wrn = static fn (string $s) => $c ? "\033[33m{$s}\033[0m" : $s;
    $bld = static fn (string $s) => $c ? "\033[1m{$s}\033[0m"  : $s;

    $total = count($list);
    $fails = array_values(array_filter($list, static fn ($x) => !$x['ok']));

    $buf  = "\n" . $bld('── ' . $title . ' ') . $dim(str_repeat('─', max(0, 46 - mb_strlen($title)))) . "\n";

    $prevBad = false;
    foreach ($list as $x) {
        if ($x['ok']) {
            $buf .= '  ' . $ok('✓') . ' ' . $x['name'] . "\n";
            $prevBad = false;
            continue;
        }
        if (!$prevBad) {
            $buf .= "\n";
        }
        $buf .= '  ' . $bad('✗') . ' ' . $bld($x['name']) . "\n";
        $buf .= '      ' . $wrn($x['msg']) . "\n";
        $buf .= '      ' . $dim($x['trace']) . "\n";
        $prevBad = true;
    }

    $buf .= "\n  ";
    if ($fails === []) {
        $buf .= $ok('✅ 全部通过') . $dim("：{$total} 项断言，{$ms} ms") . "\n";
    } else {
        $buf .= $bad('❌ ' . count($fails) . " 项失败") . $dim("：共 {$total} 项，{$ms} ms") . "\n";
    }
    return $buf;
}

// ==================================================================
// 网页输出
// ==================================================================

function vhTestRenderHtml(array $list, string $title, float $ms, array $meta = []): string {
    $total = count($list);
    $fails = array_values(array_filter($list, static fn ($x) => !$x['ok']));
    $pass  = $total - count($fails);

    $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // 顶部统计
    $h  = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">';
    $h .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
    $h .= '<title>' . $e($title) . ' - 测试报告</title>';
    $h .= '<style>' . vhTestCss() . '</style></head><body><div class="wrap">';

    $h .= '<header class="hd"><h1>' . $e($title) . '</h1>';
    $h .= '<p class="sub">VodHub 行为测试 · 零依赖断言</p></header>';

    $h .= '<div class="cards">';
    $h .= '<div class="card ' . ($fails === [] ? 'c-ok' : 'c-bad') . '">'
        . '<div class="n">' . ($fails === [] ? '全部通过' : count($fails) . ' 项失败') . '</div>'
        . '<div class="d">' . $pass . ' / ' . $total . ' 项断言</div></div>';
    $h .= '<div class="card"><div class="n">' . $total . '</div><div class="d">断言总数</div></div>';
    $h .= '<div class="card"><div class="n">' . round($ms) . ' ms</div><div class="d">耗时</div></div>';
    $h .= '<div class="card"><div class="n">' . $e((string) ($meta['php'] ?? PHP_VERSION)) . '</div><div class="d">PHP 版本</div></div>';
    $h .= '</div>';

    // 失败项置顶（网页上第一眼就要看到问题）
    if ($fails !== []) {
        $h .= '<section class="bad"><h2>失败详情（' . count($fails) . '）</h2>';
        foreach ($fails as $x) {
            $h .= '<div class="case">';
            $h .= '<div class="cn"><span class="x">✗</span>' . $e($x['name']) . '</div>';
            $h .= '<div class="cm">' . $e($x['msg']) . '</div>';
            $h .= '<div class="ct">' . $e($x['trace']) . '</div>';
            $h .= '</div>';
        }
        $h .= '</section>';
    }

    // 全部用例
    $h .= '<section><h2>全部用例（' . $total . '）</h2><ul class="list">';
    foreach ($list as $x) {
        $h .= '<li class="' . ($x['ok'] ? 'p' : 'f') . '">'
            . '<span class="m">' . ($x['ok'] ? '✓' : '✗') . '</span>'
            . '<span class="n">' . $e($x['name']) . '</span></li>';
    }
    $h .= '</ul></section>';

    $h .= '<footer>由 <code>tests/run.php</code> 生成 · '
        . '命令行执行：<code>php tests/run.php</code></footer>';
    $h .= '</div></body></html>';
    return $h;
}

/**
 * 报告样式。
 *
 * 刻意**不引外部 CSS**：测试要在任何主机上独立可读，
 * 不能依赖站点样式表是否被正确传上来（站点出问题时正是最需要看报告的时候）。
 * 颜色变量与 static/style.css 的 :root 保持同名，视觉上与后台一致。
 */
function vhTestCss(): string {
    return <<<'CSS'
:root{
  --bg:#0f1115;--card:#1b1f27;--bg-3:#1f232c;--border:#2a2f3a;
  --text:#e8eaee;--text-2:#9aa3b2;--text-3:#6b7383;
  --ok:#34a853;--bad:#e05656;--primary:#4f8cff;
  --radius:12px;--shadow:0 4px 20px rgba(0,0,0,.35);
}
@media (prefers-color-scheme:light){
  :root{
    --bg:#f5f6f8;--card:#fff;--bg-3:#eef0f3;--border:#e2e5ea;
    --text:#1a1d23;--text-2:#5a6272;--text-3:#8a92a2;
    --ok:#1e8e3e;--bad:#c5221f;--shadow:0 2px 10px rgba(0,0,0,.08);
  }
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);
  font:15px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC",
  "Hiragino Sans GB","Microsoft YaHei",sans-serif}
.wrap{max-width:960px;margin:0 auto;padding:28px 20px 60px}
.hd h1{margin:0;font-size:24px;letter-spacing:.3px}
.hd .sub{margin:6px 0 0;color:var(--text-2);font-size:14px}
.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));
  gap:12px;margin:22px 0 28px}
.card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);
  padding:16px 18px;box-shadow:var(--shadow)}
.card .n{font-size:22px;font-weight:600;line-height:1.3}
.card .d{margin-top:4px;color:var(--text-2);font-size:13px}
.c-ok .n{color:var(--ok)} .c-bad .n{color:var(--bad)}
section{margin:0 0 30px}
section h2{font-size:16px;margin:0 0 12px;padding-bottom:8px;
  border-bottom:1px solid var(--border);color:var(--text-2);font-weight:600}
.bad .case{background:var(--card);border:1px solid var(--bad);border-left-width:4px;
  border-radius:10px;padding:14px 16px;margin-bottom:10px}
.bad .cn{font-weight:600;display:flex;gap:9px;align-items:baseline}
.bad .x{color:var(--bad);font-weight:700}
.bad .cm{margin-top:8px;color:var(--text);font-family:ui-monospace,SFMono-Regular,
  Menlo,Consolas,monospace;font-size:13px;line-height:1.65;
  background:var(--bg-3);border-radius:8px;padding:10px 12px;
  white-space:pre-wrap;word-break:break-word}
.bad .ct{margin-top:7px;color:var(--text-3);font-size:12px;
  font-family:ui-monospace,Menlo,Consolas,monospace}
.note{background:rgba(255,176,32,.12);border:1px solid rgba(255,176,32,.45);
  color:var(--text);border-radius:10px;padding:11px 15px;margin:0 0 18px;font-size:14px}
section h2 .tag{float:right;font-size:12.5px;font-weight:600;padding:2px 10px;
  border-radius:20px;letter-spacing:.2px}
.t-ok{background:rgba(52,168,83,.16);color:var(--ok)}
.t-bad{background:rgba(224,86,86,.16);color:var(--bad)}
.case .suite{font-size:12px;color:var(--text-3);font-weight:400;
  font-family:ui-monospace,Menlo,Consolas,monospace}
.empty{color:var(--text-3);font-size:14px;margin:0;padding:14px;
  background:var(--card);border:1px solid var(--border);border-radius:10px}
.list{list-style:none;margin:0;padding:0;background:var(--card);
  border:1px solid var(--border);border-radius:var(--radius);overflow:hidden}
.list li{display:flex;gap:11px;padding:9px 15px;border-bottom:1px solid var(--border);
  font-size:14px}
.list li:last-child{border-bottom:0}
.list .m{font-weight:700;flex:0 0 16px}
.list .p .m{color:var(--ok)} .list .f .m{color:var(--bad)}
.list .f{background:rgba(224,86,86,.07)}
footer{margin-top:34px;padding-top:16px;border-top:1px solid var(--border);
  color:var(--text-3);font-size:13px}
code{background:var(--bg-3);padding:2px 6px;border-radius:5px;
  font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12.5px}
@media (max-width:600px){
  .wrap{padding:20px 14px 44px}
  .hd h1{font-size:20px}
  .card .n{font-size:19px}
}
CSS;
}

/**
 * 本次实际用的执行路径（显示在报告头部，让人知道结果是怎么跑出来的）。
 */
function vhTestRunMode(): string {
    if (function_exists('exec') && !in_array('exec',
        array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
        return 'exec 子进程';
    }
    if (function_exists('proc_open')) {
        return 'proc_open 子进程';
    }
    return '同进程兜底';
}

// ==================================================================
// 网页汇总报告（run.php 用）
// ==================================================================

/**
 * 把多个测试文件的结果汇总成一份 HTML。
 *
 * 与 vhTestRenderHtml() 的分工：那个渲染**单个**测试文件的结果
 * （直接访问 tests/test_xxx.php 时用），这个渲染**全套**——
 * 带文件分组、每组统计、以及「本主机环境提示」。
 *
 * @param array $results name => ['rc'=>int,'text'=>string,'cases'=>array]
 * @param array $cases   展平后的全部用例（每项含 suite 键）
 * @param array $failed  失败的文件名
 * @param array $notices 环境提示（如 exec 被禁用）
 */
function vhTestRenderSuite(array $results, array $cases, array $failed, array $notices, float $ms): string {
    $e = static fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $total = count($cases);
    $fails = array_values(array_filter($cases, static fn ($x) => empty($x['ok'])));
    $pass  = $total - count($fails);

    $h  = '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="utf-8">';
    $h .= '<meta name="viewport" content="width=device-width,initial-scale=1">';
    $h .= '<title>VodHub 测试报告</title>';
    $h .= '<style>' . vhTestCss() . '</style></head><body><div class="wrap">';

    $h .= '<header class="hd"><h1>VodHub 测试报告</h1>';
    $h .= '<p class="sub">' . count($results) . ' 个测试文件 · ' . PHP_VERSION
        . ' · ' . (defined('VHTEST_EMBEDDED') && VHTEST_EMBEDDED ? '同进程模式' : '独立进程模式')
        . '</p></header>';

    // 环境提示（如 exec 被禁用）—— 必须显眼，因为它影响结果可信度
    foreach ($notices as $n) {
        // 提示文案里允许 **粗体** —— 终端下是 ANSI 之外的纯文本，
        // 网页下要渲染成 <strong>，所以这里做一次极简替换（不做通用 Markdown）。
        $h .= '<div class="note">⚠ ' . str_replace('**', '<strong>', $e($n)) . '</div>';
    }

    $h .= '<div class="cards">';
    $h .= '<div class="card ' . ($fails === [] ? 'c-ok' : 'c-bad') . '">'
        . '<div class="n">' . ($fails === [] ? '全部通过' : count($fails) . ' 项失败') . '</div>'
        . '<div class="d">' . $pass . ' / ' . $total . ' 项断言</div></div>';
    $h .= '<div class="card"><div class="n">' . count($results) . '</div><div class="d">测试文件</div></div>';
    $h .= '<div class="card"><div class="n">' . round($ms) . ' ms</div><div class="d">总耗时</div></div>';
    $h .= '<div class="card"><div class="n">' . count($failed) . '</div><div class="d">失败文件</div></div>';
    $h .= '</div>';

    // 失败详情置顶
    if ($fails !== []) {
        $h .= '<section class="bad"><h2>失败详情（' . count($fails) . '）</h2>';
        foreach ($fails as $x) {
            $h .= '<div class="case">';
            $h .= '<div class="cn"><span class="x">✗</span>' . $e($x['name']);
            $h .= ' <span class="suite">' . $e((string) ($x['suite'] ?? '')) . '</span></div>';
            $h .= '<div class="cm">' . $e((string) ($x['msg'] ?? '')) . '</div>';
            $h .= '<div class="ct">' . $e((string) ($x['trace'] ?? '')) . '</div>';
            $h .= '</div>';
        }
        $h .= '</section>';
    }

    // 按文件分组列出全部用例
    $bySuite = [];
    foreach ($cases as $c) {
        $bySuite[(string) ($c['suite'] ?? '未分组')][] = $c;
    }
    foreach ($results as $name => $r) {
        $list = $bySuite[$name] ?? [];
        $sf   = count(array_filter($list, static fn ($x) => empty($x['ok'])));
        $h .= '<section>';
        $h .= '<h2>' . $e($name)
            . '<span class="tag ' . ($sf === 0 ? 't-ok' : 't-bad') . '">'
            . ($sf === 0 ? '✓ ' . count($list) : '✗ ' . $sf . ' / ' . count($list))
            . '</span></h2>';
        if ($list === []) {
            $h .= '<p class="empty">未收集到用例明细</p>';
        } else {
            $h .= '<ul class="list">';
            foreach ($list as $x) {
                $ok = !empty($x['ok']);
                $h .= '<li class="' . ($ok ? 'p' : 'f') . '">'
                    . '<span class="m">' . ($ok ? '✓' : '✗') . '</span>'
                    . '<span class="n">' . $e((string) ($x['name'] ?? '')) . '</span></li>';
            }
            $h .= '</ul>';
        }
        $h .= '</section>';
    }

    $h .= '<footer>由 <code>tests/run.php</code> 生成 · '
        . '命令行等价物：<code>php tests/run.php</code><br>'
        . '本报告只反映「当前这份代码」的状态，刷新即重跑。</footer>';
    $h .= '</div></body></html>';
    return $h;
}
