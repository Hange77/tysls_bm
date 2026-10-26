<?php
/**
 * 计算 browser -> system 差异（不落库，仅预览）
 * POST /api/v1/sync/preview
 * body: {categories:[{ext_id,parent_ext_id,name,updated_at?,deleted?}], bookmarks:[{ext_id,category_ext_id,title,url,description?,icon?,updated_at?,deleted?}]}
 * 返回 diff_token（10 分钟内有效、一次性）+ 差异摘要 + 风险提示
 */
require_once __DIR__ . '/../../../includes/api_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    api_error('METHOD_NOT_ALLOWED', '请使用 POST 请求', 405);
}
$user = api_require_auth();

$body = api_body();
$browserCats = isset($body['categories']) && is_array($body['categories']) ? $body['categories'] : [];
$browserBms = isset($body['bookmarks']) && is_array($body['bookmarks']) ? $body['bookmarks'] : [];

$prefs = api_get_sync_prefs($user['id']);

$diff = compute_sync_diff($user['id'], $browserCats, $browserBms, $prefs);
$summary = $diff['summary'];

// 风险提示：删除量过大时强制提醒（push 本就要求密码复核，此处仅提示）
$totalDelete = $summary['delete']['cat'] + $summary['delete']['bm'];
$totalItems = $summary['add']['cat'] + $summary['add']['bm'] + $summary['update']['cat'] + $summary['update']['bm'] + $totalDelete;
$risk = false;
$riskReason = '';
if ($totalDelete > 0 && ($totalItems > 0 && ($totalDelete / max($totalItems, 1)) > 0.2 || $totalDelete > 50)) {
    $risk = true;
    $riskReason = '本次将删除 ' . $totalDelete . ' 项，超过安全阈值，请确认无误后再推送';
}

// 存储客户端快照，供 push 取出重算并应用
$diffToken = api_store_preview($user['id'], [
    'categories' => $browserCats,
    'bookmarks'  => $browserBms,
]);

api_json([
    'diff_token' => $diffToken,
    'summary'    => $summary,
    'risk'       => $risk,
    'risk_reason'=> $riskReason,
    'note'       => '预览可能已建立「浏览器书签」根分类；实际写入需携带本 diff_token 与账号密码调用 /api/v1/sync/push',
]);
