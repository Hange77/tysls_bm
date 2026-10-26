<?php
/**
 * 后台管理 - 页面管理(HTML页面,如关于页、公告页、公司文化等)
 * 这些页面 user_id=0, content_type=html, 可写任意HTML
 * v1.3.4: 支持批量删除/批量移动(改变所属分类)
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
$adminPage = 'pages';
$pageTitle = '页面管理';
$currentPage = 'admin';
$message = '';
$messageType = '';

// 处理删除
if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $id = (int)$_GET['delete'];
    $page = DB::fetch("SELECT * FROM " . DB_PREFIX . "pages WHERE id = ? AND user_id = 0", [$id]);
    if ($page) {
        recycle_soft_delete(DB_PREFIX . 'pages', $id, 0, true);
        log_operation('delete', 'page', $id, '删除页面: ' . $page['title']);
        $message = '页面已移入回收站';
        $messageType = 'success';
    }
}

// ========== 批量删除 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'delete') {
    CSRF::check();
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        $message = '请先选择要删除的页面';
        $messageType = 'danger';
    } else {
        $deleted = 0;
        foreach ($ids as $rawId) {
            $id = (int)$rawId;
            $page = DB::fetch("SELECT title FROM " . DB_PREFIX . "pages WHERE id = ? AND user_id = 0", [$id]);
            if (!$page) continue;
            recycle_soft_delete(DB_PREFIX . 'pages', $id, 0, true);
            log_operation('delete', 'page', $id, '批量删除页面: ' . $page['title']);
            $deleted++;
        }
        $message = "已将 {$deleted} 个页面移入回收站";
        $messageType = 'success';
    }
}

// ========== 批量移动(改变所属分类) ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'move') {
    CSRF::check();
    $ids = $_POST['ids'] ?? [];
    $targetCat = (int)($_POST['target_category'] ?? 0);
    if (!is_array($ids) || empty($ids)) {
        $message = '请先选择要移动的页面';
        $messageType = 'danger';
    } else {
        if ($targetCat > 0) {
            $cat = DB::fetch("SELECT id FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = 0", [$targetCat]);
            if (!$cat) $targetCat = 0;
        }
        $moved = 0;
        foreach ($ids as $rawId) {
            $id = (int)$rawId;
            $page = DB::fetch("SELECT id FROM " . DB_PREFIX . "pages WHERE id = ? AND user_id = 0", [$id]);
            if (!$page) continue;
            DB::update(DB_PREFIX . 'pages', ['category_id' => $targetCat], 'id = :id AND user_id = 0', [':id' => $id]);
            log_operation('update', 'page', $id, '批量移动页面到分类 ' . $targetCat);
            $moved++;
        }
        $catName = $targetCat > 0 ? '指定分类' : '未分类';
        $message = "已移动 {$moved} 个页面到{$catName}";
        $messageType = 'success';
    }
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_page'])) {
    CSRF::check();
    $id = (int)($_POST['id'] ?? 0);
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    // 禁止纯数字slug（避免与ID冲突），纯数字时自动添加前缀
    if ($slug !== '' && ctype_digit($slug)) {
        $slug = 'page-' . $slug;
    }
    $content = $_POST['content'] ?? '';
    $isPublic = isset($_POST['is_public']) ? 1 : 0;
    $password = trim($_POST['password'] ?? '');
    if (!$title || !$content) {
        $message = '标题和内容不能为空';
        $messageType = 'danger';
    } else {
        if ($categoryId > 0) {
            $cat = DB::fetch("SELECT id FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = 0", [$categoryId]);
            if (!$cat) $categoryId = 0;
        }
        $data = [
            'category_id' => $categoryId,
            'title' => $title,
            'slug' => $slug ?: make_slug($title),
            'content' => $content,
            'content_type' => 'html',
            'is_public' => $isPublic,
        ];
        if ($password !== '') {
            $data['password'] = password_hash($password, PASSWORD_DEFAULT);
        }
        if ($id > 0) {
            DB::update(DB_PREFIX . 'pages', $data, 'id = :id AND user_id = 0', [':id' => $id]);
            log_operation('update', 'page', $id, '编辑页面: ' . $title);
            $message = '页面已更新';
        } else {
            $data['user_id'] = 0;
            $newId = DB::insert(DB_PREFIX . 'pages', $data);
            log_operation('create', 'page', $newId, '添加页面: ' . $title);
            $message = '页面已添加';
        }
        $messageType = 'success';
        redirect(url('admin/pages.php'));
    }
}

$editPage = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editPage = DB::fetch("SELECT * FROM " . DB_PREFIX . "pages WHERE id = ? AND user_id = 0 AND deleted_at IS NULL", [$editId]);
}

$sysCats = DB::fetchAll("SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = 0 AND deleted_at IS NULL ORDER BY sort ASC, id ASC");
$catTree = build_category_tree($sysCats);

$pages = DB::fetchAll(
    "SELECT p.*, c.name as category_name FROM " . DB_PREFIX . "pages p 
     LEFT JOIN " . DB_PREFIX . "categories c ON p.category_id = c.id 
     WHERE p.user_id = 0 AND p.deleted_at IS NULL ORDER BY p.created_at DESC"
);

include __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="admin-main">
    <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div><?php endif; ?>
    <?php if (isset($_GET['action']) && $_GET['action'] === 'add' || $editPage): ?>
    <div class="card">
      <div class="card-header"><h3><?php echo $editPage ? '编辑页面' : '添加页面(HTML)'; ?></h3>
      <a href="<?php echo url('admin/pages.php'); ?>" class="btn btn-sm btn-default">返回列表</a></div>
      <form method="post" action="">
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="save_page" value="1">
        <input type="hidden" name="id" value="<?php echo $editPage ? (int)$editPage['id'] : 0; ?>">
        <div class="form-row">
          <div class="form-group"><label>页面标题 *</label><input type="text" name="title" class="form-control" required value="<?php echo e($editPage['title'] ?? ''); ?>"></div>
          <div class="form-group"><label>URL别名(slug)</label><input type="text" name="slug" class="form-control" placeholder="留空自动生成" value="<?php echo e($editPage['slug'] ?? ''); ?>"></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>所属分类</label><select name="category_id" class="form-control"><option value="0">未分类</option><?php echo render_category_options($catTree, $editPage['category_id'] ?? 0); ?></select></div>
          <div class="form-group"><label>访问密码(留空无密码)</label><input type="text" name="password" class="form-control"><?php if ($editPage && $editPage['password']): ?><div class="form-hint">当前已设置密码,留空不修改</div><?php endif; ?></div>
        </div>
        <div class="form-group">
          <label>页面内容 * (支持任意HTML,可用于关于页、公告页、公司文化等)</label>
          <textarea name="content" class="form-control" rows="16" required placeholder="<h1>关于我们</h1><p>这里是页面内容...</p>" style="font-family:monospace;font-size:13px;"><?php echo $editPage['content'] ?? ''; ?></textarea>
        </div>
        <div class="form-group"><div class="form-check"><input type="checkbox" name="is_public" id="page_is_public" <?php echo (!isset($editPage) || $editPage['is_public']) ? 'checked' : ''; ?>><label for="page_is_public">公开显示</label></div></div>
        <button type="submit" class="btn btn-primary"><?php echo $editPage ? '保存修改' : '添加页面'; ?></button>
        <a href="<?php echo url('admin/pages.php'); ?>" class="btn btn-default">取消</a>
      </form>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-header"><h3>页面列表 (共 <?php echo count($pages); ?> 个)</h3>
      <a href="<?php echo url('admin/pages.php?action=add'); ?>" class="btn btn-sm btn-primary">+ 添加页面</a></div>
      <?php if ($pages): ?>
      <!-- 批量操作栏 -->
      <div class="batch-bar" id="batchBar" style="display:none;">
        <span class="batch-count">已选 <strong id="selectedCount">0</strong> 项</span>
        <form method="post" action="" style="display:inline-flex;gap:8px;align-items:center;" id="batchForm">
          <?php echo CSRF::field(); ?>
          <input type="hidden" name="batch_action" id="batchAction" value="">
          <select name="target_category" id="targetCategory" class="form-control form-control-sm" style="width:auto;display:none;">
            <option value="0">移到未分类</option>
            <?php echo render_category_options($catTree, 0); ?>
          </select>
          <button type="button" class="btn btn-sm btn-default" id="batchMoveBtn" onclick="startBatchMove(this)">批量移动</button>
          <button type="button" class="btn btn-sm btn-danger" onclick="confirmBatchDelete()">批量删除</button>
          <button type="button" class="btn btn-sm btn-default" onclick="clearSelection()">取消选择</button>
        </form>
      </div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th style="width:36px;"><input type="checkbox" id="selectAll" title="全选"></th><th>标题</th><th>分类</th><th>Slug</th><th>状态</th><th>浏览</th><th>更新时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($pages as $p): ?>
        <tr>
          <td><input type="checkbox" class="row-check" value="<?php echo (int)$p['id']; ?>"></td>
          <td><?php echo e($p['title']); ?></td>
          <td><?php echo e($p['category_name'] ?: '-'); ?></td>
          <td><?php echo e($p['slug'] ?: '-'); ?></td>
          <td><?php echo $p['is_public'] ? '<span class="badge badge-success">公开</span>' : '<span class="badge badge-muted">隐藏</span>'; ?><?php echo $p['password'] ? ' <span class="badge badge-warning">有密码</span>' : ''; ?></td>
          <td><?php echo (int)$p['view_count']; ?></td>
          <td><?php echo e(date('Y-m-d H:i', strtotime($p['updated_at']))); ?></td>
          <td class="actions">
            <a href="<?php echo page_url($p); ?>" target="_blank" class="btn btn-sm btn-default">查看</a>
            <a href="?edit=<?php echo (int)$p['id']; ?>" class="btn btn-sm btn-default">编辑</a>
            <form method="post" action="?delete=<?php echo (int)$p['id']; ?>" style="display:inline;" onsubmit="return confirm('确定删除该页面吗?');"><?php echo CSRF::field(); ?><button type="submit" class="btn btn-sm btn-danger">删除</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?>
      <div class="empty-state"><div class="empty-icon">📄</div><p>暂无页面</p><a href="<?php echo url('admin/pages.php?action=add'); ?>" class="btn btn-primary">添加第一个页面</a></div>
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
  var selectedCount = document.getElementById('selectedCount');
  var targetCategory = document.getElementById('targetCategory');
  function updateBatchBar() {
    var checked = document.querySelectorAll('.row-check:checked');
    var count = checked.length;
    selectedCount.textContent = count;
    batchBar.style.display = count > 0 ? 'flex' : 'none';
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
    var btn = document.getElementById('batchMoveBtn');
    if (btn) { btn.dataset.moveReady = ''; btn.textContent = '批量移动'; btn.classList.remove('btn-primary'); }
  }
  function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(function(cb) { return cb.value; });
  }
  function startBatchMove(btn) {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    if (!btn.dataset.moveReady) {
      btn.dataset.moveReady = '1';
      btn.textContent = '确认移动';
      btn.classList.add('btn-primary');
      targetCategory.style.display = 'inline-block';
      targetCategory.focus();
      return;
    }
    var sel = targetCategory;
    var targetName = sel.options[sel.selectedIndex].text.trim();
    if (!confirm('确定将选中的 ' + ids.length + ' 个页面移动到【' + targetName + '】吗?')) return;
    doBatchSubmit('move');
  }
  function confirmBatchDelete() {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    if (!confirm('确定删除选中的 ' + ids.length + ' 个页面吗?')) return;
    doBatchSubmit('delete');
  }
  function doBatchSubmit(action) {
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
    form.submit();
  }
  window.startBatchMove = startBatchMove;
  window.confirmBatchDelete = confirmBatchDelete;
  window.clearSelection = clearSelection;
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
