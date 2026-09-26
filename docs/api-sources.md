# 数据源接入

VodHub 自己不存内容，**所有影片都来自你在后台配置的第三方接口**。

- [接口格式要求](#接口格式要求)
- [四种请求](#四种请求)
- [地址怎么填](#地址怎么填)
- [使用了哪些字段](#使用了哪些字段)
- [选模板：看封面比例](#选模板看封面比例)
- [图片代理与防盗链](#图片代理与防盗链)
- [接口测试](#接口测试)

---

## 接口格式要求

需要**苹果 CMS（MacCMS）标准的 `provide/vod` JSON 接口**。

几乎所有 MacCMS 系的影视站、采集站、第三方 API 商都提供这个格式。判断方法很简单——地址里通常带这段：

```
.../api.php/provide/vod/at/json/
```

### 请求方式

程序以 `GET` + `application/json` 方式请求，**不需要任何鉴权头**。

如果你的接口需要密钥（`?key=xxx`），直接把 key 拼在地址里即可，程序会原样保留。

### 响应必须是

```json
{
    "code": 1,
    "msg": "ok",
    "page": 1,
    "pagecount": 100,
    "total": 1000,
    "list": [
        {
            "vod_id": 12345,
            "vod_name": "影片名",
            "vod_pic": "https://img.example.com/cover.jpg",
            "vod_remarks": "更新至第10集",
            ...
        }
    ],
    "list": [],
    "class": [
        {"type_id": 1, "type_name": "动作"}
    ]
}
```

- **分类接口**（`ac=list`）返回 `class` 数组
- **列表/详情接口**（`ac=detail`）返回 `list` 数组 + 分页字段

---

## 四种请求

程序只发这四种请求，都可以在你的接口上手动试：

| 用途 | 参数 | 返回 |
|------|------|------|
| 拉分类 | `?ac=list` | `class` 数组（几乎不变，走 24h 缓存） |
| 拉列表 | `?ac=detail&pg=1[&t=分类ID]` | `list` + 分页（30 分钟缓存） |
| 搜 索 | `?ac=detail&wd=关键词&pg=1` | 同上 |
| 拉详情 | `?ac=detail&ids=影片ID` | 单条完整字段 |

### 手动验证

```bash
# 1. 分类能出来吗？
curl "你的地址?ac=list" | head -c 500

# 2. 列表能出来吗？（应看到 list 数组）
curl "你的地址?ac=detail&pg=1" | head -c 500

# 3. 详情能出来吗？
curl "你的地址?ac=detail&ids=1" | head -c 500
```

三个都能返回 JSON → 可以接进来。

---

## 地址怎么填

**填到 `at/json/` 这一级就够了，后面不要带 `?` 或参数**：

```
✅ https://example.com/api.php/provide/vod/at/json/
✅ https://example.com/api.php/provide/vod/at/json
⚠️ https://example.com/api.php/provide/vod          ← 少了 /at/json/，可能 404
❌ https://example.com/api.php/provide/vod/at/json/?ac=detail   ← 不要带参数，程序会拼
❌ example.com/api.json                             ← 必须 http:// 或 https:// 开头
```

程序会自动拼接 `?ac=xxx` 与分页参数，并保留你写在地址里的额外参数（如 key）。

**保存后请点「测试」**，能拿到延迟和内容才算通。

---

## 使用了哪些字段

上游单条记录有 **83 个字段**，但填充率从 100% 到全 0 不等。程序按「**有值才渲染**」
消费它们 —— 不渲染「未知」占位，字段缺失时那一行自然消失。

各字段的实测填充率来自对某个采集源的抓取统计（83 个字段里，`vod_id` / `vod_area` /
`vod_year` 等 17 个 100% 填充，`vod_actor` 79%、`vod_pubdate` 81%，而 `vod_hits` 系列全为 0）。
**不同源差异很大**，所以程序一律「有值才渲染」。
字段解析逻辑集中在 `includes/fields.php`，归一化判断在 `includes/enrich.php`。

### 列表卡片 / 搜索结果（`partials/vod_grid.php`）

| 字段 | 用途 |
|------|------|
| `vod_id` | 拼播放链接（必需） |
| `vod_name` | 卡片标题 |
| `vod_pic` | 封面图 |
| `vod_status` | 为 `0` 表示上游已下架，不出卡片 |
| `vod_remarks` | 角标首选（100% 填充，如「更新至第 08 集」） |
| `vod_state` | 角标次选（23%，如「正片」） |
| `vod_duration` | 角标兜底（22%），认「45分钟」「1小时30分」 |
| `vod_score` / `vod_douban_score` / `vod_score_all` + `vod_score_num` | 评分角标，按此顺序兜底：`vod_score` → `vod_douban_score` → `vod_score_all ÷ vod_score_num`（后两者填充互补） |
| `vod_class` / `type_name` / `vod_year` | 副标题 |
| `vod_sub` | 别名，接在副标题后 |
| `vod_en` `vod_letter` `vod_actor` `vod_director` `vod_writer` `vod_tag` | 拼进 `data-s`，供本页筛选（见下） |

### 本页筛选（`static/app.js`）

顶栏搜索走上游的 `wd` 参数，**而 `wd` 只匹配 `vod_name`** —— 实测
`wd=<拼音>`、`wd=<别名>` 都返回 0 条。所以列表页/搜索页额外提供一个本页筛选框，
按服务端预拼的 `data-s` 串做子串匹配，让这些字段在站内真正可用：

> 片名 + 别名 + 拼音 + 首字母 + 演员 + 导演 + 编剧 + 类型 + 标签 + 分类名

匹配是**纯前端**的，只作用于当前这一页已加载的卡片，不发额外请求。

### 播放页（`partials/vod_meta.php` + `partials/vod_side.php`）

5 套模板共用这两个片段（`play.php` 原本是逐字节相同的拷贝，字段一多就得改 5 遍）。

| 区块 | 字段 |
|------|------|
| 信息 chips | `type_name` `type_id_1`（父分类，可点回上级）`vod_year` `vod_area` `vod_lang` `vod_remarks` `vod_state` `vod_duration` `vod_score` `vod_hits` `vod_status` |
| 副标题 | `vod_sub`（别名集） |
| 简介 | `vod_content` 与 `vod_blurb` 择优（取清洗后更长的那份），HTML 全部压成纯文本 |
| 演职员 | `vod_director` `vod_writer` `vod_actor`（HTML 实体解码后拆分，主演默认折叠） |
| 侧栏信息 | `vod_id` `vod_sub` `vod_class` `type_id_1` `vod_area` `vod_lang` `vod_pubdate`（`2026-09-16(美国)` 拆成日期 + 国家）`vod_duration` `vod_total` `vod_director` `vod_writer` `vod_state` `vod_remarks` `vod_score` `vod_douban_id` / `vod_douban_score`（豆瓣外链）`vod_en` / `vod_letter` `vod_time_add`（相对时间）`vod_play_from` / `vod_play_server` |

`vod_total`（总集数）与 `vod_play_url` 实际解析出的集数一起算：
两者常对不上（前者是全剧，后者只有已更新的），所以显示 `共 N 集（已更新 M）`。

chips 与侧栏还各有一行 `vod_version`（版本/清晰度，实测取值如「TV版」，本源 5/200 填充）。

### 智能归一化（可关）

同一份数据在上游有多种写法：实测 `vod_area` 里「中国大陆」48 次、「大陆」43 次，
`vod_lang` 里「国语」57 次、「汉语普通话」47 次。归一化分两层：

1. **规则层**（`includes/fields.php`）—— 精确映射 + 正则，**不联网**。
   实测本源 200 条记录中地区 200 条、语言 198 条由规则直接命中。
2. **模型层**（`includes/enrich.php`，TypeSafe System One）—— 只补规则答不上来的部分：
   - 地区/语言的长尾与残缺值（如「汉语普通话,英语,**法**」）
   - 由 `vod_class` + `vod_tag` 归出的主类型（`vod_tag` 是分词后的 token 汤，规则无法解读）
   - 更新状态的非规整句式（如「HD」「HD中字」）
   - 内容分级（成人内容判定）

调用策略：**只在播放页触发、异步**、同一条影片的多个判断合并进**一次**请求并行求值，
结果按 `(数据源, vod_id)` 落库缓存 30 天；失败写 10 分钟负缓存并保持原始字段。
关闭开关见后台「站点与维护 → 播放页字段智能归一化」。

> 归一化只改**展示**，不改存储，也不改上游返回。关掉开关后页面完全按原始值渲染。

### 明确不用的字段

| 字段 | 不用的原因 |
|------|-----------|
| `vod_hits`（含 `_day` / `_week` / `_month`）、`vod_up` / `vod_down` | 200 条里仅 1~2 条非零，展示只会是「播放 0」/空点赞，纯噪音 |
| `vod_isend` | 语义与本源数据矛盾（`197 条为 0`，但 `remarks` 里有 31 条「已完结」；而 `=1` 的 3 条 `remarks` 却写着「更新至第715集」）。**宁可不用，也不能显示错的状态** |
| `vod_down_from` / `vod_down_server` / `vod_down_*` / `vod_down_url` | 值恒为 `no`，且本站不提供下载 |
| `vod_plot` / `vod_plot_name` / `vod_plot_detail` | 本源全空 |
| `vod_pwd*` `vod_jumpurl` `vod_reurl` `vod_rel_*` | 密码保护、外跳与关联推荐，本站不提供 |
| `vod_pic_thumb` `vod_pic_slide` `vod_pic_screenshot` | 本源全空，封面只用 `vod_pic` |
| `group_id` `vod_color` `vod_level` `vod_points*` 等 | CMS 后台自用字段，与前台展示无关 |
| `vod_letter` | **不单独做 A–Z 索引条**（上游无该参数，只能过滤当前页）；已并入 `data-s` 参与筛选 |

### 播放地址

`vod_play_from` + `vod_play_url` 组合成播放器可读的列表：

```
# 一行一个播放组，组内 多个集数，用 # 或换行分隔
正片$https://xxx/1.m3u8
第2集$https://xxx/2.m3u8
```

程序解析成 `[ ['label' => '正片', 'url' => 'https://...'], ... ]` 交给 `hls.js` 播放。

> **只支持 `m3u8`。** 接口如果返回 mp4 直链也能播（浏览器原生支持），但 `flv`、`mpd` 等格式需要额外播放器支持，暂未实现。

---

## 选模板：看封面比例

不同接口的 `vod_pic` 比例天差地别，**选错模板会导致封面被裁或留白**。

打开一个接口的封面链接，看图片长宽：

| 封面实际形状 | 选模板 | 封面比例 |
|------------|--------|---------|
| 横图（宽 > 高） | `bilibili` | 16:9 → `56.25%` |
| 竖图（高 > 宽，电影海报常见） | `default` | 2:3 → `150%` |
| 接近 3:4 | `iqiyi` / `douban` | 75% |
| 列表行式展示 | `douban` | 横向列表布局 |

**每个数据源可以单独绑定模板** —— 所以同一个站里混用横图源和竖图源完全没问题：

后台 → 数据源 → 编辑 → 「使用模板」选合适的 → 保存 → 点前台该源标签确认效果。

### 怎么快速判断

1. 后台添加源 → 点「测试」
2. 保存，去前台点这个源
3. 看封面：
   - **两侧被裁掉** → 封面是竖图但模板是宽图，换成竖图模板
   - **上下大片留白** → 封面是横图但模板是竖图，换成宽图模板

比例不对时后台也能微调：模板管理 → 编辑样式 → 改「封面高度比例」。

---

## 图片代理与防盗链

### 什么时候需要开

**典型症状**：列表里封面全是裂图 / 占位图，但直接在浏览器打开图片地址是正常的。

原因：上游服务器检查 `Referer` 头，从你的域名请求就拒绝返回。程序 `<meta name="referrer" content="no-referrer">` 能解决一部分，有些站仍会拦 —— 这时候开图片代理。

### 怎么用

后台 → 数据源 → 编辑：

1. **勾选「启用图片代理」**
2. **白名单直接留空** ← 推荐

> 白名单**留空 = 不限制域名**，程序会代理该源所有图片。**内网 / 保留地址仍然会被自动拦截**（防 SSRF），所以留空是安全且最省事的。
>
> 只有在你想**额外加严**限制时才需要手动填域名，逗号分隔。

### 不知道图片域名？

不用查 —— 点数据源行里的**「识别图域」**按钮，程序会拉该源最近 10 条内容，提取出实际用到的图片域名写进白名单。

### 代理跑通后

系统会**自动把实际用到的域名记录到白名单里**，你可以在编辑表单里看到并随时调整。

### 什么时候不用开

- 图片本身正常显示
- 上游没有防盗链

> 没事别开 —— 代理会增加你服务器的出站请求和带宽消耗。

---

## 接口测试

点数据源行的**「测试」**按钮，返回三种结果：

| 结果 | 含义 | 下一步 |
|------|------|--------|
| ✅ 成功，X 秒 | 通了，返回了内容 | 直接启用 |
| ❌ 失败 | 连不上或超时 | 检查地址、证书、是否需要 key |
| ✅ 通但拿不到内容 | 连接成功但 `list` 为空 | 地址层级不对，补 `/at/json/` |

**常见失败原因**：

1. **地址层级不对** —— 少了 `/at/json/`
2. **需要 `http://` 或 `https://` 前缀** —— 没带会直接拒绝保存
3. **上游需要密钥** —— 把 `?key=xxx` 拼到地址里
4. **上游限你的 IP** —— 换个网络试，或看它有没有公开入口
5. **你的服务器在墙内 / 被墙** —— 上游域名无法解析

测试通过后点**「启用」**，前台才会展示这个源。

---

## 下一步

- [模板开发](templates.md) —— 封面比例还不对，自己调一套模板
- [故障排查](troubleshooting.md)
