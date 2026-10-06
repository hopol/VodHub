<?php
/**
 * buildMeta() 集成测试
 *
 * 这是全站字段渲染的**总入口**：5 套模板的 play.php、list.php
 * 全部经过它。任何一处回归的表现都是「某类字段集体消失」且**不报错**。
 *
 * 与 test_fields.php 的分工：那边测单个纯函数，这边测**它们组合起来**的行为。
 */

require_once __DIR__ . '/bootstrap.php';

/** 构造一条贴近真实上游的 detail（字段名与 docs/api-sources.md 一致） */
function sampleDetail(array $over = []): array {
    return $over + [
        'vod_id'         => 12345,
        'vod_name'       => '庆余年 第二季',
        'vod_sub'        => '庆余年2 / Joy of Life II',
        'vod_en'         => 'Joy of Life II',
        'vod_letter'     => 'q',
        'vod_pic'        => '',
        'type_id'        => 1,
        'type_id_1'      => 0,
        'type_name'      => '国产剧',
        'vod_class'      => '古装,剧情,权谋',
        'vod_tag'        => '庆余年,范闲,猫腻,改编',
        'vod_area'       => '中国大陆',
        'vod_lang'       => '汉语普通话',
        'vod_year'       => '2024',
        'vod_pubdate'    => '2024-05-26(中国大陆)',
        'vod_duration'   => '45',
        'vod_remarks'    => '更新至第36集',
        'vod_state'      => '正片',
        'vod_version'    => '高清',
        'vod_score'      => 8.5,
        'vod_douban_id'  => 35981101,
        'vod_douban_score' => 7.8,
        'vod_hits'       => 12345,
        'vod_actor'      => '张若昀,李沁',
        'vod_director'   => '孙皓,李少飞',
        'vod_writer'     => '猫腻',
        'vod_blurb'      => '<p>范闲历经生死，重回京都。</p>',
        'vod_content'    => '范闲历经生死，重回京都，与庆帝展开终极对决。',
        'vod_play_from'  => '蓝光,高清',
        'vod_play_url'   => "正片\$https://a/1.m3u8",
        'vod_time_add'   => time() - 3600,
        'vod_time'       => '2024-05-26',
        'vod_status'     => 1,
    ];
}

// ==================================================================
// 一、基本组装
// ==================================================================

t('buildMeta 返回全部约定的键', static function (): void {
    $m = buildMeta(sampleDetail(), 1);
    foreach ([
        'vod_id', 'name', 'pic', 'type_name', 'genres', 'year', 'region', 'lang',
        'status', 'remarks', 'score', 'hits', 'actors', 'desc',
        'status_code', 'type_id', 'type_id_1',
    ] as $k) {
        ok(array_key_exists($k, $m), "缺键 {$k} —— 模板按它取，缺了就是 undefined index");
    }
});

t('buildMeta 名称走 cleanTitle（去掉人为断词符）', static function (): void {
    eq('庆余年第二季', buildMeta(sampleDetail(['vod_name' => '庆余年.第二季']), 1)['name']);
});

t('buildMeta 首字母转大写', static function (): void {
    eq('Q', buildMeta(sampleDetail(), 1)['letter']);
});

t('buildMeta 分类合并 class 与 tag 并去重', static function (): void {
    $m = buildMeta(sampleDetail(['vod_class' => '剧情', 'vod_tag' => '剧情,权谋']), 1);
    eq(['剧情', '权谋'], $m['genres'], 'class 与 tag 的并集要去重');
});

t('buildMeta 日期解析出年月日与国家', static function (): void {
    $m = buildMeta(sampleDetail(), 1);
    eq('2024-05-26', $m['pubdate']);
    eq('中国大陆', $m['pubcountry']);
});

t('buildMeta 时长与评分', static function (): void {
    $m = buildMeta(sampleDetail(), 1);
    eq('45分钟', $m['duration']);
    eq(8.5, $m['score']);
});

// ==================================================================
// 二、简介择优（blurb 与 content 取更长的一份）
// ==================================================================

t('buildMeta content 更长时用 content', static function (): void {
    $m = buildMeta(sampleDetail(), 1);
    eq('范闲历经生死，重回京都，与庆帝展开终极对决。', $m['desc']);
});

t('buildMeta blurb 更长时用 blurb', static function (): void {
    $m = buildMeta(sampleDetail([
        'vod_blurb'   => '这是一段非常长的摘要，比 content 长很多很多。',
        'vod_content' => '短',
    ]), 1);
    eq('这是一段非常长的摘要，比 content 长很多很多。', $m['desc']);
});

t('buildMeta 两者都是「暂无」时 desc 为空而不是「暂无」', static function (): void {
    $m = buildMeta(sampleDetail(['vod_blurb' => '暂无', 'vod_content' => '暂无']), 1);
    eq('', $m['desc']);
    eq('', $m['blurb']);
    eq('', $m['content']);
});

t('buildMeta 简介里的 HTML 标签被剥掉（模板直接 h() 输出）', static function (): void {
    $m = buildMeta(sampleDetail(['vod_blurb' => '<p>前情提要<br/>正片</p>', 'vod_content' => '']), 1);
    notContains($m['blurb'], '<p>', '标签必须在字段层剥掉');
    notContains($m['blurb'], '<br', '标签必须在字段层剥掉');
});

// ==================================================================
// 三、演职员
// ==================================================================

t('buildMeta 演员用顿号连接', static function (): void {
    eq('张若昀、李沁', buildMeta(sampleDetail(), 1)['actor_line']);
});

t('buildMeta 导演的多人逗号串被拆开再连', static function (): void {
    eq('孙皓、李少飞', buildMeta(sampleDetail(), 1)['director']);
});

t('buildMeta 编剧单人不加多余分隔符', static function (): void {
    eq('猫腻', buildMeta(sampleDetail(), 1)['writer']);
});

// ==================================================================
// 四、地区与语言：代码优先
// ==================================================================

t('buildMeta 地区走代码归一', static function (): void {
    eq('中国大陆', buildMeta(sampleDetail(['vod_area' => '大陆']), 1)['region']);
});

t('【1.5.0】地区映射表没命中时原样展示上游写法，不留空', static function (): void {
    // ⚠ 这是移除模型层时最容易踩的一处：normalizeRegions() 遇到任何不认识的
    //   地区就返回 null，若直接 `(string)(... ?? '')`，这些片子的**地区会整个消失**。
    //   正确做法是回退到上游原文 —— 宁可显示「火星地区」，也不要什么都不显示。
    eq('火星地区', buildMeta(sampleDetail(['vod_area' => '火星地区']), 1)['region']);
    // 「北美」是真实会遇到但映射表没收进去的写法（实测返回 null）
    eq('北美', buildMeta(sampleDetail(['vod_area' => '北美']), 1)['region']);
});

t('【1.5.0】语言映射表没命中时同样原样展示', static function (): void {
    eq('粤语', buildMeta(sampleDetail(['vod_lang' => '粤语']), 1)['lang']);
    eq('外语', buildMeta(sampleDetail(['vod_lang' => '外语']), 1)['lang']);
});

// ==================================================================
// 五、更新状态的三条来源
// ==================================================================

t('buildMeta 状态来自代码归一（连载中）', static function (): void {
    eq('连载中', buildMeta(sampleDetail(), 1)['status']);
});

t('【1.5.0】remarks 判不出状态时留空（不猜、不显示占位）', static function (): void {
    // 1.5.0 之前这里会问模型，模型能把「HD中字」答成「非正片」。
    // 现在只认 normalizeRemarks() 认得的写法，判不出就不渲染这一行。
    eq('', buildMeta(sampleDetail(['vod_remarks' => 'HD中字']), 1)['status']);
});

t('buildMeta 三处都没有时状态为空串（不渲染占位）', static function (): void {
    $m = buildMeta(sampleDetail(['vod_remarks' => '']), 1);
    eq('', $m['status']);
});

// ==================================================================
// 六、类型回退：type_name 缺失时从 types 数组反查
// ==================================================================

t('buildMeta type_name 缺失时从 types 数组回退', static function (): void {
    $m = buildMeta(
        sampleDetail(['type_name' => '']),
        1,
        [['type_id' => 1, 'type_name' => '国产剧']]
    );
    eq('国产剧', $m['type_name']);
});

t('buildMeta 父分类名为「全部」时清空（不做无意义的父分类链接）', static function (): void {
    $m = buildMeta(
        sampleDetail(['type_id_1' => 0]),
        1,
        [['type_id' => 0, 'type_name' => '全部']]
    );
    eq('', $m['type_name_1']);
});

// ==================================================================
// 七、状态码
// ==================================================================

t('buildMeta 已下架 status_code=0 会被模板渲染成警告 chip', static function (): void {
    eq(0, buildMeta(sampleDetail(['vod_status' => 0]), 1)['status_code']);
});

t('buildMeta 缺 vod_status 时默认 1（在架）', static function (): void {
    $d = sampleDetail();
    unset($d['vod_status']);
    eq(1, buildMeta($d, 1)['status_code']);
});

// ==================================================================
// 八、1.5.0 移除模型层后的行为约定
// ==================================================================

t('【1.5.0】buildMeta 只收 3 个参数，签名里没有 enrich', static function (): void {
    $r = new ReflectionFunction('buildMeta');
    eq(3, $r->getNumberOfParameters(), 'enrich 参数应已移除');
});

t('【1.5.0】不再返回 enrich 派生的键', static function (): void {
    $m = buildMeta(sampleDetail(), 1);
    foreach (['enriched', 'genre_main', 'genre_raw', 'adult', 'adult_warn'] as $k) {
        ok(!array_key_exists($k, $m), "{$k} 应随 enrich 层一并移除");
    }
});

// 九、极端输入：全空 detail 不得抛异常
// ==================================================================

t('buildMeta 全空 detail 不抛异常且键齐全', static function (): void {
    $m = buildMeta([], 0);
    eq('', $m['name']);
    eq('', $m['region']);
    eq('', $m['lang']);
    eq('', $m['status']);
    eq(0.0, $m['score']);
    eq(0, $m['hits']);
    eq([], $m['genres']);
    eq([], $m['actors']);
    eq(0, $m['vod_id']);
    ok(array_key_exists('desc', $m), '即使全空也要有 desc 键');
});

t('buildMeta 无封面时用占位图而不是空串（模板直接当 src 用）', static function (): void {
    $m = buildMeta(sampleDetail(['vod_pic' => '']), 0);
    contains($m['pic'], 'no-cover', '空封面应给占位图，否则 <img src=""> 会请求当前页');
});

t('buildMeta 缺字段的 detail 全部走安全默认值', static function (): void {
    $m = buildMeta(['vod_id' => 7], 1);
    eq(7, $m['vod_id']);
    eq('', $m['name']);
    eq('', $m['remarks']);
    eq(1, $m['status_code']);
});

// ==================================================================
// 十、1.5.1：封面查询与模板配置的回归判据
// ==================================================================

t('【1.5.1】coverUrl() 不再自己查库（每张封面 1 次 → 每次请求 1 次）', static function (): void {
    $root = dirname(__DIR__);
    $src  = (string) file_get_contents($root . '/includes/functions.php');

    // 取 coverUrl 的函数体（到下一个 function 声明为止）
    $start = strpos($src, 'function coverUrl');
    $body  = substr($src, (int) $start, 1200);
    ok(
        !str_contains($body, 'prepare('),
        'coverUrl() 里仍有 prepare —— 一个列表页 20 张封面就是 20 条完全相同的 SQL'
        . '（整页只用一个数据源，sourceId 是同一个值）'
    );
    ok(
        str_contains($body, 'sourceImgProxyOn('),
        'coverUrl() 应改为调用 sourceImgProxyOn()（按 sourceId 进程内缓存）'
    );
});

t('【1.5.1】sourceImgProxyOn() 的缓存是进程内的、且按 sourceId 分键', static function (): void {
    $root = dirname(__DIR__);
    $src  = (string) file_get_contents($root . '/includes/functions.php');
    $start = strpos($src, 'function sourceImgProxyOn');
    ok($start !== false, 'sourceImgProxyOn() 应存在');
    $body = substr($src, (int) $start, 800);

    ok(str_contains($body, 'static $on'), '必须用 static 局部变量做进程内缓存');
    ok(str_contains($body, 'isset($on[$sourceId])'), '命中缓存应直接返回，不重查');
    ok(str_contains($body, '$on[$sourceId] ='), '缓存必须按 sourceId 分键'
       . '（一个请求内可能渲染多个源，见「全站」类页面）');

    // ⚠ 判据的边界：缓存**不能**跨请求，否则后台改了「图片代理」
    //   要等缓存过期才生效 —— 那正是 1.3.11「改数据源前台不变」那一类 bug。
    ok(
        !str_contains($body, 'apcu_') && !str_contains($body, 'file_put_contents'),
        '缓存不得跨请求（APCu / 写文件都会让「改了设置前台不变」）'
    );
});

finish();
