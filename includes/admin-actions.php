<?php
/**
 * 后台 POST 处理：所有 admin.php 的 action 分支集中在这里。
 *
 * 由 admin.php 重构而来（2026-09-25），纯搬家不改功能。
 * admin.php 现在只负责：登录分支 + 数据准备 + 渲染 HTML。
 *
 * 每个 action 对应一个 adminActionXxx() 函数。
 * 函数统一约定：
 *   - 仅在已登录 + CSRF 校验通过后被调用
 *   - 需要跳转（如登录）时自行 exit
 *   - 返回值为操作结果提示消息
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/template.php';
require_once __DIR__ . '/enrich.php';
require_once __DIR__ . '/config-io.php';   // 系统缓存清理 + 配置导入导出

/** action → 处理函数映射表 */
$_ADMIN_ACTION_MAP = [
    'add_source'            => 'adminActionAddSource',
    'update_source'         => 'adminActionUpdateSource',
    'delete_source'         => 'adminActionDeleteSource',
    'toggle_source'         => 'adminActionToggleSource',
    'test_source'           => 'adminActionTestSource',
    'detect_img_host'       => 'adminActionDetectImgHost',
    'add_group'             => 'adminActionAddGroup',
    'update_group'          => 'adminActionUpdateGroup',
    'delete_group'          => 'adminActionDeleteGroup',
    'site_settings'         => 'adminActionSiteSettings',
    'site_template'         => 'adminActionSiteTemplate',
    'edit_template'         => 'adminActionEditTemplate',
    'reset_template'        => 'adminActionResetTemplate',
    'access_settings'       => 'adminActionAccessSettings',
    'change_admin_password' => 'adminActionChangeAdminPassword',
    'clear_cache'           => 'adminActionClearCache',
    'import_config'         => 'adminActionImportConfig',
];

/**
 * 分发 POST 请求（仅限已登录后调用）
 *
 * @return string 操作提示消息
 */
function adminHandlePost(): string {
    // ⚠️ 必须 global：$_ADMIN_ACTION_MAP 定义在**本文件顶层**（全局作用域），
    //    函数内不加这句读到的是局部的 undefined。
    //
    //    后果很隐蔽：上面那句 `$handler = $_ADMIN_ACTION_MAP[$action] ?? null`
    //    有 `??` 兜着、告警被抑制，走不到出错；
    //    而最后那句 `count($_ADMIN_ACTION_MAP)` **没有**兜底 ——
    //    于是「未知 action」本该返回的那句优雅提示，实际变成
    //    **Fatal TypeError → 500 白屏**。那句提示恰恰是排查
    //    「服务器上的 admin-actions.php 版本不对」的唯一线索，
    //    自己先把页面打死了。
    global $_ADMIN_ACTION_MAP;

    // 取 action 时容错：上游可能因缓存/截断带上空白字符，先 trim。
    $raw  = $_POST['action'] ?? '';
    $action = trim((string) (is_scalar($raw) ? $raw : ''));
    $handler = $_ADMIN_ACTION_MAP[$action] ?? null;

    if ($handler === null && $action !== '') {
        // 兜底：映射表可能因 opcache/版本不一致而缺项，但处理函数就在本文件里。
        // 按 action 名反推函数名（update_source -> adminActionUpdateSource）再试一次，
        // 避免"函数明明存在却报未知操作"。仅在函数真实存在时才接受。
        $fn = 'adminAction' . str_replace(' ', '', ucwords(str_replace('_', ' ', $action)));
        if (function_exists($fn)) {
            $handler = $fn;
        }
    }

    if ($handler === null) {
        // 把实际收到的 action 显示出来：为空 = 表单字段没提交上来；
        // 非空 = 映射表和函数都对不上（服务器上的 admin-actions.php 版本不对）。
        $shown = $action === '' ? '(空)' : h(mb_substr($action, 0, 40));
        return '⚠️ 未知操作：收到 action=' . $shown
             . '（映射表 ' . count($_ADMIN_ACTION_MAP) . ' 项）';
    }
    return $handler($_POST);
}

// ==================================================================
// 各 action 的具体实现
// ==================================================================

function adminActionAddSource(array $post): string {
        $name = trim((string) ($post['name'] ?? ''));
        $url  = trim((string) ($post['api_url'] ?? ''));
        $sort = intval($post['sort'] ?? 0);
        $note = trim((string) ($post['note'] ?? ''));
        $tpl  = trim((string) ($post['template'] ?? ''));
        $gid  = intval($post['group_id'] ?? 0);
        $imgProxy = intval($post['img_proxy'] ?? 0) === 1 ? 1 : 0;
        $imgHosts = cleanHostList((string) ($post['img_hosts'] ?? ''));
        if ($name === '' || $url === '') {
            return '⚠️ 名称与接口地址不能为空';
        } elseif (!preg_match('#^https?://#i', $url)) {
            return '⚠️ 接口地址必须以 http:// 或 https:// 开头';
        } else {
            // 白名单可选：留空表示不限制主机（仍靠内网/保留地址拦截兜底）
            addSource($name, $url, $sort, $note, $tpl, $gid, $imgProxy, $imgHosts);
            return '✅ 已添加数据源「' . $name . '」';
        }
}

function adminActionUpdateSource(array $post): string {
        $id   = intval($post['id'] ?? 0);
        $name = trim((string) ($post['name'] ?? ''));
        $url  = trim((string) ($post['api_url'] ?? ''));
        $sort = intval($post['sort'] ?? 0);
        $note = trim((string) ($post['note'] ?? ''));
        $tpl  = trim((string) ($post['template'] ?? ''));
        $gid  = intval($post['group_id'] ?? 0);
        $imgProxy = intval($post['img_proxy'] ?? 0) === 1 ? 1 : 0;
        $imgHosts = cleanHostList((string) ($post['img_hosts'] ?? ''));
        $enabled = intval($post['enabled'] ?? 0) === 1 ? 1 : 0;
        if ($id <= 0 || $name === '' || $url === '') {
            return '⚠️ 参数不完整';
        } else {
            // 白名单可选：留空表示不限制主机（仍靠内网/保留地址拦截兜底）
            updateSource($id, $name, $url, $enabled, $sort, $note, $tpl, $gid, $imgProxy, $imgHosts);
            return '✅ 已更新数据源「' . $name . '」';
        }
}

function adminActionDeleteSource(array $post): string {
        $id = intval($post['id'] ?? 0);
        $row = getSource($id);
        if ($row) {
            (new VodClient($row['api_url']))->clearCache();
            deleteSource($id);
            return '✅ 已删除数据源「' . $row['name'] . '」';
        } else {
            return '⚠️ 数据源不存在';
        }
}

function adminActionTestSource(array $post): string {
        $id = intval($post['id'] ?? 0);
        $row = getSource($id);
        if (!$row) {
            return '⚠️ 数据源不存在';
        } else {
            $probe = (new VodClient($row['api_url']))->probe();
            return ($probe['ok'] ? '✅ ' : '❌ ')
                . $row['name'] . '：' . $probe['msg']
                . '（' . $probe['elapsed'] . ' 秒）';
        }
}

function adminActionAccessSettings(array $post): string {
        $enabled = intval($post['access_enabled'] ?? 0) === 1 ? '1' : '0';
        $pwd     = (string) ($post['access_password'] ?? '');
        $oldHash = setting('access_password');
        if ($pwd !== '') {
            // 只有填了新密码才更新；留空表示保持原密码
            setSetting('access_password', password_hash($pwd, PASSWORD_DEFAULT));
        }
        if ($enabled === '1' && $oldHash === '' && $pwd === '') {
            // 旧密码为空、本次也没填新密码，无法启用保护
            setSetting('access_enabled', '0');
            return '⚠️ 尚未设置访问密码，无法启用保护。请先填写访问密码。';
        } else {
            setSetting('access_enabled', $enabled);
            return $enabled === '1'
                ? '✅ 已启用访问密码保护'
                : '✅ 已关闭访问密码，任何人可直接访问';
        }
}

function adminActionChangeAdminPassword(array $post): string {
        $old = (string) ($post['old_password'] ?? '');
        $new = (string) ($post['new_password'] ?? '');
        $new2 = (string) ($post['new_password2'] ?? '');
        if (!password_verify($old, setting('admin_password'))) {
            return '⚠️ 原密码错误';
        } elseif (strlen($new) < 6) {
            return '⚠️ 新密码至少 6 位';
        } elseif ($new !== $new2) {
            return '⚠️ 两次输入的新密码不一致';
        } else {
            setSetting('admin_password', password_hash($new, PASSWORD_DEFAULT));
            return '✅ 管理密码已修改';
        }
}

function adminActionSiteSettings(array $post): string {
        $title = trim((string) ($post['site_title'] ?? ''));
        if ($title === '') {
            return '⚠️ 站点名称不能为空';
        }
        setSetting('site_title', $title);

        // 列表列数：0 = 跟随模板默认；1~8 = 全站统一列数
        $cols = intval($post['list_columns'] ?? 0);
        if ($cols < 0) { $cols = 0; }
        if ($cols > 8) { $cols = 8; }
        setSetting('list_columns', (string) $cols);

        // 字段智能归一化开关（未提交的表单按关闭处理，与其他布尔项一致）
        setSetting('enrich_enabled', intval($post['enrich_enabled'] ?? 0) === 1 ? '1' : '0');

        // 容量档位：决定各缓存目录的硬上限（1 GB 档 132 MB / 5 GB 档 387 MB）。
        // 探测不可靠的主机（disk_total_space 返回整机磁盘）必须手动指定。
        $tier = (string) ($post['lowpower_tier'] ?? 'auto');
        if (!in_array($tier, ['auto', 'compact', 'standard'], true)) {
            $tier = 'auto';
        }
        setSetting('lowpower_tier', $tier);

        // 首页补拉预算（秒）。免费空间网关超时差异很大（3/5/10/30 秒），
        // 写死 6 秒在 3 秒超时的主机上照样撞 502 —— 所以暴露成可配置项。
        $wb = intval($post['warm_budget'] ?? 6);
        if ($wb < 0) { $wb = 0; }
        if ($wb > 30) { $wb = 30; }
        setSetting('warm_budget', (string) $wb);

        return $cols > 0
            ? '✅ 站点设置已保存（列表列数：' . $cols . '）'
            : '✅ 站点设置已保存（列表列数：跟随模板默认）';
}

function adminActionAddGroup(array $post): string {
        $gname = trim((string) ($post['group_name'] ?? ''));
        $gsort = intval($post['group_sort'] ?? 0);
        if ($gname === '') {
            return '⚠️ 分组名称不能为空';
        } else {
            $gid = addGroup($gname, $gsort);
            return '✅ 已新增分组「' . $gname . '」，可在下方给数据源指派';
        }
}

function adminActionUpdateGroup(array $post): string {
        $gid   = intval($post['id'] ?? 0);
        $gname = trim((string) ($post['group_name'] ?? ''));
        $gsort = intval($post['group_sort'] ?? 0);
        if ($gid <= 0 || $gname === '') {
            return '⚠️ 参数不完整';
        } else {
            updateGroup($gid, $gname, $gsort);
            return '✅ 分组已改名为「' . $gname . '」';
        }
}

function adminActionDeleteGroup(array $post): string {
        $gid = intval($post['id'] ?? 0);
        $row = getGroup($gid);
        if (!$row) {
            return '⚠️ 分组不存在';
        } else {
            deleteGroup($gid);
            return '✅ 已删除分组「' . $row['name'] . '」，组内数据源已归入"未分组"';
        }
}

function adminActionDetectImgHost(array $post): string {
        // 主动探测该数据源的图片域名（用户常常并不知道图片域名）
        $id = intval($post['id'] ?? 0);
        $row = getSource($id);
        if (!$row) {
            return '⚠️ 数据源不存在';
        }
        $client = new VodClient($row['api_url']);
        $data = $client->getList(0, 1);
        $items = $data['list'] ?? [];
        if (!$items) {
            return '❌ 拉取不到内容，无法识别图片域名';
        }
        $found = [];
        foreach (array_slice($items, 0, 10) as $it) {
            $pic = trim((string) ($it['vod_pic'] ?? ''));
            if ($pic === '') {
                continue;
            }
            $p = parse_url($pic);
            if (!empty($p['host'])) {
                $found[] = strtolower($p['host']);
            }
        }
        $found = array_values(array_unique($found));
        if (!$found) {
            return '❌ 前 10 条内容里没找到封面图地址';
        }
        // 合并写入（learnImgHost 会自动去重 + 归并主域）
        foreach ($found as $h) {
            learnImgHost($id, $h);
        }
        return '✅ 识别到图片域名：' . implode('、', $found)
            . '（已写入白名单，可回到编辑表单查看）';
}

function adminActionToggleSource(array $post): string {
        // 数据源启用/关闭快捷开关
        $id = intval($post['id'] ?? 0);
        $row = getSource($id);
        if (!$row) {
            return '⚠️ 数据源不存在';
        } else {
            $newEnabled = $row['enabled'] ? 0 : 1;
            $stmt = db()->prepare('UPDATE sources SET enabled = ? WHERE id = ?');
            $stmt->execute([$newEnabled, $id]);
            // 契约：这里是裸 SQL，绕过了 updateSource()，所以要自己作废。
            // 1.3.10 之前漏了这条 —— 关掉的源在前台照旧展示最长一整点。
            pcClear();
            return $newEnabled
                ? '✅ 已启用「' . $row['name'] . '」'
                : '⏸ 已关闭「' . $row['name'] . '」（前台不再展示）';
        }
}

function adminActionSiteTemplate(array $post): string {
        // 站点默认模板
        $tpl = trim((string) ($post['site_template'] ?? ''));
        if ($tpl === '' || !tplExists($tpl)) {
            return '⚠️ 模板不存在：' . h($tpl);
        } else {
            setSetting('site_template', $tpl);
            return '✅ 站点默认模板已设为「' . h(tplMeta($tpl)['title']) . '」';
        }
}

function adminActionEditTemplate(array $post): string {
        // 简单的模板编辑：只允许改 CSS 变量，不碰模板结构
        $tpl = trim((string) ($post['template'] ?? ''));
        if ($tpl === '' || !tplExists($tpl)) {
            return '⚠️ 模板不存在';
        }
        $vars = $post['vars'] ?? [];
        if (!is_array($vars)) {
            return '⚠️ 参数格式错误';
        }
        // 只接受该模板 :root 里真实定义过的变量名（白名单来自模板自身），
        // 且按变量值的后缀做格式校验。这样新增模板、新增变量都不用改代码。
        $allowed = tplEditorVars($tpl);
        if (!$allowed) {
            return '⚠️ 该模板没有可调的样式变量';
        }
        $clean = [];
        foreach ($allowed as $varName => $info) {
            if (!isset($vars[$varName])) { continue; }
            $val = trim((string) $vars[$varName]);
            if ($val === '') { continue; }
            $kind = tplVarKind($info['value']);
            $norm = tplVarValidate($kind, $val);
            if ($norm !== null) {
                $clean[$varName] = $norm;
            }
        }
        setSetting('tpl_vars_' . $tpl, $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : '');
        return $clean
            ? '✅ 已保存「' . h(tplMeta($tpl)['title']) . '」的样式调整（' . count($clean) . ' 项）'
            : '⚠️ 没有有效的样式值被保存';
}

function adminActionResetTemplate(array $post): string {
        // 恢复模板样式为默认
        $tpl = trim((string) ($post['template'] ?? ''));
        if ($tpl === '' || !tplExists($tpl)) {
            return '⚠️ 模板不存在';
        }
        setSetting('tpl_vars_' . $tpl, '');
        return '✅ 已恢复「' . h(tplMeta($tpl)['title']) . '」的默认样式';
}

/**
 * 清理系统缓存
 *
 * 三项分开勾选：接口缓存与归一化记录是「数据缓存」，清了会重新请求上游；
 * OPcache 是「PHP 脚本缓存」，清了才会加载磁盘上新改的文件 ——
 * 虚拟主机上「传了文件前台没变化」九成是它，所以单独给一个开关。
 */
function adminActionClearCache(array $post): string {
        return clearSystemCache(
            intval($post['clear_api'] ?? 0) === 1,
            intval($post['clear_enrich'] ?? 0) === 1,
            intval($post['clear_opcache'] ?? 0) === 1,
            intval($post['clear_page'] ?? 0) === 1,
            intval($post['clear_img'] ?? 0) === 1
        );
}

/**
 * 导入配置（JSON）
 *
 * 支持上传文件或直接粘贴；文件优先。
 * 覆盖模式会清空现有分组与数据源，因此由前端 confirm 确认。
 */
function adminActionImportConfig(array $post): string {
        $read = configReadInput($_FILES, $post);
        if (!$read['ok']) {
            return $read['msg'];
        }
        $result = configImport($read['data'], (string) ($post['mode'] ?? 'merge'));
        return $result['msg'];
}
