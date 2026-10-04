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
 *   ② 验证「测试本身有效」——故意改坏八处，确认每一处都会让测试变红。
 *
 * 第 ② 条的理由：**一个不会变红的测试等于没有测试。**
 * 本项目踩过：`global $_ADMIN_ACTION_MAP;` 被注释掉时，
 * 那条回归测试**依然是绿的**（正则没剥注释，匹配到了说明文字）。
 * 「测试会不会变红」这件事本身必须被验证，且固化为流程。
 *
 * ── 为什么是八处（1.3.11 从四处扩到八处）────────────────────────
 *
 *   四处够不够？不够。本项目过去 12 次发布里 7 次栽在同一类错误上 ——
 *   「一个必须始终成立的约定，只存在于人的记忆里，没有任何机制在看着它」。
 *   而注入回归正是把「记忆」变成「机制」的手段。
 *
 *   **选择的四条标准**（每条注入都必须同时满足，否则不放进来）：
 *     ① 对应一次**真实发生过**的事故 —— 不是假想的安全加固；
 *     ② 该事故**确实被一条测试覆盖**（否则注入了也不会红，这条注入就是自欺）；
 *     ③ 改动**最小且可精确回滚** —— 用固定文本替换，不用正则猜；
 *     ④ 覆盖一个**此前没有注入过的文件**，让「注入机制」本身也分布开。
 *
 *   新增的四条见下方 $injections 的注释，每条都写明对应哪次事故。
 *
 * ⚠⚠ **每一条注入都必须验证两件事，缺一不可**：
 *     ① 注入后测试**变红**；
 *     ② 还原后测试**恢复全绿**。
 *   只验 ① 的注入可能「因为别的巧合而变红」——
 *   本项目 1.3.9 就栽在这上面（判据自欺）。② 由文件末尾统一校验。
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

// 1.3.11 起注入分布在 6 个文件上，而旧实现只备份/还原 admin-actions.php 一个
// （情形 B 回调式替换的隐含前提）。这里改成**按需备份**：每个目标文件只在
// 第一次被注入时留档，shutdown 时统一还原 —— 避免「还原了没备份过的文件」这类事故。
$__vhBackups = [];

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

/** 取某文件的原始内容（首次调用时留档，之后都从留档还原） */
function injOriginal(string $path): string {
    global $__vhBackups;
    if (!array_key_exists($path, $__vhBackups)) {
        $__vhBackups[$path] = (string) file_get_contents($path);
    }
    return $__vhBackups[$path];
}

/** 把文件还原成 injOriginal() 留档的那份 */
function injRestore(string $path): void {
    global $__vhBackups;
    if (array_key_exists($path, $__vhBackups)) {
        file_put_contents($path, $__vhBackups[$path]);
    }
}

// 无论如何都还原，绝不把改坏的源码留在仓库。
// ⚠ 必须逐个还原而不是「重新读一遍」——被改坏的文件读回来也是坏的。
register_shutdown_function(static function (): void {
    global $__vhBackups;
    foreach ($__vhBackups as $path => $orig) {
        file_put_contents($path, $orig);
    }
});

// ⚠ 每条注入都要满足文件头那四条标准。对应事故的编号见 CHANGELOG。
//   已有的四条是 1.3.4 ~ 1.3.9 的真实事故；1.3.11 新增后四条。
$injections = [
    [
        // 1.3.3：漏 global $_ADMIN_ACTION_MAP → count() 抛 TypeError → 后台 500 白屏。
        // 那句「⚠️ 未知操作」是排查「服务器上的文件版本不对」的唯一线索，
        // 提示自己先把页面打死了。
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
        // 1.3.4：curl 自动跟随最多 3 次重定向，而重定向目标**从未复检** ——
        // 「白名单内的公网域名 → 302 → 127.0.0.1」，已实测可利用。
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
        // 1.3.9：tests/report.php 在 Web SAPI 下引用未定义的 STDOUT 常量。
        'name'  => '去掉 report.php 的 STDOUT 守卫（Web 下 Undefined constant）',
        'apply' => null,
        'file'  => 'tests/report.php',
        'find'  => "    if (!defined('STDOUT')) {\n        return false;\n    }\n",
        'repl'  => '',
    ],

    // ═══════════════════════ 1.3.11 新增的四条 ═══════════════════════
    // 选取理由见文件头：每条都对应一次真实事故，且此前**没有任何注入覆盖它们**。
    // 前四条集中在 admin-actions.php / img.php / tests/，
    // 这四条把覆盖摊到 db.php / fields.php / imgcache.php / auth.php ——

    [
        // ⭐ **这一条对应的是用户每天都会踩到、且 199 项测试全绿的缺陷**：
        //
        //   后台把源的模板从深色改成 B站粉 → 提示「✅ 已更新数据源」
        //   → 前台 Ctrl+F5 强刷 → **还是旧的** → 最多要等一整点才翻篇。
        //
        //   根因：写 sources 的 6 个 CRUD 函数**一个都没调 pcClear()**，
        //   而 docs/lowpower.md 一直写着「任意配置写入都会自动全量作废」。
        //   修法是加 pcClear()，由 tests/test_contracts.php 第 1 条契约强制。
        //
        //   为什么这条注入价值最高：它是**唯一一个「用户可见」的注入**，
        //   其余七条都是「内部正确性」。而它证明的恰恰是最难靠人记住的那类约定。
        //
        // ⚠ 注入后 test_contracts 必然变红 —— 若不红，说明那条契约已经失效，
        //   这条注入本身也就没有意义了（本项目 1.3.9 的教训）。
        'name'  => '注释掉 updateSource() 里的 pcClear()（复现「改数据源前台不变」）',
        'apply' => null,
        'file'  => 'includes/db.php',
        'find'  => "                    \$groupId, \$imgProxy, \$imgHosts, \$id]);\n"
                 . "    // 契约：换模板 / 改名 / 改 URL / 改分组 / 改图片代理，前台全都看得见。\n"
                 . "    // 这条 1.3.10 之前是漏的 —— 后台显示保存成功，前台一整点内都是旧页面。\n"
                 . "    pcClear();\n",
        'repl'  => "                    \$groupId, \$imgProxy, \$imgHosts, \$id]);\n",
    ],
    [
        // 1.3.4：`vod_remarks` 上游偶尔直接给 null，而签名只收 string 时，
        // 任何一处调用方忘了 (string) 强转就是 **TypeError → 整站 500**（致命错误，不是警告）。
        // 当时三处调用点全靠强转兜着 —— 那是「必须由每个调用方记得强转」的隐式契约，
        // 改成 ?string 后由类型系统兜住，调用方也一并简化。
        //
        // ⚠ 这条与前七条性质不同：它验的是**类型系统兜底**这件事本身。
        //   PHP 在弱类型模式下把 null 传给 string 参数仍是 TypeError（已实测），
        //   所以这条注入是真的能把「忘了加 ?」打回原形。
        'name'  => '把 normalizeRemarks() 的 ?string 改回 string（复现 1.3.4 的整站 TypeError）',
        'apply' => null,
        'file'  => 'includes/fields.php',
        'find'  => 'function normalizeRemarks(?string $raw): ?string {',
        'repl'  => 'function normalizeRemarks(string $raw): ?string {',
    ],
    [
        // 1.3.4：SVG 属于 image/*，img.php 的「只放行图片类响应」拦不住它；
        // 一旦落盘到 static/imgcache/*.svg（**在 Web 根内、Apache 直接可访问**），
        // 里面可以内嵌 <script> 或 <svg onload=...>，
        // 任何人访问那个 URL 都会**以本站同源身份执行脚本** —— 存储型 XSS。
        'name'  => '让图片缓存重新收下 svg（复现 1.3.4 的存储型 XSS）',
        'apply' => null,
        'file'  => 'includes/imgcache.php',
        'find'  => "    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico'];",
        'repl'  => "    return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'svg'];",
    ],
    [
        // ⚠ 1.3.12 去重时踩到并修掉的一个真 bug：
        //   模板页面内部原本写 `require_once __DIR__ . '/footer.php'` 引用同级文件，
        //   而各模板里与 default 逐字节相同的 footer.php **被删掉了**。
        //   `__DIR__` 是硬路径、零回退 —— 于是唯一必须保留的 bilibili/list.php
        //   直接 `Failed opening required .../bilibili/footer.php` 整页 500。
        //
        //   其余模板之所以没事，正是因为它们的 list.php 也被删了（走 renderTemplate 回退）。
        //   也就是说：**当时只有 1/20 的概率会暴露**。
        //
        //   这条注入把那个雷重新埋回去，验证 test_contracts 的契约 5 会响。
        //   ⚠ 期望「解析失败」也算变红 —— run.php 解析不了注入后的文件时
        //   会如实报「该测试文件未产生任何结果」，退出码非 0，判定照样成立。
        'name'  => '把模板的 tplInclude 改回 __DIR__ 硬路径（复现去重时的整页 500）',
        'apply' => null,
        'file'  => 'templates/bilibili/list.php',
        'find'  => "require tplInclude('footer.php', \$tplName);",
        'repl'  => "require_once __DIR__ . '/footer.php';",
    ],
    [
        // ⚠ 1.3.12 最危险的一处改动：
        //   去重删掉了非 default 模板的 index.php，而 tplExists() 原来只认 index.php。
        //   若有人「顺手改回去」，resolveTemplate() 一路回退 default、
        //   adminActionEditTemplate() 直接拒绝保存 ——
        //   **4 套非 default 模板静默全部失效，且没有任何报错**。
        'name'  => '把 tplExists() 的判据改回只认 index.php（非 default 模板会静默全废）',
        'apply' => null,
        'file'  => 'includes/template.php',
        'find'  => "    return is_file(tplRoot() . '/' . \$name . '/theme.json');",
        'repl'  => "    return is_file(tplRoot() . '/' . \$name . '/index.php');",
    ],
    [
        // 1.3.4：`'secure' => true` 写死会让**纯 HTTP 的免费主机登录完全失效**
        // （Cookie 发不出去，表现为「密码对但进不去」，极难查）。
        // 本项目大量部署在没有 HTTPS 的免费空间上，所以必须按当前请求动态判断。
        'name'  => '把会话 Cookie 的 secure 写死 true（复现 1.3.4 的登录失效）',
        'apply' => null,
        'file'  => 'includes/auth.php',
        'find'  => "'secure'   => vhIsHttpsRequest(),",
        'repl'  => "'secure'   => true,",
    ],
];

// 本应验证的注入数：**事先数清**，而不是边跑边加。
// 边跑边加会让第一条打印成「1/1」（当时只见过自己），看上去像全都验过了 ——
// 这与 1.3.7「汇总不报总数导致漏报一次失败」是同一个错误。
$injList  = array_values(array_filter($injections, static fn ($x) => $x !== null));
$injTotal = count($injList);
$injRed   = 0;   // 实际验证「如期变红」的注入数

foreach ($injList as $inj) {

    // 情形 A：固定文本替换（跨文件）
    //
    // ⚠ 用 `find` 全文匹配而不是正则猜位置：注入必须**可精确回滚**。
    //   正则匹配到多处时 str_replace/preg_replace 的行为会变得难以预期，
    //   而「注入机制本身出错」和「注入成功」必须能被区分开。
    if (isset($inj['file'])) {
        $target = $root . '/' . $inj['file'];
        if (!is_file($target)) {
            printf("⛔ 跳过「%s」：找不到 %s —— **这条注入本身已失效**\n", $inj['name'], $inj['file']);
            $fail = 1;
            continue;
        }
        $orig = injOriginal($target);
        if (!str_contains($orig, (string) $inj['find'])) {
            // ⚠⚠ **这一条必须判失败，不能只跳过**：
            //   目标文本找不到，意味着「注入点已被重构掉了」。
            //   如果只打印一行就放过，那么**这次 CI 根本没验到这条注入**，
            //   而报告却显示「8 条注入全部验证通过」—— 那是本项目
            //   1.3.9「判据自欺」的同一个错误：报告结论与实际做的事对不上。
            fwrite(STDERR, "⛔ 「{$inj['name']}」：目标文本未找到。"
                . "这说明代码被重构了，本条注入**已不再覆盖任何东西**，必须重写它。\n");
            $fail = 1;
            continue;
        }
        file_put_contents($target, str_replace((string) $inj['find'], (string) $inj['repl'], $orig));

        $r = runTests($root);
        injRestore($target);   // 立刻还原（留档，别拿被改坏的当前内容当原文）

        if (($r['_rc'] ?? 1) === 0) {
            fwrite(STDERR, "⛔ 注入后测试仍然全绿 —— 回归测试失效：{$inj['name']}\n");
            $fail = 1;
        } else {
            $injRed++;
            printf("✅ 注入 %d/%d「%s」→ 测试如期变红\n", $injRed, $injTotal, $inj['name']);
        }
        continue;
    }

    // 情形 B：回调式替换（同一文件内的多次替换，如 global 那条）
    $patched = $inj['apply'](injOriginal($aa));
    if ($patched === '') {
        fwrite(STDERR, "⛔ 「{$inj['name']}」：目标行未找到 —— 这条注入已失效，必须重写。\n");
        $fail = 1;
        continue;
    }
    file_put_contents($aa, $patched);

    $r = runTests($root);
    injRestore($aa);          // 立刻还原

    if (($r['_rc'] ?? 1) === 0) {
        fwrite(STDERR, "⛔ 注入后测试仍然全绿 —— 回归测试失效：{$inj['name']}\n");
        $fail = 1;
    } else {
        $injRed++;
        printf("✅ 注入 %d/%d「%s」→ 测试如期变红\n", $injRed, $injTotal, $inj['name']);
    }
}

// 汇总数必须与实际验证数相等 —— 和 vhTally() 同一个道理：
// **只报「验证了 8 条」而不报「本应验证 8 条」，漏掉的就看不见了。**
if ($injRed !== $injTotal) {
    fwrite(STDERR, "⛔ 注入汇总不符：验证了 {$injRed} 条，本应有 {$injTotal} 条\n");
    $fail = 1;
} else {
    printf("✅ %d / %d 条注入全部验证有效（每条都「改坏→变红」过）\n", $injRed, $injTotal);
}

// 还原后必须恢复全绿。
//
// ⚠⚠ 这一条与「每条注入都变红」**同等重要**，缺了它整套注入就是自欺：
//   一次注入可能「因为别的原因」变红（比如顺手把源码改坏了），
//   而只要还原后仍是红的，我们至少能立刻发现「注入破坏了源码」。
//   这是 1.3.9 那条教训的另一半：**判据的有效性要由判据之外的证据来确认。**
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
    printf("✅ 还原后恢复全绿（%d / %d 项断言）\n", $after['pass'], $after['total']);
}

// 最后一道：确认每个被注入过的文件**逐字节回到了原样**。
//
// 为什么还要查这一层：上面「恢复全绿」只说明**测试**通过了，
// 而「源码被悄悄改坏但恰好没影响到任何断言」是完全可能的
//（比如注释掉了一段没人断言的代码）。
// 注入机制自己必须可验证 —— 否则「8 条注入全过」这句话本身就不可信。
$drifted = [];
foreach ($__vhBackups as $path => $orig) {
    if ((string) file_get_contents($path) !== $orig) {
        $drifted[] = str_replace($root . '/', '', $path);
    }
}
if ($drifted !== []) {
    fwrite(STDERR, "⛔ 注入结束后这些文件没有逐字节还原：\n  " . implode("\n  ", $drifted)
        . "\n   还原机制本身有问题 —— 这比任何一条注入失效都严重。\n");
    $fail = 1;
} else {
    printf("✅ %d 个被注入的文件全部逐字节还原\n", count($__vhBackups));
}

// ==================================================================

echo "\n";
if ($fail === 0) {
    echo "\033[32m✅ 验证闭环自检通过\033[0m\n";
    exit(0);
}
echo "\033[31m❌ 验证闭环自检未通过\033[0m\n";
exit(1);
