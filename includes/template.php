<?php
/**
 * 模板系统：模板发现、解析与加载
 *
 * 目录约定：templates/<模板名>/<页面名>.php
 *   templates/default/index.php
 *   templates/bilibili/index.php
 *
 * 解析顺序（数据源优先，站点兜底）：
 *   1. 数据源绑定了模板 → 用该模板
 *   2. 否则用站点默认模板（设置项 site_template）
 *   3. 上述模板不存在 → 回退 default
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
// 片段（partials/*.php）与模板页都会用到字段层的解析函数，
// 这里统一引入，避免每个模板各自 require 一遍、漏一个就是白屏。
require_once __DIR__ . '/fields.php';
require_once __DIR__ . '/pagecache.php';   // 支柱一：页面静态缓存（读走兜底、写顺便落盘）

/** 模板根目录 */
function tplRoot(): string {
    return __DIR__ . '/../templates';
}

/** 列出全部可用模板（目录名即模板名），按名称排序 */
function listTemplates(): array {
    $root = tplRoot();
    $out = [];
    foreach ((glob($root . '/*', GLOB_ONLYDIR) ?: []) as $dir) {
        $name = basename($dir);
        if ($name === '.' || $name === '..') { continue; }
        $meta = tplMeta($name);
        $out[$name] = $meta;
    }
    ksort($out);
    return $out;
}

/** 读取模板元信息（templates/<名>/theme.json，无则给默认值） */
function tplMeta(string $name): array {
    $default = [
        'name'        => $name,
        'title'       => $name,
        'description' => '',
        'cover_mode'  => 'tall',     // tall=竖图 2/3；wide=宽图 16/9
        'columns'     => 5,          // 列表页列数（PC 端）
    ];
    $file = tplRoot() . '/' . $name . '/theme.json';
    if (is_file($file)) {
        $json = json_decode((string) file_get_contents($file), true);
        if (is_array($json)) {
            $default = array_merge($default, array_intersect_key($json, $default));
        }
    }
    // 合并后台保存的样式变量（编辑器写入的 CSS 变量）
    $saved = setting('tpl_vars_' . $name, '');
    if ($saved !== '') {
        $vars = json_decode($saved, true);
        if (is_array($vars)) {
            $default['vars'] = $vars;
        }
    }
    if (empty($default['vars'])) {
        $default['vars'] = [];
    }
    // 后台"站点设置 → 列表列数"的覆盖值（0 = 不覆盖，用模板默认 columns）
    $default['adminColumns'] = intval(setting('list_columns', '0'));
    return $default;
}

/**
 * 读取某模板"可调"的 CSS 变量定义（供后台样式编辑器使用）
 *
 * 直接解析模板自带的 style.css 顶部 :root 块，因此新增模板、新增变量
 * 都无需改动业务代码——变量名与默认值都来自模板自身。
 *
 * @param string $tplName 模板名
 * @return array  name => ['name'=>变量名, 'value'=>当前默认值（带单位）]
 */
function tplEditorVars(string $tplName): array {
    $cssFile = tplRoot() . '/' . $tplName . '/style.css';
    // default 模板没有自带 style.css，它用的是站点公共的 static/style.css
    if (!is_file($cssFile) && $tplName === 'default') {
        $cssFile = __DIR__ . '/../static/style.css';
    }
    if (!is_file($cssFile)) {
        return [];
    }
    $content = (string) file_get_contents($cssFile);
    // 只取第一个 :root { ... } 块（模板自己的主题变量都定义在这里）
    if (!preg_match('/:root\s*\{([^}]*)\}/is', $content, $m)) {
        return [];
    }
    // 先剥掉 CSS 注释，避免注释文本干扰解析
    $block = preg_replace('!/\*.*?\*/!s', '', (string) $m[1]);

    // 用朴素正则抓「变量名: 值;」，再按后缀筛维度。
    // 不在正则里带 (?:^|;) 前缀——那会让匹配吃掉分号后，紧邻的下一条声明拿不到
    // 起始锚点而被整条跳过，导致维度成片丢失。
    // 也不把维度名塞进正则——否则会丢前缀（--bili-primary / --tm-primary 必须原样保留，
    // 否则保存的键与 CSS 变量名对不上，改了等于没改）。
    $pat = '/(--[A-Za-z0-9_-]+)\s*:\s*([^;]+);/';
    preg_match_all($pat, $block, $mm, PREG_SET_ORDER);

    // 编辑器只暴露这 5 个"可调"维度，按变量名后缀判定。
    // --list-columns 是后台"站点设置"的覆盖值，不属于模板可调维度，明确排除。
    $dims = ['primary-dark', 'primary', 'radius', 'cover-ratio', 'columns'];
    $skip = ['--list-columns'];

    $out = [];
    foreach ($mm as $match) {
        $name = trim((string) ($match[1] ?? ''));
        $val  = trim((string) ($match[2] ?? ''));
        if ($name === '' || $val === '' || in_array($name, $skip, true)) {
            continue;
        }
        $dim = null;
        foreach ($dims as $d) {
            // 后缀必须精确落在 `--xxx<维度>` 上：--primary-2 结尾是 -2，不匹配 --primary
            if (str_ends_with($name, $d) && strlen($name) === strlen('--') + strlen($d)) {
                $dim = $d; break;          // 纯维度名，如 --radius
            }
            if (str_ends_with($name, '-' . $d)) {
                $dim = $d; break;          // 带前缀，如 --bili-radius / --tm-radius
            }
        }
        if ($dim === null) {
            continue;
        }
        // 关键：只暴露「模板 CSS 里真的用 var() 引用了」的维度。
        // 否则会出现「变量定义了、编辑器能改、DOM 也注入了，但 CSS 里没人读」的隐蔽 bug
        // （如 douban 用横向 flex 列表，--tm-columns 定义了却零引用，改了前台纹丝不动）。
        $ref = '/var\(\s*' . preg_quote($name, '/') . '\s*[,)]/';
        if (!preg_match($ref, $content)) {
            continue;
        }
        $out[$name] = ['name' => $name, 'value' => $val];
    }
    return $out;
}

/** 样式编辑框里各维度对应的中文标签 */
function tplVarLabel(string $var): string {
    // 只看后缀，与变量前缀无关（--primary / --bili-primary / --tm-primary 都认）
    if (str_ends_with($var, '-primary-dark')) return '主题色（深，悬停态）';
    if (str_ends_with($var, '-primary'))      return '主题色';
    if (str_ends_with($var, '-cover-ratio'))  return '封面高度比例';
    if (str_ends_with($var, '-radius'))       return '卡片圆角（像素，0-40）';
    if (str_ends_with($var, '-columns'))      return '列表列数（2-8）';
    return $var;
}

/** 根据 CSS 值的后缀判断它属于哪种可调类型 */
function tplVarKind(string $value): string {
    $v = strtolower(trim($value));
    if (preg_match('/^#[0-9a-fA-F]{3,8}$/', $v))       return 'color';
    if (preg_match('/^\d+(\.\d+)?px$/', $v))           return 'px';
    if (preg_match('/^\d+(\.\d+)?%$/', $v))            return 'percent';
    if (preg_match('/^\d+$/', $v))                     return 'int';
    return 'other';
}

/** 校验并规范化一个样式值；不合法返回 null */
function tplVarValidate(string $kind, string $val): ?string {
    switch ($kind) {
        case 'color':
            return preg_match('/^#[0-9a-fA-F]{3,8}$/', $val) ? $val : null;
        case 'px':
            return preg_match('/^\d{1,3}(\.\d+)?$/', $val) ? $val . 'px' : null;
        case 'percent':
            return preg_match('/^\d{1,3}(\.\d+)?$/', $val) ? $val . '%' : null;
        case 'int':
            return preg_match('/^[1-8]$/', $val) ? $val : null;
        default:
            return null;
    }
}


/**
 * 判断模板是否存在。
 *
 * ⚠⚠ **判据在 1.3.12 改过**（原来只认 `index.php`）：
 *
 *   1.3.12 删掉了 4 套模板里与 default 完全相同的 7 个页面文件
 *   （模板去重，见 docs/templates.md）。而这些模板**恰恰没有自己的 index.php** ——
 *   若判据仍是「必须有 index.php」，`tplExists('netflix')` 会返回 false，
 *   于是 `resolveTemplate()` 一路回退到 default、
 *   `adminActionEditTemplate()` 直接拒绝保存、
 *   **整个非 default 模板静默失效，而且没有任何报错**。
 *
 *   新的判据是「目录存在 + 有 theme.json」（theme.json 才是模板真正必需的元信息，
 *   `tplMeta()` 读的就是它，缺了会给一套默认值）。
 *
 *   ⚠ 所以**theme.json 是不可删的**，它现在是「这个模板存在」的标记。
 *     页面文件（index/list/play/…）全部可以删，缺失时 renderTemplate() 回退 default。
 */
function tplExists(string $name): bool {
    if ($name === '' || str_contains($name, '/') || str_contains($name, '\\')) {
        return false;   // 防路径穿越：模板名会被拼进 tplRoot() . '/' . $name
    }
    return is_file(tplRoot() . '/' . $name . '/theme.json');
}

/**
 * 解析最终使用的模板名
 *
 * @param array|null $source 数据源记录，为 null 表示站点级页面（如首页）
 * @return string 模板目录名
 */
function resolveTemplate($source = null): string {
    $fallback = 'default';

    // 数据源绑定的模板优先
    if (is_array($source)) {
        $tpl = trim((string) ($source['template'] ?? ''));
        if ($tpl !== '' && tplExists($tpl)) {
            return $tpl;
        }
    }

    // 站点默认模板
    $siteTpl = setting('site_template', 'default');
    if ($siteTpl !== '' && tplExists($siteTpl)) {
        return $siteTpl;
    }

    return $fallback;
}

/**
 * 加载模板页面
 *
 * @param string $pageName  页面名（index / list / search / play / history / login）
 * @param array  $data      传给模板的变量
 * @param string $tplName   模板名，留空则按 $data['source'] 解析
 *
 * 注意：$pageName / $tplName 与 $data 中的键刻意区分，避免 extract() 覆盖数据。
 *       历史 bug：参数曾命名为 $page / $template，而 $data 里有 'page'（页码）和
 *       'template' 键，EXTR_SKIP 会跳过它们，导致模板里读到的是函数参数值。
 */
function renderTemplate(string $pageFile, array $tplData = [], string $tplOverride = ''): void {
    // 注意：参数名刻意避开 $data/$page/$template，防止 extract() 跳过同名键

    // ---------------------------------------------------------------- 第 2 层兜底
    // 极致低功耗模式 · 支柱一。正常情况下 .htaccess 已经在 PHP 之前把
    // c/<时间桶>/<key>.html 直出（0 EP）。走到这里只有四种可能：
    //   ① 该小时内第一次访问（还没生成）  ② rewrite 不可用（Nginx / 禁用 mod_rewrite）
    //   ③ 服务器时区与 PHP 对不上，-f 恒不成立  ④ 访问密码开启（必须过 PHP 鉴权）
    // 前三种读盘远比重新渲染便宜（~3 ms vs ~70 ms），第四种 pcEnabled() 会返回 false。
    pcSyncGate();     // 自愈：该关静态就写 c/.lock，该开就摘掉
    // 自愈：换过代码就把上一版生成的静态 HTML 作废（否则要等整点才生效）。
    // 带 function_exists 是**升级包漏传 pagecache.php** 时的兜底 ——
    // 这里是全站渲染的咽喉，宁可少一次作废，也不能让它 fatal 出白屏。
    if (function_exists('pcVersionGate')) {
        pcVersionGate();
    }
    $pcKey = pcKey($pageFile, $tplData);
    if ($pcKey !== null && pcEnabled()) {
        $hit = pcRead($pcKey);
        if ($hit !== null) {
            if (!headers_sent()) {
                header('Content-Type: text/html; charset=utf-8');
                header('Cache-Control: no-cache, must-revalidate');
            }
            echo $hit;
            exit;
        }
    }

    if ($tplOverride === '') {
        $tplOverride = resolveTemplate($tplData['source'] ?? null);
    }
    if (!tplExists($tplOverride)) {
        $tplOverride = 'default';
    }

    $file = tplRoot() . '/' . $tplOverride . '/' . $pageFile . '.php';
    if (!is_file($file)) {
        // 该模板缺这个页面时回退 default，再缺就报错
        $file = tplRoot() . '/default/' . $pageFile . '.php';
    }
    if (!is_file($file)) {
        http_response_code(500);
        exit('模板页面缺失：' . h($pageFile));
    }

    // 把 $tplData 展开为模板内可直接使用的变量
    extract($tplData, EXTR_SKIP);
    $tplName = $tplOverride;
    $tplMeta = tplMeta($tplOverride);

    // 渲染进输出缓冲：拿到完整 HTML 后顺便落盘，供 .htaccess 下次 0 EP 直出。
    // 先写 .tmp 再 rename —— rename 同文件系统内是原子的，rewrite 的 -f
    // 绝不会读到半截 HTML。模板若中途 exit，缓冲由 PHP 关闭时自动刷出，
    // 页面照常可见，只是这次没缓存上。
    ob_start();
    require $file;
    $html = (string) ob_get_clean();

    // pcIsVolatile()：模板自己标了「这一页数据不完整」（如首页某源分类还没补上），
    // 就不写盘 —— 否则残页会被小时桶冻住整整一小时，缓存补上了也没访客看得见。
    if ($pcKey !== null && $html !== ''
        && !(function_exists('pcIsVolatile') && pcIsVolatile())) {
        pcWrite($pcKey, $html);
    }
    echo $html;
}

/**
 * 解析模板内同级文件（header / footer / player_script）的实际路径。
 *
 * ═══════════════════════════════════════════════════════════════════════
 *  为什么需要它：1.3.12 删掉了各模板里与 default 逐字节相同的页面文件，
 *  而这些页面内部原本是 `require_once __DIR__ . '/footer.php'` 引用同级的。
 *  `__DIR__` 是硬路径、**没有任何回退** —— bilibili 模板的 list.php
 *  （唯一与 default 不同、因而必须保留的那个）在 footer.php 被删后
 *  直接 `Failed opening required .../bilibili/footer.php` 而整页 500。
 *  `renderTemplate()` 的回退只覆盖**入口那一个文件**，模板内部的 require
 *  不在它的视野里 —— 这个坑必须由模板自己来接。
 * ═══════════════════════════════════════════════════════════════════════
 *
 * ⚠⚠ **为什么是「返回路径」而不是「在函数里 include」—— 本项目已踩过一次**：
 *
 *   页面文件里的 `$siteTitle` / `$tplMeta` / `$sources`，
 *   是 renderTemplate() 用 extract() 放进**它自己那个作用域**的。
 *   `require_once __DIR__ . '/header.php'` 写在页面文件里时，
 *   被包含的文件继承的是**页面文件所在的作用域**（即 renderTemplate 的），
 *   所以变量看得见。
 *
 *   而一旦把 include 挪进本函数，被包含的文件就改在**本函数自己的作用域**里执行，
 *   renderTemplate 的局部变量**一个都看不见**。实测后果：
 *   `$siteTitle` 变 undefined、`$tplMeta['adminColumns']` 报 null 下标访问，
 *   每个页面的站名与标题全部变空 —— 且**不报致命错误、页面照常输出**。
 *   那是最难发现的一类回归：看起来「页面正常」，其实内容已经错了。
 *
 *   所以分工是：**本函数只解析路径，`require` 留在页面文件里写**，
 *   作用域才与去重前完全一致。
 *
 * 用法（模板里）：
 *     <?php require tplInclude('header.php'); ?>
 *
 * 查找顺序：当前模板 → default → 一个空壳文件。
 * **永远返回一个存在的路径**，所以 require 不会 fatal；
 * 真的什么都��了也只是页面少一段尾巴，而不是整页 500。
 *
 * @param string $file 同级文件名，如 'header.php' / 'footer.php'
 */
function tplInclude(string $file, string $template = ''): string {
    // 防路径穿越：文件名会被拼进路径
    if (!preg_match('/^[A-Za-z0-9_-]+\.php$/', $file)) {
        return tplRoot() . '/' . TPL_NOOP_FILE;
    }
    // ⚠ 模板名**由调用方显式传进来**，不从 $GLOBALS 读 ——
    //   renderTemplate() 里 $tplName 是一个**局部变量**（它 extract 过数据，
    //   不能污染全局），所以 $GLOBALS['tplName'] 永远是空的 → 恒回退 default。
    //   那正是去重后「B 站模板丢了 style.css」的原因：页面文件从 default/ 载入
    //   header，而 default 的 header 自然只引 static/style.css。
    //   （tplPartial() 有同样的隐患，它是被模板显式传了 $tplName 才没出事。）
    $tpl = $template !== '' ? $template : (string) ($GLOBALS['tplName'] ?? 'default');
    foreach ([$tpl, 'default'] as $dir) {
        $path = tplRoot() . '/' . $dir . '/' . $file;
        if (is_file($path)) {
            return $path;
        }
    }
    return tplRoot() . '/' . TPL_NOOP_FILE;
}

/** 空壳文件名：tplInclude() 兜底用，保证返回的路径一定存在 */
const TPL_NOOP_FILE = '_noop.php';

/**
 * 在模板内引用子片段（如 partials/vod_grid.php）
 * 优先引用当前模板的片段，缺失时回退 default
 *
 * @param string $partial  片段名
 * @param string $template 模板名，留空则用当前模板
 * @param array  $data     传给片段的变量数组（显式传入，避免作用域丢失）
 */
function tplPartial(string $partial, string $template = '', array $data = []): void {
    if ($template === '') {
        $template = $GLOBALS['tplName'] ?? 'default';
    }
    $file = tplRoot() . '/' . $template . '/partials/' . $partial . '.php';
    if (!is_file($file)) {
        $file = tplRoot() . '/default/partials/' . $partial . '.php';
    }
    if (!is_file($file)) {
        return;
    }
    // 片段在独立作用域执行，需显式传入依赖的变量
    extract($data, EXTR_SKIP);
    require $file;
}
