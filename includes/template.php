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


/** 判断模板是否存在（至少要有 index.php） */
function tplExists(string $name): bool {
    return is_file(tplRoot() . '/' . $name . '/index.php');
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

    require $file;
}

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
