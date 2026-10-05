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

// 契约 2 要读 $_ADMIN_ACTION_MAP（定义在 admin-actions.php 的顶层全局作用域）。
// ⚠ 加载它 = 把 client.php / template.php / enrich.php / config-io.php 一并引入，
//   但**不会执行任何 action**（那些只在 adminHandlePost() 里被调用）——
//   所以这里没有出站请求、没有写库，可以在站点上安全跑。
require_once __DIR__ . '/../includes/admin-actions.php';

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

/**
 * 两个文件是否逐字节相同。
 *
 * ⚠ 不用 cmp()：它可能落在免费主机的 disable_functions 里，
 *   而测试工具应该比它测的代码更耐用（本项目栽过 shell_exec / getenv 两次）。
 * 也不用 md5_file()：大文件上白读一遍内存，模板文件虽小但这个习惯不好。
 */
function t_contract_same(string $a, string $b): bool {
    if (!is_file($a) || !is_file($b)) {
        return false;
    }
    $sa = @filesize($a);
    $sb = @filesize($b);
    if ($sa === false || $sb === false || $sa !== $sb) {
        return false;   // 大小不同就一定不同（先便宜后昂贵）
    }
    $ha = @hash_file('sha256', $a);
    $hb = @hash_file('sha256', $b);
    return $ha !== false && $hb !== false && hash_equals($ha, $hb);
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


// ══════════════════════════════════════════════════════════════════════
// 契约 2：新增的 admin action 必须「已登记为会失效」或「显式声明只读」
// ══════════════════════════════════════════════════════════════════════
//
//  契约 1 守住了「写 sources/groups 的函数必须失效」，
//  但它**看不见**「新增了一个 action，写的是别的东西」——
//  比如给后台加一个「一键改全站排序」的按钮，走的是别的表，
//  契约 1 一路绿灯，而前台照样不更新。
//
//  契约 2 换个角度：不是去找写操作，而是要求**每个 action 都必须表态**。
//  新增 action 时只有两种合法结局：
//    ① 它会改变前台 → 必须在失效登记名单里（ADMIN_INVALIDATING_ACTIONS）；
//    ② 它只读不改 → 必须进只读白名单（ADMIN_READONLY_ACTIONS）并写明理由。
//  **「忘了登记」不再是可能的状态** —— 新 action 必然落进其中一张表，
//  因为两张表的并集必须等于映射表的键集。
//
//  这个设计的关键：判据是「**集合相等**」而不是「逐个检查」，
//  所以新增 action 无论写什么，测试都会立刻变红要求表态。

group('契约 2：admin action 必须登记为「会失效」或「只读」');

/** 会改变前台展示的 action（直接写库，且写路径自带失效）。 */
const ADMIN_INVALIDATING_ACTIONS = [
    // —— 数据源 / 分组（契约 1 保证其写入函数自带 pcClear）——
    'add_source', 'update_source', 'delete_source', 'toggle_source',
    'add_group', 'update_group', 'delete_group',
    // 改的是图片域名白名单，裸 SQL，自行作废
    'detect_img_host',
    // —— 走 setSetting()，pcClear() 挂在它上面 ——
    'site_settings', 'site_template', 'edit_template', 'reset_template',
    'access_settings', 'change_admin_password',
    // 手动清理：勾了「页面静态缓存」就自己清了
    'clear_cache',
    // 导入走 pcClearBatch()，退出时统一清
    'import_config',
];

/**
 * 只读 action（不写任何库，所以不必失效）。
 *
 * ⚠ **必须写理由**。留空理由的白名单等于「随便声明一下就能免检」，
 *   那这张表会变成绕过契约的后门 —— 而这正是本契约要防的东西。
 */
const ADMIN_READONLY_ACTIONS = [
    // 探活：出站打一次上游看通不通，不碰任何表
    'test_source' => '只做上游连通性探测（VodClient::probe()），不写任何表',
];

t('映射表里每个 action 都已在「会失效」或「只读」两张表中登记', static function (): void {
    global $_ADMIN_ACTION_MAP;
    // ⚠ 列表式 const 的 array_keys() 返回的是**下标**（0,1,2…）不是值。
    //   这里直接用数组本身比对 —— 上一版写成 array_keys() 时，
    //   16 个 action 全被误判成「未登记」，而 test_source 恰好因为
    //   是关联式（键=action 名）才通过。**两个用例行为不一致本身就是信号。**
    $registered = array_merge(
        array_values(ADMIN_INVALIDATING_ACTIONS),
        array_keys(ADMIN_READONLY_ACTIONS)
    );
    $missing = [];
    foreach (array_keys($_ADMIN_ACTION_MAP) as $action) {
        if (!in_array($action, $registered, true)) {
            $missing[] = $action;
        }
    }
    eq([], $missing,
        "这些 action 既不在 ADMIN_INVALIDATING_ACTIONS，也不在 ADMIN_READONLY_ACTIONS：\n  "
        . implode("\n  ", $missing)
        . '\n\n新增后台 action 时必须表态：会改前台的进前者，只读的进后者（并写明理由）。'
        . '「忘了登记」不该是一个可能的状态 —— 否则契约 1 看不见它。'
    );
});

t('两张表里没有映射表已不存在的 action（防止预授权长期免检）', static function (): void {
    global $_ADMIN_ACTION_MAP;
    $stale = [];
    foreach (array_merge(
        array_values(ADMIN_INVALIDATING_ACTIONS),   // 列表式：取值，不是 array_keys
        array_keys(ADMIN_READONLY_ACTIONS)         // 关联式：键就是 action 名
    ) as $action) {
        if (!isset($_ADMIN_ACTION_MAP[$action])) {
            $stale[] = $action;
        }
    }
    eq([], $stale,
        '这些 action 在 $_ADMIN_ACTION_MAP 里已经不存在了，请删掉登记 —— '
        . '否则将来有人新增同名 action 时会直接继承免检');
});

t('一个 action 不能同时出现在两张表里', static function (): void {
    $both = array_values(array_intersect(
        array_values(ADMIN_INVALIDATING_ACTIONS),
        array_keys(ADMIN_READONLY_ACTIONS)
    ));
    eq([], $both,
        '同一个 action 既是「会失效」又是「只读」 —— 说明登记时没想清楚。'
        . '只读必须真的只读：处理函数里出现 setSetting / pcClear / 任何 CRUD 调用就是违规。');
});

t('只读 action 的处理函数确实不写任何库（白名单不是免死金牌）', static function (): void {
    // 这一条堵住「先声明只读、后来加了写入」的漏洞 ——
    // 那样声明会静默失效，而白名单仍然让它免检。
    $writers = [
        'setSetting', 'pcClear', 'addSource', 'updateSource', 'deleteSource',
        'addGroup', 'updateGroup', 'deleteGroup', 'learnImgHost', 'configImport',
    ];
    $dirty = [];
    foreach (array_keys(ADMIN_READONLY_ACTIONS) as $action) {
        $fn = 'adminAction' . str_replace(' ', '', ucwords(str_replace('_', ' ', $action)));
        $body = t_contract_body(
            t_contract_code((string) file_get_contents(dirname(__DIR__) . '/includes/admin-actions.php')),
            $fn
        );
        if ($body === null) {
            $dirty[] = "{$action}：找不到 {$fn}()";
            continue;
        }
        foreach ($writers as $w) {
            if (preg_match('/' . $w . '\s*\(/', $body)) {
                $dirty[] = "{$action}：{$fn}() 里出现了 {$w}()，它已经不是只读了";
            }
        }
    }
    eq([], $dirty,
        "只读白名单里的 action 其实会写库：\n  " . implode("\n  ", $dirty)
        . '\n\n要么改回只读，要么移进 ADMIN_INVALIDATING_ACTIONS —— '
        . '不能因为「当初声明过只读」就永远免检。'
    );
});

t('只读白名单每条都写明了理由（防止「声明一下」变成后门）', static function (): void {
    $noReason = [];
    foreach (ADMIN_READONLY_ACTIONS as $action => $why) {
        if (trim((string) $why) === '') {
            $noReason[] = $action;
        }
    }
    eq([], $noReason,
        '这些只读 action 没写理由。白名单必须说明「为什么不影响前台」，'
        . '否则将来没人敢删它 —— 一张没人敢动的表就等于没有契约。');
});

t('会失效名单里的 action，处理函数确实能到达失效路径', static function () use ($root): void {
    // 静态可达性：处理函数里必须直接调用到「自带失效」的写函数之一
    //（不递归追一层，因为 pcClearBatch → addSource → pcClear 是三跳，
    //  那样跟就等于把整个调用图重建一遍，判据会脆得没法维护）。
    $reaching = [
        'setSetting',        // 末尾挂 pcClear()
        'pcClear',           // 直接清
        'addSource', 'updateSource', 'deleteSource',
        'addGroup', 'updateGroup', 'deleteGroup',
        'learnImgHost',      // 裸 SQL + 自行 pcClear
        'configImport',      // pcClearBatch 包裹
        'clearSystemCache',  // 勾了页面缓存就自己清
    ];
    $unreached = [];
    $code = t_contract_code((string) file_get_contents($root . '/includes/admin-actions.php'));
    foreach (ADMIN_INVALIDATING_ACTIONS as $action) {
        $fn = 'adminAction' . str_replace(' ', '', ucwords(str_replace('_', ' ', $action)));
        $body = t_contract_body($code, $fn);
        if ($body === null) {
            $unreached[] = "{$action}：找不到 {$fn}()";
            continue;
        }
        $hit = false;
        foreach ($reaching as $r) {
            if (preg_match('/\b' . $r . '\s*\(/', $body)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            $unreached[] = "{$action}：{$fn}() 里找不到任何失效路径";
        }
    }
    eq([], $unreached,
        "登记为「会失效」但处理函数到不了失效路径：\n  " . implode("\n  ", $unreached)
        . '\n\n它要么其实不失效（那就该进只读表），要么走了一条没有接上的写入路径。'
    );
});

// ══════════════════════════════════════════════════════════════════════
// 契约 3：guardCaps() 里定义的每个上限，都必须有地方真的用它
// ══════════════════════════════════════════════════════════════════════
//
//  **这一条当场抓出了两个真实缺陷**，是本契约存在的理由：
//
//    ① `enrich_days`（紧凑 15 天 / 标准 30 天）**只有赋值、没有任何读取点**。
//       结果是 `enrich` 表**完全没有自动清理** ——
//       唯一的 DELETE 在 enrich.php，只能由后台手动触发。
//       按 100 源 × 每天 200 部新片 × 30 天 = 60 万行 ≈ 300 MB，
//       在 1 GB 盘上占三成，而文档承诺的总预算是 132 MB。
//
//    ② `small` 键同样只写不读（它与 `base` 是同一个信息的两个出口，
//       `base` 有读点所以没人发现 `small` 是死的）。
//
//  这类缺陷的共同形状：**「定义了上限但忘了用」**。
//  它不会让任何测试变红，因为**没有任何函数的行为依赖它** ——
//  这正是它能活这么久的原因，也是契约测试唯一能覆盖的那一类。
//
//  修法（本版一并修掉，否则本契约上线即红）：
//    ① 给 enrich 表加 GC（按天 + 按行数双闸）与 created_at 索引（schema v6）；
//    ② 删掉死的 `small` 键，或让它被真的读取。

group('契约 3：定义了的容量上限必须真的被用上');

t('guardCaps() 里每个键都有至少一个读取点', static function () use ($root): void {
    // 只扫「除 guardCaps() 自己以外」的代码 ——
    // 定义处当然算读点，不排除的话这条契约永远是绿的。
    $src   = t_contract_code((string) file_get_contents($root . '/includes/guard.php'));
    $body  = t_contract_body($src, 'guardCaps');
    ok($body !== null, '应能找到 guardCaps()');

    // ---- 抽出它定义的全部键 ----
    //
    // ⚠ 只认两种形态，且**必须排除档位名**：
    //   ① 字面量数组里的键：'cache_files' => 600,
    //   ② 后面对 $caps[...] 的赋值：$caps['log_bytes'] = ...
    //   上一版把 `=> \d+` 写得太宽，把 $factor 那张档位表里的
    //   normal/tight/compact/protect 也当成键了 —— 它们不是上限，是水位档名。
    //
    $bodyStr = (string) $body;
    preg_match_all("/'([a-z_]+)'\s*=>\s*-?\d+(?![.\w])/", $bodyStr, $m1);
    preg_match_all('/\$caps\[\s*[\'"]([a-z_]+)[\'"]\s*\]\s*=/i', $bodyStr, $m2);
    $keys = array_values(array_unique(array_merge($m1[1], $m2[1])));
    sort($keys);
    ok(
        count($keys) >= 15,
        '解析出 ' . count($keys) . ' 个键（应至少 15 个），判据本身可能失效'
    );
    // 档位名不该出现在键集合里 —— 它们是 $factor 表的值，不是 caps 的键
    foreach (['normal', 'tight', 'compact', 'protect'] as $tierName) {
        ok(
            !in_array($tierName, $keys, true),
            "把水位档名「{$tierName}」误认成了容量上限 —— 判据把 $factor 表也扫进来了"
        );
    }

    // ---- 全仓库其它代码（排除 guardCaps() 自身的函数体）----
    $others = '';
    foreach (t_contract_files($root) as $file) {
        if (basename($file) === 'guard.php') {
            $others .= str_replace($bodyStr, '', $src);
            continue;
        }
        $others .= t_contract_code((string) file_get_contents($file));
    }

    // ---- 逐键找读取点 ----
    //
    // 读取形态只有一种：$caps['k'] 或 $lp['caps']['k']（后台把它传进模板变量 $lp）。
    // 两者都是「下标取值」，所以判据就是**有没有出现这个下标**。
    // ⚠ 不能用「字符串 k 在文件里出现过」来判 —— 注释与文档里提到键名不算读取。
    $dead = [];
    $live = [];
    foreach ($keys as $k) {
        $q     = preg_quote($k, '/');
        $found = preg_match_all(
            "/\[['\"]" . $q . "['\"]\]/",
            $others
        );
        if ($found > 0) {
            $live[] = $k;
        } else {
            $dead[] = $k;
        }
    }

    eq([], $dead,
        "guardCaps() 定义了这些上限，但全仓库**没有任何地方读取它们**：\n  " . implode("\n  ", $dead)
        . "\n\n已确认有读取点的：" . implode('、', $live)
        . "\n\n一个只写不读的上限等于没有上限 —— 上层以为有护栏，实际没有。"
        . "\n这正是 enrich 表能长到 300 MB 的原因（enrich_days 从未被读取，"
        . "\nenrich 表因此一直没有自动清理）。\n"
        . "处理方式：要么给它接上真实的读取点，要么把这个键删掉（别留一个假护栏）。"
    );
});

t('enrich 表有自动清理（enrich_days 必须真的被用上）', static function () use ($root): void {
    $gc = t_contract_code((string) file_get_contents($root . '/includes/guard.php'));
    ok(
        preg_match('/\[[\'"]enrich_days[\'"]\]/', $gc)
            && preg_match('/function\s+guardGcEnrich\b/', $gc),
        'enrich_days 必须被 guardGcAll() 的某一步真的用上 —— '
        . '1.3.11 之前它只有赋值没有读取点，enrich 表因此完全无自动清理，'
        . '正常用能涨到约 300 MB（1 GB 盘的 30%）'
    );
    // GC 还得真的挂在 guardGcAll() 的步骤表里，否则定义了函数也等于没跑
    $all = t_contract_body($gc, 'guardGcAll');
    ok($all !== null, '应能找到 guardGcAll()');
    ok(
        str_contains((string) $all, 'guardGcEnrich('),
        'guardGcEnrich() 必须出现在 guardGcAll() 的步骤表里 —— '
        . '定义了却不调用，等于没有这条清理规则'
    );
});

t('enrich 表按 created_at 建了索引（否则按时间删除是全表扫描）', static function () use ($root): void {
    $db = t_contract_code((string) file_get_contents($root . '/includes/db.php'));
    ok(
        preg_match('/idx_enrich_created/', $db),
        'enrich 表需要 CREATE INDEX idx_enrich_created ON enrich(created_at) —— '
        . '读取路径（点查 + IN）靠主键已经够了，但按时间删除没有索引就是全表扫描。'
        . '这个索引随 DB_SCHEMA_VERSION 6 的迁移一起建。'
    );
});

// ══════════════════════════════════════════════════════════════════════
// 契约 4：文档里的承诺必须能在代码里找到对应的东西
// ══════════════════════════════════════════════════════════════════════
//
//  这一条防的是 1.3.10 复查里最刺眼的一处：
//  `docs/lowpower.md` 写着「（正常情况下任意配置写入都会自动全量作废）」——
//  而「改数据源」那一半从来不会。**这句话是错的，且它恰好是站长唯一会去查的地方。**
//
//  难处在于「数字」不是一概都能对上：
//    · 文档写「约 132 MB」，代码里是 4 个分项相加 —— 要人算；
//    · 文档写「约 600 张」，是按平均图片大小估的 —— 代码里根本没有这个数；
//    · 文档写「≥ 5 GB」，代码是 guardIsSmall() 里的 1.5 GB 阈值。
//  所以本契约不试图「核对每个数字」，而是抓**一个更窄但更致命**的子类：
//  **文档承诺「会自动做某事」，代码里必须真有那条自动路径。**
//
//  这一类是可以判死的：文档句子里出现「自动 / 无需配置 / 不用手动」这类词时，
//  它断言的必然是代码里某条自动机制存在。这类断言错了 = 用户照文档行事却等不到结果。

group('契约 4：文档的「自动生效」类承诺必须有真实代码路径');

/** 文档承诺 → 代码里必须存在的标记（判据刻意做窄，只抓「会自动做某事」这一类） */
const DOC_AUTO_CLAIMS = [
    // 承诺                          文档位置          代码里必须有的标记
    ['任意配置写入自动全量作废',     'docs/lowpower.md', 'pcClear('],
    ['访问密码开启自动停用静态化',   'docs/lowpower.md', 'pcSyncGate('],
    ['磁盘水位自动收紧各目录上限',   'docs/lowpower.md', 'guardGcAll('],
    ['切换版本自动作废旧静态页',     'docs/lowpower.md', 'pcVersionGate('],
    ['数据源分组变更自动作废缓存',   'docs/lowpower.md', 'pcClearBatch('],
];

t('文档里「自动生效」的每条承诺，代码里都有对应实现', static function () use ($root): void {
    $code = '';
    foreach (t_contract_files($root) as $file) {
        $code .= t_contract_code((string) file_get_contents($file));
    }
    $unbacked = [];
    foreach (DOC_AUTO_CLAIMS as [$claim, $doc, $marker]) {
        if (!str_contains($code, $marker)) {
            $unbacked[] = "{$claim}（{$doc} 声称自动，但代码里找不到 {$marker}）";
        }
    }
    eq([], $unbacked,
        "文档声称会自动生效，但代码里没有对应实现：\n  " . implode("\n  ", $unbacked)
        . "\n\n这种错比 bug 更贵 —— 用户会照着文档行事，然后等一个永远不会发生的结果。"
    );
});

t('lowpower.md 的缓存上限说法与代码一致（不再是「一个数」而是「每桶乘桶数」）', static function () use ($root): void {
    // 1.3.10 查出：guardGcPageBuckets() 里那句 guardGcFiles($dir, PHP_INT_MAX, $maxBytes)
    // 是死代码 —— c/ 底下全是桶目录，没有一个条目能通过 is_file()，
    // 于是 $total 恒为 0，判定「未超限」直接返回，**它从未生效过**。
    // 实际上限是「桶数 × 每桶上限」，与文档承诺的单一 15 MB 对不上。
    $doc = t_contract_code((string) file_get_contents($root . '/docs/lowpower.md'));
    ok(
        str_contains($doc, '每桶') || str_contains($doc, '桶数'),
        'docs/lowpower.md 必须说明页面缓存的上限是「每桶 / 乘以桶数」，'
        . '而不是单一的那个总字节数 —— 后者与实际行为对不上'
    );

    $gc   = t_contract_code((string) file_get_contents($root . '/includes/guard.php'));
    $body = t_contract_body($gc, 'guardGcPageBuckets');
    ok($body !== null, '应能找到 guardGcPageBuckets()');
    ok(
        !preg_match('/guardGcFiles\(\$dir\s*,\s*PHP_INT_MAX/', (string) $body),
        'guardGcPageBuckets() 里那句对 c/ 顶层调 guardGcFiles() 的是死代码'
        . '（c/ 下全是目录，is_file() 一条都过不了，$total 恒为 0）—— 请删掉，'
        . '别让下一个人以为它在工作'
    );
});

// ══════════════════════════════════════════════════════════════════════
// 契约 5：模板去重的结构约束（1.3.12 删掉 31 个重复文件之后立的）
// ══════════════════════════════════════════════════════════════════════
//
//  1.3.12 删掉了各模板里与 default **逐字节相同**的页面文件与 partials，
//  缺失时由 renderTemplate() / tplPartial() / tplInclude() 回退到 default。
//
//  但这个回退是**三处不同机制**拼出来的，任何一处漏掉都表现为
//  「某个模板的某个页面 500 或内容悄悄变空」，而不是「编译期报错」：
//    ① renderTemplate()  回退**入口**页面文件
//    ② tplPartial()      回退 partials/*.php
//    ③ tplInclude()      回退页面**内部** require 的 header/footer/player_script
//
//  ③ 是 1.3.12 才有的 —— 因为页面文件内部原本写的是
//  `require_once __DIR__ . '/footer.php'`，`__DIR__` 是硬路径、**零回退**，
//  footer.php 被删后 bilibili 的 list.php 直接整页 500。
//  这个坑实测踩过，注释写在 includes/template.php 的 tplInclude() 里。
//
//  所以契约 5 盯三件事：必需文件还在 / 没有重新长出重复 / tplInclude 的判据没退化。

group('契约 5：模板去重的结构约束');

/** 每套模板必须有、且**不允许**被删的文件 */
const TPL_MUST_HAVE = ['theme.json', 'header.php'];

/** 允许各模板与 default 不同、因而必须自带副本的文件 */
const TPL_MAY_DIFFER = ['bilibili' => ['list.php', 'partials/vod_grid.php']];

t('每套模板仍带着 theme.json 与 header.php（缺任何一个模板就废了）', static function () use ($root): void {
    $bad = [];
    foreach (array_keys(listTemplates()) as $tpl) {
        foreach (TPL_MUST_HAVE as $f) {
            if (!is_file(tplRoot() . '/' . $tpl . '/' . $f)) {
                $bad[] = "{$tpl}/{$f}";
            }
        }
    }
    eq([], $bad,
        "这些模板缺少必需文件：\n  " . implode("\n  ", $bad)
        . "\n\ntheme.json 现在是「这个模板存在」的标记（tplExists() 判据已改成查它）——"
        . "\nheader.php 则是每套主题的配色与搜索框差异所在。"
        . "\n\n⚠ 特别注意 theme.json：**删了它，整套模板会静默失效且不报错**"
        . "（resolveTemplate() 回退 default、模板编辑器拒绝保存）。"
    );
});

t('没有模板重新长出与 default 逐字节相同的文件（那正是要消灭的重复）', static function () use ($root): void {
    // 反向检查：CI 也有这一步，但契约测试在 Web 上也能跑 —— 站长自查用得上
    $dup = [];
    foreach (array_keys(listTemplates()) as $tpl) {
        if ($tpl === 'default') {
            continue;
        }
        foreach (glob(tplRoot() . '/' . $tpl . '/*.php') ?: [] as $f) {
            $rel = basename($f);
            if ($rel === 'header.php' || $rel === 'theme.json') {
                continue;   // 这两个本就允许不同
            }
            if (!isset(TPL_MAY_DIFFER[$tpl]) || !in_array($rel, TPL_MAY_DIFFER[$tpl], true)) {
                if (is_file(tplRoot() . '/default/' . $rel) && t_contract_same($f, tplRoot() . '/default/' . $rel)) {
                    $dup[] = "{$tpl}/{$rel}";
                }
            }
        }
        foreach (glob(tplRoot() . '/' . $tpl . '/partials/*.php') ?: [] as $f) {
            $rel = 'partials/' . basename($f);
            if (!isset(TPL_MAY_DIFFER[$tpl]) || !in_array($rel, TPL_MAY_DIFFER[$tpl], true)) {
                if (is_file(tplRoot() . '/default/' . $rel) && t_contract_same($f, tplRoot() . '/default/' . $rel)) {
                    $dup[] = "{$tpl}/{$rel}";
                }
            }
        }
    }
    eq([], $dup,
        "这些文件与 default 逐字节相同，应删掉并回退 default：\n  " . implode("\n  ", $dup)
        . "\n\n留着它们 = 把「改一个 bug 要改 5 处」这个坑又挖回来。"
        . "\n确实需要不同内容的，请登记进 TPL_MAY_DIFFER 并写明原因。");
});

t('t_contract_same() 真能分辨内容差异（上面的判据本身要可信）', static function (): void {
    // 反证：造几个内容不同的临时文件，确认判据分辨得出来。
    // 不然「没有重复」这个结论可能只是判据恒返回 true。
    // ⚠ 刻意**不用 cmp()**：它属于可能在 disable_functions 里的函数，
    //   本项目的测试工具应该比它测试的代码更耐用（同 shell_exec / getenv 的教训）。
    $a = tempnam(sys_get_temp_dir(), 'vh');
    $b = tempnam(sys_get_temp_dir(), 'vh');
    file_put_contents($a, 'x');
    file_put_contents($b, 'y');
    ok(t_contract_same($a, $b) === false, '内容不同时必须判为「不同」—— 否则「无重复」是假结论');
    file_put_contents($b, 'x');
    ok(t_contract_same($a, $b) === true, '内容相同时必须判为「相同」');
    ok(t_contract_same($a, $a) === true, '同一文件与自己必然相同');
    @unlink($a);
    @unlink($b);
});

t('tplExists() 认的是 theme.json，不是 index.php（改回去模板会静默全废）', static function (): void {
    // 这条锁的是 1.3.12 最危险的一处改动：
    // 去重删掉了非 default 模板的 index.php，而 tplExists() 原来只认 index.php。
    // 若有人「顺手改回去」，resolveTemplate() 会一路回退 default、
    // 模板编辑器直接拒绝保存 —— **整个非 default 模板静默失效，零报错**。
    ok(tplExists('default'), 'default 模板应存在');
    ok(tplExists('bilibili'), 'bilibili 模板应存在（它已没有 index.php 了）');
    ok(tplExists('netflix'), 'netflix 模板应存在');
    ok(!tplExists('no-such-template'), '不存在的模板必须返回 false');
    ok(!tplExists('../etc'), 'tplExists 必须挡住路径穿越');
});

t('模板页面内部不再用 __DIR__ 硬路径 require 同级文件（那个零回退）', static function () use ($root): void {
    // __DIR__ . '/footer.php' 在 footer.php 被删后是整页 500，
    // 而且**只有 bilibili/list.php 会触发**（其余模板的该文件已删）——
    // 也就是说：一旦有人把 bilibili/list.php 复制回 5 份，就静默恢复了这个雷。
    $bad = [];
    foreach (glob(tplRoot() . '/*/*.php') ?: [] as $f) {
        $src = t_contract_code((string) file_get_contents($f));
        if (preg_match('/require(?:_once)?\s+__DIR__/', $src)) {
            $bad[] = basename(dirname($f)) . '/' . basename($f);
        }
    }
    eq([], $bad,
        "这些模板还在用 __DIR__ 硬路径引用同级文件：\n  " . implode("\n  ", $bad)
        . "\n\n请改用 tplInclude('footer.php', $tplName) —— 它有 default 回退，"
        . "\n且 require 留在页面文件里（作用域与去重前完全一致）。"
        . "\n⚠ 这条坑实测踩过：footer.php 被删后 bilibili 的 list.php 直接 500。");
});

t('非 default 模板的页面文件确实都已删除（说明回退在真的被使用）', static function () use ($root): void {
    // 反向断言：如果哪天又长回来了，本条会提醒你把 TPL_MAY_DIFFER 之外的清掉。
    // 它不是「必须删」，而是「别悄悄长回来」—— 长回来不会立刻出错，
    // 但会让「改 5 处」的问题重新长回来，而那正是本版要消灭的。
    $kept = [];
    foreach (array_keys(listTemplates()) as $tpl) {
        if ($tpl === 'default') {
            continue;
        }
        foreach (['index.php', 'play.php', 'search.php', 'history.php',
                  'login.php', 'footer.php', 'player_script.php'] as $rel) {
            $mayDiffer = isset(TPL_MAY_DIFFER[$tpl]) && in_array($rel, TPL_MAY_DIFFER[$tpl], true);
            if (!$mayDiffer && is_file(tplRoot() . '/' . $tpl . '/' . $rel)) {
                $kept[] = "{$tpl}/{$rel}";
            }
        }
    }
    eq([], $kept,
        "这些页面又出现在非 default 模板里了：\n  " . implode("\n  ", $kept)
        . "\n\n如果它们与 default 完全相同，应该删掉（回退已就位）；"
        . "\n如果确实需要不同内容，请登记进 TPL_MAY_DIFFER 并写明原因。");
});

t('tplInclude() 在两个模板都缺文件时返回空壳而不是 false', static function () use ($root): void {
    // require 一个不存在的路径 = 致命错误 = 整页 500。
    // tplInclude() 的兜底必须是「一个一定存在的文件」。
    $p = tplInclude('definitely-not-here.php', 'netflix');
    ok(is_string($p) && $p !== '', '应返回一个路径字符串');
    ok(is_file($p), '返回的路径必须真实存在 —— 否则 require 它就是致命错误');
    // 恶意文件名也必须落到空壳
    ok(is_file(tplInclude('../../config.php', 'netflix')), '路径穿越尝试必须落到空壳');
});

// ══════════════════════════════════════════════════════════════════════
// 契约 6：页面静态缓存的「总量闸」必须真的接上电，且绝不删当前小时的桶
// ══════════════════════════════════════════════════════════════════════
//
//  这条锁的是 1.3.11 → 1.3.13 那一波反复里最微妙的一段：
//
//    1.3.10 发现 `guardGcFiles($dir, PHP_INT_MAX, $maxBytes)` 是死代码
//         （c/ 下全是桶目录，is_file() 一条都过不了，判定恒为「未超限」）。
//    1.3.11 删掉了它，并把文档改成「每桶上限 × 桶数」——
//         **那是把问题写进了文档，不是解决问题**：
//         文档承诺的总预算里页面缓存只占 15 MB（紧凑档），
//         而代码允许到 15 × 桶数，多出来的 15 MB 就名正言顺地花掉了。
//    1.3.13 真正做成总量闸（page_total_bytes），并加这条契约钉住它。
//
//  两条安全性质，缺任何一条都是事故：
//    ① 总量超限时**必须真的会删**（否则又变回一个假护栏）；
//    ② **绝不删本小时的桶** —— 里面的页面正在被 .htaccess 直出，
//       删了等于把访客正在看的页面变成回源渲染。

group('契约 6：页面缓存总量闸（假护栏的对照组）');

t('page_total_bytes 被 guardGcAll() 真的传下去了', static function () use ($root): void {
    $gc = t_contract_code((string) file_get_contents($root . '/includes/guard.php'));
    ok(
        preg_match('/\$caps\[\s*[\'"]page_total_bytes[\'"]\s*\]/', $gc),
        'page_total_bytes 必须被 guardGcAll() 读到并传给 guardGcPageBuckets() —— '
        . '定义了就没人用的话，它就是 1.3.10 那个 enrich_days 的翻版'
    );
});

t('总量闸不是又一段死代码（c/ 顶层全是目录，不能再对顶层调 guardGcFiles）', static function () use ($root): void {
    $gc   = t_contract_code((string) file_get_contents($root . '/includes/guard.php'));
    $body = t_contract_body($gc, 'guardGcPageBuckets');
    ok($body !== null, '应能找到 guardGcPageBuckets()');
    ok(
        !preg_match('/guardGcFiles\(\$dir\s*,\s*PHP_INT_MAX/', (string) $body),
        'guardGcPageBuckets() 里不得对 c/ 顶层再调 guardGcFiles() —— '
        . 'c/ 下全是时间桶目录，没有一条能通过 is_file()，$total 恒为 0，'
        . '这段代码**从来不是活的**（1.3.10 的原缺陷）。总量闸请按「桶整体回收」实现。'
    );
    ok(
        str_contains((string) $body, 'guardRmDir('),
        '总量闸必须按**桶整体回收**（guardRmDir）—— 桶是天然的整体单位，'
        . '逐文件 unlink 不会减少目录项、也不真正回收 inode'
    );
});

t('总量闸绝不删本小时的桶（那些页面正被 .htaccess 直出）', static function () use ($root): void {
    $gc   = t_contract_code((string) file_get_contents($root . '/includes/guard.php'));
    $body = t_contract_body($gc, 'guardGcPageBuckets');
    ok($body !== null, '应能找到 guardGcPageBuckets()');
    ok(
        preg_match('/\$thisHour\s*=/', (string) $body) && str_contains((string) $body, '$thisHour'),
        '总量闸必须显式排除本小时桶 —— 删掉它等于把访客正在看的页面变成回源渲染，'
        . '那是 502 级事故而不是性能问题'
    );
    // 本小时桶必须出现在「跳过」分支里，而不是只被赋值一次就没人用
    ok(
        preg_match('/basename\(\$bucket\)\s*===\s*\$thisHour/', (string) $body),
        '应能看到「桶名 === 本小时 → continue」这个跳过条件'
    );
});

t('page_total_bytes 没有被缩放两次（同一把尺子不能量两次）', static function () use ($root): void {
    // ⚠ 这是本条契约在实现时真踩到的 bug：
    //   写成 $caps['page_total_bytes'] = ceil($caps['page_bytes'] * $factor)
    //   而上面几行已经把 page_bytes 缩放过一次 —— 于是 compact 档实测变成
    //   7.5 MB → 3.8 MB，只有预期的 1/4，护栏形同虚设。
    $gc   = t_contract_code((string) file_get_contents($root . '/includes/guard.php'));
    $body = t_contract_body($gc, 'guardCaps');
    ok($body !== null, '应能找到 guardCaps()');
    ok(
        !preg_match('/page_total_bytes\]\s*=\s*[^;]*\*\s*\$factor/', (string) $body),
        'page_total_bytes 直接沿用已缩放好的 page_bytes 即可 —— '
        . 'page_bytes 在上面已经被 $factor 缩放过，再乘一次就是缩放两次，'
        . '实测紧凑档会只剩预期的 1/4'
    );
    ok(
        preg_match('/page_total_bytes\'\]\s*=\s*\$caps\[\s*[\'"]page_bytes[\'"]\s*\]/', (string) $body),
        '应直接赋值为 $caps[\'page_bytes\']（已缩放好的值）'
    );
});

// ══════════════════════════════════════════════════════════════════════
// 契约 7：版本号只有一个来源，文档里不许出现硬编码的旧版本
// ══════════════════════════════════════════════════════════════════════
//
//  1.3.13 之前项目里有**三个**版本号，其中两个各写各的：
//    · config.php  APP_VERSION     ✅ 基准
//    · img.php     IMG_PROXY_VER   ❌ 写死 '1.2.0'，落后 10 个版本
//    · CHANGELOG.md 顶部条目        ❌ 1.3.4 ~ 1.3.10 全挂在「## [1.3.4]」底下
//
//  而 IMG_PROXY_VER 是**部署确认标记** —— 注释明写「curl -I 看这个头，
//  就能确认你上传的文件真的生效了」。它停在 1.2.0 意味着：
//  站长 curl 一下，无论传的是哪一版，看到的都是 `X-Img-Proxy: 1.2.0`。
//  **这个头等于没有信息量**，而它恰恰是排查「传了没生效」的第一手线索。
//
//  本项目过去 12 次里 8 次的根因是「两处各写各的，忘了同步」。
//  所以契约 7 做两件事：把第二个来源消掉，并让文档里的版本号跟着走。

group('契约 7：版本号只有一个来源');

t('IMG_PROXY_VER 跟随 APP_VERSION，不自己写一个版本号', static function () use ($root): void {
    $src = t_contract_code((string) file_get_contents($root . '/img.php'));
    ok(
        preg_match("/define\(\s*'IMG_PROXY_VER'\s*,\s*APP_VERSION\s*\)/", $src),
        "IMG_PROXY_VER 必须直接取 APP_VERSION —— 它是部署确认标记"
        . '（curl -I 看 X-Img-Proxy 确认新文件生效了），'
        . '写死一个版本号会让这个标记**永远显示旧值**，等于没有信息量'
    );
    ok(
        !preg_match("/define\(\s*'IMG_PROXY_VER'\s*,\s*'[\d.]+'/", $src),
        "IMG_PROXY_VER 不许再写字面量版本号 —— 1.3.13 之前它写死 '1.2.0'，"
        . '而真实版本早已到 1.3.10，站长 curl 看到的永远是 1.2.0'
    );
});

t('img.php 在 config.php 是旧版时仍给出可诊断的头（不能是空的）', static function () use ($root): void {
    $src = t_contract_code((string) file_get_contents($root . '/img.php'));
    ok(
        str_contains($src, "defined('APP_VERSION')"),
        "APP_VERSION 未定义时（config.php 漏传）必须给个兜底值 —— "
        . '这个头是诊断信息，空掉就没有诊断价值了'
    );
});

t('CHANGELOG 里 1.3.5 之后的每个版本都有独立的二级标题', static function () use ($root): void {
    $md = (string) file_get_contents($root . '/CHANGELOG.md');
    // Keep a Changelog 的自动链接（[1.3.4] ↔ 1.3.10）依赖二级标题；
    // 挂成 ### 子节的话，**按版本号搜更新日志的人根本搜不到**——
    // 而「查 1.3.9 改了什么」是站长升级后的第一动作。
    preg_match_all('/^## \[([\d.]+)\]/m', $md, $m);
    $tops = $m[1];

    // ⚠⚠ **判据必须从「代码里的版本号」推要检查哪些版本，不能写死清单。**
    //   写死 ['1.3.5','1.3.10'] 的话，下次发 1.4.0 时它压根不在检查范围里 ——
    //   而那正是「新版本又被挂成 ### 子节」第一次发生的时候。
    //   （与契约 1 的「扫出来而不是列出来」同一个道理。）
    preg_match("/APP_VERSION'\s*,\s*'([^']+)'/", (string) file_get_contents($root . '/config.php'), $v);
    $cur = $v[1] ?? '';
    ok($cur !== '', '应能从 config.php 读出 APP_VERSION');

    // ---- 要检查哪些版本：从当前版本往下，逐个「次版本号」都算 ----
    //
    // ⚠⚠ **不要按固定前缀（1.3.x）枚举** —— 1.4.0 发布那天就踩了：
    //   旧判据写的是「若当前是 1.3 线就检查 1.3.0~1.3.N，
    //   否则只检查当前版本」。于是版本号一升到 1.4.0，
    //   **1.3.0 ~ 1.3.14 全部跳出了检查范围**，
    //   而「把 1.3.9 降级成子节」这条注入从此不再变红（注入汇总会报 11/12）。
    //
    //   这与契约 1「扫出来而不是列出来」是同一个错误的另一个方向：
    //   判据的范围写死在一个会变的常量上，版本一变它就悄悄失效。
    //
    // ---- 检查范围从哪来？（这一段改过两次，都是判据范围自身失效）----
    //
    // ① 最初写死「1.3.0~1.3.N」→ 升到 1.4.0 后整条 1.3 线跳出检查。
    // ② 改成「凡 CHANGELOG 里以二级标题出现过的版本」→ **循环论证**：
    //    把 1.3.9 降级成子节，它同时从范围里消失，于是判据永远发现不了。
    //    （注入回归立刻报「11/12」—— 注入汇总比判据更早发现问题。）
    //
    // 现在的做法：**范围 = 文件里出现过的所有版本号**（## 与 ### 都算）∪ 当前版本。
    // 这样「降级」会改变层级但改不掉版本号本身 ——
    // 范围由文件内容决定，且不会因为检查对象的变化而收缩。
    preg_match_all('/^#{2,3} \[?([\d]+\.[\d]+\.[\d]+)\]?/m', $md, $anyLevel);
    $expect = array_values(array_unique(array_merge($anyLevel[1], [$cur])));
    // ⚠ 允许「跳过」的版本：发过包又收回的。
    //   1.3.13 的包发出后被实测发现一处误报，修好后作为 1.3.14 发布 ——
    //   那种情况下 CHANGELOG 里**不该**有 1.3.13 段落（它没作为正式版存在过）。
    //   写死跳过名单和写死检查名单一样会漂移，所以**从 CHANGELOG 自己读**：
    //   哪一版正文里写了「本版包含原本标记为 X 的全部内容」，X 就是被折叠的那一版。
    $folded = [];
    if (preg_match('/本版包含原本标记为 ([\d.]+) 的全部内容/u', $md, $fm)) {
        $folded[] = $fm[1];
    }

    $missing = array_values(array_filter(
        $expect,
        static fn ($v) => !in_array($v, $tops, true) && !in_array($v, $folded, true)
    ));
    eq([], $missing,
        '这些版本在 CHANGELOG 里没有独立的「## [x.y.z]」二级标题：'
        . implode('、', $missing)
        . "\n（检查范围由 CHANGELOG 里已出现过的版本决定，不会因版本号升级而缩小）"
        . (count($folded) ? "\n已声明折叠：" . implode('、', $folded) : '')
        . "\n\n挂成 ### 子节的后果：Keep a Changelog 的自动链接全断，"
        . "\n而且**按版本号搜更新日志的人搜不到** ——"
        . "\n而「查 1.3.9 改了什么」正是站长升级后的第一动作。" );
    // 版本号不应重复
    $dup = array_keys(array_filter(array_count_values($tops), static fn ($n) => $n > 1));
    eq([], $dup, 'CHANGELOG 里有重复的版本标题：' . implode('、', $dup));
});

/**
 * 这个路径是否属于「运行期产物 / 开发文件」，即不该计入部署载荷。
 *
 * ⚠⚠ **必须按路径前缀判，不能只判第一段** —— 本项目在线上踩过：
 *   `in_array(explode('/', $rel)[0], $skipDirs)` 会把
 *   `static/imgcache/cover.jpg` 判成 'static'（不在排除列表里）→
 *   **访客访问后自动下载的封面图全被算成了部署载荷**，
 *   站长实测多出 325 KB。
 *
 * ⚠ 边界检查（`$dir . '/'`）同样必需：没有它，排除项 'c' 会把
 *   **'config.php' 一起吃掉** —— 那是站点启动的命门。
 *
 * 抽成具名函数而不是内联，是为了让**自检与实际统计共用同一份实现**：
 * 早先版本把它们写成两套独立代码，于是「注入真实逻辑变坏」时自检毫无反应 ——
 * 那正是本项目栽过的「判据自欺」：判据测的是另一样东西。
 *
 * @param string $rel      相对站点根的路径，如 'static/imgcache/a.jpg'
 * @param array  $skipDirs 排除目录表（键是路径，值是原因）
 */
function t_payload_excluded(string $rel, array $skipDirs): bool {
    $rel = ltrim($rel, '/');
    foreach (array_keys($skipDirs) as $dir) {
        $dir = trim((string) $dir, '/');
        if ($dir !== '' && ($rel === $dir || str_starts_with($rel, $dir . '/'))) {
            return true;
        }
    }
    return false;
}

t('README 的体积宣传与实测对得上', static function () use ($root): void {
    // ⚠ 判据刻意做成「实测 vs 声明」，而不是去 grep 某个写死的数字：
    //   写死「README 里不许出现 1.3 MB」的话，下次体积变了就得改判据，
    //   而「判据要改」这件事本身很容易被忘掉 —— 于是判据失效、没人发现。
    //   实测法则是：算出真实体积，要求 README 声明的数字覆盖它。
    // ---- 算的是「站点部署载荷」，必须与 README 的口径一致 ----
    //
    // ⚠⚠ **排除项是被本契约自己逼出来的**，两次都是：
    //
    //   ① vp.php（17 KB）：它是**升级包里才有**的体检脚本，站点上跑完就该删，
    //      不属于部署载荷。第一版没排除它，实测 0.95 → 0.97 直接变红。
    //   ② CHANGELOG.md（109 KB）：更新日志是**给人读的**，不是站点运行所需。
    //      它有 109 KB —— 比整个 includes/ 还大，绝不能算进「部署载荷」。
    //   ③ 升级说明.md：只在升级包里，解压覆盖后可以删。
    //
    //   这三条都是「不在站点根目录长期存在」的文件。
    //   口径若与 README 不一致，这条判据就只是在给自己制造红点。
    // ⚠⚠⚠ **排除必须按「路径前缀」判，不能只判顶层目录名。**
    //
    //   踩过的坑：把 'static/imgcache' 放进 skipDirs，而判据写成
    //   `in_array(explode('/', $rel)[0], $skipDirs)`。
    //   而 static/imgcache/cover-xxx.jpg 的第一段是 **'static'**，不在列表里
    //   → **用户下载的封面图全被算成了「部署载荷」**。
    //   线上实测：judged 1159 KB vs 实际 866 KB，差额就是几百张封面。
    //
    //   这类 bug 的形状很典型：**排除项写对了、匹配方式写错了，两者都不会报错。**
    //   所以下面用前缀匹配而不是「取第一段去比对」，且逐条注明为什么排除。
    $skipDirs = [
        '.git'          => '版本库',
        'docs'          => '文档，部署不必上传',
        'tests'         => '开发工具，部署不必上传',
        'runtime'       => 'SQLite 与运行期缓存',
        '.github'       => 'CI 配置',
        'c'             => '页面静态缓存（可随时删，会自动重建）',
        'static/imgcache' => '★ 图片本地缓存：里面是**用户访问后自动下载的封面图**，'
                           . '数量与体积随站点运行增长，不属于「安装体积」',
    ];
    $skipFiles = [
        'vp.php'            => '升级体检脚本，跑完即删',
        'CHANGELOG.md'      => '更新日志，给人读的',
        '升级说明.md'        => '只在升级包里',
    ];
    $bytes = 0;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        if (!$f->isFile()) {
            continue;
        }
        $rel = substr((string) $f->getPathname(), strlen($root) + 1);
        if (t_payload_excluded($rel, $skipDirs) || isset($skipFiles[$rel])) {
            continue;
        }
        $bytes += (int) $f->getSize();
    }
    $mb  = $bytes / 1048576;
    $src = (string) file_get_contents($root . '/README.md');
    ok(
        preg_match('/整个项目[^\\n]*?([\\d.]+)\\s*MB/', $src, $m),
        'README 应声明部署载荷的实际体积（现在是「约 0.94 MB」），'
        . '否则读者会拿一个对不上的数字做决定'
    );
    // ⚠ 容差 2 KB：README 里的数字是四舍五入后手写的，
    //   988819 字节写「0.95 MB」是准确的，但 0.95 与实测只差 0.006 MB，
    //   严格大于会把四舍五入误判成「往小说」。
    //   所以判据是「声明值 + 容差 ≥ 实测值」。
    //
    // ⚠⚠ **这条判据只抓「往小说」，不抓「往大说」—— 这是刻意的**：
    //   README 说 1.3 MB 而实测 0.95 MB 时，本条**不会**变红。
    //   因为「往大说」是安全方向（读者以为更大，落到 1 GB 主机上仍绰绰有余），
    //   而「往小说」才是会误导人的那种。
    //   把两个方向都判红会逼着人把判据改成「精确等于某个魔数」，
    //   那才是真正的退步 —— 详见同文件里契约 1 的「扫出来而不是列出来」。
    // ---- 判据自检：排除逻辑本身对不对？----
    //
    // ⚠⚠ **这一段是被线上的一次真实误报逼出来的。**
    //
    //   1.3.13 的排除列表里写了 'static/imgcache'，而匹配写成
    //   `in_array(explode('/', $rel)[0], $skipDirs)`。
    //   而 static/imgcache/cover-xxx.jpg 的第一段是 **'static'** → 不在列表里
    //   → **用户访问后自动下载的封面图全被算成了「部署载荷」**。
    //   站长实测：判据说 1.191 MB，而干净树只有 0.866 MB，差额 325 KB 全是封面。
    //
    //   这类 bug 的形状很典型：**排除项写对了、匹配方式写错了，两者都不会报错。**
    //   所以下面把「哪些必须排除 / 哪些必须计入」逐条钉死。
    $excluded = ['static/imgcache/a.jpg', 'c/x.html', 'runtime/data.db',
                 'docs/x.md', 'tests/run.php', '.github/workflows/ci.yml', '.git/config'];
    $included = ['config.php', 'includes/guard.php', 'static/style.css',
                 'static/js/hls.min.js', 'admin.php', 'img.php', 'LICENSE', 'robots.txt'];
    $isExcluded = static fn (string $rel): bool => t_payload_excluded($rel, $skipDirs);
    $wronglyIncluded = array_values(array_filter($excluded, static fn ($f) => !$isExcluded($f)));
    eq([], $wronglyIncluded,
        "这些路径本该排除（运行期产物 / 开发文件），却被算进了部署载荷：\n  "
        . implode("\n  ", $wronglyIncluded)
        . "\n\n★ 特别注意 static/imgcache：那是**访客访问后自动下载的封面图**，"
        . "\n  体积随站点运行增长，与「安装体积」无关。"
        . "\n  判据必须按**路径前缀**匹配，不能只判第一段 —— "
        . "\n  explode('/', 'static/imgcache/a.jpg')[0] 是 'static'，不是 'static/imgcache'。"
    );
    $wronglyExcluded = array_values(array_filter($included, static fn ($f) => $isExcluded($f)));
    eq([], $wronglyExcluded,
        "这些文件是站点运行所需，却被误排除 —— 那会让实测值偏小，"
        . "等于用同一个错误掩盖另一个：\n  " . implode("\n  ", $wronglyExcluded));

    ok(
        (float) $m[1] + 0.002 >= $mb,
        sprintf(
            'README 说 %.3f MB，实测 %.3f MB —— 声明值必须覆盖实测值，'
            . '宁可往大说也不要往小说（宣传性数字一旦对不上，读者会连对的那部分也不信）',
            (float) $m[1],
            $mb
        )
    );
});

// ==================================================================
// 契约 8：任何「把 vod_play_url 交给播放器」的地方，都必须先过 parsePlayUrl
// ==================================================================
//
// 2026-10-05 线上事故：source 5 的影片播不出来。根因是上游用 MacCMS 的
// `$$$` 把**两套线路**并接在同一个 vod_play_url 里，而解析时先按 `#` 切了集数，
// `$$$` 于是落进「两套线路接缝」的那一集，它的地址变成
// `地址A$$$第01集$地址B` —— 播放器拿到一段不存在的 URL，静默失败。
//
// 这条契约守的是**约定**而非某个函数：上游改用别的线路分隔符（MacCMS 系
// 还见过 `$$$` / `$`+换行 的各种变体）时，只要解析入口仍是 parsePlayUrl，
// 就还在覆盖范围内；反过来，若有人新写一条「直接把 vod_play_url 塞给播放器」
// 的捷径而绕开它，这里会红。
//
// 判据是**扫出来的**（全仓库找 data-url / hls.loadSource 的赋值来源），
// 不是列出来的 —— 否则新增第 2 个模板时不会被检查，测试照样全绿。

t('契约 8 播放器拿到的地址都来自 parsePlayUrl，没有绕开线路解析的捷径', static function (): void {
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__), FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $p = (string) $f;
        if (!preg_match('/\.(php|js)$/', $p) || str_contains($p, '/tests/') || str_contains($p, '/.git/')) {
            continue;
        }
        $files[] = $p;
    }

    $offenders = [];
    foreach ($files as $p) {
        $src = (string) file_get_contents($p);
        // data-url 只能来自 $p['url']（parsePlayUrl 的产物），不能来自 $detail['vod_play_url']
        if (preg_match('/data-url="[^"]*\$\w+\[[\'"]vod_play_url[\'"]\]/', $src)) {
            $offenders[] = $p;
        }
        // hls.js / 原生播放的入参同理
        if (preg_match('/loadSource\(\s*\$\w+\[.vod_play_url.\]/', $src)
            || preg_match('/video\.src\s*=\s*\$\w+\[.vod_play_url.\]/', $src)) {
            $offenders[] = $p;
        }
    }
    eq([], array_values(array_unique($offenders)),
        "这些文件把上游原始 vod_play_url 直接喂给了播放器 —— 那会绕过线路解析，"
        . "重现 2026-10-05 的「一集都放不出来」：\n  " . implode("\n  ", $offenders));
});

// ==================================================================
finish();
