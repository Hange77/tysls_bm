<?php
/**
 * 吊销当前令牌（登出插件）
 * POST /api/v1/logout
 */
require_once __DIR__ . '/../../includes/api_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('METHOD_NOT_ALLOWED', '请使用 POST 请求', 405);
}
$user = api_require_auth();

$row = $GLOBALS['_api_token_row'] ?? null;
if ($row && !empty($row['id'])) {
    DB::update(DB_PREFIX . 'api_tokens', ['revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [(int)$row['id']]);
    log_operation('api_logout', 'user', $user['id'], '插件登出（吊销令牌）');
}

api_json(['success' => true, 'message' => '已登出']);
