<?php
/**
 * 找回密码 - 重置密码
 * 访问方式: reset_password.php?token=xxxx
 */
require_once __DIR__ . '/includes/init.php';
$pageTitle = '重置密码';
$currentPage = 'home';

$token = $_GET['token'] ?? '';
$user = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) {
    $user = user_by_reset_token($token);
}

$message = '';
$messageType = '';
if (!$user) {
    $message = '重置链接无效或已过期,请重新申请。';
    $messageType = 'danger';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user) {
    CSRF::check();
    $pw = $_POST['password'] ?? '';
    $pw2 = $_POST['password2'] ?? '';
    if (strlen($pw) < 8) {
        $message = '密码至少8位';
        $messageType = 'danger';
    } elseif ($pw !== $pw2) {
        $message = '两次密码输入不一致';
        $messageType = 'danger';
    } else {
        DB::update(DB_PREFIX . 'users', [
            'password'      => password_hash($pw, PASSWORD_DEFAULT),
            'reset_token'   => '',
            'reset_expire'  => 0,
            'email_verified'=> 1, // 通过邮件重置即视为拥有该邮箱
        ], 'id = :id', [':id' => $user['id']]);
        log_operation('update', 'user', $user['id'], '通过邮件重置密码');
        set_flash('success', '密码已重置,请使用新密码登录');
        redirect(url('login.php'));
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="auth-page"><div class="auth-box">
  <h1>🔖 重置密码</h1>

  <?php if ($message): ?>
  <div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
  <?php endif; ?>

  <?php if ($user): ?>
  <form method="post" action="?token=<?php echo e($token); ?>">
    <?php echo CSRF::field(); ?>
    <div class="form-group">
      <label>新密码</label>
      <input type="password" name="password" class="form-control" required>
      <div class="form-hint">至少8位</div>
    </div>
    <div class="form-group">
      <label>确认新密码</label>
      <input type="password" name="password2" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-primary btn-lg btn-block">重置密码</button>
  </form>
  <?php endif; ?>

  <div class="auth-footer"><a href="<?php echo url('login.php'); ?>">返回登录</a></div>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
