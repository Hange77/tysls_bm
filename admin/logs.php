<?php
/**
 * 后台管理 - 操作日志
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();

$adminPage = 'logs';
$pageTitle = '操作日志';
$currentPage = 'admin';

// 筛选
$filterUser = trim($_GET['user'] ?? '');
$filterAction = trim($_GET['action'] ?? '');

$where = 'WHERE 1=1';
$params = [];
if ($filterUser) {
    $where .= " AND (username LIKE ? OR user_id = ?)";
    $params[] = "%{$filterUser}%";
    $params[] = is_numeric($filterUser) ? (int)$filterUser : 0;
}
if ($filterAction) {
    $where .= " AND action = ?";
    $params[] = $filterAction;
}

$total = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "operation_logs {$where}", $params);
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pagination = paginate($total, $page, 50);
$logs = DB::fetchAll(
    "SELECT * FROM " . DB_PREFIX . "operation_logs {$where} ORDER BY id DESC LIMIT ? OFFSET ?",
    array_merge($params, [$pagination['per_page'], $pagination['offset']])
);

// 操作类型列表
$actions = DB::fetchAll("SELECT DISTINCT action FROM " . DB_PREFIX . "operation_logs ORDER BY action");

include __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="admin-main">
    <div class="card">
      <div class="card-header">
        <h3>操作日志 (共 <?php echo $total; ?> 条)</h3>
        <form method="get" action="" style="display:flex;gap:8px;">
          <input type="text" name="user" class="form-control" style="width:160px;" placeholder="用户名/ID" value="<?php echo e($filterUser); ?>">
          <select name="action" class="form-control" style="width:140px;">
            <option value="">全部操作</option>
            <?php foreach ($actions as $a): ?>
            <option value="<?php echo e($a['action']); ?>" <?php echo $filterAction === $a['action'] ? 'selected' : ''; ?>><?php echo e($a['action']); ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-default">筛选</button>
          <a href="<?php echo url('admin/logs.php'); ?>" class="btn btn-sm btn-default">重置</a>
        </form>
      </div>
      <?php if ($logs): ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>ID</th><th>时间</th><th>用户</th><th>操作</th><th>目标</th><th>详情</th><th>IP</th><th>User-Agent</th></tr></thead>
        <tbody>
        <?php foreach ($logs as $log): ?>
        <tr>
          <td><?php echo (int)$log['id']; ?></td>
          <td style="white-space:nowrap;"><?php echo e(date('Y-m-d H:i:s', strtotime($log['created_at']))); ?></td>
          <td><?php echo e($log['username']); ?>(<?php echo (int)$log['user_id']; ?>)</td>
          <td><span class="badge badge-primary"><?php echo e($log['action']); ?></span></td>
          <td><?php echo e($log['target_type'] . ($log['target_id'] ? ' #' . $log['target_id'] : '')); ?></td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($log['detail']); ?>"><?php echo e($log['detail']); ?></td>
          <td><?php echo e($log['ip']); ?></td>
          <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo e($log['user_agent']); ?>"><?php echo e($log['user_agent']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php
      $baseUrl = url('admin/logs.php');
      $query = [];
      if ($filterUser) $query['user'] = $filterUser;
      if ($filterAction) $query['action'] = $filterAction;
      $baseUrl .= $query ? '?' . http_build_query($query) : '';
      echo render_pagination($pagination, $baseUrl);
      ?>
      <?php else: ?>
      <div class="empty-state"><div class="empty-icon">📋</div><p>暂无操作日志</p></div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
