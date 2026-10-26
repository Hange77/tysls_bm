<?php
/**
 * 通用辅助函数 - rewrite
 *
 * 伪静态链接生成：页面 / 分类链接与开关判断。
 */

/**
 * 生成分类链接
 * 伪静态开启时：有slug用slug，无slug用ID（/category/xxx 或 /category/123）
 * 伪静态关闭时：始终用动态地址（category.php?id=123）
 * @param array $category 分类数据
 * @return string 链接URL
 */
function category_url($category) {
    if (is_rewrite_enabled()) {
        if (!empty($category['slug']) && (int)$category['user_id'] === 0) {
            return url('category/' . $category['slug']);
        }
        return url('category/' . (int)$category['id']);
    }
    return url('category.php?id=' . (int)$category['id']);
}

// ========== v1.3.8 伪静态链接函数 ==========
/**
 * 判断是否开启伪静态
 * @return bool
 */
function is_rewrite_enabled() {
    return setting('rewrite_enabled', '0') === '1';
}

/**
 * 生成页面/便签链接
 * 伪静态开启时：有slug用slug，无slug用ID（/page/xxx 或 /page/123）
 * 伪静态关闭时：始终用动态地址（page.php?id=123）
 * @param array $page 页面数据
 * @return string 链接URL
 */
function page_url($page) {
    if (is_rewrite_enabled()) {
        if (!empty($page['slug']) && (int)$page['user_id'] === 0) {
            return url('page/' . $page['slug']);
        }
        return url('page/' . (int)$page['id']);
    }
    return url('page.php?id=' . (int)$page['id']);
}

