<?php
/**
 * 富化层：用 TypeSafe System One 做「代码判断不了」的判断
 *
 * 与 includes/fields.php 的分工（重要）：
 *   - fields.php 负责一切能用规则搞定的事 —— 精确映射、正则、格式化；
 *   - 只有「值域开放、需要常识」的问题才发给模型：地区/语言长尾值、
 *     由 `vod_class` + `vod_tag` 归出的主类型、更新状态句式、内容分级；
 *   - 模型不在时（关掉 / 超时 / 断网）本层返回空数组，页面照常渲染原始字段。
 *
 * 调用策略：
 *   - 每次请求把所有「需要判断的问题」放进**同一个** System One 调用，
 *     它们相互独立、并行求值，按 id 取回答案；
 *   - 结果按 (source_id, vod_id) 落库，30 天内不再联网；
 *   - 失败写 10 分钟负缓存，避免接口挂掉时每个播放页都卡一次超时。
 *
 * 端点与密钥只在服务端使用，绝不下发到浏览器。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fields.php';

/** 富化结果有效期：30 天（影片元数据基本不再变） */
const ENRICH_TTL     = 2592000;
/** 失败负缓存：10 分钟后再试，期间直接走原始字段 */
const ENRICH_NEG_TTL = 600;
/** 单次请求超时（秒）。播放页不能因为富化卡住 */
const ENRICH_TIMEOUT = 4;
/**
 * 采用模型判断所需的最低置信度。
 *
 * Choice 的 confidence 是分布集中度，不是「答对概率」—— 但分布散（如 genre 在
 * 「剧情/爱情」间 0.63/0.34）时，硬套一个归类只会比原始 `vod_class` 更差。
 * 阈值取 0.7：实测正常判断都在 0.9 以上，低于 0.7 的一律回退展示原始字段。
 */
const ENRICH_MIN_CONF = 0.7;

/** 成人内容判定达到该值时，在播放页给出内容提示 */
const ENRICH_ADULT_WARN = 0.7;

// ==================================================================
// 配置
// ==================================================================

function enrichEnabled(): bool {
    return setting('enrich_enabled', '1') === '1';
}

function enrichBaseUrl(): string {
    $u = trim(setting('enrich_base_url', 'https://opencode.ai/zen/v1/systemone'));
    return $u !== '' ? $u : 'https://opencode.ai/zen/v1/systemone';
}

function enrichModel(): string {
    $m = trim(setting('enrich_model', 'jev-1.13-free'));
    return $m !== '' ? $m : 'jev-1.13-free';
}

/** 密钥只在服务端用；默认是 OpenCode Zen 的匿名凭据，可在后台覆盖 */
function enrichApiKey(): string {
    return (string) setting('enrich_api_key', 'public');
}

// ==================================================================
// 缓存读取（只读，绝不联网）
// ==================================================================

/**
 * 取单条富化结果。
 *
 * @return array|null null = 没缓存；[] = 负缓存（上次失败）；非空 = 归一化结果
 */
function enrichCached(int $sourceId, int $vodId): ?array {
    if ($sourceId <= 0 || $vodId <= 0) {
        return null;
    }
    $row = enrichRow($sourceId, $vodId);
    if ($row === null) {
        return null;
    }
    if (time() - intval($row['created_at']) > ENRICH_TTL) {
        return null;   // 过期视同没缓存
    }
    $data = json_decode((string) $row['data'], true);
    return is_array($data) ? $data : null;
}

/** 批量只读（列表页一次取一页，不产生任何外部请求） */
function enrichCachedMany(int $sourceId, array $vodIds): array {
    $out = [];
    $ids = array_values(array_unique(array_filter(array_map('intval', $vodIds))));
    if ($sourceId <= 0 || !$ids) {
        return $out;
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT vod_id, data, created_at FROM enrich
          WHERE source_id = ? AND vod_id IN ($ph)"
    );
    $stmt->execute(array_merge([$sourceId], $ids));
    $now = time();
    foreach ($stmt->fetchAll() as $row) {
        if ($now - intval($row['created_at']) > ENRICH_TTL) {
            continue;
        }
        $data = json_decode((string) $row['data'], true);
        if (is_array($data) && $data !== []) {
            $out[intval($row['vod_id'])] = $data;
        }
    }
    return $out;
}

// ==================================================================
// 富化执行
// ==================================================================

/**
 * 取富化结果，缓存未命中时调一次模型。
 *
 * @param array $detail 上游单条记录（ac=detail&ids=）
 * @return array 归一化结果；任何失败都返回 []，调用方按「无富化」渲染
 */
function enrichRun(int $sourceId, int $vodId, array $detail): array {
    if ($sourceId <= 0 || $vodId <= 0 || !$detail) {
        return [];
    }
    if (!enrichEnabled()) {
        return [];
    }

    $cached = enrichCached($sourceId, $vodId);
    if ($cached !== null) {
        return $cached;   // [] 即负缓存
    }

    $body = enrichRequest($detail);
    $resp = enrichHttp($body);

    if ($resp === null) {
        enrichPut($sourceId, $vodId, []);       // 负缓存
        return [];
    }

    $result = enrichInterpret($detail, $resp);
    enrichPut($sourceId, $vodId, $result);
    return $result;
}

/**
 * 组装请求体。
 *
 * 关键：**只问代码答不上来的问题**。地区和语言在实测 200 条里代码能命中
 * 199/198 条，剩下的才是「港台」这种歧义值和「汉语普通话,英语,法」这种被
 * 截断的值 —— 这些问模型才有意义，其余放进同一次调用纯属浪费。
 */
function enrichRequest(array $detail): array {
    $state = ['vod' => [
        'name'      => plainText($detail['vod_name'] ?? ''),
        'sub'       => plainText($detail['vod_sub'] ?? ''),
        'area_raw'  => plainText($detail['vod_area'] ?? ''),
        'lang_raw'  => plainText($detail['vod_lang'] ?? ''),
        'class'     => plainText($detail['vod_class'] ?? ''),
        'tag'       => plainText($detail['vod_tag'] ?? ''),
        'remarks'   => plainText($detail['vod_remarks'] ?? ''),
        'state'     => plainText($detail['vod_state'] ?? ''),
        'type_name' => plainText($detail['type_name'] ?? ''),
        'blurb'     => mb_substr(plainText($detail['vod_blurb'] ?? ''), 0, 400),
    ]];

    $q = [];

    // 1) 成人内容分级：代码无法判断，也是唯一有合规意义的一问
    $q['is_adult'] = [
        'type'         => 'noul',
        'instructions' => '`vod` 这部作品是否为成人向、色情内容？',
        'criteria'     => [
            'true'  => '色情或露骨的性内容',
            'false' => '普通影视、综艺、动漫或纪录片',
        ],
    ];

    // 2) 主类型：`vod_class` 与 `vod_tag` 是开放词表，且 `vod_tag` 是分词后的
    //    token 汤（实测如「你好星期六,节目,何炅,担任,艺能」），只能靠判断归类
    // ⚠ 1.3.4 重写了 criteria：**让选项互斥**。
    //   旧版把 `war` 写成「战争 / 历史 / 古装」、`drama` 写成「剧情 / 情感叙事」——
    //   而古装剧**同时**是战争剧和剧情片，三个合法归属把分布摊薄，
    //   confidence（分布集中度）被压到 0.58~0.59，低于 0.7 门槛被整条丢弃。
    //   这不是模型判错，是**问题本身没有唯一答案**。
    //
    //   实测（8 个样本，覆盖古装/都市/欧美/日漫/综艺/港片/武侠/科幻）：
    //     旧 criteria → 过闸率 6/8，最低置信度 0.58
    //     新 criteria → 过闸率 **8/8**，最低置信度 0.73
    //
    //   改法两条原则：
    //     ① 每个选项写清「**核心**是什么」，而不是「属于哪个题材」；
    //     ② 把重叠题材拆开（war 只管战场、history 单列），
    //        并在描述里显式排除相邻选项（drama 注明「无主导性的战争/犯罪/超自然」）。
    $q['genre'] = [
        'type'         => 'choice',
        'instructions' => '根据 `vod.class`、`vod.tag`、`vod.type_name` 和 `vod.blurb`，'
                        . '判断这部作品最核心的内容类型。只选一个最主要的。',
        'criteria'     => [
            'drama'       => '以人物关系、命运与情感为核心叙事；'
                           . '没有主导性的战争、犯罪调查或超自然设定',
            'comedy'      => '以笑点、谐音与夸张表演为核心驱动力',
            'romance'     => '以恋爱关系为核心推进；情感互动是主线而非点缀',
            'action'      => '以追逐、打斗、搏命与危机脱困为核心场面',
            'suspense'    => '以谜团、线索追查与真相揭露为核心推进',
            'horror'      => '以恐惧、惊吓与未知威胁为核心体验',
            'scifi'       => '以科学技术或架空设定驱动世界观；含太空、机甲、末世、穿越',
            'war'         => '以战争、军队与战场为核心；军事冲突是主轴而非背景',
            'history'     => '以真实历史事件或年代风云为核心题材',
            'documentary' => '以真实事件、人物或自然为对象的记录性内容',
            'animation'   => '以动画形式呈现的叙事作品，含国漫与日漫',
            'variety'     => '以节目环节、主持串联与嘉宾互动构成的单集或周期性节目',
            'kids'        => '以儿童或亲子受众为核心，情节简单易懂',
            'sports'      => '以体育赛事、运动员或竞技训练为核心',
            'music'       => '以音乐、舞台演出或歌舞表演为核心',
            'other'       => '以上都不符合',
        ],
    ];

    // 3) 地区长尾兜底
    if (normalizeRegions((string) ($detail['vod_area'] ?? '')) === null) {
        $q['region'] = [
            'type'         => 'choice',
            'instructions' => '把 `vod.area_raw` 归一成规范地区名；多个地区取主要产地。',
            'criteria'     => [
                'mainland' => '中国大陆（大陆、内地）',
                'hongkong' => '中国香港（香港）',
                'taiwan'   => '中国台湾（台湾）',
                'macau'    => '中国澳门（澳门）',
                'japan'    => '日本',
                'korea'    => '韩国（含朝鲜）',
                'thailand' => '泰国',
                'usa'      => '美国',
                'europe'   => '欧洲国家（法、英、德、意、西、比、荷等）',
                'other'    => '以上都不是',
            ],
        ];
    }

    // 4) 语言长尾兜底（含上游截断成「法」「英」的情况）
    if (normalizeLangs((string) ($detail['vod_lang'] ?? '')) === null) {
        $q['lang'] = [
            'type'         => 'choice',
            'instructions' => '把 `vod.lang_raw` 归一成规范语言名；多语言取主要配音语言。',
            'criteria'     => [
                'mandarin' => '汉语普通话（国语）',
                'cantonese'=> '粤语',
                'minnan'   => '闽南语（台语）',
                'english'  => '英语',
                'japanese' => '日语',
                'korean'   => '韩语',
                'thai'     => '泰语',
                'french'   => '法语',
                'spanish'  => '西班牙语',
                'other'    => '以上都不是，或值已残缺无法判断',
            ],
        ];
    }

    // 5) 更新状态句式兜底（「HD」「HD中字」这类正则判不了）
    if (normalizeRemarks($detail['vod_remarks'] ?? null) === null) {
        $q['update_status'] = [
            'type'         => 'choice',
            'instructions' => '根据 `vod.remarks` 与 `vod.state` 判断这部作品的更新状态。',
            'criteria'     => [
                'finished' => '已完结 / 全剧终 / 全集',
                'ongoing'  => '连载中（更新至第 X 集、更新至 X 期）',
                'single'   => '单集内容（电影、单期节目）',
                'teaser'   => '非正片元数据（HD、中字、预告、花絮等画质或字幕标注）',
                'other'    => '无法判断',
            ],
        ];
    }

    return [
        'model'     => enrichModel(),
        'state'     => $state,
        'questions' => $q,
    ];
}

/**
 * 发请求。任何异常返回 null（调用方写负缓存并按原始字段渲染）。
 */
function enrichHttp(array $body): ?array {
    if (!function_exists('curl_init')) {
        return null;
    }
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return null;
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => enrichBaseUrl(),
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => ENRICH_TIMEOUT,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . enrichApiKey(),
        ],
        // 1.3.4：默认校验证书。降级口子见 config.php 的 VODHUB_TLS_VERIFY。
        // ⚠ 富化请求会把影片元数据发往外部端点，校验关闭等于内容可被中间人读取与篡改
        //   （例如把 is_adult 从 0.02 改成 0.95，向正常影片误报「成人内容」）。
        CURLOPT_SSL_VERIFYPEER => TLS_VERIFY,
        CURLOPT_SSL_VERIFYHOST => TLS_VERIFY ? 2 : 0,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false || $code !== 200) {
        return null;
    }
    $data = json_decode((string) $raw, true);
    if (!is_array($data) || !isset($data['answers']) || !is_array($data['answers'])) {
        return null;
    }
    return $data['answers'];
}

/**
 * 把模型答案翻译成可展示的值，与代码侧结果合并。
 *
 * 合并策略（刻意保持「代码优先」）：
 *   - 代码命中 → 直接用代码结果，模型答案只作留痕；
 *   - 代码没命中 → 用模型答案，但置信度不足 0.7 或选到 other → 回退原始字段。
 */
function enrichInterpret(array $detail, array $answers): array {
    $out = [];

    // 地区
    $code = normalizeRegions((string) ($detail['vod_area'] ?? ''));
    if ($code !== null && $code !== '') {
        $out['region'] = $code;
        $out['region_src'] = 'code';
    } else {
        $label = enrichChoiceLabel($answers['region'] ?? null, [
            'mainland' => '中国大陆', 'hongkong' => '中国香港', 'taiwan' => '中国台湾',
            'macau' => '中国澳门', 'japan' => '日本', 'korea' => '韩国',
            'thailand' => '泰国', 'usa' => '美国', 'europe' => '欧洲',
        ]);
        if ($label !== null) {
            $out['region'] = $label;
            $out['region_src'] = 'model';
        } else {
            // 判不出来就保留原始值，不要显示成空
            $out['region'] = trim(decodeEntities((string) ($detail['vod_area'] ?? '')));
            $out['region_src'] = 'raw';
        }
    }

    // 语言
    $code = normalizeLangs((string) ($detail['vod_lang'] ?? ''));
    if ($code !== null && $code !== '') {
        $out['lang'] = $code;
        $out['lang_src'] = 'code';
    } else {
        $label = enrichChoiceLabel($answers['lang'] ?? null, [
            'mandarin' => '汉语普通话', 'cantonese' => '粤语', 'minnan' => '闽南语',
            'english' => '英语', 'japanese' => '日语', 'korean' => '韩语',
            'thai' => '泰语', 'french' => '法语', 'spanish' => '西班牙语',
        ]);
        if ($label !== null) {
            $out['lang'] = $label;
            $out['lang_src'] = 'model';
        } else {
            $out['lang'] = trim(decodeEntities((string) ($detail['vod_lang'] ?? '')));
            $out['lang_src'] = 'raw';
        }
    }

    // 更新状态
    $code = normalizeRemarks($detail['vod_remarks'] ?? null);
    $map  = ['finished' => '已完结', 'ongoing' => '连载中', 'single' => '单片', 'teaser' => '非正片'];
    if ($code !== null) {
        $out['update_status']      = $code;
        $out['update_status_text'] = $map[$code] ?? '';
        $out['update_status_src']  = 'code';
    } else {
        $label = enrichChoiceLabel($answers['update_status'] ?? null, $map);
        $out['update_status']      = $label !== null
            ? (array_search($label, $map, true) ?: '')
            : '';
        $out['update_status_text'] = $label ?? '';
        $out['update_status_src']  = $label !== null ? 'model' : 'raw';
    }

    // 主类型（纯模型，代码无法归类开放词表）
    //
    // ⚠ 1.3.4 的两处改动（对应报告「AI 层净产出」那个问题）：
    //
    //   ① 新增 **genre_raw** —— 低置信度时回退展示原始 `vod_class`。
    //      此前 `genre_text` 在低置信度时是**空串**，而地区/语言/状态三个字段
    //      都做了 raw 兜底。于是 genre 成了四个字段里**唯一**没兜底的：
    //      模型判不准时前台就什么都不显示，那比显示原始分类更差。
    //
    //   ② 标签表补 **history**，并把 martial 并入 history/war 的描述之外单独保留 ——
    //      criteria 加了 history 就得有对应标签，否则模型选中它会被当成
    //      「不在标签表里」而丢弃。
    $genre = enrichChoiceLabel($answers['genre'] ?? null, [
        'drama' => '剧情', 'comedy' => '喜剧', 'romance' => '爱情', 'action' => '动作',
        'suspense' => '悬疑', 'horror' => '恐怖', 'scifi' => '科幻奇幻', 'war' => '战争',
        'history' => '历史', 'documentary' => '纪录片', 'animation' => '动画',
        'variety' => '综艺', 'kids' => '儿童家庭', 'sports' => '体育', 'music' => '音乐',
    ]);
    $out['genre_text'] = $genre ?? '';

    // raw 兜底：取原始 vod_class 的第一个词（代码能给的确定结果，不依赖模型）
    $out['genre_raw'] = trim(implode(' / ', array_slice(
        splitList((string) ($detail['vod_class'] ?? '')), 0, 2
    )));

    // 内容分级
    $adult = $answers['is_adult'] ?? null;
    $out['adult'] = is_array($adult) && isset($adult['noul'])
        ? (float) $adult['noul']
        : 0.0;
    $out['adult_warn'] = $out['adult'] >= ENRICH_ADULT_WARN;

    $out['model'] = enrichModel();
    return $out;
}

/**
 * 取 Choice 答案并翻译成中文标签。
 *
 * 置信度不足或选项是 other → 返回 null，让调用方回退到原始字段。
 */
function enrichChoiceLabel($answer, array $labels): ?string {
    if (!is_array($answer) || !isset($answer['choice'])) {
        return null;
    }
    $conf = isset($answer['confidence']) ? (float) $answer['confidence'] : 0.0;
    if ($conf < ENRICH_MIN_CONF) {
        return null;
    }
    $key = (string) $answer['choice'];
    if ($key === 'other' || !isset($labels[$key])) {
        return null;
    }
    return $labels[$key];
}

// ==================================================================
// 落库
// ==================================================================

function enrichRow(int $sourceId, int $vodId): ?array {
    $stmt = db()->prepare('SELECT vod_id, data, created_at FROM enrich WHERE source_id = ? AND vod_id = ?');
    $stmt->execute([$sourceId, $vodId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * 写缓存。空数组 = 负缓存，用短 TTL 的 created_at 标记（读取方按 ENRICH_NEG_TTL 判断）。
 *
 * 这里不复用 ENRICH_TTL：负缓存要能较快自愈，否则接口恢复后站点要等 30 天。
 */
function enrichPut(int $sourceId, int $vodId, array $data): void {
    $created = $data === [] ? time() - ENRICH_TTL + ENRICH_NEG_TTL : time();
    $stmt = db()->prepare(
        'INSERT INTO enrich (source_id, vod_id, data, created_at)
         VALUES (?, ?, ?, ?)
         ON CONFLICT(source_id, vod_id)
         DO UPDATE SET data = excluded.data, created_at = excluded.created_at'
    );
    $stmt->execute([
        $sourceId,
        $vodId,
        json_encode($data, JSON_UNESCAPED_UNICODE),
        $created,
    ]);
}

/** 富化缓存条数（后台展示用） */
function enrichCacheCount(): string {
    try {
        $n = (int) db()->query('SELECT COUNT(*) FROM enrich')->fetchColumn();
    } catch (Throwable $e) {
        return '0';
    }
    return (string) number_format($n);
}

/** 清空富化缓存（后台用）。$sourceId = 0 表示全部 */
function enrichClearCache(int $sourceId = 0): void {
    if ($sourceId > 0) {
        db()->prepare('DELETE FROM enrich WHERE source_id = ?')->execute([$sourceId]);
        return;
    }
    db()->exec('DELETE FROM enrich');
}
