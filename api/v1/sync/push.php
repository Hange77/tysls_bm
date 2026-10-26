<?php
/**
 * 执行 browser -> system 推送（破坏性方向）
 * POST /api/v1/sync/push
 * body: {diff_token, password}
 * 安全要求：必须携带 preview 产出的 diff_token（一次性、10 分钟有效）+ 重新输入的账号密码。
 */
require_once __DIR__ . '/../../../includes/api_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('METHOD_NOT_ALLOWED', '请使用 POST 请求', 405);
}
$user = api_require_auth();

$body = api_body();
$diffToken = trim((string)($body['diff_token'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($diffToken === '') {
    api_error('VALIDATION', '缺少 diff_token，请先调用 /api/v1/sync/preview', 422);
}

// 取出预览快照（一次性消费）
$payload = api_consume_preview($diffToken);
if (!$payload || ($payload['categories'] ?? null) === null) {
    api_error('DIFF_EXPIRED', 'diff_token 已失效或已被使用，请重新预览', 409);
}

$prefs = api_get_sync_prefs($user['id']);

// 用预览快照重算差异（确保 preview == applied）
$diff = compute_sync_diff($user['id'], $payload['categories'], $payload['bookmarks'], $prefs);

// 仅当本次推送「含删除操作」时才要求密码复核（破坏性方向二次确认）。
// 纯新增/更新可自动执行（自动同步无需密码），与「密码仅保护破坏方向」的安全设计一致。
$hasDeletes = !empty($diff['deletes']);
if ($hasDeletes) {
    if ($password === '') {
        api_error('NEED_PASSWORD', '本次推送含 ' . count($diff['deletes']) . ' 项删除操作，需输入账号密码确认', 422);
    }
    if (!safe_password_verify($password, $user['password'])) {
        api_error('FORBIDDEN', '密码错误，推送已取消', 403);
    }
}

try {
    $applied = apply_sync_diff($user['id'], $diff, $prefs);
} catch (Exception $e) {
    api_error('SERVER_ERROR', '推送失败：' . $e->getMessage(), 500);
}

$conflicts = $diff['summary']['conflict'];
log_operation('sync_push', 'user', $user['id'], [
    'applied' => $applied,
    'conflicts' => $conflicts,
]);

api_json([
    'success'            => true,
    'applied'            => $applied,
    'conflicts_resolved' => $conflicts['cat'] + $conflicts['bm'],
    'new_baseline'       => date('Y-m-d H:i:s'),
]);
