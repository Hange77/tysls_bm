<?php
/**
 * 用户中心 - 个人设置（含主题设置）
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
$userId = Auth::id();
$userPage = 'profile';
$pageTitle = '个人设置';
$currentPage = 'user';
$message = '';
$messageType = '';

// 处理基本信息修改
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_profile'])) {
    CSRF::check();
    $nickname = trim($_POST['nickname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $avatar = trim($_POST['avatar'] ?? '');

    if (!$nickname) {
        $message = '昵称不能为空';
        $messageType = 'danger';
    } elseif ($email && !validate_email($email)) {
        $message = '邮箱格式不正确';
        $messageType = 'danger';
    } else {
        if ($email) {
            $exists = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "users WHERE email = ? AND id != ?", [$email, $userId]);
            if ($exists) {
                $message = '该邮箱已被其他用户使用';
                $messageType = 'danger';
            }
        }
        if (!$message) {
            // 第一步:保存基本资料(这些字段一定存在)
            try {
                DB::update(DB_PREFIX . 'users', [
                    'nickname' => $nickname,
                    'email' => $email,
                    'bio' => $bio,
                    'avatar' => $avatar,
                ], 'id = :id', [':id' => $userId]);
                $basicSaved = true;
            } catch (Exception $e) {
                $message = '基本资料保存失败: ' . $e->getMessage();
                $messageType = 'danger';
                $basicSaved = false;
            }

            if ($basicSaved) {
                Auth::refresh();
                log_operation('update', 'user', $userId, '修改个人资料');
                $message = '设置已保存';
                $messageType = 'success';
            }
        }
    }
}

// 处理密码修改
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    CSRF::check();
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $user = Auth::user();
    if (!safe_password_verify($oldPassword, $user['password'])) {
        $message = '原密码不正确';
        $messageType = 'danger';
    } elseif (strlen($newPassword) < 8) {
        $message = '新密码至少8位';
        $messageType = 'danger';
    } elseif ($newPassword !== $confirmPassword) {
        $message = '两次新密码输入不一致';
        $messageType = 'danger';
    } else {
        try {
            DB::update(DB_PREFIX . 'users', [
                'password' => password_hash($newPassword, PASSWORD_DEFAULT),
            ], 'id = :id', [':id' => $userId]);
            log_operation('update', 'user', $userId, '修改密码');
            $message = '密码已修改,请重新登录';
            $messageType = 'success';
            Auth::logout();
            redirect(url('login.php'));
        } catch (Exception $e) {
            $message = '修改失败: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// 重新发送验证邮件
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resend_verify'])) {
    CSRF::check();
    $u = Auth::user();
    if (!empty($u['email']) && empty($u['email_verified'])) {
        if (setting('mail_enabled', '0') === '1') {
            $raw = new_token();
            $hash = password_hash($raw, PASSWORD_DEFAULT);
            DB::update(DB_PREFIX . 'users', [
                'verify_token'  => $hash,
                'verify_expire' => time() + 86400,
            ], 'id = :id', [':id' => $userId]);
            $verifyUrl = abs_url('verify.php?token=' . $raw);
            $site = setting('site_name', '书签导航');
            $res = Mailer::send($u['email'], '[' . $site . '] 请验证您的邮箱', Mailer::verifyTemplate($site, $verifyUrl));
            $message = $res['ok'] ? '验证邮件已重新发送,请查收' : '验证邮件发送失败(' . $res['msg'] . '),请稍后重试';
            $messageType = $res['ok'] ? 'success' : 'danger';
        } else {
            $message = '邮件功能未开启,请联系管理员在系统设置中配置 SMTP 后重试';
            $messageType = 'warning';
        }
    } else {
        $message = '当前邮箱已验证或为空,无需重发';
        $messageType = 'info';
    }
}
$user = Auth::user();
include __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="user-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="user-main">
      <?php if ($message): ?>
      <div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
      <?php endif; ?>
      <?php if (!empty($user['email']) && empty($user['email_verified'])): ?>
      <div class="alert alert-warning">
        ⚠️ 您的邮箱 <b><?php echo e($user['email']); ?></b> 尚未验证。
        <form method="post" action="" style="display:inline;">
          <?php echo CSRF::field(); ?>
          <input type="hidden" name="resend_verify" value="1">
          <button type="submit" class="btn btn-sm btn-warning" style="margin-left:8px;vertical-align:middle;">重新发送验证邮件</button>
        </form>
      </div>
      <?php endif; ?>
      <div class="card">
        <div class="card-header"><h3>基本资料</h3></div>
        <form method="post" action="">
          <?php echo CSRF::field(); ?>
          <input type="hidden" name="save_profile" value="1">
          <div class="form-row">
            <div class="form-group">
              <label>用户名(不可修改)</label>
              <input type="text" class="form-control" value="<?php echo e($user['username']); ?>" disabled>
              <div class="form-hint">个人收藏夹地址: <?php echo e(url('u/' . $user['username'])); ?></div>
            </div>
            <div class="form-group">
              <label>昵称</label>
              <input type="text" name="nickname" class="form-control" required value="<?php echo e($user['nickname']); ?>">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>邮箱</label>
              <input type="email" name="email" class="form-control" value="<?php echo e($user['email']); ?>">
            </div>
            <div class="form-group">
              <label>头像URL(可选)</label>
              <input type="text" name="avatar" class="form-control" placeholder="https://..." value="<?php echo e($user['avatar']); ?>">
            </div>
          </div>
          <div class="form-group">
            <label>个人简介</label>
            <textarea name="bio" class="form-control" rows="2" maxlength="255"><?php echo e($user['bio']); ?></textarea>
          </div>

          <div class="form-hint" style="margin:8px 0 4px;">
            界面主题、深色模式与首页布局请在首页右上角的「界面」面板中设置（登录后自动保存到你的账号）。
          </div>

          <button type="submit" class="btn btn-primary">保存设置</button>
        </form>
      </div>
      <div class="card">
        <div class="card-header"><h3>修改密码</h3></div>
        <form method="post" action="">
          <?php echo CSRF::field(); ?>
          <input type="hidden" name="change_password" value="1">
          <div class="form-group">
            <label>原密码</label>
            <input type="password" name="old_password" class="form-control" required>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>新密码</label>
              <input type="password" name="new_password" class="form-control" required>
              <div class="form-hint">至少8位</div>
            </div>
            <div class="form-group">
              <label>确认新密码</label>
              <input type="password" name="confirm_password" class="form-control" required>
            </div>
          </div>
          <button type="submit" class="btn btn-warning">修改密码</button>
        </form>
      </div>
      <div class="card">
        <div class="card-header"><h3>账号信息</h3></div>
        <table class="table">
          <tr><th style="width:140px;">用户ID</th><td><?php echo (int)$user['id']; ?></td></tr>
          <tr><th>角色</th><td><?php echo $user['role'] === 'admin' ? '<span class="badge badge-primary">管理员</span>' : '<span class="badge badge-muted">普通用户</span>'; ?></td></tr>
          <tr><th>邮箱验证</th><td><?php echo empty($user['email_verified']) ? '<span class="badge badge-warning">未验证</span>' : '<span class="badge badge-success">已验证</span>'; ?></td></tr>
          <tr><th>注册时间</th><td><?php echo e(date('Y-m-d H:i:s', strtotime($user['created_at']))); ?></td></tr>
          <tr><th>最后登录</th><td><?php echo $user['last_login_at'] ? e(date('Y-m-d H:i:s', strtotime($user['last_login_at']))) : '-'; ?></td></tr>
          <tr><th>最后登录IP</th><td><?php echo e($user['last_login_ip'] ?: '-'); ?></td></tr>
        </table>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
