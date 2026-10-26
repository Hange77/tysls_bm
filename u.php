<?php
/**
 * 用户公开个人收藏夹
 * URL格式: /u/用户名 (需URL重写) 或 /u.php?name=用户名 或 /u.php/用户名
 * v1.3: 分类标题可点击进入详情页,子分类独立渲染内容区块,添加"更多"按钮
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/home.php';

// 解析用户名
$username = '';
if (!empty($_GET['name'])) {
    $username = trim($_GET['name']);
} elseif (!empty($_SERVER['PATH_INFO'])) {
    $username = trim($_SERVER['PATH_INFO'], '/');
} elseif (isset($_GET['url'])) {
    $parts = explode('/', trim($_GET['url'], '/'));
    if (!empty($parts) && $parts[0] === 'u' && isset($parts[1])) {
        $username = $parts[1];
    }
}

if (!$username || !validate_username($username)) {
    show_404('用户不存在');
}

// 查询用户
$user = DB::fetch("SELECT * FROM " . DB_PREFIX . "users WHERE username = ? AND is_active = 1", [$username]);
if (!$user) {
    show_404('用户不存在');
}

$userId = (int)$user['id'];
$isOwner = Auth::check() && Auth::id() === $userId;
$isAdmin = Auth::isAdmin();
$canViewPrivate = $isOwner || $isAdmin;

// 复用网址大全的 4 种排版渲染（布局由访客界面设置决定；便签随分类展示；分类密码受上下文控制）
home_set_view_ctx($userId, $canViewPrivate, true, true);

// 处理分类密码验证
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cat_password']) && isset($_POST['cat_id'])) {
    CSRF::check();
    $catId = (int)$_POST['cat_id'];
    $cat = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = ? AND deleted_at IS NULL", [$catId, $userId]);
    if ($cat && $cat['password'] && safe_password_verify($_POST['cat_password'], $cat['password'])) {
        $_SESSION['cat_unlocked_' . $catId] = true;
    }
    redirect(url('u/' . $username . '#cat-' . $catId));
}

// ========== 公告和广告（用户收藏夹页面） ==========
$showAnnouncementsOnUserPage = setting('show_announcements_on_user_page', '1') === '1';
$userPageAnnouncements = [];
$userPageAdsTop = get_ads('user', 'top');
$userPageAdsBottom = get_ads('user', 'bottom');

if ($showAnnouncementsOnUserPage && setting('marquee_enabled', '1') === '1') {
    $userPageAnnouncements = DB::fetchAll(
        "SELECT * FROM " . DB_PREFIX . "announcements 
         WHERE is_active = 1 AND (expire_at IS NULL OR expire_at > NOW())
         ORDER BY sort ASC, id ASC"
    );
}

$pageTitle = $user['nickname'] ?: $user['username'];
$currentPage = '';
include __DIR__ . '/includes/header.php';
?>
<!-- 书签/便签/子分类的渲染已统一复用 includes/home.php 的 render_home_* 4 种排版（见下方 home_set_view_ctx），旧的 u_* 辅助函数已移除。 -->
<div class="container">
  <!-- 用户信息头部 -->
  <div class="profile-header">
    <div class="avatar">
      <?php if ($user['avatar']): ?>
        <img src="<?php echo e($user['avatar']); ?>" alt="">
      <?php else: ?>
        <?php echo e(mb_substr($user['nickname'] ?: $user['username'], 0, 1)); ?>
      <?php endif; ?>
    </div>
    <div style="flex:1;">
      <h1><?php echo e($user['nickname'] ?: $user['username']); ?></h1>
      <?php if ($user['bio']): ?>
      <p><?php echo e($user['bio']); ?></p>
      <?php else: ?>
      <p style="opacity:.6;">这个人很懒，什么都没留下~</p>
      <?php endif; ?>
      <p style="font-size:12px;opacity:.8;margin-top:6px;">
        个人收藏夹地址:
        <span id="userHomepageUrl" style="cursor:pointer;text-decoration:underline;" onclick="copyUserHomepage()" title="点击复制"><?php
          $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
          $host = $_SERVER['HTTP_HOST'] ?? '';
          $fullUrl = $scheme . '://' . $host . url('u/' . $username);
          echo e($fullUrl);
        ?></span>
        <span id="copyTip" style="display:none;margin-left:6px;color:#52c41a;">已复制!</span>
      </p>
    </div>
    <?php if ($isOwner): ?>
    <a href="<?php echo url('user/'); ?>" class="btn" style="background:rgba(255,255,255,.2);color:#fff;">管理我的收藏</a>
    <?php endif; ?>
  </div>
  <script>
  function copyUserHomepage() {
    var urlEl = document.getElementById('userHomepageUrl');
    var text = urlEl.textContent.trim();
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(showCopyTip);
    } else {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); showCopyTip(); } catch(e) {}
      document.body.removeChild(ta);
    }
  }
  function showCopyTip() {
    var tip = document.getElementById('copyTip');
    tip.style.display = 'inline';
    setTimeout(function(){ tip.style.display = 'none'; }, 2000);
  }
  </script>

  <!-- 公告跑马灯（用户收藏夹页面） -->
  <?php if (!empty($userPageAnnouncements)): ?>
  <div class="announcement-bar marquee-<?php echo e(setting('marquee_direction', 'horizontal')); ?>" style="--marquee-duration:<?php echo (int)setting('marquee_speed', '30'); ?>s;margin-bottom:16px;">
    <span class="announcement-label">📢</span>
    <div class="marquee-container">
      <div class="marquee-track">
        <?php for ($i = 0; $i < 2; $i++): foreach ($userPageAnnouncements as $ann): ?>
        <span class="marquee-item">
          <?php if ($ann['link_url']): ?><a href="<?php echo e($ann['link_url']); ?>" target="_blank" rel="noopener noreferrer"><?php endif; ?>
          <?php if ($ann['title']): ?><strong><?php echo e($ann['title']); ?>：</strong><?php endif; ?>
          <?php echo e($ann['content']); ?>
          <?php if ($ann['link_url']): ?></a><?php endif; ?>
        </span>
        <?php endforeach; endfor; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- 顶部广告位（用户收藏夹页面） -->
  <?php if (!empty($userPageAdsTop)): ?>
  <div class="ad-card">
    <?php foreach ($userPageAdsTop as $ad): echo $ad['code']; endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- v1.4.1.1 全站聚合搜索条（页头广告位下方） -->
  <?php include __DIR__ . '/includes/search_bar.php'; ?>

  <?php
  $uTree = get_home_tree();
  $uLayout = get_ui_prefs()['layout'];
  ?>
  <?php if (empty($uTree)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="empty-icon">📭</div>
      <p>该用户暂无公开收藏</p>
    </div>
  </div>
  <?php else: ?>
  <div id="homeRoot">
    <?php if ($uLayout === 'vertical'): ?>
      <?php render_home_vertical($uTree); ?>
    <?php elseif ($uLayout === 'horizontal'): ?>
      <?php render_home_horizontal($uTree); ?>
    <?php elseif ($uLayout === 'card'): ?>
      <?php render_home_card($uTree); ?>
    <?php else: ?>
      <?php render_home_default($uTree); ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>

</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
