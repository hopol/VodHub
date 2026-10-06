<?php
/**
 * 数据层：SQLite 封装 + 表结构初始化 + 数据源读写
 */

require_once __DIR__ . '/../config.php';
// 页面静态缓存：setSetting() 末尾要作废它。必须在顶层引入，否则
// 「配置变了前台没反应」只在某些调用上下文里修好、另一些漏掉 —— 那是最难查的一类 bug。
// pagecache.php 只依赖 config.php 与 guard.php，不依赖本文件，所以这里不会成环。
require_once __DIR__ . '/pagecache.php';

/** 目标 schema 版本。dbInit() 用它判断「是否已经完整初始化」。 */
const DB_SCHEMA_VERSION = 7;

/** 获取数据库连接（单例，自动建表） */
function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0755, true);
    }
    vhGuardIndex(DATA_DIR);       // 数据目录若回退在 Web 根内，挡住列目录

    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // 支柱五 · SQLite 调优：
    //   WAL      → 读写不再互斥，并发请求不再排队等写锁
    //   NORMAL   → WAL 下官方推荐的 fsync 折中，大幅减少 I/O（免费主机按 I/O 计费）
    //   busy_timeout → 把「立刻报 database is locked」变成「等 3 秒」，避免并发峰值 500
    // 极少数共享主机不支持 WAL（journal_mode 会原样返回 delete），此时静默退回默认。
    // 注意：WAL 会产生 data.db-wal / data.db-shm，面板备份时要连它们一起拷。
    try {
        $pdo->query('PRAGMA journal_mode = WAL');
        $pdo->query('PRAGMA synchronous = NORMAL');
        $pdo->query('PRAGMA busy_timeout = 3000');
    } catch (Throwable $e) {
        // 不支持就用默认模式，功能不受影响
    }

    dbInit($pdo);
    return $pdo;
}

/** 初始化表结构与默认配置 */
function dbInit(PDO $pdo): void {
    // schema_version：用于后续升级表结构时判断当前版本
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');

    $currentVersion = (int) setting('schema_version');
    if ($currentVersion < 1) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS sources (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            name       TEXT    NOT NULL,
            api_url    TEXT    NOT NULL,
            enabled    INTEGER NOT NULL DEFAULT 1,
            sort       INTEGER NOT NULL DEFAULT 0,
            note       TEXT    NOT NULL DEFAULT "",
            template   TEXT    NOT NULL DEFAULT "",       -- 绑定的模板，空表示用站点默认
            created_at INTEGER NOT NULL
        )');
        setSetting('schema_version', '1');
    }

    // v2：sources 表增加 template 列（已存在则跳过）
    if ((int) setting('schema_version') < 2) {
        $cols = $pdo->query('PRAGMA table_info(sources)')->fetchAll();
        $hasTemplate = false;
        foreach ($cols as $c) {
            if ($c['name'] === 'template') { $hasTemplate = true; break; }
        }
        if (!$hasTemplate) {
            $pdo->exec('ALTER TABLE sources ADD COLUMN template TEXT NOT NULL DEFAULT ""');
        }
        setSetting('schema_version', '2');
    }

    // v3：数据源分组
    // 用独立 groups 表（组名可改、可排序、可删除而不破坏引用），
    // sources 通过 group_id 关联；group_id = 0 表示"未分组"。
    if ((int) setting('schema_version') < 3) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS groups (
            id   INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT    NOT NULL,
            sort INTEGER NOT NULL DEFAULT 0
        )');
        $cols = $pdo->query('PRAGMA table_info(sources)')->fetchAll();
        $hasGroupId = false;
        foreach ($cols as $c) {
            if ($c['name'] === 'group_id') { $hasGroupId = true; break; }
        }
        if (!$hasGroupId) {
            $pdo->exec('ALTER TABLE sources ADD COLUMN group_id INTEGER NOT NULL DEFAULT 0');
        }
        setSetting('schema_version', '3');
    }

    // v4：图片代理字段（按数据源开关；白名单显式填写，防 SSRF）
    if ((int) setting('schema_version') < 4) {
        $cols = $pdo->query('PRAGMA table_info(sources)')->fetchAll();
        $names = array_column($cols, 'name');
        if (!in_array('img_proxy', $names, true)) {
            $pdo->exec('ALTER TABLE sources ADD COLUMN img_proxy INTEGER NOT NULL DEFAULT 0');
        }
        if (!in_array('img_hosts', $names, true)) {
            // 逗号分隔的域名白名单，仅代理这些主机的图片
            $pdo->exec('ALTER TABLE sources ADD COLUMN img_hosts TEXT NOT NULL DEFAULT ""');
        }
        setSetting('schema_version', '4');
    }

    // ⚠ 以下 v5 / v6 两段是**历史迁移，1.5.0 起 enrich 表已废弃**（见 v7）。
    //   它们只为「老站从 v4 直升上来」保留，删掉会让迁移链断掉。
    //
    // v5：富化缓存（TypeSafe System One 的归一化结果，按 数据源+影片 落库）
    // 单条元数据 30 天不变，所以按 vod_id 缓存而不是每次重算；
    // 空 data 是负缓存（上次调用失败），由 enrich.php 按短 TTL 自愈。
    if ((int) setting('schema_version') < 5) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS enrich (
            source_id  INTEGER NOT NULL,
            vod_id     INTEGER NOT NULL,
            data       TEXT    NOT NULL DEFAULT "",
            created_at INTEGER NOT NULL,
            PRIMARY KEY (source_id, vod_id)
        )');
        setSetting('schema_version', '5');
    }

    // v6：enrich 表加 created_at 索引
    //
    // 读取路径（点查 + IN 批量）靠 `PRIMARY KEY (source_id, vod_id)` 已经够了，
    // 但 1.3.12 要新增的 guardGcEnrich() 要按时间删 —— 没有索引就是**全表扫描**，
    // 而 GC 是每次渲染都可能跑到的路径，在共享 CPU 上不能这么花。
    //
    // ⚠ 建索引用 IF NOT EXISTS：SQLite 不支持 `CREATE INDEX IF NOT EXISTS` 之外的
    //   重复创建，且迁移可能被重复执行（多进程同时首次访问）。
    if ((int) setting('schema_version') < 6) {
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_enrich_created ON enrich(created_at)');
        setSetting('schema_version', '6');
    }

    // v7：**删掉 enrich 表**（1.5.0 移除了 TypeSafe 归一化层）
    //
    // 为什么不「只删代码、留空表」：这张表在 1.3.11 之前**没有自动清理**，
    // 实测能涨到约 300 MB（1 GB 盘的三成）。留着它纯属占磁盘。
    //
    // ⚠ 上面 v5/v6 两段迁移**故意保留**：老站若停在 v4，升级时仍要能建表，
    //   然后紧接着被这里删掉。DROP TABLE IF EXISTS 幂等，重复执行无副作用。
    //   删掉 v5/v6 会让「从 v4 直升 1.5.0」的老站缺一张表再被 DROP —— 结果相同，
    //   但保留它们能让迁移链保持可读、也让将来想恢复的人有据可依。
    //
    // ⚠ 这个迁移**不可逆**：表里的归一化缓存删掉后回滚 1.4.1 需要重新积累
    //   （30 天 TTL 内会重新学一遍）。升级说明里已点明。
    if ((int) setting('schema_version') < 7) {
        $pdo->exec('DROP TABLE IF EXISTS enrich');
        setSetting('schema_version', '7');
    }

    // ---------------------------------------------------------------- 短路
    // 支柱五 · 收益最大的一处：已完整初始化的库直接返回，跳过下面的建表与默认值写入。
    //
    // 为什么要短路：$defaults 里有 password_hash(DEFAULT_ADMIN_PASSWORD, PASSWORD_DEFAULT)。
    // bcrypt 是刻意慢的算法，⚙️ 实测单次 46.8 ms、占整个 dbInit()（50.7 ms）的 93%，
    // 而这些键在安装后早已存在，下面的 ON CONFLICT DO NOTHING **一次都不会写入** ——
    // 等于每个 PHP 请求白付 47 ms 纯 CPU（免费主机的共享 CPU 上可能是 100~250 ms），
    // 而 img.php 这类高频入口同样要付。
    //
    // 判定必须放在**所有 schema 迁移之后**：放在前面会让未来的表结构升级静默失败。
    // 这里只认「版本已到目标」+「管理密码键存在且非空」，任一不满足就走完整初始化
    // （首次安装与老库升级路径完全不受影响）。
    if ((int) setting('schema_version') >= DB_SCHEMA_VERSION) {
        $chk = $pdo->prepare('SELECT value FROM settings WHERE key = ?');
        $chk->execute(['admin_password']);
        $hash = (string) $chk->fetchColumn();
        if ($hash !== '') {
            return;
        }
    }

    // 默认设置：不启用访问密码
    $defaults = [
        'access_enabled'   => '0',                       // 0=无需密码 1=需要密码
        'access_password'  => '',                        // 访问密码的哈希，空表示无密码
        'admin_password'   => password_hash(             // 后台密码的哈希
            DEFAULT_ADMIN_PASSWORD,
            PASSWORD_DEFAULT
        ),
        'site_title'       => APP_NAME,
        'player_autoplay'  => '1',
        'site_template'    => 'default',                 // 站点默认模板
        'list_columns'     => '0',                       // 列表列数（0 = 跟随模板默认）
    ];
    foreach ($defaults as $k => $v) {
        $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
            ON CONFLICT(key) DO NOTHING');
        $stmt->execute([$k, (string) $v]);
    }
}

/** 读取单个配置项 */
function setting(string $key, string $default = ''): string {
    static $cache = [];
    // 传入空键名为内部约定：清空缓存（供 setSetting 调用）
    if ($key === '') {
        $cache = [];
        return '';
    }
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $cache[$key] = $row ? (string) $row['value'] : $default;
}

/** 写入配置项 */
function setSetting(string $key, string $value): void {
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
        ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
    setting(''); // 清空读取缓存

    // 配置变更 → 页面静态缓存全量作废（顺带同步 c/.lock 总闸）。
    // 这是「改了配置前台没反应」这个最伤体验 bug 的唯一可靠防线：
    // .htaccess 读不到 SQLite（RewriteMap 在 .htaccess 中不可用），
    // 所以只能由 PHP 主动删掉 c/。
    // 顶层已 require pagecache.php，这里无条件调用 —— 不依赖调用上下文。
    pcClear();
}

// ⚠⚠ **契约：任何会改变前台展示的写入，都必须让页面静态缓存失效。**
//
//   2026-10-04 复查发现：数据源与分组的增/改/删/启停**全都绕过** setSetting()
//   （走的是下面的裸 CRUD 函数），于是 pcClear() 从来没被触发过 ——
//   后台提示「✅ 已更新数据源」，前台却照旧渲染旧的静态 HTML，
//   最长要等一整点才翻篇。
//
//   而 docs/lowpower.md 一直写着「任意配置写入都会自动全量作废」，
//   那句话对这一半是错的 —— 且它恰好是站长唯一会去看的地方。
//
//   **修法选「在数据层收口」而不是「在 dispatcher 收口」**，理由：
//     · dispatcher 收口要靠一张 action 白名单 —— 下次新增 action 又会漏，
//       而这正是本项目过去 12 次反复栽跟头的同一类错误；
//     · 数据层收口天然覆盖**所有**调用方（后台 POST、配置导入、
//       将来的 CLI / 计划任务），新增写入只要走这几个函数就自动带上失效。
//
//   这条契约由 tests/test_contracts.php 第 1 条强制，不靠记性。
//
//   ⚠ 但「收口」不等于「无脑全清」：deleteGroup() 会连带 UPDATE sources，
//     configImport() 一次写几十行 —— 逐次全量扫描 c/ 在免费主机上是纯浪费
//     （实测 60 个文件单次 5.9 ms）。批量路径请用 pcClearBatch() 包起来，
//     它让中途的 pcClear() 只登记意图、退出时清一次。

/** 取全部数据源（默认仅启用的） */
function getSources(bool $onlyEnabled = true): array {
    $sql = 'SELECT * FROM sources';
    if ($onlyEnabled) {
        $sql .= ' WHERE enabled = 1';
    }
    $sql .= ' ORDER BY sort ASC, id ASC';
    return db()->query($sql)->fetchAll();
}

/** 取单个数据源 */
function getSource(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM sources WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 新增数据源，返回 id */
function addSource(string $name, string $apiUrl, int $sort = 0, string $note = '',
                  string $template = '', int $groupId = 0, int $imgProxy = 0, string $imgHosts = '',
                  int $createdAt = 0): int {
    $stmt = db()->prepare('INSERT INTO sources
        (name, api_url, enabled, sort, note, template, group_id, img_proxy, img_hosts, created_at)
        VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)');
    // $createdAt > 0 时保留原始收录时间（配置导入要用），否则取当前时间
    $stmt->execute([$name, $apiUrl, $sort, $note, $template, $groupId, $imgProxy, $imgHosts,
                    $createdAt > 0 ? $createdAt : time()]);
    pcClear();   // 契约：新增源会改变页头的数据源标签栏与列表内容
    return (int) db()->lastInsertId();
}

/** 修改数据源 */
function updateSource(int $id, string $name, string $apiUrl, int $enabled, int $sort, string $note,
                      string $template = '', int $groupId = 0, int $imgProxy = 0, string $imgHosts = ''): void {
    $stmt = db()->prepare('UPDATE sources
        SET name = ?, api_url = ?, enabled = ?, sort = ?, note = ?, template = ?,
            group_id = ?, img_proxy = ?, img_hosts = ? WHERE id = ?');
    $stmt->execute([$name, $apiUrl, $enabled, $sort, $note, $template,
                    $groupId, $imgProxy, $imgHosts, $id]);
    // 契约：换模板 / 改名 / 改 URL / 改分组 / 改图片代理，前台全都看得见。
    // 这条 1.3.10 之前是漏的 —— 后台显示保存成功，前台一整点内都是旧页面。
    pcClear();
}

/** 删除数据源 */
function deleteSource(int $id): void {
    $stmt = db()->prepare('DELETE FROM sources WHERE id = ?');
    $stmt->execute([$id]);
    pcClear();   // 契约：已删的源不该继续出现在前台
}

// ==================================================================
// 数据源分组
// ==================================================================

/** 取全部分组 */
function getGroups(): array {
    return db()->query('SELECT * FROM groups ORDER BY sort ASC, id ASC')->fetchAll();
}

/** 取单个分组 */
function getGroup(int $id): ?array {
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM groups WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** 新增分组，返回 id */
function addGroup(string $name, int $sort = 0): int {
    $stmt = db()->prepare('INSERT INTO groups (name, sort) VALUES (?, ?)');
    $stmt->execute([$name, $sort]);
    pcClear();   // 契约：分组名与分组归属都渲染在页头
    return (int) db()->lastInsertId();
}

/** 修改分组（可改名、改排序） */
function updateGroup(int $id, string $name, int $sort): void {
    $stmt = db()->prepare('UPDATE groups SET name = ?, sort = ? WHERE id = ?');
    $stmt->execute([$name, $sort, $id]);
    pcClear();   // 契约：改分组名 / 排序后前台页头必须立刻跟着变
}

/** 删除分组：组内的源自动归入"未分组"（group_id = 0） */
function deleteGroup(int $id): void {
    db()->prepare('UPDATE sources SET group_id = 0 WHERE group_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM groups WHERE id = ?')->execute([$id]);
    pcClear();   // 契约：删组会让组内所有源改挂到「未分组」，前台页头随之变
}
