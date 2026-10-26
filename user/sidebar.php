<?php
/**
 * 用户中心侧边栏
 */
if (!defined('ROOT_PATH')) { require_once __DIR__ . '/../includes/init.php'; }
$userPage = $userPage ?? '';
?>
<div class="user-sidebar">
  <a href="<?php echo url('user/'); ?>" class="<?php echo $userPage === 'dashboard' ? 'active' : ''; ?>">📊 概览</a>
  <a href="<?php echo url('user/bookmarks.php'); ?>" class="<?php echo $userPage === 'bookmarks' ? 'active' : ''; ?>">🔖 书签管理</a>
  <a href="<?php echo url('user/import.php'); ?>" class="<?php echo $userPage === 'import' ? 'active' : ''; ?>">📥 批量导入</a>
  <a href="<?php echo url('user/export.php'); ?>" class="<?php echo $userPage === 'export' ? 'active' : ''; ?>">📤 批量导出</a>
  <a href="<?php echo url('user/categories.php'); ?>" class="<?php echo $userPage === 'categories' ? 'active' : ''; ?>">📁 分类管理</a>
  <a href="<?php echo url('user/notes.php'); ?>" class="<?php echo $userPage === 'notes' ? 'active' : ''; ?>">📝 便签管理</a>
  <a href="<?php echo url('user/recycle.php'); ?>" class="<?php echo $userPage === 'recycle' ? 'active' : ''; ?>">♻ 回收站</a>
  <a href="<?php echo url('user/profile.php'); ?>" class="<?php echo $userPage === 'profile' ? 'active' : ''; ?>">⚙️ 个人设置</a>
  <a href="<?php echo url('user/plugin_settings.php'); ?>" class="<?php echo $userPage === 'plugin' ? 'active' : ''; ?>">🧩 插件设置</a>
  <a href="<?php echo url('u/' . (Auth::user()['username'])); ?>" target="_blank">👁️ 查看我的公开页</a>
</div>
