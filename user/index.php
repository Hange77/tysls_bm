<?php
/**
 * 用户中心 - 概览
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$userId = Auth::id();
$userPage = 'dashboard';
$pageTitle = '我的收藏';
$currentPage = 'user';

// 统计
$statBookmarks = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "bookmarks WHERE user_id = ? AND deleted_at IS NULL", [$userId]);
$statCategories = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "categories WHERE user_id = ? AND deleted_at IS NULL", [$userId]);
$statNotes = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "pages WHERE user_id = ? AND content_type = 'text' AND deleted_at IS NULL", [$userId]);
$statClicks = DB::fetchColumn("SELECT COALESCE(SUM(click_count),0) FROM " . DB_PREFIX . "bookmarks WHERE user_id = ? AND deleted_at IS NULL", [$userId]);

// 最近书签
$recentBookmarks = DB::fetchAll(
    "SELECT b.*, c.name as category_name FROM " . DB_PREFIX . "bookmarks b 
     LEFT JOIN " . DB_PREFIX . "categories c ON b.category_id = c.id 
     WHERE b.user_id = ? AND b.deleted_at IS NULL ORDER BY b.created_at DESC LIMIT 5",
    [$userId]
);

// 最近便签
$recentNotes = DB::fetchAll(
    "SELECT * FROM " . DB_PREFIX . "pages WHERE user_id = ? AND content_type = 'text' AND deleted_at IS NULL ORDER BY created_at DESC LIMIT 5",
    [$userId]
);

include __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="user-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="user-main">
      <div class="stats-grid">
        <div class="stat-card">
          <div class="stat-icon">🔖</div>
          <div class="stat-label">书签总数</div>
          <div class="stat-value"><?php echo $statBookmarks; ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">📁</div>
          <div class="stat-label">分类数</div>
          <div class="stat-value"><?php echo $statCategories; ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">📝</div>
          <div class="stat-label">便签数</div>
          <div class="stat-value"><?php echo $statNotes; ?></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon">👆</div>
          <div class="stat-label">总点击</div>
          <div class="stat-value"><?php echo $statClicks; ?></div>
        </div>
      </div>

      <div class="card">
        <div class="card-header">
          <h3>最近添加的书签</h3>
          <a href="<?php echo url('user/bookmarks.php'); ?>" class="btn btn-sm btn-default">全部</a>
        </div>
        <?php if ($recentBookmarks): ?>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>标题</th><th>分类</th><th>状态</th><th>添加时间</th></tr></thead>
            <tbody>
              <?php foreach ($recentBookmarks as $bm): ?>
              <tr>
                <td><a href="<?php echo url('go.php?id=' . $bm['id']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($bm['title']); ?></a></td>
                <td><?php echo e($bm['category_name'] ?: '-'); ?></td>
                <td><?php echo $bm['is_public'] ? '<span class="badge badge-success">公开</span>' : '<span class="badge badge-muted">私有</span>'; ?></td>
                <td><?php echo e(date('Y-m-d H:i', strtotime($bm['created_at']))); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">🔖</div>
          <p>还没有书签,去添加一个吧</p>
          <a href="<?php echo url('user/bookmarks.php?action=add'); ?>" class="btn btn-primary">添加书签</a>
        </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header">
          <h3>最近便签</h3>
          <a href="<?php echo url('user/notes.php'); ?>" class="btn btn-sm btn-default">全部</a>
        </div>
        <?php if ($recentNotes): ?>
        <div class="note-grid">
          <?php foreach ($recentNotes as $note): ?>
          <a href="<?php echo page_url($note); ?>" class="note-card" style="text-decoration:none;color:inherit;display:block;">
            <h3><?php echo e($note['title']); ?></h3>
            <div class="note-content"><?php echo e(truncate($note['content'], 60)); ?></div>
            <div class="note-meta">
              <span><?php echo e(date('m-d H:i', strtotime($note['created_at']))); ?></span>
              <span><?php echo $note['is_public'] ? '公开' : '私有'; ?></span>
            </div>
          </a>
          <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">📝</div>
          <p>还没有便签</p>
          <a href="<?php echo url('user/notes.php?action=add'); ?>" class="btn btn-primary">写便签</a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
