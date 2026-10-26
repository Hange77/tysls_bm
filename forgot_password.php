<?php
/**
 * 找回密码 - 申请重置链接
 */
require_once __DIR__ . '/includes/init.php';
$pageTitle = '找回密码';
$currentPage = 'home';

$message = '';
$messageType = '';
$now = time();
// IP 维度频率限制(防止邮件轰炸): 同一 IP 5 分钟内最多 5 次申请
$rlKey = 'forgot_' . get_client_ip();
$rl = rate_limit_check($rlKey, 5, 300);
if ($rl['limited']) {
    $wait = ceil($rl['retry_after'] / 60);
    $message = '请求过于频繁,请 ' . $wait . ' 分钟后再试';
    $messageType = 'danger';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$message) {
    CSRF::check();
    $email = trim($_POST['email'] ?? '');
    if (!validate_email($email)) {
        $message = '请输入正确的邮箱地址';
        $messageType = 'danger';
    } else {
        $user = DB::fetch("SELECT * FROM " . DB_PREFIX . "users WHERE email = ?", [$email]);
        $mailOn = setting('mail_enabled', '0') === '1';
        if ($mailOn && $user) {
            $raw = new_token();
            $hash = password_hash($raw, PASSWORD_DEFAULT);
            DB::update(DB_PREFIX . 'users', [
                'reset_token'  => $hash,
                'reset_expire' => $now + 1800, // 30 分钟
            ], 'id = :id', [':id' => $user['id']]);
            $resetUrl = abs_url('reset_password.php?token=' . $raw);
            $site = setting('site_name', '书签导航');
            $res = Mailer::send($email, '[' . $site . '] 重置您的密码', Mailer::resetTemplate($site, $resetUrl));
            if (!$res['ok']) {
                error_log('重置密码邮件发送失败: ' . $res['msg']);
            }
        }
        // 无论邮箱是否存在, 统一提示, 避免账号枚举
        rate_limit_hit($rlKey);
        if ($mailOn) {
            $message = '如果该邮箱已注册,重置链接已发送到您的邮箱,请查收(30 分钟内有效)。';
            $messageType = 'success';
        } else {
            $message = '邮件功能未开启,无法发送重置链接,请联系管理员重置密码。';
            $messageType = 'warning';
        }
    }
}

include __DIR__ . '/includes/header.php';
?>
<div class="auth-page"><div class="auth-box">
  <h1>🔖 找回密码</h1>
  <p class="subtitle">请输入注册时使用的邮箱</p>

  <?php if ($message): ?>
  <div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
  <?php endif; ?>

  <form method="post" action="">
    <?php echo CSRF::field(); ?>
    <div class="form-group">
      <label>邮箱</label>
      <input type="email" name="email" class="form-control" required value="<?php echo e($_POST['email'] ?? ''); ?>">
    </div>
    <button type="submit" class="btn btn-primary btn-lg btn-block">发送重置链接</button>
  </form>

  <div class="auth-footer">
    <a href="<?php echo url('login.php'); ?>">返回登录</a>
    <?php if (setting('allow_register', '1') === '1'): ?>
    | <a href="<?php echo url('register.php'); ?>">注册新账号</a>
    <?php endif; ?>
  </div>
</div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
