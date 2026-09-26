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
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';

class VodClient {
    private string $baseUrl;
    private int   $timeout = 12;

    public function __construct(string $baseUrl) {
        $this->baseUrl = trim($baseUrl);
    }

    /** 获取分类列表 */
    public function getTypes(): array {
        $data = $this->request(['ac' => 'list']);
        $types = $data['class'] ?? [];
        if (!is_array($types)) {
            return [];
        }
        // 兼容 type_pid 缺失的情况，统一排序
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

    private function request(array $params): array {
        if (!is_dir(CACHE_DIR)) {
            mkdir(CACHE_DIR, 0755, true);
        }
        $cacheFile = CACHE_DIR . '/' . md5($this->baseUrl . http_build_query($params)) . '.json';

        // 分类数据（ac=list）几乎不变，走长缓存；列表/详情数据走短缓存。
        // 分开的目的是避免分类请求被列表的 30 分钟 TTL 拖累，减少上游请求。
        $ttl = (($params['ac'] ?? '') === 'list') ? CACHE_TTL_TYPE : CACHE_TTL;

        // 命中缓存直接返回
        if (is_file($cacheFile) && (time() - filemtime($cacheFile) < $ttl)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        // 请求上游
        $code = 0;
        $err = '';
        $body = $this->httpGet($this->buildUrl($params), $code, $err);
        if ($body !== '') {
            $data = json_decode($body, true);
            if (is_array($data)) {
                // LOCK_EX：并发下两个进程同时写同一缓存文件时，防止写出互相穿插的损坏内容。
                // 单用户场景概率低，但写坏一次的后果是 json_decode 失败、页面走降级分支。
                @file_put_contents($cacheFile, $body, LOCK_EX);
                return $data;
            }
        }

        // 失败降级：有旧缓存用旧缓存（注意用长 TTL 判断，分类缓存旧一点也可用）
        if (is_file($cacheFile)) {
            $cached = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                return $cached;
            }
        }

        return [
            'code'      => 0,
            'msg'       => $body === '' ? '接口请求失败：' . $err : '返回数据无法解析',
            'page'      => $params['pg'] ?? 1,
            'pagecount' => 0,
            'limit'     => 20,
            'total'     => 0,
            'list'      => [],
            'class'     => [],
        ];
    }

    private function buildUrl(array $params): string {
        $base = $this->baseUrl;
        $sep = str_contains($base, '?') ? '&' : '?';
        return $base . $sep . http_build_query($params);
    }

    private function httpGet(string $url, int &$httpCode = 0, string &$err = ''): string {
        $err = '';
        if (!function_exists('curl_init')) {
            $err = '服务器未安装 curl 扩展';
            return '';
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
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
