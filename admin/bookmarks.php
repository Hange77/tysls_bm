<?php
/**
 * 后台管理 - 系统书签管理(网址大全, user_id=0)
 * v1.3.4: 支持批量删除/批量移动
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
$adminPage = 'bookmarks';
$pageTitle = '书签管理(网址大全)';
$currentPage = 'admin';
$message = '';
$messageType = '';

require_once __DIR__ . '/../includes/bookmarks_actions.php';

// ========== 书签操作（增删改 / 批量 / 去重，前后台共用逻辑见 includes/bookmarks_actions.php） ==========
if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $res = bm_single_delete((int)$_GET['delete'], 0, true);
    if ($res) { $message = $res['message']; $messageType = $res['type']; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'delete') {
    CSRF::check();
    $res = bm_batch_delete($_POST['ids'] ?? [], 0, true);
    $message = $res['message']; $messageType = $res['type'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'move') {
    CSRF::check();
    $res = bm_batch_move($_POST['ids'] ?? [], (int)($_POST['target_category'] ?? 0), 0, true);
    $message = $res['message']; $messageType = $res['type'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'set_public') {
    CSRF::check();
    $res = bm_batch_set_public($_POST['ids'] ?? [], ($_POST['public_value'] ?? '1') == '1' ? 1 : 0, 0, true);
    $message = $res['message']; $messageType = $res['type'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'set_featured') {
    CSRF::check();
    $res = bm_batch_set_featured($_POST['ids'] ?? [], ($_POST['featured_value'] ?? '1') == '1' ? 1 : 0, 0, true);
    $message = $res['message']; $messageType = $res['type'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'dedupe') {
    CSRF::check();
    $res = bm_dedupe(0, true, (int)($_POST['category_id'] ?? 0), !empty($_POST['include_children']), '网址大全全部书签');
    $message = $res['message']; $messageType = $res['type'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_bookmark'])) {
    CSRF::check();
    $res = bm_save(0, true, $_POST, ['featured' => true]);
    if ($res) { $message = $res['message']; $messageType = $res['type']; }
}

$editBookmark = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editBookmark = DB::fetch("SELECT * FROM " . DB_PREFIX . "bookmarks WHERE id = ? AND user_id = 0 AND deleted_at IS NULL", [$editId]);
}

// 系统分类
$sysCats = DB::fetchAll("SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = 0 AND deleted_at IS NULL ORDER BY sort ASC, id ASC");
$catTree = build_category_tree($sysCats);

// 列表
$filterCat = isset($_GET['cat']) ? (int)$_GET['cat'] : -1; // -1=全部, 0=未分类, >0=指定分类
$filterDomain = isset($_GET['domain']) ? trim($_GET['domain']) : '';
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$onlyDup = isset($_GET['dup']) && $_GET['dup'] === '1';
$onlyDupTitle = isset($_GET['duptitle']) && $_GET['duptitle'] === '1';
$onlyFeatured = isset($_GET['featured']) && $_GET['featured'] === '1';
$where = "WHERE b.user_id = 0 AND b.deleted_at IS NULL";
if ($onlyFeatured) {
    $where .= " AND b.is_featured = 1";
}
$params = [];
if ($filterCat === 0) {
    $where .= " AND b.category_id = 0";
} elseif ($filterCat > 0) {
    $childIds = get_category_descendants($filterCat, 0);
    $allIds = array_merge([$filterCat], $childIds);
    $placeholders = implode(',', array_fill(0, count($allIds), '?'));
    $where .= " AND b.category_id IN ({$placeholders})";
    $params = $allIds;
}
// 域名筛选 / 仅看重复域名
$domains = DB::fetchAll("SELECT DISTINCT b.domain AS domain FROM " . DB_PREFIX . "bookmarks b WHERE b.user_id = 0 AND b.deleted_at IS NULL AND b.domain <> '' ORDER BY b.domain ASC");
$dupDomains = [];
if ($onlyDup) {
    $dupRows = DB::fetchAll("SELECT b.domain AS domain, COUNT(*) AS c FROM " . DB_PREFIX . "bookmarks b WHERE b.user_id = 0 AND b.deleted_at IS NULL AND b.domain <> '' GROUP BY b.domain HAVING c > 1 ORDER BY c DESC, b.domain ASC");
    $dupDomains = array_column($dupRows, 'domain');
}
$dupTitles = [];
if ($onlyDupTitle) {
    $dupTitleRows = DB::fetchAll("SELECT TRIM(b.title) AS title, COUNT(*) AS c FROM " . DB_PREFIX . "bookmarks b WHERE b.user_id = 0 AND b.deleted_at IS NULL AND TRIM(b.title) <> '' GROUP BY TRIM(b.title) HAVING c > 1 ORDER BY c DESC, TRIM(b.title) ASC");
    $dupTitles = array_column($dupTitleRows, 'title');
}
if ($filterDomain !== '') {
    $where .= " AND b.domain = ?";
    $params[] = $filterDomain;
} elseif ($onlyDup) {
    if ($dupDomains) {
        $ph = implode(',', array_fill(0, count($dupDomains), '?'));
        $where .= " AND b.domain IN ({$ph})";
        $params = array_merge($params, $dupDomains);
    } else {
        $where .= " AND 0 = 1";
    }
}
if ($onlyDupTitle) {
    if ($dupTitles) {
        $ph = implode(',', array_fill(0, count($dupTitles), '?'));
        $where .= " AND TRIM(b.title) IN ({$ph})";
        $params = array_merge($params, $dupTitles);
    } else {
        $where .= " AND 0 = 1";
    }
}
// 关键字搜索(标题/URL/描述)
if ($q !== '') {
    $like = '%' . $q . '%';
    $where .= " AND (b.title LIKE ? OR b.url LIKE ? OR b.description LIKE ?)";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}
$total = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "bookmarks b {$where}", $params);
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if (!in_array($perPage, [10, 20, 50, 100], true)) $perPage = 20;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pagination = paginate($total, $page, $perPage);
// 排序切换
$sort = $_GET['sort'] ?? 'default';
if (!in_array($sort, ['default', 'time', 'hot'])) $sort = 'default';
if ($sort === 'time') {
    $orderBy = 'b.created_at DESC, b.id DESC';
} elseif ($sort === 'hot') {
    $orderBy = 'b.click_count DESC, b.id DESC';
} else {
    $orderBy = 'b.sort ASC, b.id DESC';
}
// 拖拽排序模式：需用户主动点击「拖拽排序」按钮(?drag=1) 且 已选中具体分类(含未分类0) 且 无搜索/筛选/重复过滤
// 该模式下取消分页、列出该分类全部书签，方可整分类拖拽重排；默认显示正常分页列表
$dragRequested = (($_GET['drag'] ?? '') === '1');
$dragMode = $dragRequested && $filterCat !== -1 && $q === '' && $filterDomain === '' && !$onlyDup && !$onlyDupTitle;

// 搜索/筛选参数(供顶部与底部「每页设置」、分页链接复用)
$keepParams = [];
if ($filterCat !== -1) $keepParams['cat'] = $filterCat;
if ($sort !== 'default') $keepParams['sort'] = $sort;
if ($filterDomain !== '') $keepParams['domain'] = $filterDomain;
if ($onlyDup) $keepParams['dup'] = '1';
if ($onlyDupTitle) $keepParams['duptitle'] = '1';
if ($onlyFeatured) $keepParams['featured'] = '1';
if ($q !== '') $keepParams['q'] = $q;
$keepParams['per_page'] = $perPage;
$pageUrl = url('admin/bookmarks.php') . '?' . http_build_query($keepParams);
$pageUrlDragOn = url('admin/bookmarks.php') . '?' . http_build_query(array_merge($keepParams, ['drag' => '1']));

if ($dragMode) {
    // 拖拽模式：列出该分类全部书签（按当前排序），不做分页
    $bookmarks = DB::fetchAll(
        "SELECT b.*, c.name as category_name FROM " . DB_PREFIX . "bookmarks b 
         LEFT JOIN " . DB_PREFIX . "categories c ON b.category_id = c.id 
         {$where} ORDER BY {$orderBy}",
        $params
    );
} else {
    $bookmarks = DB::fetchAll(
        "SELECT b.*, c.name as category_name FROM " . DB_PREFIX . "bookmarks b 
         LEFT JOIN " . DB_PREFIX . "categories c ON b.category_id = c.id 
         {$where} ORDER BY {$orderBy} LIMIT ? OFFSET ?",
        array_merge($params, [$pagination['per_page'], $pagination['offset']])
    );
}

include __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="admin-main">
    <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div><?php endif; ?>
    <?php if (isset($_GET['action']) && $_GET['action'] === 'add' || $editBookmark): ?>
    <div class="card">
      <div class="card-header">
        <h3><?php echo $editBookmark ? '编辑书签' : '添加书签(网址大全)'; ?></h3>
        <a href="<?php echo url('admin/bookmarks.php'); ?>" class="btn btn-sm btn-default">返回列表</a>
      </div>
      <form method="post" action="">
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="save_bookmark" value="1">
        <input type="hidden" name="id" value="<?php echo $editBookmark ? (int)$editBookmark['id'] : 0; ?>">
        <div class="form-row">
          <div class="form-group"><label>标题 *</label><input type="text" name="title" class="form-control" required value="<?php echo e($editBookmark['title'] ?? ''); ?>"></div>
          <div class="form-group"><label>URL *</label><input type="text" name="url" class="form-control" required placeholder="https://example.com" value="<?php echo e($editBookmark['url'] ?? ''); ?>"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>所属分类</label>
            <select name="category_id" class="form-control"><option value="0">未分类</option><?php echo render_category_options($catTree, $editBookmark['category_id'] ?? 0); ?></select>
          </div>
          <div class="form-group"><label>图标(emoji或图片URL)</label><input type="text" name="icon" class="form-control" value="<?php echo e($editBookmark['icon'] ?? ''); ?>"></div>
        </div>
        <div class="form-group"><label>描述</label><textarea name="description" class="form-control" rows="2"><?php echo e($editBookmark['description'] ?? ''); ?></textarea></div>
        <div class="form-row">
          <div class="form-group"><label>排序</label><input type="number" name="sort" class="form-control" value="<?php echo (int)($editBookmark['sort'] ?? 0); ?>"></div>
          <div class="form-group"><label>推荐位颜色</label><input type="color" name="featured_color" class="form-control" value="<?php echo e($editBookmark['featured_color'] ?? '#e74c3c'); ?>" style="height:38px;padding:2px;width:100%;"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>&nbsp;</label><div class="form-check"><input type="checkbox" name="is_public" id="is_public" <?php echo (!isset($editBookmark) || $editBookmark['is_public']) ? 'checked' : ''; ?>><label for="is_public">公开显示</label></div></div>
          <div class="form-group"><label>&nbsp;</label><div class="form-check"><input type="checkbox" name="is_featured" id="is_featured" <?php echo (!empty($editBookmark['is_featured'])) ? 'checked' : ''; ?>><label for="is_featured">设为推荐位（标题加粗+独立颜色）</label></div></div>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo $editBookmark ? '保存修改' : '添加书签'; ?></button>
        <a href="<?php echo url('admin/bookmarks.php'); ?>" class="btn btn-default">取消</a>
      </form>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-header">
        <h3>系统书签列表 (共 <?php echo $total; ?> 个)</h3>
        <a href="<?php echo url('admin/bookmarks.php?action=add'); ?>" class="btn btn-sm btn-primary">+ 添加书签</a>
      </div>
      <div class="subcat-nav" style="margin-bottom:16px;">
        <a href="<?php echo url('admin/bookmarks.php'); ?>" class="<?php echo $filterCat === -1 ? 'active' : ''; ?>">全部</a>
        <a href="<?php echo url('admin/bookmarks.php?cat=0'); ?>" class="<?php echo $filterCat === 0 ? 'active' : ''; ?>">未分类</a>
        <?php foreach ($catTree as $cat): ?>
        <a href="<?php echo url('admin/bookmarks.php?cat=' . $cat['id']); ?>" class="<?php echo $filterCat === (int)$cat['id'] ? 'active' : ''; ?>"><?php echo e($cat['icon'] ? $cat['icon'] . ' ' : ''); ?><?php echo e($cat['name']); ?></a>
        <?php endforeach; ?>
      </div>
      <!-- 搜索框 -->
      <form method="get" class="filter-row search-row" style="padding:0 20px 12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <?php if ($filterCat !== -1): ?><input type="hidden" name="cat" value="<?php echo $filterCat; ?>"><?php endif; ?>
        <?php if ($sort !== 'default'): ?><input type="hidden" name="sort" value="<?php echo $sort; ?>"><?php endif; ?>
        <input type="text" name="q" class="form-control" placeholder="搜索标题 / 网址 / 描述…" value="<?php echo e($q); ?>" style="max-width:280px;">
        <button type="submit" class="btn btn-sm btn-primary">搜索</button>
        <?php if ($q !== ''): ?>
        <a href="<?php echo url('admin/bookmarks.php' . ($filterCat !== -1 ? '?cat=' . $filterCat : '')); ?>" class="btn btn-sm btn-default">清除搜索</a>
        <?php endif; ?>
      </form>
      <!-- 域名筛选 / 仅看重复域名 -->
      <form method="get" class="filter-row" style="padding:0 20px 12px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
        <?php if ($filterCat !== -1): ?><input type="hidden" name="cat" value="<?php echo $filterCat; ?>"><?php endif; ?>
        <?php if ($sort !== 'default'): ?><input type="hidden" name="sort" value="<?php echo $sort; ?>"><?php endif; ?>
        <label style="font-size:13px;color:var(--text-muted);">域名：
          <select name="domain" onchange="this.form.submit()" style="margin-left:4px;">
            <option value="">全部域名</option>
            <?php foreach ($domains as $d): ?><option value="<?php echo e($d['domain']); ?>" <?php echo $filterDomain === $d['domain'] ? 'selected' : ''; ?>><?php echo e($d['domain']); ?></option><?php endforeach; ?>
          </select>
        </label>
        <label style="font-size:13px;color:var(--text-muted);"><input type="checkbox" name="dup" value="1" onchange="this.form.submit()" <?php echo $onlyDup ? 'checked' : ''; ?>> 仅看重复域名</label>
        <label style="font-size:13px;color:var(--text-muted);"><input type="checkbox" name="duptitle" value="1" onchange="this.form.submit()" <?php echo $onlyDupTitle ? 'checked' : ''; ?>> 仅看重复标题</label>
        <label style="font-size:13px;color:var(--text-muted);"><input type="checkbox" name="featured" value="1" onchange="this.form.submit()" <?php echo $onlyFeatured ? 'checked' : ''; ?>> 仅看推荐位</label>
        <?php if ($filterDomain !== '' || $onlyDup || $onlyDupTitle): ?><a href="<?php echo url('admin/bookmarks.php'); ?>" class="btn btn-sm btn-default">清除筛选</a><?php endif; ?>
      </form>
      <!-- 排序切换 -->
      <div class="sort-bar" style="padding:0 20px 12px;">
        <span class="sort-label">排序：</span>
        <a href="<?php echo url('admin/bookmarks.php?sort=default' . ($filterCat !== -1 ? '&cat=' . $filterCat : '')); ?>" class="sort-btn <?php echo $sort==='default'?'active':''; ?>">分类</a>
        <a href="<?php echo url('admin/bookmarks.php?sort=time' . ($filterCat !== -1 ? '&cat=' . $filterCat : '')); ?>" class="sort-btn <?php echo $sort==='time'?'active':''; ?>">时间</a>
        <a href="<?php echo url('admin/bookmarks.php?sort=hot' . ($filterCat !== -1 ? '&cat=' . $filterCat : '')); ?>" class="sort-btn <?php echo $sort==='hot'?'active':''; ?>">热度</a>
      </div>
      <?php if ($bookmarks): ?>
      <!-- 批量操作栏 -->
      <div class="batch-bar" id="batchBar" style="display:none;">
        <span class="batch-count">已选 <strong id="selectedCount">0</strong> 项</span>
        <form method="post" action="" style="display:inline-flex;gap:8px;align-items:center;" id="batchForm">
          <?php echo CSRF::field(); ?>
          <input type="hidden" name="batch_action" id="batchAction" value="">
          <select name="target_category" id="targetCategory" class="form-control form-control-sm target-category" style="width:auto;display:none;">
            <option value="0">移到未分类</option>
            <?php echo render_category_options($catTree, 0); ?>
          </select>
            <select name="public_value" id="batchPublicValue" class="form-control form-control-sm" style="width:auto;">
              <option value="1">设为公开</option>
              <option value="0">设为私有</option>
            </select>
            <button type="button" class="btn btn-sm btn-default" onclick="batchSetPublic()">批量设状态</button>
            <select name="featured_value" id="batchFeaturedValue" class="form-control form-control-sm" style="width:auto;">
              <option value="1">设为推荐位</option>
              <option value="0">取消推荐位</option>
            </select>
            <button type="button" class="btn btn-sm btn-default" onclick="batchSetFeatured()">批量设推荐</button>
            <button type="button" class="btn btn-sm btn-default batch-move-btn" id="batchMoveBtn" onclick="startBatchMove(this)">批量移动</button>
            <button type="button" class="btn btn-sm btn-danger" onclick="confirmBatchDelete()">批量删除</button>
            <button type="button" class="btn btn-sm btn-default" onclick="clearSelection()">取消选择</button>
        </form>
      </div>
      <div class="toolbar-row" style="display:flex;gap:10px;align-items:center;margin:12px 0;flex-wrap:wrap;">
        <?php if ($dragMode): ?>
          <a href="<?php echo e($pageUrl); ?>" class="btn btn-sm btn-default">✅ 退出拖拽排序</a>
        <?php else: ?>
          <?php if ($filterCat !== -1): ?>
            <a href="<?php echo e($pageUrlDragOn); ?>" class="btn btn-sm btn-primary">🔀 拖拽排序</a>
            <span class="muted" style="font-size:12px;color:var(--text-muted);">进入后可拖拽整行调整顺序，仅当前分类生效</span>
          <?php else: ?>
            <button type="button" class="btn btn-sm btn-default" disabled title="请先在左侧选择一个具体分类">🔀 拖拽排序</button>
          <?php endif; ?>
        <?php endif; ?>
        <form method="post" action="" style="display:inline-flex;gap:6px;align-items:center;" onsubmit="var s=this.querySelector('select[name=category_id]');var t=s.options[s.selectedIndex].text;var c=this.querySelector('input[name=include_children]').checked;return confirm('确认对【'+t+(c?'（含子分类）':'')+'】范围内，URL 完全相同的书签仅保留一个吗？被移除的将移入回收站（可在回收站恢复）。');">
          <?php echo CSRF::field(); ?>
          <input type="hidden" name="action" value="dedupe">
          <select name="category_id" class="form-control" style="width:auto;display:inline-block;">
            <option value="0">全部书签（跨分类）</option>
            <?php echo render_category_options($catTree, 0); ?>
          </select>
          <label style="font-size:12px;color:var(--text-muted);display:inline-flex;align-items:center;gap:2px;margin:0;">
            <input type="checkbox" name="include_children" value="1"> 含子分类
          </label>
          <button type="submit" class="btn btn-sm btn-default">🔄 按分类去重</button>
        </form>
      </div>
      <?php if ($dragMode): ?>
      <div class="drag-hint">💡 当前为「分类」排序且已选中分类，可直接<strong>拖拽表格行</strong>调整书签顺序（仅在当前所选分类内生效，不影响其它分类）。</div>
      <?php else: ?>
      <div class="per-page-wrap">
        <span class="muted" style="font-size:13px;color:var(--text-muted);">共 <?php echo $total; ?> 个书签，每页 <?php echo $perPage; ?> 条</span>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
          <?php echo render_perpage_selector($keepParams, $perPage); ?>
          <?php echo render_pagination($pagination, $pageUrl); ?>
        </div>
      </div>
      <?php endif; ?>
      <div class="table-wrap"><table class="table" id="bmTable">
        <thead><tr><th style="width:36px;"><input type="checkbox" id="selectAll" title="全选"></th><th>标题</th><th>分类</th><th>URL</th><th>排序</th><th>状态</th><th>推荐</th><th>点击</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($bookmarks as $bm): ?>
        <tr data-bm-id="<?php echo (int)$bm['id']; ?>">
          <td><input type="checkbox" class="row-check" value="<?php echo (int)$bm['id']; ?>"></td>
          <td><span class="editable" data-field="title" data-id="<?php echo (int)$bm['id']; ?>" data-value="<?php echo e($bm['title']); ?>" title="<?php echo e($bm['title']); ?>" style="display:block;"><?php echo e($bm['title']); ?></span><?php if ($bm['description']): ?><br><span style="font-size:12px;color:var(--text-muted);"><?php echo e(truncate($bm['description'], 40)); ?></span><?php endif; ?></td>
          <td><?php echo e($bm['category_name'] ?: '-'); ?></td>
          <td style="max-width:220px;"><span class="editable" data-field="url" data-id="<?php echo (int)$bm['id']; ?>" data-value="<?php echo e($bm['url']); ?>" title="<?php echo e($bm['url']); ?>" style="display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo e($bm['url']); ?></span></td>
          <td class="editable" data-field="sort" data-id="<?php echo (int)$bm['id']; ?>" data-value="<?php echo (int)$bm['sort']; ?>" data-type="number" data-sort-cell><span class="edit-text"><?php echo (int)$bm['sort']; ?></span></td>
          <td class="editable" data-field="is_public" data-id="<?php echo (int)$bm['id']; ?>" data-value="<?php echo (int)$bm['is_public']; ?>" data-type="bool" title="双击切换公开/私有">
            <span class="badge <?php echo $bm['is_public'] ? 'badge-success' : 'badge-muted'; ?>"><?php echo $bm['is_public'] ? '公开' : '私有'; ?></span>
          </td>
          <td class="editable" data-field="is_featured" data-id="<?php echo (int)$bm['id']; ?>" data-value="<?php echo (int)($bm['is_featured'] ?? 0); ?>" data-type="bool" title="双击切换推荐位">
            <span class="badge <?php echo !empty($bm['is_featured']) ? 'badge-warning' : 'badge-muted'; ?>"><?php echo !empty($bm['is_featured']) ? '推荐' : '普通'; ?></span>
            <?php if (!empty($bm['is_featured']) && !empty($bm['featured_color'])): ?><span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:<?php echo e($bm['featured_color']); ?>;margin-left:6px;vertical-align:middle;"></span><?php endif; ?>
          </td>
          <td><?php echo (int)$bm['click_count']; ?></td>
          <td class="actions">
            <a href="<?php echo e($bm['url']); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-default">访问</a>
            <a href="?edit=<?php echo (int)$bm['id']; ?>" class="btn btn-sm btn-default">编辑</a>
            <form method="post" action="?delete=<?php echo (int)$bm['id']; ?>" style="display:inline;" onsubmit="return confirm('确定删除吗?');"><?php echo CSRF::field(); ?><button type="submit" class="btn btn-sm btn-danger">删除</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <style>
        .batch-bar-float{position:fixed;left:0;right:0;bottom:0;z-index:999;background:#fff;border-top:1px solid var(--border);box-shadow:0 -2px 8px rgba(0,0,0,.08);padding:10px 20px;display:none;justify-content:flex-start;gap:12px;align-items:center;}
      </style>
      <!-- 底部吸底批量操作栏 -->
      <div class="batch-bar batch-bar-float" id="batchBarBottom">
        <span class="batch-count">已选 <strong id="selectedCountBottom">0</strong> 项</span>
        <select name="target_category" class="form-control form-control-sm target-category" style="width:auto;display:none;">
          <option value="0">移到未分类</option>
          <?php echo render_category_options($catTree, 0); ?>
        </select>
        <button type="button" class="btn btn-sm btn-default batch-move-btn" onclick="startBatchMove(this)">批量移动</button>
        <button type="button" class="btn btn-sm btn-default" onclick="batchSetPublic()">批量设状态</button>
        <button type="button" class="btn btn-sm btn-default" onclick="batchSetFeatured()">批量设推荐</button>
        <button type="button" class="btn btn-sm btn-danger" onclick="confirmBatchDelete()">批量删除</button>
        <button type="button" class="btn btn-sm btn-default" onclick="clearSelection()">取消</button>
      </div>
      <?php if ($dragMode): ?>
      <div class="drag-hint">💡 当前为「分类」排序且已选中分类，可直接<strong>拖拽表格行</strong>调整书签顺序（仅在当前所选分类内生效，不影响其它分类）。</div>
      <?php else: ?>
      <div class="per-page-wrap">
        <span class="muted" style="font-size:13px;color:var(--text-muted);">共 <?php echo $total; ?> 个书签，每页 <?php echo $perPage; ?> 条</span>
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
          <?php echo render_perpage_selector($keepParams, $perPage); ?>
          <?php echo render_pagination($pagination, $pageUrl); ?>
        </div>
      </div>
      <?php endif; ?>
      <?php else: ?>
      <div class="empty-state"><div class="empty-icon">🔖</div><p>暂无系统书签</p><a href="<?php echo url('admin/bookmarks.php?action=add'); ?>" class="btn btn-primary">添加第一个书签</a></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function() {
  var selectAll = document.getElementById('selectAll');
  var rowChecks = document.querySelectorAll('.row-check');
  var batchBar = document.getElementById('batchBar');
  var batchBarBottom = document.getElementById('batchBarBottom');
  var selectedCount = document.getElementById('selectedCount');
  var selectedCountBottom = document.getElementById('selectedCountBottom');
  function updateBatchBar() {
    var checked = document.querySelectorAll('.row-check:checked');
    var count = checked.length;
    if (selectedCount) selectedCount.textContent = count;
    if (selectedCountBottom) selectedCountBottom.textContent = count;
    var show = count > 0 ? 'flex' : 'none';
    if (batchBar) batchBar.style.display = show;
    if (batchBarBottom) batchBarBottom.style.display = show;
  }
  if (selectAll) {
    selectAll.addEventListener('change', function() {
      rowChecks.forEach(function(cb) { cb.checked = selectAll.checked; });
      updateBatchBar();
    });
  }
  rowChecks.forEach(function(cb) {
    cb.addEventListener('change', function() {
      var allChecked = document.querySelectorAll('.row-check:checked').length === rowChecks.length;
      if (selectAll) selectAll.checked = allChecked;
      updateBatchBar();
    });
  });
  function clearSelection() {
    rowChecks.forEach(function(cb) { cb.checked = false; });
    if (selectAll) selectAll.checked = false;
    updateBatchBar();
    document.querySelectorAll('.batch-move-btn').forEach(function(btn) {
      btn.dataset.moveReady = '';
      btn.textContent = '批量移动';
      btn.classList.remove('btn-primary');
    });
    document.querySelectorAll('.target-category').forEach(function(sel) { sel.style.display = 'none'; });
  }
  function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(function(cb) { return cb.value; });
  }
  function startBatchMove(btn) {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    var bar = btn.closest('.batch-bar');
    var sel = bar ? bar.querySelector('select.target-category') : null;
    if (!btn.dataset.moveReady) {
      btn.dataset.moveReady = '1';
      btn.textContent = '确认移动';
      btn.classList.add('btn-primary');
      if (sel) { sel.style.display = 'inline-block'; sel.focus(); }
      return;
    }
    if (!sel) return;
    var targetName = sel.options[sel.selectedIndex].text.trim();
    if (!confirm('确定将选中的 ' + ids.length + ' 个书签移动到【' + targetName + '】吗?')) return;
    doBatchSubmit('move', sel);
  }
  function confirmBatchDelete() {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    if (!confirm('确定删除选中的 ' + ids.length + ' 个书签吗?')) return;
    doBatchSubmit('delete');
  }
  function doBatchSubmit(action, sel) {
    var ids = getSelectedIds();
    var form = document.getElementById('batchForm');
    document.getElementById('batchAction').value = action;
    form.querySelectorAll('input[name="ids[]"]').forEach(function(el) { el.remove(); });
    ids.forEach(function(id) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'ids[]';
      hidden.value = id;
      form.appendChild(hidden);
    });
    // 将所选分类同步到顶部表单的下拉(顶部表单负责提交),底部栏自带下拉亦不遗漏
    if (sel && document.getElementById('targetCategory')) {
      document.getElementById('targetCategory').value = sel.value;
    }
    form.submit();
  }

  // ========== 书签行内编辑(标题/URL/排序,双击) ==========
  var bmApiUrl = '<?php echo url('admin/api.php'); ?>';
  var bmCsrf = '<?php echo CSRF::token(); ?>';
  function bmSetCellText(cell, text) {
    cell.textContent = '';
    var span = document.createElement('span');
    span.className = 'edit-text';
    span.textContent = text;
    cell.appendChild(span);
  }
  function bmToast(msg, type) {
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + (type || 'success');
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function() { toast.classList.add('show'); }, 10);
    setTimeout(function() { toast.classList.remove('show'); setTimeout(function() { toast.remove(); }, 300); }, 2000);
  }
  document.querySelectorAll('#bmTable .editable').forEach(function(cell) {
    cell.addEventListener('dblclick', function() {
      if (cell.querySelector('input')) return;
      var field = cell.dataset.field;
      var id = cell.dataset.id;
      var type = cell.dataset.type || 'text';
      var currentValue = cell.dataset.value;
      if (type === 'bool') {
        var newVal = (currentValue === '1' || currentValue === 1 || currentValue === true) ? 0 : 1;
        toggleBool(cell, field, id, newVal);
        return;
      }
      var input = document.createElement('input');
      input.type = type;
      input.className = 'inline-input';
      input.value = currentValue;
      if (type === 'number') input.style.width = '70px';
      cell.textContent = '';
      cell.appendChild(input);
      input.focus();
      input.select();
      function save() {
        var newValue = input.value.trim();
        if (newValue === currentValue && type !== 'number') { cancel(); return; }
        if (field === 'sort' && newValue === '') { cancel(); return; }
        var fd = new FormData();
        fd.append('action', 'update_bookmark_field');
        fd.append('_csrf_token', bmCsrf);
        fd.append('id', id);
        fd.append('field', field);
        fd.append('value', newValue);
        fetch(bmApiUrl, { method: 'POST', credentials: 'same-origin', body: fd })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success) {
              var display = (res.data && res.data[field] !== undefined) ? res.data[field] : newValue;
              cell.dataset.value = display;
              bmSetCellText(cell, display);
              bmToast('已保存', 'success');
            } else {
              bmToast(res.message || '保存失败', 'danger');
              cancel();
            }
          })
          .catch(function() { bmToast('网络错误,保存失败', 'danger'); cancel(); });
      }
      function cancel() { bmSetCellText(cell, currentValue); }
      input.addEventListener('blur', save);
      input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { e.preventDefault(); cancel(); }
      });
    });
  });

  function renderStatusBadge(cell, val) {
    if (cell.dataset.field === 'is_featured') {
      cell.innerHTML = '<span class="badge ' + (val ? 'badge-warning' : 'badge-muted') + '">' + (val ? '推荐' : '普通') + '</span>';
    } else {
      cell.innerHTML = '<span class="badge ' + (val ? 'badge-success' : 'badge-muted') + '">' + (val ? '公开' : '私有') + '</span>';
    }
  }
  function toggleBool(cell, field, id, newVal) {
    var fd = new FormData();
    fd.append('action', 'update_bookmark_field');
    fd.append('_csrf_token', bmCsrf);
    fd.append('id', id);
    fd.append('field', field);
    fd.append('value', String(newVal));
    fetch(bmApiUrl, { method: 'POST', credentials: 'same-origin', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        if (res && res.success) {
          var val = (res.data && res.data[field] !== undefined) ? res.data[field] : newVal;
          cell.dataset.value = val ? '1' : '0';
          renderStatusBadge(cell, val ? 1 : 0);
          bmToast('已保存', 'success');
        } else {
          bmToast((res && res.message) ? res.message : '保存失败', 'danger');
        }
      })
      .catch(function() { bmToast('网络错误,保存失败', 'danger'); });
  }

  function batchSetPublic() {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    var sel = document.getElementById('batchPublicValue');
    var label = sel && sel.value === '1' ? '公开' : '私有';
    if (!confirm('确定将选中的 ' + ids.length + ' 个书签设为【' + label + '】吗?')) return;
    var form = document.getElementById('batchForm');
    form.querySelectorAll('input[name="ids[]"]').forEach(function(el) { el.remove(); });
    ids.forEach(function(id) {
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = 'ids[]'; h.value = id; form.appendChild(h);
    });
    document.getElementById('batchAction').value = 'set_public';
    form.submit();
  }

  function batchSetFeatured() {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    var sel = document.getElementById('batchFeaturedValue');
    var label = sel && sel.value === '1' ? '推荐位' : '普通';
    if (!confirm('确定将选中的 ' + ids.length + ' 个书签设为【' + label + '】吗?')) return;
    var form = document.getElementById('batchForm');
    form.querySelectorAll('input[name="ids[]"]').forEach(function(el) { el.remove(); });
    ids.forEach(function(id) {
      var h = document.createElement('input');
      h.type = 'hidden'; h.name = 'ids[]'; h.value = id; form.appendChild(h);
    });
    document.getElementById('batchAction').value = 'set_featured';
    form.submit();
  }

  window.startBatchMove = startBatchMove;
  window.confirmBatchDelete = confirmBatchDelete;
  window.clearSelection = clearSelection;
  window.batchSetPublic = batchSetPublic;
  window.batchSetFeatured = batchSetFeatured;
})();
</script>
<?php if ($dragMode): ?>
<script src="<?php echo e(asset('js/sortable.min.js')); ?>"></script>
<script>
(function () {
  if (typeof Sortable === 'undefined') return;
  var tbody = document.querySelector('#bmTable tbody');
  if (!tbody) return;
  var apiUrl = '<?php echo url('admin/api.php'); ?>';
  var csrf = '<?php echo CSRF::token(); ?>';
  var catId = <?php echo (int)$filterCat; ?>;
  var busy = false;
  var originalOrder = [];

  function toastMsg(msg, type) {
    var t = document.createElement('div');
    t.className = 'toast toast-' + (type || 'success');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('show'); }, 10);
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 2200);
  }
  function restoreOrder() {
    originalOrder.forEach(function (o) {
      var row = tbody.querySelector('tr[data-bm-id="' + o.id + '"]');
      if (row) tbody.appendChild(row);
      if (o.sort !== '') {
        var c = row && row.querySelector('td[data-sort-cell] .edit-text');
        if (c) c.textContent = o.sort;
      }
    });
  }

  var sortable = Sortable.create(tbody, {
    animation: 150,
    ghostClass: 'drag-ghost',
    onStart: function () {
      originalOrder = [];
      tbody.querySelectorAll('tr[data-bm-id]').forEach(function (r) {
        var numCell = r.querySelector('td[data-sort-cell] .edit-text');
        originalOrder.push({ id: r.getAttribute('data-bm-id'), sort: numCell ? numCell.textContent : '' });
      });
    },
    onEnd: function () {
      if (busy) return;
      var rows = tbody.querySelectorAll('tr[data-bm-id]');
      if (rows.length < 2) return;
      var ids = [];
      rows.forEach(function (r) { ids.push(r.getAttribute('data-bm-id')); });
      var changed = ids.length !== originalOrder.length || ids.some(function (id, i) { return id !== originalOrder[i].id; });
      if (!changed) return;
      busy = true;
      sortable.option('disabled', true);
      tbody.style.opacity = '0.55';
      toastMsg('保存中…', 'info');
      var fd = new FormData();
      fd.append('action', 'update_bookmark_order');
      fd.append('_csrf_token', csrf);
      fd.append('cat_id', String(catId));
      ids.forEach(function (id) { fd.append('ids[]', id); });
      fetch(apiUrl, { method: 'POST', credentials: 'same-origin', body: fd })
        .then(function (r) {
          if (!r.ok) throw new Error('http_' + r.status);
          return r.text();
        })
        .then(function (text) {
          var res;
          try { res = JSON.parse(text); } catch (e) { throw new Error('bad_json'); }
          if (res && res.success) {
            rows.forEach(function (r, i) {
              var cell = r.querySelector('td[data-sort-cell] .edit-text');
              if (cell) cell.textContent = String(i + 1);
            });
            toastMsg('排序已保存', 'success');
          } else {
            throw new Error((res && res.message) ? res.message : 'server_error');
          }
        })
        .catch(function (err) {
          restoreOrder();
          var msg = (err && err.message && err.message.indexOf('http_') === 0) ? '服务器错误，保存失败'
                  : (err && err.message === 'bad_json') ? '服务器返回异常，保存失败'
                  : (err && err.message) ? err.message
                  : '网络错误，保存失败';
          toastMsg(msg, 'danger');
        })
        .then(function () {
          busy = false;
          sortable.option('disabled', false);
          tbody.style.opacity = '1';
        });
    }
  });
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../includes/footer.php'; ?>
