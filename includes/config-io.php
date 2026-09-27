<?php
/**
 * 配置导入导出 + 系统缓存清理
 *
 * 两个入口共用本文件：
 *   - admin.php 的 GET ?export=1  → configExport() 直接吐 JSON 附件
 *   - admin-actions.php 的 POST   → configImport() / clearSystemCache()
 *
 * 导出的是「配置」，不是「数据」：
 *   导出 settings / groups / sources；
 *   不导出 enrich 归一化记录、runtime/cache 接口缓存 —— 那些是可重建的缓存。
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/enrich.php';

/** 配置文件格式版本。破坏性改动时 +1，导入按此拒收不认识的版本 */
const CONFIG_FORMAT   = 'vodhub-config';
const CONFIG_VERSION  = 1;

/** 敏感键：默认不导出，只有导出时显式勾选才带上 */
const CONFIG_SECRET_KEYS = ['admin_password', 'access_password', 'enrich_api_key'];

/** 导入时绝不覆盖的键 —— schema 由 dbInit() 的迁移负责，改了会让升级逻辑错乱 */
const CONFIG_DENY_KEYS = ['schema_version'];

/** 导入体积上限（字节）。配置 JSON 通常只有几 KB，这里留足余量同时防 DoS */
const CONFIG_MAX_BYTES = 2 * 1024 * 1024;

// ==================================================================
// 系统缓存
// ==================================================================

/** 缓存现状：接口缓存文件数/体积 + 归一化记录数（后台状态行用） */
function cacheStats(): array {
    $files = 0;
    $bytes = 0;
    foreach ((glob(CACHE_DIR . '/*.json') ?: []) as $f) {
        if (is_file($f)) {
            $files++;
            $bytes += (int) filesize($f);
        }
    }
    return [
        'files'  => $files,
        'bytes'  => $bytes,
        'size'   => bytesHuman($bytes),
        'enrich' => enrichCacheCount(),
    ];
}

/** 字节数转可读串 */
function bytesHuman(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
}

/**
 * OPcache 现状。
 *
 * 这是「传了文件前台没变化」的头号原因：虚拟主机常开 opcache.enable=1，
 * 且 opcache.validate_timestamps=0（永不重新校验），换文件后必须显式 reset。
 * 后台要能把这个状态摆出来，否则用户无从判断卡在哪一层。
 */
function opcacheInfo(): array {
    if (!function_exists('opcache_get_status')) {
        return ['available' => false, 'enabled' => false,
                'msg' => '当前 PHP 未安装 OPcache 扩展'];
    }
    $restrict = (string) ini_get('opcache.restrict_api');
    if ($restrict !== '' && !str_starts_with(__FILE__, rtrim($restrict, '/'))) {
        return ['available' => false, 'enabled' => false,
                'msg' => 'OPcache 受 opcache.restrict_api 限制，本脚本路径不可调用'];
    }
    $st = @opcache_get_status(false);
    if (!is_array($st)) {
        return ['available' => false, 'enabled' => false,
                'msg' => 'OPcache 未启用（opcache.enable=0）'];
    }
    $enabled = (bool) ($st['opcache_enabled'] ?? false);

    $revalidate = (string) ini_get('opcache.revalidate_freq');
    $timestamps = (string) ini_get('opcache.validate_timestamps');

    $msg = 'OPcache 已启用';
    // validate_timestamps=0 是「换文件不生效」的根因，必须点出来
    if ($timestamps === '0') {
        $msg .= '，**且 validate_timestamps=0（永不自动重新校验）** —— 换文件后必须手动重置才会生效';
    } else {
        $msg .= '，约 ' . ($revalidate === '' ? '0' : $revalidate) . ' 秒后自动校验新文件';
    }
    // 内存信息在顶层 memory_usage 里 —— opcache_statistics 只有计数与命中率，
    // 读错层级会显示成「0 B」，看着像没启用。
    $mem = is_array($st['memory_usage'] ?? null) ? $st['memory_usage'] : [];
    $used  = (int) ($mem['used_memory'] ?? 0);
    $free  = (int) ($mem['free_memory'] ?? 0);
    $waste = (int) ($mem['wasted_memory'] ?? 0);

    return [
        'available'  => true,
        'enabled'    => $enabled,
        'scripts'    => (int) ($st['opcache_statistics']['num_cached_scripts'] ?? 0),
        'keys'       => (int) ($st['opcache_statistics']['num_cached_keys'] ?? 0),
        'max_keys'   => (int) ($st['opcache_statistics']['max_cached_keys'] ?? 0),
        'memory'     => '已用 ' . bytesHuman($used) . ' / 空闲 ' . bytesHuman($free)
                     . ($waste > 0 ? ' / 浪费 ' . bytesHuman($waste) : ''),
        'timestamps' => $timestamps,
        'msg'        => $msg,
    ];
}

/**
 * 重置 OPcache。
 *
 * @return array ['ok'=>bool, 'msg'=>string]
 */
function opcacheReset(): array {
    if (!function_exists('opcache_reset')) {
        return ['ok' => false, 'msg' => '当前 PHP 未调用 opcache_reset（扩展未装或被 restrict_api 挡住）'];
    }
    $restrict = (string) ini_get('opcache.restrict_api');
    if ($restrict !== '' && !str_starts_with(__FILE__, rtrim($restrict, '/'))) {
        return ['ok' => false, 'msg' => 'OPcache 受 opcache.restrict_api 限制，无法在此路径调用重置'];
    }
    try {
        $ok = (bool) @opcache_reset();
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'opcache_reset() 抛错：' . $e->getMessage()];
    }
    if (!$ok) {
        return ['ok' => false, 'msg' => 'opcache_reset() 返回 false（多半是 opcache.enable=0，或无调用权限）'];
    }
    return ['ok' => true, 'msg' => 'OPcache 已重置，PHP 脚本缓存全部作废'];
}

/**
 * 清理系统缓存。
 *
 * @param bool $api      清 runtime/cache/*.json（接口响应）
 * @param bool $enrich   清 enrich 表（字段归一化记录）
 * @param bool $opcache  重置 PHP 脚本缓存
 * @return string 汇报消息
 */
function clearSystemCache(bool $api, bool $enrich, bool $opcache): string {
    $parts = [];

    if ($api) {
        $n = 0;
        $bytes = 0;
        foreach ((glob(CACHE_DIR . '/*.json') ?: []) as $f) {
            if (is_file($f)) {
                $bytes += (int) filesize($f);
                if (@unlink($f)) {
                    $n++;
                }
            }
        }
        $parts[] = '接口缓存 ' . $n . ' 个文件（' . bytesHuman($bytes) . '）';
    }

    if ($enrich) {
        enrichClearCache();
        $parts[] = '归一化记录已清';
    }

    if ($opcache) {
        $r = opcacheReset();
        $parts[] = ($r['ok'] ? '✅ ' : '⚠️ ') . $r['msg'];
    }

    if (!$parts) {
        return '⚠️ 一项都没勾，什么也没清';
    }
    return '✅ 已清理：' . implode('；', $parts);
}

// ==================================================================
// 配置导出
// ==================================================================

/**
 * 组装导出数据。
 *
 * @param bool $withSecrets 是否带上密码哈希与 API 密钥（默认不带）
 */
function configExport(bool $withSecrets = false): array {
    $settings = [];
    foreach (db()->query('SELECT key, value FROM settings ORDER BY key') as $r) {
        $k = (string) $r['key'];
        if (in_array($k, CONFIG_DENY_KEYS, true)) {
            continue;
        }
        if (!$withSecrets && in_array($k, CONFIG_SECRET_KEYS, true)) {
            continue;
        }
        $settings[$k] = (string) $r['value'];
    }

    $groups = [];
    foreach (getGroups() as $g) {
        $groups[] = [
            'id'   => intval($g['id']),
            'name' => (string) $g['name'],
            'sort' => intval($g['sort']),
        ];
    }

    $sources = [];
    foreach (getSources(false) as $s) {
        $sources[] = [
            // id 必须导出：覆盖导入时按它原样恢复，
            // 否则 img.php?s=N 与 ?source=N 这类 URL 升级后会全部错位
            'id'         => intval($s['id']),
            'name'       => (string) $s['name'],
            'api_url'    => (string) $s['api_url'],
            'enabled'    => intval($s['enabled']),
            'sort'       => intval($s['sort']),
            'note'       => (string) $s['note'],
            'template'   => (string) $s['template'],
            'group_id'   => intval($s['group_id']),
            'img_proxy'  => intval($s['img_proxy']),
            'img_hosts'  => (string) $s['img_hosts'],
            'created_at' => intval($s['created_at']),
        ];
    }

    return [
        'format'          => CONFIG_FORMAT,
        'version'         => CONFIG_VERSION,
        'generator'       => 'VodHub',
        'exported_at'     => date('c'),
        'schema_version'  => intval(setting('schema_version')),
        'include_secrets' => $withSecrets,
        'settings'        => $settings,
        'groups'          => $groups,
        'sources'         => $sources,
    ];
}

/** 输出 JSON 附件并终止 */
function configExportDownload(bool $withSecrets): void {
    $data = configExport($withSecrets);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        http_response_code(500);
        exit('导出失败：' . json_last_error_msg());
    }
    $name = 'vodhub-config-' . date('Ymd-His') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-store');
    echo $json;
    exit;
}

// ==================================================================
// 配置导入
// ==================================================================

/**
 * 导入配置。
 *
 * @param array  $data 已解码的配置数组
 * @param string $mode 'merge' 按接口地址匹配，只增不删；'replace' 清空重建
 * @return array ['ok'=>bool, 'msg'=>string]
 */
function configImport(array $data, string $mode): array {
    if (($data['format'] ?? '') !== CONFIG_FORMAT) {
        return ['ok' => false, 'msg' => '⚠️ 不是 VodHub 配置文件（缺少 format = "' . CONFIG_FORMAT . '"）'];
    }
    $ver = intval($data['version'] ?? 0);
    if ($ver < 1 || $ver > CONFIG_VERSION) {
        return ['ok' => false, 'msg' => '⚠️ 配置版本 ' . $ver . ' 不受支持（本版支持 1 ~ ' . CONFIG_VERSION . '）'];
    }
    if ($mode !== 'merge' && $mode !== 'replace') {
        $mode = 'merge';
    }

    $settings = is_array($data['settings'] ?? null) ? $data['settings'] : [];
    $groups   = is_array($data['groups'] ?? null) ? $data['groups'] : [];
    $sources  = is_array($data['sources'] ?? null) ? $data['sources'] : [];
    if (!$settings && !$groups && !$sources) {
        return ['ok' => false, 'msg' => '⚠️ 配置文件里没有 settings / groups / sources 任何一段'];
    }

    // 密钥单独摘出来：只有文件本身带了才覆盖，避免导入普通备份时把后台密码冲掉
    $secrets = [];
    foreach (CONFIG_SECRET_KEYS as $k) {
        if (array_key_exists($k, $settings)) {
            $secrets[$k] = (string) $settings[$k];
            unset($settings[$k]);
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $groupsAdded = 0;
        $gidMap = [];   // 文件里的旧分组 id => 新分组 id

        if ($mode === 'replace') {
            $pdo->exec('DELETE FROM sources');
            $pdo->exec('DELETE FROM groups');
        }

        foreach ($groups as $g) {
            if (!is_array($g) || trim((string) ($g['name'] ?? '')) === '') {
                continue;
            }
            $oldId = intval($g['id'] ?? 0);
            if ($mode === 'replace' && $oldId > 0) {
                // 覆盖模式表已清空，可以原样恢复 id —— 否则列表页/播放页收藏的
                // ?source=N 与 img.php?s=N 全部错位（实测 id 会从 8 跳到 16）。
                $pdo->prepare('INSERT INTO groups (id, name, sort) VALUES (?, ?, ?)')
                    ->execute([$oldId, (string) $g['name'], intval($g['sort'] ?? 0)]);
                $newId = $oldId;
            } else {
                $newId = addGroup((string) $g['name'], intval($g['sort'] ?? 0));
            }
            if ($oldId > 0) {
                $gidMap[$oldId] = $newId;
            }
            $groupsAdded++;
        }

        $added = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($sources as $s) {
            if (!is_array($s)) {
                $skipped++;
                continue;
            }
            $name = trim((string) ($s['name'] ?? ''));
            $url  = trim((string) ($s['api_url'] ?? ''));
            if ($name === '' || $url === '' || !preg_match('#^https?://#i', $url)) {
                $skipped++;   // 名称/地址缺失或协议非法的条目直接跳过，不让一条坏数据废掉整次导入
                continue;
            }

            // 分组 id 要重映射：新旧库的自增 id 不可能一样
            // 分组 id 必须重映射：新旧库的自增 id 不可能一致。
            // 文件里指向的分组若不在文件中，就归入「未分组」。
            $fileGid = intval($s['group_id'] ?? 0);
            $gidParam = $fileGid > 0 ? intval($gidMap[$fileGid] ?? 0) : 0;

            $exists = $pdo->prepare('SELECT id FROM sources WHERE api_url = ?');
            $exists->execute([$url]);
            $row = $exists->fetch();

            if ($row && $mode === 'merge') {
                updateSource(
                    intval($row['id']), $name, $url,
                    intval($s['enabled'] ?? 1), intval($s['sort'] ?? 0),
                    (string) ($s['note'] ?? ''), (string) ($s['template'] ?? ''),
                    $gidParam, intval($s['img_proxy'] ?? 0), (string) ($s['img_hosts'] ?? '')
                );
                $updated++;
            } elseif (!$row) {
                $fileSid = intval($s['id'] ?? 0);
                if ($mode === 'replace' && $fileSid > 0) {
                    // 覆盖模式下表是空的，按文件里的 id 原样插入，
                    // 保证 img.php?s=N、list.php?source=N 这类 URL 升级后仍然有效
                    $pdo->prepare(
                        'INSERT INTO sources
                           (id, name, api_url, enabled, sort, note, template,
                            group_id, img_proxy, img_hosts, created_at)
                         VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([
                        $fileSid, $name, $url, intval($s['sort'] ?? 0),
                        (string) ($s['note'] ?? ''), (string) ($s['template'] ?? ''),
                        $gidParam, intval($s['img_proxy'] ?? 0),
                        (string) ($s['img_hosts'] ?? ''),
                        intval($s['created_at'] ?? 0) > 0 ? intval($s['created_at'] ?? 0) : time(),
                    ]);
                } else {
                    addSource(
                        $name, $url, intval($s['sort'] ?? 0), (string) ($s['note'] ?? ''),
                        (string) ($s['template'] ?? ''), $gidParam,
                        intval($s['img_proxy'] ?? 0), (string) ($s['img_hosts'] ?? ''),
                        intval($s['created_at'] ?? 0)
                    );
                }
                $added++;
            } else {
                $skipped++;   // replace 模式下不会走到这；merge 模式下 api_url 已处理
            }
        }

        // 设置：白名单式写入，格式非法的键直接丢弃
        $settingsApplied = 0;
        foreach ($settings as $k => $v) {
            $k = (string) $k;
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $k) || in_array($k, CONFIG_DENY_KEYS, true)) {
                continue;
            }
            setSetting($k, (string) $v);
            $settingsApplied++;
        }
        foreach ($secrets as $k => $v) {
            setSetting($k, (string) $v);
            $settingsApplied++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'msg' => '❌ 导入失败，已回滚：' . $e->getMessage()];
    }

    setting('');                          // settings 有静态缓存，写完必须清
    clearSystemCache(true, false, false); // 分组/源可能全变了，接口响应缓存全部作废

    $msg = sprintf(
        '✅ 导入完成（%s）：分组 +%d，数据源 +%d 改 %d 跳过 %d，设置写入 %d 项',
        $mode === 'replace' ? '覆盖' : '合并',
        $groupsAdded, $added, $updated, $skipped, $settingsApplied
    );
    if (!$secrets) {
        $msg .= '；文件未含密钥，后台密码与访问密码保持不变';
    }
    return ['ok' => true, 'msg' => $msg];
}

/**
 * 读取上传或粘贴进来的配置文本，解码成数组。
 *
 * @return array ['ok'=>bool, 'msg'=>string, 'data'=>array]
 */
function configReadInput(array $files, array $post): array {
    $text = '';

    if (!empty($files['config_file']['tmp_name']) && is_uploaded_file($files['config_file']['tmp_name'])) {
        $size = intval($files['config_file']['size'] ?? 0);
        if ($size > CONFIG_MAX_BYTES) {
            return ['ok' => false, 'msg' => '⚠️ 配置文件超过 ' . bytesHuman(CONFIG_MAX_BYTES) . ' 上限'];
        }
        if (intval($files['config_file']['error'] ?? 0) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'msg' => '⚠️ 文件上传失败（error=' . intval($files['config_file']['error'] ?? -1) . '）'];
        }
        $text = (string) file_get_contents($files['config_file']['tmp_name']);
    }

    if ($text === '' && trim((string) ($post['config_text'] ?? '')) !== '') {
        $text = (string) $post['config_text'];
    }

    if (trim($text) === '') {
        return ['ok' => false, 'msg' => '⚠️ 请选择配置文件，或把 JSON 粘贴到文本框'];
    }
    if (strlen($text) > CONFIG_MAX_BYTES) {
        return ['ok' => false, 'msg' => '⚠️ 配置内容超过 ' . bytesHuman(CONFIG_MAX_BYTES) . ' 上限'];
    }

    $data = json_decode($text, true);
    if (!is_array($data)) {
        return ['ok' => false, 'msg' => '⚠️ 不是有效的 JSON：' . json_last_error_msg()];
    }
    return ['ok' => true, 'msg' => '', 'data' => $data];
}
