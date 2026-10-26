<?php
/**
 * 通用辅助函数 - settings
 *
 * 系统设置：setting() / load_settings() / save_setting() 读写与缓存。
 */

/**
 * 加载所有设置
 * @param bool $force 是否强制刷新缓存（保存设置后调用）
 */
function load_settings($force = false) {
    static $cache = null;
    if ($cache !== null && !$force) return $cache;
    $cache = [];
    try {
        $rows = DB::query("SELECT skey, svalue FROM " . DB_PREFIX . "settings")->fetchAll();
        foreach ($rows as $row) {
            $cache[$row['skey']] = $row['svalue'];
        }
    } catch (Exception $e) {
        // 表可能不存在(安装中)
    }
    return $cache;
}

/**
 * 写入/更新单个系统设置（UPSERT）
 */
function save_setting($key, $value) {
    $exists = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "settings WHERE skey = ?", [$key]);
    if ($exists) {
        DB::update(DB_PREFIX . 'settings', ['svalue' => $value], 'skey = :k', [':k' => $key]);
    } else {
        DB::insert(DB_PREFIX . 'settings', ['skey' => $key, 'svalue' => $value]);
    }
}

// ========== 系统设置：setting() / load_settings() ==========
// 说明：这两个函数原先定义在 includes/init.php，但 API 引导文件
// includes/api_init.php 不会加载 init.php（避免 session/HTML/安装跳转副作用），
// 导致其调用的 load_settings() 与 sync.php 调用的 setting() 在 API 上下文中未定义，
// 触发致命错误。故迁移到 functions.php，web 端与 API 端共用。

/**
 * 读取单个系统设置
 */
function setting($key, $default = '') {
    return isset($GLOBALS['settings'][$key]) ? $GLOBALS['settings'][$key] : $default;
}

