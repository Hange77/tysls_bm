<?php
/**
 * 核心引导文件 - 所有页面入口都应 require 此文件
 */

// 错误显示(生产环境建议关闭,安装程序会自动处理)
if (!defined('DISPLAY_ERRORS')) {
    define('DISPLAY_ERRORS', 0);
}
ini_set('display_errors', DISPLAY_ERRORS ? '1' : '0');
error_reporting(DISPLAY_ERRORS ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_STRICT);

// 时区
date_default_timezone_set('Asia/Shanghai');

// 会话配置(安全)
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Lax');
// 兼容反向代理/负载均衡(HTTPS 终止)场景,正确下发 Secure Cookie
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
    || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
    || (!empty($_SERVER['HTTP_CF_VISITED_SCHEME']) && strtolower($_SERVER['HTTP_CF_VISITED_SCHEME']) === 'https');
if ($isHttps) {
    ini_set('session.cookie_secure', 1);
}

// 安全响应头(防点击劫持 / MIME 嗅探 / 限制被嵌入第三方框架)
// 注意: 系统允许管理员粘贴自定义统计/广告代码(内联脚本),故不限制 script-src,仅限制 frame-ancestors
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Content-Security-Policy: frame-ancestors 'self'");
}

// 根目录
define('ROOT_PATH', dirname(__DIR__));

// 应用基础URL路径(子目录安装时自动检测,如 /v1)
// 先自动检测,配置文件中定义的 BASE_PATH 会覆盖
if (!defined('BASE_PATH')) {
    $basePath = '';
    if (!empty($_SERVER['SCRIPT_FILENAME']) && !empty($_SERVER['SCRIPT_NAME'])) {
        $scriptFs  = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME']);
        $scriptUrl = $_SERVER['SCRIPT_NAME'];
        $appRootFs = rtrim(str_replace('\\', '/', ROOT_PATH), '/');
        // 当前脚本文件系统路径去掉应用根目录,得到相对路径(如 /user/index.php)
        if (strpos($scriptFs, $appRootFs) === 0) {
            $relativePath = substr($scriptFs, strlen($appRootFs));
            // 从URL路径中去掉相同的相对路径,剩余就是基础路径(如 /v1)
            if ($relativePath && substr($scriptUrl, -strlen($relativePath)) === $relativePath) {
                $basePath = rtrim(substr($scriptUrl, 0, -strlen($relativePath)), '/');
            }
        }
    }
    define('BASE_PATH', $basePath);
}

// 加载配置
$configFile = ROOT_PATH . '/config.php';
if (!file_exists($configFile)) {
    // 未安装,跳转到安装程序
    header('Location: ' . BASE_PATH . '/install/install.php');
    exit;
}
require_once $configFile;

// 表前缀
if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'bm_');
}

// 加载核心类
require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/csrf.php';
require_once ROOT_PATH . '/includes/mail.php';

// 启动会话
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 初始化数据库连接
DB::init(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PREFIX);

// 初始化认证
Auth::init();

// 确保用户表含有邮箱验证/找回密码字段(老版本升级兼容)
ensure_email_columns();

// 确保书签表含有 domain 字段(v1.3.9.3 升级兼容)
ensure_bookmark_columns();

// 确保 users 表含有 home_layout 字段（v1.3.9.6 界面偏好）
ensure_user_layout_column();

// 加载系统设置到全局
$GLOBALS['settings'] = load_settings();

/**
 * 确保 users 表存在邮箱验证/找回密码相关字段(老库升级时按需 ALTER)
 */
function ensure_email_columns() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = DB::fetchAll("SHOW COLUMNS FROM " . DB_PREFIX . "users");
        $have = array_column($cols, 'Field');
        $need = ['email_verified', 'verify_token', 'verify_expire', 'reset_token', 'reset_expire'];
        $missing = array_diff($need, $have);
        if ($missing) {
            $alters = [];
            if (in_array('email_verified', $missing)) $alters[] = "ADD COLUMN `email_verified` tinyint(1) NOT NULL DEFAULT 0 COMMENT '邮箱是否已验证'";
            if (in_array('verify_token',  $missing)) $alters[] = "ADD COLUMN `verify_token` varchar(64) NOT NULL DEFAULT '' COMMENT '验证token哈希'";
            if (in_array('verify_expire', $missing)) $alters[] = "ADD COLUMN `verify_expire` int(11) NOT NULL DEFAULT 0 COMMENT '验证过期时间'";
            if (in_array('reset_token',   $missing)) $alters[] = "ADD COLUMN `reset_token` varchar(64) NOT NULL DEFAULT '' COMMENT '找回密码token哈希'";
            if (in_array('reset_expire',  $missing)) $alters[] = "ADD COLUMN `reset_expire` int(11) NOT NULL DEFAULT 0 COMMENT '找回密码过期时间'";
            DB::execute("ALTER TABLE " . DB_PREFIX . "users " . implode(', ', $alters));
            // 已存在的老用户无法回溯验证其邮箱, 统一标记为已验证
            DB::execute("UPDATE " . DB_PREFIX . "users SET email_verified = 1 WHERE email_verified = 0");
        }
    } catch (Exception $e) {
        error_log('ensure_email_columns failed: ' . $e->getMessage());
    }
}

/**
 * 确保 bookmarks 表存在 domain 字段(老库升级时按需 ALTER)
 * 并回填老数据的 domain(由 url 派生)
 */
function ensure_bookmark_columns() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = DB::fetchAll("SHOW COLUMNS FROM " . DB_PREFIX . "bookmarks");
        $have = array_column($cols, 'Field');
        $alters = [];
        if (!in_array('domain', $have))         $alters[] = "ADD COLUMN `domain` varchar(255) NOT NULL DEFAULT '' COMMENT '域名(由url派生,用于筛选/查重)'";
        if ($alters) {
            DB::execute("ALTER TABLE " . DB_PREFIX . "bookmarks " . implode(', ', $alters));
        }
        // 回填老数据 domain
        $needFix = DB::fetchAll("SELECT id, url FROM " . DB_PREFIX . "bookmarks WHERE domain = '' OR domain IS NULL");
        foreach ($needFix as $row) {
            DB::update(DB_PREFIX . 'bookmarks', ['domain' => extract_domain($row['url'])], 'id = ?', [$row['id']]);
        }
    } catch (Exception $e) {
        error_log('ensure_bookmark_columns failed: ' . $e->getMessage());
    }
}

/**
 * 确保 users 表存在 home_layout 字段(界面偏好,老库升级时按需 ALTER)
 */
function ensure_user_layout_column() {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $cols = DB::fetchAll("SHOW COLUMNS FROM " . DB_PREFIX . "users");
        $have = array_column($cols, 'Field');
        if (!in_array('home_layout', $have)) {
            DB::execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN `home_layout` varchar(50) NOT NULL DEFAULT 'default' COMMENT '首页布局:default/vertical' AFTER `dark_mode`");
        }
    } catch (Exception $e) {
        error_log('ensure_user_layout_column failed: ' . $e->getMessage());
    }
}

/**
 * 获取系统设置
 * 注：setting() 与 load_settings() 已迁移至 includes/functions.php，
 * 供 web 端与 API 端（api_init.php）共用，避免 API 上下文未定义函数。
 */
