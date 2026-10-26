<?php
/**
 * 后台管理 - 系统分类管理(网址大全, user_id=0, 无限级)
 * v1.3.4: 支持批量删除/批量移动/双击行内编辑
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
$adminPage = 'categories';
$pageTitle = '分类管理(网址大全)';
$currentPage = 'admin';
$message = '';
$messageType = '';

require_once __DIR__ . '/../includes/categories_actions.php';

// ========== 分类操作（增删改 / 批量，前后台共用逻辑见 includes/categories_actions.php） ==========
if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $res = cat_single_delete((int)$_GET['delete'], 0, true);
    if ($res) { $message = $res['message']; $messageType = $res['type']; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'delete') {
    CSRF::check();
    $res = cat_batch_delete($_POST['ids'] ?? [], 0, true);
    $message = $res['message']; $messageType = $res['type'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'move') {
    CSRF::check();
    $res = cat_batch_move($_POST['ids'] ?? [], (int)($_POST['target_parent'] ?? 0), 0, true);
    $message = $res['message']; $messageType = $res['type'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    CSRF::check();
    $res = cat_save(0, true, $_POST);
    if ($res) { $message = $res['message']; $messageType = $res['type']; }
}

$editCat = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editCat = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = 0 AND deleted_at IS NULL", [$editId]);
}

$allCats = DB::fetchAll("SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = 0 AND deleted_at IS NULL ORDER BY sort ASC, id ASC");
// 统计每个分类下的书签数 / 页面(便签)数(仅直属,不递归子分类)
$bmCountMap = [];
foreach (DB::fetchAll("SELECT category_id, COUNT(*) AS cnt FROM " . DB_PREFIX . "bookmarks WHERE user_id = 0 AND deleted_at IS NULL GROUP BY category_id") as $row) {
    $bmCountMap[(int)$row['category_id']] = (int)$row['cnt'];
}
$pageCountMap = [];
foreach (DB::fetchAll("SELECT category_id, COUNT(*) AS cnt FROM " . DB_PREFIX . "pages WHERE user_id = 0 AND deleted_at IS NULL GROUP BY category_id") as $row) {
    $pageCountMap[(int)$row['category_id']] = (int)$row['cnt'];
}
foreach ($allCats as &$c) {
    $cid = (int)$c['id'];
    $c['bookmark_count'] = $bmCountMap[$cid] ?? 0;
    $c['page_count'] = $pageCountMap[$cid] ?? 0;
}
unset($c);
$catTree = build_category_tree($allCats);

// 扁平化分类列表(用于批量移动的目标选择)
$flatCats = [];
function flatten_admin_cats($tree, $level, &$result) {
    foreach ($tree as $cat) {
        $result[] = ['id' => (int)$cat['id'], 'name' => $cat['name'], 'level' => $level];
        flatten_admin_cats($cat['children'], $level + 1, $result);
    }
}
flatten_admin_cats($catTree, 0, $flatCats);

include __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="admin-main">
    <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div><?php endif; ?>
    <?php if (isset($_GET['action']) && $_GET['action'] === 'add' || $editCat): ?>
    <div class="card">
      <div class="card-header"><h3><?php echo $editCat ? '编辑分类' : '添加分类(网址大全)'; ?></h3>
      <a href="<?php echo url('admin/categories.php'); ?>" class="btn btn-sm btn-default">返回列表</a></div>
      <form method="post" action="">
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="save_category" value="1">
        <input type="hidden" name="id" value="<?php echo $editCat ? (int)$editCat['id'] : 0; ?>">
        <div class="form-row">
          <div class="form-group"><label>分类名称 *</label><input type="text" name="name" class="form-control" required value="<?php echo e($editCat['name'] ?? ''); ?>"></div>
          <div class="form-group"><label>父分类</label><select name="parent_id" class="form-control"><option value="0">顶级分类</option><?php echo render_category_options($catTree, $editCat['parent_id'] ?? 0, 0, $editCat['id'] ?? 0); ?></select></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>图标(emoji)</label><input type="text" name="icon" class="form-control" placeholder="如: 📁" value="<?php echo e($editCat['icon'] ?? ''); ?>"></div>
          <div class="form-group"><label>排序</label><input type="number" name="sort" class="form-control" value="<?php echo (int)($editCat['sort'] ?? 0); ?>"></div>
        </div>
        <div class="form-group"><label>描述</label><input type="text" name="description" class="form-control" value="<?php echo e($editCat['description'] ?? ''); ?>"></div>
        <div class="form-group"><div class="form-check"><input type="checkbox" name="is_public" id="cat_is_public" <?php echo (!isset($editCat) || $editCat['is_public']) ? 'checked' : ''; ?>><label for="cat_is_public">公开显示在网址大全</label></div></div>
        <button type="submit" class="btn btn-primary"><?php echo $editCat ? '保存修改' : '添加分类'; ?></button>
        <a href="<?php echo url('admin/categories.php'); ?>" class="btn btn-default">取消</a>
      </form>
    </div>
    <?php else: ?>
    <div class="card">
      <div class="card-header"><h3>系统分类列表</h3>
      <a href="<?php echo url('admin/categories.php?action=add'); ?>" class="btn btn-sm btn-primary">+ 添加分类</a></div>
      <?php if ($catTree): ?>
      <!-- 批量操作栏 -->
      <div class="batch-bar" id="batchBar" style="display:none;">
        <span class="batch-count">已选 <strong id="selectedCount">0</strong> 项</span>
        <form method="post" action="" style="display:inline-flex;gap:8px;align-items:center;" id="batchForm">
          <?php echo CSRF::field(); ?>
          <input type="hidden" name="batch_action" id="batchAction" value="">
          <select name="target_parent" id="targetParent" class="form-control form-control-sm" style="width:auto;display:none;">
            <option value="0">移到顶级分类</option>
            <?php foreach ($flatCats as $fc): ?>
            <option value="<?php echo $fc['id']; ?>"><?php echo str_repeat('　', $fc['level']) . e($fc['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-sm btn-default" id="batchMoveBtn" onclick="startBatchMove(this)">批量移动</button>
          <button type="button" class="btn btn-sm btn-danger" onclick="confirmBatchDelete()">批量删除</button>
          <button type="button" class="btn btn-sm btn-default" onclick="clearSelection()">取消选择</button>
        </form>
      </div>
      <div class="table-wrap">
      <table class="table" id="catTable">
        <thead>
          <tr>
            <th style="width:36px;"><input type="checkbox" id="selectAll" title="全选"></th>
            <th>分类名称 <span class="hint-text">(双击编辑)</span></th>
            <th>图标 <span class="hint-text">(双击编辑)</span></th>
            <th>状态 <span class="hint-text">(双击切换)</span></th>
            <th>排序 <span class="hint-text">(双击编辑)</span></th>
            <th>数量</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
        <?php
        function render_admin_cat_rows_v2($tree, $level) {
            $html = '';
            foreach ($tree as $cat) {
                $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
                $catId = (int)$cat['id'];
                $html .= '<tr data-id="' . $catId . '">';
                $html .= '<td><input type="checkbox" class="row-check" value="' . $catId . '"></td>';
                $html .= '<td class="editable" data-field="name" data-id="' . $catId . '" data-value="' . e($cat['name']) . '">';
                $html .= $indent . '<span class="edit-text">' . e($cat['name']) . '</span>';
                $html .= '</td>';
                $html .= '<td class="editable" data-field="icon" data-id="' . $catId . '" data-value="' . e($cat['icon']) . '">';
                $html .= '<span class="edit-text">' . e($cat['icon'] ?: '-') . '</span>';
                $html .= '</td>';
                $html .= '<td class="editable-toggle" data-field="is_public" data-id="' . $catId . '" data-value="' . (int)$cat['is_public'] . '">';
                if ($cat['is_public']) {
                    $html .= '<span class="badge badge-success">公开</span>';
                } else {
                    $html .= '<span class="badge badge-muted">隐藏</span>';
                }
                $html .= '</td>';
                $html .= '<td class="editable" data-field="sort" data-id="' . $catId . '" data-value="' . (int)$cat['sort'] . '" data-type="number">';
                $html .= '<span class="edit-text">' . (int)$cat['sort'] . '</span>';
                $html .= '</td>';
                $html .= '<td class="count-cell">书签 ' . (int)($cat['bookmark_count'] ?? 0) . ' · 页面 ' . (int)($cat['page_count'] ?? 0) . '</td>';
                $html .= '<td class="actions">';
                $html .= '<a href="' . url('category.php?param=' . $catId) . '" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-default">访问</a>';
                $html .= '<a href="?edit=' . $catId . '" class="btn btn-sm btn-default">编辑</a>';
                $html .= '<form method="post" action="?delete=' . $catId . '" style="display:inline;" onsubmit="return confirm(\'确定删除该分类吗?分类下的书签将移到未分类\');">' . CSRF::field() . '<button type="submit" class="btn btn-sm btn-danger">删除</button></form>';
                $html .= '</td></tr>';
                $html .= render_admin_cat_rows_v2($cat['children'], $level + 1);
            }
            return $html;
        }
        echo render_admin_cat_rows_v2($catTree, 0);
        ?>
        </tbody>
      </table>
      </div>
      <?php else: ?>
      <div class="empty-state"><div class="empty-icon">📁</div><p>暂无分类</p><a href="<?php echo url('admin/categories.php?action=add'); ?>" class="btn btn-primary">添加第一个分类</a></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<script>
(function() {
  var apiUrl = '<?php echo url('admin/api.php'); ?>';
  var csrfToken = '<?php echo CSRF::token(); ?>';
  var csrfKey = '_csrf_token';

  // 用 textContent 安全回显,避免存储型 XSS(分类名/图标等用户输入)
  function setEditCellText(cell, text) {
    cell.textContent = '';
    var span = document.createElement('span');
    span.className = 'edit-text';
    span.textContent = text;
    cell.appendChild(span);
  }

  // ========== 双击行内编辑(文本/数字) ==========
  document.querySelectorAll('.editable').forEach(function(cell) {
    cell.addEventListener('dblclick', function() {
      if (cell.querySelector('input')) return;
      var field = cell.dataset.field;
      var id = cell.dataset.id;
      var type = cell.dataset.type || 'text';
      var currentValue = cell.dataset.value;
      var input = document.createElement('input');
      input.type = type;
      input.className = 'inline-input';
      input.value = currentValue;
      if (type === 'number') input.style.width = '70px';
      cell.innerHTML = '';
      cell.appendChild(input);
      input.focus();
      input.select();
      function save() {
        var newValue = input.value.trim();
        if (newValue === currentValue && type !== 'number') { cancel(); return; }
        var fd = new FormData();
        fd.append('action', 'update_category_field');
        fd.append(csrfKey, csrfToken);
        fd.append('id', id);
        fd.append('field', field);
        fd.append('value', newValue);
        fetch(apiUrl, { method: 'POST', body: fd })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success) {
              cell.dataset.value = res.data[field] !== undefined ? res.data[field] : newValue;
              var display = res.data[field] !== undefined ? res.data[field] : newValue;
              if (field === 'icon' && display === '') display = '-';
              setEditCellText(cell, display);
              showToast('已保存', 'success');
            } else {
              showToast(res.message || '保存失败', 'danger');
              cancel();
            }
          })
          .catch(function() { showToast('网络错误,保存失败', 'danger'); cancel(); });
      }
      function cancel() {
        var display = currentValue;
        if (field === 'icon' && display === '') display = '-';
        setEditCellText(cell, display);
      }
      input.addEventListener('blur', save);
      input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
        if (e.key === 'Escape') { e.preventDefault(); cancel(); }
      });
    });
  });

  // ========== 双击切换状态(公开/隐藏) ==========
  document.querySelectorAll('.editable-toggle').forEach(function(cell) {
    cell.style.cursor = 'pointer';
    cell.title = '双击切换公开/隐藏';
    cell.addEventListener('dblclick', function() {
      var id = cell.dataset.id;
      var current = cell.dataset.value;
      var newValue = current == 1 ? 0 : 1;
      var fd = new FormData();
      fd.append('action', 'update_category_field');
      fd.append(csrfKey, csrfToken);
      fd.append('id', id);
      fd.append('field', 'is_public');
      fd.append('value', newValue);
      fetch(apiUrl, { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(res) {
          if (res.success) {
            cell.dataset.value = res.data.is_public;
            if (res.data.is_public == 1) {
              cell.innerHTML = '<span class="badge badge-success">公开</span>';
            } else {
              cell.innerHTML = '<span class="badge badge-muted">隐藏</span>';
            }
            showToast('已切换为' + (res.data.is_public == 1 ? '公开' : '隐藏'), 'success');
          } else {
            showToast(res.message || '操作失败', 'danger');
          }
        })
        .catch(function() { showToast('网络错误', 'danger'); });
    });
  });

  // ========== 批量选择 ==========
  var selectAll = document.getElementById('selectAll');
  var rowChecks = document.querySelectorAll('.row-check');
  var batchBar = document.getElementById('batchBar');
  var selectedCount = document.getElementById('selectedCount');
  var targetParent = document.getElementById('targetParent');
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
      targetParent.style.display = 'inline-block';
      targetParent.focus();
      return;
    }
    var sel = targetParent;
    var targetName = sel.options[sel.selectedIndex].text.trim();
    if (!confirm('确定将选中的 ' + ids.length + ' 个分类移动到【' + targetName + '】吗?')) return;
    doBatchSubmit('move');
  }
  function confirmBatchDelete() {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    if (!confirm('确定删除选中的 ' + ids.length + ' 个分类吗？\n该分类及其子分类、书签、页面将一并移入回收站。')) return;
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

  // ========== Toast 提示 ==========
  function showToast(msg, type) {
    var toast = document.createElement('div');
    toast.className = 'toast toast-' + (type || 'success');
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function() { toast.classList.add('show'); }, 10);
    setTimeout(function() {
      toast.classList.remove('show');
      setTimeout(function() { toast.remove(); }, 300);
    }, 2000);
  }

  window.startBatchMove = startBatchMove;
  window.confirmBatchDelete = confirmBatchDelete;
  window.clearSelection = clearSelection;
})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
