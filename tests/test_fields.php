<?php
/**
 * includes/fields.php 的纯函数测试
 *
 * 为什么先测这些：
 *   buildMeta() 被 5 套模板 + 列表页 + 播放页共用，是全站字段渲染的总入口；
 *   它的下层全是纯函数（无 I/O、无网络），是回归风险最高 / 测试成本最低的一批。
 *   CHANGELOG 里那三起线上事故（$ 脏数据、播放源整串当一个 chip、类型回退）
 *   全部落在这一层。
 */

require_once __DIR__ . '/bootstrap.php';

$__c = static fn () => null;   // 占位，避免未使用告警

// ==================================================================
// 一、文本清洗：decodeEntities / plainText
// ==================================================================

t('decodeEntities 还原演员名里的实体', static function (): void {
    eq("Frank Cox-O'Connell", decodeEntities('Frank Cox-O&#039;Connell'));
});

t('decodeEntities 空值与 null 安全', static function (): void {
    eq('', decodeEntities(null));
    eq('', decodeEntities(''));
});

t('plainText 去标签并折叠空白', static function (): void {
    eq('前情提要 正片开始', plainText('<p>前情提要<br/>正片开始</p>'));
});

t('plainText 把 &nbsp; 与全角空格压成普通空格', static function (): void {
    eq('甲 乙', plainText("甲\xc2\xa0乙"));
    eq('甲 乙', plainText("甲\xe3\x80\x80乙"));
});

t('plainText 标签替换成空格而非直接粘连（防前后词粘连）', static function (): void {
    // 关键：去标签后两个词之间要有空格，否则「前情提要<br>正片」会粘成「前情提要正片」
    eq('前情提要 正片', plainText('前情提要<br>正片'));
});

t('plainText 纯文本原样返回', static function (): void {
    eq('范闲与庆帝的终极对决', plainText('范闲与庆帝的终极对决'));
});

// ==================================================================
// 二、splitList：多值字段拆分
// ==================================================================

t('splitList 英文逗号分隔', static function (): void {
    eq(['剧情', '爱情'], splitList('剧情,爱情'));
});

t('splitList 中文逗号与顿号、分号、斜杠、竖线', static function (): void {
    eq(['剧情', '爱情'], splitList('剧情，爱情'));
    eq(['剧情', '爱情'], splitList('剧情、爱情'));
    eq(['剧情', '爱情'], splitList('剧情;爱情'));
    eq(['中国大陆', '中国香港'], splitList('中国大陆 / 中国香港'));
    eq(['中国大陆', '中国香港'], splitList('中国大陆|中国香港'));
});

t('splitList 93/200 是单��无分隔符，整串即一个值', static function (): void {
    eq(['武侠'], splitList('武侠'));
});

t('splitList 占位值返回空数组（不返回「暂无」当数据）', static function (): void {
    eq([], splitList('暂无'));
    eq([], splitList('未知'));
    eq([], splitList('0'));
    eq([], splitList(''));
    eq([], splitList(null));
});

t('splitList 去重且保序', static function (): void {
    eq(['剧情', '动作'], splitList('剧情,动作,剧情'));
});

t('splitList 容忍分隔符两侧空格', static function (): void {
    eq(['剧情', '爱情'], splitList('剧情 ,  爱情'));
});

// ==================================================================
// 三、parsePubdate：日期解析
// ==================================================================

t('parsePubdate 带国家的完整格式', static function (): void {
    eq(
        ['date' => '2026-09-16', 'country' => '美国', 'year' => '2026'],
        parsePubdate('2026-09-16(美国)')
    );
});

t('parsePubdate 全角括号也能解析', static function (): void {
    eq(
        ['date' => '2026-09-16', 'country' => '中国大陆', 'year' => '2026'],
        parsePubdate('2026-09-16（中国大陆）')
    );
});

t('parsePubdate 仅日期', static function (): void {
    eq(['date' => '2026-09-16', 'country' => '', 'year' => '2026'], parsePubdate('2026-09-16'));
});

t('parsePubdate 仅年份时 date 退化为年份', static function (): void {
    eq(['date' => '2026', 'country' => '', 'year' => '2026'], parsePubdate('2026'));
});

t('parsePubdate 缺失与占位返回全空', static function (): void {
    eq(['date' => '', 'country' => '', 'year' => ''], parsePubdate(''));
    eq(['date' => '', 'country' => '', 'year' => ''], parsePubdate(null));
    eq(['date' => '', 'country' => '', 'year' => ''], parsePubdate('暂无'));
});

// ==================================================================
// 四、scoreOf / badgeOf / episodes
// ==================================================================

t('scoreOf 优先 vod_score', static function (): void {
    eq(8.5, scoreOf(['vod_score' => 8.5, 'vod_douban_score' => 7.0]));
});

t('scoreOf vod_score 为 0 时回退 douban', static function (): void {
    eq(7.0, scoreOf(['vod_score' => 0, 'vod_douban_score' => 7.0]));
});

t('scoreOf 再兜 vod_score_all / vod_score_num 求均分', static function (): void {
    // 实测 414/69 = 6.0
    eq(6.0, scoreOf(['vod_score_all' => 414, 'vod_score_num' => 69]));
    // 实测 1232/154 = 8.0
    eq(8.0, scoreOf(['vod_score_all' => 1232, 'vod_score_num' => 154]));
});

t('scoreOf 全空返回 0.0（不是「未知」）', static function (): void {
    eq(0.0, scoreOf([]));
    // 分母为 0 时不得除零
    eq(0.0, scoreOf(['vod_score_all' => 414, 'vod_score_num' => 0]));
});

t('badgeOf 优先 remarks（实测 100% 填充）', static function (): void {
    eq('更新至第08集', badgeOf(['vod_remarks' => '更新至第08集', 'vod_state' => '正片']));
});

t('badgeOf remarks 缺失时用 state', static function (): void {
    eq('正片', badgeOf(['vod_state' => '正片']));
});

t('badgeOf 都没有时用时长', static function (): void {
    eq('45分钟', badgeOf(['vod_duration' => '45']));
});

t('badgeOf 跳过「暂无」继续往下找', static function (): void {
    eq('正片', badgeOf(['vod_remarks' => '暂无', 'vod_state' => '正片']));
});

t('episodes 取较大者作总数', static function (): void {
    eq(
        ['total' => 54, 'updated' => 36, 'label' => '共 54 集（已更新 36）'],
        episodes(['vod_total' => 54], 36)
    );
});

t('episodes 已更新数超过 vod_total 时取已更新数', static function (): void {
    eq(
        ['total' => 20, 'updated' => 36, 'label' => '共 36 集'],
        episodes(['vod_total' => 20], 36)
    );
});

t('episodes 两者都为 0 时不渲染标签', static function (): void {
    eq(['total' => 0, 'updated' => 0, 'label' => ''], episodes([], 0));
});

// ==================================================================
// 五、timeAgo：相对时间
// ==================================================================

t('timeAgo 0 与负数返回空串（不渲染）', static function (): void {
    eq('', timeAgo(0));
    eq('', timeAgo(-1));
    eq('', timeAgo(null));
});

t('timeAgo 一小时内', static function (): void {
    eq('刚刚', timeAgo(time() - 30));
    eq('5 分钟前', timeAgo(time() - 300));
    eq('59 分钟前', timeAgo(time() - 3540));
});

t('timeAgo 一天内', static function (): void {
    eq('3 小时前', timeAgo(time() - 3 * 3600));
});

t('timeAgo 30 天内', static function (): void {
    eq('5 天前', timeAgo(time() - 5 * 86400));
});

t('timeAgo 超过 30 天退化为绝对日期', static function (): void {
    $ts = time() - 60 * 86400;
    eq(date('Y-m-d', $ts), timeAgo($ts));
});

t('timeAgo 未来时间不返回负数（脏数据兜底）', static function (): void {
    eq('刚刚', timeAgo(time() + 9999));
});

// ==================================================================
// 六、parseDuration / formatDuration / formatNumber
// ==================================================================

t('parseDuration 兼容多种单位写法', static function (): void {
    eq(45, parseDuration('45'));
    eq(45, parseDuration('45分钟'));
    eq(45, parseDuration('45 分钟'));
    eq(90, parseDuration('1小时30分'));
    eq(90, parseDuration('1h30m'));
    eq(60, parseDuration('1小时'));
});

t('parseDuration 认不出时返回 0（不是「未知」）', static function (): void {
    eq(0, parseDuration(''));
    eq(0, parseDuration(null));
    eq(0, parseDuration('未知'));
});

t('formatDuration 整点小时不写「1小时00分」', static function (): void {
    eq('1小时', formatDuration('60'));
    eq('2小时', formatDuration('120'));
});

t('formatDuration 非整点补零', static function (): void {
    eq('1小时30分', formatDuration('90'));
});

t('formatDuration 不足一小时只写分钟', static function (): void {
    eq('45分钟', formatDuration('45'));
});

t('formatDuration 0 与无法解析返回空串（不渲染占位）', static function (): void {
    eq('', formatDuration('0'));
    eq('', formatDuration(''));
    eq('', formatDuration('未知'));
});

t('formatNumber 万位简化', static function (): void {
    eq('609', formatNumber(609));
    eq('9999', formatNumber(9999));
    eq('1.2万', formatNumber(12345));
});

// ==================================================================
// 七、parsePlayUrl：播放地址解析
// ==================================================================

t('parsePlayUrl 标准格式 名$地址', static function (): void {
    eq(
        [['label' => '正片', 'url' => 'https://xxx/1.m3u8']],
        parsePlayUrl('正片$https://xxx/1.m3u8')
    );
});

t('parsePlayUrl 多组用 # 分隔', static function (): void {
    eq(
        [
            ['label' => '正片', 'url' => 'https://a/1.m3u8'],
            ['label' => '第2集', 'url' => 'https://a/2.m3u8'],
        ],
        parsePlayUrl('正片$https://a/1.m3u8#第2集$https://a/2.m3u8')
    );
});

t('parsePlayUrl 多组用换行分隔', static function (): void {
    eq(
        [
            ['label' => '正片', 'url' => 'https://a/1.m3u8'],
            ['label' => '第2集', 'url' => 'https://a/2.m3u8'],
        ],
        parsePlayUrl("正片\$https://a/1.m3u8\n第2集\$https://a/2.m3u8")
    );
});

t('parsePlayUrl 连续多个 $ 只当一个分隔符（1.3.3 修过的脏数据）', static function (): void {
    // 上游脏数据「蓝光$$1080P」类问题：按第一个 $ 切会把地址段留成 "$地址"，播放器直接坏掉
    eq(
        [['label' => '蓝光', 'url' => 'https://a/1.m3u8']],
        parsePlayUrl('蓝光$$https://a/1.m3u8')
    );
    eq(
        [['label' => '蓝光', 'url' => 'https://a/1.m3u8']],
        parsePlayUrl('蓝光$$$https://a/1.m3u8')
    );
});

t('parsePlayUrl 无 $ 时整串当地址，标签回退为「播放」', static function (): void {
    eq(
        [['label' => '播放', 'url' => 'https://a/1.m3u8']],
        parsePlayUrl('https://a/1.m3u8')
    );
});

t('parsePlayUrl 标签为空串时回退为「播放」', static function (): void {
    eq(
        [['label' => '播放', 'url' => 'https://a/1.m3u8']],
        parsePlayUrl('$https://a/1.m3u8')
    );
});

t('parsePlayUrl 空值与空组返回空数组', static function (): void {
    eq([], parsePlayUrl(''));
    eq([], parsePlayUrl(null));
    eq([], parsePlayUrl("#\n#"), '只有分隔符没有内容');
});

// ==================================================================
// 八、playFromList：播放源名（1.3.3 修过的脏数据）
// ==================================================================

t('playFromList 逗号分隔拆成多个来源名', static function (): void {
    eq(['蓝光', '高清', '4K'], playFromList('蓝光,高清,4K'));
});

t('playFromList 兼容井号与竖线分隔', static function (): void {
    eq(['蓝光', '高清'], playFromList('蓝光#高清'));
    eq(['蓝光', '高清'], playFromList('蓝光|高清'));
});

t('playFromList 剥掉来源名尾部的 $清晰度 脏数据', static function (): void {
    // 1.3.3 修复的正是这条：原样输出会变成「蓝光$1080P」当成一个来源名
    eq(['蓝光'], playFromList('蓝光$1080P'));
    eq(['蓝光'], playFromList('蓝光$$1080P'));
});

t('playFromList 去重保序', static function (): void {
    eq(['蓝光', '高清'], playFromList('蓝光,高清,蓝光'));
});

t('playFromList 丢弃纯符号与空段', static function (): void {
    eq(['蓝光'], playFromList('蓝光,$$$'));
    eq([], playFromList('$$$'));
    eq([], playFromList(''));
    eq([], playFromList(null));
});

// ==================================================================
// 九、cleanTitle
// ==================================================================

t('cleanTitle 去掉人为断词符', static function (): void {
    eq('刚认识', cleanTitle('刚认.识'));
    eq('庆余年', cleanTitle('庆余年'));
    eq('AB', cleanTitle('A-B'));
});

t('cleanTitle 空值安全', static function (): void {
    eq('', cleanTitle(null));
    eq('', cleanTitle(''));
});

// ==================================================================
// 十、doubanUrl
// ==================================================================

t('doubanUrl 有效 id 生成条目页地址', static function (): void {
    eq('https://movie.douban.com/subject/1292052/', doubanUrl(1292052));
});

t('doubanUrl 0 与非数字返回空串', static function (): void {
    eq('', doubanUrl(0));
    eq('', doubanUrl('abc'));
    eq('', doubanUrl(null));
});

// ==================================================================
// 十一、normalizeRegions：地区归一（null 与 '' 的语义是本文件的核心契约）
// ==================================================================

t('normalizeRegions 空值返回空串（= 没数据，不展示）', static function (): void {
    eq('', normalizeRegions(''));
    eq('', normalizeRegions('暂无'));
});

t('normalizeRegions 同义值归一', static function (): void {
    eq('中国大陆', normalizeRegions('大陆'));
    eq('中国大陆', normalizeRegions('内地'));
    eq('中国大陆', normalizeRegions('中国'));
    eq('中国香港', normalizeRegions('香港'));
    eq('中国台湾', normalizeRegions('台湾'));
});

t('normalizeRegions 已是规范值时原样返回', static function (): void {
    eq('中国大陆', normalizeRegions('中国大陆'));
    eq('美国', normalizeRegions('美国'));
});

t('normalizeRegions 多地区用 / 连接', static function (): void {
    eq('中国大陆 / 中国香港', normalizeRegions('中国大陆,中国香港'));
});

t('normalizeRegions 展开合称（比丢给模型更准）', static function (): void {
    eq('中国香港 / 中国台湾', normalizeRegions('港台'));
    eq('中国香港 / 中国澳门 / 中国台湾', normalizeRegions('港澳台'));
    eq('日本 / 韩国', normalizeRegions('日韩'));
});

t('normalizeRegions 去重（同一地区出现多次只算一个）', static function (): void {
    eq('中国大陆', normalizeRegions('中国大陆,大陆,中国'));
});

t('normalizeRegions 认不出的值返回 null（交给模型，≠ 空串）', static function (): void {
    // 契约关键：null = 「看不懂，请模型判断」；'' = 「没数据，别展示」
    isNull(normalizeRegions('火星'));
    isNull(normalizeRegions('中国大陆,火星'));
    isNull(normalizeRegions('外星'), '多值里有一个不认识就整体交给模型，避免半生不熟的拼接');
});

t('normalizeRegions 类型签名要求 string，传空串不报错', static function (): void {
    eq('', normalizeRegions(trim('')));
});

// ==================================================================
// 十二、normalizeLangs：语言归一
// ==================================================================

t('normalizeLangs 同义值归一（国语 57 次 vs 汉语普通话 47 次）', static function (): void {
    eq('汉语普通话', normalizeLangs('国语'));
    eq('汉语普通话', normalizeLangs('普通话'));
    eq('汉语普通话', normalizeLangs('中文'));
    eq('汉语普通话', normalizeLangs('华语'));
    eq('闽南语', normalizeLangs('台语'));
    eq('韩语', normalizeLangs('朝鲜语'));
});

t('normalizeLangs 多语言连接', static function (): void {
    eq('汉语普通话 / 粤语', normalizeLangs('汉语普通话,粤语'));
});

t('normalizeLangs 空值返回空串', static function (): void {
    eq('', normalizeLangs(''));
    eq('', normalizeLangs('暂无'));
});

t('normalizeLangs 上游截断成单字时返回 null（交给模型）', static function (): void {
    // 「汉语普通话,英语,法」这类被截断的值，正是模型该接手的
    isNull(normalizeLangs('法'));
    isNull(normalizeLangs('汉语普通话,英语,法'));
    isNull(normalizeLangs('克林贡语'));
});

t('normalizeLangs 去重', static function (): void {
    eq('汉语普通话', normalizeLangs('国语,普通话,汉语普通话'));
});

// ==================================================================
// 十三、normalizeRemarks：更新状态（66 种取值但句式规整）
// ==================================================================

t('normalizeRemarks 已完结句式', static function (): void {
    eq('finished', normalizeRemarks('已完结'));
    eq('finished', normalizeRemarks('全剧终'));
    eq('finished', normalizeRemarks('全集'));
    eq('finished', normalizeRemarks('大结局'));
});

t('normalizeRemarks 连载中句式', static function (): void {
    eq('ongoing', normalizeRemarks('更新至第08集'));
    eq('ongoing', normalizeRemarks('更新至20260926期'));
    eq('ongoing', normalizeRemarks('连载中'));
});

t('normalizeRemarks HD / 中字判不了返回 null（交给模型）', static function (): void {
    isNull(normalizeRemarks('HD'));
    isNull(normalizeRemarks('HD中字'));
});

t('normalizeRemarks 空串返回 null', static function (): void {
    isNull(normalizeRemarks(''));
});

t('normalizeRemarks 接受 null（1.3.4 起签名改为 ?string）', static function (): void {
    // 此前签名是 string，三处调用点全靠 (string) 强转兜底 ——
    // 任何一处忘了强转就是整站 500（TypeError 是致命错误）。
    // 现在由类型系统兜住，这条测试锁住它不会再退回去。
    isNull(normalizeRemarks(null), 'null 应视作「没数据」，返回 null 交给模型');
});

t('normalizeRemarks 对各种空值一律返回 null', static function (): void {
    isNull(normalizeRemarks(null));
    isNull(normalizeRemarks(''));
    isNull(normalizeRemarks('   '), '纯空白也当没数据');
});

t('searchHaystack 汇总多个可检索字段', static function (): void {
    $s = searchHaystack([
        'vod_name'    => '庆余年',
        'vod_sub'     => '庆余年第二季',
        'vod_en'      => 'Joy of Life',
        'vod_letter'  => 'Q',
        'vod_actor'   => '张若昀',
        'vod_director'=> '孙皓',
        'vod_class'   => '古装,剧情',
        'vod_tag'     => '权谋',
    ]);
    contains($s, '庆余年');
    contains($s, '张若昀');
    contains($s, '孙皓');
    contains($s, '权谋');
});

t('searchHaystack 统一小写（GELBOYS 与 gelboys 都能命中）', static function (): void {
    $s = searchHaystack(['vod_name' => 'GELBOYS']);
    contains($s, 'gelboys');
});

t('searchHaystack 全角括号折叠为半角', static function (): void {
    $s = searchHaystack(['vod_name' => '庆余年（第二季）']);
    contains($s, '(', '全角（应折叠成半角');
    notContains($s, '（', '全角括号应被折叠掉');
});

t('searchHaystack 拼接 type_name', static function (): void {
    contains(searchHaystack(['vod_name' => 'A', 'type_name' => '国产剧']), '国产剧');
});

finish();
