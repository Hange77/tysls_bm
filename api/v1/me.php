<?php
/**
 * 当前用户信息 + 同步偏好
 * GET /api/v1/me
 */
require_once __DIR__ . '/../../includes/api_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('METHOD_NOT_ALLOWED', '请使用 GET 请求', 405);
}
$user = api_require_auth();

$prefs = api_get_sync_prefs($user['id']);
$root = api_get_browser_root($user['id']);

api_json([
    'user_id'  => (int)$user['id'],
    'username' => $user['username'],
    'nickname' => $user['nickname'],
    'email'    => $user['email'],
    'sync'     => [
        'delete_enabled'         => !empty($prefs['delete_enabled']),
        'trash_days'             => (int)$prefs['trash_days'],
        'protected_category_ids' => $prefs['protected_category_ids'],
        'browser_root'           => $root ? [
            'id'     => (int)$root['id'],
            'name'   => $root['name'],
            'ext_id' => $root['ext_id'],
        ] : null,
    ],
]);
