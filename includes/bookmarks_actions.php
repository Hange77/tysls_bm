<?php
/**
 * 书签操作共享逻辑（后台 admin 与用户中心 user 共用）
 *
 * 前后台书签管理页的差异仅在于作用域：
 *   - 后台：系统书签 user_id = 0，调用时传 $isAdmin = true
 *   - 用户：个人书签 user_id = 当前用户，调用时传 $isAdmin = false
 * 本文件把这些"仅作用域不同"的增删改/批量/去重逻辑收敛于此，改一处即两处生效。
 *
 * 约定：所有函数成功时要么返回 null（单删未命中），要么返回
 *       ['message'=>..., 'type'=>...]；保存类函数在成功时直接 redirect() 退出。
 */

/**
 * 单个书签删除（软删除进回收站）
 */
function bm_single_delete($id, $userId, $isAdmin) {
    $bm = DB::fetch(
        "SELECT * FROM " . DB_PREFIX . "bookmarks WHERE id = ? AND user_id = ?",
        [$id, $isAdmin ? 0 : $userId]
    );
    if (!$bm) return null;
    recycle_soft_delete(DB_PREFIX . 'bookmarks', $id, $isAdmin ? 0 : $userId, $isAdmin);
    log_operation('delete', 'bookmark', $id, ($isAdmin ? '删除系统书签: ' : '删除书签: ') . $bm['title']);
    return ['message' => '书签已移入回收站', 'type' => 'success'];
}

/**
 * 批量删除书签
 */
function bm_batch_delete($ids, $userId, $isAdmin) {
    if (!is_array($ids) || empty($ids)) {
        return ['message' => '请先选择要删除的书签', 'type' => 'danger'];
    }
    $deleted = 0;
    foreach ($ids as $rawId) {
        $id = (int)$rawId;
        $bm = DB::fetch(
            "SELECT title FROM " . DB_PREFIX . "bookmarks WHERE id = ? AND user_id = ?",
            [$id, $isAdmin ? 0 : $userId]
        );
        if (!$bm) continue;
        recycle_soft_delete(DB_PREFIX . 'bookmarks', $id, $isAdmin ? 0 : $userId, $isAdmin);
        log_operation('delete', 'bookmark', $id, ($isAdmin ? '批量删除系统书签: ' : '批量删除书签: ') . $bm['title']);
        $deleted++;
    }
    return ['message' => "已将 {$deleted} 个书签移入回收站", 'type' => 'success'];
}

/**
 * 批量移动书签到指定分类
 */
function bm_batch_move($ids, $targetCat, $userId, $isAdmin) {
    if (!is_array($ids) || empty($ids)) {
        return ['message' => '请先选择要移动的书签', 'type' => 'danger'];
    }
    if ($targetCat > 0) {
        $cat = DB::fetch(
            "SELECT id FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = ?",
            [$targetCat, $isAdmin ? 0 : $userId]
        );
        if (!$cat) $targetCat = 0;
    }
    $moved = 0;
    foreach ($ids as $rawId) {
        $id = (int)$rawId;
        $bm = DB::fetch(
            "SELECT id FROM " . DB_PREFIX . "bookmarks WHERE id = ? AND user_id = ?",
            [$id, $isAdmin ? 0 : $userId]
        );
        if (!$bm) continue;
        $where = $isAdmin ? 'id = :id AND user_id = 0' : 'id = :id AND user_id = :uid';
        $wparams = $isAdmin ? [':id' => $id] : [':id' => $id, ':uid' => $userId];
        DB::update(DB_PREFIX . 'bookmarks', ['category_id' => $targetCat], $where, $wparams);
        log_operation('update', 'bookmark', $id, ($isAdmin ? '批量移动系统书签到分类 ' : '批量移动到分类 ') . $targetCat);
        $moved++;
    }
    $catName = $targetCat > 0 ? '指定分类' : '未分类';
    return ['message' => "已移动 {$moved} 个书签到{$catName}", 'type' => 'success'];
}

/**
 * 批量设置公开/私有
 */
function bm_batch_set_public($ids, $publicValue, $userId, $isAdmin) {
    if (!is_array($ids) || empty($ids)) {
        return ['message' => '请先选择要设置的书签', 'type' => 'danger'];
    }
    $updated = 0;
    foreach ($ids as $rawId) {
        $id = (int)$rawId;
        $bm = DB::fetch(
            "SELECT id FROM " . DB_PREFIX . "bookmarks WHERE id = ? AND user_id = ?",
            [$id, $isAdmin ? 0 : $userId]
        );
        if (!$bm) continue;
        $where = $isAdmin ? 'id = :id AND user_id = 0' : 'id = :id AND user_id = :uid';
        $wparams = $isAdmin ? [':id' => $id] : [':id' => $id, ':uid' => $userId];
        DB::update(DB_PREFIX . 'bookmarks', ['is_public' => $publicValue], $where, $wparams);
        $updated++;
    }
    $label = $publicValue ? '公开' : '私有';
    return ['message' => "已将 {$updated} 个书签设为{$label}", 'type' => 'success'];
}

/**
 * 批量设置推荐位（仅后台使用，用户侧不暴露该入口）
 */
function bm_batch_set_featured($ids, $featuredValue, $userId, $isAdmin) {
    if (!is_array($ids) || empty($ids)) {
        return ['message' => '请先选择要设置的书签', 'type' => 'danger'];
    }
    $updated = 0;
    foreach ($ids as $rawId) {
        $id = (int)$rawId;
        $bm = DB::fetch(
            "SELECT id FROM " . DB_PREFIX . "bookmarks WHERE id = ? AND user_id = ?",
            [$id, $isAdmin ? 0 : $userId]
        );
        if (!$bm) continue;
        $where = $isAdmin ? 'id = :id AND user_id = 0' : 'id = :id AND user_id = :uid';
        $wparams = $isAdmin ? [':id' => $id] : [':id' => $id, ':uid' => $userId];
        DB::update(DB_PREFIX . 'bookmarks', ['is_featured' => $featuredValue], $where, $wparams);
        $updated++;
    }
    $label = $featuredValue ? '推荐位' : '普通';
    return ['message' => "已将 {$updated} 个书签设为{$label}", 'type' => 'success'];
}

/**
 * 一键去重（按所选分类范围，URL 完全相同仅保留一个）
 * @param string $defaultScopeLabel 未选择具体分类时的范围文案（后台"网址大全全部书签"/用户"我的全部书签"）
 */
function bm_dedupe($userId, $isAdmin, $catId, $includeChildren, $defaultScopeLabel) {
    $scope = null;
    $scopeLabel = $defaultScopeLabel;
    if ($catId > 0) {
        $scope = [$catId];
        if ($includeChildren) {
            $scope = array_merge($scope, get_category_descendants($catId, $isAdmin ? 0 : $userId));
        }
        $catName = DB::fetch(
            "SELECT name FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = ?",
            [$catId, $isAdmin ? 0 : $userId]
        );
        $scopeLabel = '分类「' . ($catName ? $catName['name'] : $catId) . '」' . ($includeChildren ? '（含子分类）' : '');
    }
    $removed = dedupe_bookmarks($isAdmin ? 0 : $userId, $isAdmin, $scope);
    return $removed > 0
        ? ['message' => "已对【{$scopeLabel}】去重，将 {$removed} 个重复书签移入回收站（可在回收站恢复）", 'type' => 'success']
        : ['message' => "在【{$scopeLabel}】中未发现重复书签", 'type' => 'success'];
}

/**
 * 书签添加/编辑（持久化）
 * 校验不通过时返回 ['message'=>..., 'type'=>...]；通过时直接 redirect() 退出。
 * @param array $opts ['featured'=>是否处理推荐位字段, 'strict_url'=>是否做 URL 严格校验（用户侧开启）]
 */
function bm_save($userId, $isAdmin, $input, $opts = []) {
    $opts = array_merge(['featured' => false, 'strict_url' => false], $opts);

    $id = (int)($input['id'] ?? 0);
    $categoryId = (int)($input['category_id'] ?? 0);
    $title = trim($input['title'] ?? '');
    $url = trim($input['url'] ?? '');
    $description = trim($input['description'] ?? '');
    $icon = trim($input['icon'] ?? '');
    $sort = (int)($input['sort'] ?? 0);
    $isPublic = isset($input['is_public']) ? 1 : 0;

    if (!$title || !$url) {
        return ['message' => '标题和URL不能为空', 'type' => 'danger'];
    }
    // 用户侧允许不带协议的 URL，但做基本格式校验；后台信任管理员输入
    if ($opts['strict_url']) {
        if (!validate_url($url) && !preg_match('/^https?:\/\//i', $url)) {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-\.\/\?\=\&\%\#\+]+$/', $url)) {
                return ['message' => 'URL格式不正确', 'type' => 'danger'];
            }
        }
    }

    // 校验分类归属
    if ($categoryId > 0) {
        $cat = DB::fetch(
            "SELECT id FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = ?",
            [$categoryId, $isAdmin ? 0 : $userId]
        );
        if (!$cat) $categoryId = 0;
    }

    $data = [
        'category_id' => $categoryId,
        'title' => $title,
        'url' => $url,
        'description' => $description,
        'icon' => $icon,
        'sort' => $sort,
        'is_public' => $isPublic,
        'domain' => extract_domain($url),
    ];
    if ($opts['featured']) {
        $isFeatured = isset($input['is_featured']) ? 1 : 0;
        $featuredColor = trim($input['featured_color'] ?? '');
        if ($featuredColor !== '' && !preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $featuredColor)) {
            $featuredColor = ''; // 非法颜色值置空，避免脏数据
        }
        $data['is_featured'] = $isFeatured;
        $data['featured_color'] = $featuredColor;
    }

    if ($id > 0) {
        $where = $isAdmin ? 'id = :id AND user_id = 0' : 'id = :id AND user_id = :uid';
        $wparams = $isAdmin ? [':id' => $id] : [':id' => $id, ':uid' => $userId];
        DB::update(DB_PREFIX . 'bookmarks', $data, $where, $wparams);
        log_operation('update', 'bookmark', $id, ($isAdmin ? '编辑系统书签: ' : '编辑书签: ') . $title);
        $msg = '书签已更新';
    } else {
        $data['user_id'] = $isAdmin ? 0 : $userId;
        $newId = DB::insert(DB_PREFIX . 'bookmarks', $data);
        log_operation('create', 'bookmark', $newId, ($isAdmin ? '添加系统书签: ' : '添加书签: ') . $title);
        $msg = '书签已添加';
    }

    $redirect = $isAdmin ? url('admin/bookmarks.php') : url('user/bookmarks.php');
    redirect($redirect);
}
