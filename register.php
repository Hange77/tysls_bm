<?php
/**
 * 注册
 */
require_once __DIR__ . '/includes/init.php';

if (Auth::check()) {
    redirect(url('user/'));
}

if (setting('allow_register', '1') !== '1') {
    die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>注册已关闭</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f5f5f5;}
    .box{text-align:center;background:#fff;padding:40px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.1);}
    h1{color:#333;}p{color:#666;}a{color:#4a90d9;text-decoration:none;}</style></head>
    <body><div class="box"><h1>注册已关闭</h1><p>请联系管理员开通账号</p><p><a href="login.php">返回登录</a></p></div></body></html>');
}

$error = '';
$now = time();
// 注册频率限制: IP 维度 1 小时内最多 10 次,防脚本批量刷号(不再依赖会话,清 cookie 无法绕过)
$rlKey = 'register_' . get_client_ip();
$rl = rate_limit_check($rlKey, 10, 3600);
if ($rl['limited']) {
    die('注册过于频繁,请稍后再试');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    // 每次提交都计入频率限制(含校验失败),防脚本批量刷号
    rate_limit_hit($rlKey);
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';

    if (!validate_username($username)) {
        $error = '用户名格式不正确(3-20位,字母开头,只能包含字母数字下划线)';
    } elseif (!$email) {
        $error = '请填写邮箱';
    } elseif (!validate_email($email)) {
        $error = '邮箱格式不正确';
    } elseif (strlen($password) < 8) {
        $error = '密码至少8位';
    } elseif ($password !== $password2) {
        $error = '两次密码输入不一致';
    } else {
        $result = Auth::register($username, $email, $password);
        if ($result === 'exists') {
            $error = '用户名或邮箱已被注册';
        } else {
            // 注册成功: 处理邮箱验证
            $userId = (int)$result;
            if (setting('mail_enabled', '0') === '1') {
                $raw = new_token();
                $hash = password_hash($raw, PASSWORD_DEFAULT);
                DB::update(DB_PREFIX . 'users', [
                    'email_verified' => 0,
                    'verify_token'   => $hash,
                    'verify_expire'  => time() + 86400, // 24 小时
                ], 'id = :id', [':id' => $userId]);
                $verifyUrl = abs_url('verify.php?token=' . $raw);
                $site = setting('site_name', '书签导航');
                $res = Mailer::send($email, '[' . $site . '] 请验证您的邮箱', Mailer::verifyTemplate($site, $verifyUrl));
                if ($res['ok']) {
                    set_flash('success', '注册成功,验证邮件已发送至 ' . $email . ',请查收完成验证');
                } else {
                    set_flash('warning', '注册成功,但验证邮件发送失败(' . $res['msg'] . '),您可在个人中心重新发送');
                }
            } else {
                // 未配置邮件服务, 视为已验证, 不阻断使用
                DB::update(DB_PREFIX . 'users', ['email_verified' => 1], 'id = :id', [':id' => $userId]);
            }
            // 注册成功自动登录
            Auth::login($username, $password);
            redirect(url('user/'));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>注册 - <?php echo e(setting('site_name', '书签导航')); ?></title>
<link rel="stylesheet" href="<?php echo asset('css/style.css'); ?>">
</head>
<body class="auth-page">
<div class="auth-box">
  <h1>🔖 <?php echo e(setting('site_name', '书签导航')); ?></h1>
  <p class="subtitle">创建您的账号,开始收藏</p>

  <?php if ($error): ?>
  <div class="alert alert-danger"><?php echo e($error); ?></div>
  <?php endif; ?>

  <form method="post" action="">
    <?php echo CSRF::field(); ?>
    <div class="form-group">
      <label>用户名</label>
      <input type="text" name="username" class="form-control" required autofocus value="<?php echo e($_POST['username'] ?? ''); ?>">
      <div class="form-hint">3-20位,字母开头,只能包含字母数字下划线,将用于个人收藏夹地址</div>
    </div>
    <div class="form-group">
      <label>邮箱 *</label>
      <input type="email" name="email" class="form-control" required value="<?php echo e($_POST['email'] ?? ''); ?>">
    </div>
    <div class="form-group">
      <label>密码</label>
      <input type="password" name="password" class="form-control" required>
      <div class="form-hint">至少8位</div>
    </div>
    <div class="form-group">
      <label>确认密码</label>
      <input type="password" name="password2" class="form-control" required>
    </div>
    <button type="submit" class="btn btn-primary btn-lg btn-block">注 册</button>
  </form>

  <div class="auth-footer">
    已有账号? <a href="<?php echo url('login.php'); ?>">立即登录</a>
    <br><a href="<?php echo url(''); ?>" style="margin-top:8px;display:inline-block;">返回首页</a>
  </div>
</div>
</body>
</html>
