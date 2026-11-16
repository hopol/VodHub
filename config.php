<?php
/**
 * 全局配置
 * 除本文件外，其余配置均存放在数据库（runtime/data.db）中，可在后台修改。
 */

// ---------------------------------------------------------------- PHP 版本保护
// 必须放在本文件最顶部：项目用了 str_contains、str_starts_with、命名参数等 PHP 8 特性，
// 在旧版本上会直接致命错误或静默白屏。这里把"莫名其妙白屏"变成明确提示。
if (PHP_VERSION_ID < 80000) {
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/html; charset=utf-8');
    }
    exit(
        '<!DOCTYPE html><html lang="zh-CN"><meta charset="utf-8">'
        . '<title>PHP 版本过低</title>'
        . '<body style="font-family:sans-serif;padding:40px;line-height:1.8">'
        . '<h1>需要 PHP 8.0 及以上</h1>'
        . '<p>当前环境的 PHP 版本：<b>' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '</b></p>'
        . '<p>本站使用了 PHP 8 引入的语言特性，无法在低版本上运行。</p>'
        . '<p>解决办法：在空间面板中将 PHP 版本切换到 <b>8.0 / 8.1 / 8.2 / 8.3</b>，'
        . '或联系空间服务商升级运行环境。</p>'
        . '</body></html>'
    );
}

// 同时要求关键扩展，缺了也是白屏。
//
// ⚠⚠ **`mbstring` 必须在清单里（1.5.6 加）**：它是**独立扩展**，不是 PHP 8 核心
//   的一部分（`json` 才是 8.0 起不可禁用）。项目里 `mb_strtolower`（列表页/搜索页
//   的 `searchHaystack()`）、`mb_strlen`（播放页 `buildMeta()`）、`mb_substr`
//   （后台未知 action 的提示）都在必经路径上，缺了就是 **Fatal**：
//     · 列表页 / 搜索页 → 整页 500
//     · 播放页         → 整页 500
//     · 后台未知 action → 500（恰好在站长排查「服务器版本不对」时炸掉）
//
//   `docs/deployment.md` 原先写着「`json`、`mbstring`（PHP 8 默认自带）」——
//   那句是**错的**，于是「缺扩展」被归因成「代码坏了」或「升级包没传全」。
//
// ⚠ 报错文案**列出全部缺失项**，不再把扩展名写死在句子里 ——
//   否则加一个扩展就要改一次文案，又会漂移。
$requiredExts = ['curl', 'pdo_sqlite', 'mbstring'];
$missingExts  = array_values(array_filter($requiredExts, static fn (string $e): bool => !extension_loaded($e)));
if ($missingExts !== []) {
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/html; charset=utf-8');
    }
    $missList = htmlspecialchars(implode('、', $missingExts), ENT_QUOTES, 'UTF-8');
    exit(
        '<!DOCTYPE html><html lang="zh-CN"><meta charset="utf-8">'
        . '<title>缺少 PHP 扩展</title>'
        . '<body style="font-family:sans-serif;padding:40px;line-height:1.8">'
        . '<h1>缺少必需的 PHP 扩展</h1>'
        . '<p>缺失扩展：<b>' . $missList . '</b></p>'
        . '<p>请在空间面板中启用下列扩展后重试：<br><code>'
        . htmlspecialchars(implode(' / ', $requiredExts), ENT_QUOTES, 'UTF-8')
        . '</code></p>'
        . '<p style="color:#666">（<code>mbstring</code> 是独立扩展，不是 PHP 8 自带的 ——'
        . '缺它会让列表页与播放页直接 500。）</p>'
        . '</body></html>'
    );
}

if (!function_exists('vhGetEnv')) {
    /**
     * 安全读取环境变量：**该函数被禁用时不抛错**。
     *
     * ⚠ 实测：`getenv` 出现在 `disable_functions` 里时，
     *   PHP 8 直接抛 `Error: Call to undefined function getenv()`
     *   —— 是**致命错误**，不是返回 false。
     *   （我曾以为它返回 false，那是错的，实测纠正。）
     *
     *   免费主机的 disable_functions 因时而异，不能假设它一定可用。
     *   本项目多处读环境变量（数据目录、TLS 开关），一处炸就是整站 500。
     *
     * @return string|false 同 getenv()，但函数不存在时返回 false
     */
    function vhGetEnv(string $name) {
        return function_exists('getenv') ? getenv($name) : false;
    }
}

// 站点名称（显示在页面标题与页头）
define('APP_NAME', '影视聚合站');

// 版本号（与 CHANGELOG.md 保持一致）
define('APP_VERSION', '1.5.6');

// ================================================================
// 数据目录（安全基线 · 无 .htaccess 也能守住的那一条）
// ================================================================
//
// 【为什么要有这段】
//   runtime/ 下是 data.db（管理密码哈希 + 上游接口地址）、接口缓存、错误日志。
//   Apache 对**非 .php 文件**的请求是「直接读磁盘吐字节」，全程不进 PHP ——
//   所以任何「在 config.php 里判断一下」的拦截都无效。
//   `.htaccess` 能拦，但它需要主机开 `AllowOverride`；`AllowOverride` 没开的
//   免费主机上，这里就是唯一防线。
//   **唯一与服务器配置无关的解法是：不把它放进 Web 根。**
//
// 【四级解析，绝不自动搬老站的数据】
//   ① 显式指定 VODHUB_DATA_DIR（常量或环境变量）—— 站长主动迁移时用
//   ② runtime/data.db 已存在            —— 老站点**原地不动**（自动切换 = 丢库）
//   ③ 全新安装                          —— 优先建站点根的兄弟目录 ../vodhub-data
//   ④ 上面都不成                        —— 回退 runtime/（此时靠 .htaccess 拦）
//
// DATA_DIR_OUTSIDE 供后台与 vp.php 判断安全等级：
//   true  → 数据在 Web 根之外，`.htaccess` 拆了也不会泄露（最优）
//   false → 数据在 Web 根内，`.htaccess` 是唯一防线，必须确认它真的生效

/**
 * 目录是否可用（**试写判据**，不是 is_writable()）。
 *
 * 共享主机上父目录常显示可写、实际 mkdir 被拒：NFS 挂载、配额满、
 * open_basedir 未放行、面板软链。所以新建目录必须真的写进去一次。
 *
 * 目录已存在时退化为一次 is_writable()（stat 调用）——
 * 本函数在**每个请求**都会走到这一分支，不能每次都写探针文件。
 */
function vhDirUsable(string $dir): bool {
    if ($dir === '') {
        return false;
    }
    if (is_dir($dir)) {
        return is_writable($dir);
    }
    if (!@mkdir($dir, 0755, true)) {
        return false;   // open_basedir 未放行 / 父目录只读 / 权限不足
    }
    $probe = $dir . '/.wprobe';
    $ok = (@file_put_contents($probe, '1') !== false);
    if ($ok) {
        @unlink($probe);
    }
    return $ok;
}

/**
 * 在目录里放一个空 index.html，挡住目录列表。
 *
 * 代替 `.htaccess` 里的 `Options -Indexes` —— 那条需要 `AllowOverride Options`，
 * 而多数免费主机只给 `FileInfo`，于是**包了 `<IfModule>` 也会 500**
 * （IfModule 只看模块在不在，不看 AllowOverride 允不允许）。
 * `DirectoryIndex index.html` 是 Apache 主配置的默认值，**不需要任何
 * AllowOverride 权限** —— 这就是为什么纯文件比 `.htaccess` 指令可靠。
 *
 * 幂等、廉价（已存在就直接返回），建目录的每个点各调一次。
 */
/**
 * 路径是否落在 Web 根之内（1.5.6）。
 *
 * 用 `$_SERVER['DOCUMENT_ROOT']` + `realpath` 实测，**不用 `dirname(__DIR__)` 猜** ——
 * 后者只有在「应用就在 Web 根」时才等于 Web 根，子目录部署时是错的
 * （见 vhResolveDataDir() ③ 的说明）。
 *
 * ⚠ realpath 对不存在的路径返回 false，此时退回字面比对
 *   （与 guardSessionExposure() 同一套处理，理由见那里）。
 *
 * @return bool true = 在 Web 根内（需要处置）；false = 在 Web 根外（安全）
 */
function vhPathInsideWebRoot(string $dir): bool {
    $docroot = trim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($docroot === '') {
        return false;   // 拿不到 Web 根（CLI / 异常环境）：不误判成「站内」
    }
    $nroot = rtrim(str_replace('\\', '/', (string) $docroot), '/');
    if ($nroot === '') {
        return false;
    }
    $real = realpath($dir);
    $norm = rtrim(str_replace('\\', '/', (string) ($real !== false ? $real : $dir)), '/');
    return $norm === $nroot || str_starts_with($norm, $nroot . '/');
}

/**
 * 给「落在 Web 根内」的数据目录补两道防线（1.5.6）。
 *
 * `.htaccess` 的 `runtime/` 规则拦不到同级的 `vodhub-data/`，所以必须就地补：
 *   1. `.htaccess` → `Require all denied`（Apache 2.4）+ `Deny from all`（2.2 兼容）；
 *      两者都包在 `<IfModule>` 里，且**不使用 `Options` / `php_value`**
 *      （那两类需要 AllowOverride Options，多数免费主机不给，会让整站 500 ——
 *       详见 guardHtAudit() 的说明）。
 *   2. `index.html` → 挡目录列表（不依赖 AllowOverride，见 vhGuardIndex()）。
 *
 * ⚠ 两道都只是「尽力」：**真正的解法是把数据目录移出 Web 根**
 *   （见 docs/deployment.md「数据目录」一节）。这里只保证
 *   「站长没来得及移」时不至于裸奔。
 */
function vhGuardDataDir(string $dir): void {
    static $done = [];
    if (isset($done[$dir]) || !is_dir($dir)) {
        return;
    }
    $done[$dir] = true;

    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht,
            "# VodHub：数据目录落在 Web 根内，本文件拒绝一切 HTTP 访问。\n"
            . "# ⚠ 根治办法是把数据目录移出 Web 根（docs/deployment.md「数据目录」）。\n"
            . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n"
        );
    }
    vhGuardIndex($dir);
}

function vhGuardIndex(string $dir): void {
    static $done = [];
    if (isset($done[$dir]) || $dir === '' || !is_dir($dir)) {
        return;
    }
    $done[$dir] = true;
    $f = $dir . '/index.html';
    if (is_file($f)) {
        return;
    }
    @file_put_contents(
        $f,
        '<!-- VodHub：本文件唯一的作用是挡住目录列表（代替 .htaccess 的 `Options -Indexes`）。'
        . '那条指令需要 AllowOverride Options，多数免费主机不给，会整站 500。请勿删除。 -->\n'
    );
}

/** 解析数据目录。结果按请求缓存，后续调用零开销。 */
function vhResolveDataDir(): array {
    static $resolved = null;
    if ($resolved !== null) {
        return $resolved;
    }

    $legacy = __DIR__ . '/runtime';

    // ① 显式指定 —— 迁移数据目录时用。两种写法任选其一（常量更通用，
    //    因为免费主机面板常常没有设置环境变量的入口）：
    //      a) 在本文件下方写：  define('VODHUB_DATA_DIR', '/home/你/vodhub-data');
    //      b) 设环境变量：       VODHUB_DATA_DIR=/home/你/vodhub-data
    $spec = defined('VODHUB_DATA_DIR') ? (string) VODHUB_DATA_DIR : (string) (vhGetEnv('VODHUB_DATA_DIR') ?: '');
    if (trim($spec) !== '') {
        $spec = rtrim(trim($spec), '/');
        if ($spec !== '' && vhDirUsable($spec)) {
            // ⚠ 1.5.6：显式指定也不能盲信「站外」——站长可能填了一个 Web 根内的路径。
            //   与 ③ 同一套实测判定，并同样补防线。
            $inside = vhPathInsideWebRoot($spec);
            if ($inside) {
                vhGuardDataDir($spec);
            }
            return $resolved = [$spec, !$inside];
        }
    }

    // ② **已有数据的老站点绝不自动搬家** —— 静默换目录等于静默丢库。
    //    迁移必须由站长显式做（见 docs/deployment.md「数据目录」一节）。
    if (is_file($legacy . '/data.db')) {
        return $resolved = [$legacy, false];
    }

    // ③ 全新安装：优先站外
    //
    // ⚠⚠ **1.5.6：「兄弟目录 = 站外」只在应用部署在 Web 根时成立**。
    //
    //   站点装在 `htdocs/vodhub/`（Web 根是 `htdocs/`）时，
    //   `dirname(__DIR__)` = `htdocs` → 数据目录建在 **`htdocs/vodhub-data`**，
    //   即 **Web 根之内**，`https://域名/vodhub-data/data.db` 可直接下载
    //   （管理密码哈希 + 全部接口地址）；而 `DATA_DIR_OUTSIDE = true`，
    //   后台第一屏显示「Web 根之外（不依赖 .htaccess）」——**与事实相反**。
    //   `.htaccess` 的 `^runtime/` 规则在 `vodhub/` 下，拦不到同级的 `vodhub-data/`。
    //
    //   修法：**用 DOCUMENT_ROOT 实测**，落在 Web 根内的一律判「站内」，
    //   并给它补上 `.htaccess` 拒绝访问 + `index.html`（不依赖 AllowOverride 之外的东西）。
    $outer = dirname(__DIR__) . '/vodhub-data';
    if (vhDirUsable($outer)) {
        $inside = vhPathInsideWebRoot($outer);
        if ($inside) {
            // 在 Web 根内：补一道「不依赖 .htaccess 的防线」+ 把等级说清楚
            vhGuardDataDir($outer);
        }
        return $resolved = [$outer, !$inside];
    }

    // ④ 回退站点根内（此时 .htaccess 的 runtime/ 拦截是唯一防线，后台会标红）
    return $resolved = [$legacy, false];
}

// ---- 站长手动指定数据目录（默认注释 = 交给 vhResolveDataDir() 自动决定）----
// 迁移老站点时，把下行的 define 前面的 // 去掉，并改成 Web 根之外的绝对路径：
// define('VODHUB_DATA_DIR', '/home/你的用户名/vodhub-data');
// ⚠️ 改完**必须**先把 runtime/ 整个移动到该路径（是移动不是复制），
//    否则等于开了个空库，站点会「看起来像重装了」。
//    完整步骤见 docs/deployment.md「数据目录（不依赖 .htaccess 的安全基线）」。

[$vhDataDir, $vhDataOutside] = vhResolveDataDir();

// 运行数据目录（数据库、缓存，部署后需保证可写）
define('DATA_DIR', $vhDataDir);

// 数据目录是否在 Web 根之外（详见上方说明）
define('DATA_DIR_OUTSIDE', $vhDataOutside);

// SQLite 数据库文件
define('DB_FILE', DATA_DIR . '/data.db');

// 接口响应缓存目录
define('CACHE_DIR', DATA_DIR . '/cache');

// 缓存有效期（秒）。上游接口不稳定，缓存既是性能手段也是容灾手段
define('CACHE_TTL', 1800);

// 分类数据（ac=list）的缓存有效期：分类几乎不变，走长缓存以减少上游请求。
// 列表/详情仍用 CACHE_TTL，两者分开是为了避免分类被短 TTL 拖累。
define('CACHE_TTL_TYPE', 86400); // 24 小时

// 上游失败的负缓存：失败后这段时间内**完全不出站**，
// 直接用过期数据或空结果。这是消灭「上游一挂，每次点击白等 12 秒」
// 这个故障放大器的关键 —— 没有它，故障期间每一次访问都在占 EP 槽位。
define('CACHE_TTL_NEG', 60);

// ---------------------------------------------------------------- 极致低功耗模式
// 页面静态缓存目录。
// **必须留在 Web 根内**：Apache 的 0-EP 直出（.htaccess 里那 7 组 RewriteCond -f）
// 只能服务站点根之下的文件。它装的是已渲染好的公开页面，不涉密，
// 所以「留在 Web 根内」在安全上没有代价；列目录由 static/ 等处的空 index.html 挡。
// 若你的主机完全用不上 rewrite，把它移出去也不影响功能（会退回第 2 层 1 EP 读盘）。
define('PAGE_CACHE_DIR', __DIR__ . '/c');

// 图片代理本地缓存目录。**必须留在 Web 根内** —— 它是靠 Apache 直接吐字节省 EP 的，
// 一旦移出 Web 根，每张图都要起一个 PHP 进程，支柱二就白做了。
// 里面是公开的封面图，不涉密；列目录由 static/imgcache/index.html 挡。
define('IMG_CACHE_DIR', __DIR__ . '/static/imgcache');

// 会话名称
define('SESSION_NAME', 'vodsite_sid');

// 会话键名
define('SESS_ACCESS_OK', 'access_ok');
define('SESS_ADMIN_OK',  'admin_ok');
define('SESS_CSRF',      'csrf_token');

// 后台默认密码（首次安装后请立即在后台修改）
define('DEFAULT_ADMIN_PASSWORD', 'admin123');

// ---------------------------------------------------------------- TLS 证书校验
/**
 * 是否校验上游的 HTTPS 证书。
 *
 * ⚠ **默认为 true（校验）**，这是 1.3.4 的安全修复。
 *   此前 img.php 与 includes/client.php 都写死
 *   `CURLOPT_SSL_VERIFYPEER => false` —— 意味着数据源地址、管理员密码哈希、
 *   影片元数据与播放地址的往来流量**全部暴露给中间人**。
 *
 * **为什么要留降级口子**：实测（badssl.com 自签名证书）
 *   VERIFY=true  → 失败，HTTP 0，SSL certificate problem
 *   VERIFY=false → 成功，HTTP 200
 *   也就是说**免费主机的 CA 链一旦不完整，默认开启就会直接连不上** ——
 *   表现为「所有数据源都超时」，属最难定位的一类故障。
 *
 *   所以保留显式降级，但：
 *     ① 默认开启，需要降级的人自己选择；
 *     ② 降级状态会在后台明确显示（见 guardHtAudit 的同级检查）。
 *
 * 改法（二选一，写在下方取消注释即可）：
 *   define('VODHUB_TLS_VERIFY', false);
 * 或环境变量 VODHUB_TLS_VERIFY=0
 */
if (!function_exists('vhTlsVerifyFromEnv')) {
    /**
     * 由环境变量值解析「是否校验证书」。
     *
     * 抽成纯函数是**为了可测**：常量在一个进程里只能 define 一次，
     * 若把判断写在 config.php 顶层，测试就只能靠 shell_exec 起子进程去试 ——
     * 而免费主机普遍禁用它（1.3.5 首次发布就因此报了三项失败）。
     * 抽出来后测试直接调它，覆盖所有取值且零外部依赖。
     *
     * @param string|false $v getenv() 的返回值
     * @return bool true = 校验证书（安全默认）
     */
    function vhTlsVerifyFromEnv($v): bool {
        if ($v === false || $v === '' || $v === null) {
            return true;                       // 未设置 → 校验（安全默认）
        }
        $s = strtolower(trim((string) $v));
        if ($s === '') {
            return true;
        }
        // 明确列出的「关闭」取值才降级；其余（含 1/true/on/yes）都保持校验。
        // 反过来做（不在白名单就关闭）会让拼错的值悄悄关掉安全防护。
        return !in_array($s, ['0', 'false', 'off', 'no', 'none'], true);
    }
}

if (!defined('VODHUB_TLS_VERIFY')) {
    define('VODHUB_TLS_VERIFY', vhTlsVerifyFromEnv(vhGetEnv('VODHUB_TLS_VERIFY')));
}

// 降级状态是否对外可见（后台与 vp.php 会提示「当前未校验证书」）
define('TLS_VERIFY', (bool) VODHUB_TLS_VERIFY);

// 时区
date_default_timezone_set('Asia/Shanghai');

// 错误显示（生产环境建议关闭）
error_reporting(E_ALL);
ini_set('display_errors', '1');
