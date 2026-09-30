# 更新日志

本项目所有值得注意的变更都记录在这里。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

### 修复：`.htaccess` 不再触发免费主机的整站 500

**现象**：传完文件打开就是 **500 白屏**，后台也进不去，换浏览器/清缓存都没用。

**原因**：旧 `.htaccess` 里有 **19 条越权指令**，分成两档 ——

| 档 | 指令 | 条数 | 需要 `AllowOverride` |
|---|---|---:|---|
| 必 500 | `Options -Indexes` ×1、`php_flag` ×4、`php_value` ×2 | 7 | **`Options`** |
| 只给 FileInfo 时 500 | `ExpiresActive` ×1、`ExpiresByType` ×11 | 12 | **`Indexes`** |

而多数免费虚拟空间只给 `FileInfo`（`RewriteEngine` 自己就要的那一类）。

> ⚠️ `<IfModule>` **救不了任何一档** —— 它只检查「模块是否加载」，不检查
> 「AllowOverride 是否允许这条指令」。`mod_autoindex`、`mod_expires`、`php_module`
> 几乎总是加载着，所以包在 IfModule 里的这些**看着很安全，实际必 500**。
> 这是它最坑人的地方。

> ⚠️ **`ExpiresActive` 属于 `Indexes` 不属于 `FileInfo`** —— 这一条与不少教程的
> 说法相反，是 2026-09-29 拿 Apache/2.4.58 **逐条实测**出来的：
> ```
> AllowOverride=FileInfo  → ExpiresActive 500「not allowed here」
> AllowOverride=Indexes   → ExpiresActive 200
> AllowOverride=FileInfo  → RewriteEngine 200 / Header set 200
> ```
> 它特别毒的地方在于：**FileInfo 恰恰是 rewrite 必需的那一类**，
> 于是会出现「rewrite 能用、却因为一句 expires 把整站打成 500」这种最难查的组合。

**做法**：`.htaccess` **只保留 FileInfo 类指令**（`RewriteEngine`/`RewriteCond`/
`RewriteRule`/`Header`），权限要求从「FileInfo + Options + Indexes」
降到「**仅 FileInfo**」—— 而 FileInfo 正是 rewrite 自己需要的，
**能用 rewrite 的主机就一定能用它**，不存在中间地带。原来那三档的职责改由
**纯文件与 PHP** 兜底，都不需要任何服务器配置：

| 原指令 | 需要 | 现在靠什么 | 为什么不需要 AllowOverride |
|---|---|---|---|
| `Options -Indexes` | Options | 各目录下的空 `index.html`（`static/`、`templates/`、`static/img/`、`static/js/`，以及运行期新建的 `runtime/`、`runtime/cache/`、`static/imgcache/`、`runtime/rl/`、`runtime/queue/`） | `DirectoryIndex index.html` 是 **Apache 主配置的默认值** |
| `php_value` / `php_flag` | Options | `includes/guard.php` 的运行时 `ini_set()` | PHP 自己的接口，与 Apache 无关，**覆盖全部 SAPI** |
| `ExpiresActive` / `ExpiresByType` | **Indexes** | `mod_headers` 的 `Cache-Control` | 只要 FileInfo；现代浏览器以它为准，实测功能无差别。`expires` 整段**只保留在 `docs/deployment.md`**，用之前先确认主机给了 `Indexes` |

**实测矩阵**（Apache/2.4.58，同一份 `.htaccess` 只改主配置）：

| `AllowOverride` | 结果 |
|---|---|
| `None` | ✅ 200 —— 文件完全不解析，没有 500，但访问控制也全没了 |
| **`FileInfo`** | ✅ **200 —— 本次改造的目标档位** |
| `FileInfo Indexes` / `All` | ✅ 200 |
| `Indexes` / `Limit` / `AuthConfig` / `Options`（不含 FileInfo） | ❌ 500 `RewriteEngine not allowed here` |

**验证**：真实 Apache 下（老布局 + `AllowOverride FileInfo`）——
8 个入口全部 200/302；`runtime/data.db`、`runtime/cache/*`、`runtime/php-errors.log`、
`templates/*.php`、`templates/*/theme.json` 全部 **403**；页面静态直出正常；
静态资源带 `Cache-Control`；**`.htaccess` 错误 0 条**。

### 新增：数据目录可移出 Web 根（不依赖 `.htaccess` 的安全基线）

`data.db` 是 `data.db` —— Apache 对**非 `.php` 文件**的请求直接读磁盘吐字节，
**全程不进 PHP**，所以任何「在 PHP 里判断一下」的拦截都无效。
唯一与服务器配置无关的解法是**不把它放进 Web 根**。

`config.php` 新增 `vhDirUsable()` / `vhResolveDataDir()`，三级解析：

1. 显式指定（`VODHUB_DATA_DIR` 常量或环境变量）
2. **`runtime/data.db` 已存在 → 原地不动**（自动切换等于静默丢库，绝不自动搬）
3. 全新安装 → 优先建站点根的兄弟目录 `../vodhub-data/`（Web 不可达）
4. 都不成 → 回退 `runtime/`（此时 `.htaccess` 拦截是唯一防线）

导出 `DATA_DIR_OUTSIDE` 常量，供后台与 `vp.php` 判断安全等级。
**判据是试写不是 `is_writable()`** —— 共享主机上父目录常显示可写、实际 `mkdir` 被拒。

> ⚠️ 移出后**面板备份只覆盖 Web 根**，`../vodhub-data/` 要单独备份。
> 这是物理隔离的真实代价，写进了部署文档。

### 新增：安全基线自检（后台第一屏 + `vp.php` I 节）

三项都是「**主机配置层**」问题，PHP 代码本身管不着，但必须能被准确说出来：

- **`.htaccess` 权限类别逐行审计**（`guardHtAudit()`）：按 Apache `AllowOverride`
  五类归类，把越权指令的**行号**直接报出来。不看 `<IfModule>`，只看指令名 ——
  否则包在 IfModule 里的 `Options -Indexes` 会被漏判。
- **会话目录泄露面**（`guardSessionExposure()`）：`sess_<ID>` 的**文件名就是会话 ID**，
  目录可列 + 文件可下载 = 直接伪造 `vodsite_sid` 进后台，**完全绕过密码** ——
  比 `data.db` 泄露更直接。`guardGcSessions()` 早就检测了同一件事，
  但只把它当 GC 触发条件，没当泄露面。
  实现上**不能只靠 `realpath()`**：它对不存在的路径返回 `false`，
  而会话目录恰恰常常还没建（PHP 到第一次 `session_start()` 才创建），
  只判 realpath 会把最该拦的情况报成安全 → 现在退回字面路径比对。
- **目录列表挡板**：各目录空 `index.html` 是否就位。

后台与 `vp.php` 的判定条件一致：
**`.htaccess` 无越权指令 且（数据在 Web 根外 或 有 rewrite）且 会话不在站内** → 达标。

### 修复（验证过程中发现，与上面三条无关的**原有缺陷**）

- **`.htaccess` 的 `<FilesMatch "\.php$"> Header set Cache-Control` 把 `img.php`
  自己发的缓存头盖掉了**：
  `img.php` 精心发的 `Cache-Control: public, max-age=604800` 被覆盖成
  `no-cache, must-revalidate` → **每一张走 `img.php` 的图都会被浏览器反复回源**，
  白白烧月流量与 EP，而且**完全不报错**、极难发现（Apache 实测复现）。
  改成 `Header setifempty` —— 语义是「PHP 没发才补」：
  - PHP 发了自己的（`img.php` 的 max-age、`enrich` 的 no-store）→ **原样保留**
  - PHP 没发（`renderTemplate()` 的正常渲染路径）→ 补 `no-cache`，HTML 不被缓存

  > 附带踩到并记下来的坑：Apache 的 `.htaccess` **只认行首 `#` 为注释**，
  > 我一度在里面写了 `<!-- -->`，直接 500
  > （`Expected </!--> but saw </IfModule>`）—— 文件自己的注释里早就警告过这一点。

- **后台每加载一次就往错误日志灌 20 行 Warning**：
  `requireAdmin()` 在鉴权通过后调 `sessionRelease()` 放锁，`session_status()` 回到
  `NONE`；页面一开始输出，模板里的 `csrfToken()` 又要 `sessionStart()` ——
  后台一个页面有十来个 `<input name="csrf">`，于是**每次加载 20 行**
  `session_name(): Session name cannot be changed after headers have already been sent`。
  这不是噪音：错误日志被 `guardGcLog()` 截断在 2 MB，这种刷屏会让日志反复被截，
  **真正的故障反而出现在被冲掉的那一段里**；开着 `display_errors`
  （`VODHUB_DEBUG=1` 排障）时这些字节还会**直接混进 HTML 把页面冲坏**。
  两处一起修：
  - `sessionStart()` 在 `headers_sent()` 时直接返回（`$_SESSION` 是 release 前的
    副本，读它够用），不再硬调；
  - `requireAccess()` / `requireAdmin()` 在**放锁之前**先调 `csrfToken()` 把令牌落盘，
    否则令牌只存在于内存，下一次 POST 校验会「表单已过期」。

- **未知 `action` 从「优雅提示」变成 500 白屏**：
  `includes/admin-actions.php` 的 `$_ADMIN_ACTION_MAP` 定义在**文件顶层**（全局作用域），
  而 `adminHandlePost()` 函数内没加 `global` —— `$handler = $_ADMIN_ACTION_MAP[$action] ?? null`
  有 `??` 兜着、告警被抑制；最后那句 `count($_ADMIN_ACTION_MAP)` **没有兜底**，
  于是直接 `Fatal TypeError`。而那句提示恰恰是排查
  「服务器上的 `admin-actions.php` 版本不对」的**唯一线索**，自己先把页面打死了。
  已补 `global`。

### 变更

- `dist/build.sh` 三条硬校验：① 运行期数据不进包 ② **开发/发布产物不进包**
  （`.git/`、`dist/`、`.github/`、`*.zip` —— 站点根出现 `.git/` 就是整站源码公开可下载，
  这一条与服务器配置完全无关，纯靠打包阶段挡住）③ **`.htaccess` 权限红线**：
  含 Options 类指令直接拒绝打包。
  清单同时并入 `git ls-files --others` —— `git diff` 不含未跟踪文件，
  而新增的 `static/index.html` 等正是挡住目录列表的那些，漏了极难从现象反查。
- `.user.ini` 头部说明改写：mod_php 主机现在**只靠 `guard.php`**（`php_value` 已移除）。
- `docs/deployment.md` 新增「`.htaccess` 现在只用一类权限」与「数据目录」两节；
  `docs/troubleshooting.md` 新增「上传后整站 500 白屏」「会话目录落在 Web 根之内」两条。
- **无数据库变更**（`schema_version` 仍为 5）。

---

## [1.3.2] - 2026-09-29

**主题：1.3.1 上线当天暴露的三个问题** —— 数据源标签栏消失、清缓存后 502、
`.htaccess` 丢了却没人知道。**无数据库变更**（`schema_version` 仍为 5），
覆盖文件即可升级、还原文件即可回滚。

### 修复

- **`?source=N` 页面顶部的数据源标签栏整个消失**（点进某个源就再也切不回去，
  看着像「导航链接全坏了」）：`index.php` 把 `$sources` 过滤成只剩当前源再传给模板，
  而页头是按 `count($sources) > 1` 决定渲不渲染标签栏的 —— 单源时必然不渲染。
  实测无参首页 16 个标签、`?source=13` **0 个**。
  现在拆成两个变量：`$sources` = 全部源（页头导航用）、`$pageSources` = 过滤后（内容区用），
  模板改用 `$pageSources ?? $sources`（老模板没传就退回原行为）。

- **清空缓存后短暂 502**：1.3.1 让首页在本地没分类时自己补拉，但**没给单次请求设上限** ——
  清完接口缓存的第一波访问是 N 个源**串行**、每个最多 4 秒，15 个源最坏 **60 秒**，
  必然撞上网关超时。现在加 **6 秒预算**（`WARM_BUDGET`）：用满就不再出站，
  拉不完的下次访问继续（已成功的都已落盘，进度不丢），本页标成残页、不写静态缓存。
  最坏耗时 6 + 4 = **10 秒**，稳在网关超时之内；上游健康时 15 个源通常 4~5 秒就能全部补齐。

- **`.htaccess` 丢失无人知晓**：它是全站唯一能拦 `runtime/` 与 `templates/` 的文件，
  却也是传输链路上最容易丢的点文件（FTP 过滤、zip 解压跳过、镜像同步反删）。
  1.3.1 上线当天连续踩到两次，后果是 **`runtime/data.db` 可公开下载**（密码哈希 + 接口地址）。
  现在**后台第一屏常驻红色告警**：文件不在或不含 `RewriteEngine On` 就一直显示，
  并写明「单独上传、别用 zip 解压、别用镜像同步」。

### 变更

- 版本号 `APP_VERSION` → **1.3.2**；`pcVersionGate()` 会在升级后的首个请求
  自动作废上一版的静态桶，新代码立即生效、无需手动清缓存。

---

## [1.3.1] - 2026-09-28

**主题：把 1.3.0 上线当天暴露的三个问题一次修干净** —— 首页分类拿不到、
静态直出从来没生效过、`clearCache()` 一直是空转的；后台加上版本号显示。
全部为 bug 修复与小增强，**无数据库变更**（`schema_version` 仍为 5），
**文件覆盖即可升级，回滚只需还原文件**。

### 修复

- **首页拿不到数据源分类（1.3.0 回归）**：`VodClient::getTypesLocal()` 在
  「本地没有分类缓存」时**直接返回空结果、永不出站**，而首页是分类的**唯一**
  展示入口 —— 拿不到分类时连「浏览全部 →」都不渲染，站内再没有路径能把这份缓存
  填回来；后台「测试连接」走 `probe()` 是直连上游、不落盘，于是出现
  「后台测试正常、首页却全空」。触发条件很常见：后台「清理系统缓存」默认勾选
  「接口响应缓存」，点一次首页就永久空白（线上 `hop.free.je` 实测 15 个源全空、
  `index.php?source=1` 在缓存被列表页补上后立刻恢复 32 个分类）。
  现在改为：**本地有数据（哪怕过期）仍然只读本地、零出站；一条都没有时补拉一次**，
  拉到就落盘自愈；上游不通时由 60 秒负缓存兜住，不会每次访问都重复出站。
  同时空态文案带上失败原因（新增 `VodClient::lastMsg()`），不再只有一句
  「无法获取分类」。

- **残页被小时静态缓存冻住**：上游恰好在「冷缓存」那一瞬不通时，渲染出的
  「无法获取分类」会被写进 `c/<时间桶>/index.html`，**接下来整整一小时所有访客
  都看它**，而缓存 60 秒后其实已经能补上。新增 `pcVolatile()`：拿到空分类时把
  该次渲染标成残页，`renderTemplate()` 跳过落盘。

- **换完代码要等整点才生效**：新增 `pcVersionGate()` —— `APP_VERSION` 变了
  （或桶里有 HTML 却没有版本标记，即上一版留下的）就把静态桶整体作废。
  只要这次请求跑到了 PHP 就立刻生效，不用手动清缓存、也不用等小时翻篇；
  **`.htaccess` 直出命中时 PHP 不跑**，那种主机仍需等整点或手动清一次。
  版本标记 `c/.v` 在目录尚未存在时也会先补写，避免「本版刚生成的页被自己
  在下一次请求误判成上一版残留」。
  两处新函数都用 `function_exists()` 包住，升级包漏传 `pagecache.php` 时
  只是少一次自愈，不会把前台打死。

- **`.htaccess` 静态直出从 1.3.0 起就一次都没命中**：规则里用的 `%{TIME_YMD}`
  **在 Apache 2.4 里根本不存在**，它静默展开成空串 —— `-f` 检查的路径变成
  `c/-19/index.html`，永远找不到文件，「命中时 0 个 PHP 进程」这条支柱在
  **所有 Apache 主机上都没生效**，而 rewrite 轨迹里只有一行
  `input='.../c/-19/index.html' => not-matched`，不报任何错。
  改用 `%{TIME_YEAR}%{TIME_MON}%{TIME_DAY}`。本地 Apache 2.4.58 开
  `rewrite:trace6` 实测：`TIME_YEAR/MON/DAY/HOUR/MIN/SEC/WDAY/TIME` 全部正常，
  **唯独 `TIME_YMD` 是空的**。

- **PHP 与 Apache 用的不是同一个钟**：日期修好之后，只要 Apache 的系统时区
  不落在 PHP 猜的三份候选（应用时区 / 主机 ini 时区 / UTC）里，仍然永远命不中
  （本地实测 Apache `TZ=Europe/London`，连着两次请求都回源）。
  现在 `.htaccess` 开头把 Apache 自己的桶名写进环境变量 `VH_BUCKET`，
  `pcBuckets()` 照着写 —— **写与读由同一条规则决定**，时区不再是命中条件；
  没有这个变量（`.htaccess` 未生效 / Nginx）自动退回原三候选，行为不变。

- **`VodClient::clearCache()` 一直是空转的**：缓存文件名是
  `md5(baseUrl . 查询串)`，而它拿 `md5(baseUrl)` 去 `str_starts_with` ——
  两个 md5 没有包含关系，`unlink` **一次都不会执行**，删数据源时那份缓存
  原样留在 1 GB 主机上，只能等 guard 的 LRU GC 收走。
  文件名改为 `<md5(baseUrl)>_<md5(查询串)>.json`（前缀即源指纹），
  `.neg` 负缓存一并清；**旧命名在首次读到时原地改名**，暖缓存不作废、
  升级后不必重新打一轮上游，一直没被读到的旧文件交给 LRU GC
  （或后台勾「接口响应缓存」一次清光）。

### 新增

- **后台顶栏显示当前源码版本**（`当前 1.3.0`，取自 `config.php` 的 `APP_VERSION`）。
  `config.php` 是旧版、没定义该常量时如实显示「未知 · config.php 是旧版」——
  这行本身就是个诊断点，不猜版本号。

- **`vp.php` 文件指纹表同步到当前发布版**：`admin.php` / `includes/guard.php` /
  `img.php` 在 `da575cc` 之后又被改过、指纹没跟上，B 节会把「已传最新版」误报成
  「旧!」，把排查带偏。C 节同时补检 `pcVolatile()` / `pcIsVolatile()` /
  `pcVersionGate()`。

---

## [1.3.0] - 2026-09-28

这一版的主题是**极致低功耗**：让免费虚拟主机的配额（EP / 磁盘 / CPU / inode）
基本感知不到本项目的存在，同时**一个功能都不砍**。
方案与量化推导见《VodHub 极致低功耗模式实施方案》。

设计上的三条硬约束，全部写进了验收清单：

1. **稳态接近零 EP** —— 优化的目标不是「快 30%」，是「根本不进 PHP」；
2. **磁盘必须有硬上限** —— 1 GB / 5 GB 是唯一决定性的容量配额，而磁盘满
   不表现为变慢，而是逐层崩坏（图片写不进 → 页面缓存写不进 → 接口缓存
   写不进 → SQLite 白屏 → 会话失效）；
3. **所有功能照常** —— 分类、分页、搜索、播放、选集、图片代理、字段富化、
   后台、历史收藏、访问密码、模板切换，一个都不许砍。

### 新增

- **页面静态化（支柱一）**：`index` / `list` / `play` / `history` 四类前台页
  渲染后落盘到 `c/<时间桶>/<key>.html`，由 `.htaccess` 条件重写**直接由
  Apache 直出 —— 命中时 0 个 PHP 进程**。
  - 时间桶 `%{TIME_YMD}-%{TIME_HOUR}` 让静态文件**每小时自动过期**，
    无需 cron、无需手动清缓存；超过 2 小时的桶由 `includes/guard.php` 删除。
    **没有那条 GC，`c/` 会以约 192 MB/天 增长，1 GB 磁盘 5 天就满。**
  - PHP 侧同时提供**第 2 层兜底**：`renderTemplate()` 先读盘，读到就直接
    `readfile()`。rewrite 不可用（Nginx、禁用 `mod_rewrite`）、或服务器时区
    与 PHP 对不上时，退化为 **1 个进程 × 约 3 ms**（实测 150 ms → 33 ms）。
  - **时区防御**：Apache 取服务器本地时区，PHP 取 `config.php` 里的
    Asia/Shanghai，两者很可能不一致。写入时把「应用时区 / 主机 ini 时区 / UTC」
    三种候选桶**各写一份**，并同时生成小时补零与不补零两种名字。
    对不上时**不会读到错误内容，只是永远回源**——靠第 2 层兜底。
  - **安全红线**：`access_enabled = 1` 时 `pcEnabled()` 既不读也不写，
    `c/` 下没有文件，`.htaccess` 的 `-f` 自然不成立 → **无法绕过访问密码**。
    这一点已作为硬验收项实测通过。
  - `search` / `login` **刻意不静态化**：搜索词不可预测（也不能拿关键词当
    文件名，任意 Unicode 有路径穿越风险），登录页必须走 CSRF 与会话。

- **图片代理本地化（支柱二）**：代理功能 100% 保留，只把「每次」变成「首次」。
  - `img.php` 成功取图后落盘到 `static/imgcache/<sha1>.<ext>`（`.tmp` + `rename` 原子写）；
  - `coverUrl()` 命中本地副本时**直出静态 URL**，Apache 静态服务 → 0 EP、0 出站；
  - `img.php` 收到本地已有副本的请求时直接回吐字节，**不做 DNS、不出站**。
    文件只可能来自「已通过三重 SSRF 校验的同一 URL」，且此时**不发起任何新
    请求**，所以即便域名此后被改指向内网也不会造成 SSRF，只是回吐已有字节；
  - 落盘失败（磁盘满 / 无权限）**静默降级**为继续走 `img.php` 转发，功能不受影响；
  - 超限按 `filemtime` LRU 淘汰，被淘汰的图下次访问会重新拉一次——
    **自愈，功能永不缺失**，只是那一张再付一次 EP。
  - ⚠️ 澄清一个容易搞错的点：本地化省的是 **EP 和出站到上游的请求**，
    **不减少「发给访客的字节数」**。月流量配额只看发出去的字节，
    图片走 `img.php` 还是走 `static/imgcache/` 对配额而言是一样的。

- **富化内联（支柱三）**：`play.php` 在 `enrichCached()` 命中时把归一化字段
  **直接渲染进首屏**，容器标 `data-enrich="done"`；`app.js` 改为只在
  `data-enrich="pending"` 时才请求 `enrich.php`。
  - 以前**即使富化结果 30 天前就入库，浏览器仍会为它发一次 `enrich.php`**
    （1 个 EP + 一次会话启停 + 3 次 SQLite 查询）——纯浪费，数据早就在服务端手上；
  - 现在缓存命中 → **0 额外请求**；未命中 → 仍是 1 次，但**首屏直接就是
    归一化字段**（以前是先显示原始字段再闪一下替换）。**省资源的同时体验更好。**

- **访客路径零阻塞（支柱四）**：`VodClient::request()` 重写。
  - 上游超时 **12 s → 4 s**、连接超时 6 s → 3 s（`img.php` 同步下调）；
  - **失败写 60 秒负缓存** —— 失败期间**完全不出站**。这是消灭
    「上游一挂，每次点击白等 12 秒」这个故障放大器的关键；
  - **过期数据有界刷新**：1 秒内能成就用新的，不成就直接用旧的。
    （方案原稿是「立即返回 + 刷新队列」，实现时合并为一步——最坏阻塞 1 s
      仍远低于方案给访客的 2 s 预算，却省掉整套队列文件与后台按钮）；
  - **搜索词不落盘**：缓存文件名含 `wd`，爬虫每搜一个词就永久多一个文件，
    TTL 过期只覆盖内容、不删文件。这是 1 GB 磁盘上最快失控的增长链。
    搜索本就要求实时，落盘价值低、磁盘与 inode 风险高；
  - **首页只读本地分类**：新增 `VodClient::getTypesLocal()`，5 套模板的首页
    全部改用它。分类 24 小时不变，显示一小时前的列表用户无感，但首页从
    「N 个源串行、冷缓存最坏 N × 12 s、必然 504」变成**恒定 <200 ms 且零出站**。

- **热路径微秒化（支柱五）**：
  - **`dbInit()` 短路**：`$defaults` 里有一行 `password_hash()`，bcrypt 刻意
    慢，**实测单次 46.8 ms、占整个 `dbInit()`（50.7 ms）的 93%**，而这些键
    安装后早已存在、`ON CONFLICT DO NOTHING` 一次都不会写入——等于**每个请求
    白付 47 ms 纯 CPU**。现在确认「schema 已到目标版本且管理密码键非空」后直接
    return。**实测 `dbInit()` 50.7 ms → 0.35 ms（−99.3%）**；短路点放在所有
    迁移分支之后，**老库升级与首次安装路径完全不受影响**。
  - **释放会话锁**：新增 `sessionRelease()`，`requireAccess()` / `requireAdmin()`
    鉴权通过后立即 `session_write_close()`。PHP 的 files 处理器会把排他锁持有到
    脚本结束（**含上游 curl 之后**），同会话的第二个请求会被串行阻塞。
    锁持有时长从「整页」缩到约 1 ms。POST/CSRF 路径需要写时会重新打开。
  - **SQLite WAL**：`journal_mode=WAL` + `synchronous=NORMAL` + `busy_timeout=3000`。
    读写不再互斥、fsync 大幅减少、`database is locked` 变成「等 3 秒」。
    ⚠️ 会生成 `data.db-wal` / `data.db-shm`，**面板备份要连它们一起拷**；
    不支持 WAL 的主机自动退回默认模式（不报错）。
  - **生产错误输出三层兜底**：`.user.ini`（CGI/FPM/LSAPI）、`.htaccess` 的
    `php_value`（mod_php，用 PHP 8 的正确模块标识 `php_module`，老教程写的
    `mod_php.c` 在部分发行版上匹配不到）、以及 `includes/guard.php` 里的运行时
    `ini_set`（**不依赖任何主机配置**）。三层缺一层都可能失效，所以三层都写。
    `config.php` 里那句 `ini_set('display_errors','1')` **故意不改**
    （那是给本地排障留的），需要重新打开时设环境变量 `VODHUB_DEBUG=1`。

- **护栏（支柱六）** —— 新增 `includes/guard.php`，在 1 GB 磁盘上这一支柱
  **升为第一优先级**，因为磁盘是唯一决定性的容量配额：
  - **按档位的硬上限**：紧凑档（1 GB）页面缓存 300 个/15 MB、图片缓存 60 MB、
    接口缓存 600 个/30 MB，合计约 132 MB；标准档（5 GB）分别为 1000 个/40 MB、
    200 MB、2000 个/100 MB，合计约 387 MB；
  - **磁盘水位四档**：>30% 正常 / 15~30% ×0.6 / 8~15% ×0.5 / **<8% 进入保护模式，
    停止写入页面与图片缓存**（页面只读、图片走 `img.php` 转发）——
    **降速但不停摆**。8% 是硬红线：SQLite 满盘会报 `disk is full`，
    会话文件写不进去会让所有登录态失效；
  - **GC 触发**：按 `runtime/gc.last` 的 mtime 节流，**每 5 分钟最多跑一次**
    （不用概率触发——低流量站点靠 `random_int(1,20)` 会几乎永远不跑，
     而磁盘恰恰在「没人管」的时候最危险）；任何一项 GC 抛异常都不影响其余项；
  - **按 IP 限流**：`search` 20 次/分、`play` 30 次/分、`img` 120 次/分，
    超限返回 **429 + Retry-After**（而不是 503）——日志里才能分清
    「被限流」和「EP 打满」；
  - **错误日志 2 MB 截断**、会话文件 24 小时清理（仅当会话目录在本站内
    **且** `session.gc_probability = 0` 时才动手，不误伤共享目录里的别人）。

- **后台「极致低功耗状态」**：实时显示磁盘可用百分比、当前档位、本档各项上限、
  页面静态缓存与图片本地缓存的体积、页面静态化是否启用（开启访问密码时会
  明确标注「按安全要求自动停用」）。磁盘低于 8% 红线时红字提示。
- **后台「容量档位」设置**（站点与维护）：`自动探测` / `紧凑（1 GB 空间）` /
  `标准（5 GB 及以上）`。**磁盘 ≤1 GB 请手动选紧凑**——不少主机的
  `disk_total_space()` 拿到的是整台服务器的磁盘而不是你的配额。
- **后台清理范围扩展**：新增「页面静态缓存」「图片本地缓存」两个勾选项。
- **新增 `robots.txt`**：Disallow `search.php` / `list.php?` / `img.php` /
  `admin.php` / `enrich.php` / `c/` / `runtime/` / `templates/`，**保留首页与播放页
  可抓**（有 SEO 价值，且它们已静态化，抓取成本≈0）。爬虫不只耗 EP，还耗磁盘。
- **新增 `.user.ini`**：生产 PHP 配置（错误输出、OPcache、会话 GC）。
- **新增 `vp.php` 升级体检脚本**（**用完删除**）。只读诊断，五节输出：
  ① 环境（PHP 版本、**SAPI**——决定 `.user.ini` 还是 `.htaccess php_value` 生效、时区）；
  ② **13 个关键文件的字节数 + md5 前 12 位与 v1.3.0 应有值逐一对比**，标 `OK/旧!/缺!`
  —— 一眼看出到底哪个文件没传对，终结「我明明传了」的来回；
  ③ 代码层：关键常量与函数是否就位，**并直接实测 `pcSyncGate()`**（首页 500 的死因）；
  ④ 三个新目录是否可写；
  ⑤ OPcache：内存/是否已满/脚本数/`validate_timestamps` 实际值 + 尝试 reset；
  ⑥ 按上面结果自动生成「建议动作」。
  ⑦ **后台段落完整性**：读 `admin.php` 源码列出应有 9 个段落与 6 个入口标记，
     据此识别它是 1.1.x / 1.2.0 / 1.3.0 —— 解决「后台某个功能段不见了」这类问题。
     **口诀：源码有 + 后台没看到 = 页面截断；源码就没有 = `admin.php` 旧版。**
     例：找不到「数据备份还原」，G 节会告诉你它其实叫「📦 配置导入导出」、
     在整页最底部、且是 v1.2.0 才有的。
  包里**有没有 `vp.php` 就是「拿没拿到最新包」的判据**。
- **新增 `includes/pagecache.php` / `includes/imgcache.php`**。
- **配置变更 → 页面静态缓存全量作废**：`setSetting()` 末尾调用 `pcClear()`。
  `.htaccess` 读不到 SQLite（`RewriteMap` 在 `.htaccess` 中不可用），只能由
  PHP 主动删 —— 这是「改了配置前台没反应」这个最伤体验 bug 的唯一可靠防线。

### 修复

- **`disk_free_space` / `disk_total_space` 被主机禁用 → 前台再次 500（第四轮）。
  这次是 InfinityFree（`hop.free.je`，路径 `infinityfree.com/if0_.../htdocs/`）。**

  用 `vp.php` 的 C 节在**线上**直接拿到死因：

  ```
  B 节：13 个文件全部 OK      ← 升级包确实完整覆盖了
  C 节：APP_VERSION=1.3.0 / PAGE_CACHE_DIR=... ← config.php 也确实是新版
        NG! pcSyncGate()  Error: Call to undefined function disk_total_space()
  ```

  **两个我自己的错误**：

  1. **`@` 只能抑制 Warning，抑制不了 `Error`。** 原代码是
     `@disk_free_space()` / `@disk_total_space()` —— 函数被 `disable_functions`
     禁用时抛的是 `Error`，`@` 毫无作用，直接冒到 `renderTemplate()` 把整站
     前台打死成空体 500（后台因有 `try/catch` 反而没事，所以两边表现不一致）。
     这是「可选的性能优化不该有把整站打死的权限」这条原则的**第四次**应用
     ——前三次修的是常量，这次漏了「函数可能被禁用」。

  2. **后台提示文案写死了 `config.php` 归因。** 用户照着去补传 `config.php`，
     文件本来就是对的，白折腾。**归因必须跟着错误类型走。**

  修复：

  - `guardDisk()` 加**双层防护**：`function_exists`（覆盖 `disable_functions`）
    + `try/catch (Throwable)`（兜底），并返回 `probe` 状态（`ok`/`disabled`/`failed`）；
  - 探测不可用时 `guardCaps()` **按后台「容量档位」设置**决定上限
    （`auto` 且探测不到 → 取**保守的紧凑档**：猜大了让 1 GB 主机拿 387 MB 预算，
     猜小了只损失一点缓存容量，两害相权取其轻），**水位控制停用但硬上限仍生效**；
  - `admin.php` 新增 **`lpDiagnose()`**，按错误类型分四类给不同处理建议：
    `disk_*` 被禁 → 「与升级包无关，手动选容量档位」；
    其它 `undefined function` → 「跑 vp.php 的 H 节」；
    `Undefined constant` → 「config.php 是旧版」；
    `Failed opening required` → 「升级包缺文件」；
    其它 → 原样贴出来提 Issue；
  - 状态栏在探测不可用时显示 **「磁盘可用 探测不可用」**，
    不再显示自相矛盾的「100%（0 B / 0 B）」，并加一条黄底说明其影响面；
  - `img.php` 的 `gethostbynamel()` 同样加 `function_exists` —— 它也被 `@` 包着，
    且禁用时**必须拒绝代理**（`503 resolver disabled`），
    宁可图片不显示也不能放开 SSRF 校验缺口；
  - `guard.php` 的 `glob()` 调用同样加保护（禁了就跳过 GC，改用后台清理按钮）。

  验证（Apache + `disable_functions=disk_free_space,disk_total_space`）：

  | 路径 | 修复前（线上） | 修复后 |
  |---|---|---|
  | `/index.php` `/history.php` `/list.php` `/search.php` `/play.php` | **500 空体** | **全部 200** |
  | 后台状态栏 | 「无法显示（Call to undefined function disk_total_space()）—— 升级包没有完整覆盖」 | 正常显示 + 黄底说明「被禁用、与升级包无关、硬上限仍生效」 |
  | 页面完整性 | — | 30,099 B，系统缓存 / 配置导入导出 / OPcache 勾选俱全 |

- **升级后首页 500（空响应体）—— 漏传 `config.php` 会打死整站前台。**
  线上实测（`tv.ieo.de5.net`）：静态文件与 `/admin.php` 全 200、
  `img.php` 返回正常的 400、`enrich.php` 返回 200，**唯独 5 个前台页
  `index / list / search / play / history` 全是 500 + `Content-Length: 0`**。

  根因：1.3.0 在 `config.php` 里新增了 4 个常量，而**线上那份还是 1.2.0 旧版**。
  `renderTemplate()` 第一行 `pcSyncGate()` 读 `PAGE_CACHE_DIR` 直接抛
  `Error: Undefined constant`；`display_errors=Off` 让它**不打印**，
  于是变成空体 500 —— 既无报错文本也无半截页面，极难从表象定位到「漏传一个配置文件」。

  **为什么只有那 5 个页挂**：只有它们走 `renderTemplate()`。
  `img.php` 不走、`enrich.php` 只 require 不调用、`admin.php` 自己输出 HTML、
  静态文件不进 PHP —— 这条差异就是判据。

  本地完整复现并验证修复：

  | config.php | 前台 5 页 | 后台状态栏 | OPcache 按钮 |
  |---|---|---|---|
  | 1.2.0 旧版（修复前） | **500 空体** | 段落整个消失 | **不见了** |
  | 1.2.0 旧版（修复后） | **全部 200** | 「⚠️ 无法显示（config.php 是旧版…）」 | ✅ 在 |
  | 1.3.0 新版 | 全部 200 | 正常显示 | ✅ 在 |

  **修复原则：可选的性能优化，不该有把整站打死的权限。**
  - `includes/guard.php` 自带 `PAGE_CACHE_DIR` / `IMG_CACHE_DIR` 默认值
    （它被 pagecache 与 imgcache 共同依赖，放一处即覆盖两者）；
  - `includes/client.php` 自带 `CACHE_TTL_NEG` 默认值 60 秒
    （否则上游一挂、每条失败请求都读它 → 前台全 500）；
  - 后台状态栏的判据改为 **`APP_VERSION`** —— 它**没有**兜底默认值，
    只有新版 `config.php` 才 define，因此「漏传 config.php」依然会被提示出来，
    只是从「致命」降级为「待补」。
  - 静态化、图片本地化、限流、GC 在两种 `config.php` 下均验证工作正常
    （生成 9 个 HTML / 3 个桶，二次访问 3/3 命中 0-EP）。

（本次排查的主诉）。
  功能从未被删 —— `clear_opcache` 复选框那段在 v1.2.0→v1.3.0 的 diff 里
  是**未改动的上下文行**。真正的原因是**页面在到达它之前就断了，且被静默吞掉**：
  - 生产环境 `display_errors=Off`（`includes/guard.php` 会关掉它），
    PHP 致命错误不再打印 → **页面无声无息地截断**，看起来「后台正常打开、
    但下半部分没了」；
  - 而我新增的「极致低功耗状态」栏恰好**紧挨在「系统缓存」之前**，
    它依赖 1.3.0 新增的函数与 `config.php` 新常量 —— **文件没传齐就 fatal，
    正好把下面那个唯一救场按钮一起带下水**。
    「传了文件没变化要清 OPcache」与「清 OPcache 的按钮不见了」撞在一起，
    是最坏的一种故障形态。

  两条修复：

  1. **状态栏故障隔离**（`admin.php`）：用依赖自检 + `try/catch` 包住整段，
     缺函数或缺常量时**跳过状态栏并给出明确原因**，下方「系统缓存」照常渲染。
     实测「漏传 `config.php`」场景：修复前页面 24,255 B、**系统缓存=0**；
     修复后 29,396 B、**系统缓存=1、OPcache 复选框=1**、状态栏显示
     「无法显示（常量 PAGE_CACHE_DIR 未定义（config.php 是旧版））」。
  2. **致命错误可见化**（`admin.php` 顶部 `register_shutdown_function`）：
     在任何 `require` 之前注册，接管 `E_ERROR / E_PARSE / E_CORE_ERROR / E_COMPILE_ERROR`，
     在已渲染的页面末尾追加一块红色提示，写明**错误信息、文件与行号**，
     并直接点出「最常见原因是升级包没传齐、43 个文件必须一次传完」。
     实测「漏传 `guard.php` / `pagecache.php`」：修复前 715～807 B 的**空白半截页**；
     修复后页面末尾有可读的诊断块。
     保留已渲染部分**不清缓冲** —— 上下文比干净的错误页更有诊断价值。

  同时端到端验证了按钮本身：POST `action=clear_cache` 后返回
  `✅ 已清理：…；✅ OPcache 已重置，PHP 脚本缓存全部作废`。

### 变更

- **卡片 `<img>` 增加 `referrerpolicy="no-referrer"` 与 `decoding="async"`**（5 套模板）。
  5 套模板的 header 本来就有 `<meta name="referrer" content="no-referrer">`，
  但那是**单点依赖**——某个模板漏写 meta、或图片被 JS 插到别的上下文就失效。
  现在 `<img>` 上显式声明，双保险；对「按 Referer 校验防盗链」的上游，
  不发 Referer 反而更可能放行。
- **破图兜底改为「先重试一次，再换占位图」**：上游抖一下不该直接表现为
  「整页一排相同的深灰占位图」。第 1 次失败记下原地址（`data-orig-src`），
  2 秒后带一个新 query 重试；仍失败才换 `no-cover.svg`（该占位图由 `.htaccess`
  缓存 30 天，不会被反复请求）。原始 URL 保留在 DOM 上，方便排查是哪个源在裂。
- **`img.php` 所有 4xx/5xx 响应加 `Cache-Control: no-store`**，
  防止故障页被浏览器或 `ExpiresByType` 「保鲜」30 天——那会让图片
  **莫名其妙长期全裂**，极难排查。
- **`VodClient` 超时参数化**：`httpGet()` 新增 `$timeout`，完整拉取 4 s、
  过期数据刷新 1 s，`probe()` 沿用默认。
- **`clearSystemCache()` 签名扩展**：新增 `$page` / `$img` 两个参数（带默认值，
  旧调用不受影响）。

### 已知边界（写进文档，不藏）

- **`.htaccess` 静态直出的最细时间粒度就是 1 小时**：Apache 的 `%{TIME}` 不支持
  取模，`RewriteMap` 在 `.htaccess` 中不可用。所以列表页内容最多滞后
  **1 小时（静态桶）+ 一次有界刷新**——这是**时间延迟**，不是功能缺失。
- **服务器时区既不等于应用时区、也不是 UTC 时**，`.htaccess` 不会命中
  （**不会读到错误内容，只是永远回源**），由第 2 层兜底，仍是 1 个进程 × 3 ms。
- **图片本地化不减少月流量**（见上）。要降流量只有两条路：
  关掉图片经过本站，或落盘时压缩转 WebP——**本次未实现 WebP**（方案里标为可选，
  且图片经过你手时做有损转换有把图画坏的风险）。
- **方案里「图片分批加载（IntersectionObserver，每批 4 张）」本次未实现**：
  `loading="lazy"` 已经把首屏并发压到 6～10 张，而图片本地化之后
  「一次性爆发」只发生在**每张图的一生一次**；为这点收益承担
  「JS 失效时图片全不显示」的风险不划算（红线：不砍功能）。
- **部署时建议不传 `docs/` 目录**（实测 860 KB，含截图），并在面板里
  确认 `c/` 与 `static/imgcache/` 可写。

---

## [1.2.0] - 2026-09-27

### 新增

- **后台「清理系统缓存」**：原来只有「清空全部缓存」（接口缓存 + 归一化记录），
  现改为可勾选的三项，并**新增 OPcache 重置** —— 虚拟主机上
  `opcache.validate_timestamps=0`（永不自动重新校验）时，换完文件前台永远跑旧代码，
  **等多久都没用**，必须显式 `opcache_reset()`。
  实测复现：`validate_timestamps=0` 下改文件 → 请求仍是旧代码 → 等 4 秒还是旧代码
  → 点该按钮 → 立即生效。状态行同时展示 OPcache 是否启用、缓存了多少脚本与键、
  内存占用，以及 `validate_timestamps` 是否为 0（后者是「传了没用」的根因）。
- **后台「配置导入导出」**：导出站点设置、模板样式变量、分组与数据源为 JSON
  （`format=vodhub-config`，`version=1`）。导入支持**合并**（按 `api_url` 匹配，
  只增不删）与**覆盖**（清空重建）两种方式，覆盖前由前端二次确认。
  **覆盖导入会原样保留 `id`** —— 升级演练时发现，若不保留，AUTOINCREMENT 会让
  数据源 id 从 8 跳到 16，`img.php?...&s=8` 与 `list.php?source=8` 这类 URL 全部失效；
  现导出带 `id`，覆盖模式表已清空、按文件里的 id 原样插回，URL 升级后仍然有效。
  分组 `id` 同样保留，源的 `group_id` 跟着重映射。
  密码哈希与 API 密钥**默认不导出**，只有导出时显式勾选才带上；
  导入时文件没带密钥就绝不覆盖现有密码。整个导入在事务内执行，失败自动回滚。
  新增 `includes/config-io.php`；`addSource()` 增加可选 `createdAt` 参数以保留原始收录时间。

### 修复

- **[严重] 开启图片代理后所有封面都打不开**：`img.php` 只 `require` 了
  `config.php` 与 `includes/db.php`，漏了 `includes/functions.php`，
  于是取图成功后在「自动学习图片域名」那一步调用 `learnImgHost()` 直接
  `Call to undefined function` **Fatal error** —— 图片字节一个都没发出去。
  该缺陷自 v1.0.0 起就存在，v1.0.0 / v1.0.1 / v1.1.0 三个版本全部命中。
  **注意**：浏览器里表现为封面全变占位图，直接打开 `img.php?...` 才能看到报错。
- **图片代理失败时无法定位原因**：缺参数、数据源不存在、代理未开启三种情况
  共用同一句 `proxy disabled`。现拆成可区分的返回：
  `400 missing source param (s)` / `404 source not found (s=N)` /
  `403 proxy disabled for source N [名称]`。
- **[严重] `s` 参数在部分主机上读不到，图片全部被拒**：原代码用 `$_GET['s']`
  取数据源 ID，而 PHP 按 ini 的 `arg_separator.input` 切分查询串，**该值因主机而异**。
  实测 `arg_separator.input=';'`（或取到其他非 `&` 值）时 `s` 整个丢掉，
  `u` 的值里反而带着 `&s=1` —— 这正是那句笼统的 `proxy disabled` 的来源。
  同理，从网页源代码复制出来的地址里 `&` 是字面的 `&amp;`，也读不到 `s`。
  现改为直接按字面 `&` 切分 `$_SERVER['QUERY_STRING']`，完全不受 ini 影响，
  并容忍 `amp;` 前缀 —— **这两种写法现在都能正常出图**。
- **文件开头有 BOM / 空行时「接口 200 却不显示图片」**：`<?php` 之前的字节会被
  PHP 先吐出去。开着 `output_buffering` 时它们混进图片数据，浏览器解码失败；
  没开缓冲时 `headers` 已发送，`Content-Type` 失效。现改为**任何 require 之前**
  检测「文件不是以 `<?php` 开头」：有缓冲就整体丢弃缓冲（此时里面只有这点垃圾），
  无缓冲则输出一条指明文件与行号的诊断，而不是让图片默默裂掉。
- **图片地址被二次解码**：`$_GET['u']` 已被 PHP 解码过一次，原代码无条件再
  `urldecode()` 一遍，会把地址里的字面 `+` 读成空格、把 `%25` 之类的合法序列
  再剥一层。现自行解析查询串时统一用 `rawurldecode()`。
- **新增 `X-Img-Proxy` 响应头**：上传后 `curl -I` 一条 `img.php` 就能确认文件
  真的生效 —— 很多主机会开 OPcache，传完不重启仍跑旧代码。

### 文档

- `docs/troubleshooting.md` 新增图片代理**错误码对照表（12 行）**，
  「代理一开就满屏 Fatal error」的成因说明，以及
  **「传了文件，前台没变化」** 的排查步骤（OPcache `validate_timestamps=0`）。
- `docs/configuration.md` 新增「清理系统缓存（含 OPcache）」与「配置导入导出」两节。

---

## [1.1.0] - 2026-09-27

### 新增

- **播放页用上此前完全未读取的源字段**：别名 `vod_sub`、上映日期 `vod_pubdate`、
  导演 `vod_director`、编剧 `vod_writer`、主演 `vod_actor`、集数 `vod_total`、
  片源状态 `vod_state`、豆瓣 `vod_douban_id` / `vod_douban_score`、拼音 `vod_en` 与
  首字母 `vod_letter`、收录时间 `vod_time_add`、播放来源 `vod_play_from` /
  `vod_play_server`、父分类 `type_id_1` —— 改造前这些字段在全站**一处都没被读过**。
  新增共享片段 `partials/vod_meta.php`（信息 chips）与 `partials/vod_side.php`（侧栏信息），
  5 套模板共用，改展示只需改片段而不是 5 份 `play.php`。
- **列表页 / 搜索页本页筛选**：上游 `wd` 参数只匹配 `vod_name`（实测 `wd=<拼音>`、
  `wd=<别名>` 均返回 0 条），别名、拼音、首字母、演员、导演、编剧、标签在站内检索里
  原本是死的。现按服务端预拼的 `data-s` 串做纯前端子串匹配，不发额外请求。
- **字段智能归一化（TypeSafe System One，可关）**：地区/语言的长尾与残缺值、
  由 `vod_class` + `vod_tag` 归出的主类型、非规整的更新状态句式、内容分级。
  规则层（`includes/fields.php`）不联网且优先，实测本源 200 条记录中地区 200 条、
  语言 198 条由规则直接命中；模型只补规则答不上来的部分。**异步**调用、同一条影片的
  多个判断合并进一次请求并行求值、结果按（数据源 + 影片）缓存 30 天、失败写 10 分钟
  负缓存并保持原始字段。开关在「站点设置 → 播放页字段智能归一化」。
- 新增 `includes/fields.php`（字段解析层）与 `includes/enrich.php`（归一化层）。

### 修复

- **简介里的 HTML 标签原样显示**：`vod_content` 含 `<p>`、`<br/>`、`&nbsp;`，
  此前整体过 `h()` 转义后用户看到的是字面量 `<p>…</p>`。现统一压成纯文本再转义，
  并在 `vod_content` 与 `vod_blurb` 之间按清洗后长度择优。
- **列表卡片大量「未知」角标**：卡片角标此前**只显示时长**，而本源 `vod_duration`
  仅 22% 填充，等于 78% 的卡片挂着「未知」。现改为按填充率取优先级：
  `vod_remarks`(100%) → `vod_state`(23%) → `vod_duration`(22%)，且无值不渲染。
- **`formatDuration()` 认错单位**：只做 `intval()`，`1小时30分` 会被读成 **1 分钟**。
  现认「小时/分钟/分/h/m」，并把整点写成「1小时」而非「1小时00分」。

### 变更

- 卡片副标题追加别名；评分角标在 `vod_score` 为 0 时回退 `vod_douban_score`
  （两者填充互补，12% / 11% 非零）。
- 上游 `vod_status = 0` 的内容不再进列表。
- 数据库结构迁移至 v5（新增 `enrich` 表）；「清空全部缓存」同时清空归一化记录。

---

## [1.0.1] - 2026-09-26

### 安全

- **[高危] 后台 GET 请求缺少鉴权**：`requireAdmin()` 此前只写在 `admin.php` 的 POST
  分支内，`GET admin.php` 直接进入渲染段。任何人打开后台地址即可**无密码查看完整
  管理界面**，包括全部数据源接口地址（可能含 API 密钥）、分组、站点与模板设置。
  现已对 GET 与 POST 一律拦截。
- **会话绑定管理密码指纹**：登录时记录当时的密码哈希，`isAdminOk()` 每次校验其是否
  仍与当前密码一致。**重新安装**或**修改管理密码**后，浏览器残留的旧 cookie 会话立即
  失效，不再出现"重装后免密进入后台"。

### 修复

- **退出后台无任何反馈**：因上述 GET 鉴权缺失，`logout.php` 清除会话并重定向回
  `admin.php` 后又被完整渲染，表现为"点了退出没反应"。现已跳转登录页并显示
  「✅ 已安全退出后台登录」提示。
- **后台退出会误清前台访问密码会话**：`logout.php?admin=1` 原先同时清除
  `SESS_ACCESS_OK`，导致启用访问密码时，退出后台会让前台也需重新登录。现改为前后台
  各清各的会话。
- 退出后换发会话 ID（`session_regenerate_id`），旧 cookie 立即失效，防会话固定。

### 变更

- 登录页支持展示中性提示（区分于红色错误提示）。

---

## [1.0.0] - 2026-09-26

首个公开发布版本。

> **协议**：本版本起采用 [GPL-3.0](LICENSE)，© **HopoL**。衍生作品必须同样开源。

### 新增

**核心**

- 纯 PHP 影视聚合站，数据全部来自后台配置的第三方接口，不存储视频文件
- 前台：分类首页、列表分页、关键词搜索、m3u8 播放页（支持清晰度切换）
- 播放历史与收藏，存于浏览器 localStorage，不上传服务器
- 访问密码保护（可开关）与管理后台独立密码

**数据源**

- 苹果 CMS `provide/vod` 格式接口接入，增删改查
- 一键启用 / 关闭数据源（保留配置但不再前台展示）
- 一键测试连接（延迟 + 可用性）
- 数据源分组（组名可改、可排序、可删除，删除后组内源自动归入未分组）
- 图片代理：按源独立开关，绕过上游防盗链；内网 / 保留地址自动拦截
- 图片域名白名单（留空 = 不限制域名，仍靠内网拦截兜底）
- 「识别图域」：从源的最近内容中自动提取图片域名写入白名单

**模板系统**

- 目录式模板：`templates/<模板名>/`，复制一份改名即为新模板
- 模板解析顺序：数据源绑定优先 → 站点默认 → `default` 兜底
- 5 套内置模板：`default`（深色竖图）、`bilibili`（浅粉宽图）、`douban`（横向列表）、`iqiyi`（网格 3:4）、`netflix`（密集网格）
- 数据源可单独绑定模板，适配不同封面比例的接口
- 后台可视化样式编辑：主题色、悬停色、圆角、封面比例、列数
- 变量白名单**动态解析自模板自身 CSS**，新增模板无需改动业务代码
- 编辑器只暴露模板 CSS 中实际被 `var()` 引用的维度，避免出现"改了没反应"的字段
- 一键恢复默认样式

**站点与维护**

- 三级缓存：列表 / 详情短期缓存，分类长期缓存，上游故障自动降级用旧缓存
- 站点名称、列表列数（PC 端）设置
- 缓存占用查看与一键清空
- `.htaccess` 静态资源缓存规则（免费虚拟主机适用）

**工程质量**

- `config.php` 顶部 PHP 版本与必需扩展保护，把"莫名白屏"变成明确提示
- SQLite 自动表结构迁移（`schema_version` 递增，老库升级无需手工改库）
- 缓存文件写入加 `LOCK_EX`，避免极端并发写坏
- `hls.js` 本地化，不依赖国外 CDN
- 后台所有 POST 集中分发到 `includes/admin-actions.php`，带映射表 + 函数名兜底派发
- 后台操作失败时输出诊断信息（实际收到的参数 + 映射表项数）

### 安全

- 全部后台 POST 表单携带 CSRF 令牌，登录后 `session_regenerate_id()`
- 图片代理白名单与内网地址拦截，防 SSRF
- 所有用户可见输出经 `h()` 转义
- `runtime/` 目录不对外暴露，且已加入 `.gitignore`

---

[Unreleased]: https://github.com/hopol/VodHub/compare/v1.3.2...HEAD
[1.3.2]: https://github.com/hopol/VodHub/compare/v1.3.1...v1.3.2
[1.3.1]: https://github.com/hopol/VodHub/compare/v1.3.0...v1.3.1
[1.3.0]: https://github.com/hopol/VodHub/compare/v1.2.0...v1.3.0
[1.2.0]: https://github.com/hopol/VodHub/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/hopol/VodHub/compare/v1.0.1...v1.1.0
[1.0.1]: https://github.com/hopol/VodHub/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/hopol/VodHub/releases/tag/v1.0.0
