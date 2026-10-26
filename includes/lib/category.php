<?php
/**
 * 通用辅助函数 - category
 *
 * 分类相关：后代分类获取 / 分类树构建 / 分类下拉选项渲染。
 */

/**
 * 构建分类树
 */
function build_category_tree($categories, $parentId = 0) {
    $tree = [];
    foreach ($categories as $cat) {
        if ((int)$cat['parent_id'] === $parentId) {
            $cat['children'] = build_category_tree($categories, (int)$cat['id']);
            $tree[] = $cat;
        }
    }
    return $tree;
}

/**
 * 获取分类的所有子分类ID(用于递归删除/查询)
 */
function get_category_descendants($categoryId, $userId = null) {
    $ids = [];
    $children = DB::fetchAll(
        "SELECT id FROM " . DB_PREFIX . "categories WHERE parent_id = ?" . ($userId !== null ? " AND user_id = ?" : ""),
        $userId !== null ? [$categoryId, $userId] : [$categoryId]
    );
    foreach ($children as $child) {
        $ids[] = (int)$child['id'];
        $ids = array_merge($ids, get_category_descendants($child['id'], $userId));
    }
    return $ids;
}

/**
 * 渲染分类树为选项(select用)
 */
function render_category_options($tree, $selectedId = 0, $level = 0, $excludeId = 0) {
    $html = '';
    foreach ($tree as $cat) {
        if ((int)$cat['id'] === (int)$excludeId) continue;
        $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
        $selected = ((int)$cat['id'] === (int)$selectedId) ? ' selected' : '';
        $html .= '<option value="' . (int)$cat['id'] . '"' . $selected . '>' . $indent . e($cat['name']) . '</option>';
        $html .= render_category_options($cat['children'], $selectedId, $level + 1, $excludeId);
    }
    return $html;
}

