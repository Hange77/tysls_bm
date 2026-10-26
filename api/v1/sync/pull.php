<?php
/**
 * 拉取系统侧增量（system -> browser）
 * GET /api/v1/sync/pull?since=YYYY-MM-DD HH:MM:SS
 * 返回浏览器书签子树内自 since 以来变更（含软删除）的分类与书签。
 */
require_once __DIR__ . '/../../../includes/api_init.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    api_error('METHOD_NOT_ALLOWED', '请使用 GET 请求', 405);
}
$user = api_require_auth();

$prefs = api_get_sync_prefs($user['id']);

// 回收站清理（顺带执行，超过保留期的软删除记录硬删除）
api_purge_trash($user['id'], $prefs['trash_days']);

$root = api_get_browser_root($user['id']);
$now = date('Y-m-d H:i:s');

if (!$root) {
    // 尚未建立浏览器书签根分类：返回空基线
    api_json([
        'server_time' => $now,
        'baseline'    => $now,
        'categories'  => [],
        'bookmarks'   => [],
    ]);
}

$subtree = api_get_subtree_ids($user['id'], (int)$root['id']);
$inClause = implode(',', $subtree);

$sinceRaw = trim((string)($_GET['since'] ?? ''));
$since = $sinceRaw === '' ? '1970-01-01 00:00:00' : date('Y-m-d H:i:s', strtotime($sinceRaw) ?: 0);

$catRows = DB::fetchAll(
    "SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = ? AND id IN ({$inClause}) AND (updated_at > ? OR deleted_at > ?) ORDER BY updated_at ASC",
    [$user['id'], $since, $since]
);
$bmRows = DB::fetchAll(
    "SELECT * FROM " . DB_PREFIX . "bookmarks WHERE user_id = ? AND category_id IN ({$inClause}) AND (updated_at > ? OR deleted_at > ?) ORDER BY updated_at ASC",
    [$user['id'], $since, $since]
);

$categories = array_map(function ($c) {
    return [
        'id'         => (int)$c['id'],
        'parent_id'  => (int)$c['parent_id'],
        'name'       => $c['name'],
        'slug'       => $c['slug'],
        'icon'       => $c['icon'],
        'sort'       => (int)$c['sort'],
        'is_public'  => (int)$c['is_public'],
        'ext_id'     => $c['ext_id'],
        'source'     => $c['source'],
        'deleted_at' => $c['deleted_at'],
        'updated_at' => $c['updated_at'],
    ];
}, $catRows);

$bookmarks = array_map(function ($b) {
    return [
        'id'         => (int)$b['id'],
        'category_id'=> (int)$b['category_id'],
        'title'      => $b['title'],
        'url'        => $b['url'],
        'description'=> $b['description'],
        'icon'       => $b['icon'],
        'sort'       => (int)$b['sort'],
        'is_public'  => (int)$b['is_public'],
        'ext_id'     => $b['ext_id'],
        'source'     => $b['source'],
        'domain'     => $b['domain'],
        'deleted_at' => $b['deleted_at'],
        'updated_at' => $b['updated_at'],
    ];
}, $bmRows);

api_json([
    'server_time' => $now,
    'baseline'    => $now,
    'categories'  => $categories,
    'bookmarks'   => $bookmarks,
]);
