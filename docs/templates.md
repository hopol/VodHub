# 模板开发

VodHub 的模板是**目录即模板**：`templates/<模板名>/` 里放什么，前台就长什么样。
复制一份改个名字，就是一套新模板，**一行业务代码都不用动**。

- [模板如何被解析](#模板如何被解析)
- [必备文件清单](#必备文件清单)
- [theme.json](#themejson)
- [写页面：可用变量](#写页面可用变量)
- [CSS 变量编辑器](#css-变量编辑器)
- [从零做一套模板](#从零做一套模板)
- [常见坑](#常见坑)

---

## 模板如何被解析

解析顺序**永远是这三步**（`includes/template.php` 的 `resolveTemplate()`）：

```
1. 数据源绑定了模板？  ── 是 ──> 用该模板
              │
              否
              ↓
2. 站点默认模板（设置项 site_template）存在？
              │
              存在 ──> 用它
              ↓ 不存在
3. 回退 default
```

加载页面时（`renderTemplate()`）：

- 先按上面的顺序定下模板名
- 去 `templates/<模板名>/<页面名>.php` 找文件
- **找不到就回退到 `default/<页面名>.php`**
- 连 default 都没有 → 500「模板页面缺失」

> 回退机制保证了新模板即使写漏页面也不会白屏，但**能不回退就不回退** —— 回退意味着你那套模板在那个页面上没生效。

### 目录约定

```
templates/
├── default/                    # 全站兜底，页面文件只在这里保留一份
│   ├── theme.json
│   ├── header.php  footer.php
│   ├── index.php   list.php   play.php
│   ├── search.php  history.php  login.php  player_script.php
│   └── partials/
│       ├── vod_grid.php        # 卡片网格片段（列表页 / 搜索页）
│       ├── vod_meta.php        # 播放页 · 信息 chips
│       └── vod_side.php        # 播放页 · 侧栏影片信息
└── mytheme/                    # 一套新模板：通常只有 3~4 个文件
    ├── theme.json              # 元信息（**必需**，见下方警告）
    ├── header.php              # <head> + 顶栏 + 搜索框（与 default 的差异在这）
    └── style.css               # 模板专属样式
```

> ⚠ **1.3.12 起，一套新模板通常只需要 `theme.json` + `header.php` + `style.css`。**
>
> 页面文件（`index` / `list` / `play` / `search` / `history` / `login` /
> `footer` / `player_script` 与 `partials/*`）**不必复制** ——
> 缺失时会自动回退到 `default/`：
>
> | 缺什么 | 谁负责回退 |
> |---|---|
> | 入口页面文件（index/list/play/…） | `renderTemplate()` |
> | `partials/*.php` | `tplPartial()` |
> | 页面**内部**引用的 header/footer/player_script | `tplInclude()` |
>
> **这正是 1.3.12 删掉 31 个重复文件（1,552 行）的原因**：
> 它们与 default 逐字节相同，却让「改一个 bug 要改 5 处」——
> 而过去 8 天里那类「改了 A 忘了 B」的事故已经发生 4 次。
>
> ⚠⚠ **`theme.json` 现在不可删。**
> `tplExists()`（判断「这套模板存不存在」）的判据在 1.3.12 从
> 「有没有 `index.php`」改成了「有没有 `theme.json`」——
> 因为去重删掉了非 default 模板的 `index.php`。
> **删掉 theme.json 的后果是整套模板静默失效、且不报任何错**：
> `resolveTemplate()` 一路回退 default、模板编辑器直接拒绝保存。

---

## 必备文件清单

**新模板只有 3 个文件是必需的**，CI 缺一个就挂：

| 文件 | 作用 | 必需？ |
|------|------|:---:|
| `theme.json` | 模板名、说明、封面模式、列数。**同时是「这套模板存在」的标记** | ✅ |
| `header.php` | HTML 头 + 顶栏 + 源切换标签 + 搜索框 | ✅ |
| `style.css` | 模板专属样式（`default` 除外，它复用 `static/style.css`） | ✅ |
| `footer.php` | 页脚 | ❌ 回退 default |
| `index.php` | 首页（按源分区块列分类） | ❌ 回退 default |
| `list.php` | 分类列表 + 分页 | ❌ 回退 default |
| `play.php` | 播放器 + 选集 + 简介 | ❌ 回退 default |
| `search.php` | 搜索结果 | ❌ 回退 default |
| `history.php` | 播放历史与收藏 | ❌ 回退 default |
| `login.php` | 访问密码输入页 | ❌ 回退 default |
| `player_script.php` | 播放器初始化脚本 | ❌ 回退 default |
| `partials/vod_grid.php` | 卡片网格（列表页复用） | ❌ 回退 default |
| `partials/vod_meta.php` | 播放页信息 chips | ❌ 回退 default |
| `partials/vod_side.php` | 播放页侧栏 | ❌ 回退 default |

**只有当你要改的页面与 default 不同时，才需要自带副本。**
现有 5 套模板里只有 `bilibili` 有两个：`list.php` 与 `partials/vod_grid.php`。

> CI 还有一条**反向检查**：非 default 模板里若出现与 default **逐字节相同**的
> `.php` 文件，会直接判失败 —— 那正是这次要消灭的重复。
> 确实需要不同内容的，先改内容；改完还相同就说明它本该删掉。

---

## theme.json

```json
{
    "title": "Bilibili 风格",
    "description": "浅色粉色主题，适合封面是横图的接口",
    "cover_mode": "wide",
    "columns": 5
}
```

| 字段 | 类型 | 说明 |
|------|------|------|
| `title` | string | 后台下拉框里显示的名字 |
| `description` | string | 后台模板列表里的说明文字 |
| `cover_mode` | string | `wide` = 宽图 16:9；`tall` = 竖图 2:3；也可写 `3:4` 等。**只影响后台显示与建议值，实际比例由 CSS 决定** |
| `columns` | int | 列表页 PC 端默认列数 |

---

## 写页面：可用变量

`renderTemplate()` 会把数据 `extract()` 进模板作用域。每个页面拿到的东西不一样：

### 所有页面

| 变量 | 说明 |
|------|------|
| `$siteTitle` | 站点名称 |
| `$pageTitle` | 当前页标题 |
| `$tplName` | 当前模板名（字符串） |
| `$tplMeta` | 模板元信息数组，**含 `vars`（后台保存的样式）** |
| `$sources` | 启用中的数据源数组 |
| `$currentSourceId` | 当前选中的源 ID |
| `$source` | 当前数据源行（含 `template` 字段），未选中时 `null` |

### 列表 / 搜索页

| 变量 | 说明 |
|------|------|
| `$list` | 影片数组，元素含 `vod_id`、`vod_name`、`vod_pic`、`vod_remarks` 等 |
| `$types` | 分类数组 |
| `$typeId` | 当前分类 |
| `$page` / `$pagecount` / `$total` | 分页信息 |
| `$curName` | 当前分类名 |

### 播放页

| 变量 | 说明 |
|------|------|
| `$name` | 影片名 |
| `$pic` | 封面（**已处理过代理**，直接用） |
| `$desc` | 简介 |
| `$playUrls` | 播放地址数组：`[['label' => '正片', 'url' => 'https://...']]` |
| `$meta` | `buildMeta()` 的返回值：别名、地区、语言、上映日期、导演/编剧/主演、集数、豆瓣链接、收录时间、播放来源等。**空字段就是空串**，按「有值才渲染」处理 |
| `$playCount` | `vod_play_url` 实际解析出的集数（算「共 N 集」用） |
| `$types` / `$typeName` | 分类 |
| `$autoplay` | 是否自动播放 |

### 引入片段

```php
<?php tplPartial('vod_grid', $tplName, ['list' => $list, 'sourceId' => $sourceId]); ?>
```

片段在独立作用域执行，需要的变量要**显式传**；模板名不传则用当前模板。
片段缺失时会**回退到 `default/` 下的同名片段**，所以新模板漏写不会白屏。

现有的三个片段：

| 片段 | 页面 | 需要传入的变量 |
|------|------|--------------|
| `partials/vod_grid.php` | 列表页、搜索页 | `list`、`sourceId` |
| `partials/vod_meta.php` | 播放页 · 信息 chips | `meta`、`source`、`sourceId` |
| `partials/vod_side.php` | 播放页 · 侧栏信息 | `meta`、`detail`、`source`、`sourceId`、`playCount` |

`$meta` 由 `buildMeta()`（`includes/fields.php`）在控制器里算好，**模板不要自己拼字段** ——
解析规则（HTML 实体解码、`2026-09-16(美国)` 拆分、时长认单位、地区/语言归一…）都集中在
那里，模板只负责排版。

> 这三个片段是「字段显示在哪儿」的唯一出口。5 套模板的 `play.php` 原本是逐字节相同的
> 拷贝，字段一多就得改 5 遍、漏一个就出现「有的模板显示有的不显示」，所以抽成了片段。
> **想改播放页展示哪些字段，改片段，不要改 5 份 `play.php`。**

### 转义

**所有用户可见数据必须过 `h()`**：

```php
<?= h($item['vod_name']) ?>        ✅
<?= $item['vod_name'] ?>           ❌ XSS
```

封面图请用 `coverUrl()`，它会自动处理图片代理：

```php
<?php $pic = coverUrl($item['vod_pic'] ?? '', $sourceId); ?>
```

---

## CSS 变量编辑器

后台的「简单样式编辑」是这套机制的核心，也是**最容易踩坑的地方**。

### 编辑器怎么工作

```
模板 style.css 的 :root 块
        ↓ 自动解析
识别出 5 个可调维度（primary / primary-dark / radius / cover-ratio / columns）
        ↓ 过滤
只保留「CSS 里真的用 var() 引用了的」维度
        ↓ 后台渲染
动态生成输入框 → 用户改 → 保存到 settings 表
        ↓ 每次页面加载
header 注入 <style>:root { --xxx-yyy: 值; }</style>
```

### 关键点

**1. 变量名前缀随便你起，编辑器都认**

```css
:root {
    --my-primary: #ff6600;          /* ✅ 认 */
    --my-primary-dark: #cc5200;     /* ✅ 认 */
    --my-radius: 12px;              /* ✅ 认 */
    --my-cover-ratio: 75%;          /* ✅ 认 */
    --my-columns: 5;                /* ✅ 认 */
}
```

只要以这 5 个维度名结尾即可：`-primary`、`-primary-dark`、`-radius`、`-cover-ratio`、`-columns`（或无前缀的 `--primary` 等）。

**2. 定义了不等于会被编辑 —— 必须真的用 `var()` 引用**

```css
:root {
    --my-columns: 5;                     /* 定义了 */
}

.grid {
    grid-template-columns: repeat(5, 1fr);  /* ❌ 写死，没用 var */
}
```

结果：后台**根本不会显示**「列表列数」这个输入框（编辑器自动过滤了没人用的变量）。

**正确写法**：

```css
.grid {
    grid-template-columns: repeat(var(--my-columns, 5), minmax(0, 1fr));  /* ✅ */
}
```

> 这条规则就是为了杜绝「后台能改、前台没反应」这类隐蔽 bug —— 与其给用户一个无效的开关，不如不显示它。

**3. header 必须注入变量**

`header.php` 里要带上这段（缺了保存了也不生效）：

```php
<link rel="stylesheet" href="templates/mytheme/style.css">
<?php if (!empty($tplMeta['vars'])): ?>
<!-- 后台模板编辑器写入的 CSS 变量覆盖 -->
<style>:root { <?php foreach ($tplMeta['vars'] as $k => $v): echo h($k), ':', h($v), ';'; endforeach; ?> }</style>
<?php endif; ?>
```

### 5 个维度的类型与取值

编辑器按 `:root` 里**默认值的后缀**自动判断类型，所以你写什么后缀决定用户填什么：

| 维度 | 默认值写法 | 类型 | 用户填 | 校验规则 |
|------|-----------|------|--------|---------|
| `primary` | `#fb7299` | color | `#fb7299` | `#` + 3~8 位十六进制 |
| `primary-dark` | `#f4527c` | color | `#f4527c` | 同上 |
| `radius` | `8px` | px | `8` | 数字，自动加 `px` |
| `cover-ratio` | `56.25%` | percent | `56.25` | 数字，自动加 `%` |
| `columns` | `5` | int | `5` | `1`~`8` |

> 所以默认值**一定要带单位**（`8px`、`56.25%`），否则会被当成 `other` 类型而无法校验。

---

## 从零做一套模板

### 第 1 步：复制

```bash
cp -r templates/default templates/mytheme
```

### 第 2 步：改 theme.json

```json
{
    "title": "我的模板",
    "description": "一句话说明它适合什么内容",
    "cover_mode": "wide",
    "columns": 5
}
```

### 第 3 步：改 style.css 的 `:root`

```css
:root {
    --my-primary: #00a1d6;
    --my-primary-dark: #0080a8;
    --my-radius: 10px;
    --my-cover-ratio: 56.25%;
    --my-columns: 5;
}
```

然后把文件里所有 `var(--old-prefix-...)` 批量替换成 `var(--my-...)`：

```bash
sed -i 's/var(--\([a-z-]*\)/var(--my-\1/g' templates/mytheme/style.css
```

（注意别把 `--my-` 再叠一层，替换完检查一遍）

### 第 4 步：封面比例改为变量驱动

**这是最常见的漏改点**：

```css
.cover {
    aspect-ratio: var(--my-cover-ratio, 16 / 9);   /* 不要写死 aspect-ratio: 16 / 9 */
}
```

### 第 5 步：列数改为三重回退

```css
.vod-grid {
    grid-template-columns: repeat(var(--list-columns, var(--my-columns, 5)), minmax(0, 1fr));
}
```

三级含义：

| 层 | 来源 | 什么时候生效 |
|----|------|------------|
| `--list-columns` | 后台「站点设置 → 列表列数」 | 站点设置为非 0 时（**优先级最高**） |
| `--my-columns` | 后台「模板编辑」 | 站点设置为 0 时 |
| `5` | 你自己写的兜底 | 两者都没配时 |

### 第 6 步：改 header.php 的样式引用

```php
<link rel="stylesheet" href="templates/mytheme/style.css">
<?php if (!empty($tplMeta['vars'])): ?>
<style>:root { <?php foreach ($tplMeta['vars'] as $k => $v): echo h($k), ':', h($v), ';'; endforeach; ?> }</style>
<?php endif; ?>
```

**5 个页面 header 都要改**（`index/list/play/search/history/login` 共用同一个 `header.php`，所以只需改一处）。

### 第 7 步：自测

1. 后台 → 模板管理 → 编辑样式 → 下拉框选「我的模板」
   - ✅ 应看到 5 个输入框，**值不为空**
2. 改主题色为一个显眼的颜色 → 保存
3. 前台刷新 → ✅ 颜色变了
4. 改封面比例 → ✅ 封面比例变了
5. 改列数 → ✅ 列数变了
6. 点「恢复默认样式」→ ✅ 回到你 CSS 里写的原值

**5 个维度必须逐个验一遍** —— 任何一项没反应，就是那条链路断了。

### 第 8 步：提交

见 [贡献指南](../CONTRIBUTING.md#模板贡献的额外要求)，PR 里请附前台截图。

---

## 常见坑

### 后台改了样式，前台没反应

按顺序排查：

1. `header.php` 里有没有**注入 `tplMeta['vars']`** 的那段？（最常漏）
2. CSS 里是 `var(--xxx)` 还是**写死的字面量**？
3. 浏览器硬刷新 `Ctrl+Shift+R`，或开发者工具里勾选「停用缓存」
4. 变量名的**前缀和 CSS 里 `var()` 引用的完全一致吗**？（如 `--my-primary` 别一处写 `--theme-primary`）

### 后台某个输入框没显示

正常现象 —— 那个维度在你的 CSS 里**没有被 `var()` 引用**，编辑器自动隐藏了。想让它出现，就把 CSS 改成用 `var()`。

### 列数改了不生效

检查是不是**站点头部「站点设置 → 列表列数」设成了具体数字**。只要它非 0，就会**覆盖**模板编辑器的列数（设计如此）。设成「跟随模板默认」(0) 再试。

### 封面被裁切 / 大片留白

`aspect-ratio` 的值和实际封面比例不匹配：

| 封面实际比例 | `cover-ratio` 应设为 |
|------------|-------------------|
| 16:9 宽图 | `56.25%` |
| 3:4 | `75%` |
| 2:3 竖图 | `150%` |
| 1:1 方图 | `100%` |

### 页面报「模板页面缺失：xxx」

你的模板缺 `xxx.php`，且 `default` 里也没有。补齐文件即可。

### 新模板在后台不出现

确认 `templates/mytheme/theme.json` 存在 —— `tplExists()` 是靠**这个文件**判断模板是否有效的
（1.3.12 起判据从 `index.php` 改成了 `theme.json`，因为页面文件已可回退 default，
去重后非 default 模板不再有 `index.php`）。没有它整套模板会被忽略。
