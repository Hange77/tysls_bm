<?php
/**
 * 后台管理 - 概览
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();

$adminPage = 'dashboard';
$pageTitle = '后台管理';
$currentPage = 'admin';

// 统计
$statUsers = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "users");
$statBookmarks = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "bookmarks WHERE deleted_at IS NULL");
$statCategories = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "categories WHERE deleted_at IS NULL");
$statPages = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "pages WHERE deleted_at IS NULL");
$statLogs = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "operation_logs");
$statClicks = DB::fetchColumn("SELECT COALESCE(SUM(click_count),0) FROM " . DB_PREFIX . "bookmarks WHERE deleted_at IS NULL");

// 最近用户
$recentUsers = DB::fetchAll("SELECT * FROM " . DB_PREFIX . "users ORDER BY created_at DESC LIMIT 5");
// 最近日志
$recentLogs = DB::fetchAll("SELECT * FROM " . DB_PREFIX . "operation_logs ORDER BY created_at DESC LIMIT 10");

include __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="admin-main">
    <div class="stats-grid">
      <div class="stat-card"><div class="stat-icon">👥</div><div class="stat-label">用户总数</div><div class="stat-value"><?php echo $statUsers; ?></div></div>
      <div class="stat-card"><div class="stat-icon">🔖</div><div class="stat-label">书签总数</div><div class="stat-value"><?php echo $statBookmarks; ?></div></div>
      <div class="stat-card"><div class="stat-icon">📁</div><div class="stat-label">分类总数</div><div class="stat-value"><?php echo $statCategories; ?></div></div>
      <div class="stat-card"><div class="stat-icon">📄</div><div class="stat-label">页面/便签</div><div class="stat-value"><?php echo $statPages; ?></div></div>
      <div class="stat-card"><div class="stat-icon">👆</div><div class="stat-label">总点击</div><div class="stat-value"><?php echo $statClicks; ?></div></div>
      <div class="stat-card"><div class="stat-icon">📋</div><div class="stat-label">操作日志</div><div class="stat-value"><?php echo $statLogs; ?></div></div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3>最近注册用户</h3>
        <a href="<?php echo url('admin/users.php'); ?>" class="btn btn-sm btn-default">全部用户</a>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>ID</th><th>用户名</th><th>昵称</th><th>邮箱</th><th>角色</th><th>状态</th><th>注册时间</th></tr></thead>
          <tbody>
            <?php foreach ($recentUsers as $u): ?>
            <tr>
              <td><?php echo (int)$u['id']; ?></td>
              <td><?php echo e($u['username']); ?></td>
              <td><?php echo e($u['nickname']); ?></td>
              <td><?php echo e($u['email'] ?: '-'); ?></td>
              <td><?php echo $u['role'] === 'admin' ? '<span class="badge badge-primary">管理员</span>' : '<span class="badge badge-muted">普通用户</span>'; ?></td>
              <td><?php echo $u['is_active'] ? '<span class="badge badge-success">正常</span>' : '<span class="badge badge-danger">已禁用</span>'; ?></td>
              <td><?php echo e(date('Y-m-d H:i', strtotime($u['created_at']))); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h3>最近操作日志</h3>
        <a href="<?php echo url('admin/logs.php'); ?>" class="btn btn-sm btn-default">全部日志</a>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>时间</th><th>用户</th><th>操作</th><th>目标</th><th>IP</th></tr></thead>
          <tbody>
            <?php foreach ($recentLogs as $log): ?>
            <tr>
              <td><?php echo e(date('Y-m-d H:i:s', strtotime($log['created_at']))); ?></td>
              <td><?php echo e($log['username']); ?></td>
              <td><span class="badge badge-primary"><?php echo e($log['action']); ?></span></td>
              <td><?php echo e($log['target_type'] . ($log['target_id'] ? ' #' . $log['target_id'] : '')); ?></td>
              <td><?php echo e($log['ip']); ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
