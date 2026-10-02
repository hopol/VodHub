# 测试

零依赖的纯 PHP 断言测试。**一个 `php` 命令就能跑，不需要 Composer、不需要 vendor/。**

## 怎么跑

### 网页（最方便）

直接浏览器打开：

```
https://你的站点/tests/run.php               跑全套
https://你的站点/tests/run.php?f=enrich     只跑富化层
https://你的站点/tests/test_fields.php      单个文件
```

得到一份 HTML 报告：**统计卡片 + 失败详情置顶 + 按文件分组 + 深浅色自适应**
（跟随系统主题）。刷新即重跑，每次看到的都是当前代码的状态。

### 命令行

```bash
php tests/run.php              # 跑全部（约 300 ms）
php tests/run.php fields       # 只跑文件名含 fields 的
php tests/run.php enrich       # 只跑富化层
php tests/run.php buildmeta    # 只跑元数据组装
php tests/run.php admin        # 只跑后台动作
```

退出码 `0` = 全通过，`1` = 有失败。CI 直接用这个判成败。

**两种环境读的是同一份数据**，所以不会出现「网页说全过、终端说失败」。
（曾经出现过 —— 早期版本终端用 ANSI 颜色、网页是纯文本，
浏览器里看到的是 `^[[32mM-bM-^\M-^S` 这种裸字节，完全没法读。）

## 为什么不开子进程（曾走过弯路）

**结论：全程同进程，任何主机上都能跑。**

早期版本给每个测试文件开独立进程（`exec` → `proc_open` 兜底），
理由是"静态变量互不污染"。但实测发现：

- 免费虚拟主机常把 `exec()` 和 `proc_open` 都列入 `disable_functions`
  —— **而那正是本项目的目标用户群**；
- 在这类主机上，网页版只能显示
  "⚠ 无法为每个测试文件创建独立进程，本次不执行"，
  **对用户毫无意义**。

于是改成同进程 include。曾经的顾虑是：被包含文件的顶层全局赋值
（如 `admin-actions.php` 的 `$_ADMIN_ACTION_MAP = [...]`）会不会丢？

**实测不会。** `include` 在**全局作用域**执行时，顶层赋值就是全局的，
四个测试文件连跑 **152 项全绿**。关键有两条：

| # | 坑 | 做法 |
|---|---|---|
| ① | `require_once` 只在首次生效，第二次 include 不会重新执行顶层 | 用 **`include`** 而非 `require` |
| ② | `include` 写在函数体内，顶层赋值会落进函数局部 | include 必须写在 **run.php 顶层**，不能包在函数里 |

> 中途踩过一次假的：探针脚本在 `finish()` 之后才读收集器，
> 而 `finish()` 已把用例清空 → 读到 0 项，误判成"同进程不可行"。
> **是探针写错，不是方案错。** 读结果要在 finish 之前，
> 或者像现在这样由 `run.php` 负责重置与取回。

### 三种主机环境实测

| 主机环境 | 网页 | 命令行 |
|---------|------|--------|
| 全部可用 | ✅ 195 项 | ✅ 195 项 |
| 禁 `shell_exec` / `exec` / `proc_open` | ✅ 195 项 | ✅ 195 项 |
| 再加禁 `popen` / `passthru` / `system` / `pcntl_exec` | ✅ 195 项 | ✅ 195 项 |
| 再加禁 `getenv` | ✅ 195 项（`getenv` 返回 false，按「未设置」处理） | 同 |

> ⚠ **写测试时必须守住的一条**：**不要在测试里用 `shell_exec` / `exec` 起子进程。**
> 上一轮刚把测试改成同进程（理由正是「免费主机禁用这些函数」），
> 转头却在测试内部用 `shell_exec` 验证常量 —— 1.3.5 首次发布时线上直接报了三项失败。
>
> 需要「同一进程里做不到」的事（比如常量只能 define 一次）？
> **把判断抽成纯函数**（见 `config.php` 的 `vhTlsVerifyFromEnv`），
> 测试直接调它，覆盖所有取值且零外部依赖。
>
> 验证手段：`php -d disable_functions=shell_exec,exec,proc_open tests/run.php`

## 为什么不用 PHPUnit

本项目**零 Composer、零 `vendor/`**，并且明确要部署到
「不懂技术的免费主机用户」手里。引入 PHPUnit 意味着给**每一个部署实例**
凭空加一个 `vendor/` 目录 —— 与零依赖战略直接冲突。

所以测试也必须零依赖。这不是"退而求其次"：
本项目要测的东西大多是**纯函数**（无 I/O、无网络、无全局状态），
一个 `eq()` / `ok()` 就够了，框架带来的收益远小于它的成本。

## 文件结构

| 文件 | 项数 | 覆盖 |
|------|-----:|------|
| `bootstrap.php` | — | 断言框架（`t` / `eq` / `isNull` / `hasValue` …）+ 数据目录隔离 |
| `test_fields.php` | 79 | 字段层纯函数：文本清洗、多值拆分、日期、评分、时长、播放地址、归一化映射 |
| `test_enrich.php` | 30 | 富化合并层：代码命中 / 模型命中 / 置信度回退 / 缺答案 四态 |
| `test_buildmeta.php` | 31 | `buildMeta()` 组合行为 + 「enrich 缺席也能独立工作」契约 |
| `test_admin_actions.php` | 12 | 后台动作表结构 + 表单覆盖率 + 500 白屏事故的回归防护 |
| `report.php` | — | 双模式渲染：终端 ANSI 彩色 / 网页 HTML 报告（同一份数据） |
| `run.php` | — | 统一入口：每个测试文件跑在**独立进程**（见下方「主机限制」） |

## 写测试的约定

### 1. 一条 `t()` 测一件事，名字要能独立看懂

```php
t('置信度低于 0.7 返回 null（让调用方回退原始字段）', static function (): void {
    isNull(enrichChoiceLabel(['choice' => 'drama', 'confidence' => 0.59], $LBL));
});
```

失败时终端直接显示这句话 —— 它就是排查的起点。

### 2. 测**契约**，不只是测"当前输出"

最要紧的一类是 **`null` 与 `''` 的区别**：

```php
eq('',  normalizeRegions(''));      // 没数据 → 不展示
isNull(normalizeRegions('火星'));   // 看不懂 → 交给模型
```

混淆这两者会让某类影片的地区/语言**集体消失且不报错**。
`isNull()` 就是专门用来锁这条的，别用 `eq(null, ...)` 代替 ——
后者传 `''` 也会"通过"。

### 3. 边界值要单独测

```php
eq('剧情', enrichChoiceLabel(['choice' => 'drama', 'confidence' => 0.7], $LBL));  // 恰好达标：采用
isNull(enrichChoiceLabel(['choice' => 'drama', 'confidence' => 0.69], $LBL));     // 差 0.01：回退
```

`>= 0.7` 改成 `> 0.7` 这种改动，只有边界测试能抓住。

### 4. 查源码的断言要先剥注释

```php
$body = preg_replace('#//[^\n]*|/\*.*?\*/#s', '', $fn[1]);
ok(preg_match('/(^|[^\w$])global\s+\$_ADMIN_ACTION_MAP\s*;/', (string) $body), ...);
```

不剥注释的话，**把 `global` 注释掉也能匹配上**，测试就成了摆设。
（这条是实测踩出来的：第一次写完我试着注入回归，测试居然还是绿的。）

### 5. 缺数据的行为也要测

```php
t('【契约】enrich 缺席时 adult 恒为 0 且不误报', static function (): void {
    $m = buildMeta(sampleDetail(), 1, [], null);
    ok($m['adult_warn'] === false, '模型缺席时绝不能误标成人内容');
});
```

「出错时会发生什么」往往比「正常时输出什么」更重要。

## 隔离

只测纯函数，**不建库、不出站**。`VODHUB_DATA_DIR` 指向临时目录并注册了清理：

```php
putenv('VODHUB_DATA_DIR=' . sys_get_temp_dir() . '/vodhub-test-' . getmypid());
register_shutdown_function(/* 删临时目录 */);
```

万一某个被测函数不慎触发 `db()`，也只会在临时目录里建库，
**绝不污染站点的 `runtime/`**（那里有管理员密码哈希与数据源地址）。

## 已记录的待修项

`normalizeRemarks(string $raw)` 的签名不接受 `null`，
而 `enrich.php` 与 `buildMeta()` 两处调用方都靠 `(string)` 强转兜住。
**当前线上不会炸**，但任何一处漏了强转就是整站 500。

已在 `test_fields.php` 里如实记录当前行为并标注，等第二阶段与
`genre` 的修改一并处理（把签名改成 `?string` 即可，一行）。

## 加新测试时

1. 想测新函数 → 在对应的 `test_*.php` 里加 `t()`，**不要新建文件**
   （文件粒度 = 排查时的定位粒度）
2. 新建一类测试（比如缓存层）→ 建 `test_<层名>.php`，它会被 `run.php` 自动发现
3. 跑 `php tests/run.php` 确认绿，再提交
