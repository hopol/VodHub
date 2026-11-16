<?php
/**
 * 站内搜索匹配层测试（1.5.6）
 *
 * 起因是一次真实反馈：搜《理想照耀中国》时
 *   · `理想` / `理想照耀` 能找到（片名前缀，上游语义友好）
 *   · `照耀` / `中国` 完全找不到（不是片名开头，上游 `LIKE '词%'` 语义之外）
 *
 * 上游的 `wd` 是前缀匹配这件事我们改不了，所以站内搜索不能把「找得到」
 * 交给上游。本文件钉住 1.5.6 引入的匹配层行为：
 *   · `vhSearchTerms()`   —— 切词（含中文 n-gram），长片段优先、有上限
 *   · `vhSearchScore()`   —— 命中分档（片名含 > 别名 > 演职员）
 *   · `vhSearchFilter()`  —— 子串过滤 + 稳定排序
 *   · `vhSearchMerge()`   —— 多路召回去重
 *
 * ⚠ 这些都是**纯函数**（不联网、不读库），与 bootstrap 的隔离原则一致。
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../includes/fields.php';   // searchHaystack / plainText / cleanTitle

// ⚠⚠ **这段实现本应放在 `includes/search.php`，但 1.5.6 选择了 C 方案**
//   （只写明上游 `wd` 的前缀语义限制，不上线任何搜索改造）。
//   生产代码里不放没人调用的函数 —— 契约 12「没有定义了却没人调用的函数」会当场变红。
//   所以它暂存在测试文件里，作为 **A/B 方案（本地片名索引 / 按需拉多页）的预留资产**：
//   将来做 A/B 时，把下面这四个函数搬回 `includes/search.php`、接上 `search.php` 即可，
//   本文件的 23 条测试直接继续生效，不必重写。
//
//   这四个函数是纯函数（不联网、不读库），与 bootstrap 的隔离原则一致。

/** 单次搜索最多发起多少个召回片段（含原文） */
const VH_SEARCH_MAX_TERMS = 6;

function vhSearchTerms(string $wd): array {
    $wd = trim($wd);
    if ($wd === '') {
        return [];
    }
    $out = [];
    $add = static function (string $s) use (&$out): void {
        $s = trim($s);
        if ($s === '' || in_array($s, $out, true)) { return; }
        $out[] = $s;
    };
    $add($wd);
    foreach (preg_split('/[\s,，、;；·・\/／|｜]+/u', $wd) ?: [] as $w) {
        $add($w);
    }
    if (!preg_match('/\s/u', $wd)) {
        $chars = preg_split('//u', $wd, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $n = count($chars);
        foreach ([3, 2] as $len) {
            for ($i = 0; $i + $len <= $n; $i++) {
                $add(implode('', array_slice($chars, $i, $len)));
            }
        }
    }
    $short = [];
    $rest  = [];
    foreach ($out as $t) {
        if (mb_strlen($t) <= 2) { $short[] = $t; } else { $rest[] = $t; }
    }
    usort($rest, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
    $ordered = [];
    $slotLeft = VH_SEARCH_MAX_TERMS;
    foreach ($short as $t) {
        if ($slotLeft <= 0) { break; }
        $ordered[] = $t; $slotLeft--;
    }
    foreach ($rest as $t) {
        if ($slotLeft <= 0) { break; }
        $ordered[] = $t; $slotLeft--;
    }
    if (($pos = array_search($wd, $ordered, true)) !== false && $pos > 0) {
        unset($ordered[$pos]);
        array_unshift($ordered, $wd);
    }
    return array_values($ordered);
}

function vhSearchScore(array $item, string $wd): int {
    $q = mb_strtolower(trim($wd), 'UTF-8');
    if ($q === '') { return 0; }
    $name   = mb_strtolower(cleanTitle((string) ($item['vod_name'] ?? '')), 'UTF-8');
    $sub    = mb_strtolower(plainText((string) ($item['vod_sub'] ?? '')), 'UTF-8');
    $en     = mb_strtolower(trim((string) ($item['vod_en'] ?? '')), 'UTF-8');
    $letter = mb_strtolower(trim((string) ($item['vod_letter'] ?? '')), 'UTF-8');
    $hay    = searchHaystack($item);
    if ($name === $q)                                    { return 100; }
    if ($name !== '' && str_starts_with($name, $q))      { return 90; }
    if (str_contains($name, $q))                         { return 80; }
    if (str_contains($sub, $q) || str_contains($en, $q)) { return 70; }
    if ($letter !== '' && $letter === $q)                { return 60; }
    if (str_contains($hay, $q))                          { return 40; }
    return 0;
}

function vhSearchFilter(array $items, string $wd): array {
    $keep = [];
    foreach ($items as $it) {
        if (!is_array($it)) { continue; }
        $s = vhSearchScore($it, $wd);
        if ($s > 0) { $keep[] = ['s' => $s, 'i' => $it]; }
    }
    $idx = 0;
    foreach ($keep as &$k) { $k['o'] = $idx++; }
    unset($k);
    usort($keep, static fn (array $a, array $b): int => ($b['s'] <=> $a['s']) ?: ($a['o'] <=> $b['o']));
    $out = array_map(static fn (array $k): array => $k['i'], $keep);
    return ['list' => array_values($out), 'matched' => count($out), 'filtered' => count($items) - count($out)];
}

function vhSearchMerge(array ...$lists): array {
    $seen = [];
    $out  = [];
    foreach ($lists as $list) {
        foreach ($list as $it) {
            if (!is_array($it)) { continue; }
            $id  = intval($it['vod_id'] ?? 0);
            $key = $id > 0 ? 'id:' . $id
                 : 'n:' . mb_strtolower(trim((string) ($it['vod_name'] ?? '')), 'UTF-8')
                     . '|' . trim((string) ($it['vod_year'] ?? ''));
            if (isset($seen[$key])) { continue; }
            $seen[$key] = true;
            $out[] = $it;
        }
    }
    return $out;
}

/** 一条贴近真实上游的记录 */
function sItem(array $over = []): array {
    return $over + [
        'vod_id'      => 1001,
        'vod_name'    => '理想照耀中国',
        'vod_sub'     => '光之初',
        'vod_en'      => 'Ideal Illuminates China',
        'vod_letter'  => 'l',
        'vod_actor'   => '靳东,王凯',
        'vod_director' => '张一白',
        'vod_class'   => '剧情,历史',
        'vod_tag'     => '献礼,主旋律',
        'type_name'   => '国产剧',
        'vod_year'    => '2021',
    ];
}

// ==================================================================
// 一、切词
// ==================================================================

group('1.5.6 搜索切词');

t('整串永远保留（上游前缀语义对整串最友好，不能丢）', static function (): void {
    $t = vhSearchTerms('理想照耀中国');
    eq('理想照耀中国', $t[0], '整串必须排第一位');
});

t('【核心】2 字片段必须在候选里 —— 这是「照耀」「中国」能搜到的前提', static function (): void {
    foreach (['理想照耀中国', '照耀中国', '理想照耀'] as $q) {
        $t = vhSearchTerms($q);
        // 每个相邻 2 字组合都要能被召回，否则「输入中间词」就还是搜不到
        $chars = preg_split('//u', $q, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $need  = [];
        for ($i = 0; $i + 2 <= count($chars); $i++) {
            $need[] = implode('', array_slice($chars, $i, 2));
        }
        foreach (array_unique($need) as $bigram) {
            ok(in_array($bigram, $t, true),
                "「{$q}」的 2 字片段「{$bigram}」必须在候选里：" . implode(' / ', $t));
        }
    }
});

t('短片段不被长片段挤出名单（名额被占满时也要留下 2 字片段）', static function (): void {
    $t = vhSearchTerms('理想照耀中国');
    $short = array_filter($t, static fn (string $x): bool => mb_strlen($x) <= 2);
    ok(count($short) > 0,
        '上限 ' . VH_SEARCH_MAX_TERMS . ' 之内必须保留至少一个短片段：' . implode(' / ', $t));
});

t('片段数有上限，避免多路召回打爆出站预算', static function (): void {
    foreach (['理想照耀中国', '一二三四五六七八九十'] as $q) {
        $t = vhSearchTerms($q);
        ok(count($t) <= VH_SEARCH_MAX_TERMS,
            "「{$q}」切出 " . count($t) . " 段，上限应为 " . VH_SEARCH_MAX_TERMS);
    }
});

t('候选去重：同一个片段只出现一次', static function (): void {
    $t = vhSearchTerms('理想照耀');
    eq($t, array_values(array_unique($t)), '候选不应有重复：' . implode(' / ', $t));
});

t('英文/数字按空白与分隔符切词', static function (): void {
    $t = vhSearchTerms('Joy of Life');
    ok(in_array('Joy', $t, true) || in_array('joy', $t, true), '英文词应被切出：' . implode(' / ', $t));
    ok(in_array('Joy of Life', $t, true), '整串保留：' . implode(' / ', $t));
});

t('空输入返回空数组', static function (): void {
    eq([], vhSearchTerms(''));
    eq([], vhSearchTerms('   '));
});

// ==================================================================
// 二、命中打分
// ==================================================================

group('1.5.6 命中打分');

t('【本次修复的重点】片名中间的词必须命中', static function (): void {
    $it = sItem();
    ok(vhSearchScore($it, '照耀') > 0, '「照耀」在片名中间，必须命中');
    ok(vhSearchScore($it, '中国') > 0, '「中国」在片名末尾，必须命中');
});

t('片名前缀命中仍然成立（不能为了修中间词把原行为弄坏）', static function (): void {
    $it = sItem();
    ok(vhSearchScore($it, '理想') > 0, '前缀「理想」必须命中');
    ok(vhSearchScore($it, '理想照耀') > 0, '前缀「理想照耀」必须命中');
});

t('片名完全相等排在前缀前面', static function (): void {
    $it = sItem();
    ok(vhSearchScore($it, '理想照耀中国') > vhSearchScore($it, '理想'),
        '全等分必须高于前缀分');
});

t('片名命中排在演职员命中前面', static function (): void {
    $a = sItem();
    $b = sItem(['vod_name' => '别的片', 'vod_actor' => '理想']);
    ok(vhSearchScore($a, '理想') > vhSearchScore($b, '理想'),
        '片名含「理想」应排在「演员叫理想」前面');
});

t('别名 / 拼音也能命中（上游 wd 查不到这些字段）', static function (): void {
    $it = sItem();
    ok(vhSearchScore($it, '光之初') > 0, '别名命中');
    ok(vhSearchScore($it, 'Ideal') > 0, '拼音/英文名命中');
});

t('大小写不敏感', static function (): void {
    $it = sItem();
    eq(vhSearchScore($it, 'ideal'), vhSearchScore($it, 'IDEAL'), '英文大小写应等价');
});

t('不相关的内容返回 0（用于过滤）', static function (): void {
    eq(0, vhSearchScore(sItem(), '潜水艇'));
});

t('空关键词返回 0，不能把所有结果都判命中', static function (): void {
    eq(0, vhSearchScore(sItem(), '   '));
});

// ==================================================================
// 三、过滤与排序
// ==================================================================

group('1.5.6 过滤与排序');

t('过滤掉不相关的记录', static function (): void {
    $items = [sItem(['vod_id' => 1, 'vod_name' => '理想照耀中国']),
              sItem(['vod_id' => 2, 'vod_name' => '完全无关'])];
    $r = vhSearchFilter($items, '照耀');
    eq(1, $r['matched'], '应只保留命中的一条');
    eq(1, $r['filtered'], '另一条应被过滤');
});

t('排序稳定：同分保持原顺序（用户每次看到的顺序不该变）', static function (): void {
    $items = [
        sItem(['vod_id' => 1, 'vod_name' => '理想照耀中国']),
        sItem(['vod_id' => 2, 'vod_name' => '理想照耀世界']),
        sItem(['vod_id' => 3, 'vod_name' => '理想照耀未来']),
    ];
    $r = vhSearchFilter($items, '理想照耀');
    eq([1, 2, 3], array_map(static fn (array $i): int => intval($i['vod_id']), $r['list']),
        '同分必须保持原顺序');
});

t('命中质量高的排在前面', static function (): void {
    $items = [
        sItem(['vod_id' => 1, 'vod_name' => '别的片', 'vod_actor' => '理想']),
        sItem(['vod_id' => 2, 'vod_name' => '理想照耀中国']),
    ];
    $r = vhSearchFilter($items, '理想');
    eq(2, intval($r['list'][0]['vod_id']), '片名命中的应排第一');
});

t('非数组条目被安全跳过（上游脏数据）', static function (): void {
    $r = vhSearchFilter(['garbage', null, sItem()], '照耀');
    eq(1, $r['matched'], '只保留合法且命中的记录');
});

// ==================================================================
// 四、多路召回合并
// ==================================================================

group('1.5.6 召回合并');

t('同一部片在多个片段里被召回时只保留一条', static function (): void {
    $a = [sItem(['vod_id' => 7])];
    $b = [sItem(['vod_id' => 7]), sItem(['vod_id' => 8, 'vod_name' => '另一部'])];
    $m = vhSearchMerge($a, $b);
    eq(2, count($m), '按 vod_id 去重后应是 2 条');
});

t('vod_id 缺失时用片名+年份做键（不同片不能被并掉）', static function (): void {
    $a = [sItem(['vod_id' => 0, 'vod_name' => '甲', 'vod_year' => '2020'])];
    $b = [sItem(['vod_id' => 0, 'vod_name' => '乙', 'vod_year' => '2021'])];
    eq(2, count(vhSearchMerge($a, $b)), '两部不同的片不能被误并');
});

t('合并保持先到优先（排序依据的原始顺序不被破坏）', static function (): void {
    $a = [sItem(['vod_id' => 1, 'vod_name' => '先到'])];
    $b = [sItem(['vod_id' => 2, 'vod_name' => '后到'])];
    $m = vhSearchMerge($a, $b);
    eq('先到', $m[0]['vod_name']);
    eq('后到', $m[1]['vod_name']);
});

t('空入参安全', static function (): void {
    eq([], vhSearchMerge());
    eq([], vhSearchMerge([], []));
});

finish();
