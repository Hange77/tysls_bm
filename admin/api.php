<?php
/**
 * 后台管理 - AJAX API
 * 处理分类/书签/页面的行内编辑等异步操作
 */
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');

// AJAX 入口: 认证/权限失败必须返回 JSON, 不能 HTML 跳转, 否则前端 r.json() 会抛异常
if (!Auth::check()) {
    json_response(['success' => false, 'message' => '会话已失效, 请重新登录'], 401);
}
if (!Auth::isAdmin()) {
    json_response(['success' => false, 'message' => '需要管理员权限'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'message' => '无效的请求方法'], 405);
}
$action = $_POST['action'] ?? '';
// CSRF 验证(AJAX请求用JSON返回错误)
if (!CSRF::verify()) {
    json_response(['success' => false, 'message' => '页面已过期,请刷新后重试'], 419);
}
switch ($action) {
    case 'update_category_field':
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $allowedFields = ['name', 'icon', 'sort', 'is_public', 'password'];
        if ($id <= 0 || !in_array($field, $allowedFields, true)) {
            json_response(['success' => false, 'message' => '无效的参数']);
        }
        $cat = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = 0", [$id]);
        if (!$cat) {
            json_response(['success' => false, 'message' => '分类不存在']);
        }
        $data = [];
        if ($field === 'password') {
            $data['password'] = $value !== '' ? password_hash($value, PASSWORD_DEFAULT) : '';
        } elseif ($field === 'is_public') {
            $data['is_public'] = ($value == 1 || $value === 'true' || $value === '1') ? 1 : 0;
        } elseif ($field === 'sort') {
            $data['sort'] = (int)$value;
        } else {
            $data[$field] = trim($value);
        }
        DB::update(DB_PREFIX . 'categories', $data, 'id = :id AND user_id = 0', [':id' => $id]);
        log_operation('update', 'category', $id, '后台行内编辑分类字段: ' . $field);
        $result = ['success' => true, 'data' => []];
        if ($field === 'password') {
            $result['data']['has_password'] = !empty($data['password']);
        } elseif ($field === 'is_public') {
            $result['data']['is_public'] = (int)$data['is_public'];
        } else {
            $result['data'][$field] = $data[$field];
        }
        json_response($result);
        break;

    case 'update_bookmark_field':
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = $_POST['value'] ?? '';
        $allowedFields = ['title', 'url', 'sort', 'is_public', 'is_featured'];
        if ($id <= 0 || !in_array($field, $allowedFields, true)) {
            json_response(['success' => false, 'message' => '无效的参数']);
        }
        $bm = DB::fetch("SELECT * FROM " . DB_PREFIX . "bookmarks WHERE id = ? AND user_id = 0", [$id]);
        if (!$bm) {
            json_response(['success' => false, 'message' => '书签不存在']);
        }
        $data = [];
        if ($field === 'sort') {
            $data['sort'] = (int)$value;
        } elseif ($field === 'url') {
            $scheme = parse_url($value, PHP_URL_SCHEME);
            if (!in_array($scheme, ['http', 'https'], true)) {
                json_response(['success' => false, 'message' => 'URL 必须以 http:// 或 https:// 开头']);
            }
            $data['url'] = trim($value);
            $data['domain'] = extract_domain($value);
        } elseif ($field === 'is_public') {
            $data['is_public'] = ($value == 1 || $value === 'true' || $value === '1') ? 1 : 0;
        } elseif ($field === 'is_featured') {
            $data['is_featured'] = ($value == 1 || $value === 'true' || $value === '1') ? 1 : 0;
        } else { // title
            $val = trim($value);
            if ($val === '') {
                json_response(['success' => false, 'message' => '标题不能为空']);
            }
            $data['title'] = $val;
        }
        DB::update(DB_PREFIX . 'bookmarks', $data, 'id = :id AND user_id = 0', [':id' => $id]);
        log_operation('update', 'bookmark', $id, '后台行内编辑书签字段: ' . $field);
        $result = ['success' => true, 'data' => [$field => $data[$field]]];
        json_response($result);
        break;

    case 'update_bookmark_order':
        // 拖拽排序：把某个分类下的书签按传入顺序重编 sort = 1..N
        // 仅在 category_id + user_id = 0（系统书签）范围内生效，绝不影响其它分类/系统
        $catId = (int)($_POST['cat_id'] ?? -1);
        $ids = $_POST['ids'] ?? [];
        if ($catId < 0 || !is_array($ids) || empty($ids)) {
            json_response(['success' => false, 'message' => '参数无效']);
        }
        $idList = array_values(array_filter(array_map('intval', $ids), function ($x) { return $x > 0; }));
        if (empty($idList)) {
            json_response(['success' => false, 'message' => '参数无效']);
        }
        $ph = implode(',', array_fill(0, count($idList), '?'));
        $rows = DB::fetchAll(
            "SELECT id FROM " . DB_PREFIX . "bookmarks WHERE id IN ({$ph}) AND category_id = ? AND user_id = 0 AND deleted_at IS NULL",
            array_merge($idList, [$catId])
        );
        // 仅保留属于该分类的书签（防越权/误改），并保持「传入(拖拽)顺序」重排
        $validSet = array_flip(array_column($rows, 'id'));
        $i = 1;
        foreach ($idList as $bid) {
            if (!isset($validSet[$bid])) {
                continue;
            }
            DB::update(DB_PREFIX . 'bookmarks', ['sort' => $i], 'id = ? AND user_id = 0', [$bid]);
            $i++;
        }
        log_operation('update', 'bookmark', $catId, '后台拖拽排序分类书签');
        json_response(['success' => true, 'data' => ['count' => count($validSet)]]);
        break;

    default:
        json_response(['success' => false, 'message' => '未知的操作'], 400);
}
