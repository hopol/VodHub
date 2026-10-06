<?php
/**
 * 后台动作分发的覆盖性断言
 *
 * **直接对应一起线上事故**（CHANGELOG 记录）：
 *   未知 action 本该返回「⚠️ 未知操作」这句优雅提示 —— 而它恰恰是排查
 *   「服务器上的 admin-actions.php 版本不对」的**唯一线索**。
 *   但 adminHandlePost() 里漏了 `global $_ADMIN_ACTION_MAP`，
 *   最后那句 `count($_ADMIN_ACTION_MAP)` 直接 Fatal TypeError → **500 白屏**。
 *   提示自己先把页面打死了。
 *
 * 本文件锁三件事：
 *   1. 映射表在函数内可见（global 没被再删掉）—— 直接防那次事故复发；
 *   2. 表单里出现的每个 action 都已注册（防「加了按钮忘了注册」这种静默失败）；
 *   3. 每个 action 都有对应的处理函数（防映射表指向不存在的函数）。
 *
 * 只做**静态结构检查**，不执行任何 action —— 后台动作会写库、出站，
 * 那属于集成测试范畴；本文件要守住的是「结构完整」这一层。
 */

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/../includes/admin-actions.php';

$root = dirname(__DIR__);

// ==================================================================
// 一、映射表本身
// ==================================================================

t('映射表是全局变量且非空', static function (): void {
    // 注意：闭包里 $_ADMIN_ACTION_MAP 不可见，必须走 $GLOBALS
    ok(
        isset($GLOBALS['_ADMIN_ACTION_MAP']),
        '映射表必须在全局作用域（admin.php 与测试都直接读它）'
    );
    ok(
        count($GLOBALS['_ADMIN_ACTION_MAP']) >= 17,
        '动作数不应少于 17 个（1.3.3 的基线）'
    );
});

t('每个 action 都有对应的处理函数（防映射表指向不存在的函数）', static function (): void {
    global $_ADMIN_ACTION_MAP;
    foreach ($_ADMIN_ACTION_MAP as $action => $fn) {
        ok(function_exists($fn), "action「{$action}」指向的 {$fn}() 不存在");
        ok(
            str_starts_with($fn, 'adminAction'),
            "action「{$action}」的处理器名 {$fn} 不符 adminAction* 约定"
        );
    }
});

t('处理函数名与 action 名的反推规则一致（兜底路径依赖它）', static function (): void {
    global $_ADMIN_ACTION_MAP;
    // adminHandlePost() 的兜底：'update_source' → adminActionUpdateSource
    // 这条推导规则一旦与实际命名不符，兜底路径就成了死代码。
    foreach ($_ADMIN_ACTION_MAP as $action => $fn) {
        $derived = 'adminAction' . str_replace(' ', '', ucwords(str_replace('_', ' ', (string) $action)));
        eq($derived, $fn, "action「{$action}」");
    }
});

t('action 名不含非法字符（会被拼进类文件名 / 反射调用）', static function (): void {
    global $_ADMIN_ACTION_MAP;
    foreach (array_keys($_ADMIN_ACTION_MAP) as $action) {
        ok(
            preg_match('/^[a-z][a-z0-9_]*$/', (string) $action),
            "action「{$action}」命名不合规（应全小写下划线）"
        );
    }
});

t('映射表无重复 value（两个 action 指向同一函数必有一处是错的）', static function (): void {
    global $_ADMIN_ACTION_MAP;
    $fns = array_values($_ADMIN_ACTION_MAP);
    eq(count($fns), count(array_unique($fns)), '有多个 action 指向同一个处理函数');
});

// ==================================================================
// 二、表单覆盖率：这是「加了按钮忘了注册」的唯一防线
// ==================================================================

/**
 * 后台表单所在的全部源文件。
 *
 * ⚠ 1.5.3 拆 admin.php 后，表单 HTML 已经**不在 admin.php 里**了
 * （拆进了 includes/admin/view/*.php）。当时这条判据还读 `admin.php` 一个文件，
 * 结果只解析出 0 个 action、当场变红 —— **不是因为代码坏了，是判据的范围过时了**。
 *
 * 现在按「**哪些文件可能放表单**」去扫，而不是「我印象里表单在哪个文件」：
 *   ① admin.php（外壳、还有 login 那类直接输出的表单）
 *   ② includes/admin/view/*.php（1.5.3 拆出的 5 个区块，表单全在这里）
 *
 * 将来再拆一层（比如 sections/ 子目录），把那个目录加进来即可；
 * 关键是**范围由「视图放哪」决定，由路径描述，不写死单个文件名**。
 */
function tAdminHtmlSources(string $root): string {
    $src = '';
    foreach (['/admin.php', '/login.php'] as $f) {
        $src .= (string) @file_get_contents($root . $f);
    }
    foreach (glob($root . '/includes/admin/view/*.php') ?: [] as $f) {
        $src .= "\n" . (string) file_get_contents($f);
    }
    return $src;
}

t('后台表单里的每个 action 都已注册', static function () use ($root): void {
    global $_ADMIN_ACTION_MAP;
    $html = tAdminHtmlSources($root);
    ok($html !== '', '读不到任何后台源文件');

    preg_match_all('/name="action"\s+value="([a-z_]+)"/', $html, $m);
    $used = array_values(array_unique($m[1]));
    ok(count($used) > 0, '没从后台源文件里解析出任何 action —— 正则与模板不匹配了'
        . '（1.5.3 之后表单在 includes/admin/view/ 里，别只读 admin.php）');

    $missing = [];
    foreach ($used as $a) {
        if (!isset($_ADMIN_ACTION_MAP[$a])) {
            $missing[] = $a;
        }
    }
    eq([], $missing, '这些 action 在表单里能提交，但映射表里没注册（点了必然「未知操作」）');
});

t('action 命名风格在表单与映射表之间一致', static function () use ($root): void {
    global $_ADMIN_ACTION_MAP;
    $html = tAdminHtmlSources($root);
    preg_match_all('/name="action"\s+value="([a-z_]+)"/', $html, $m);
    foreach (array_unique($m[1]) as $a) {
        ok(
            isset($_ADMIN_ACTION_MAP[$a]) || $a === 'login',
            "表单 action「{$a}」既未注册也不在白名单里（login 由 admin.php 自己处理）"
        );
    }
});

// ==================================================================
// 三、那次 500 白屏事故的直接回归防护
// ==================================================================

t('【回归】adminHandlePost() 内部有 global $_ADMIN_ACTION_MAP', static function () use ($root): void {
    $src = (string) @file_get_contents($root . '/includes/admin-actions.php');
    // 取出 adminHandlePost() 的函数体，检查 global 语句还在
    ok(
        preg_match('/function\s+adminHandlePost\s*\(\s*\)\s*:\s*string\s*\{(.*?)\n\}/s', $src, $fn),
        '解析不到 adminHandlePost() 函数体'
    );
    // ⚠ 必须先剥掉注释再找 global —— 否则把 global 注释掉（模拟删掉）
    //   也能匹配上，测试就成了摆设。1.3.3 那次事故的根因正是「以为在、其实没了」。
    $body = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $fn[1]);
    ok(
        preg_match('/(^|[^\w$])global\s+\$_ADMIN_ACTION_MAP\s*;/', (string) $body),
        'adminHandlePost() 里缺 global $_ADMIN_ACTION_MAP —— '
        . '删掉它会让 count($_ADMIN_ACTION_MAP) 在 null 上调用，'
        . '「未知操作」的优雅提示变成 Fatal TypeError 500 白屏（1.3.3 修过一次，别改回去）'
    );
});

t('【回归】未知 action 走的是 return 而不是致命错误', static function () use ($root): void {
    $src = (string) @file_get_contents($root . '/includes/admin-actions.php');
    ok(
        str_contains($src, '未知操作'),
        '找不到「未知操作」提示 —— 它是排查「服务器文件版本不对」的唯一线索，不能删'
    );
    // 提示语与 count() 必须在同一句 return 里（拼接成一句给用户看）
    ok(
        preg_match('/未知操作.{0,120}?count\(\$_ADMIN_ACTION_MAP\)/s', $src),
        '未知操作提示里应带上映射表项数（判断服务器文件版本的直接线索）'
    );
});

t('【回归】action 读取处有 trim 容错（上游缓存可能带空白）', static function (): void {
    global $root;
    $src = (string) @file_get_contents($root . '/includes/admin-actions.php');
    ok(
        preg_match('/trim\s*\(\s*\(string\)\s*\(?\s*is_scalar/', $src),
        '$_POST[\'action\'] 读取处应做 is_scalar + trim 容错，'
        . '否则传数组进来会因 is_scalar 判定缺失而行为异常'
    );
});

// ==================================================================
// 四、被 1.3.3 修过的脏数据防护（防改回去）
// ==================================================================

t('playFromList 与 parsePlayUrl 仍处理连续 $ 的脏数据', static function (): void {
    // 1.3.3 专门修过「蓝光$$1080P」这类上游脏数据。若有人简化代码时删掉处理，
    // 表现是播放器拿到 "$地址" 直接坏掉 —— 属于静默故障。
    eq(['蓝光'], playFromList('蓝光$$1080P'));
    eq(
        [['label' => '蓝光', 'url' => 'https://a/1.m3u8']],
        parsePlayUrl('蓝光$$https://a/1.m3u8')
    );
});

t('每个 action 的处理函数都带 docblock 或至少有实现体', static function () use ($root): void {
    global $_ADMIN_ACTION_MAP;
    $src = (string) @file_get_contents($root . '/includes/admin-actions.php');
    foreach ($_ADMIN_ACTION_MAP as $action => $fn) {
        ok(
            preg_match('/function\s+' . preg_quote($fn, '/') . '\s*\([^)]*\)\s*:\s*string\s*\{/', $src),
            "{$fn}() 的签名应统一为 (...): string —— adminHandlePost() 直接 return 它的返回值"
        );
    }
});

finish();
