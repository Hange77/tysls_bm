<?php
/**
 * 用户中心 - 分类管理(无限级)
 * v1.3: 支持双击行内编辑 + 批量删除/批量移动
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
$userId = Auth::id();
$userPage = 'categories';
$pageTitle = '分类管理';
$currentPage = 'user';
$message = '';
$messageType = '';

require_once __DIR__ . '/../includes/categories_actions.php';

if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $res = cat_single_delete((int)$_GET['delete'], $userId, false);
    if ($res) { $message = $res['message']; $messageType = $res['type']; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'delete') {
    CSRF::check();
    $res = cat_batch_delete($_POST['ids'] ?? [], $userId, false);
    $message = $res['message']; $messageType = $res['type'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'move') {
    CSRF::check();
    $res = cat_batch_move($_POST['ids'] ?? [], (int)($_POST['target_parent'] ?? 0), $userId, false);
    $message = $res['message']; $messageType = $res['type'];
}

$showForm = false;
$form = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_category'])) {
    CSRF::check();
    $res = cat_save($userId, false, $_POST, ['password' => true]);
    if ($res) {
        $message = $res['message']; $messageType = $res['type'];
        $showForm = true; $form = $_POST;
    }
}

// 编辑模式
$editCat = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editCat = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = ? AND deleted_at IS NULL", [$editId, $userId]);
}

// 获取所有分类并构建树
$allCats = DB::fetchAll(
    "SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = ? AND deleted_at IS NULL ORDER BY sort ASC, id ASC",
    [$userId]
);
// 统计每个分类下的书签数 / 页面(便签)数(仅直属,不递归子分类)
$bmCountMap = [];
foreach (DB::fetchAll("SELECT category_id, COUNT(*) AS cnt FROM " . DB_PREFIX . "bookmarks WHERE user_id = ? AND deleted_at IS NULL GROUP BY category_id", [$userId]) as $row) {
    $bmCountMap[(int)$row['category_id']] = (int)$row['cnt'];
}
$pageCountMap = [];
foreach (DB::fetchAll("SELECT category_id, COUNT(*) AS cnt FROM " . DB_PREFIX . "pages WHERE user_id = ? AND deleted_at IS NULL GROUP BY category_id", [$userId]) as $row) {
    $pageCountMap[(int)$row['category_id']] = (int)$row['cnt'];
}
foreach ($allCats as &$c) {
    $cid = (int)$c['id'];
    $c['bookmark_count'] = $bmCountMap[$cid] ?? 0;
    $c['page_count'] = $pageCountMap[$cid] ?? 0;
}
unset($c);
$catTree = build_category_tree($allCats);

// 扁平化分类列表(用于批量移动的目标选择,排除选中项及其子分类)
$flatCats = [];
function flatten_cats($tree, $level, &$result) {
    foreach ($tree as $cat) {
        $result[] = ['id' => (int)$cat['id'], 'name' => $cat['name'], 'level' => $level];
        flatten_cats($cat['children'], $level + 1, $result);
    }
}
flatten_cats($catTree, 0, $flatCats);

include __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="user-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="user-main">
      <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
      <?php endif; ?>

      <?php
      $selParent = (int)($form['parent_id'] ?? ($editCat['parent_id'] ?? 0));
      $catPublicChecked = $editCat ? (bool)$editCat['is_public'] : (!empty($form) ? isset($form['is_public']) : setting('default_bookmark_public','1')==='1');
      ?>
      <?php if ($showForm || isset($_GET['action']) && $_GET['action'] === 'add' || $editCat): ?>
      <!-- 添加/编辑表单 -->
      <div class="card">
        <div class="card-header">
          <h3><?php echo $editCat ? '编辑分类' : '添加分类'; ?></h3>
          <a href="<?php echo url('user/categories.php'); ?>" class="btn btn-sm btn-default">返回列表</a>
        </div>
        <form method="post" action="">
          <?php echo CSRF::field(); ?>
          <input type="hidden" name="save_category" value="1">
          <input type="hidden" name="id" value="<?php echo $editCat ? (int)$editCat['id'] : 0; ?>">
          <div class="form-row">
            <div class="form-group">
              <label>分类名称 *</label>
              <input type="text" name="name" class="form-control" required value="<?php echo e($form['name'] ?? ($editCat['name'] ?? '')); ?>">
            </div>
            <div class="form-group">
              <label>父分类</label>
              <select name="parent_id" class="form-control">
                <option value="0">顶级分类</option>
                <?php echo render_category_options($catTree, $selParent, 0, $editCat['id'] ?? 0); ?>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>图标(emoji)</label>
              <input type="text" name="icon" class="form-control" placeholder="如: 📁" value="<?php echo e($form['icon'] ?? ($editCat['icon'] ?? '')); ?>">
            </div>
            <div class="form-group">
              <label>排序(小的在前)</label>
              <input type="number" name="sort" class="form-control" value="<?php echo (int)($form['sort'] ?? ($editCat['sort'] ?? 0)); ?>">
            </div>
          </div>
          <div class="form-group">
            <label>描述</label>
            <input type="text" name="description" class="form-control" value="<?php echo e($form['description'] ?? ($editCat['description'] ?? '')); ?>">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>访问密码(留空表示无密码)</label>
              <input type="text" name="password" class="form-control" placeholder="<?php echo ($editCat && $editCat['password']) ? '已设置密码,留空不修改,输入新密码则修改' : '设置后访客需输入密码才能查看该分类'; ?>">
              <?php if ($editCat && $editCat['password']): ?>
              <div class="form-hint" style="color:#fa8c16;font-weight:500;">🔒 当前已设置密码,留空不修改,输入新密码则覆盖</div>
              <?php endif; ?>
            </div>
            <div class="form-group">
              <label>&nbsp;</label>
              <div class="form-check">
                <input type="checkbox" name="is_public" id="cat_is_public" <?php echo $catPublicChecked ? 'checked' : ''; ?>>
                <label for="cat_is_public">公开(所有人可查看)</label>
              </div>
            </div>
          </div>
          <button type="submit" class="btn btn-primary"><?php echo $editCat ? '保存修改' : '添加分类'; ?></button>
          <a href="<?php echo url('user/categories.php'); ?>" class="btn btn-default">取消</a>
        </form>
      </div>

      <?php else: ?>
      <!-- 分类列表 -->
      <div class="card">
        <div class="card-header">
          <h3>分类列表</h3>
          <a href="<?php echo url('user/categories.php?action=add'); ?>" class="btn btn-sm btn-primary">+ 添加分类</a>
        </div>

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
              <th>密码 <span class="hint-text">(双击设置)</span></th>
              <th>排序 <span class="hint-text">(双击编辑)</span></th>
              <th>数量</th>
              <th>操作</th>
            </tr>
          </thead>
          <tbody>
          <?php
          function render_cat_rows_v2($tree, $level, $userId) {
              $html = '';
              foreach ($tree as $cat) {
                  $indent = str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $level);
                  $catId = (int)$cat['id'];
                  $html .= '<tr data-id="' . $catId . '">';
                  $html .= '<td><input type="checkbox" class="row-check" value="' . $catId . '"></td>';
                  // 分类名称 - 可双击编辑
                  $html .= '<td class="editable" data-field="name" data-id="' . $catId . '" data-value="' . e($cat['name']) . '">';
                  $html .= $indent . '<span class="edit-text">' . e($cat['name']) . '</span>';
                  $html .= '</td>';
                  // 图标 - 可双击编辑
                  $html .= '<td class="editable" data-field="icon" data-id="' . $catId . '" data-value="' . e($cat['icon']) . '">';
                  $html .= '<span class="edit-text">' . e($cat['icon'] ?: '-') . '</span>';
                  $html .= '</td>';
                  // 状态 - 双击切换
                  $html .= '<td class="editable-toggle" data-field="is_public" data-id="' . $catId . '" data-value="' . (int)$cat['is_public'] . '">';
                  if ($cat['is_public']) {
                      $html .= '<span class="badge badge-success">公开</span>';
                  } else {
                      $html .= '<span class="badge badge-muted">私有</span>';
                  }
                  $html .= '</td>';
                  // 密码 - 双击设置/清除
                  $html .= '<td class="editable-password" data-field="password" data-id="' . $catId . '" data-has-pwd="' . ($cat['password'] ? '1' : '0') . '">';
                  if ($cat['password']) {
                      $html .= '<span class="badge badge-warning">有密码</span>';
                  } else {
                      $html .= '<span style="color:var(--text-muted);">-</span>';
                  }
                  $html .= '</td>';
                  // 排序 - 可双击编辑
                  $html .= '<td class="editable" data-field="sort" data-id="' . $catId . '" data-value="' . (int)$cat['sort'] . '" data-type="number">';
                  $html .= '<span class="edit-text">' . (int)$cat['sort'] . '</span>';
                  $html .= '</td>';
                  // 数量 - 书签 / 页面(便签)
                  $html .= '<td class="count-cell">书签 ' . (int)($cat['bookmark_count'] ?? 0) . ' · 页面 ' . (int)($cat['page_count'] ?? 0) . '</td>';
                  // 操作
                  $html .= '<td class="actions">';
                  $html .= '<a href="' . url('category.php?param=' . $catId) . '" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-default">访问</a>';
                  $html .= '<a href="' . url('user/categories.php?edit=' . $catId) . '" class="btn btn-sm btn-default">编辑</a>';
                  $html .= '<form method="post" action="?delete=' . $catId . '" style="display:inline;" onsubmit="return confirm(\'确定删除该分类吗?分类下的书签将移到未分类\');">';
                  $html .= CSRF::field();
                  $html .= '<button type="submit" class="btn btn-sm btn-danger">删除</button>';
                  $html .= '</form>';
                  $html .= '</td></tr>';
                  $html .= render_cat_rows_v2($cat['children'], $level + 1, $userId);
              }
              return $html;
          }
          echo render_cat_rows_v2($catTree, 0, $userId);
          ?>
          </tbody>
        </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">📁</div>
          <p>还没有分类</p>
          <a href="<?php echo url('user/categories.php?action=add'); ?>" class="btn btn-primary">添加第一个分类</a>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function() {
  var apiUrl = '<?php echo url('user/api.php'); ?>';
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
      if (cell.querySelector('input')) return; // 已经在编辑
      var field = cell.dataset.field;
      var id = cell.dataset.id;
      var type = cell.dataset.type || 'text';
      var currentValue = cell.dataset.value;
      var textSpan = cell.querySelector('.edit-text');

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
        if (newValue === currentValue && type !== 'number') {
          cancel();
          return;
        }
        // 发送AJAX保存
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
          .catch(function() {
            showToast('网络错误,保存失败', 'danger');
            cancel();
          });
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

  // ========== 双击切换状态(公开/私有) ==========
  document.querySelectorAll('.editable-toggle').forEach(function(cell) {
    cell.style.cursor = 'pointer';
    cell.title = '双击切换公开/私有';
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
              cell.innerHTML = '<span class="badge badge-muted">私有</span>';
            }
            showToast('已切换为' + (res.data.is_public == 1 ? '公开' : '私有'), 'success');
          } else {
            showToast(res.message || '操作失败', 'danger');
          }
        })
        .catch(function() { showToast('网络错误', 'danger'); });
    });
  });

  // ========== 双击设置/清除密码 ==========
  document.querySelectorAll('.editable-password').forEach(function(cell) {
    cell.style.cursor = 'pointer';
    cell.title = '双击设置或清除密码';
    cell.addEventListener('dblclick', function() {
      if (cell.querySelector('input')) return;
      var id = cell.dataset.id;
      var hasPwd = cell.dataset.hasPwd === '1';

      var wrapper = document.createElement('div');
      wrapper.style.display = 'flex';
      wrapper.style.gap = '4px';
      wrapper.style.alignItems = 'center';

      var input = document.createElement('input');
      input.type = 'text';
      input.className = 'inline-input';
      input.placeholder = hasPwd ? '输入新密码,留空清除' : '设置密码';
      input.style.width = '130px';

      var okBtn = document.createElement('button');
      okBtn.type = 'button';
      okBtn.className = 'btn btn-sm btn-primary';
      okBtn.textContent = '确定';

      var cancelBtn = document.createElement('button');
      cancelBtn.type = 'button';
      cancelBtn.className = 'btn btn-sm btn-default';
      cancelBtn.textContent = '取消';

      wrapper.appendChild(input);
      wrapper.appendChild(okBtn);
      wrapper.appendChild(cancelBtn);
      cell.innerHTML = '';
      cell.appendChild(wrapper);
      input.focus();

      function renderOriginal() {
        if (hasPwd) {
          cell.innerHTML = '<span class="badge badge-warning">有密码</span>';
        } else {
          cell.innerHTML = '<span style="color:var(--text-muted);">-</span>';
        }
      }

      function save() {
        var pwd = input.value.trim();
        var fd = new FormData();
        fd.append('action', 'update_category_field');
        fd.append(csrfKey, csrfToken);
        fd.append('id', id);
        fd.append('field', 'password');
        fd.append('value', pwd);

        fetch(apiUrl, { method: 'POST', body: fd })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            if (res.success) {
              cell.dataset.hasPwd = res.data.has_password ? '1' : '0';
              hasPwd = res.data.has_password;
              renderOriginal();
              showToast(res.data.has_password ? '密码已设置' : '密码已清除', 'success');
            } else {
              showToast(res.message || '保存失败', 'danger');
              renderOriginal();
            }
          })
          .catch(function() { showToast('网络错误', 'danger'); renderOriginal(); });
      }

      okBtn.addEventListener('click', save);
      cancelBtn.addEventListener('click', renderOriginal);
      input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); save(); }
        if (e.key === 'Escape') { e.preventDefault(); renderOriginal(); }
      });
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

  selectAll.addEventListener('change', function() {
    rowChecks.forEach(function(cb) { cb.checked = selectAll.checked; });
    updateBatchBar();
  });

  rowChecks.forEach(function(cb) {
    cb.addEventListener('change', function() {
      var allChecked = document.querySelectorAll('.row-check:checked').length === rowChecks.length;
      selectAll.checked = allChecked;
      updateBatchBar();
    });
  });

  function clearSelection() {
    rowChecks.forEach(function(cb) { cb.checked = false; });
    selectAll.checked = false;
    updateBatchBar();
    // 重置批量移动按钮状态
    var btn = document.getElementById('batchMoveBtn');
    if (btn) {
      btn.dataset.moveReady = '';
      btn.textContent = '批量移动';
      btn.classList.remove('btn-primary');
    }
  }

  function getSelectedIds() {
    return Array.from(document.querySelectorAll('.row-check:checked')).map(function(cb) { return cb.value; });
  }

  function startBatchMove(btn) {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    if (!btn.dataset.moveReady) {
      // 第一步:显示下拉框,按钮变为"确认移动"
      btn.dataset.moveReady = '1';
      btn.textContent = '确认移动';
      btn.classList.add('btn-primary');
      targetParent.style.display = 'inline-block';
      targetParent.focus();
      return;
    }
    // 第二步:用户已选择目标分类,确认移动
    var sel = targetParent;
    var targetName = sel.options[sel.selectedIndex].text.trim();
    if (!confirm('确定将选中的 ' + ids.length + ' 个分类移动到【' + targetName + '】吗?')) {
      return;
    }
    doBatchSubmit('move');
  }

  function confirmBatchDelete() {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    if (!confirm('确定删除选中的 ' + ids.length + ' 个分类吗？\n该分类及其子分类、书签、便签将一并移入回收站。')) return;
    doBatchSubmit('delete');
  }

  function doBatchSubmit(action) {
    var ids = getSelectedIds();
    var form = document.getElementById('batchForm');
    document.getElementById('batchAction').value = action;
    // 添加隐藏的ids
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
  // 暴露批量操作函数到全局作用域(供inline onclick调用)
  window.startBatchMove = startBatchMove;
  window.confirmBatchDelete = confirmBatchDelete;
  window.clearSelection = clearSelection;
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
