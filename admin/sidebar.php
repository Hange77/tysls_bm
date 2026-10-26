<?php
/**
 * 后台管理侧边栏
 */
if (!defined('ROOT_PATH')) { require_once __DIR__ . '/../includes/init.php'; }
$adminPage = $adminPage ?? '';
?>
<div class="admin-sidebar">
  <div class="sidebar-title">系统管理</div>
  <a href="<?php echo url('admin/'); ?>" class="<?php echo $adminPage === 'dashboard' ? 'active' : ''; ?>">📊 概览</a>
  <a href="<?php echo url('admin/users.php'); ?>" class="<?php echo $adminPage === 'users' ? 'active' : ''; ?>">👥 用户管理</a>
  <a href="<?php echo url('admin/logs.php'); ?>" class="<?php echo $adminPage === 'logs' ? 'active' : ''; ?>">📋 操作日志</a>
  <a href="<?php echo url('admin/settings.php'); ?>" class="<?php echo $adminPage === 'settings' ? 'active' : ''; ?>">⚙️ 系统设置</a>
  <a href="<?php echo url('admin/plugin_settings.php'); ?>" class="<?php echo $adminPage === 'plugin' ? 'active' : ''; ?>">🔌 插件设置</a>
  <a href="<?php echo url('admin/theme.php'); ?>" class="<?php echo $adminPage === 'theme' ? 'active' : ''; ?>">🎨 界面主题</a>
  <a href="<?php echo url('admin/announcements.php'); ?>" class="<?php echo $adminPage === 'announcements' ? 'active' : ''; ?>">📢 站点公告</a>
  <a href="<?php echo url('admin/ads.php'); ?>" class="<?php echo $adminPage === 'ads' ? 'active' : ''; ?>">📺 广告管理</a>
  <a href="<?php echo url('admin/friend_links.php'); ?>" class="<?php echo $adminPage === 'friend_links' ? 'active' : ''; ?>">🤝 友情链接</a>

  <div class="sidebar-title" style="margin-top:16px;">内容管理(网址大全)</div>
  <a href="<?php echo url('admin/categories.php'); ?>" class="<?php echo $adminPage === 'categories' ? 'active' : ''; ?>">📁 分类管理</a>
  <a href="<?php echo url('admin/bookmarks.php'); ?>" class="<?php echo $adminPage === 'bookmarks' ? 'active' : ''; ?>">🔖 书签管理</a>
  <a href="<?php echo url('admin/import.php'); ?>" class="<?php echo $adminPage === 'import' ? 'active' : ''; ?>">📥 批量导入</a>
  <a href="<?php echo url('admin/export.php'); ?>" class="<?php echo $adminPage === 'export' ? 'active' : ''; ?>">📤 批量导出</a>
  <a href="<?php echo url('admin/pages.php'); ?>" class="<?php echo $adminPage === 'pages' ? 'active' : ''; ?>">📄 页面管理</a>
  <a href="<?php echo url('admin/recycle.php'); ?>" class="<?php echo $adminPage === 'recycle' ? 'active' : ''; ?>">♻️ 回收站</a>
</div>
