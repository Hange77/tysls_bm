<?php
/**
 * 通用辅助函数 - recycle
 *
 * 回收站（软删除 / 恢复 / 彻底删除 / 自动清理）与书签一键去重。
 */

/**
 * 书签一键去重（标题 + 网址 完全一致，跨分类）。
 * 仅在当前作用域内、未软删除(deleted_at IS NULL)的书签中查找重复：
 *   按 TRIM(title) + url 分组，找出 COUNT > 1 的组，每组仅保留 1 个、其余软删除(进回收站，可恢复)。
 * 保留策略：同组中优先保留浏览器同步锚定项(ext_id 非空)，否则保留最早创建(id 最小)。
 * $isAdmin=true 作用于系统书签(user_id=0)；否则作用于指定 $userId 的个人书签。
 * 返回实际移除的重复书签数量。
 */
function dedupe_bookmarks($userId, $isAdmin, $categoryIds = null) {
    $prefix = DB_PREFIX;
    $whereUser = $isAdmin ? "user_id = 0" : "user_id = " . (int)$userId;

    // 分类范围过滤：仅对指定分类(及其子分类)内的书签去重；null 表示跨所有分类
    $scopeSql = '';
    $scopeParams = [];
    if (is_array($categoryIds) && !empty($categoryIds)) {
        $catIds = array_map('intval', $categoryIds);
        $placeholders = rtrim(str_repeat('?,', count($catIds)), ',');
        $scopeSql = " AND category_id IN ({$placeholders})";
        $scopeParams = $catIds;
    }

    // 找出在所选范围内 url 完全一致且数量 > 1 的组；仅按网址去重
    $groups = DB::fetchAll(
        "SELECT url AS u, COUNT(*) AS cnt
         FROM {$prefix}bookmarks
         WHERE {$whereUser} AND deleted_at IS NULL{$scopeSql}
         GROUP BY url
         HAVING COUNT(*) > 1",
        $scopeParams
    );
    if (empty($groups)) return 0;

    $removed = 0;
    foreach ($groups as $g) {
        // 组内保留哪一个：优先保留浏览器同步锚定项(ext_id 非空，避免同步复活)，否则保留最新创建(id 最大)
        $keep = DB::fetch(
            "SELECT id FROM {$prefix}bookmarks
             WHERE {$whereUser} AND deleted_at IS NULL{$scopeSql} AND url = ?
             ORDER BY CASE WHEN ext_id IS NOT NULL AND ext_id <> '' THEN 0 ELSE 1 END ASC, id DESC
             LIMIT 1",
            array_merge($scopeParams, [$g['u']])
        );
        if (!$keep) continue;
        $keepId = (int)$keep['id'];
        $dups = DB::fetchAll(
            "SELECT id FROM {$prefix}bookmarks
             WHERE {$whereUser} AND deleted_at IS NULL{$scopeSql} AND url = ? AND id <> ?",
            array_merge($scopeParams, [$g['u'], $keepId])
        );
        foreach ($dups as $d) {
            recycle_soft_delete($prefix . 'bookmarks', (int)$d['id'], $userId, $isAdmin);
            $removed++;
        }
    }
    return $removed;
}

/**
 * 彻底删除（硬删）
 * type ∈ {bookmark, category, page}。category 时先删其子内容，再删分类本身。
 */
function recycle_permanent_delete($type, $id, $userId, $isAdmin) {
    $tableMap = ['bookmark' => DB_PREFIX . 'bookmarks', 'category' => DB_PREFIX . 'categories', 'page' => DB_PREFIX . 'pages'];
    $table = $tableMap[$type] ?? null;
    if (!$table) return;
    $extra = $isAdmin ? '' : ' AND user_id = ?';
    $params = $isAdmin ? [] : [$userId];
    if ($type === 'category') {
        $catIds = get_category_descendants($id, $isAdmin ? 0 : $userId);
        $catIds[] = (int)$id;
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        $cparams = array_merge($catIds, $params);
        DB::delete(DB_PREFIX . 'bookmarks', "category_id IN ({$ph}){$extra}", $cparams);
        DB::delete(DB_PREFIX . 'pages', "category_id IN ({$ph}){$extra}", $cparams);
        DB::delete(DB_PREFIX . 'categories', "id IN ({$ph}){$extra}", $cparams);
    } else {
        DB::delete($table, "id = ?{$extra}", array_merge([$id], $params));
    }
}

/**
 * 自动清理过期回收站内容（按 setting recycle_retention_days，默认30，0=永久）
 * user 侧传 $userId + $isAdmin=false；admin 传 $isAdmin=true（清理全站）。
 */
function recycle_purge($userId = null, $isAdmin = false) {
    $retention = (int)setting('recycle_retention_days', '30');
    if ($retention <= 0) return; // 0 = 永久保留
    $cutoff = date('Y-m-d H:i:s', time() - $retention * 86400);
    $cond = 'deleted_at IS NOT NULL AND deleted_at < ?';
    $params = [$cutoff];
    if (!$isAdmin && $userId !== null) {
        $cond .= ' AND user_id = ?';
        $params[] = $userId;
    }
    foreach ([DB_PREFIX . 'bookmarks', DB_PREFIX . 'categories', DB_PREFIX . 'pages'] as $t) {
        DB::delete($t, $cond, $params);
    }
}

/**
 * 恢复（软删除 -> NULL）
 * type ∈ {bookmark, category, page}。category 时连同其子分类及子内容（仅当前处于软删除的）一并恢复。
 */
function recycle_restore($type, $id, $userId, $isAdmin) {
    $tableMap = ['bookmark' => DB_PREFIX . 'bookmarks', 'category' => DB_PREFIX . 'categories', 'page' => DB_PREFIX . 'pages'];
    $table = $tableMap[$type] ?? null;
    if (!$table) return;
    $extra = $isAdmin ? '' : ' AND user_id = ?';
    $params = $isAdmin ? [] : [$userId];
    if ($type === 'category') {
        $catIds = get_category_descendants($id, $isAdmin ? 0 : $userId);
        $catIds[] = (int)$id;
        $ph = implode(',', array_fill(0, count($catIds), '?'));
        $cparams = array_merge($catIds, $params);
        DB::execute("UPDATE `" . DB_PREFIX . "categories` SET deleted_at = NULL WHERE id IN ({$ph}) AND deleted_at IS NOT NULL{$extra}", $cparams);
        DB::execute("UPDATE `" . DB_PREFIX . "bookmarks` SET deleted_at = NULL WHERE category_id IN ({$ph}) AND deleted_at IS NOT NULL{$extra}", $cparams);
        DB::execute("UPDATE `" . DB_PREFIX . "pages` SET deleted_at = NULL WHERE category_id IN ({$ph}) AND deleted_at IS NOT NULL{$extra}", $cparams);
    } else {
        DB::execute("UPDATE `{$table}` SET deleted_at = NULL WHERE id = ? AND deleted_at IS NOT NULL{$extra}", array_merge([$id], $params));
    }
}

// ========== v1.4.0.4 回收站（软删除 / 恢复 / 彻底删除 / 自动清理） ==========
// 约定：
//  - user 侧调用时传 $isAdmin=false，软删除/恢复/彻底删除均按 user_id 限定（仅本人数据）。
//  - admin 侧调用时传 $isAdmin=true，系统内容 user_id=0，不限定 user_id（全站范围）。
//  - 所有软删除均置 deleted_at = NOW()；恢复置 NULL；彻底删除为真删（DB::delete）。

/**
 * 单条记录软删除（书签 / 页面 / 笔记）
 * @param string $table   已带前缀的表名，如 DB_PREFIX.'bookmarks'
 */
function recycle_soft_delete($table, $id, $userId, $isAdmin) {
    if ($isAdmin) {
        DB::execute("UPDATE `{$table}` SET deleted_at = NOW() WHERE id = ?", [$id]);
    } else {
        DB::execute("UPDATE `{$table}` SET deleted_at = NOW() WHERE id = ? AND user_id = ?", [$id, $userId]);
    }
}

/**
 * 分类软删除（级联：该分类及其所有子分类、以及这些分类下的书签与页面一并软删除）
 */
function recycle_soft_delete_category($catId, $userId, $isAdmin) {
    $catIds = get_category_descendants($catId, $isAdmin ? 0 : $userId);
    $catIds[] = (int)$catId;
    if (empty($catIds)) return;
    $placeholders = implode(',', array_fill(0, count($catIds), '?'));
    $extra = $isAdmin ? '' : ' AND user_id = ?';
    $params = $catIds;
    if (!$isAdmin) $params[] = $userId;
    DB::execute("UPDATE `" . DB_PREFIX . "categories` SET deleted_at = NOW() WHERE id IN ({$placeholders}){$extra}", $params);
    DB::execute("UPDATE `" . DB_PREFIX . "bookmarks` SET deleted_at = NOW() WHERE category_id IN ({$placeholders}){$extra}", $params);
    DB::execute("UPDATE `" . DB_PREFIX . "pages` SET deleted_at = NOW() WHERE category_id IN ({$placeholders}){$extra}", $params);
}

