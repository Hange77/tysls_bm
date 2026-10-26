<?php
/**
 * 书签跳转 - 记录点击次数后重定向
 */
require_once __DIR__ . '/includes/init.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    show_404('书签不存在');
}

$bookmark = DB::fetch("SELECT * FROM " . DB_PREFIX . "bookmarks WHERE id = ? AND deleted_at IS NULL", [$id]);
if (!$bookmark) {
    show_404('书签不存在');
}

// 检查可见性
$isOwner = Auth::check() && Auth::id() === (int)$bookmark['user_id'];
$isAdmin = Auth::isAdmin();
if (!$bookmark['is_public'] && !$isOwner && !$isAdmin) {
    show_403('该书签为私有');
}

// 检查分类是否公开/有密码
if ($bookmark['category_id']) {
    $category = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE id = ? AND deleted_at IS NULL", [$bookmark['category_id']]);
    if ($category) {
        if (!$category['is_public'] && !$isOwner && !$isAdmin) {
            show_403('该分类为私有');
        }
        // 密码验证
        if ($category['password'] && !$isOwner && !$isAdmin) {
            $sessionKey = 'cat_unlocked_' . $category['id'];
            if (empty($_SESSION[$sessionKey])) {
                redirect(category_url($category));
            }
        }
    }
}

// 记录点击(同会话内同一书签只计一次,防止刷新刷高点击数)
if (empty($_SESSION['clicked_links']) || !is_array($_SESSION['clicked_links'])) {
    $_SESSION['clicked_links'] = [];
}
if (!in_array($id, $_SESSION['clicked_links'], true)) {
    DB::execute("UPDATE " . DB_PREFIX . "bookmarks SET click_count = click_count + 1 WHERE id = ?", [$id]);
    $_SESSION['clicked_links'][] = $id;
    if (count($_SESSION['clicked_links']) > 200) {
        $_SESSION['clicked_links'] = array_slice($_SESSION['clicked_links'], -200);
    }
}

// 重定向(安全校验:只允许http/https,防止header注入和危险协议)
$url = trim($bookmark['url']);
// 去除换行等危险字符,防止header注入
$url = str_replace(["\r", "\n", "\t", ' '], '', $url);
if (!preg_match('/^https?:\/\//i', $url)) {
    $url = 'http://' . $url;
}
// 最终校验必须是http/https
if (!preg_match('/^https?:\/\/[^\s]+$/i', $url)) {
    show_404('无效的书签链接');
}
header('Location: ' . $url);
exit;
