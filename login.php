<?php
/**
 * 登录
 */
require_once __DIR__ . '/includes/init.php';

if (Auth::check()) {
    redirect(url('user/'));
}

$error = '';
// 频率限制已下沉到 Auth::login() (IP + 账号双维度, 落地临时目录, 不依赖会话)

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$username || !$password) {
        $error = '请输入用户名和密码';
    } else {
        $result = Auth::login($username, $password);
        if ($result === true) {
            $redirect = $_SESSION['redirect_after_login'] ?? url('user/');
            unset($_SESSION['redirect_after_login']);
            redirect($redirect);
        } elseif ($result === 'locked') {
            // 提示具体的锁定剩余时间(IP 维度优先, 回退账号维度)
            $ipRl = rate_limit_check('login_ip_' . get_client_ip(), 20, 600);
            $userRl = rate_limit_check('login_user_' . $username, 5, 900);
            $wait = max($ipRl['retry_after'], $userRl['retry_after']);
            $error = '登录尝试过于频繁,请 ' . ceil($wait / 60) . ' 分钟后再试,或前往"忘记密码"重置';
        } elseif ($result === 'disabled') {
            $error = '账号已被禁用,请联系管理员';
        } else {
            $error = '用户名或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>登录 - <?php echo e(setting('site_name', '书签导航')); ?></title>
<link rel="stylesheet" href="<?php echo asset('css/style.css'); ?>">
</head>
<body class="auth-page">
<div class="auth-box">
  <h1>🔖 <?php echo e(setting('site_name', '书签导航')); ?></h1>
  <p class="subtitle">欢迎回来,请登录您的账号</p>

  <?php if ($error): ?>
  <div class="alert alert-danger"><?php echo e($error); ?></div>
  <?php endif; ?>

  <form method="post" action="">
    <?php echo CSRF::field(); ?>
    <div class="form-group">
      <label>用户名 / 邮箱</label>
      <input type="text" name="username" class="form-control" required autofocus value="<?php echo e($_POST['username'] ?? ''); ?>">
    </div>
    <div class="form-group">
      <label>密码</label>
      <input type="password" name="password" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-primary btn-lg btn-block">登 录</button>
  </form>

  <div class="auth-footer">
    还没有账号?
    <?php if (setting('allow_register', '1') === '1'): ?>
    <a href="<?php echo url('register.php'); ?>">立即注册</a>
    <?php else: ?>
    注册已关闭,请联系管理员
    <?php endif; ?>
    <br><a href="<?php echo url('forgot_password.php'); ?>" style="margin-top:8px;display:inline-block;">忘记密码?</a>
    <br><a href="<?php echo url(''); ?>" style="margin-top:8px;display:inline-block;">返回首页</a>
  </div>
</div>
</body>
</html>
