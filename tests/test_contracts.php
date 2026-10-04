<?php
/**
 * 契约测试：盯**跨文件约定**，而不是单个函数的行为
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  为什么要有这一类测试（这是本文件存在的全部理由）
 * ═══════════════════════════════════════════════════════════════════════
 *
 *  现有的 199 项测试验的是「某个函数算得对不对」。
 *  但 2026-10-04 查出的那个用户可见缺陷（P0-1）**不在其中**：
 *
 *    后台把源的模板从深色改成 B站粉 → 提示「✅ 已更新数据源」
 *    → 前台 Ctrl+F5 强刷 → **还是旧的** → 最多要等一整点
 *
 *  根因不是某个函数写错了，而是**两个文件之间的一条约定破了**：
 *
 *    「凡是会改变前台展示的写入，都必须让页面静态缓存失效。」
 *
 *  这条约定当时**只存在于人的记忆里**，没有任何机制在看着它。
 *  全仓库 `pcClear()` 只有 3 个调用点，而写 sources/groups 的 6 个 CRUD 函数
 *  **一个都没调** —— 于是「改了设置前台没反应」这个 bug 每天都在发生，
 *  而 199 项测试全绿。
 *
 *  再往前看，本项目过去 12 次发布里有 7 次栽在同一类错误上：
 *  `.htaccess` 的注释语法、Apache 的 AllowOverride 类别、getenv 被禁用时的行为、
 *  $argv 只在 CLI 存在、测试判据自身的有效性、配置变更的缓存失效 ——
 *  **全都是「约定」，全都没有守护。**
 *
 *  所以这一类测试要守的不是「代码现在对不对」，
 *  而是「**将来有人改代码时，这条约定不会被悄悄破坏**」。
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  本文件守的契约（第 1 条）
 * ═══════════════════════════════════════════════════════════════════════
 *
 *  任何对 sources / groups 两张表的写入，都必须让页面静态缓存失效。
 *
 *  **判据是「扫出来的」，不是「列出来的」** ——
 *  这一点至关重要。若这里写死「检查 addSource / updateSource / …这 6 个函数」，
 *  那么下次新增第 7 个写函数时它不会被检查，测试照样全绿，
 *  而我们会重新掉进「靠记性」的坑里。
 *  所以下面的实现是：把全仓库里**所有**含 sources/groups 写入的函数
 *  都找出来，逐个要求它自带失效手段。
 *
 * ── 两条设计取舍 ──────────────────────────────────────────────────────
 *
 *  ① **静态判据 + 注入回归，而非在本文件里真跑一遍。**
 *     本条若改成行为测试，就得真的调 CRUD 并观察 c/ 目录 —— 而 c/ 是
 *     **PAGE_CACHE_DIR 常量，指向仓库真实目录**，动它就等于在生产站点上
 *     删掉站长的页面缓存（本项目的 tests/run.php 是公开可访问的，
 *     见 tests/index.html 的存在本身）。
 *     静态判据 + 「注入后必须变红」的注入回归（tests/ci-check.php 第 5 条），
 *     是同一件事的另一半，且没有副作用 —— 这正是本项目已有的分层方式。
 *
 *  ② **用 token_get_all() 而不是正则剥注释。**
 *     tests/test_security.php 的 t_code() 用 `//[^\n]*` 剥行注释，
 *     它会把字符串里的 `https://…` 从 `//` 处截断。本项目自己的代码里
 *     就有 `'https://a.example/api'` 这类字面量，而契约判据**必须**能看到
 *     SQL 字符串（写入语句就写在字符串里）。
 *     token_get_all() 是 PHP 自带的词法分析器，字符串/注释/heredoc 它全认，
 *     零依赖且不会有这类误伤 —— 这也顺带治了「判据本身有效性」的老问题。
 */

require_once __DIR__ . '/bootstrap.php';

$root = dirname(__DIR__);

// ==================================================================
// 契约 1：写 sources / groups 的代码必须让页面静态缓存失效
// ==================================================================

group('契约 1：写 sources/groups 必须让页面静态缓存失效');

/**
 * 写入了 sources / groups 两张表的函数，允许豁免失效。
 *
 * ⚠ **豁免必须写理由，且理由要能被人复核。**
 *   没有这个口子的话，将来遇到「这条写入不影响前台」时，
 *   人的第一反应是去删断言 —— 那比破约更糟。
 *   写在这里，至少要求有人明确地说出「为什么不影响」。
 *
 * 目前为空。将来若有新增项，同步在这里登记**具体理由**。
 */
const CLEAR_INVARIANT_EXEMPT = [
    // 例：'someInternalWarmup' => '理由：只回填接口缓存，不产出任何前台页面',
];

/** 只保留「真正执行的代码」：剥掉全部注释，保留字符串字面量 */
function t_contract_code(string $src): string {
    // ⚠ token_get_all() 而不是正则：字符串里的 'https://' 会被行注释正则截断，
    //   而本判据必须看得到 SQL 字符串（写入语句就住在字符串里）。
    $out = '';
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            if ($tok[0] === T_COMMENT || $tok[0] === T_DOC_COMMENT) {
                continue;   // 注释一律丢掉 —— 否则「注释里写了 pcClear」会被误判为合规
            }
            $out .= $tok[1];
        } else {
            $out .= $tok;
        }
    }
    return $out;
}

/**
 * 取出某个函数的完整函数体（从 `function 名字` 起到配对的右花括号）。
 *
 * 返回 null 表示这个文件里没有同名函数。
 * 括号计数按 token 走，字符串与注释已在 t_contract_code() 里处理干净，
 * 所以函数体里出现 '{' '}' 字面量（比如正则、JSON 模板）也不会算错。
 */
function t_contract_body(string $code, string $fn): ?string {
    // 形如：function  名字  (  ...  )  :  返回类型   {
    if (!preg_match('/\bfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $code, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = $m[0][1];
    $open  = strpos($code, '{', $start);
    if ($open === false) {
        return null;
    }
    $depth = 0;
    $len   = strlen($code);
    for ($i = $open; $i < $len; $i++) {
        if ($code[$i] === '{') {
            $depth++;
        } elseif ($code[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($code, $start, $i - $start + 1);
            }
        }
    }
    return null;   // 括号不配对（源码坏了）——交给调用方判失败
}

/** 该函数体是否含对 sources / groups 的写入 */
function t_contract_writes(string $body): bool {
    return (bool) preg_match(
        '/\b(?:INSERT\s+INTO|UPDATE|DELETE\s+FROM)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`"]?(?:main\.)?(sources|groups)\b/i',
        $body
    );
}

/** 该函数体是否自带失效手段（直接清，或声明成批量的一部分） */
function t_contract_invalidates(string $body): bool {
    // 两种都算：pcClear() 直接清；pcClearBatch() 表示「这段里的清延迟到最外层统一做」，
    // 批量导入必须用它，否则一次导入会触发几十次全量扫描。
    return (bool) preg_match('/\bpcClear(?:Batch)?\s*\(/', $body);
}

/** 需要扫描的业务代码文件（入口页 + includes/） */
function t_contract_files(string $root): array {
    $out = [];
    foreach (glob($root . '/includes/*.php') ?: [] as $f) {
        $out[] = $f;
    }
    foreach (glob($root . '/*.php') ?: [] as $f) {
        $out[] = $f;
    }
    sort($out);
    return $out;
}

// —— 判据自检：解包器本身对不对？——
//
// ⚠ 这不是多余的。本项目 1.3.9 栽过「判据没剥注释 / 判据自欺」，
//   而下面整个契约都建立在 t_contract_body() 上。
//   **一个错了的解包器会让本文件全部断言失去意义，却照样显示全绿。**
//   所以先用构造样例把它钉死。
group('契约 1 · 判据自检（解包器必须先证明自己是对的）');

t('解包器能取出真实函数的完整函数体', static function () use ($root): void {
    $body = t_contract_body(t_contract_code((string) file_get_contents($root . '/includes/db.php')), 'updateSource');
    ok($body !== null, '应能从 db.php 里取出 updateSource()');
    ok(str_contains($body, 'function updateSource'), '函数体应含函数声明本身');
    ok(str_contains($body, 'UPDATE sources'), '函数体应含那条 UPDATE 语句');
    ok(str_contains($body, 'pcClear('), '函数体应含 pcClear()');
});

t('解包器不被嵌套花括号骗（函数体里的 {} 不会提前截断）', static function (): void {
    $sample = <<<'PHP'
<?php
function demoA(): string {
    $x = '{ 这不是函数的结束 }';
    if ($x) {
        return $x;
    }
    return 'end-of-A';
}
function demoB(): string { return 'b'; }
PHP;
    $body = t_contract_body(t_contract_code($sample), 'demoA');
    ok($body !== null, 'demoA 应可提取');
    ok(
        !str_contains($body, 'function demoB'),
        '函数体被提前截断了 —— 说明括号计数在字符串 {} 上算错了'
    );
    ok(str_contains($body, "'end-of-A'"), '应完整取到函数体末尾');
});

t('解包器取不到不存在的函数时返回 null（而不是静默通过）', static function (): void {
    isNull(
        t_contract_body(t_contract_code('<?php function real1(){}'), 'noSuchFunction'),
        '取不到函数必须显式返回 null，否则「找不到」会被当成「合规」'
    );
});

t('判据能认出各种写入句式（漏一种就等于留一个后门）', static function (): void {
    $should = [
        "db()->prepare('INSERT INTO sources (name) VALUES (?)')",
        "db()->prepare('UPDATE sources SET enabled = ? WHERE id = ?')",
        "db()->prepare('DELETE FROM sources WHERE id = ?')",
        "db()->prepare('INSERT INTO groups (name, sort) VALUES (?, ?)')",
        "db()->prepare('UPDATE groups SET name = ? WHERE id = ?')",
        "db()->prepare('DELETE FROM groups WHERE id = ?')",
        "\$pdo->exec('DELETE FROM sources')",
        "\$pdo->exec('DELETE FROM groups')",
        'INSERT INTO `sources` (name) VALUES (?)',   // 带反引号
        'INSERT INTO main.sources (name) VALUES (?)', // 带 schema 前缀
    ];
    foreach ($should as $code) {
        ok(
            t_contract_writes($code),
            "应被识别为写入：" . $code . '（漏判 = 该函数会被免检，正是要防的后门）'
        );
    }
});

t('判据不会把纯读取误判成写入（否则会逼人加无意义的 pcClear）', static function (): void {
    $reads = [
        "db()->query('SELECT * FROM sources')",
        "db()->prepare('SELECT img_proxy FROM sources WHERE id = ?')",
        "db()->prepare('SELECT id FROM sources WHERE api_url = ?')",
        'dbInit($pdo)',
    ];
    foreach ($reads as $code) {
        ok(
            !t_contract_writes($code),
            "不该被识别为写入：" . $code . '（误判 = 契约名存实亡，没人愿意维护）'
        );
    }
});

t('注释里写了 pcClear 不算数（这是本项目栽过两次的坑）', static function (): void {
    // 1.3.9：注释掉 global $_ADMIN_ACTION_MAP 后，回归测试依然全绿 ——
    //   正则没剥注释，匹配到了说明文字。1.3.4 的 SSRF 那次同构。
    $sample = "<?php\nfunction demo(): void {\n    // 这里应该调 pcClear()，但其实没调\n    \$x = 1;\n}";
    ok(
        !t_contract_invalidates(t_contract_body(t_contract_code($sample), 'demo')),
        '注释里提到 pcClear() 不得被判为合规 —— 否则注释就是免死金牌'
    );
});

// —— 本体 ——
group('契约 1 · 本体');

t('全仓库每个写 sources/groups 的函数都自带缓存失效', static function () use ($root): void {
    $bad       = [];
    $writers   = 0;
    $byFile    = [];

    foreach (t_contract_files($root) as $file) {
        $code = t_contract_code((string) file_get_contents($file));
        if (!str_contains($code, 'sources') && !str_contains($code, 'groups')) {
            continue;   // 这个文件不碰这两张表
        }
        // 把该文件里所有函数都取出来逐个判
        preg_match_all('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $code, $m);
        foreach (array_unique($m[1]) as $fn) {
            $body = t_contract_body($code, $fn);
            if ($body === null) {
                continue;   // 抽象方法 / 接口声明之类
            }
            if (!t_contract_writes($body)) {
                continue;
            }
            $writers++;
            $rel = str_replace($root . '/', '', $file);
            $byFile[$rel][] = $fn;
            if (in_array($fn, CLEAR_INVARIANT_EXEMPT, true)) {
                continue;
            }
            if (!t_contract_invalidates($body)) {
                $bad[] = "includes: {$rel} 的 {$fn}()";
            }
        }
    }

    // 自检：下面的断言只有「真的扫出了写入点」时才有意义
    ok(
        $writers >= 8,
        "本次只扫出 {$writers} 个写入函数 —— 判据本身可能失效了（应至少 8 个：db.php 六个 + "
        . 'admin-actions.php 的 toggle_source + functions.php 的 learnImgHost），'
        . '此时本条断言即使通过也没有意义'
    );

    eq(
        [],
        $bad,
        "这些函数改了 sources/groups 却没有 pcClear()：\n  " . implode("\n  ", $bad)
        . "\n\n后果（2026-10-04 实测）：后台提示「✅ 已更新数据源」，前台却照旧渲染"
        . "旧的静态 HTML，最长要等一整点 —— 且 199 项测试全绿，查不出来。\n"
        . '修法：在这类函数末尾加 pcClear();（批量路径请用 pcClearBatch() 包起来）。'
    );
});

t('本契约当场点名了哪些函数（改了 db.php 就要重新看一遍这张表）', static function () use ($root): void {
    // 这条**永远通过**，它的作用是把「契约覆盖了谁」写进测试报告里 ——
    // 报告是站长升级后唯一会看的东西，得让人看见覆盖面，而不是只看见一个 ✓。
    $found = [];
    foreach (t_contract_files($root) as $file) {
        $code = t_contract_code((string) file_get_contents($file));
        preg_match_all('/\bfunction\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $code, $m);
        foreach (array_unique($m[1]) as $fn) {
            $body = t_contract_body($code, $fn);
            if ($body !== null && t_contract_writes($body) && t_contract_invalidates($body)) {
                $found[] = str_replace($root . '/', '', $file) . '::' . $fn;
            }
        }
    }
    ok(
        count($found) >= 8,
        '受本契约保护的写入点：' . implode(', ', $found)
    );
});

t('豁免清单里没有失效的条目（防止预授权后长期免检）', static function (): void {
    $stale = [];
    foreach (array_keys(CLEAR_INVARIANT_EXEMPT) as $fn) {
        $exists = false;
        foreach (t_contract_files($root ?? dirname(__DIR__)) as $file) {
            $code = t_contract_code((string) file_get_contents($file));
            if (t_contract_body($code, $fn) !== null) {
                $exists = true;
                break;
            }
        }
        if (!$exists) {
            $stale[] = $fn;
        }
    }
    eq(
        [],
        $stale,
        '豁免清单里这些函数已经不存在了，请删掉对应条目 —— '
        . '否则将来有人新增同名函数时会直接继承豁免'
    );
});

t('pcClearBatch 的 finally 里真的调了 pcClearNow（批量路径不能只登记不执行）', static function () use ($root): void {
    $body = t_contract_body(t_contract_code((string) file_get_contents($root . '/includes/pagecache.php')), 'pcClearBatch');
    ok($body !== null, '应能找到 pcClearBatch()');
    ok(
        str_contains($body, 'finally'),
        'pcClearBatch() 必须用 finally —— 否则批量路径抛异常时缓存就再也不会被清了，'
        . '而「配置改了前台不更新」正是本契约要防的那个 bug'
    );
    ok(
        preg_match('/finally\s*\{[^}]*pcClearNow\s*\(/s', $body),
        'finally 分支里必须真的调用 pcClearNow()'
    );
});

// ==================================================================
finish();
