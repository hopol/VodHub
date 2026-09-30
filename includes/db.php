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
const DB_SCHEMA_VERSION = 5;

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
        'enrich_enabled'    => '1',                       // 播放页字段归一化（TypeSafe），0 = 关闭
        'enrich_base_url'   => 'https://opencode.ai/zen/v1/systemone',
        'enrich_model'      => 'jev-1.13-free',
        'enrich_api_key'    => 'public',
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
}

/** 删除数据源 */
function deleteSource(int $id): void {
    $stmt = db()->prepare('DELETE FROM sources WHERE id = ?');
    $stmt->execute([$id]);
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
    return (int) db()->lastInsertId();
}

/** 修改分组（可改名、改排序） */
function updateGroup(int $id, string $name, int $sort): void {
    $stmt = db()->prepare('UPDATE groups SET name = ?, sort = ? WHERE id = ?');
    $stmt->execute([$name, $sort, $id]);
}

/** 删除分组：组内的源自动归入"未分组"（group_id = 0） */
function deleteGroup(int $id): void {
    db()->prepare('UPDATE sources SET group_id = 0 WHERE group_id = ?')->execute([$id]);
    db()->prepare('DELETE FROM groups WHERE id = ?')->execute([$id]);
}
