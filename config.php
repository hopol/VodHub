<?php
/**
 * 全局配置
 * 除本文件外，其余配置均存放在数据库（runtime/data.db）中，可在后台修改。
 */

// ---------------------------------------------------------------- PHP 版本保护
// 必须放在本文件最顶部：项目用了 str_contains、str_starts_with、命名参数等 PHP 8 特性，
// 在旧版本上会直接致命错误或静默白屏。这里把"莫名其妙白屏"变成明确提示。
if (PHP_VERSION_ID < 80000) {
    if (!headers_sent()) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: text/html; charset=utf-8');
    }
    exit(
        '<!DOCTYPE html><html lang="zh-CN"><meta charset="utf-8">'
        . '<title>PHP 版本过低</title>'
        . '<body style="font-family:sans-serif;padding:40px;line-height:1.8">'
        . '<h1>需要 PHP 8.0 及以上</h1>'
        . '<p>当前环境的 PHP 版本：<b>' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '</b></p>'
        . '<p>本站使用了 PHP 8 引入的语言特性，无法在低版本上运行。</p>'
        . '<p>解决办法：在空间面板中将 PHP 版本切换到 <b>8.0 / 8.1 / 8.2 / 8.3</b>，'
        . '或联系空间服务商升级运行环境。</p>'
        . '</body></html>'
    );
}

// 同时要求关键扩展，缺了也是白屏
foreach (['curl', 'pdo_sqlite'] as $requiredExt) {
    if (!extension_loaded($requiredExt)) {
        if (!headers_sent()) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: text/html; charset=utf-8');
        }
        exit(
            '<!DOCTYPE html><html lang="zh-CN"><meta charset="utf-8">'
            . '<title>缺少 PHP 扩展</title>'
            . '<body style="font-family:sans-serif;padding:40px;line-height:1.8">'
            . '<h1>缺少必需的 PHP 扩展</h1>'
            . '<p>缺失扩展：<b>' . htmlspecialchars($requiredExt, ENT_QUOTES, 'UTF-8') . '</b></p>'
            . '<p>请在空间面板中启用 <code>curl</code> 与 <code>pdo_sqlite</code> 扩展后重试。</p>'
            . '</body></html>'
        );
    }
}

// 站点名称（显示在页面标题与页头）
define('APP_NAME', '影视聚合站');

// 版本号（与 CHANGELOG.md 保持一致）
define('APP_VERSION', '1.3.0');

// 运行数据目录（数据库、缓存，部署后需保证可写）
define('DATA_DIR', __DIR__ . '/runtime');

// SQLite 数据库文件
define('DB_FILE', DATA_DIR . '/data.db');

// 接口响应缓存目录
define('CACHE_DIR', DATA_DIR . '/cache');

// 缓存有效期（秒）。上游接口不稳定，缓存既是性能手段也是容灾手段
define('CACHE_TTL', 1800);

// 分类数据（ac=list）的缓存有效期：分类几乎不变，走长缓存以减少上游请求。
// 列表/详情仍用 CACHE_TTL，两者分开是为了避免分类被短 TTL 拖累。
define('CACHE_TTL_TYPE', 86400); // 24 小时

// 上游失败的负缓存：失败后这段时间内**完全不出站**，
// 直接用过期数据或空结果。这是消灭「上游一挂，每次点击白等 12 秒」
// 这个故障放大器的关键 —— 没有它，故障期间每一次访问都在占 EP 槽位。
define('CACHE_TTL_NEG', 60);

// ---------------------------------------------------------------- 极致低功耗模式
// 页面静态缓存目录。必须在站点根目录下（.htaccess 用相对路径 rewrite 到这里），
// 不能放进 runtime/ —— runtime/ 被 .htaccess 整个 [F] 拦掉，静态文件会被挡在门外。
define('PAGE_CACHE_DIR', __DIR__ . '/c');

// 图片代理本地缓存目录。可被 Web 直接访问（.htaccess 只拦 runtime/ 与 templates/*.php），
// 由 .htaccess 的 ExpiresByType image/* 享受 30 天缓存。
define('IMG_CACHE_DIR', __DIR__ . '/static/imgcache');

// 会话名称
define('SESSION_NAME', 'vodsite_sid');

// 会话键名
define('SESS_ACCESS_OK', 'access_ok');
define('SESS_ADMIN_OK',  'admin_ok');
define('SESS_CSRF',      'csrf_token');

// 后台默认密码（首次安装后请立即在后台修改）
define('DEFAULT_ADMIN_PASSWORD', 'admin123');

// 时区
date_default_timezone_set('Asia/Shanghai');

// 错误显示（生产环境建议关闭）
error_reporting(E_ALL);
ini_set('display_errors', '1');
