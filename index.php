<?php
/**
 * 首页 - 网址大全(系统分类+系统书签,由管理员在后台添加)
 * v1.3.9.5: 引入 includes/home.php 实现取数/渲染分离，支持三种首页显示模式
 */
require_once __DIR__ . '/includes/init.php';
require_once __DIR__ . '/includes/home.php';

$pageTitle = '网址大全';
$currentPage = 'home';

// 获取启用的公告（未过期）
$announcements = [];
if (setting('marquee_enabled', '1') === '1') {
    $announcements = DB::fetchAll(
        "SELECT * FROM " . DB_PREFIX . "announcements 
         WHERE is_active = 1 AND (expire_at IS NULL OR expire_at > NOW())
         ORDER BY sort ASC, id ASC"
    );
}

// 获取启用的广告（按显示范围过滤，当前页面是网址大全）
$adsTop = get_ads('site', 'top');
$adsBottom = get_ads('site', 'bottom');

// 搜索
$keyword = trim($_GET['q'] ?? '');

include __DIR__ . '/includes/header.php';
?>
<div class="container">
  <?php if (!$keyword): ?>
  <!-- 公告跑马灯 -->
  <?php if (!empty($announcements)): ?>
  <div class="announcement-bar marquee-<?php echo e(setting('marquee_direction', 'horizontal')); ?>" style="--marquee-duration:<?php echo (int)setting('marquee_speed', '30'); ?>s;">
    <span class="announcement-label">📢</span>
    <div class="marquee-container">
      <div class="marquee-track">
        <?php for ($i = 0; $i < 2; $i++): foreach ($announcements as $ann): ?>
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
  <!-- 顶部广告位 -->
  <?php if (!empty($adsTop)): ?>
  <div class="ad-card">
    <?php foreach ($adsTop as $ad): echo $ad['code']; endforeach; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <!-- v1.4.1.1 全站聚合搜索条（页头广告位下方） -->
  <?php include __DIR__ . '/includes/search_bar.php'; ?>

  <?php if ($keyword): ?>
    <?php $agg = search_bookmarks($keyword, Auth::id(), 'all'); ?>
    <div class="card">
      <div class="card-header">
        <h2>搜索结果: "<?php echo e($keyword); ?>"</h2>
        <a href="<?php echo url(''); ?>" class="btn btn-sm btn-default">清除搜索</a>
      </div>
      <?php if ($agg['bookmarks'] || $agg['categories'] || $agg['pages'] || $agg['notes']): ?>
        <?php if ($agg['bookmarks']): ?>
        <div style="padding:0 20px 20px;">
          <h3 style="margin:0 0 12px;font-size:16px;">书签 (<?php echo count($agg['bookmarks']); ?>)</h3>
          <div class="pill-grid">
            <?php foreach ($agg['bookmarks'] as $bm): render_bookmark_pill($bm); endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($agg['categories']): ?>
        <div style="padding:0 20px 20px;">
          <h3 style="margin:0 0 12px;font-size:16px;">分类 (<?php echo count($agg['categories']); ?>)</h3>
          <div class="search-category-list">
            <?php foreach ($agg['categories'] as $cat): ?>
            <a href="<?php echo category_url($cat); ?>" class="search-category-item"><span class="cat-icon"><?php echo e($cat['icon'] ?: '📁'); ?></span><span class="cat-name"><?php echo e($cat['name']); ?></span></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($agg['pages']): ?>
        <div style="padding:0 20px 20px;">
          <h3 style="margin:0 0 12px;font-size:16px;">页面 (<?php echo count($agg['pages']); ?>)</h3>
          <div class="search-category-list">
            <?php foreach ($agg['pages'] as $pg): ?>
            <a href="<?php echo page_url($pg); ?>" class="search-category-item"><span class="cat-icon">📄</span><span class="cat-name"><?php echo e($pg['title']); ?></span></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php if ($agg['notes']): ?>
        <div style="padding:0 20px 20px;">
          <h3 style="margin:0 0 12px;font-size:16px;">便签 (<?php echo count($agg['notes']); ?>)</h3>
          <div class="search-category-list">
            <?php foreach ($agg['notes'] as $nt): ?>
            <a href="<?php echo page_url($nt); ?>" class="search-category-item"><span class="cat-icon">📝</span><span class="cat-name"><?php echo e($nt['title']); ?></span></a>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
      <?php else: ?>
      <div class="empty-state">
        <div class="empty-icon">🔍</div>
        <p>没有找到相关内容</p>
      </div>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <?php $tree = get_home_tree(); ?>
    <?php $layout = get_ui_prefs()['layout']; ?>
    <div id="homeRoot">
    <?php if ($layout === 'vertical'): ?>
      <?php render_home_vertical($tree); ?>
    <?php elseif ($layout === 'horizontal'): ?>
      <?php render_home_horizontal($tree); ?>
    <?php elseif ($layout === 'card'): ?>
      <?php render_home_card($tree); ?>
    <?php else: ?>
      <?php render_home_default($tree); ?>
    <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- 首页友情链接（仅首页展示，footer 上方） -->
  <?php
  $friendLinks = [];
  try { $friendLinks = get_friend_links(); } catch (Exception $e) {}
  if (!$keyword && !empty($friendLinks)):
  ?>
  <div class="friend-links">
    <span class="friend-links-label">友情链接：</span>
    <?php foreach ($friendLinks as $fl): ?>
      <a href="<?php echo e($fl['url']); ?>" target="_blank" rel="noopener noreferrer" class="friend-link-item" title="<?php echo e($fl['description'] ?: $fl['name']); ?>">
        <?php if ($fl['icon']): ?><img src="<?php echo e($fl['icon']); ?>" alt="" class="friend-link-icon" loading="lazy"><?php endif; ?>
        <?php echo e($fl['name']); ?>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
