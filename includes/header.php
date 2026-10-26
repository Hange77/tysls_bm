<?php
/**
 * 公共页面头部
 */
if (!defined('ROOT_PATH')) { require_once __DIR__ . '/init.php'; }
$pageTitle = $pageTitle ?? setting('site_name', '书签导航');
$currentPage = $currentPage ?? '';

// v1.3.9.6 界面偏好（用户 > 后台全局默认 > 硬编码；游客读取 Cookie 防闪烁）
$uiPrefs = get_ui_prefs();
$theme = $uiPrefs['theme'];
$darkMode = $uiPrefs['dark'];
$layout = $uiPrefs['layout'];
$themeCss = theme_css_vars($theme, $darkMode === 'on');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($pageTitle); ?> - <?php echo e(setting('site_name', '书签导航')); ?></title>
<meta name="description" content="<?php echo e(setting('site_description', '')); ?>">
<meta name="keywords" content="<?php echo e(setting('site_keywords', '')); ?>">
<link rel="stylesheet" href="<?php echo asset('css/style.css'); ?>">
<link rel="icon" href="<?php echo e(url('favicon.ico')); ?>">
<style>:root { <?php echo $themeCss; ?> }</style>
<?php if (!empty($extraHead)) echo $extraHead; ?>
</head>
<body data-theme="<?php echo e($theme); ?>" data-dark="<?php echo $darkMode === 'on' ? '1' : '0'; ?>" data-layout="<?php echo e($layout); ?>">
<nav class="navbar">
  <div class="navbar-inner">
    <a href="<?php echo url(''); ?>" class="navbar-brand">
      <?php $siteLogo = setting('site_logo', ''); ?>
      <?php if ($siteLogo && filter_var($siteLogo, FILTER_VALIDATE_URL)): ?>
      <img src="<?php echo e($siteLogo); ?>" alt="Logo" style="height:28px;width:auto;border-radius:4px;vertical-align:middle;">
      <?php else: ?>
      <span style="font-size:22px;">🔖</span>
      <?php endif; ?>
      <?php echo e(setting('site_name', '书签导航')); ?>
    </a>
    <div class="navbar-nav">
      <a href="<?php echo url(''); ?>" class="<?php echo $currentPage === 'home' ? 'active' : ''; ?>">网址大全</a>
      <?php if (Auth::check()): ?>
      <a href="<?php echo url('user/'); ?>" class="<?php echo $currentPage === 'user' ? 'active' : ''; ?>">我的收藏</a>
      <?php if (Auth::isAdmin()): ?>
      <a href="<?php echo url('admin/'); ?>" class="<?php echo $currentPage === 'admin' ? 'active' : ''; ?>">后台管理</a>
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <!-- v1.4.1.1 导航栏搜索框已迁移至全站聚合搜索条（includes/search_bar.php，页头广告位下方） -->
    <div class="navbar-right">
      <?php if (Auth::check()): ?>
      <div class="user-info" id="userAvatarBtn">
        <div class="avatar">
          <?php if (Auth::user()['avatar']): ?>
            <img src="<?php echo e(Auth::user()['avatar']); ?>" alt="">
          <?php else: ?>
            <?php echo e(mb_substr(Auth::user()['nickname'] ?: Auth::user()['username'], 0, 1)); ?>
          <?php endif; ?>
        </div>
        <span class="user-name"><?php echo e(Auth::user()['nickname'] ?: Auth::user()['username']); ?></span>
        <span class="caret">▾</span>
      </div>
      <div class="user-dropdown" id="userDropdown" hidden>
        <a href="<?php echo url('user/profile.php'); ?>">个人设置</a>
        <?php if ($currentPage !== 'admin'): ?>
        <button type="button" class="js-open-panel">界面设置</button>
        <?php endif; ?>
        <a href="<?php echo url('logout.php'); ?>">退出登录</a>
      </div>
      <?php else: ?>
      <a href="<?php echo url('login.php'); ?>" class="btn btn-sm btn-default">登录</a>
      <?php if (setting('allow_register', '1') === '1'): ?>
      <a href="<?php echo url('register.php'); ?>" class="btn btn-sm btn-primary">注册</a>
      <?php endif; ?>
      <?php if ($currentPage !== 'admin'): ?>
      <button type="button" class="ui-toggle ui-interface-btn js-open-panel" title="界面设置">界面</button>
      <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</nav>

<?php if ($currentPage !== 'admin'): ?>
<!-- v1.3.9.6 界面设置浮动面板 -->
<div class="ui-panel" id="uiPanel" hidden>
  <div class="ui-panel-header">
    <span>界面设置</span>
    <button type="button" class="ui-panel-close" id="uiPanelClose" aria-label="关闭">×</button>
  </div>
  <div class="ui-panel-section">
    <div class="ui-panel-label">主题颜色</div>
    <div class="ui-theme-grid">
      <?php foreach (get_theme_list() as $key => $t): ?>
      <button type="button" class="ui-theme-swatch<?php echo $theme === $key ? ' active' : ''; ?>" data-theme="<?php echo e($key); ?>" title="<?php echo e($t['name']); ?>" style="background:<?php echo e($t['primary']); ?>;"></button>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="ui-panel-section">
    <div class="ui-panel-label">深色模式</div>
    <div class="ui-seg" data-group="dark">
      <button type="button" class="ui-seg-btn<?php echo $darkMode === 'off' ? ' active' : ''; ?>" data-value="off">关闭</button>
      <button type="button" class="ui-seg-btn<?php echo $darkMode === 'on' ? ' active' : ''; ?>" data-value="on">开启</button>
      <button type="button" class="ui-seg-btn<?php echo $darkMode === 'auto' ? ' active' : ''; ?>" data-value="auto">自动</button>
    </div>
  </div>
  <div class="ui-panel-section">
    <div class="ui-panel-label">首页布局</div>
    <div class="ui-seg" data-group="layout">
      <button type="button" class="ui-seg-btn<?php echo $layout === 'default' ? ' active' : ''; ?>" data-value="default">默认</button>
      <button type="button" class="ui-seg-btn<?php echo $layout === 'vertical' ? ' active' : ''; ?>" data-value="vertical">分类竖排</button>
      <button type="button" class="ui-seg-btn<?php echo $layout === 'horizontal' ? ' active' : ''; ?>" data-value="horizontal">分类横排</button>
      <button type="button" class="ui-seg-btn<?php echo $layout === 'card' ? ' active' : ''; ?>" data-value="card">分类卡片</button>
    </div>
  </div>
  <div class="ui-panel-hint"><?php echo Auth::check() ? '设置已保存到你的账号' : '未登录，设置仅保存在本机浏览器'; ?></div>
</div>
<script>
(function(){
  var THEME_LIST = <?php echo json_encode(get_theme_list()); ?>;
  var prefs = { theme: '<?php echo e($theme); ?>', dark: '<?php echo e($darkMode); ?>', layout: '<?php echo e($layout); ?>' };
  var loggedIn = <?php echo Auth::check() ? 'true' : 'false'; ?>;
  var csrf = '<?php echo e(CSRF::token()); ?>';
  var apiUrl = '<?php echo e(url('user/api.php')); ?>';

  function applyTheme(theme, dark) {
    var t = THEME_LIST[theme] || THEME_LIST['default'];
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    var isDark = (dark === 'on') || (dark === 'auto' && mq.matches);
    var s = document.documentElement.style;
    s.setProperty('--primary', t.primary);
    s.setProperty('--bg', isDark ? '#141414' : t.bg);
    s.setProperty('--card', isDark ? '#1f1f1f' : t.card);
    s.setProperty('--bg-card', isDark ? '#1f1f1f' : '#ffffff');
    s.setProperty('--text', isDark ? '#e8e8e8' : t.text);
    s.setProperty('--text2', isDark ? '#a0a0a0' : t.text2);
    s.setProperty('--text-light', isDark ? '#a0a0a0' : '#666666');
    s.setProperty('--text-muted', isDark ? '#888888' : '#999999');
    s.setProperty('--border', isDark ? '#303030' : t.border);
    s.setProperty('--link-hover', isDark ? '#40a9ff' : t.primary);
    document.body.setAttribute('data-dark', isDark ? '1' : '0');
    // 自动模式：跟随系统变化
    if (dark === 'auto') {
      var handler = function(){ applyTheme(theme, 'auto'); };
      if (mq.addEventListener) mq.addEventListener('change', handler);
      else if (mq.addListener) mq.addListener(handler);
    }
  }

  function persist(p) {
    if (loggedIn) {
      var fd = new FormData();
      fd.append('action', 'save_ui');
      fd.append('_csrf_token', csrf);
      fd.append('theme', p.theme);
      fd.append('dark_mode', p.dark);
      fd.append('home_layout', p.layout);
      fetch(apiUrl, { method: 'POST', body: fd }).catch(function(){});
    } else {
      var exp = new Date(Date.now() + 365 * 864e5).toUTCString();
      document.cookie = 'ui_theme=' + p.theme + ';path=/;expires=' + exp;
      document.cookie = 'ui_dark=' + p.dark + ';path=/;expires=' + exp;
      document.cookie = 'ui_layout=' + p.layout + ';path=/;expires=' + exp;
    }
  }

  function syncPanel() {
    document.querySelectorAll('.ui-theme-swatch').forEach(function(b){ b.classList.toggle('active', b.dataset.theme === prefs.theme); });
    document.querySelectorAll('.ui-seg[data-group="dark"] .ui-seg-btn').forEach(function(b){ b.classList.toggle('active', b.dataset.value === prefs.dark); });
    document.querySelectorAll('.ui-seg[data-group="layout"] .ui-seg-btn').forEach(function(b){ b.classList.toggle('active', b.dataset.value === prefs.layout); });
  }

  function setPref(key, value) {
    prefs[key] = value;
    if (key === 'theme' || key === 'dark') applyTheme(prefs.theme, prefs.dark);
    if (key === 'layout') {
      document.documentElement.setAttribute('data-layout', prefs.layout);
      if (document.getElementById('homeRoot')) {
        persist(prefs);
        setTimeout(function(){ location.reload(); }, 150);
        return;
      }
    }
    persist(prefs);
    syncPanel();
  }

  var darkBtn = null; // 深色模式 quick toggle 已移入界面面板
  var order = ['off', 'on', 'auto'];

  var panel = document.getElementById('uiPanel');
  var panelClose = document.getElementById('uiPanelClose');
  document.querySelectorAll('.js-open-panel').forEach(function(b){
    b.addEventListener('click', function(e){
      e.stopPropagation();
      if (panel) panel.hidden = !panel.hidden;
      var dd = document.getElementById('userDropdown');
      if (dd) dd.hidden = true;
    });
  });
  if (panelClose) panelClose.addEventListener('click', function(){ panel.hidden = true; });
  document.addEventListener('click', function(e){
    if (panel && !panel.hidden && !panel.contains(e.target) && !e.target.classList.contains('js-open-panel')) panel.hidden = true;
  });

  // 头像下拉菜单
  var avatarBtn = document.getElementById('userAvatarBtn');
  var dropdown = document.getElementById('userDropdown');
  if (avatarBtn && dropdown) {
    avatarBtn.addEventListener('click', function(e){ e.stopPropagation(); dropdown.hidden = !dropdown.hidden; });
    document.addEventListener('click', function(e){
      if (!dropdown.hidden && !dropdown.contains(e.target) && e.target !== avatarBtn) dropdown.hidden = true;
    });
  }

  document.querySelectorAll('.ui-theme-swatch').forEach(function(b){
    b.addEventListener('click', function(){ setPref('theme', b.dataset.theme); });
  });
  document.querySelectorAll('.ui-seg').forEach(function(seg){
    seg.querySelectorAll('.ui-seg-btn').forEach(function(b){
      b.addEventListener('click', function(){ setPref(seg.dataset.group, b.dataset.value); });
    });
  });

  applyTheme(prefs.theme, prefs.dark);
  document.documentElement.setAttribute('data-layout', prefs.layout);
  syncPanel();
})();
</script>
<?php endif; ?>

<?php $flashMsg = get_flash(); if ($flashMsg): ?>
<div class="container" style="max-width:1200px;margin:16px auto 0;padding:0 16px;">
  <div class="alert alert-<?php echo e($flashMsg['type']); ?>"><?php echo e($flashMsg['msg']); ?></div>
</div>
<?php endif; ?>
