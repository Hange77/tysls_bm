<?php
/**
 * 分类详情页
 * 支持系统分类(user_id=0)和用户分类(user_id>0)
 * v1.3: 完整的权限检查、子分类递归展示、书签+便签
 */
require_once __DIR__ . '/includes/init.php';

// 支持数字ID和slug别名（slug仅系统分类user_id=0可用）
$param = $_GET['param'] ?? $_GET['id'] ?? '';
if ($param === '') show_404('分类不存在');
if (ctype_digit((string)$param)) {
    $id = (int)$param;
    $category = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE id = ? AND deleted_at IS NULL", [$id]);
} else {
    // slug别名仅系统分类(user_id=0)可用，用户分类不支持slug
    $category = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE slug = ? AND user_id = 0 AND deleted_at IS NULL", [$param]);
    if ($category) $id = (int)$category['id'];
}
if (!$category) show_404('分类不存在');

$catUserId = (int)$category['user_id'];
$isOwner = Auth::check() && Auth::id() === $catUserId;
$isAdmin = Auth::isAdmin();
$canViewPrivate = $isOwner || $isAdmin;

// 私有分类检查
if (!$category['is_public'] && !$canViewPrivate) {
    show_403('该分类为私有');
}

// 密码验证
$unlocked = false;
if ($category['password']) {
    if ($canViewPrivate) {
        $unlocked = true;
    } elseif (!empty($_SESSION['cat_unlocked_' . $id]) && $_SESSION['cat_unlocked_' . $id] > time()) {
        $unlocked = true;
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        CSRF::check();
        if (safe_password_verify($_POST['password'] ?? '', $category['password'])) {
            // 解锁状态 2 小时内有效,避免公共电脑长期免密
            $_SESSION['cat_unlocked_' . $id] = time() + 7200;
            $unlocked = true;
        } else {
            $passwordError = '密码错误';
        }
    }
} else {
    $unlocked = true;
}

// 获取分类所有者信息(如果是用户分类)
$catOwner = null;
if ($catUserId > 0) {
    $catOwner = DB::fetch("SELECT id, username, nickname, avatar FROM " . DB_PREFIX . "users WHERE id = ?", [$catUserId]);
}

// 构建面包屑(父分类链)
$breadcrumb = [];
$tmpId = (int)$category['parent_id'];
while ($tmpId > 0) {
    $parent = DB::fetch("SELECT id, name, parent_id FROM " . DB_PREFIX . "categories WHERE id = ? AND deleted_at IS NULL", [$tmpId]);
    if (!$parent) break;
    array_unshift($breadcrumb, $parent);
    $tmpId = (int)$parent['parent_id'];
}

$pageTitle = $category['name'];
// 广告显示范围：系统分类=site，用户分类=user
$catPageScope = ((int)$category['user_id'] === 0) ? 'site' : 'user';
$catAdsTop = get_ads($catPageScope, 'top');
$catAdsBottom = get_ads($catPageScope, 'bottom');

include __DIR__ . '/includes/header.php';

/**
 * 递归渲染分类及其子分类的内容
 */
function render_category_content($catId, $catUserId, $canViewPrivate, $level = 0) {
    $cat = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE id = ? AND deleted_at IS NULL", [$catId]);
    if (!$cat) return '';
    // 权限检查
    if (!$cat['is_public'] && !$canViewPrivate) return '';
    // 密码检查(非所有者)
    $locked = false;
    $catUnlocked = !empty($_SESSION['cat_unlocked_' . $catId]) && $_SESSION['cat_unlocked_' . $catId] > time();
    if ($cat['password'] && !$canViewPrivate && !$catUnlocked) {
        $locked = true;
    }
    $catLink = category_url($cat);
    $html = '';
    if ($level > 0) {
        $html .= '<div class="subcat-section" id="subcat-' . (int)$cat['id'] . '">';
        $html .= '<div class="subcat-title">';
        $html .= '<a href="' . e($catLink) . '" class="cat-title-link">';
        if ($cat['icon']) $html .= '<span class="cat-icon">' . e($cat['icon']) . '</span>';
        $html .= e($cat['name']);
        $html .= '</a>';
        if (!$cat['is_public']) $html .= '<span class="badge badge-muted" style="margin-left:6px;font-size:11px;">私有</span>';
        if ($locked) $html .= '<span class="badge badge-warning" style="margin-left:6px;font-size:11px;">🔒</span>';
        $html .= '</div>';
    }
    if ($locked) {
        $html .= '<div class="card" style="text-align:center;padding:20px;margin:8px 0;">';
        $html .= '<p style="color:var(--text-muted);margin-bottom:10px;font-size:13px;">该分类需要密码才能查看</p>';
        $html .= '<form method="post" action="" style="max-width:260px;margin:0 auto;display:flex;gap:6px;">';
        $html .= CSRF::field();
        $html .= '<input type="password" name="password" class="form-control form-control-sm" placeholder="输入密码" required>';
        $html .= '<button type="submit" class="btn btn-sm btn-primary">解锁</button>';
        $html .= '</form></div>';
    } else {
        // 子分类（置顶，递归，最多3层）
        if ($level < 2) {
            $subSql = "SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = ? AND parent_id = ? AND deleted_at IS NULL";
            if (!$canViewPrivate) $subSql .= " AND is_public = 1";
            $subSql .= " ORDER BY sort ASC, id ASC";
            $subCats = DB::fetchAll($subSql, [$catUserId, $catId]);
            foreach ($subCats as $sc) {
                $html .= render_category_content((int)$sc['id'], $catUserId, $canViewPrivate, $level + 1);
            }
        } else {
            // 超过3层,只显示子分类链接
            $subSql = "SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = ? AND parent_id = ? AND deleted_at IS NULL";
            if (!$canViewPrivate) $subSql .= " AND is_public = 1";
            $subSql .= " ORDER BY sort ASC, id ASC LIMIT 20";
            $subCats = DB::fetchAll($subSql, [$catUserId, $catId]);
            if (!empty($subCats)) {
                $html .= '<div class="subcat-nav" style="margin-top:12px;">';
                foreach ($subCats as $sc) {
                    $html .= '<a href="' . e(category_url($sc)) . '">' . e($sc['icon'] ? $sc['icon'] . ' ' : '') . e($sc['name']) . ' &raquo;</a>';
                }
                $html .= '</div>';
            }
        }
        // 书签（小药丸）
        $bmSql = "SELECT * FROM " . DB_PREFIX . "bookmarks WHERE user_id = ? AND category_id = ? AND deleted_at IS NULL";
        if (!$canViewPrivate) $bmSql .= " AND is_public = 1";
        $bmSql .= " ORDER BY sort ASC, id ASC";
        $bookmarks = DB::fetchAll($bmSql, [$catUserId, $catId]);
        if (!empty($bookmarks)) {
            $html .= '<div class="pill-grid cat-pill-grid">';
            foreach ($bookmarks as $bm) {
                ob_start();
                render_bookmark_pill($bm, $canViewPrivate, 24);
                $html .= ob_get_clean();
            }
            $html .= '</div>';
        }
        // 页面（content_type=html，站内直链，不经 go.php）
        $pageSql = "SELECT * FROM " . DB_PREFIX . "pages WHERE user_id = ? AND content_type = 'html' AND category_id = ? AND deleted_at IS NULL";
        if (!$canViewPrivate) $pageSql .= " AND is_public = 1";
        $pageSql .= " ORDER BY id ASC";
        $pages = DB::fetchAll($pageSql, [$catUserId, $catId]);
        if (!empty($pages)) {
            if ($level === 0) {
                $html .= '<h3 style="font-size:15px;margin:20px 0 12px;color:var(--text-light);">页面</h3>';
            }
            $html .= '<div class="pill-grid cat-pill-grid cat-page-pills">';
            foreach ($pages as $p) {
                $html .= '<a href="' . e(page_url($p)) . '" class="bookmark-mini cat-page-pill" title="' . e($p['title']) . '">';
                $html .= '<span class="mini-icon">📄</span>';
                $html .= '<span class="mini-title">' . e($p['title']) . '</span>';
                $html .= '</a>';
            }
            $html .= '</div>';
        }
        // 便签
        $noteSql = "SELECT * FROM " . DB_PREFIX . "pages WHERE user_id = ? AND content_type = 'text' AND category_id = ? AND deleted_at IS NULL";
        if (!$canViewPrivate) $noteSql .= " AND is_public = 1";
        $noteSql .= " ORDER BY created_at DESC LIMIT 12";
        $notes = DB::fetchAll($noteSql, [$catUserId, $catId]);
        if (!empty($notes)) {
            if ($level === 0) {
                $html .= '<h3 style="font-size:15px;margin:20px 0 12px;color:var(--text-light);">便签</h3>';
            }
            $html .= '<div class="note-grid">';
            foreach ($notes as $note) {
                $html .= '<a href="' . page_url($note) . '" class="note-card" style="text-decoration:none;color:inherit;display:block;">';
                $html .= '<h3>' . e($note['title']) . '</h3>';
                $html .= '<div class="note-content">' . e(truncate($note['content'], 100)) . '</div>';
                $html .= '<div class="note-meta"><span>' . e(date('m-d H:i', strtotime($note['created_at']))) . '</span></div>';
                $html .= '</a>';
            }
            $html .= '</div>';
        }
    }
    if ($level > 0) {
        $html .= '</div>';
    }
    return $html;
}
?>
<div class="container">
  <?php if (!$unlocked): ?>
  <!-- 密码锁定 -->
  <div class="card" style="text-align:center;padding:40px;max-width:500px;margin:40px auto;">
    <div style="font-size:48px;margin-bottom:16px;">🔒</div>
    <h2 style="margin-bottom:8px;"><?php echo e($category['name']); ?></h2>
    <p style="color:var(--text-muted);margin-bottom:24px;">该分类需要密码才能查看</p>
    <?php if (!empty($passwordError)): ?>
    <div class="alert alert-danger" style="max-width:320px;margin:0 auto 16px;"><?php echo e($passwordError); ?></div>
    <?php endif; ?>
    <form method="post" action="" style="max-width:320px;margin:0 auto;display:flex;gap:8px;">
      <?php echo CSRF::field(); ?>
      <input type="password" name="password" class="form-control" placeholder="输入分类密码" required autofocus>
      <button type="submit" class="btn btn-primary">解锁</button>
    </form>
  </div>

  <?php else: ?>
  <!-- 面包屑 -->
  <?php if (!empty($breadcrumb) || $catOwner): ?>
  <div class="breadcrumb">
    <a href="<?php echo url(''); ?>">首页</a>
    <?php if ($catOwner): ?>
    <span class="sep">/</span>
    <a href="<?php echo e(url('u/' . $catOwner['username'])); ?>"><?php echo e($catOwner['nickname'] ?: $catOwner['username']); ?>的收藏</a>
    <?php endif; ?>
    <?php foreach ($breadcrumb as $bc): ?>
    <span class="sep">/</span>
    <a href="<?php echo e(category_url($bc)); ?>"><?php echo e($bc['name']); ?></a>
    <?php endforeach; ?>
    <span class="sep">/</span>
    <span class="current"><?php echo e($category['name']); ?></span>
  </div>
  <?php endif; ?>

  <!-- 页头广告 -->
  <?php if (!empty($catAdsTop)): ?>
  <div class="ad-card">
    <?php foreach ($catAdsTop as $ad): echo $ad['code']; endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- v1.4.1.1 全站聚合搜索条（页头广告位下方） -->
  <?php include __DIR__ . '/includes/search_bar.php'; ?>

  <!-- 分类标题区 -->
  <div class="category-section">
    <div class="category-title">
      <?php if ($category['icon']): ?><span class="cat-icon"><?php echo e($category['icon']); ?></span><?php endif; ?>
      <?php echo e($category['name']); ?>
      <?php if (!$category['is_public']): ?>
      <span class="badge badge-muted" style="margin-left:8px;">私有</span>
      <?php endif; ?>
      <?php if ($category['description']): ?>
      <span style="font-size:13px;color:var(--text-muted);font-weight:normal;"><?php echo e($category['description']); ?></span>
      <?php endif; ?>
      <?php if ($isOwner): ?>
      <a href="<?php echo e(url('user/categories.php?edit=' . $id)); ?>" class="cat-more-btn" style="font-size:12px;">编辑分类</a>
      <?php endif; ?>
    </div>

    <?php
    // 渲染分类内容(含递归子分类)
    echo render_category_content($id, $catUserId, $canViewPrivate, 0);
    ?>

    <?php
    // 检查是否完全为空
    $bmCount = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "bookmarks WHERE user_id = ? AND category_id = ? AND deleted_at IS NULL" . ($canViewPrivate ? '' : " AND is_public = 1"), [$catUserId, $id]);
    $noteCount = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "pages WHERE user_id = ? AND content_type = 'text' AND category_id = ? AND deleted_at IS NULL" . ($canViewPrivate ? '' : " AND is_public = 1"), [$catUserId, $id]);
    $subCount = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "categories WHERE user_id = ? AND parent_id = ? AND deleted_at IS NULL" . ($canViewPrivate ? '' : " AND is_public = 1"), [$catUserId, $id]);
    $pageCount = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "pages WHERE user_id = ? AND content_type = 'html' AND category_id = ? AND deleted_at IS NULL" . ($canViewPrivate ? '' : " AND is_public = 1"), [$catUserId, $id]);
    if ($bmCount == 0 && $noteCount == 0 && $subCount == 0 && $pageCount == 0):
    ?>
    <div class="empty-state"><div class="empty-icon">📭</div><p>该分类下暂无内容</p></div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
