<?php
/**
 * 公共页面页脚
 * - 页尾广告位在此渲染,但位于 <footer> 标签之前(内容区之后、页脚之外),确保广告在页面底色上、与页脚视觉独立
 * - 其他页脚代码(footer_extra_code)紧跟 ICP 备案号之后输出
 */
if (!defined('ROOT_PATH')) { require_once __DIR__ . '/init.php'; }

// 根据当前页面判断广告显示范围(user=用户收藏夹页, site=其余页面)
$adScope = 'site';
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
if (substr($scriptName, -6) === '/u.php') {
    $adScope = 'user';
}
$footerAds = [];
try {
    $footerAds = get_ads($adScope, 'bottom');
} catch (Exception $e) {
    $footerAds = [];
}
?>
<?php if (!empty($footerAds)): ?>
<div class="container" style="max-width:1200px;margin:0 auto;padding:0 20px;">
  <div class="ad-card">
    <?php foreach ($footerAds as $ad): echo $ad['code']; endforeach; ?>
  </div>
</div>
<?php endif; ?>
<footer class="footer">
  <p><?php echo e(setting('site_name', '书签导航')); ?> &copy; <?php echo date('Y'); ?>
  <?php if (setting('icp_number')): ?>
    | <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?php echo e(setting('icp_number')); ?></a>
  <?php endif; ?>
  <?php if (setting('footer_extra_code')): ?>
    | <?php echo setting('footer_extra_code'); ?>
  <?php endif; ?>
  </p>
</footer>
<?php if (setting('analytics_code')): ?>
<?php echo setting('analytics_code'); ?>
<?php endif; ?>
</body>
</html>
