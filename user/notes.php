<?php
/**
 * 用户中心 - 便签管理(纯文字)
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$userId = Auth::id();
$userPage = 'notes';
$pageTitle = '便签管理';
$currentPage = 'user';
$message = '';
$messageType = '';

// 处理删除
if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $id = (int)$_GET['delete'];
    $note = DB::fetch("SELECT * FROM " . DB_PREFIX . "pages WHERE id = ? AND user_id = ? AND content_type = 'text'", [$id, $userId]);
    if ($note) {
        recycle_soft_delete(DB_PREFIX . 'pages', $id, $userId, false);
        log_operation('delete', 'page', $id, '删除便签: ' . $note['title']);
        $message = '便签已移入回收站';
        $messageType = 'success';
    }
}

// ========== 批量删除 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'delete') {
    CSRF::check();
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        $message = '请先选择要删除的便签';
        $messageType = 'danger';
    } else {
        $deleted = 0;
        foreach ($ids as $rawId) {
            $id = (int)$rawId;
            $note = DB::fetch("SELECT title FROM " . DB_PREFIX . "pages WHERE id = ? AND user_id = ? AND content_type = 'text'", [$id, $userId]);
            if (!$note) continue;
            recycle_soft_delete(DB_PREFIX . 'pages', $id, $userId, false);
            log_operation('delete', 'page', $id, '批量删除便签: ' . $note['title']);
            $deleted++;
        }
        $message = "已将 {$deleted} 条便签移入回收站";
        $messageType = 'success';
    }
}

// ========== 批量移动 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['batch_action'] ?? '') === 'move') {
    CSRF::check();
    $ids = $_POST['ids'] ?? [];
    $targetCat = (int)($_POST['target_category'] ?? 0);
    if (!is_array($ids) || empty($ids)) {
        $message = '请先选择要移动的便签';
        $messageType = 'danger';
    } else {
        if ($targetCat > 0) {
            $cat = DB::fetch("SELECT id FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = ?", [$targetCat, $userId]);
            if (!$cat) $targetCat = 0;
        }
        $moved = 0;
        foreach ($ids as $rawId) {
            $id = (int)$rawId;
            $note = DB::fetch("SELECT id FROM " . DB_PREFIX . "pages WHERE id = ? AND user_id = ? AND content_type = 'text'", [$id, $userId]);
            if (!$note) continue;
            DB::update(DB_PREFIX . 'pages', ['category_id' => $targetCat], 'id = :id AND user_id = :uid AND content_type = :ct', [':id' => $id, ':uid' => $userId, ':ct' => 'text']);
            log_operation('update', 'page', $id, '批量移动便签到分类 ' . $targetCat);
            $moved++;
        }
        $catName = $targetCat > 0 ? '指定分类' : '未分类';
        $message = "已移动 {$moved} 条便签到{$catName}";
        $messageType = 'success';
    }
}

// 处理保存
$showForm = false;
$form = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_note'])) {
    CSRF::check();
    $form = $_POST;
    $id = (int)($_POST['id'] ?? 0);
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $isPublic = isset($_POST['is_public']) ? 1 : 0;

    if (!$title || !$content) {
        $message = '标题和内容不能为空';
        $messageType = 'danger';
        $showForm = true;
    } else {
        if ($categoryId > 0) {
            $cat = DB::fetch("SELECT id FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = ?", [$categoryId, $userId]);
            if (!$cat) $categoryId = 0;
        }

        $data = [
            'category_id' => $categoryId,
            'title' => $title,
            'content' => $content,
            'content_type' => 'text',
            'is_public' => $isPublic,
        ];

        if ($id > 0) {
            DB::update(DB_PREFIX . 'pages', $data, 'id = :id AND user_id = :uid AND content_type = :ct', [':id' => $id, ':uid' => $userId, ':ct' => 'text']);
            log_operation('update', 'page', $id, '编辑便签: ' . $title);
            $message = '便签已更新';
        } else {
            $data['user_id'] = $userId;
            $newId = DB::insert(DB_PREFIX . 'pages', $data);
            log_operation('create', 'page', $newId, '添加便签: ' . $title);
            $message = '便签已添加';
        }
        $messageType = 'success';
        redirect(url('user/notes.php'));
    }
}

// 编辑模式
$editNote = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editNote = DB::fetch("SELECT * FROM " . DB_PREFIX . "pages WHERE id = ? AND user_id = ? AND content_type = 'text' AND deleted_at IS NULL", [$editId, $userId]);
    if (!$editNote) {
        $message = '便签不存在';
        $messageType = 'danger';
    }
}

// 获取分类
$userCategories = DB::fetchAll("SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = ? ORDER BY sort ASC, id ASC", [$userId]);
$catTree = build_category_tree($userCategories);

// 列表
$total = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "pages WHERE user_id = ? AND content_type = 'text' AND deleted_at IS NULL", [$userId]);
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pagination = paginate($total, $page, 12);
$notes = DB::fetchAll(
    "SELECT p.*, c.name as category_name FROM " . DB_PREFIX . "pages p 
     LEFT JOIN " . DB_PREFIX . "categories c ON p.category_id = c.id 
     WHERE p.user_id = ? AND p.content_type = 'text' AND p.deleted_at IS NULL 
     ORDER BY p.created_at DESC LIMIT ? OFFSET ?",
    [$userId, $pagination['per_page'], $pagination['offset']]
);

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
      $noteSelCat = (int)($form['category_id'] ?? ($editNote['category_id'] ?? 0));
      $notePublicChecked = $editNote ? (bool)$editNote['is_public'] : (!empty($form) ? isset($form['is_public']) : true);
      ?>
      <?php if ($showForm || isset($_GET['action']) && $_GET['action'] === 'add' || $editNote): ?>
      <div class="card">
        <div class="card-header">
          <h3><?php echo $editNote ? '编辑便签' : '写便签'; ?></h3>
          <a href="<?php echo url('user/notes.php'); ?>" class="btn btn-sm btn-default">返回列表</a>
        </div>
        <form method="post" action="">
          <?php echo CSRF::field(); ?>
          <input type="hidden" name="save_note" value="1">
          <input type="hidden" name="id" value="<?php echo $editNote ? (int)$editNote['id'] : 0; ?>">
          <div class="form-row">
            <div class="form-group">
              <label>标题 *</label>
              <input type="text" name="title" class="form-control" required value="<?php echo e($form['title'] ?? ($editNote['title'] ?? '')); ?>">
            </div>
            <div class="form-group">
              <label>所属分类</label>
              <select name="category_id" class="form-control">
                <option value="0">未分类</option>
                <?php echo render_category_options($catTree, $noteSelCat); ?>
              </select>
            </div>
          </div>
          <div class="form-group">
            <label>内容 * (纯文字,支持换行和自动链接)</label>
            <textarea name="content" class="form-control" rows="10" required placeholder="在这里写下你的便签内容..."><?php echo e($form['content'] ?? ($editNote['content'] ?? '')); ?></textarea>
          </div>
          <div class="form-group">
            <div class="form-check">
              <input type="checkbox" name="is_public" id="note_is_public" <?php echo $notePublicChecked ? 'checked' : ''; ?>>
              <label for="note_is_public">公开(所有人可通过我的收藏夹查看)</label>
            </div>
          </div>
          <button type="submit" class="btn btn-primary"><?php echo $editNote ? '保存修改' : '保存便签'; ?></button>
          <a href="<?php echo url('user/notes.php'); ?>" class="btn btn-default">取消</a>
        </form>
      </div>
      <?php else: ?>
      <div class="card">
        <div class="card-header">
          <h3>便签列表 (共 <?php echo $total; ?> 条)</h3>
          <a href="<?php echo url('user/notes.php?action=add'); ?>" class="btn btn-sm btn-primary">+ 写便签</a>
        </div>
        <?php if ($notes): ?>
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
        <div class="note-grid" id="noteGrid">
          <?php foreach ($notes as $note): ?>
          <div class="note-card note-card-selectable">
            <div class="note-card-check">
              <input type="checkbox" class="row-check" value="<?php echo (int)$note['id']; ?>">
            </div>
            <h3><?php echo e($note['title']); ?></h3>
            <div class="note-content"><?php echo e(truncate($note['content'], 100)); ?></div>
            <div class="note-meta">
              <span><?php echo e($note['category_name'] ?: '未分类'); ?></span>
              <span><?php echo $note['is_public'] ? '公开' : '私有'; ?></span>
            </div>
            <div style="margin-top:10px;display:flex;gap:6px;">
              <a href="<?php echo page_url($note); ?>" target="_blank" class="btn btn-sm btn-default">查看</a>
              <a href="<?php echo url('user/notes.php?edit=' . $note['id']); ?>" class="btn btn-sm btn-default">编辑</a>
              <form method="post" action="?delete=<?php echo $note['id']; ?>" style="display:inline;" onsubmit="return confirm('确定删除该便签吗?');">
                <?php echo CSRF::field(); ?>
                <button type="submit" class="btn btn-sm btn-danger">删除</button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php echo render_pagination($pagination, url('user/notes.php')); ?>
        <?php else: ?>
        <div class="empty-state">
          <div class="empty-icon">📝</div>
          <p>还没有便签</p>
          <a href="<?php echo url('user/notes.php?action=add'); ?>" class="btn btn-primary">写第一条便签</a>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function() {
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

  rowChecks.forEach(function(cb) {
    cb.addEventListener('change', updateBatchBar);
  });

  function clearSelection() {
    rowChecks.forEach(function(cb) { cb.checked = false; });
    updateBatchBar();
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
      targetCategory.style.display = 'inline-block';
      targetCategory.focus();
      return;
    }
    // 第二步:用户已选择目标分类,确认移动
    var sel = targetCategory;
    var targetName = sel.options[sel.selectedIndex].text.trim();
    if (!confirm('确定将选中的 ' + ids.length + ' 个分类移动到【' + targetName + '】吗?')) {
      return;
    }
    doBatchSubmit('move');
  }

  function confirmBatchDelete() {
    var ids = getSelectedIds();
    if (ids.length === 0) return;
    if (!confirm('确定删除选中的 ' + ids.length + ' 条便签吗?')) return;
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
  // 暴露批量操作函数到全局作用域(供inline onclick调用)
  window.startBatchMove = startBatchMove;
  window.confirmBatchDelete = confirmBatchDelete;
  window.clearSelection = clearSelection;
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
