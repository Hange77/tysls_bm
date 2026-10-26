<?php
/**
 * 分类操作共享逻辑（后台 admin 与用户中心 user 共用）
 *
 * 前后台分类管理页的差异仅在于作用域：
 *   - 后台：系统分类 user_id = 0，调用时传 $isAdmin = true
 *   - 用户：个人分类 user_id = 当前用户，调用时传 $isAdmin = false
 * 此外用户侧保存时支持"访问密码"字段（后台无）。
 * 本文件把"仅作用域不同"的增删改/批量逻辑收敛于此，改一处即两处生效。
 */

/**
 * 单个分类删除（级联软删除子分类及其下书签/页面）
 */
function cat_single_delete($id, $userId, $isAdmin) {
    $cat = DB::fetch(
        "SELECT * FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
        [$id, $isAdmin ? 0 : $userId]
    );
    if (!$cat) return null;
    recycle_soft_delete_category($id, $isAdmin ? 0 : $userId, $isAdmin);
    log_operation('delete', 'category', $id, ($isAdmin ? '删除系统分类(级联): ' : '删除分类(级联): ') . $cat['name']);
    return ['message' => $isAdmin
        ? '分类已移入回收站（含子分类及其下书签/页面）'
        : '分类已移入回收站（含子分类及其下书签/便签）', 'type' => 'success'];
}

/**
 * 批量删除分类（级联）
 */
function cat_batch_delete($ids, $userId, $isAdmin) {
    if (!is_array($ids) || empty($ids)) {
        return ['message' => '请先选择要删除的分类', 'type' => 'danger'];
    }
    $deleted = 0;
    foreach ($ids as $rawId) {
        $id = (int)$rawId;
        $cat = DB::fetch(
            "SELECT * FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = ? AND deleted_at IS NULL",
            [$id, $isAdmin ? 0 : $userId]
        );
        if (!$cat) continue;
        recycle_soft_delete_category($id, $isAdmin ? 0 : $userId, $isAdmin);
        log_operation('delete', 'category', $id, ($isAdmin ? '批量删除系统分类(级联): ' : '批量删除分类(级联): ') . $cat['name']);
        $deleted++;
    }
    return ['message' => "已将 {$deleted} 个分类移入回收站（含子分类及其下书签/" . ($isAdmin ? '页面' : '便签') . "）", 'type' => 'success'];
}

/**
 * 批量移动分类到指定父分类（自动跳过"自己或自己的子分类"作为目标）
 */
function cat_batch_move($ids, $targetParent, $userId, $isAdmin) {
    if (!is_array($ids) || empty($ids)) {
        return ['message' => '请先选择要移动的分类', 'type' => 'danger'];
    }
    $moved = 0;
    $skipped = 0;
    foreach ($ids as $rawId) {
        $id = (int)$rawId;
        if ($targetParent > 0 && ($targetParent === $id || in_array($targetParent, get_category_descendants($id, $isAdmin ? 0 : $userId)))) {
            $skipped++;
            continue;
        }
        $cat = DB::fetch(
            "SELECT id FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = ?",
            [$id, $isAdmin ? 0 : $userId]
        );
        if (!$cat) continue;
        $where = $isAdmin ? 'id = :id AND user_id = 0' : 'id = :id AND user_id = :uid';
        $wparams = $isAdmin ? [':id' => $id] : [':id' => $id, ':uid' => $userId];
        DB::update(DB_PREFIX . 'categories', ['parent_id' => $targetParent], $where, $wparams);
        log_operation('update', 'category', $id, ($isAdmin ? '批量移动系统分类到 parent_id=' : '批量移动分类到 parent_id=') . $targetParent);
        $moved++;
    }
    return ['message' => "已移动 {$moved} 个分类" . ($skipped > 0 ? "，{$skipped} 个因目标无效被跳过" : ''), 'type' => 'success'];
}

/**
 * 分类添加/编辑（持久化）
 * 校验不通过时返回 ['message'=>..., 'type'=>...]；通过时直接 redirect() 退出。
 * @param array $opts ['password'=>true 时处理访问密码字段（用户侧）]
 *
 * 注：原始逻辑中"父分类不能选择自己或子分类"仅将 parent_id 回退为 0 后继续保存，
 *     且后续成功消息会覆盖该提示，故此处同样回退 parent_id 并正常保存即可，不额外报错。
 */
function cat_save($userId, $isAdmin, $input, $opts = []) {
    $opts = array_merge(['password' => false], $opts);

    $id = (int)($input['id'] ?? 0);
    $parentId = (int)($input['parent_id'] ?? 0);
    $name = trim($input['name'] ?? '');
    $description = trim($input['description'] ?? '');
    $icon = trim($input['icon'] ?? '');
    $sort = (int)($input['sort'] ?? 0);
    $isPublic = isset($input['is_public']) ? 1 : 0;

    if (!$name) {
        return ['message' => '分类名称不能为空', 'type' => 'danger'];
    }
    // 父分类不能是自己或自己的子分类：回退为顶级后继续保存
    if ($id > 0 && $parentId > 0) {
        $descendants = get_category_descendants($id, $isAdmin ? 0 : $userId);
        if (in_array($parentId, $descendants) || $parentId === $id) {
            $parentId = 0;
        }
    }

    $data = [
        'parent_id' => $parentId,
        'name' => $name,
        'description' => $description,
        'icon' => $icon,
        'sort' => $sort,
        'is_public' => $isPublic,
    ];
    if ($opts['password'] && !empty($input['password'])) {
        $data['password'] = password_hash($input['password'], PASSWORD_DEFAULT);
    }

    if ($id > 0) {
        $where = $isAdmin ? 'id = :id AND user_id = 0' : 'id = :id AND user_id = :uid';
        $wparams = $isAdmin ? [':id' => $id] : [':id' => $id, ':uid' => $userId];
        DB::update(DB_PREFIX . 'categories', $data, $where, $wparams);
        log_operation('update', 'category', $id, ($isAdmin ? '编辑系统分类: ' : '编辑分类: ') . $name);
        $msg = '分类已更新';
    } else {
        $data['user_id'] = $isAdmin ? 0 : $userId;
        $newId = DB::insert(DB_PREFIX . 'categories', $data);
        log_operation('create', 'category', $newId, ($isAdmin ? '添加系统分类: ' : '添加分类: ') . $name);
        $msg = '分类已添加';
    }

    $redirect = $isAdmin ? url('admin/categories.php') : url('user/categories.php');
    redirect($redirect);
}
