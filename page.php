<?php
/**
 * 页面/便签详情页
 */
require_once __DIR__ . '/includes/init.php';

// 支持数字ID和slug别名（slug仅后台页面user_id=0可用）
$param = $_GET['param'] ?? $_GET['id'] ?? '';
if ($param === '') {
    show_404('页面不存在');
}
if (ctype_digit((string)$param)) {
    $id = (int)$param;
    $page = DB::fetch("SELECT * FROM " . DB_PREFIX . "pages WHERE id = ? AND deleted_at IS NULL", [$id]);
} else {
    // slug别名仅后台页面(user_id=0)可用，用户便签不支持slug
    $page = DB::fetch("SELECT * FROM " . DB_PREFIX . "pages WHERE slug = ? AND user_id = 0 AND deleted_at IS NULL", [$param]);
    if ($page) $id = (int)$page['id'];
}
if (!$page) {
    show_404('页面不存在');
}

$isOwner = Auth::check() && Auth::id() === (int)$page['user_id'];
$isAdmin = Auth::isAdmin();

// 检查可见性
if (!$page['is_public'] && !$isOwner && !$isAdmin) {
    show_403('该页面为私有');
}

// 密码保护
if ($page['password'] && !$isOwner && !$isAdmin) {
    $sessionKey = 'page_unlocked_' . $page['id'];
    $unlocked = !empty($_SESSION[$sessionKey]) && $_SESSION[$sessionKey] > time();

    if (!$unlocked && $_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::check();
        if (safe_password_verify($_POST['password'] ?? '', $page['password'])) {
            // 解锁状态 2 小时内有效,避免公共电脑长期免密
            $_SESSION[$sessionKey] = time() + 7200;
            $unlocked = true;
        } else {
            $passwordError = '密码错误';
        }
    }

    if (!$unlocked) {
        $pageTitle = '需要密码';
        include __DIR__ . '/includes/header.php';
        ?>
        <div class="container-narrow">
          <div class="card" style="text-align:center;padding:40px;">
            <div style="font-size:48px;margin-bottom:16px;">🔒</div>
            <h2 style="margin-bottom:16px;"><?php echo e($page['title']); ?></h2>
            <p style="color:var(--text-muted);margin-bottom:24px;">该页面需要密码才能访问</p>
            <?php if (!empty($passwordError)): ?>
            <div class="alert alert-danger" style="max-width:320px;margin:0 auto 16px;"><?php echo e($passwordError); ?></div>
            <?php endif; ?>
            <form method="post" action="" style="max-width:320px;margin:0 auto;">
              <?php echo CSRF::field(); ?>
              <div class="form-group">
                <input type="password" name="password" class="form-control" placeholder="请输入访问密码" required autofocus>
              </div>
              <button type="submit" class="btn btn-primary btn-block">确认访问</button>
            </form>
          </div>
        </div>
        <?php
        include __DIR__ . '/includes/footer.php';
        exit;
    }
}

// 检查分类可见性
if ($page['category_id']) {
    $category = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE id = ? AND deleted_at IS NULL", [$page['category_id']]);
    if ($category && !$category['is_public'] && !$isOwner && !$isAdmin) {
        show_403('该分类为私有');
    }
}

// 记录浏览
DB::execute("UPDATE " . DB_PREFIX . "pages SET view_count = view_count + 1 WHERE id = ?", [$id]);

// 获取作者信息
$author = null;
if ($page['user_id'] > 0) {
    $author = DB::fetch("SELECT id, username, nickname, avatar FROM " . DB_PREFIX . "users WHERE id = ?", [$page['user_id']]);
}

$pageTitle = $page['title'];
// 广告显示范围：系统页面=site，用户便签=user
$pageAdScope = ((int)$page['user_id'] === 0) ? 'site' : 'user';
$pageAdsTop = get_ads($pageAdScope, 'top');
$pageAdsBottom = get_ads($pageAdScope, 'bottom');
include __DIR__ . '/includes/header.php';
?>
<div class="container-narrow">
  <!-- 页头广告 -->
  <?php if (!empty($pageAdsTop)): ?>
  <div class="ad-card">
    <?php foreach ($pageAdsTop as $ad): echo $ad['code']; endforeach; ?>
  </div>
  <?php endif; ?>
  <!-- v1.4.1.1 全站聚合搜索条（页头广告位下方） -->
  <?php include __DIR__ . '/includes/search_bar.php'; ?>
  <div class="page-content">
    <h1><?php echo e($page['title']); ?></h1>
    <div style="color:var(--text-muted);font-size:13px;margin-bottom:20px;padding-bottom:12px;border-bottom:1px solid var(--border);">
      <?php if ($author): ?>
      作者: <a href="<?php echo url('u/' . $author['username']); ?>"><?php echo e($author['nickname'] ?: $author['username']); ?></a>
      &nbsp;|&nbsp;
      <?php endif; ?>
      发布于 <?php echo e(date('Y-m-d H:i', strtotime($page['created_at']))); ?>
      &nbsp;|&nbsp;
      浏览 <?php echo (int)$page['view_count']; ?> 次
      <?php if ($isOwner || $isAdmin): ?>
      &nbsp;|&nbsp;
      <?php if ($page['user_id'] > 0): ?>
      <a href="<?php echo url('user/notes.php?edit=' . $page['id']); ?>">编辑</a>
      <?php else: ?>
      <a href="<?php echo url('admin/pages.php?edit=' . $page['id']); ?>">编辑</a>
      <?php endif; ?>
      <?php endif; ?>
    </div>
    <div class="page-body">
      <?php if ($page['content_type'] === 'html'): ?>
        <?php
        // HTML页面:管理员创建的页面,允许HTML
        // 注意:只有管理员可以创建html类型页面,用户只能创建text类型
        echo $page['content'];
        ?>
      <?php else: ?>
        <?php echo render_text_content($page['content']); ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
