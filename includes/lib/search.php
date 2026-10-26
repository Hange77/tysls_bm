<?php
/**
 * 通用辅助函数 - search
 *
 * 搜索：书签 / 分类 / 页面 / 便签的全文检索。
 */

// ========== v1.2 搜索功能 ==========
function search_bookmarks($keyword, $userId = null, $scope = 'all') {
    $keyword = trim($keyword);
    if ($keyword === '') return ['bookmarks' => [], 'categories' => [], 'pages' => [], 'notes' => []];
    $like = '%' . $keyword . '%';
    $results = ['bookmarks' => [], 'categories' => [], 'pages' => [], 'notes' => []];

    // 搜索书签（排除已软删除）
    $where = "WHERE (b.title LIKE ? OR b.url LIKE ? OR b.description LIKE ?) AND b.deleted_at IS NULL";
    $params = [$like, $like, $like];
    if ($scope === 'my' && $userId) {
        $where .= " AND b.user_id = ?";
        $params[] = $userId;
    } elseif ($scope === 'all') {
        if ($userId) {
            $where .= " AND (b.user_id = 0 OR b.user_id = ?)";
            $params[] = $userId;
        } else {
            $where .= " AND b.user_id = 0";
        }
    }
    $sql = "SELECT b.*, c.name as category_name FROM " . DB_PREFIX . "bookmarks b LEFT JOIN " . DB_PREFIX . "categories c ON b.category_id = c.id {$where} ORDER BY b.click_count DESC, b.id DESC LIMIT 100";
    try {
        $results['bookmarks'] = DB::fetchAll($sql, $params);
    } catch (Exception $e) {}

    // 搜索分类（排除已软删除）
    $catWhere = "WHERE (c.name LIKE ? OR c.description LIKE ?) AND c.deleted_at IS NULL";
    $catParams = [$like, $like];
    if ($scope === 'my' && $userId) {
        $catWhere .= " AND c.user_id = ?";
        $catParams[] = $userId;
    } elseif ($scope === 'all') {
        if ($userId) {
            $catWhere .= " AND (c.user_id = 0 OR c.user_id = ?)";
            $catParams[] = $userId;
        } else {
            $catWhere .= " AND c.user_id = 0";
        }
    }
    $catSql = "SELECT c.* FROM " . DB_PREFIX . "categories c {$catWhere} ORDER BY c.sort ASC, c.id ASC LIMIT 50";
    try {
        $results['categories'] = DB::fetchAll($catSql, $catParams);
    } catch (Exception $e) {}

    // 搜索页面（系统公开 html 页面；scope=my 时含自己的页面）
    $pageWhere = "WHERE p.content_type = 'html' AND p.deleted_at IS NULL AND (p.title LIKE ? OR p.content LIKE ?)";
    $pageParams = [$like, $like];
    if ($scope === 'my' && $userId) {
        $pageWhere .= " AND p.user_id = ?";
        $pageParams[] = $userId;
    } elseif ($scope === 'all') {
        if ($userId) {
            // 登录用户：系统公开页面 + 自己的页面
            $pageWhere .= " AND (p.user_id = 0 AND p.is_public = 1 OR p.user_id = ?)";
            $pageParams[] = $userId;
        } else {
            // 游客：仅系统公开页面
            $pageWhere .= " AND p.user_id = 0 AND p.is_public = 1";
        }
    }
    $pageSql = "SELECT p.* FROM " . DB_PREFIX . "pages p {$pageWhere} ORDER BY p.updated_at DESC, p.id DESC LIMIT 50";
    try {
        $results['pages'] = DB::fetchAll($pageSql, $pageParams);
    } catch (Exception $e) {}

    // 搜索便签（仅登录用户自己的 text 便签，含私有）
    $notes = [];
    if ($userId) {
        $noteSql = "SELECT p.* FROM " . DB_PREFIX . "pages p WHERE p.content_type = 'text' AND p.user_id = ? AND p.deleted_at IS NULL AND (p.title LIKE ? OR p.content LIKE ?) ORDER BY p.updated_at DESC, p.id DESC LIMIT 50";
        try {
            $notes = DB::fetchAll($noteSql, [$userId, $like, $like]);
        } catch (Exception $e) {}
    }
    $results['notes'] = $notes;

    return $results;
}

