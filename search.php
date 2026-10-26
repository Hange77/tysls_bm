<?php
/**
 * v1.2 全站搜索页面
 * 支持按标题、URL、描述搜索书签和分类
 * 搜索范围：全部（系统+我的）/ 我的收藏
 */
require_once __DIR__ . '/includes/init.php';
$pageTitle = '搜索';
$currentPage = 'search';

$keyword = trim($_GET['q'] ?? '');
$scope = $_GET['scope'] ?? 'all';
if ($scope === 'my' && !Auth::check()) $scope = 'all';
if (!in_array($scope, ['all', 'my'])) $scope = 'all';

$results = ['bookmarks' => [], 'categories' => [], 'pages' => [], 'notes' => []];
if ($keyword !== '') {
    $results = search_bookmarks($keyword, Auth::id(), $scope);
}
$totalCount = count($results['bookmarks']) + count($results['categories']) + count($results['pages']) + count($results['notes']);
// 广告显示范围：搜索全部=site，搜索我的=user
$searchAdScope = ($scope === 'my') ? 'user' : 'site';
$searchAdsTop = get_ads($searchAdScope, 'top');
$searchAdsBottom = get_ads($searchAdScope, 'bottom');

include __DIR__ . '/includes/header.php';
?>
<div class="container">
  <!-- 页头广告 -->
  <?php if (!empty($searchAdsTop)): ?>
  <div class="ad-card">
    <?php foreach ($searchAdsTop as $ad): echo $ad['code']; endforeach; ?>
  </div>
  <?php endif; ?>
  <!-- v1.4.1.1 全站聚合搜索条（页头广告位下方） -->
  <?php include __DIR__ . '/includes/search_bar.php'; ?>
  <div class="card">
    <div class="card-header">
      <h2>搜索结果</h2>
      <?php if ($keyword): ?>
      <a href="<?php echo url('search.php'); ?>" class="btn btn-sm btn-default">清除搜索</a>
      <?php endif; ?>
    </div>
    <?php if ($keyword): ?>
    <div style="padding:0 20px 16px;font-size:14px;color:var(--text2);">
      关键词：<strong><?php echo e($keyword); ?></strong>
      ，范围：<strong><?php echo $scope === 'my' ? '我的收藏' : '站内'; ?></strong>
      ，共找到 <strong><?php echo (int)$totalCount; ?></strong> 条结果
    </div>
    <?php endif; ?>

    <?php if (!$keyword): ?>
    <div class="empty-state">
      <div class="empty-icon">🔍</div>
      <p>请在上方搜索框输入关键词</p>
      <p style="font-size:13px;color:var(--text2);">支持搜索书签的标题、URL、描述，以及分类、页面、便签</p>
    </div>
    <?php else: ?>

    <?php if (!empty($results['bookmarks'])): ?>
    <div style="padding:0 20px 20px;">
      <h3 style="margin:0 0 12px;font-size:16px;">书签 (<?php echo count($results['bookmarks']); ?>)</h3>
      <div class="bookmark-grid">
        <?php foreach ($results['bookmarks'] as $bm): ?>
        <a href="<?php echo url('go.php?id=' . $bm['id']); ?>" target="_blank" rel="noopener noreferrer" class="bookmark-item">
          <div class="bookmark-icon">
            <?php if ($bm['icon']): ?>
              <?php if (filter_var($bm['icon'], FILTER_VALIDATE_URL)): ?>
                <img src="<?php echo e($bm['icon']); ?>" alt="" loading="lazy">
              <?php else: ?>
                <?php echo e($bm['icon']); ?>
              <?php endif; ?>
            <?php else: ?>
              🌐
            <?php endif; ?>
          </div>
          <div class="bookmark-info">
            <div class="bookmark-title"><?php echo e($bm['title']); ?></div>
            <?php if ($bm['description']): ?>
            <div class="bookmark-desc"><?php echo e(truncate($bm['description'], 60)); ?></div>
            <?php endif; ?>
            <div class="bookmark-meta">
              <span><?php echo e($bm['category_name'] ?: '未分类'); ?></span>
              <span>点击 <?php echo (int)$bm['click_count']; ?></span>
            </div>
          </div>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($results['categories'])): ?>
    <div style="padding:0 20px 20px;">
      <h3 style="margin:0 0 12px;font-size:16px;">分类 (<?php echo count($results['categories']); ?>)</h3>
      <div class="search-category-list">
        <?php foreach ($results['categories'] as $cat): ?>
        <a href="<?php echo category_url($cat); ?>" class="search-category-item">
          <span class="cat-icon"><?php echo e($cat['icon'] ?: '📁'); ?></span>
          <span class="cat-name"><?php echo e($cat['name']); ?></span>
          <?php if ($cat['description']): ?>
          <span class="cat-desc"><?php echo e(truncate($cat['description'], 40)); ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($results['pages'])): ?>
    <div style="padding:0 20px 20px;">
      <h3 style="margin:0 0 12px;font-size:16px;">页面 (<?php echo count($results['pages']); ?>)</h3>
      <div class="search-category-list">
        <?php foreach ($results['pages'] as $pg): ?>
        <a href="<?php echo page_url($pg); ?>" class="search-category-item">
          <span class="cat-icon">📄</span>
          <span class="cat-name"><?php echo e($pg['title']); ?></span>
          <?php if ($pg['content']): ?>
          <span class="cat-desc"><?php echo e(truncate(strip_tags($pg['content']), 40)); ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($results['notes'])): ?>
    <div style="padding:0 20px 20px;">
      <h3 style="margin:0 0 12px;font-size:16px;">便签 (<?php echo count($results['notes']); ?>)</h3>
      <div class="search-category-list">
        <?php foreach ($results['notes'] as $nt): ?>
        <a href="<?php echo page_url($nt); ?>" class="search-category-item">
          <span class="cat-icon">📝</span>
          <span class="cat-name"><?php echo e($nt['title']); ?></span>
          <?php if ($nt['content']): ?>
          <span class="cat-desc"><?php echo e(truncate($nt['content'], 40)); ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($totalCount === 0): ?>
    <div class="empty-state">
      <div class="empty-icon">🔍</div>
      <p>没有找到与 "<?php echo e($keyword); ?>" 相关的结果</p>
      <p style="font-size:13px;color:var(--text2);">建议：检查关键词拼写，或尝试其他关键词</p>
    </div>
    <?php endif; ?>

    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
