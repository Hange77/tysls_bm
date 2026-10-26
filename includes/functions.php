<?php
/**
 * 通用辅助函数（加载器）
 *
 * 原 functions.php（单一百宝箱）已按内聚域拆分为 includes/lib/*.php。
 * 本文件仅负责统一加载，对外接口保持不变：
 *   - Web 端 includes/init.php
 *   - API 端 includes/api_init.php
 * 均只 require 本文件，无需改动；各 lib 文件之间无加载顺序依赖（皆为函数定义）。
 */

foreach ([
    'util',
    'category',
    'recycle',
    'theme',
    'io',
    'search',
    'ads',
    'rewrite',
    'security',
    'settings',
] as $__bm_lib) {
    require_once __DIR__ . '/lib/' . $__bm_lib . '.php';
}
