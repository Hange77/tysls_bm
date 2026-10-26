<?php
/**
 * 用户中心 - 回收站（仅本人）
 * v1.4.0.4: 软删除回收站。书签 / 分类 / 便签 / 页面均支持恢复、彻底删除、清空、按类型筛选。
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$userId = Auth::id();
$userPage = 'recycle';
$pageTitle = '回收站';
$currentPage = 'user';
$message = '';
$messageType = '';

$typeLabels = ['bookmark' => '书签', 'category' => '分类', 'page' => '便签'];

// 加载时先清理超过保留期的已删除内容
recycle_purge($userId, false);

// ========== 处理 POST ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $action = $_POST['action'] ?? '';

    if ($action === 'restore') {
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if (in_array($type, ['bookmark', 'category', 'page'], true) && $id > 0) {
            recycle_restore($type, $id, $userId, false);
            log_operation('update', $type, $id, '回收站恢复');
            $message = '已恢复：' . ($typeLabels[$type] ?? $type);
            $messageType = 'success';
        }
    } elseif ($action === 'delete') {
        $type = $_POST['type'] ?? '';
        $id = (int)($_POST['id'] ?? 0);
        if (in_array($type, ['bookmark', 'category', 'page'], true) && $id > 0) {
            recycle_permanent_delete($type, $id, $userId, false);
            log_operation('delete', $type, $id, '回收站彻底删除');
            $message = '已彻底删除';
            $messageType = 'success';
        }
    } elseif ($action === 'restore_multi' || $action === 'delete_multi') {
        // 批量恢复 / 批量彻底删除
        $type = $_POST['type'] ?? '';
        if (in_array($type, ['bookmark', 'category', 'page'], true)) {
            $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($x) { return $x > 0; })));
            if ($ids) {
                foreach ($ids as $id) {
                    if ($action === 'restore_multi') {
                        recycle_restore($type, $id, $userId, false);
                    } else {
                        recycle_permanent_delete($type, $id, $userId, false);
                    }
                }
                $verb = $action === 'restore_multi' ? '批量恢复' : '批量彻底删除';
                log_operation('update', $type, 0, '回收站' . $verb . '(' . count($ids) . ')');
                $message = '已' . $verb . ' ' . count($ids) . ' 项' . ($typeLabels[$type] ?? $type);
                $messageType = 'success';
            }
        }
    } elseif ($action === 'empty') {
        $scope = $_POST['scope'] ?? 'all';
        $map = ['bookmark' => DB_PREFIX . 'bookmarks', 'category' => DB_PREFIX . 'categories', 'page' => DB_PREFIX . 'pages'];
        if ($scope === 'all') {
            foreach ($map as $t) {
                DB::delete($t, 'deleted_at IS NOT NULL AND user_id = ?', [$userId]);
            }
            $message = '已清空回收站（全部类型）';
            $messageType = 'success';
        } elseif (isset($map[$scope])) {
            DB::delete($map[$scope], 'deleted_at IS NOT NULL AND user_id = ?', [$userId]);
            $message = '已清空：' . ($typeLabels[$scope] ?? $scope);
            $messageType = 'success';
        }
    }
}

// ========== 查询本人已删除内容 ==========
$bookmarks = DB::fetchAll(
    "SELECT b.*, c.name as category_name FROM " . DB_PREFIX . "bookmarks b 
     LEFT JOIN " . DB_PREFIX . "categories c ON b.category_id = c.id 
     WHERE b.user_id = ? AND b.deleted_at IS NOT NULL 
     ORDER BY b.deleted_at DESC",
    [$userId]
);
$categories = DB::fetchAll(
    "SELECT c.* FROM " . DB_PREFIX . "categories c 
     WHERE c.user_id = ? AND c.deleted_at IS NOT NULL 
     ORDER BY c.deleted_at DESC",
    [$userId]
);
$notes = DB::fetchAll(
    "SELECT p.* FROM " . DB_PREFIX . "pages p 
     WHERE p.user_id = ? AND p.content_type = 'text' AND p.deleted_at IS NOT NULL 
     ORDER BY p.deleted_at DESC",
    [$userId]
);

include __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="user-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="user-main">
      <div class="card">
        <div class="card-header">
          <h3>♻ 回收站</h3>
          <form method="post" action="" style="display:inline;" onsubmit="return confirm('确定清空回收站全部内容吗？此操作不可恢复');">
            <?php echo CSRF::field(); ?>
            <input type="hidden" name="action" value="empty">
            <input type="hidden" name="scope" value="all">
            <button type="submit" class="btn btn-sm btn-danger">清空回收站（全部类型）</button>
          </form>
        </div>
        <div class="card-body" style="padding:16px 20px;">
          <?php if ($message): ?>
          <div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
          <?php endif; ?>

          <p style="color:var(--text-muted);font-size:13px;margin-top:0;">
            此处仅显示你本人已删除的内容，可在保留期（默认 30 天）内恢复；超过保留期将自动彻底删除。
          </p>

          <?php
          // 渲染单段已删除内容
          function render_recycle_section($title, $type, $rows, $nameField, $userId) {
              global $typeLabels;
          ?>
          <h4 style="margin:18px 0 8px;font-size:15px;">
            <?php echo e($title); ?>
            <?php if ($rows): ?>
            <form method="post" action="" style="display:inline;margin-left:8px;" onsubmit="return confirm('确定清空该类型所有已删除内容吗？');">
              <?php echo CSRF::field(); ?>
              <input type="hidden" name="action" value="empty">
              <input type="hidden" name="scope" value="<?php echo $type; ?>">
              <button type="submit" class="btn btn-sm btn-default">清空该类</button>
            </form>
            <?php endif; ?>
          </h4>
          <?php if ($rows): ?>
          <div class="table-wrap">
            <div class="batch-bar" style="display:flex;gap:8px;align-items:center;margin:0 0 10px;">
              <button type="button" class="btn btn-sm btn-primary" onclick="recycleBatch('restore_multi','<?php echo $type; ?>',this)">批量恢复</button>
              <button type="button" class="btn btn-sm btn-danger" onclick="recycleBatch('delete_multi','<?php echo $type; ?>',this)">批量彻底删除</button>
              <span style="color:var(--text-muted);font-size:12px;">勾选后批量操作</span>
            </div>
            <table class="table">
              <thead>
                <tr>
                  <th style="width:36px;"><input type="checkbox" class="sel-all" title="全选" onclick="recycleSelAll(this)"></th>
                  <th>标题 / 名称</th>
                  <th>类型</th>
                  <th>删除时间</th>
                  <th>操作</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                  <td><input type="checkbox" class="row-check" value="<?php echo (int)$row['id']; ?>"></td>
                  <td><?php echo e($row[$nameField]); ?></td>
                  <td><?php echo e($typeLabels[$type] ?? $type); ?></td>
                  <td><?php echo e(date('Y-m-d H:i', strtotime($row['deleted_at']))); ?></td>
                  <td class="actions">
                    <form method="post" action="" style="display:inline;" onsubmit="return confirm('确定恢复该内容吗？');">
                      <?php echo CSRF::field(); ?>
                      <input type="hidden" name="action" value="restore">
                      <input type="hidden" name="type" value="<?php echo $type; ?>">
                      <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                      <button type="submit" class="btn btn-sm btn-default">恢复</button>
                    </form>
                    <form method="post" action="" style="display:inline;" onsubmit="return confirm('确定彻底删除吗？此操作不可恢复');">
                      <?php echo CSRF::field(); ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="type" value="<?php echo $type; ?>">
                      <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                      <button type="submit" class="btn btn-sm btn-danger">彻底删除</button>
                    </form>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php else: ?>
          <p style="color:var(--text-muted);font-size:13px;">暂无已删除的<?php echo e($typeLabels[$type] ?? $type); ?>。</p>
          <?php endif; ?>
          <?php
          }

          render_recycle_section('书签', 'bookmark', $bookmarks, 'title', $userId);
          render_recycle_section('分类', 'category', $categories, 'name', $userId);
          render_recycle_section('便签', 'page', $notes, 'title', $userId);
          ?>
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
<script>
function recycleSelAll(cb) {
  var wrap = cb.closest('.table-wrap');
  wrap.querySelectorAll('.row-check').forEach(function (el) { el.checked = cb.checked; });
}
function recycleHidden(n, v) {
  var i = document.createElement('input');
  i.type = 'hidden'; i.name = n; i.value = v;
  return i;
}
function recycleBatch(action, type, btn) {
  var wrap = btn.closest('.table-wrap');
  var ids = [];
  wrap.querySelectorAll('.row-check:checked').forEach(function (el) { ids.push(el.value); });
  if (!ids.length) { alert('请先勾选要操作的内容'); return; }
  var verb = action === 'restore_multi' ? '恢复' : '彻底删除';
  var tip = action === 'restore_multi' ? ('确定批量恢复选中的 ' + ids.length + ' 项内容吗？')
                                        : ('确定批量彻底删除选中的 ' + ids.length + ' 项内容吗？此操作不可恢复');
  if (!confirm(tip)) return;
  var f = document.createElement('form');
  f.method = 'post'; f.action = '';
  var csrf = document.querySelector('input[name="_csrf_token"]');
  if (csrf) f.appendChild(recycleHidden('_csrf_token', csrf.value));
  f.appendChild(recycleHidden('action', action));
  f.appendChild(recycleHidden('type', type));
  ids.forEach(function (id) { f.appendChild(recycleHidden('ids[]', id)); });
  document.body.appendChild(f);
  f.submit();
}
</script>
