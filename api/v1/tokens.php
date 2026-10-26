<?php
/**
 * 令牌管理
 * GET  /api/v1/tokens     列出当前用户全部令牌
 * DELETE /api/v1/tokens?id=  吊销指定令牌（仅限本人）
 */
require_once __DIR__ . '/../../includes/api_init.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    api_json([], 204);
}
$user = api_require_auth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = DB::fetchAll(
        "SELECT id, name, last_used_at, created_at, revoked_at FROM " . DB_PREFIX . "api_tokens WHERE user_id = ? ORDER BY created_at DESC",
        [$user['id']]
    );
    api_json(['tokens' => array_map(function ($r) {
        return [
            'id'          => (int)$r['id'],
            'name'        => $r['name'],
            'last_used_at'=> $r['last_used_at'],
            'created_at'  => $r['created_at'],
            'revoked'     => !empty($r['revoked_at']),
        ];
    }, $rows)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        api_error('VALIDATION', '缺少令牌 id', 422);
    }
    // 禁止吊销当前正在使用的令牌（避免把自己踢下线时误删，需用 logout）
    $current = $GLOBALS['_api_token_row'] ?? null;
    if ($current && (int)$current['id'] === $id) {
        api_error('VALIDATION', '不能吊销当前令牌，请使用 logout', 422);
    }
    $aff = DB::execute(
        "UPDATE " . DB_PREFIX . "api_tokens SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL",
        [date('Y-m-d H:i:s'), $id, $user['id']]
    );
    if ($aff > 0) {
        log_operation('api_token_revoke', 'user', $user['id'], '吊销令牌 #' . $id);
        api_json(['success' => true, 'message' => '已吊销']);
    }
    api_error('NOT_FOUND', '令牌不存在或已吊销', 404);
}

api_error('METHOD_NOT_ALLOWED', '不支持的请求方法', 405);
