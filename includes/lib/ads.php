<?php
/**
 * 通用辅助函数 - ads
 *
 * 广告位与友情链接：读取与渲染。
 */

// ========== v1.3.8 广告获取函数 ==========
/**
 * 获取启用的广告（按显示范围和位置过滤）
 * @param string $pageScope 页面范围: 'site'=网址大全页面, 'user'=用户收藏夹页面
 * @param string $position 广告位: 'top'=页头, 'bottom'=页尾
 * @return array 广告列表
 */
function get_ads($pageScope = 'site', $position = 'top') {
    static $allAds = null;
    if ($allAds === null) {
        try {
            $allAds = DB::fetchAll("SELECT * FROM " . DB_PREFIX . "ads WHERE is_active = 1 AND position IN ('top','bottom') ORDER BY sort ASC, id ASC");
        } catch (Exception $e) {
            $allAds = [];
        }
    }
    $result = [];
    foreach ($allAds as $ad) {
        // 显示范围过滤: all=全站, site=仅网址大全, user=仅用户收藏夹
        $scope = $ad['display_scope'] ?? 'all';
        if ($scope !== 'all' && $scope !== $pageScope) continue;
        // 位置过滤
        if ($ad['position'] !== $position) continue;
        $result[] = $ad;
    }
    return $result;
}

/**
 * 获取启用的友情链接（系统级，首页 footer 上方展示）
 * @return array 按 sort 升序
 */
function get_friend_links() {
    try {
        return DB::fetchAll("SELECT * FROM " . DB_PREFIX . "friend_links WHERE is_active = 1 ORDER BY sort ASC, id ASC");
    } catch (Exception $e) {
        return [];
    }
}

/**
 * 输出广告HTML
 * @param array $ads 广告列表
 * @param string $slotClass 广告位CSS类名
 */
function render_ads($ads, $slotClass = '') {
    if (empty($ads)) return '';
    $html = '<div class="ad-slot ' . $slotClass . '">';
    foreach ($ads as $ad) {
        $html .= $ad['code'];
    }
    $html .= '</div>';
    return $html;
}

