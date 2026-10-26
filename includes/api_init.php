<?php
/**
 * API 引导文件 —— 浏览器插件 REST 接口专用
 *
 * 与 Web 端 includes/init.php 的区别：
 *  - 不启动 Session、不输出 HTML / CSP 响应头（避免破坏 JSON 响应）
 *  - 仅建立 DB 连接与加载辅助函数
 *  - 提供 Bearer 令牌鉴权、CORS、JSON 输出等 API 专用辅助
 *
 * 所有 /api/v1/* 入口都必须首行 require 本文件。
 */

if (!defined('DISPLAY_ERRORS')) {
    define('DISPLAY_ERRORS', 0);
}
ini_set('display_errors', DISPLAY_ERRORS ? '1' : '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);

date_default_timezone_set('Asia/Shanghai');

define('ROOT_PATH', dirname(__DIR__));

// 加载配置（未安装时返回 JSON 错误，而非 HTML 跳转）
$configFile = ROOT_PATH . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => ['code' => 'NOT_INSTALLED', 'message' => '系统未安装']], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $configFile;

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'bm_');
}

// 加载核心类与函数（auth.php 仅定义 Auth 类，不会触发会话，可安全引入）
require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/sync.php';

// 初始化数据库
DB::init(DB_HOST, DB_NAME, DB_USER, DB_PASS, DB_PREFIX);

// 加载系统设置到全局（log_operation / setting() 依赖）
$GLOBALS['settings'] = load_settings();

// ---------- CORS（插件跨域调用，Bearer 令牌已做鉴权） ----------
if (!headers_sent()) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');
}
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ---------- JSON 输出辅助 ----------
function api_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error($code, $message, $http = 400, $extra = []) {
    api_json(array_merge(['error' => ['code' => $code, 'message' => $message]], $extra), $http);
}

/**
 * 读取请求体 JSON（POST/PUT）
 */
function api_body() {
    $raw = file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return [];
    }
    return $data;
}

/**
 * Bearer 令牌校验
 * @return array|null 成功返回用户数组，并写入 $GLOBALS['_api_token_row']
 */
function api_authenticate() {
    $header = '';
    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $header = $_SERVER['HTTP_AUTHORIZATION'];
    } elseif (function_exists('getallheaders')) {
        $hs = getallheaders();
        $header = $hs['Authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(.+)$/i', trim($header), $m)) {
        return null;
    }
    $token = trim($m[1]);
    if ($token === '') {
        return null;
    }
    $hash = hash('sha256', $token);
    $row = DB::fetch(
        "SELECT * FROM " . DB_PREFIX . "api_tokens WHERE token_hash = ? AND revoked_at IS NULL",
        [$hash]
    );
    if (!$row) {
        return null;
    }
    // 刷新最近使用时间（非关键，失败忽略）
    try {
        DB::update(DB_PREFIX . 'api_tokens', ['last_used_at' => date('Y-m-d H:i:s')], 'id = ?', [$row['id']]);
    } catch (Exception $e) {
    }
    $user = DB::fetch("SELECT * FROM " . DB_PREFIX . "users WHERE id = ? AND is_active = 1", [$row['user_id']]);
    if (!$user) {
        return null;
    }
    $GLOBALS['_api_token_row'] = $row;
    return $user;
}

/**
 * 要求鉴权，否则 401
 */
function api_require_auth() {
    $user = api_authenticate();
    if (!$user) {
        api_error('UNAUTHORIZED', '令牌无效或已失效', 401);
    }
    return $user;
}

/**
 * 生成新令牌并入库，返回明文 token（仅此一次下发）
 */
function api_create_token($userId, $name) {
    $token = new_token();
    $hash = hash('sha256', $token);
    DB::insert(DB_PREFIX . 'api_tokens', [
        'user_id'     => $userId,
        'name'       => $name ?: '插件',
        'token_hash' => $hash,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    return $token;
}
