<?php
/**
 * 用户中心 AJAX API 端点
 * 处理行内编辑、批量操作等异步请求
 */
require_once __DIR__ . '/../includes/init.php';

header('Content-Type: application/json; charset=utf-8');

// AJAX 入口: 未登录必须返回 JSON, 不能 HTML 跳转, 否则前端 r.json() 会抛异常
if (!Auth::check()) {
    api_response(false, '会话已失效, 请重新登录');
}
$userId = Auth::id();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/**
 * 统一 JSON 响应
 */
function api_response($success, $message = '', $data = []) {
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== 分类行内编辑 ==========
if ($action === 'update_category_field') {
    CSRF::check();
    $id = (int)($_POST['id'] ?? 0);
    $field = trim($_POST['field'] ?? '');
    $value = $_POST['value'] ?? '';

    $allowedFields = ['name', 'icon', 'sort', 'is_public', 'password'];
    if (!in_array($field, $allowedFields, true)) {
        api_response(false, '不支持的字段');
    }

    $cat = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = ?", [$id, $userId]);
    if (!$cat) {
        api_response(false, '分类不存在');
    }

    $updateData = [];

    switch ($field) {
        case 'name':
            $name = trim($value);
            if ($name === '') {
                api_response(false, '分类名称不能为空');
            }
            $updateData['name'] = $name;
            break;

        case 'icon':
            $updateData['icon'] = trim($value);
            break;

        case 'sort':
            $updateData['sort'] = (int)$value;
            break;

        case 'is_public':
            $updateData['is_public'] = ($value == 1 || $value === 'true' || $value === '1') ? 1 : 0;
            break;

        case 'password':
            $pwd = trim($value);
            if ($pwd === '') {
                // 空字符串表示清除密码
                $updateData['password'] = '';
            } else {
                $updateData['password'] = password_hash($pwd, PASSWORD_DEFAULT);
            }
            break;
    }

    if (!empty($updateData)) {
        DB::update(DB_PREFIX . 'categories', $updateData, 'id = :id AND user_id = :uid', [':id' => $id, ':uid' => $userId]);
        log_operation('update', 'category', $id, '行内编辑: ' . $field);
    }

    // 返回更新后的值用于前端展示
    $fresh = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE id = ?", [$id]);
    api_response(true, '已保存', [
        'id' => (int)$fresh['id'],
        'name' => $fresh['name'],
        'icon' => $fresh['icon'],
        'sort' => (int)$fresh['sort'],
        'is_public' => (int)$fresh['is_public'],
        'has_password' => !empty($fresh['password']),
    ]);
}

// ========== 书签行内编辑 ==========
if ($action === 'update_bookmark_field') {
    CSRF::check();
    $id = (int)($_POST['id'] ?? 0);
    $field = trim($_POST['field'] ?? '');
    $value = $_POST['value'] ?? '';
    $allowedFields = ['title', 'url', 'sort', 'is_public'];
    if (!in_array($field, $allowedFields, true)) {
        api_response(false, '不支持的字段');
    }
    $bm = DB::fetch("SELECT * FROM " . DB_PREFIX . "bookmarks WHERE id = ? AND user_id = ?", [$id, $userId]);
    if (!$bm) {
        api_response(false, '书签不存在');
    }
    $updateData = [];
    if ($field === 'sort') {
        $updateData['sort'] = (int)$value;
    } elseif ($field === 'url') {
        $scheme = parse_url($value, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            api_response(false, 'URL 必须以 http:// 或 https:// 开头');
        }
        $updateData['url'] = trim($value);
        $updateData['domain'] = extract_domain($value);
    } elseif ($field === 'is_public') {
        $updateData['is_public'] = ($value == 1 || $value === 'true' || $value === '1') ? 1 : 0;
    } else { // title
        $title = trim($value);
        if ($title === '') {
            api_response(false, '标题不能为空');
        }
        $updateData['title'] = $title;
    }
    if (!empty($updateData)) {
        DB::update(DB_PREFIX . 'bookmarks', $updateData, 'id = :id AND user_id = :uid', [':id' => $id, ':uid' => $userId]);
        log_operation('update', 'bookmark', $id, '行内编辑: ' . $field);
    }
    $fresh = DB::fetch("SELECT * FROM " . DB_PREFIX . "bookmarks WHERE id = ?", [$id]);
    api_response(true, '已保存', [
        'id' => (int)$fresh['id'],
        'title' => $fresh['title'],
        'url' => $fresh['url'],
        'sort' => (int)$fresh['sort'],
        'is_public' => (int)$fresh['is_public'],
    ]);
}

// ========== 书签拖拽排序 ==========
if ($action === 'update_bookmark_order') {
    CSRF::check();
    // 把某个分类下的书签按传入顺序重编 sort = 1..N
    // 仅在 category_id + 当前用户范围内生效，绝不影响其它分类/系统
    $catId = (int)($_POST['cat_id'] ?? -1);
    $ids = $_POST['ids'] ?? [];
    if ($catId < 0 || !is_array($ids) || empty($ids)) {
        api_response(false, '参数无效');
    }
    $idList = array_values(array_filter(array_map('intval', $ids), function ($x) { return $x > 0; }));
    if (empty($idList)) {
        api_response(false, '参数无效');
    }
    $ph = implode(',', array_fill(0, count($idList), '?'));
    $rows = DB::fetchAll(
        "SELECT id FROM " . DB_PREFIX . "bookmarks WHERE id IN ({$ph}) AND category_id = ? AND user_id = ? AND deleted_at IS NULL",
        array_merge($idList, [$catId, $userId])
    );
    // 仅保留属于该分类的书签（防越权/误改），并保持「传入(拖拽)顺序」重排
    $validSet = array_flip(array_column($rows, 'id'));
    $i = 1;
    foreach ($idList as $bid) {
        if (!isset($validSet[$bid])) {
            continue;
        }
        DB::update(DB_PREFIX . 'bookmarks', ['sort' => $i], 'id = ? AND user_id = ?', [$bid, $userId]);
        $i++;
    }
    log_operation('update', 'bookmark', $catId, '拖拽排序分类书签');
    api_response(true, '已保存', ['count' => count($validSet)]);
}

// ========== 保存界面偏好（主题/深色模式/首页布局） ==========
if ($action === 'save_ui') {
    CSRF::check();
    $theme = trim($_POST['theme'] ?? 'default');
    $dark = trim($_POST['dark_mode'] ?? 'off');
    $layout = trim($_POST['home_layout'] ?? 'default');
    $themes = get_theme_list();
    if (!isset($themes[$theme])) $theme = 'default';
    if (!in_array($dark, ['off', 'on', 'auto'], true)) $dark = 'off';
    if (!in_array($layout, ['default', 'vertical', 'horizontal', 'card'], true)) $layout = 'default';

    try {
        DB::update(DB_PREFIX . 'users', [
            'theme' => $theme,
            'dark_mode' => $dark,
            'home_layout' => $layout,
        ], 'id = :id', [':id' => $userId]);
        Auth::refresh();
        api_response(true, '已保存', ['theme' => $theme, 'dark_mode' => $dark, 'home_layout' => $layout]);
    } catch (Exception $e) {
        // 低版本数据库可能缺少 home_layout 字段，尝试补齐后重试
        try {
            DB::execute("ALTER TABLE " . DB_PREFIX . "users ADD COLUMN `home_layout` varchar(50) NOT NULL DEFAULT 'default' AFTER `dark_mode`");
            DB::update(DB_PREFIX . 'users', [
                'theme' => $theme,
                'dark_mode' => $dark,
                'home_layout' => $layout,
            ], 'id = :id', [':id' => $userId]);
            Auth::refresh();
            api_response(true, '已保存', ['theme' => $theme, 'dark_mode' => $dark, 'home_layout' => $layout]);
        } catch (Exception $e2) {
            api_response(false, '保存失败: ' . $e2->getMessage());
        }
    }
}

api_response(false, '未知操作: ' . $action);
