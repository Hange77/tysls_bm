<?php
/**
 * 插件登录：账号密码 -> Bearer Token
 * POST /api/v1/login
 * body: {username, password, device_name?}
 */
require_once __DIR__ . '/../../includes/api_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('METHOD_NOT_ALLOWED', '请使用 POST 请求', 405);
}

$body = api_body();
$username = trim((string)($body['username'] ?? ''));
$password = (string)($body['password'] ?? '');
$name = trim((string)($body['device_name'] ?? $body['name'] ?? '插件'));

if ($username === '' || $password === '') {
    api_error('VALIDATION', '用户名或密码不能为空', 422);
}

// 限频（防暴力破解）：IP + 账号双维度
$ipKey = 'api_login_ip_' . get_client_ip();
$userKey = 'api_login_user_' . $username;
$ipRl = rate_limit_check($ipKey, 20, 600);   // 同 IP 10 分钟最多 20 次失败
$userRl = rate_limit_check($userKey, 5, 900); // 同账号 15 分钟最多 5 次失败
if ($ipRl['limited'] || $userRl['limited']) {
    api_error('RATE_LIMITED', '尝试过于频繁，请稍后再试', 429, ['retry_after' => max($ipRl['retry_after'], $userRl['retry_after'])]);
}

$user = DB::fetch("SELECT * FROM " . DB_PREFIX . "users WHERE username = ? OR email = ?", [$username, $username]);
if (!$user || !safe_password_verify($password, $user['password'])) {
    rate_limit_hit($ipKey);
    rate_limit_hit($userKey);
    api_error('INVALID_CREDENTIALS', '用户名或密码错误', 401);
}
if (!$user['is_active']) {
    api_error('FORBIDDEN', '账号已被禁用', 403);
}
rate_limit_reset($ipKey);
rate_limit_reset($userKey);

// 更新登录信息
DB::update(DB_PREFIX . 'users', [
    'last_login_at' => date('Y-m-d H:i:s'),
    'last_login_ip' => get_client_ip(),
], 'id = :id', [':id' => $user['id']]);

$token = api_create_token($user['id'], $name);
log_operation('api_login', 'user', $user['id'], '插件登录: ' . $name);

api_json([
    'token'     => $token,
    'user_id'   => (int)$user['id'],
    'username'  => $user['username'],
    'nickname'  => $user['nickname'],
    'expires_at'=> null, // 长期有效，可手动吊销
]);
