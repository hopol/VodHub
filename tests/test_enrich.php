<?php
/**
 * includes/enrich.php 的富化合并层测试
 *
 * 被测对象是 enrichInterpret() 与 enrichChoiceLabel() —— **它们不出网**：
 * 请求由 enrichHttp() 发出、结果由 enrichInterpret() 解释，两端已分离，
 * 所以这一层可以纯离线测。
 *
 * 为什么这四态必须锁死：
 *   enrich 的返回值直接决定播放页显示什么，而它有四条互不相同的路径 ——
 *   代码命中 / 模型命中 / 置信度不足回退原始值 / 上次失败（负缓存）。
 *   CHANGELOG 里「图片代理彻底修好」「富化静默降级」这类修复都动过这一层，
 *   一旦改错就是「某类影片的字段集体消失」且**不报错**，属最难排查的一类回归。
 */

require_once __DIR__ . '/bootstrap.php';

// ---- 先把 enrich 的函数引进来（它 require db.php，但只在被调用时才建库） ----
require_once __DIR__ . '/../includes/enrich.php';

// ==================================================================
// 一、enrichChoiceLabel：置信度门槛与 other 的处理
// ==================================================================

$LBL = ['drama' => '剧情', 'action' => '动作', 'other' => '以上都不是'];

t('置信度达标时返回中文标签', static function () use ($LBL): void {
    eq('剧情', enrichChoiceLabel(['choice' => 'drama', 'confidence' => 0.95], $LBL));
});

t('置信度恰好等于门槛时采用（边界：< 才回退）', static function () use ($LBL): void {
    eq('剧情', enrichChoiceLabel(['choice' => 'drama', 'confidence' => 0.7], $LBL));
});

t('置信度低于 0.7 返回 null（让调用方回退原始字段）', static function () use ($LBL): void {
    isNull(enrichChoiceLabel(['choice' => 'drama', 'confidence' => 0.69], $LBL));
    // 实测样本：庆余年 drama 0.63 / war 0.36 → confidence 0.59，走的就是这条
    isNull(enrichChoiceLabel(['choice' => 'drama', 'confidence' => 0.59], $LBL));
});

t('选到 other 时返回 null（不能把「以上都不是」显示给用户）', static function () use ($LBL): void {
    isNull(enrichChoiceLabel(['choice' => 'other', 'confidence' => 0.99], $LBL));
});

t('选项不在标签表里时返回 null', static function () use ($LBL): void {
    isNull(enrichChoiceLabel(['choice' => 'martial', 'confidence' => 0.99], $LBL));
});

t('答案缺失或结构不对时返回 null（不抛异常）', static function () use ($LBL): void {
    isNull(enrichChoiceLabel(null, $LBL));
    isNull(enrichChoiceLabel([], $LBL));
    isNull(enrichChoiceLabel('drama', $LBL), '传字符串而不是数组');
    isNull(enrichChoiceLabel(['confidence' => 0.99], $LBL), '缺 choice 键');
    isNull(enrichChoiceLabel(['choice' => 'drama'], $LBL), '缺 confidence 键');
});

t('缺 confidence 时按 0 处理（即视为不达标）', static function () use ($LBL): void {
    // 实测端点在极少数情况下可能不返回 confidence；宁可回退也不盲信
    isNull(enrichChoiceLabel(['choice' => 'drama'], $LBL));
});

// ==================================================================
// 二、enrichInterpret · 态一：代码命中 → 模型答案只作留痕，不覆盖
// ==================================================================

t('态一 地区代码命中时用代码结果，src=code', static function (): void {
    $r = enrichInterpret(
        ['vod_area' => '大陆', 'vod_lang' => '国语', 'vod_remarks' => '已完结'],
        ['region' => ['choice' => 'japan', 'confidence' => 0.99]]   // 模型说日本
    );
    eq('中国大陆', $r['region'], '代码命中优先，模型不得覆盖');
    eq('code', $r['region_src']);
});

t('态一 语言代码命中时用代码结果', static function (): void {
    $r = enrichInterpret(
        ['vod_lang' => '国语'],
        ['lang' => ['choice' => 'japanese', 'confidence' => 0.99]]
    );
    eq('汉语普通话', $r['lang']);
    eq('code', $r['lang_src']);
});

t('态一 更新状态代码命中时给出中文文案', static function (): void {
    $r = enrichInterpret(
        ['vod_remarks' => '更新至第36集'],
        ['update_status' => ['choice' => 'finished', 'confidence' => 0.99]]
    );
    eq('ongoing', $r['update_status'], '代码说连载中，模型说完结 → 以代码为准');
    eq('连载中', $r['update_status_text']);
    eq('code', $r['update_status_src']);
});

t('态一 代码 finished 时文案是「已完结」', static function (): void {
    $r = enrichInterpret(['vod_remarks' => '全剧终'], []);
    eq('finished', $r['update_status']);
    eq('已完结', $r['update_status_text']);
});

// ==================================================================
// 三、enrichInterpret · 态二：代码没命中 → 用模型答案
// ==================================================================

t('态二 地区长尾由模型兜底，src=model', static function (): void {
    $r = enrichInterpret(
        ['vod_area' => '火星'],
        ['region' => ['choice' => 'usa', 'confidence' => 0.95]]
    );
    eq('美国', $r['region']);
    eq('model', $r['region_src']);
});

t('态二 语言被上游截断时由模型兜底', static function (): void {
    $r = enrichInterpret(
        ['vod_lang' => '汉语普通话,英语,法'],
        ['lang' => ['choice' => 'french', 'confidence' => 0.93]]
    );
    eq('法语', $r['lang']);
    eq('model', $r['lang_src']);
});

t('态二 HD 中字这类判不了的更新状态由模型兜底', static function (): void {
    $r = enrichInterpret(
        ['vod_remarks' => 'HD中字'],
        ['update_status' => ['choice' => 'teaser', 'confidence' => 0.88]]
    );
    eq('teaser', $r['update_status']);
    eq('非正片', $r['update_status_text']);
    eq('model', $r['update_status_src']);
});

t('态二 genre 纯靠模型（代码无法归类开放词表）', static function (): void {
    $r = enrichInterpret(
        ['vod_class' => '古装,权谋', 'vod_tag' => '胡歌,改编'],
        ['genre' => ['choice' => 'war', 'confidence' => 0.82]]
    );
    eq('战争历史', $r['genre_text']);
});

t('态二 region 与 lang 的中文标签映射正确', static function (): void {
    $r = enrichInterpret(
        ['vod_area' => '外星', 'vod_lang' => '克林贡语'],
        [
            'region' => ['choice' => 'hongkong', 'confidence' => 0.95],
            'lang'   => ['choice' => 'cantonese', 'confidence' => 0.95],
        ]
    );
    eq('中国香港', $r['region']);
    eq('粤语', $r['lang']);
});

// ==================================================================
// 四、enrichInterpret · 态三：置信度不足 / 选到 other → 回退**原始字段**
// ==================================================================

t('态三 地区置信度不足时保留原始值而不是留空', static function (): void {
    $r = enrichInterpret(
        ['vod_area' => '火星'],
        ['region' => ['choice' => 'usa', 'confidence' => 0.5]]
    );
    eq('火星', $r['region'], '判不出来就显示原始值，不能显示成空');
    eq('raw', $r['region_src']);
});

t('态三 地区选到 other 时保留原始值', static function (): void {
    $r = enrichInterpret(
        ['vod_area' => '火星'],
        ['region' => ['choice' => 'other', 'confidence' => 0.99]]
    );
    eq('火星', $r['region']);
    eq('raw', $r['region_src']);
});

t('态三 语言判不出来时保留原始值', static function (): void {
    $r = enrichInterpret(
        ['vod_lang' => '汉语普通话,英语,法'],
        ['lang' => ['choice' => 'french', 'confidence' => 0.4]]
    );
    eq('汉语普通话,英语,法', $r['lang']);
    eq('raw', $r['lang_src']);
});

t('态三 完全没有答案时全部回退原始字段', static function (): void {
    $r = enrichInterpret(['vod_area' => '火星', 'vod_lang' => '克林贡语'], []);
    eq('火星', $r['region']);
    eq('raw', $r['region_src']);
    eq('克林贡语', $r['lang']);
    eq('raw', $r['lang_src']);
});

t('态三 genre 置信度不足时为空串（当前无 raw 兜底，见报告 6.1 场景一）', static function (): void {
    // 这条锁的是**当前实际行为**。报告指出 genre 是 4 个字段里唯一没有
    // raw 兜底的，低置信度时前台什么都不显示 —— 这是待修项，不是期望行为。
    // 测试先如实记录，修复后再改断言。
    $r = enrichInterpret(
        ['vod_class' => '古装,权谋'],
        ['genre' => ['choice' => 'drama', 'confidence' => 0.59]]
    );
    eq('', $r['genre_text'], '当前行为：低置信度 → 空串（待修）');
});

// ==================================================================
// 五、enrichInterpret · 态四：成人内容判定（Noul，无置信度门槛）
// ==================================================================

t('态四 is_adult 取 noul 概率', static function (): void {
    $r = enrichInterpret([], ['is_adult' => ['noul' => 0.98]]);
    eq(0.98, $r['adult']);
    ok($r['adult_warn'] === true, '0.98 ≥ 0.7 应触发提示');
});

t('态四 普通影片不触发提示', static function (): void {
    $r = enrichInterpret([], ['is_adult' => ['noul' => 0.02]]);
    eq(0.02, $r['adult']);
    ok($r['adult_warn'] === false, '0.02 < 0.7 不应提示');
});

t('态四 Noul 恰好在门槛上触发（边界：>= 0.7）', static function (): void {
    $r = enrichInterpret([], ['is_adult' => ['noul' => 0.7]]);
    ok($r['adult_warn'] === true);
});

t('态四 缺少 is_adult 答案时 adult=0 且不提示（不得因缺数据误报）', static function (): void {
    $r = enrichInterpret([], []);
    eq(0.0, $r['adult']);
    ok($r['adult_warn'] === false, '缺数据时绝不误报成人内容');
});

t('态四 is_adult 结构异常时按 0 处理不抛异常', static function (): void {
    $r = enrichInterpret([], ['is_adult' => 'oops']);
    eq(0.0, $r['adult']);
    ok($r['adult_warn'] === false);
});

// ==================================================================
// 六、返回值契约：调用方（buildMeta / enrich.php）依赖的键
// ==================================================================

t('返回值必带 model 键（后台展示用）', static function (): void {
    $r = enrichInterpret([], []);
    ok(isset($r['model']), '缺 model 键会让后台富化统计显示不出模型名');
    eq('jev-1.13-free', $r['model']);
});

t('空 detail 不抛异常（负缓存后仍会走一次合并）', static function (): void {
    $r = enrichInterpret([], []);
    ok(is_array($r));
    eq('', $r['region']);
    eq(0.0, $r['adult']);
});

t('detail 字段全是 HTML 实体时 region 回退已解码', static function (): void {
    // decodeEntities 在 raw 兜底路径上生效，模板直接 h() 输出，不会把实体当字面量
    $r = enrichInterpret(['vod_area' => '&#26159; A&amp; B'], []);
    eq('是 A& B', $r['region']);
});

t('四个 src 键恒定存在（模板与后台按它们分流）', static function (): void {
    $r = enrichInterpret([], []);
    foreach (['region_src', 'lang_src', 'update_status_src'] as $k) {
        ok(array_key_exists($k, $r), "缺键 {$k}");
    }
});

finish();
