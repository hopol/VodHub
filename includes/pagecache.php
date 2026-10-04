<?php
/**
 * 页面静态缓存（极致低功耗模式 · 支柱一）
 *
 * 目标：把「每次浏览 1 个 EP」降到 0 —— 让 Apache 直出 HTML。
 *
 * 两层命中，缺一不可：
 *   第 1 层（0 EP）：.htaccess 按 c/<时间桶>/<key>.html 的 -f 判断直出
 *   第 2 层（1 EP × ~3 ms）：本文件的 pcRead() —— rewrite 不可用（Nginx /
 *           禁用 rewrite）、或时区/格式与 Apache 对不上时的兜底。
 *
 * 时间桶为什么要「多候选」：
 *   Apache 用 %{TIME_YMD}-%{TIME_HOUR} 拼路径，取的是**服务器本地时区**，
 *   而 PHP 的 date() 取的是 config.php 里设的 Asia/Shanghai，两者很可能不一致
 *   （共享主机多为 UTC）。对不上时 rewrite 的 -f 恒不成立 —— **不会读到错误
 *   内容，只是永远不命中**，退回到第 2 层。所以这里一次写入全部候选桶名，
 *   覆盖「应用时区 / 主机 ini 时区 / UTC」以及「小时是否补零」四种组合。
 *   多写的文件由 guardGcPageBuckets() 按 2 小时桶 + 总量上限回收。
 *
 * 安全红线（不可妥协）：
 *   访问密码开启时必须整体禁用 —— .htaccess 直出会**完全绕过 requireAccess()**，
 *   而 .htaccess 无法验证会话是否真的鉴权通过（伪造 cookie 即可绕过）。
 *   因此 access_enabled=1 时本模块**不读也不写**，c/ 下不会有文件，
 *   rewrite 的 -f 自然不成立。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/guard.php';

// ⚠️ 这里**不能**在顶层 require db.php：db.php 会 require 本文件
// （为了在 setSetting() 里作废页面缓存），顶层互相 require 会成环。
// setting() 的调用点都在函数体内，届时 db.php 必然已加载完毕。

/**
 * 页面静态化是否启用。
 *
 * 三个条件全都要满足：
 *   1) 后台没有关掉它
 *   2) 访问密码未开启（安全红线，见文件头）
 *   3) 磁盘没进保护模式（protect 档停止一切写入）
 */
function pcEnabled(): bool {
    require_once __DIR__ . '/db.php';   // setting() 定义在这里（惰性引入，避免环）
    if (setting('page_cache', '1') !== '1') {
        return false;
    }
    if (setting('access_enabled') === '1') {
        return false;
    }
    if (!guardCaps()['writable']) {
        return false;
    }
    return true;
}

/**
 * 推导页面的静态文件名。
 *
 * 必须与 .htaccess 的 rewrite 规则**逐字对应**，改这里就要改那边：
 *   index.php                 → index.html
 *   index.php?source=1        → index-1.html
 *   list.php?source=1&type=2&page=3 → list-1-2-3.html
 *   list.php?source=1&type=2 （缺 page，分类 chip 链接）→ list-1-2-1.html
 *   play.php?source=1&id=99   → play-1-99.html
 *   history.php               → history.html
 *   history.php?source=1      → history-1.html
 *
 * search.php 与 login.php 返回 null：搜索词不可预测（无法映射成固定文件名，
 * 也不能拿关键词当文件名 —— 任意 Unicode 有路径穿越风险），登录页必须走
 * CSRF 与会话。
 *
 * @param string $pageFile renderTemplate() 的第一个参数
 * @param array  $tplData  传给模板的数据
 */
function pcKey(string $pageFile, array $tplData): ?string {
    switch ($pageFile) {
        case 'index':
            $s = (int) ($tplData['sourceId'] ?? 0);
            return $s > 0 ? 'index-' . $s . '.html' : 'index.html';

        case 'list':
            return sprintf(
                'list-%d-%d-%d.html',
                (int) ($tplData['sourceId'] ?? 0),
                (int) ($tplData['typeId']   ?? 0),
                max(1, (int) ($tplData['page'] ?? 1))
            );

        case 'play':
            return sprintf(
                'play-%d-%d.html',
                (int) ($tplData['sourceId'] ?? 0),
                (int) ($tplData['vodId']    ?? 0)
            );

        case 'history':
            $s = (int) ($tplData['currentSourceId'] ?? 0);
            return $s > 0 ? 'history-' . $s . '.html' : 'history.html';

        default:
            // search / login 及任何未知页面一律不动态化
            return null;
    }
}

/**
 * 当前小时的全部候选桶名（去重）。
 *
 * 覆盖：应用时区（date）、主机 ini 时区（若有，且与应用时区不同）、UTC，
 *      以及小时不补零的写法。见文件头「时间桶为什么要多候选」。
 *
 * @return string[] 形如 ['20260928-09', ...]
 */
function pcBuckets(): array {
    static $buckets = null;
    if ($buckets !== null) {
        return $buckets;
    }

    $names = [];
    $add = static function (DateTimeImmutable $d) use (&$names): void {
        $names[$d->format('Ymd-H')] = true;   // 小时补零（Apache %H / PHP H）
        $names[$d->format('Ymd-G')] = true;   // 小时不补零（部分 Apache 版本）
    };

    $now = new DateTimeImmutable('now');

    // 1) 应用时区（config.php 设的 Asia/Shanghai）
    $add($now);

    // 2) 主机 ini 时区（Apache 多半按它或按系统 UTC 走）
    $iniTz = trim((string) @ini_get('date.timezone'));
    if ($iniTz !== '' && strtolower($iniTz) !== strtolower((string) date_default_timezone_get())) {
        try {
            $add($now->setTimezone(new DateTimeZone($iniTz)));
        } catch (Throwable $e) {
            // 非法时区名直接忽略
        }
    }

    // 3) UTC（共享主机最常见的系统时区）
    $add($now->setTimezone(new DateTimeZone('UTC')));

    // 4) **Apache 自己报的桶** —— 前三个都是猜的（应用时区 / ini 时区 / UTC），
    //    只要 Apache 的系统时区不在这三者之内就**永远命不中**：本地实测
    //    Apache TZ=Europe/London 时，连着两次请求都回源，页面能用但一层白做。
    //    `.htaccess` 开头那条 RewriteRule 把 %{TIME_YEAR}%{TIME_MON}%{TIME_DAY}-%{TIME_HOUR}
    //    写进 VH_BUCKET，这里照抄 —— 「按哪个钟找」和「按哪个钟写」从此同源，
    //    不存在猜错。没有这个环境变量（.htaccess 没生效 / Nginx）就退回上面三个候选。
    foreach ([(string) ($_SERVER['VH_BUCKET'] ?? ''), (string) (vhGetEnv('VH_BUCKET') ?: '')] as $b) {
        $b = trim($b);
        if ($b !== '' && preg_match('/^\d{8}-\d{1,2}$/', $b)) {
            $names[$b] = true;
        }
    }

    return $buckets = array_keys($names);
}

/** 读取用的规范桶名（PHP 自己的时区，永远与写入一致） */
function pcBucket(): string {
    return date('Ymd-H');
}

// ==================================================================
// 读写
// ==================================================================

/**
 * 读当前桶内的静态页面。命中返回 HTML，未命中返回 null。
 *
 * 这里只认自己算出来的桶名，因此**新鲜度天然正确**（过一小时桶名就变）。
 */
function pcRead(string $key): ?string {
    $file = PAGE_CACHE_DIR . '/' . pcBucket() . '/' . $key;
    if (!is_file($file)) {
        return null;
    }
    $html = @file_get_contents($file);
    return $html === false ? null : $html;
}

/**
 * 写静态页面到全部候选桶。原子写（.tmp + rename），避免 rewrite 的 -f
 * 读到半截 HTML。写失败静默 —— 页面照常已经 echo 出去了，只是没缓存。
 */
function pcWrite(string $key, string $html): void {
    if ($html === '' || !pcEnabled()) {
        return;
    }
    foreach (pcBuckets() as $bucket) {
        $dir = PAGE_CACHE_DIR . '/' . $bucket;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            continue;
        }
        $tmp = $dir . '/' . $key . '.tmp';
        if (@file_put_contents($tmp, $html, LOCK_EX) === false) {
            continue;
        }
        @rename($tmp, $dir . '/' . $key);
    }
}

/**
 * 标记本次渲染是**残页**（数据不完整），不要写进静态缓存。
 *
 * 典型场景：首页某个数据源的分类缓存被清空、而上游此刻不通 —— 渲染出来的是
 * 「无法获取分类」。这种页面一旦落进小时桶，**接下来整整一小时所有访客都看它**，
 * 而缓存 60 秒后其实已经能补上了。宁可这一小时多渲染几次，也不冻住残页。
 *
 * 谁来标：`VodClient::getTypesLocal()` 在拿到空分类时标一次。
 */
function pcVolatile(): void {
    $GLOBALS['__pc_volatile'] = true;
}

/** 本次渲染是否被标成残页（renderTemplate 写盘前问一句） */
function pcIsVolatile(): bool {
    return !empty($GLOBALS['__pc_volatile']);
}

/**
 * 版本门：代码版本变了就把上一版生成的静态 HTML 作废。
 *
 * 为什么必须有：静态桶按**小时**翻篇，换完代码后最多要等一整点新逻辑才生效 ——
 * 「修好了却看着没修」最典型的来源就是这个（1.3.0 修首页分类时实测踩到）。
 * 升级换文件的瞬间把上一版的产出清掉，改完即生效。
 *
 * 三种情况都作废：版本号不同 / 从没写过版本标记却已有 HTML（上一版留下的）。
 * 只删时间桶目录，`.lock` 与 `.v` 都不动；任何异常都吞掉 ——
 * 作废失败最多是「晚一个桶才生效」，绝不影响本次渲染。
 */
function pcVersionGate(): void {
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $cur = defined('APP_VERSION') ? trim((string) APP_VERSION) : '';
    if ($cur === '') {
        return;   // config.php 是旧版、认不出版本：不猜，更不乱删
    }

    $mark = PAGE_CACHE_DIR . '/.v';

    if (!is_dir(PAGE_CACHE_DIR)) {
        // 还没有静态目录：没有旧页可作废，但**必须先把版本标记补上** ——
        // 否则目录稍后被建出来、里面已经有本版写的 HTML 时，
        // 下一次请求会把它们误判成「上一版留下的」而白删一整轮（多渲染一次不算事，
        // 但「刚生成的缓存立刻被自己删掉」这种行为日后极难查）。
        if (@mkdir(PAGE_CACHE_DIR, 0755, true)) {
            @file_put_contents($mark, $cur, LOCK_EX);
        }
        return;
    }

    $old = is_file($mark) ? trim((string) @file_get_contents($mark)) : null;
    if ($old === $cur) {
        return;
    }

    try {
        // 没有标记却已经有 HTML → 一定是这版代码之前留下的，作废一次
        $stale = ($old !== null) || ((glob(PAGE_CACHE_DIR . '/*/*.html') ?: []) !== []);
        if ($stale) {
            foreach ((glob(PAGE_CACHE_DIR . '/*', GLOB_ONLYDIR) ?: []) as $bucket) {
                guardRmDir($bucket);
            }
        }
    } catch (Throwable $e) {
        // 见函数说明：清不掉只是晚一个桶生效
    }
    @file_put_contents($mark, $cur, LOCK_EX);
}

/**
 * 清空整个页面缓存。
 *
 * 触发时机：任意配置写入（后台改站点设置 / 数据源 / 分组 / 模板样式 /
 * 访问密码开关）。这是「配置变更 → 静态全失效」的唯一可靠机制 ——
 * rewrite 里读不到 SQLite（RewriteMap 在 .htaccess 中不可用），
 * 所以只能由 PHP 主动删。
 */
function pcClear(): void {
    // 批量模式（pcClearBatch）内只登记意图，最外层退出时统一清一次。
    // 原因见 pcClearBatch() 的说明：一次配置导入会写几十个源，
    // 逐次 pcClear() 实测 60 个文件就要 5.9 ms × N，白主机上是纯浪费。
    if ((int) ($GLOBALS['__pc_batch'] ?? 0) > 0) {
        return;
    }
    pcClearNow();
}

/**
 * 把一串写操作包起来，期间所有 pcClear() 只登记不执行，退出时清一次。
 *
 * ⚠ 为什么需要它：pcClear() 收口到数据层之后（见 db.php 的 addSource 等），
 *   任何一次写数据源都会触发全量作废 —— 这是对的，但**批量路径会重复作废**。
 *   实测 `c/` 下 60 个文件时单次 pcClear 约 5.9 ms，
 *   一次「覆盖导入」写 50 个源就是 50 次 ≈ 295 ms（共享 CPU 上还要翻倍），
 *   而这些源是一次性写完的，中间没有访客，中间态的缓存**没有任何意义**。
 *
 *   用法：`return pcClearBatch(function () { ...导入逻辑... });`
 *
 *   嵌套安全：按深度计数，最外层退出时才真正清。
 */
function pcClearBatch(callable $fn) {
    $GLOBALS['__pc_batch'] = (int) ($GLOBALS['__pc_batch'] ?? 0) + 1;
    try {
        return $fn();
    } finally {
        $GLOBALS['__pc_batch'] = (int) $GLOBALS['__pc_batch'] - 1;
        if ((int) $GLOBALS['__pc_batch'] === 0) {
            pcClearNow();
        }
    }
}

/** pcClear() 的实际动作（分离出来，好让批量模式直接复用） */
function pcClearNow(): void {
    $dir = PAGE_CACHE_DIR;
    if (!is_dir($dir)) {
        return;
    }
    foreach ((scandir($dir) ?: []) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            guardRmDir($path);
        } else {
            @unlink($path);
        }
    }
    // 清完顺手同步总闸：该关就写 c/.lock，该开就摘掉
    pcSyncGate();
    // 版本标记也被上面一起删了，补回去 —— 否则下次 pcVersionGate 会把
    // 「刚清空的目录」误判成「上一版留下的 HTML」而白跑一趟。
    if (defined('APP_VERSION')) {
        @file_put_contents(PAGE_CACHE_DIR . '/.v', trim((string) APP_VERSION), LOCK_EX);
    }
}

/**
 * 静态直出的**总闸**：`c/.lock` 存在时，.htaccess 的全部静态规则直接失效。
 *
 * 为什么需要它（而不是只靠 pcEnabled() 的判断）：
 *   pcEnabled() 是 PHP 侧的判断，只能管住「读与写」。而 `.htaccess` 是在
 *   **PHP 跑起来之前**就生效的 —— 如果 `c/` 下还留着上一次生成的文件，
 *   它们会被无条件直出，**绕过 requireAccess()**。
 *
 * 正常路径下 `setSetting()` → `pcClear()` 会把文件删干净，但安全控制不该
 * 依赖「某条调用链一定被执行」。这里补一道结构性的闸：只要静态直出应当
 * 关闭（访问密码开启 / 后台关掉页面缓存 / 磁盘进保护模式），就落一个
 * `.lock` 文件，让 rewrite 从源头不匹配。每次前台渲染都会调用它自愈。
 */
function pcSyncGate(): void {
    $lock  = PAGE_CACHE_DIR . '/.lock';
    $needs = !pcEnabled();
    if (!$needs) {
        if (is_file($lock)) {
            @unlink($lock);
        }
        return;
    }
    if (is_file($lock)) {
        return;
    }
    if (!is_dir(PAGE_CACHE_DIR)) {
        if (!@mkdir(PAGE_CACHE_DIR, 0755, true)) {
            return;   // 建不出来就不硬来；clear 已经把文件删了，一样安全
        }
    }
    @file_put_contents($lock, date('c'));
}

/** 页面缓存当前文件数（后台状态展示用） */
function pcCount(): int {
    return count(glob(PAGE_CACHE_DIR . '/*/*.html') ?: []);
}
