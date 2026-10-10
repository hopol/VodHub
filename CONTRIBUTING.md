# 贡献指南

感谢你愿意让 VodHub 变得更好 🎉

无论是一行文档修正、一个 Bug 修复，还是一套全新模板，都欢迎。

## 目录

- [开始之前](#开始之前)
- [提交 Issue](#提交-issue)
- [提交 PR](#提交-pr)
- [代码风格](#代码风格)
- [模板贡献的额外要求](#模板贡献的额外要求)

---

## 开始之前

1. Fork 仓库并克隆到本地
2. 确认你的环境满足 [README 的环境要求](README.md#-环境要求)（PHP 8.0+、`curl`、`pdo_sqlite`）
3. **先搜一下 [已有 Issue](https://github.com/hopol/VodHub/issues)** 和 PR，避免重复劳动

已经有一个想法想实现？**先开 Issue 讨论方向**，再动手写代码 —— 这样能省掉很多返工。

---

## 提交 Issue

### 报 Bug

提交前请确认能稳定复现。一个好的 Bug 报告需要：

```markdown
**环境**
- PHP 版本：8.2.10
- Web 服务器：Nginx 1.24 / Apache 2.4
- 操作系统：Debian 12

**复现步骤**
1. 后台 → 数据源管理 → 添加源
2. …

**期望行为**
模板切换后前台应变成 bilibili 的浅色主题

**实际行为**
前台仍然是深色，无任何变化

**截图 / 日志**
（贴后台提示、或浏览器控制台、或 php_errors.log 内容）

**我已排除的原因**
- [ ] 已确认相关文件是最新的
- [ ] 已清空 runtime/cache 后重试
```

**特别提示**：如果后台提示里带了括号和详细信息（例如 `未知操作：收到 action=xxx（映射表 16 项）`），**请把完整文字贴上** —— 那是我们加的诊断信息，能直接定位问题。

> ⚠️ **请不要在 Issue 里粘贴你的真实接口地址、访问密码或管理员密码。**

### 功能建议

请说明：

- **你想解决什么问题**（而不是"我想要 X 功能"）
- 现有功能为什么不够用
- 如果有想法，大致的实现思路

---

## 提交 PR

### 流程

```
1. 从 main 拉分支：git checkout -b fix/描述性名称
2. 修改代码
3. 自检（见下）
4. 提交 PR，填写模板
5. 等待 review
```

**分支命名**：

| 类型 | 前缀 | 例子 |
|------|------|------|
| 修 Bug | `fix/` | `fix/source-template-not-applied` |
| 加功能 | `feat/` | `feat/group-color-tag` |
| 改文档 | `docs/` | `docs/fix-typo-deploy` |
| 新模板 | `theme/` | `theme/youtube` |
| 重构 | `refactor/` | `refactor/extract-cache-layer` |

### 提交前自检

CI 会跑语法检查，但请本地先过一遍：

```bash
# 逐文件语法检查（任何一个报错都会挂 CI）
find . -name "*.php" -not -path "./runtime/*" -print0 | xargs -0 -n1 php -l
```

- [ ] 所有 PHP 文件 `php -l` 通过
- [ ] 没有提交 `runtime/` 下的任何文件（`.gitignore` 已挡，但请 `git status` 再确认一次）
- [ ] 改动只涉及本次 PR 的主题，无关的格式化/重排不要混进来
- [ ] 新增或改动功能，在 PR 描述里写清**怎么测**

### Commit 信息

用祈使句、说清"做了什么"而不是"改了哪个文件"：

```
✅ fix: 首页未传递 source 导致数据源模板不生效
✅ feat: 数据源支持按分组归类
✅ docs: 补充 Nginx 反向代理配置

❌ 修改
❌ fix bug
❌ update files
```

---

## 代码风格

项目**没有引入任何代码格式化工具**（为了保持零依赖），所以请向已有代码对齐：

### PHP

```php
<?php
/**
 * 文件顶部写清这个文件的职责。
 */

// 4 空格缩进，不用 Tab
function example(string $name): string {
    $trimmed = trim($name);
    if ($trimmed === '') {
        return '默认值';
    }
    return $trimmed;
}
```

要点：

- **必须** PHP 8.0+ 语法（项目在 `config.php` 顶部有版本保护，但不要用 8.1+ 独有的函数如 `readonly`、`enum`，否则 8.0 用户会挂）
- 类型声明尽量补全（参数、返回值）
- 面向用户的错误提示用 `⚠️` / `✅` / `❌` 开头，与现有风格一致
- 中文注释解释**为什么**，不要翻译代码字面意思

### HTML / 模板

```php
<!-- 转义一律用 h()，不要直接 echo 用户数据 -->
<span><?= h($someValue) ?></span>

<!-- PHP 短标签 -->
<?= $condition ? 'selected' : '' ?>>
```

> 注意 `?>>` 这种写法是正常的：`?>` 结束 PHP，`>` 闭合 HTML 标签。

### CSS

- 用 CSS 变量做主题，不写死颜色
- 模板专属样式只放 `templates/<模板名>/style.css`
- 公共样式才进 `static/style.css`

---

## 模板贡献的额外要求

新模板是 VodHub 最受欢迎的贡献类型之一。除了 PR 常规要求，还需要：

### 必须

1. **目录结构完整**，以下两个文件缺一不可：

   ```
   templates/<你的模板名>/
   ├── theme.json      # 必需 —— 也是「这套模板存在」的标记
   └── style.css       # 必需 —— 主题自己的样式
   ```

   > ⚠ **1.5.1 起不要再写 `header.php`**：头部全站只有
   > `templates/default/header.php` 一份，主题差异收进 `theme.json` 的
   > `style` / `search_ph`。**自带 header.php 会被契约 5 直接判红**
   > （「模板头部只有一份」那条断言）。
   >
   > 页面文件（`index` / `list` / `play` / `search` / `history` / `login` /
   > `player_script` / `footer` 与 `partials/*`）同理：**缺了会自动回退 default，
   > 能回退就回退**。只有你的模板确实需要不同内容时才自带副本，
   > 并且要把它登记进 `TPL_MAY_DIFFER` —— **与 default 逐字节相同的副本会被判红**
   > （仓库里目前只有 `bilibili/list.php` 与 `bilibili/partials/vod_grid.php`
   > 是这种「必须不同」的副本）。

2. **`theme.json` 填完整**：

   ```json
   {
     "title": "模板显示名",
     "description": "一句话说明适合什么风格的接口",
     "cover_mode": "wide",
     "columns": 5,
     "style": "templates/<你的模板名>/style.css",
     "search_ph": "搜索影片名称…"
   }
   ```

   `cover_mode` 取值：`wide`（宽图 16:9）/ `tall`（竖图 2:3）/ 其他如 `3:4`。

   > ⚠ `style` 对**非 default** 模板是**必填**（契约 5 会判）——
   > 它是 `<head>` 里那条 `<link>` 的地址。留空等于这套主题**静默丢掉自己的配色**，
   > 页面照常渲染，只是变成默认主题的样子。

3. **封面比例必须走 CSS 变量**，否则后台改了不生效：

   ```css
   :root {
       --your-prefix-cover-ratio: 56.25%;   /* 会被后台编辑器识别 */
   }

   .cover {
       aspect-ratio: var(--your-prefix-cover-ratio, 16 / 9);
   }
   ```

   编辑器能自动识别**任意前缀**的 `primary` / `primary-dark` / `radius` / `cover-ratio` / `columns` 五个维度，详见 [模板开发文档](docs/templates.md#css-变量编辑器)。

4. **必须实际跑一遍**：加载模板 → 后台改样式 → 前台确认变化 → 恢复默认。

### 加分项

- 附上前台截图（首页 / 列表 / 播放页）
- 在 PR 描述里说明它适合什么类型的内容源
- 移动端适配（860px 断点）

### 最容易踩的坑

| 现象 | 原因 |
|------|------|
| 后台改了样式前台没反应 | CSS 里用的是字面量，没写 `var()` |
| 后台没显示某个维度字段 | 变量在 `:root` 定义了，但 CSS 里没人引用 —— 编辑器会自动隐藏它 |
| 列数改了不生效 | `.vod-grid` 里要写成 `var(--list-columns, var(--your-columns, 5))` 三重回退 |

---

## 许可与授权

本项目采用 [GPL-3.0](LICENSE) 协议。

**提交 PR 即表示**：

1. 你有权以 GPL-3.0 授权该改动（你是原创作者，或已获得原作者许可）
2. 你同意将自己的改动以 **GPL-3.0** 授权给本项目
3. 你已阅读并认可本项目的许可证条款

> 请**不要**提交你无权以 GPL-3.0 授权的内容 —— 例如：
> - 从 GPL-3.0 **不兼容**协议（如 MIT、Apache-2.0、BSD）复制来的代码
> - 未经授权的第三方代码、付费素材、受版权保护的图形资源
> - 生成内容中你无法确认授权范围的部分
>
> 引入第三方代码时，请在 PR 描述里写明**来源、协议、以及为何兼容 GPL-3.0**。

如果你希望以其他协议贡献，请**先开 Issue 讨论**，不要直接提交。

---

## 行为准则

参与本项目即表示同意遵守 [行为准则](CODE_OF_CONDUCT.md)。

---

再次感谢你的时间和善意 🙌
