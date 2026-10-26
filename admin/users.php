<?php
/**
 * 后台管理 - 用户管理
 * 功能: 查看用户列表、查看用户所有内容、禁用/启用、赋予/撤销管理员权限
 * 安全: id=1的超级管理员不可被编辑或删除
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();

$adminPage = 'users';
$pageTitle = '用户管理';
$currentPage = 'admin';
$message = '';
$messageType = '';
$isSuperAdmin = Auth::isSuperAdmin();

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    if ($targetId <= 0) {
        $message = '无效的用户ID';
        $messageType = 'danger';
    } elseif ($targetId === 1 && !$isSuperAdmin) {
        $message = '只有超级管理员可以操作ID为1的用户';
        $messageType = 'danger';
    } elseif ($targetId === (int)Auth::id() && in_array($action, ['disable', 'revoke_admin'])) {
        $message = '不能对自己执行此操作';
        $messageType = 'danger';
    } else {
        $targetUser = DB::fetch("SELECT * FROM " . DB_PREFIX . "users WHERE id = ?", [$targetId]);
        if (!$targetUser) {
            $message = '用户不存在';
            $messageType = 'danger';
        } else {
            switch ($action) {
                case 'toggle_active':
                    $newStatus = $targetUser['is_active'] ? 0 : 1;
                    DB::update(DB_PREFIX . 'users', ['is_active' => $newStatus], 'id = :id', [':id' => $targetId]);
                    log_operation('update', 'user', $targetId, ($newStatus ? '启用' : '禁用') . '用户: ' . $targetUser['username']);
                    $message = '用户已' . ($newStatus ? '启用' : '禁用');
                    $messageType = 'success';
                    break;

                case 'grant_admin':
                    if (!$isSuperAdmin) {
                        $message = '只有超级管理员(id=1)可以赋予管理员权限';
                        $messageType = 'danger';
                    } elseif ($targetId === 1) {
                        $message = '不能修改超级管理员的角色';
                        $messageType = 'danger';
                    } else {
                        DB::update(DB_PREFIX . 'users', ['role' => 'admin'], 'id = :id', [':id' => $targetId]);
                        log_operation('grant', 'user', $targetId, '赋予管理员权限: ' . $targetUser['username']);
                        $message = '已赋予管理员权限';
                        $messageType = 'success';
                    }
                    break;

                case 'revoke_admin':
                    if (!$isSuperAdmin) {
                        $message = '只有超级管理员(id=1)可以撤销管理员权限';
                        $messageType = 'danger';
                    } elseif ($targetId === 1) {
                        $message = '不能撤销超级管理员的权限';
                        $messageType = 'danger';
                    } else {
                        DB::update(DB_PREFIX . 'users', ['role' => 'user'], 'id = :id', [':id' => $targetId]);
                        log_operation('revoke', 'user', $targetId, '撤销管理员权限: ' . $targetUser['username']);
                        $message = '已撤销管理员权限';
                        $messageType = 'success';
                    }
                    break;

                case 'reset_password':
                    // 安全限制: 仅超级管理员可重置密码; 管理员之间不能互改; 任何人不能重置自己的密码
                    if (!$isSuperAdmin) {
                        $message = '只有超级管理员可以重置其他用户密码';
                        $messageType = 'danger';
                    } elseif ($targetUser['role'] === 'admin') {
                        $message = '不能重置管理员账号的密码,请让其到个人中心修改';
                        $messageType = 'danger';
                    } elseif ($targetId === (int)Auth::id()) {
                        $message = '不能重置自己的密码,请到个人中心修改';
                        $messageType = 'danger';
                    } else {
                        $newPassword = $_POST['new_password'] ?? '';
                        $newPassword2 = $_POST['new_password2'] ?? '';
                        if (strlen($newPassword) < 8) {
                            $message = '新密码至少8位';
                            $messageType = 'danger';
                        } elseif ($newPassword !== $newPassword2) {
                            $message = '两次密码输入不一致';
                            $messageType = 'danger';
                        } else {
                            DB::update(DB_PREFIX . 'users', ['password' => password_hash($newPassword, PASSWORD_DEFAULT)], 'id = :id', [':id' => $targetId]);
                            log_operation('update', 'user', $targetId, '管理员重置密码: ' . $targetUser['username']);
                            $message = '密码已重置,请通知用户使用新密码登录';
                            $messageType = 'success';
                        }
                    }
                    break;

                case 'delete':
                    if (!$isSuperAdmin) {
                        $message = '只有超级管理员可以删除用户';
                        $messageType = 'danger';
                    } elseif ($targetId === 1) {
                        $message = '不能删除超级管理员';
                        $messageType = 'danger';
                    } elseif ($targetId === (int)Auth::id()) {
                        $message = '不能删除自己';
                        $messageType = 'danger';
                    } else {
                        // 删除用户及其所有内容
                        DB::beginTransaction();
                        try {
                            DB::delete(DB_PREFIX . 'bookmarks', 'user_id = ?', [$targetId]);
                            DB::delete(DB_PREFIX . 'pages', 'user_id = ?', [$targetId]);
                            DB::delete(DB_PREFIX . 'categories', 'user_id = ?', [$targetId]);
                            DB::delete(DB_PREFIX . 'users', 'id = ?', [$targetId]);
                            DB::commit();
                            log_operation('delete', 'user', $targetId, '删除用户及其所有内容: ' . $targetUser['username']);
                            $message = '用户及其所有内容已删除';
                            $messageType = 'success';
                        } catch (Exception $e) {
                            DB::rollBack();
                            $message = '删除失败: ' . $e->getMessage();
                            $messageType = 'danger';
                        }
                    }
                    break;
            }
        }
    }
}

// 查看用户详情
$viewUser = null;
$viewUserContent = [];
if (isset($_GET['view'])) {
    $viewId = (int)$_GET['view'];
    $viewUser = DB::fetch("SELECT * FROM " . DB_PREFIX . "users WHERE id = ?", [$viewId]);
    if ($viewUser) {
        $viewUserContent['bookmarks'] = DB::fetchAll(
            "SELECT b.*, c.name as category_name FROM " . DB_PREFIX . "bookmarks b 
             LEFT JOIN " . DB_PREFIX . "categories c ON b.category_id = c.id 
             WHERE b.user_id = ? ORDER BY b.created_at DESC",
            [$viewId]
        );
        $viewUserContent['bookmark_count'] = DB::fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "bookmarks WHERE user_id = ?",
            [$viewId]
        );
        $viewUserContent['categories'] = DB::fetchAll(
            "SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = ? ORDER BY created_at DESC",
            [$viewId]
        );
        $viewUserContent['notes'] = DB::fetchAll(
            "SELECT * FROM " . DB_PREFIX . "pages WHERE user_id = ? AND content_type = 'text' ORDER BY created_at DESC",
            [$viewId]
        );
        $viewUserContent['note_count'] = DB::fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "pages WHERE user_id = ? AND content_type = 'text'",
            [$viewId]
        );
        $viewUserContent['logs'] = DB::fetchAll(
            "SELECT * FROM " . DB_PREFIX . "operation_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 20",
            [$viewId]
        );
    }
}

// 用户列表
$keyword = trim($_GET['q'] ?? '');
$where = '';
$params = [];
if ($keyword) {
    $where = "WHERE username LIKE ? OR email LIKE ? OR nickname LIKE ?";
    $params = ["%{$keyword}%", "%{$keyword}%", "%{$keyword}%"];
}
$total = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "users {$where}", $params);
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$pagination = paginate($total, $page, 20);
$users = DB::fetchAll(
    "SELECT * FROM " . DB_PREFIX . "users {$where} ORDER BY id ASC LIMIT ? OFFSET ?",
    array_merge($params, [$pagination['per_page'], $pagination['offset']])
);

include __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="admin-main">
    <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if ($viewUser): ?>
    <!-- 用户详情 -->
    <div class="card">
      <div class="card-header">
        <h3>用户详情: <?php echo e($viewUser['username']); ?></h3>
        <a href="<?php echo url('admin/users.php'); ?>" class="btn btn-sm btn-default">返回列表</a>
      </div>
      <table class="table">
        <tr><th style="width:120px;">用户ID</th><td><?php echo (int)$viewUser['id']; ?></td></tr>
        <tr><th>用户名</th><td><?php echo e($viewUser['username']); ?></td></tr>
        <tr><th>昵称</th><td><?php echo e($viewUser['nickname']); ?></td></tr>
        <tr><th>邮箱</th><td><?php echo e($viewUser['email'] ?: '-'); ?></td></tr>
        <tr><th>角色</th><td><?php echo $viewUser['role'] === 'admin' ? '<span class="badge badge-primary">管理员</span>' : '<span class="badge badge-muted">普通用户</span>'; ?></td></tr>
        <tr><th>状态</th><td><?php echo $viewUser['is_active'] ? '<span class="badge badge-success">正常</span>' : '<span class="badge badge-danger">已禁用</span>'; ?></td></tr>
        <tr><th>注册时间</th><td><?php echo e(date('Y-m-d H:i:s', strtotime($viewUser['created_at']))); ?></td></tr>
        <tr><th>最后登录</th><td><?php echo $viewUser['last_login_at'] ? e(date('Y-m-d H:i:s', strtotime($viewUser['last_login_at']))) : '-'; ?></td></tr>
        <tr><th>最后登录IP</th><td><?php echo e($viewUser['last_login_ip'] ?: '-'); ?></td></tr>
        <tr><th>个人收藏夹</th><td><a href="<?php echo url('u/' . $viewUser['username']); ?>" target="_blank"><?php echo e(url('u/' . $viewUser['username'])); ?></a></td></tr>
      </table>
    </div>

    <div class="card">
      <div class="card-header"><h3>书签 (<?php echo count($viewUserContent['bookmarks']); ?>)</h3></div>
      <?php if ($viewUserContent['bookmarks']): ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>标题</th><th>分类</th><th>URL</th><th>状态</th><th>添加时间</th></tr></thead>
        <tbody>
        <?php foreach ($viewUserContent['bookmarks'] as $bm): ?>
        <tr>
          <td><?php echo e($bm['title']); ?></td>
          <td><?php echo e($bm['category_name'] ?: '-'); ?></td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo e($bm['url']); ?></td>
          <td><?php echo $bm['is_public'] ? '<span class="badge badge-success">公开</span>' : '<span class="badge badge-muted">私有</span>'; ?></td>
          <td><?php echo e(date('Y-m-d H:i', strtotime($bm['created_at']))); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><p style="color:var(--text-muted);">暂无书签</p><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><h3>便签 (<?php echo count($viewUserContent['notes']); ?>)</h3></div>
      <?php if ($viewUserContent['notes']): ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>标题</th><th>内容摘要</th><th>状态</th><th>创建时间</th></tr></thead>
        <tbody>
        <?php foreach ($viewUserContent['notes'] as $note): ?>
        <tr>
          <td><a href="<?php echo page_url($note); ?>" target="_blank"><?php echo e($note['title']); ?></a></td>
          <td><?php echo e(truncate($note['content'], 60)); ?></td>
          <td><?php echo $note['is_public'] ? '<span class="badge badge-success">公开</span>' : '<span class="badge badge-muted">私有</span>'; ?></td>
          <td><?php echo e(date('Y-m-d H:i', strtotime($note['created_at']))); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><p style="color:var(--text-muted);">暂无便签</p><?php endif; ?>
    </div>

    <div class="card">
      <div class="card-header"><h3>操作日志 (最近20条)</h3></div>
      <?php if ($viewUserContent['logs']): ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>时间</th><th>操作</th><th>目标</th><th>详情</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($viewUserContent['logs'] as $log): ?>
        <tr>
          <td><?php echo e(date('Y-m-d H:i:s', strtotime($log['created_at']))); ?></td>
          <td><span class="badge badge-primary"><?php echo e($log['action']); ?></span></td>
          <td><?php echo e($log['target_type'] . ($log['target_id'] ? ' #' . $log['target_id'] : '')); ?></td>
          <td><?php echo e(truncate($log['detail'], 50)); ?></td>
          <td><?php echo e($log['ip']); ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?><p style="color:var(--text-muted);">暂无日志</p><?php endif; ?>
    </div>

    <?php else: ?>
    <!-- 用户列表 -->
    <div class="card">
      <div class="card-header">
        <h3>用户列表 (共 <?php echo $total; ?> 人)</h3>
        <form method="get" action="" style="display:flex;gap:8px;">
          <input type="text" name="q" class="form-control" style="width:200px;" placeholder="搜索用户名/邮箱/昵称" value="<?php echo e($keyword); ?>">
          <button type="submit" class="btn btn-sm btn-default">搜索</button>
        </form>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>ID</th><th>用户名</th><th>昵称</th><th>邮箱</th><th>角色</th><th>状态</th><th>注册时间</th><th>操作</th></tr></thead>
          <tbody>
            <?php foreach ($users as $u): ?>
            <tr>
              <td><?php echo (int)$u['id']; ?></td>
              <td><?php echo e($u['username']); ?><?php echo (int)$u['id'] === 1 ? ' <span class="badge badge-warning">超管</span>' : ''; ?></td>
              <td><?php echo e($u['nickname']); ?></td>
              <td><?php echo e($u['email'] ?: '-'); ?></td>
              <td><?php echo $u['role'] === 'admin' ? '<span class="badge badge-primary">管理员</span>' : '<span class="badge badge-muted">普通用户</span>'; ?></td>
              <td><?php echo $u['is_active'] ? '<span class="badge badge-success">正常</span>' : '<span class="badge badge-danger">已禁用</span>'; ?></td>
              <td><?php echo e(date('Y-m-d', strtotime($u['created_at']))); ?></td>
              <td class="actions">
                <a href="?view=<?php echo (int)$u['id']; ?>" class="btn btn-sm btn-default">查看</a>
                <?php if ($isSuperAdmin && $u['role'] !== 'admin'): ?>
                <a href="javascript:void(0)" class="btn btn-sm btn-default" onclick="openReset(<?php echo (int)$u['id']; ?>, '<?php echo e($u['username']); ?>')">重置密码</a>
                <?php endif; ?>
                <?php if ((int)$u['id'] !== 1 && (int)$u['id'] !== (int)Auth::id()): ?>
                <form method="post" action="" style="display:inline;" onsubmit="return confirm('确定<?php echo $u['is_active'] ? '禁用' : '启用'; ?>该用户吗?');">
                  <?php echo CSRF::field(); ?>
                  <input type="hidden" name="action" value="toggle_active">
                  <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                  <button type="submit" class="btn btn-sm <?php echo $u['is_active'] ? 'btn-warning' : 'btn-success'; ?>"><?php echo $u['is_active'] ? '禁用' : '启用'; ?></button>
                </form>
                <?php endif; ?>
                <?php if ($isSuperAdmin && (int)$u['id'] !== 1): ?>
                  <?php if ($u['role'] === 'admin'): ?>
                  <form method="post" action="" style="display:inline;" onsubmit="return confirm('确定撤销该用户的管理员权限吗?');">
                    <?php echo CSRF::field(); ?>
                    <input type="hidden" name="action" value="revoke_admin">
                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-default">撤销管理员</button>
                  </form>
                  <?php else: ?>
                  <form method="post" action="" style="display:inline;" onsubmit="return confirm('确定赋予该用户管理员权限吗?');">
                    <?php echo CSRF::field(); ?>
                    <input type="hidden" name="action" value="grant_admin">
                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-primary">设为管理员</button>
                  </form>
                  <?php endif; ?>
                  <form method="post" action="" style="display:inline;" onsubmit="return confirm('确定删除该用户及其所有内容吗?此操作不可恢复!');">
                    <?php echo CSRF::field(); ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger">删除</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php echo render_pagination($pagination, url('admin/users.php' . ($keyword ? '?q=' . urlencode($keyword) : ''))); ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- 重置密码弹窗 -->
<div id="resetModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.45);z-index:999;align-items:center;justify-content:center;">
  <div style="background:#fff;border-radius:10px;max-width:380px;width:90%;padding:24px;box-shadow:0 8px 30px rgba(0,0,0,.2);">
    <h3 style="margin-bottom:6px;">重置密码</h3>
    <p style="color:var(--text2);font-size:13px;margin-bottom:16px;">用户: <strong id="resetUsername"></strong></p>
    <form method="post" action="">
      <?php echo CSRF::field(); ?>
      <input type="hidden" name="action" value="reset_password">
      <input type="hidden" name="user_id" id="resetUserId" value="0">
      <div class="form-group">
        <label>新密码 (至少8位)</label>
        <input type="password" name="new_password" class="form-control" required minlength="8">
      </div>
      <div class="form-group">
        <label>确认新密码</label>
        <input type="password" name="new_password2" class="form-control" required minlength="8">
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px;">
        <button type="button" class="btn btn-default" onclick="closeReset()">取消</button>
        <button type="submit" class="btn btn-primary">确认重置</button>
      </div>
    </form>
  </div>
</div>
<script>
function openReset(id, username) {
  document.getElementById('resetUserId').value = id;
  document.getElementById('resetUsername').textContent = username;
  var modal = document.getElementById('resetModal');
  modal.style.display = 'flex';
}
function closeReset() {
  document.getElementById('resetModal').style.display = 'none';
}
document.getElementById('resetModal').addEventListener('click', function(e) {
  if (e.target === this) closeReset();
});
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
