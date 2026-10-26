<?php
/**
 * v1.4.1.1 全站聚合搜索条（显示在页头广告位下方，前台各页通用）
 * 选项卡：站内（默认，站内搜索） / 我的收藏（登录后显示） / 站外引擎（后台配置，点击后新窗口跳转；默认项可在后台"默认搜索范围/引擎"设置）
 * - 引擎来自后台设置 search_engines（启用项按 sort 升序，模板须含 %s）
 * - 默认选中「全部」；从搜索页带 scope=my 进入时自动选中「我的收藏」
 * 使用方式：<?php include __DIR__ . '/includes/search_bar.php'; ?>
 */
if (!defined('ROOT_PATH')) { require_once __DIR__ . '/init.php'; }

// 站外引擎（仅取启用且模板含 %s 的项，按 sort 升序）
$searchBarEngines = [];
$enginesJson = setting('search_engines', '');
if ($enginesJson) {
    $decoded = json_decode($enginesJson, true);
    if (is_array($decoded)) {
        foreach ($decoded as $eng) {
            if (!empty($eng['enabled']) && !empty($eng['url_template'])
                && strpos((string)$eng['url_template'], '%s') !== false) {
                $searchBarEngines[] = $eng;
            }
        }
        usort($searchBarEngines, function ($a, $b) {
            return (int)($a['sort'] ?? 0) <=> (int)($b['sort'] ?? 0);
        });
    }
}
$searchBarQ = trim($_GET['q'] ?? '');
// 后台设置的默认搜索范围/引擎：site / my / ext:NAME（站内对应 tab 的 data-mode 为 all）
$searchBarDefaultMode = 'all';
$defMode = setting('search_default_mode', 'site');
if ($defMode === 'my' && Auth::check()) {
    $searchBarDefaultMode = 'my';
} elseif (strpos($defMode, 'ext:') === 0) {
    $defName = substr($defMode, 4);
    foreach ($searchBarEngines as $eng) {
        if (($eng['name'] ?? '') === $defName) { $searchBarDefaultMode = 'ext:' . $defName; break; }
    }
}
// 带 q/scope 的回带（如从搜索结果页返回）优先按 URL，否则用后台默认
$hasUrlCtx = (!empty($_GET['q']) || isset($_GET['scope']));
$searchBarScope = (($_GET['scope'] ?? 'all') === 'my' && Auth::check()) ? 'my' : 'all';
$activeMode = $hasUrlCtx ? $searchBarScope : $searchBarDefaultMode;
?>
<div class="agg-search" id="aggSearch">
  <div class="agg-tabs" role="tablist">
    <button type="button" class="agg-tab<?php echo $activeMode === 'all' ? ' active' : ''; ?>" data-mode="all">站内</button>
    <?php if (Auth::check()): ?>
    <button type="button" class="agg-tab<?php echo $activeMode === 'my' ? ' active' : ''; ?>" data-mode="my">我的收藏</button>
    <?php endif; ?>
    <?php foreach ($searchBarEngines as $eng): ?>
    <button type="button" class="agg-tab<?php echo $activeMode === 'ext:' . $eng['name'] ? ' active' : ''; ?>" data-mode="external" data-template="<?php echo e($eng['url_template']); ?>"><?php echo e($eng['name']); ?></button>
    <?php endforeach; ?>
  </div>
  <form class="agg-input-row" id="aggSearchForm" data-search-url="<?php echo e(url('search.php')); ?>" autocomplete="off">
    <input type="text" class="agg-keyword" name="q" placeholder="输入关键词，选择范围后回车或点 🔍 搜索" value="<?php echo e($searchBarQ); ?>">
    <button type="submit" class="agg-submit" title="搜索" aria-label="搜索">🔍</button>
  </form>
</div>
<script>
(function(){
  var root = document.getElementById('aggSearch');
  if (!root || root.dataset.init) return;
  root.dataset.init = '1';
  var form = root.querySelector('.agg-input-row');
  var input = root.querySelector('.agg-keyword');
  root.querySelectorAll('.agg-tab').forEach(function(tab){
    tab.addEventListener('click', function(){
      root.querySelectorAll('.agg-tab').forEach(function(t){ t.classList.remove('active'); });
      tab.classList.add('active');
      input.focus();
    });
  });
  form.addEventListener('submit', function(ev){
    ev.preventDefault();
    var q = input.value.trim();
    if (!q) { input.focus(); return; }
    var active = root.querySelector('.agg-tab.active');
    var mode = active ? active.getAttribute('data-mode') : 'all';
    if (mode === 'external') {
      var tpl = active.getAttribute('data-template') || '';
      if (tpl) window.open(tpl.replace('%s', encodeURIComponent(q)), '_blank', 'noopener');
      return;
    }
    var scope = mode === 'my' ? 'my' : 'all';
    location.href = form.getAttribute('data-search-url') + '?q=' + encodeURIComponent(q) + '&scope=' + scope;
  });
})();
</script>
