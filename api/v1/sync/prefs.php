<?php
/**
 * 同步偏好设置
 * GET  /api/v1/sync/prefs     读取
 * POST /api/v1/sync/prefs     更新 {delete_enabled?, trash_days?, protected_category_ids?}
 */
require_once __DIR__ . '/../../../includes/api_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    api_json([], 204);
}
$user = api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    api_json(['sync' => api_get_sync_prefs($user['id'])]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = api_body();
    $prefs = api_set_sync_prefs($user['id'], $body);
    log_operation('sync_prefs', 'user', $user['id'], '更新同步偏好');
    api_json(['success' => true, 'sync' => $prefs]);
}

api_error('METHOD_NOT_ALLOWED', '不支持的请求方法', 405);
