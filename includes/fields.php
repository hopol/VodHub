<?php
/**
 * 字段层：把苹果 CMS provide/vod 的原始字段解析成可直接渲染的值
 *
 * 设计原则（与 includes/enrich.php 的分工）：
 *   - 确定性的解析、映射、格式化全部在这里，不花钱、不联网、可预测；
 *   - 只有「值域开放、需要常识判断」的部分才交给 enrich.php（TypeSafe）；
 *   - enrich 缺席时本层必须独立可用 —— 所有函数都不得依赖 enrich 的结果。
 *
 * 覆盖的原始字段见 docs/api-sources.md「使用了哪些字段」。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';   // cleanTitle / coverUrl / typeName
require_once __DIR__ . '/db.php';           // coverUrl 查 sources.img_proxy

// ==================================================================
// 文本清洗
// ==================================================================

/**
 * 解 HTML 实体。
 *
 * 演员字段里常见 `Frank Cox-O&#039;Connell` 这类实体，直接 split 会把名字拆错。
 */
function decodeEntities(?string $s): string {
    $s = (string) $s;
    if ($s === '') {
        return '';
    }
    return html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * HTML → 纯文本：去标签、解实体、折叠异常空白。
 *
 * 用于 `vod_content` / `vod_blurb` / `vod_actor`。上游简介带 `<p>`、`<br/>`、
 * `&nbsp;` 与全角空格（U+3000），直接转义后用户会看到字面量的 `<p>`，
 * 直接输出又会引入标签 —— 所以统一压成纯文本再交给 `h()`。
 */
function plainText(?string $s): string {
    $s = decodeEntities((string) $s);
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/<[^>]*>/u', ' ', $s) ?? $s;   // 标签 → 空格，避免前后词粘连
    $s = str_replace(["\xc2\xa0", "\xe3\x80\x80"], ' ', $s); // &nbsp; / 全角空格
    $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * 拆分多值字段。
 *
 * 分隔符按实测取：`vod_class` 用英文逗号，`vod_area` 用 " / "，
 * `vod_lang` / `vod_actor` 用英文或中文逗号，`vod_sub` 用 " / "。
 * 注意 `vod_class` 有 93/200 是单个词（无分隔符），此时整串即一个值。
 */
function splitList(?string $s): array {
    $s = trim(decodeEntities((string) $s));
    if ($s === '' || $s === '暂无' || $s === '0') {
        return [];
    }
    $parts = preg_split('/\s*[,，、;；\/／|｜]\s*/u', $s) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim(preg_replace('/\s+/u', ' ', $p) ?? $p);
        if ($p === '' || $p === '暂无' || $p === '未知') {
            continue;
        }
        $out[] = $p;
    }
    return array_values(array_unique($out));
}

// ==================================================================
// 标量解析
// ==================================================================

/**
 * 上映日期解析。
 *
 * 实测格式 `2026-09-16(美国)`，也有 `2026`、`2026-09-16`。
 * 返回 ['date' => '2026-09-16', 'country' => '美国', 'year' => '2026']，字段缺失时为空串。
 */
function parsePubdate(?string $s): array {
    $raw  = trim(decodeEntities((string) $s));
    $out  = ['date' => '', 'country' => '', 'year' => ''];
    if ($raw === '' || $raw === '暂无') {
        return $out;
    }
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})\s*[（(]([^）)]+)[）)]/u', $raw, $m)) {
        $out['date']    = $m[1] . '-' . $m[2] . '-' . $m[3];
        $out['country'] = trim($m[4]);
        $out['year']    = $m[1];
    } elseif (preg_match('/(\d{4})-(\d{2})-(\d{2})/u', $raw, $m)) {
        $out['date'] = $m[1] . '-' . $m[2] . '-' . $m[3];
        $out['year'] = $m[1];
    } elseif (preg_match('/(\d{4})/u', $raw, $m)) {
        $out['year'] = $m[1];
        $out['date'] = $m[1];
    } else {
        $out['date'] = $raw;
    }
    return $out;
}

/** 豆瓣条目页地址；`vod_douban_id` 为 0 时返回空串 */
function doubanUrl($id): string {
    $id = intval($id);
    return $id > 0 ? 'https://movie.douban.com/subject/' . $id . '/' : '';
}

/**
 * 有效评分。
 *
 * 本源 `vod_score` 12% 非零、`vod_douban_score` 11% 非零，两者填充互补，
 * 所以先看 `vod_score`，不行再回退 `vod_douban_score`，都没有返回 0。
 */
function scoreOf(array $item): float {
    $a = (float) ($item['vod_score'] ?? 0);
    if ($a > 0) {
        return $a;
    }
    $b = (float) ($item['vod_douban_score'] ?? 0);
    if ($b > 0) {
        return $b;
    }
    // 再兜一层：`vod_score_all`（累计分）与 `vod_score_num`（评分人数）求均分。
    // 这两个字段单独看没意义，但合起来是真实评分（实测 414/69 = 6.0、1232/154 = 8.0）。
    $sum = (float) ($item['vod_score_all'] ?? 0);
    $num = (float) ($item['vod_score_num'] ?? 0);
    if ($sum > 0 && $num > 0) {
        return round($sum / $num, 1);
    }
    return 0.0;
}

/**
 * 卡片角标文案。
 *
 * 优先级按实测填充率排：`vod_remarks` 100%（"更新至第08集" / "已完结"）
 * > `vod_state` 23%（"正片"）> `vod_duration` 22%。
 * 原先一律显示时长，在本源上 78% 会是「未知」。
 */
function badgeOf(array $item): string {
    $remarks = trim(decodeEntities((string) ($item['vod_remarks'] ?? '')));
    if ($remarks !== '' && $remarks !== '暂无') {
        return $remarks;
    }
    $state = trim(decodeEntities((string) ($item['vod_state'] ?? '')));
    if ($state !== '' && $state !== '暂无') {
        return $state;
    }
    return formatDuration($item['vod_duration'] ?? '');
}

/** 相对时间：`vod_time_add`（Unix 秒）→ 「刚刚 / 3小时前 / 5天前 / 2026-09-24」 */
function timeAgo($ts): string {
    $ts = intval($ts);
    if ($ts <= 0) {
        return '';
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        $diff = 0;
    }
    if ($diff < 60)      return '刚刚';
    if ($diff < 3600)    return intdiv($diff, 60) . ' 分钟前';
    if ($diff < 86400)   return intdiv($diff, 3600) . ' 小时前';
    if ($diff < 86400 * 30) return intdiv($diff, 86400) . ' 天前';
    return date('Y-m-d', $ts);
}

/**
 * 集数汇总：`vod_total`（总集数，37% 填充）+ `vod_play_url` 实际解析出的集数。
 *
 * 上游两个字段经常对不上（`vod_total` 是全剧总集数，`vod_play_url` 只有已更新的），
 * 所以一起算，取较大者作为「共 N 集」，并把已更新集数单独报出来。
 */
function episodes(array $detail, int $parsedCount = 0): array {
    $total   = max(0, intval($detail['vod_total'] ?? 0));
    $updated = max(0, $parsedCount);
    $known   = max($total, $updated);
    $label   = '';
    if ($known > 0) {
        $label = '共 ' . $known . ' 集';
        if ($total > 0 && $updated > 0 && $updated < $total) {
            $label .= '（已更新 ' . $updated . '）';
        }
    }
    return ['total' => $total, 'updated' => $updated, 'label' => $label];
}

// ==================================================================
// 代码侧归一化（确定性映射；返回 null 表示「本层判断不了，交给模型」）
// ==================================================================

/**
 * 地区归一化。
 *
 * 上游同一站点内值就不统一（实测「中国大陆」48 次 vs「大陆」43 次，
 * 「台湾」8 次 vs「中国台湾」6 次），多地区用 " / " 连接。
 * 返回 null 时由 enrich.php 兜底；返回非 null 时直接采用，不联网。
 */
function normalizeRegions(string $raw): ?string {
    static $map = [
        '中国大陆' => '中国大陆', '大陆' => '中国大陆', '内地' => '中国大陆', '中国' => '中国大陆',
        '中国香港' => '中国香港', '香港' => '中国香港',
        '中国台湾' => '中国台湾', '台湾' => '中国台湾',
        '中国澳门' => '中国澳门', '澳门' => '中国澳门',
        '日本' => '日本', '韩国' => '韩国', '朝鲜' => '朝鲜', '泰国' => '泰国',
        '美国' => '美国', '加拿大' => '加拿大', '英国' => '英国', '法国' => '法国',
        '德国' => '德国', '意大利' => '意大利', '西班牙' => '西班牙', '印度' => '印度',
        '俄罗斯' => '俄罗斯', '澳大利亚' => '澳大利亚', '新西兰' => '新西兰',
        '荷兰' => '荷兰', '比利时' => '比利时', '瑞典' => '瑞典', '丹麦' => '丹麦', '挪威' => '挪威',
        '波兰' => '波兰', '土耳其' => '土耳其', '伊朗' => '伊朗', '以色列' => '以色列',
        '墨西哥' => '墨西哥', '巴西' => '巴西', '阿根廷' => '阿根廷', '智利' => '智利',
        '南非' => '南非', '埃及' => '埃及', '新加坡' => '新加坡', '马来西亚' => '马来西亚',
        '印度尼西亚' => '印度尼西亚', '菲律宾' => '菲律宾', '越南' => '越南',
    ];
    $raw = trim($raw);
    if ($raw === '' || $raw === '暂无') {
        return '';          // 没数据 → 不展示；'看不懂'才是 null
    }
    // 聚合站常用合称：先展开成多个地区再逐个归一，比丢给模型猜更准
    static $expand = [
        '港台'   => '中国香港,中国台湾',
        '港澳台' => '中国香港,中国澳门,中国台湾',
        '欧美'   => '美国,英国,法国,德国',
        '日韩'   => '日本,韩国',
        '东南亚' => '泰国,新加坡,马来西亚,印度尼西亚,菲律宾,越南',
    ];
    if (isset($expand[$raw])) {
        $raw = $expand[$raw];
    }
    $parts = splitList($raw);
    if (!$parts) {
        return '';
    }
    $out = [];
    foreach ($parts as $p) {
        if (!isset($map[$p])) {
            return null;   // 有一个不认识就整体交给模型，避免半生不熟的拼接
        }
        $out[] = $map[$p];
    }
    $out = array_values(array_unique($out));
    return $out ? implode(' / ', $out) : null;
}

/**
 * 语言归一化。与地区同理：「国语」57 次 vs「汉语普通话」47 次指同一件事。
 * 上游还会截断成「法」「英」这种单字 —— 这类交给模型。
 */
function normalizeLangs(string $raw): ?string {
    static $map = [
        '国语' => '汉语普通话', '普通话' => '汉语普通话', '汉语普通话' => '汉语普通话',
        '中文' => '汉语普通话', '华语' => '汉语普通话',
        '粤语' => '粤语', '广东话' => '粤语',
        '闽南语' => '闽南语', '台语' => '闽南语', '台湾话' => '闽南语',
        '客家话' => '客家话', '四川话' => '四川话', '上海话' => '上海话',
        '日语' => '日语', '英语' => '英语', '韩语' => '韩语', '朝鲜语' => '韩语',
        '泰语' => '泰语', '法语' => '法语', '德语' => '德语', '俄语' => '俄语',
        '西班牙语' => '西班牙语', '葡萄牙语' => '葡萄牙语', '意大利语' => '意大利语',
        '印地语' => '印地语', '阿拉伯语' => '阿拉伯语', '马来语' => '马来语',
        '其它' => '其它', '其他' => '其它',
    ];
    $raw = trim($raw);
    if ($raw === '' || $raw === '暂无') {
        return '';
    }
    $parts = splitList($raw);
    if (!$parts) {
        return '';
    }
    $out = [];
    foreach ($parts as $p) {
        if (!isset($map[$p])) {
            return null;
        }
        $out[] = $map[$p];
    }
    $out = array_values(array_unique($out));
    return $out ? implode(' / ', $out) : null;
}

/**
 * 更新状态归一化 → 'finished' | 'ongoing' | null。
 *
 * 实测 `vod_remarks` 有 66 种取值，但句式规整：
 * 「已完结」「更新至20260926期」「更新至第08集」能直接判；「HD」「HD中字」判不了，交模型。
 *
 * ⚠ 参数声明为 **?string 而不是 string**（1.3.4 起）：
 *   `vod_remarks` 缺失时 `?? ''` 给的是空串，但上游偶尔直接给 null ——
 *   而签名只收 string 时，**任何一处调用方忘了 (string) 强转就是整站 500**
 *   （TypeError 是致命错误，不是警告）。
 *   此前三处调用点全靠 `(string) (...)` 强转兜着，属于「必须由每个调用方
 *   记得强转」的隐式契约；现在由类型系统兜住，调用方也一并简化。
 */
function normalizeRemarks(?string $raw): ?string {
    $s = trim(decodeEntities((string) $raw));
    if ($s === '') {
        return null;
    }
    if (preg_match('/已完结|全剧终|完结|全集|大结局/u', $s)) {
        return 'finished';
    }
    if (preg_match('/更新至|连载中|播出至|更新\b/u', $s)) {
        return 'ongoing';
    }
    return null;
}

// ==================================================================
// 展示组装
// ==================================================================

/**
 * 本地筛选用的可检索文本。
 *
 * 上游 `wd` 参数只匹配 `vod_name`（实测 `wd=xianvneili`、`wd=GELBOYS` 均返回 0 条），
 * 所以别名 `vod_sub`、拼音 `vod_en`、首字母 `vod_letter`、演员、导演、编剧、标签
 * 这些字段在站内检索里原本完全用不上 —— 前端筛选框按这里拼的串匹配。
 */
function searchHaystack(array $item): string {
    $buf = [];
    foreach (['vod_name', 'vod_sub', 'vod_en', 'vod_letter',
              'vod_actor', 'vod_director', 'vod_writer', 'vod_class', 'vod_tag'] as $k) {
        $v = plainText($item[$k] ?? '');
        if ($v !== '') {
            $buf[] = $v;
        }
    }
    $tn = trim((string) ($item['type_name'] ?? ''));
    if ($tn !== '') {
        $buf[] = $tn;
    }
    // 统一小写，让 `GELBOYS` / `gelboys` 都能命中；全角括号一并折叠
    $s = mb_strtolower(implode(' ', $buf), 'UTF-8');
    $s = str_replace(['（', '）', '【', '】'], ['(', ')', '[', ']'], $s);
    return $s;
}

/**
 * 组装播放页元数据。
 *
 * 一次性把该用的字段算完，模板只负责排版。返回值里每个键都可能是空串 ——
 * 模板按「非空才渲染」处理，字段缺失（本源填充率各不相同）时自然消失，不留占位。
 *
 * @param array      $detail  上游单条记录（ac=detail&ids=）
 * @param int        $sourceId 数据源 id，封面代理要用
 * @param array      $types   分类数组（用于 type_name 回退）
 * @param array|null $enrich  enrich.php 的归一化结果，null 表示没开或未缓存
 */
function buildMeta(array $detail, int $sourceId, array $types = [], ?array $enrich = null): array {
    $enrich = is_array($enrich) ? $enrich : [];

    $pub = parsePubdate($detail['vod_pubdate'] ?? '');

    // 优先用模型归一化的值（带 confidence，低置信度时 enrich 会回填原始值）
    $region = trim((string) ($enrich['region'] ?? '')) ?: (string) (normalizeRegions((string) ($detail['vod_area'] ?? '')) ?? '');
    $lang   = trim((string) ($enrich['lang'] ?? ''))   ?: (string) (normalizeLangs((string) ($detail['vod_lang'] ?? '')) ?? '');

    $status = trim((string) ($enrich['update_status_text'] ?? ''));
    if ($status === '') {
        $code = normalizeRemarks($detail['vod_remarks'] ?? null);
        $status = match ($code) {
            'finished' => '已完结',
            'ongoing'  => '连载中',
            default    => '',
        };
    }

    $actors    = splitList($detail['vod_actor'] ?? '');
    $genres    = array_merge(splitList($detail['vod_class'] ?? ''), splitList($detail['vod_tag'] ?? ''));
    $genres    = array_values(array_unique($genres));
    $alias     = splitList($detail['vod_sub'] ?? '');
    $typeId1   = intval($detail['type_id_1'] ?? 0);
    $typeName1 = $typeId1 > 0 ? typeName($types, $typeId1) : '';
    if ($typeName1 === '全部') {
        $typeName1 = '';
    }

    // \`vod_content\` 是详细内容、\`vod_blurb\` 是摘要，两者都是 97% 填充但都可能被截断或
    // 是空壳。按「清洗后更长的那份」择优，而不是无条件用 content —— 本源两者高度
    // 重合，别的源常见 content 只剩半句。
    $blurb   = plainText($detail['vod_blurb'] ?? '');
    $content = plainText($detail['vod_content'] ?? '');
    if ($blurb === '暂无')   { $blurb = ''; }
    if ($content === '暂无') { $content = ''; }
    $desc = ($blurb !== '' && mb_strlen($blurb) > mb_strlen($content))
        ? $blurb
        : ($content !== '' ? $content : $blurb);

    return [
        // —— 标识与链接 ——
        'vod_id'    => intval($detail['vod_id'] ?? 0),
        'source_id' => $sourceId,
        'name'      => cleanTitle($detail['vod_name'] ?? ''),
        'alias'     => implode(' / ', $alias),
        'sub'       => plainText($detail['vod_sub'] ?? ''),
        'pic'       => coverUrl($detail['vod_pic'] ?? '', $sourceId),
        'letter'    => strtoupper(trim((string) ($detail['vod_letter'] ?? ''))),
        'pinyin'    => trim((string) ($detail['vod_en'] ?? '')),

        // —— 分类 ——
        'type_id'    => intval($detail['type_id'] ?? 0),
        'type_name'  => trim((string) ($detail['type_name'] ?? '')) ?: typeName($types, intval($detail['type_id'] ?? 0)),
        'type_id_1'  => $typeId1,
        'type_name_1'=> $typeName1,
        'genres'     => $genres,

        // —— 播控元数据 ——
        'year'      => trim((string) ($detail['vod_year'] ?? '')),
        'region'    => $region,
        'lang'      => $lang,
        'pubdate'   => $pub['date'],
        'pubcountry'=> $pub['country'],
        'duration'  => formatDuration($detail['vod_duration'] ?? ''),
        'state'     => trim(decodeEntities((string) ($detail['vod_state'] ?? ''))),
        'version'   => trim(decodeEntities((string) ($detail['vod_version'] ?? ''))),
        'status'    => $status,
        'remarks'   => trim(decodeEntities((string) ($detail['vod_remarks'] ?? ''))),
        'score'     => scoreOf($detail),
        'hits'      => intval($detail['vod_hits'] ?? 0),

        // —— 演职员 ——
        'actors'    => $actors,
        'actor_line'=> implode('、', $actors),
        // 上游导演/编剧也会用逗号串多个人（'しばざきひろき,冈部周悟'），拆开再连
        'director'  => implode('、', splitList(plainText($detail['vod_director'] ?? ''))),
        'writer'    => implode('、', splitList(plainText($detail['vod_writer'] ?? ''))),

        // —— 内容 ——
        'blurb'     => $blurb,
        'content'   => $content,
        'desc'      => $desc,

        // —— 外链与来源 ——
        'douban_id'   => intval($detail['vod_douban_id'] ?? 0),
        'douban_url'  => doubanUrl($detail['vod_douban_id'] ?? 0),
        'douban_score'=> (float) ($detail['vod_douban_score'] ?? 0),
        'play_from'   => trim((string) ($detail['vod_play_from'] ?? '')),
        'play_server' => trim((string) ($detail['vod_play_server'] ?? '')),
        'time_add'    => timeAgo($detail['vod_time_add'] ?? 0),
        'time'        => trim((string) ($detail['vod_time'] ?? '')),
        'status_code' => intval($detail['vod_status'] ?? 1),

        // —— 富化附带 ——
        'adult'       => (float) ($enrich['adult'] ?? 0),
        'adult_warn'  => !empty($enrich['adult_warn']),
        // 归一后的主类型（模型给的），可能为空串
        'genre_main'  => (string) ($enrich['genre_text'] ?? ''),
        // 模型判不准时的兜底：原始 vod_class 前两个词（1.3.4 新增）
        'genre_raw'   => (string) ($enrich['genre_raw'] ?? ''),
        'enriched'    => $enrich !== [],
    ];
}
