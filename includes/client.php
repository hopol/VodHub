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

// 1.3.0 在 config.php 里新增的常量兜底 —— 升级包漏传 config.php 时，
// 旧版没有它，而 request() 的负缓存分支每条失败请求都会读一次，
// 不兜底就是「上游一挂、前台全 500」。同 guard.php 的理由：
// 可选的优化常量不该有把整站打死的权限。
if (!defined('CACHE_TTL_NEG')) {
    define('CACHE_TTL_NEG', 60);
}

class VodClient {
    private string $baseUrl;
    /** 从未有过数据时的完整拉取超时 */
    private int   $timeout = 4;
    /** 已有过期数据时的有界刷新超时 */
    private int   $refreshTimeout = 1;

    public function __construct(string $baseUrl) {
        $this->baseUrl = trim($baseUrl);
    }

    /** 获取分类列表 */
    public function getTypes(): array {
        return $this->sortTypes($this->request(['ac' => 'list'])['class'] ?? []);
    }

    /**
     * **只读**本地分类缓存（即使已过期也用，永不出站）。
     *
     * 首页用：模板原本对**每个数据源**串行调一次 getTypes()，
     * 冷缓存最坏 N × 12 秒，5 个源就是 60 秒 —— 必然超过免费主机
     * 15~30 秒的网关超时，表现为首页 504。而分类数据 24 小时不怎么变，
     * 显示一小时前的分类列表用户根本察觉不到。
     * 刷新交给访问缓存过期后的正常路径（那里有 1 秒有界刷新兜着）。
     */
    public function getTypesLocal(): array {
        return $this->sortTypes($this->request(['ac' => 'list'], true)['class'] ?? []);
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

    /** 清空该源的缓存 */
    public function clearCache(): void {
        $prefix = md5($this->baseUrl);
        foreach ((glob(CACHE_DIR . '/*.json') ?: []) as $file) {
            if (str_starts_with(basename($file), $prefix)) {
                @unlink($file);
            }
        }
    }

    // ------------------------------------------------------------------

    private function request(array $params, bool $localOnly = false): array {
        if (!is_dir(CACHE_DIR)) {
            @mkdir(CACHE_DIR, 0755, true);
        }
        $cacheFile = CACHE_DIR . '/' . md5($this->baseUrl . http_build_query($params)) . '.json';

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
            return $cached;
        }

        // ② 只读本地：永不出站（首页分类走这条）
        if ($localOnly) {
            return $cached !== null
                ? $cached
                : $this->emptyResult($params, '本地尚无分类缓存');
        }

        // ③ 负缓存：上游刚失败过，这段时间内完全不出站
        if ($this->isNegative($cacheFile)) {
            return $cached !== null
                ? $cached                                  // 有过期数据就用
                : $this->emptyResult($params, '接口暂时不可用（' . CACHE_TTL_NEG . ' 秒内不再重试）');
        }

        // ④ 有数据但过期 → 有界刷新：1 秒内能成就用新的，不成就用旧的。
        //    没有数据 → 完整拉取，超时 4 秒。
        $timeout  = $cached !== null ? $this->refreshTimeout : $this->timeout;
        $code = 0;
        $err  = '';
        $body = $this->httpGet($this->buildUrl($params), $code, $err, $timeout);

        if ($body !== '') {
            $data = json_decode($body, true);
            if (is_array($data)) {
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

    /** 请求失败时的统一空结构（字段与原实现一致，模板可直接消费） */
    private function emptyResult(array $params, string $msg): array {
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
     * @param int $timeout 超时秒数。默认取 $this->timeout（4 s）；
     *                     过期数据的有界刷新传 1 s。
     */
    private function httpGet(string $url, int &$httpCode = 0, string &$err = '', int $timeout = 0): string {
        $err = '';
        if (!function_exists('curl_init')) {
            $err = '服务器未安装 curl 扩展';
            return '';
        }
        if ($timeout <= 0) {
            $timeout = $this->timeout;
        }
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
