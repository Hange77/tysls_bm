<?php
/**
 * 邮箱验证
 * 访问方式: verify.php?token=xxxx
 */
require_once __DIR__ . '/includes/init.php';
$pageTitle = '邮箱验证';
$currentPage = 'home';

$result = null;
if (isset($_GET['token']) && preg_match('/^[a-f0-9]{64}$/', $_GET['token'])) {
    $token = $_GET['token'];
    $user = user_by_verify_token($token);
    if ($user) {
        DB::update(DB_PREFIX . 'users', [
            'email_verified' => 1,
            'verify_token'   => '',
            'verify_expire'  => 0,
        ], 'id = :id', [':id' => $user['id']]);
        log_operation('update', 'user', $user['id'], '验证邮箱');
        $result = ['ok' => true, 'msg' => '邮箱验证成功,您的账号已完成邮箱验证。'];
    } else {
        $result = ['ok' => false, 'msg' => '验证链接无效或已过期,请重新发送验证邮件。'];
    }
} else {
    $result = ['ok' => false, 'msg' => '无效的验证链接。'];
}

include __DIR__ . '/includes/header.php';
?>
<div class="container" style="max-width:560px;margin:40px auto;">
  <div class="card">
    <div class="card-header"><h3>邮箱验证</h3></div>
    <div class="alert alert-<?php echo $result['ok'] ? 'success' : 'danger'; ?>"><?php echo e($result['msg']); ?></div>
    <p style="text-align:center;">
      <?php if (Auth::check()): ?>
      <a href="<?php echo url('user/profile.php'); ?>" class="btn btn-primary">前往个人中心</a>
      <?php else: ?>
      <a href="<?php echo url('login.php'); ?>" class="btn btn-primary">前往登录</a>
      <?php endif; ?>
    </p>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
