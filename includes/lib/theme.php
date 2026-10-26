<?php
/**
 * 通用辅助函数 - theme
 *
 * 主题系统与界面偏好：主题列表 / CSS 变量 / UI 偏好解析 / 书签药丸渲染。
 */

// ========== v1.2 主题系统 ==========
function get_theme_list() {
    return [
        'default' => ['name' => '默认蓝', 'primary' => '#4a90d9', 'bg' => '#f5f7fa', 'card' => '#ffffff', 'text' => '#1a1a1a', 'text2' => '#666666', 'border' => '#e8e8e8'],
        'green' => ['name' => '护眼绿', 'primary' => '#52c41a', 'bg' => '#f6ffed', 'card' => '#ffffff', 'text' => '#1a1a1a', 'text2' => '#666666', 'border' => '#d9f7be'],
        'orange' => ['name' => '暖橙', 'primary' => '#fa8c16', 'bg' => '#fff7e6', 'card' => '#ffffff', 'text' => '#1a1a1a', 'text2' => '#666666', 'border' => '#ffd591'],
        'purple' => ['name' => '优雅紫', 'primary' => '#722ed1', 'bg' => '#f9f0ff', 'card' => '#ffffff', 'text' => '#1a1a1a', 'text2' => '#666666', 'border' => '#d3adf7'],
        'cyan' => ['name' => '青碧', 'primary' => '#13c2c2', 'bg' => '#e6fffb', 'card' => '#ffffff', 'text' => '#1a1a1a', 'text2' => '#666666', 'border' => '#87e8de'],
        'gray' => ['name' => '商务灰', 'primary' => '#595959', 'bg' => '#fafafa', 'card' => '#ffffff', 'text' => '#262626', 'text2' => '#8c8c8c', 'border' => '#d9d9d9'],
    ];
}

// ========== v1.3.9.6 界面偏好解析 ==========
// 优先级：登录用户个人设置 > 后台全局默认 > 硬编码默认值
// 游客：优先读取浏览器 Cookie（避免刷新闪烁），回退到后台全局默认
function get_ui_prefs() {
    $defaults = [
        'theme'  => setting('default_theme', 'default'),
        'dark'   => setting('default_dark_mode', 'off'),
        'layout' => setting('default_home_layout', 'default'),
    ];
    $themes = get_theme_list();
    $validDark = ['off', 'on', 'auto'];
    $validLayout = ['default', 'vertical', 'horizontal', 'card'];

    if (Auth::check()) {
        $u = Auth::user();
        $theme  = (!empty($u['theme']) && isset($themes[$u['theme']])) ? $u['theme'] : $defaults['theme'];
        $dark   = in_array($u['dark_mode'] ?? '', $validDark, true) ? $u['dark_mode'] : $defaults['dark'];
        $layout = in_array($u['home_layout'] ?? '', $validLayout, true) ? $u['home_layout'] : $defaults['layout'];
    } else {
        $theme  = (isset($_COOKIE['ui_theme']) && isset($themes[$_COOKIE['ui_theme']])) ? $_COOKIE['ui_theme'] : $defaults['theme'];
        $dark   = in_array($_COOKIE['ui_dark'] ?? '', $validDark, true) ? $_COOKIE['ui_dark'] : $defaults['dark'];
        $layout = in_array($_COOKIE['ui_layout'] ?? '', $validLayout, true) ? $_COOKIE['ui_layout'] : $defaults['layout'];
    }

    if (!isset($themes[$theme])) $theme = 'default';
    if (!in_array($dark, $validDark, true)) $dark = 'off';
    if (!in_array($layout, $validLayout, true)) $layout = 'default';

    return ['theme' => $theme, 'dark' => $dark, 'layout' => $layout];
}

// ========== v1.3.9.6 小药丸书签渲染（全局复用） ==========
// 标题超过 $limit 个字符时截断并补省略号（中文按字符计）；分类页建议传 24，首页默认 14
// v1.4.0.5: 书签描述利用（后台设置 bookmark_desc_mode）：
//   off   = 不显示描述（默认，兼容旧行为）
//   hover = 鼠标悬停（title）显示描述
//   below = 在标题下方显示描述（小字）
function render_bookmark_pill($bm, $showPrivate = false, $limit = 14) {
    $title = truncate($bm['title'] ?? '', $limit, '…');
    $url = url('go.php?id=' . (int)($bm['id'] ?? 0));
    $desc = trim($bm['description'] ?? '');
    $descMode = setting('bookmark_desc_mode', 'off');
    if (!in_array($descMode, ['off', 'hover', 'below'], true)) {
        $descMode = 'off';
    }
    // 悬停提示：标题后追加描述（截断避免过长）
    $titleAttr = $bm['title'] ?? '';
    if ($descMode === 'hover' && $desc !== '') {
        $titleAttr = ($bm['title'] ?? '') . ' — ' . truncate($desc, 120, '…');
    }
    $descHtml = '';
    if ($descMode === 'below' && $desc !== '') {
        $descHtml = '<span class="mini-desc">' . e(truncate($desc, 30, '…')) . '</span>';
    }
    // 高亮书签（功能4）：仅对标题文字加粗 + 独立颜色，不动药丸外观
    // 颜色值需为合法 hex（#rgb 或 #rrggbb），否则仅加粗，避免 XSS / 非法样式
    $isFeatured = !empty($bm['is_featured']);
    $featuredStyle = '';
    if ($isFeatured) {
        $featuredStyle = ' style="font-weight:700;' . (function () use ($bm) {
            $fc = trim($bm['featured_color'] ?? '');
            if ($fc !== '' && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $fc)) {
                return 'color:' . $fc . ';';
            }
            return '';
        })() . '"';
    }
    $cls = 'bookmark-mini' . ($isFeatured ? ' is-featured' : '');
    // 自动获取 Favicon（v1.4.1.3）：未设置图标时按域名从 favicon 服务获取，失败回退 🌐
    $favIconHtml = '';
    if (setting('favicon_mode', 'auto') !== 'off') {
        $host = '';
        $rawUrl = $bm['url'] ?? '';
        if ($rawUrl !== '') $host = (string) parse_url($rawUrl, PHP_URL_HOST);
        if ($host === '' && !empty($bm['domain'])) $host = $bm['domain'];
        if ($host !== '') {
            $tpl = setting('favicon_service_url', '');
            if ($tpl === '') $tpl = 'https://icons.duckduckgo.com/ip3/{domain}.ico';
            $favIconHtml = '<img class="mini-icon" src="' . e(str_replace('{domain}', $host, $tpl)) . '" alt="" loading="lazy" onerror="this.outerHTML=\'<span class=mini-icon>🌐</span>\'">';
        }
    }
    ?>
    <a href="<?php echo e($url); ?>" target="_blank" rel="noopener noreferrer" class="<?php echo $cls; ?>" title="<?php echo e($titleAttr); ?>">
      <?php if (!empty($bm['icon'])): ?>
        <?php if (filter_var($bm['icon'], FILTER_VALIDATE_URL)): ?>
          <img class="mini-icon" src="<?php echo e($bm['icon']); ?>" alt="" loading="lazy">
        <?php else: ?>
          <span class="mini-icon"><?php echo e($bm['icon']); ?></span>
        <?php endif; ?>
      <?php elseif ($favIconHtml !== ''): ?>
        <?php echo $favIconHtml; ?>
      <?php else: ?>
        <span class="mini-icon">🌐</span>
      <?php endif; ?>
      <?php if ($descHtml !== ''): ?>
        <span class="mini-text">
          <span class="mini-title"<?php echo $featuredStyle; ?>><?php echo e($title); ?></span>
          <?php echo $descHtml; ?>
        </span>
      <?php else: ?>
        <span class="mini-title"<?php echo $featuredStyle; ?>><?php echo e($title); ?></span>
      <?php endif; ?>
      <?php if ($showPrivate && empty($bm['is_public'])): ?>
        <span class="mini-badge">私</span>
      <?php endif; ?>
    </a>
    <?php
}

function theme_css_vars($theme, $dark = false) {
    $themes = get_theme_list();
    $t = $themes[$theme] ?? $themes['default'];
    if ($dark) {
        return "--primary:{$t['primary']};--bg:#141414;--card:#1f1f1f;--bg-card:#1f1f1f;--text:#e8e8e8;--text2:#a0a0a0;--text-light:#a0a0a0;--text-muted:#888888;--border:#303030;--link-hover:#40a9ff;";
    }
    return "--primary:{$t['primary']};--bg:{$t['bg']};--card:{$t['card']};--bg-card:#ffffff;--text:{$t['text']};--text2:{$t['text2']};--text-light:#666666;--text-muted:#999999;--border:{$t['border']};--link-hover:{$t['primary']};";
}

