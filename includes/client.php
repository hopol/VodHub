<?php
/**
 * 接口客户端：对接苹果 CMS（MacCMS）标准 provide/vod 格式
 *
 * 约定：
 *   - 分类列表：?ac=list            → 返回 class 数组
 *   - 内容列表：?ac=detail&pg=N&t=X → 返回 list + 分页信息
 *   - 视频详情：?ac=detail&ids=ID   → 返回单条完整字段
 *   - 搜索：    ?ac=detail&wd=关键词
 *
 * 带文件缓存。上游请求失败时自动降级使用旧缓存，保证站点不白屏。
 *
 * 极致低功耗模式 · 支柱四（访客路径零阻塞）在这个类里落了四件事：
 *   1. 超时从 12 s 降到 4 s（从未有数据时的完整拉取），连接 6 s → 2.5 s
 *   2. 失败写 60 s 负缓存 —— 失败期间**完全不出站**，
 *      消灭「上游一挂，每次点击白等 12 秒」这个故障放大器
 *   3. 有过期数据时**有界刷新**：1 秒内能成就用新的，不成就用旧的
 *      （方案原稿是「立即返回 + 刷新队列」，这里合并成一步 ——
 *        最坏阻塞 1 s 仍远低于方案给访客的 2 s 预算，却省掉整套队列）
 *   4. 搜索词不落盘 —— 缓存文件名含 wd，爬虫可以无限制造文件，
 *      这是 1 GB 磁盘上最快失控的一条增长链
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

/**
 * 读一条后台设置（只读，不联网）。
 *
 * 这里**不能**顶层 require db.php：db.php 会 require 本文件（setSetting()
 * 末尾要作废接口缓存），顶层互相 require 会成环。所以 setting() 必须在
 * 函数体内调用 —— 而构造函数要读 warm_budget，只能在调用时才读，
 * 届时 db.php 必然已由 auth.php / functions.php 的调用链加载完毕。
 * db.php 没加载时（极少数裸调用）退回默认值，绝不 fatal。
 */
function vhSetting(string $key, string $default = ''): string {
    if (!function_exists('setting')) {
        return $default;
    }
    try {
        return setting($key, $default);
    } catch (Throwable $e) {
        return $default;
    }
}

// 1.3.0 在 config.php 里新增的常量兜底 —— 升级包漏传 config.php 时，
// 旧版没有它，而 request() 的负缓存分支每条失败请求都会读一次，
// 不兜底就是「上游一挂、前台全 500」。同 guard.php 的理由：
// 可选的优化常量不该有把整站打死的权限。
if (!defined('CACHE_TTL_NEG')) {
    define('CACHE_TTL_NEG', 60);
}

class VodClient {
    private string $baseUrl;
    /** 最近一次请求的失败说明，见 lastMsg() */
    private string $lastMsg = '';
    /**
     * 从未有过数据时的完整拉取超时（秒）。
     *
     * 2026-09-30 线上（vodhub.ct.ws，InfinityFree + OpenResty）实测：
     * 网关超时约 3s，旧值 4s 意味着**只要 1 个源慢**，首页/列表页/播放页
     * 就整体超过 3s → 网关 502。改成 2s：单源最坏 2s，稳稳压在网关内；
     * 拉不到就写负缓存 + 用旧数据，绝不让一个慢源拖死整页。
     *
     * 注意：CURLOPT_TIMEOUT 是**整数秒**（curl 语义），所以这里给 2 而不是 2.5。
     */
    private int   $timeout = 2;
    /** 已有过期数据时的有界刷新超时 */
    private int   $refreshTimeout = 1;

    /**
     * 首页冷缓存补拉的**单次请求**预算（秒）。见 request() 里的 ④′。
     *
     * 1.3.1 让首页在本地没分类时自己补拉，但没给「一次请求最多拉多久」设上限：
     * 清空接口缓存后的第一波访问 = N 个源**串行**、每个最多 4 秒，
     * 15 个源最坏 60 秒 —— 必然撞上网关超时，表现为**短暂 502**（2026-09-29 线上实测）。
     */
    private const WARM_BUDGET_DEFAULT = 6.0;
    /** 本次请求里首页首次补拉的时刻（PHP 的 static 每请求重置，不用手动清） */
    private static float $warmStart = 0.0;
    /** 本次请求的补拉预算（秒），后台可调，默认 6 */
    private float $warmBudget = 6.0;

    /**
     * 本次「整页渲染」的墙钟截止时间戳（static，跨实例共享，每请求只算一次）。
     *
     * 2026-09-30 线上 502 根因：一页可能要对多个源各出站一次，
     * 每个单独看都在 2s 内，**加总却冲破 OpenResty ~3s 网关** → 502。
     * 所以除了「单源超时」还要「整页出站预算」：从本页第一个出站起表，
     * 到截止时间后就不再为**新数据**出站（旧数据/负缓存照常用），
     * 把整页最坏耗时锁在 PAGE_DEADLINE_BUDGET 内。
     */
    private static float $pageStart = 0.0;
    /** 整页出站预算（秒）。默认 2.5s，稳压在 OpenResty ~3s 网关内 */
    private const PAGE_DEADLINE_BUDGET = 2.5;

    /**
     * 页面入口调用：本页墙钟起表。
     *
     * index.php / list.php / play.php 在**任何 new VodClient / 出站之前**
     * 调一次，从页面进来到整页出站锁在 PAGE_DEADLINE_BUDGET（2.5s）内，
     * 稳压在 OpenResty ~3s 网关。static 每请求重置，重复调用幂等。
     */
    public static function pageBudgetStart(): void {
        if (self::$pageStart <= 0.0) {
            self::$pageStart = microtime(true);
        }
    }

    public function __construct(string $baseUrl) {
        $this->baseUrl = trim($baseUrl);
        // 后台可调的补拉预算（秒）。免费空间网关超时从 3 秒到 30 秒都有，
        // 写死 6 秒在 3 秒超时的主机上照样撞 502 —— 所以暴露成可配置项。
        //
        // 注意：这里**不能**直接调 setting()——本文件在 client.php 里，
        // 而 setting() 定义在 db.php，db.php 又 require 本文件（setSetting()
        // 末尾要作废接口缓存），顶层互相 require 会成环。
        // 所以构造函数保持默认 6 秒；真正要用配置值时，由调用方在
        // setting() 可用之后调 warmBudget() 显式覆盖。
        // 所有调用 VodClient 的页面（index/list/play 等）都已经 require 过
        // db.php，所以它们的 setting() 可用 —— 但构造函数里读不到。
        // 因此把「读配置」放到第一个用到 warmBudget 的地方（request() 的 ④′）：
        $this->warmBudget = self::WARM_BUDGET_DEFAULT;
    }

    /** 获取分类列表 */
    public function getTypes(): array {
        return $this->sortTypes($this->request(['ac' => 'list'])['class'] ?? []);
    }

    /**
     * 首页分类：**本地有数据就只读本地**（哪怕已过期，也不出站）。
     *
     * 首页要对每个数据源各取一次分类，冷缓存时若每次都出站，最坏 N × 4 秒，
     * 5 个源就是 20 秒 —— 免费主机 15~30 秒的网关超时下表现为首页 504。
     * 分类 24 小时基本不变，显示一小时前的列表用户察觉不到。
     *
     * ⚠ **本地一条都没有时必须出站补一次**（1.3.0 首发漏了这一条，
     *   于是「清一次接口缓存，首页分类就永久空白」）：首页是分类的**唯一**
     *   展示入口 —— 拿不到分类时连「浏览全部 →」都不渲染，站内没有任何路径
     *   能触发刷新；后台「测试连接」走 probe() 又是直连上游、不落盘，
     *   于是出现「后台测试正常、首页没分类」这种最费解的现象。
     *   补拉成功后回到上面的只读路径；上游不通时由 60 秒负缓存兜住，
     *   不会每次访问都重复出站。
     */
    public function getTypesLocal(): array {
        $types = $this->sortTypes($this->request(['ac' => 'list'], true)['class'] ?? []);
        if (!$types) {
            // 这一页是残页（本地没缓存 + 上游没给）：标记为**不可静态化**。
            // 否则小时桶会把空白首页冻住整整一小时，缓存补上也得等整点才生效。
            if (function_exists('pcVolatile')) {
                pcVolatile();
            }
        }
        return $types;
    }

    /**
     * 最近一次请求为什么拿不到数据（模板在空态里展示给站长看）。
     *
     * 只有失败分支会写；成功时为空串。首页那句「无法获取分类」之所以难排查，
     * 就是它把「本地无缓存」「上游超时」「上游返回不可解析」说成了同一句话。
     */
    public function lastMsg(): string {
        return $this->lastMsg;
    }

    /** 分类统一排序（兼容 type_pid 缺失） */
    private function sortTypes($types): array {
        if (!is_array($types)) {
            return [];
        }
        usort($types, static function ($a, $b) {
            $pa = intval($a['type_pid'] ?? 0);
            $pb = intval($b['type_pid'] ?? 0);
            if ($pa === $pb) {
                return intval($a['type_id'] ?? 0) <=> intval($b['type_id'] ?? 0);
            }
            return $pa <=> $pb;
        });
        return $types;
    }

    /** 获取内容列表（某分类、某页）。wd 非空时为搜索 */
    public function getList(int $typeId = 0, int $page = 1, string $wd = ''): array {
        $params = ['ac' => 'detail', 'pg' => $page];
        if ($typeId > 0) {
            $params['t'] = $typeId;
        }
        if ($wd !== '') {
            $params['wd'] = $wd;
        }
        return $this->request($params);
    }

    /** 获取视频详情 */
    public function getDetail(int $vodId): ?array {
        $data = $this->request(['ac' => 'detail', 'ids' => $vodId]);
        $list = $data['list'] ?? [];
        if (!is_array($list) || !$list) {
            return null;
        }
        return $list[0];
    }

    /** 探测接口可用性（后台"测试连接"用） */
    public function probe(): array {
        $start = microtime(true);
        $url = $this->buildUrl(['ac' => 'list']);
        $code = 0;
        $err = '';
        $body = $this->httpGet($url, $code, $err);
        $elapsed = round(microtime(true) - $start, 2);
        if ($body === '') {
            return ['ok' => false, 'msg' => '请求失败：' . $err, 'elapsed' => $elapsed];
        }
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'msg' => '返回不是有效 JSON', 'elapsed' => $elapsed];
        }
        $types = is_array($data['class'] ?? null) ? count($data['class']) : 0;
        $total = intval($data['total'] ?? 0);
        return [
            'ok'      => true,
            'msg'     => sprintf('连接正常，分类 %d 个，数据 %d 条', $types, $total),
            'elapsed' => $elapsed,
        ];
    }

    /**
     * 清空该源的缓存（按文件名前缀 = `md5(baseUrl)` 整批删，`.neg` 负缓存一并清）。
     *
     * ⚠ 这个方法在 1.3.0 及更早**一直是空转的**：文件名当时是
     *   `md5(baseUrl . 查询串)`，而这里拿 `md5(baseUrl)` 去 `str_starts_with` ——
     *   两个 md5 没有任何包含关系，`unlink` 一次都不会执行。
     *   后果是删数据源时它那份缓存原样留在 1 GB 主机上，只能等 guard 的 LRU GC
     *   按 mtime 收走。改用两段式文件名（见 cacheFile()）之后前缀才真的对得上。
     *
     * 旧命名的残留文件认不出（md5 不可逆），由 GC 回收，或后台勾
     * 「接口响应缓存」一次清光。
     */
    public function clearCache(): void {
        if (!is_dir(CACHE_DIR)) {
            return;
        }
        $prefix = md5($this->baseUrl) . '_';
        foreach ((glob(CACHE_DIR . '/*') ?: []) as $file) {
            $base = basename($file);
            if (!str_starts_with($base, $prefix)) {
                continue;
            }
            if (str_ends_with($base, '.json') || str_ends_with($base, '.neg')) {
                @unlink($file);
            }
        }
    }

    /**
     * 缓存文件名：`<md5(baseUrl)>_<md5(查询串)>.json` —— **前缀就是数据源指纹**。
     *
     * 两段式是 clearCache() 能工作的前提（见其说明）。查询串那段保持 md5，
     * 因为它可能含搜索词、任意 t/ids/pg，不能原样落进文件名。
     */
    private function cacheFile(array $params): string {
        return CACHE_DIR . '/' . md5($this->baseUrl) . '_' . md5(http_build_query($params)) . '.json';
    }

    /**
     * 旧命名（`md5(baseUrl . 查询串)`）→ 新命名，只在新文件不存在时做。
     *
     * 换命名时**不清空**旧缓存：原地改名保住已经暖好的数据，升级后不必重新打一轮上游。
     * 一直没被读到的旧文件保持原样，交给 guard 的 LRU GC（mtime 最旧，最先被收）。
     */
    private function migrateLegacy(string $cacheFile, array $params): void {
        $legacy = CACHE_DIR . '/' . md5($this->baseUrl . http_build_query($params));
        if (is_file($cacheFile) || !is_file($legacy . '.json')) {
            return;
        }
        @rename($legacy . '.json', $cacheFile);
        if (!is_file($this->negFile($cacheFile)) && is_file($legacy . '.neg')) {
            @rename($legacy . '.neg', $this->negFile($cacheFile));
        }
    }

    // ------------------------------------------------------------------

    private function request(array $params, bool $localOnly = false): array {
        if (!is_dir(CACHE_DIR)) {
            @mkdir(CACHE_DIR, 0755, true);
        }
        vhGuardIndex(CACHE_DIR);  // 同上：cache/ 下是上游完整响应，不列目录

        $cacheFile = $this->cacheFile($params);
        $this->migrateLegacy($cacheFile, $params);

        // 搜索词（wd）不落盘：文件名里带着关键词，爬虫每搜一个词就永久多一个文件，
        // 而 TTL 过期只是让内容可被覆盖、文件本身不会消失 —— 这是 1 GB 磁盘上
        // 最快失控的增长链。搜索本就要求实时，落盘价值低、磁盘风险高。
        $persist = !isset($params['wd']) || (string) $params['wd'] === '';

        // 分类数据（ac=list）几乎不变，走长缓存；列表/详情数据走短缓存。
        $ttl = (($params['ac'] ?? '') === 'list') ? CACHE_TTL_TYPE : CACHE_TTL;

        // ---- 读本地（含过期数据）----
        $cached = null;
        $age    = -1;
        if (is_file($cacheFile)) {
            $age    = time() - (int) filemtime($cacheFile);
            $decode = json_decode((string) @file_get_contents($cacheFile), true);
            if (is_array($decode)) {
                $cached = $decode;
            }
        }

        // ① 未过期：最快路径，零出站
        if ($cached !== null && $age < $ttl) {
            $this->lastMsg = '';
            return $cached;
        }

        // ② 只读本地：**已经有数据**（哪怕过期）就直接用，零出站 —— 首页分类走这条。
        //    本地一条都没有时**不许空手返回**，继续往下走正常路径把缓存补上：
        //    否则首页（分类的唯一入口）在接口缓存被清空后会永久空白，且无人能自愈。
        if ($localOnly && $cached !== null) {
            return $cached;
        }

        // ③″ 整页墙钟预算（2026-09-30 线上 502 根治）：
        //    本页已出站（含上面 ①② 的判定本身）超过 PAGE_DEADLINE_BUDGET 时，
        //    **任何出站都不再做**——无论有没有旧数据。旧数据/负缓存都在
        //    下方 ①/③/⑤ 里处理；这里只负责「到点了别再去上游」。
        //    把整页最坏耗时锁在网关（OpenResty ~3s）之内。
        //    起表时机 = 页面入口 pageBudgetStart()；未调用则退化为本页第一个 request()。
        if (self::$pageStart <= 0.0) {
            self::$pageStart = microtime(true);
        }
        $pageRemainMs = (int) (self::PAGE_DEADLINE_BUDGET * 1000)
                       - (int) ((microtime(true) - self::$pageStart) * 1000);
        if ($pageRemainMs <= 0) {
            // 到点：不抛错，走下方 ③ 负缓存判定或直接 ⑤ 兜底，绝不再 httpGet
            $forceNegative = true;
        }

        // ③ 负缓存：上游刚失败过，这段时间内完全不出站
        if (isset($forceNegative) || $this->isNegative($cacheFile)) {
            if (!isset($forceNegative)) {
                $this->markNegative($cacheFile);
            }
            return $cached !== null
                ? $cached                                  // 有过期数据就用
                : $this->emptyResult($params, '接口暂时不可用（' . CACHE_TTL_NEG . ' 秒内不再重试）');
        }

        // ④′ 首页冷补拉的**总时长预算**（只管 $localOnly，即首页那条路径）。
        //     起表时机 = 本次请求里第一个「本地没数据、准备出站」的源；
        //     用满就不再出站 —— 拉不完的交给下一次请求继续，已成功的分类都已落盘、
        //     进度不会丢；本页会被标成残页、不写静态缓存（下一次访问重新渲染）。
        //     最坏耗时 ≈ 预算 + 一次超时 = 6 + 4 = 10 秒，稳稳落在网关超时之内
        //     （没有这一条：N × 4 秒，15 个源 60 秒 → 必然 502）。
        //
        // ⚠️ 1.3.3 修复：预算用满时**必须写负缓存**，否则每次访问都会从头再来 ——
        //     已成功的源走「① 未过期」零出站，但没成功的源既没缓存也没负缓存，
        //     于是**每一次首页访问都要为它们重撞一次 4 秒超时**，直到再次用满预算。
        //     15 个源清理后要反复访问十几次才能补完，期间首页每次打开都卡 6~10 秒，
        //     撞上网关超时就是 502。写负缓存 = 「这次没补上的，60 秒内别再重试」，
        //     下次访问这些源直接跳过，首页秒开，只是分类暂时不全。
        //     （负缓存只针对「自己限速没补上」，上游真的挂了走 ③ 分支，同样处理。）
        if ($localOnly) {
            if (self::$warmStart <= 0.0) {
                self::$warmStart = microtime(true);
                // 起表才读一次配置（此时 setting() 必可用——页面入口都已加载 db.php）
                if (function_exists('setting')) {
                    $this->warmBudget = (float) setting('warm_budget', (string) self::WARM_BUDGET_DEFAULT);
                }
            } elseif ((microtime(true) - self::$warmStart) >= $this->warmBudget) {
                $this->markNegative($cacheFile);   // ★ 关键：别让下次请求再撞一次超时
                return $this->emptyResult($params,
                    '本页分类补拉已达时间预算，剩余的源下次访问会继续补上');
            }
        }

        // ④ 有数据但过期 → 有界刷新：1 秒内能成就用新的，不成就用旧的。
        //    没有数据 → 完整拉取，超时 2 秒。
        //
        $timeout  = $cached !== null ? $this->refreshTimeout : $this->timeout;
        $code = 0;
        $err  = '';
        $body = $this->httpGet($this->buildUrl($params), $code, $err, $timeout,
            isset($forceNegative) ? 0 : $pageRemainMs);

        if ($body !== '') {
            $data = json_decode($body, true);
            if (is_array($data)) {
                $this->lastMsg = '';
                $this->clearNegative($cacheFile);
                // LOCK_EX：并发下两个进程同时写同一缓存文件时，防止写出互相穿插的损坏内容
                if ($persist) {
                    @file_put_contents($cacheFile, $body, LOCK_EX);
                }
                return $data;
            }
        }

        // ⑤ 失败：写负缓存 + 旧数据兜底。这一步是「故障放大器」的解药 ——
        //    没有它，上游宕机期间每一次点击都会重新同步等待（最坏 12 秒 × N 次）。
        $this->markNegative($cacheFile);

        if ($cached !== null) {
            return $cached;
        }
        return $this->emptyResult($params, '接口请求失败：' . $err);
    }

    /**
     * 后台可调的补拉预算（秒）。
     *
     * 写死 6 秒的问题：免费空间网关超时从 3 秒到 30 秒都有，
     * 3 秒超时的主机上 6 秒预算照样撞 502。所以暴露成可配置项。
     *
     * @param float|null $sec 为 null 时读当前值；否则设值并返回自身（链式）
     */
    public function warmBudget(?float $sec = null) {
        if ($sec === null) {
            return $this->warmBudget;
        }
        $this->warmBudget = max(0.0, (float) $sec);
        return $this;
    }

    /** 请求失败时的统一空结构（字段与原实现一致，模板可直接消费） */
    private function emptyResult(array $params, string $msg): array {
        $this->lastMsg = $msg;
        return [
            'code'      => 0,
            'msg'       => $msg,
            'page'      => $params['pg'] ?? 1,
            'pagecount' => 0,
            'limit'     => 20,
            'total'     => 0,
            'list'      => [],
            'class'     => [],
        ];
    }

    /** 负缓存文件（与缓存文件同名、后缀 .neg；不是点文件，才能被 glob 的 GC 收走） */
    private function negFile(string $cacheFile): string {
        return preg_replace('/\.json$/', '.neg', $cacheFile);
    }

    private function isNegative(string $cacheFile): bool {
        $f = $this->negFile($cacheFile);
        if (!is_file($f)) {
            return false;
        }
        return (time() - (int) @filemtime($f)) < CACHE_TTL_NEG;
    }

    private function markNegative(string $cacheFile): void {
        if (!is_dir(CACHE_DIR)) {
            return;
        }
        @file_put_contents($this->negFile($cacheFile), (string) time(), LOCK_EX);
    }

    private function clearNegative(string $cacheFile): void {
        @unlink($this->negFile($cacheFile));
    }

    private function buildUrl(array $params): string {
        $base = $this->baseUrl;
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . http_build_query($params);
    }

    /**
     * @param int $timeout 超时秒数。默认取 $this->timeout（无缓存 2 s / 有旧数据 1 s）；
     *                     过期数据的有界刷新传 1 s。
     */
    private function httpGet(string $url, int &$httpCode = 0, string &$err = '', int $timeout = 0, int $remainMs = 0): string {
        $err = '';
        if (!function_exists('curl_init')) {
            $err = '服务器未安装 curl 扩展';
            return '';
        }
        if ($timeout <= 0) {
            $timeout = $this->timeout;
        }
        // 整页墙钟剩余（ms）：<=0 = 到点了，不再为新数据出站（调用方走 ⑤ 兜底）
        if ($remainMs <= 0) {
            $err = '页面出站预算已用完';
            return '';
        }
        // >0 时把本次出站超时压到 min(原超时, 剩余秒数)：第三个源剩 0.5s 时不再傻等 2s
        $timeout = min($timeout, max(1, (int) ceil($remainMs / 1000)));
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                . 'Chrome/120 Safari/537.36 VodSite/1.0',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($body === false) {
            $err = (string) curl_error($ch);
            $body = '';
        }
        curl_close($ch);
        return $httpCode === 200 ? (string) $body : '';
    }
}
