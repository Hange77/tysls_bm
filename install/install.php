<?php
/**
 * 一键安装程序
 * 访问 /install/install.php 启动安装
 * 安装完成后会自动生成 lock 文件,防止重复安装
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

$rootPath = dirname(__DIR__);
$lockFile = $rootPath . '/install/install.lock';
$configFile = $rootPath . '/config.php';

// 已安装检测
if (file_exists($lockFile)) {
    die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>已安装</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f5f5f5;}
    .box{text-align:center;background:#fff;padding:40px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.1);}
    h1{color:#333;}p{color:#666;}a{color:#4a90d9;text-decoration:none;}</style></head>
    <body><div class="box"><h1>系统已安装</h1><p>如需重新安装,请删除 install/install.lock 文件</p>
    <p><a href="../index.php">进入首页</a></p></div></body></html>');
}

// 更严格的已安装检测: 若配置文件已存在,则视为已安装,避免 lock 被误删后遭到重装覆盖
if (file_exists($configFile)) {
    die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>已安装</title>
    <style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f5f5f5;}
    .box{text-align:center;background:#fff;padding:40px;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.1);}
    h1{color:#333;}p{color:#666;}a{color:#4a90d9;text-decoration:none;}</style></head>
    <body><div class="box"><h1>系统已安装</h1><p>检测到配置文件已存在,若需重新安装请先删除 config.php 与 install/install.lock</p>
    <p><a href="../index.php">进入首页</a></p></div></body></html>');
}

$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$errors = [];
$success = false;

// 环境检测
$phpVersion = PHP_VERSION;
$phpOk = version_compare($phpVersion, '7.4.0', '>=');
$extensions = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'session'];
$extStatus = [];
foreach ($extensions as $ext) {
    $extStatus[$ext] = extension_loaded($ext);
}
$allExtOk = !in_array(false, $extStatus, true);

// 目录可写检测
$writableDirs = [$rootPath, $rootPath . '/uploads', $rootPath . '/install'];
$dirStatus = [];
foreach ($writableDirs as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $dirStatus[$dir] = is_writable($dir);
}
$allDirOk = !in_array(false, $dirStatus, true);

// 处理安装提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbPort = trim($_POST['db_port'] ?? '3306');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $dbPrefix = trim($_POST['db_prefix'] ?? 'bm_');

    $adminUsername = trim($_POST['admin_username'] ?? 'admin');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';
    $adminPassword2 = $_POST['admin_password2'] ?? '';

    // 验证
    if (!$dbHost) $errors[] = '请填写数据库主机';
    if (!$dbName) $errors[] = '请填写数据库名';
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $dbName)) $errors[] = '数据库名格式不正确(只能包含字母数字下划线,且以字母或下划线开头)';
    if (!$dbUser) $errors[] = '请填写数据库用户名';
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $dbPrefix)) $errors[] = '表前缀格式不正确(只能字母数字下划线,字母开头)';
    if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,19}$/', $adminUsername)) $errors[] = '管理员用户名格式不正确(3-20位,字母开头,字母数字下划线)';
    if ($adminEmail && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) $errors[] = '管理员邮箱格式不正确';
    if (strlen($adminPassword) < 6) $errors[] = '管理员密码至少6位';
    if ($adminPassword !== $adminPassword2) $errors[] = '两次密码输入不一致';

    if (empty($errors)) {
        // 测试数据库连接
        try {
            $dsn = "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            // 创建数据库(如果不存在)
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // 读取并执行SQL
            $sqlFile = $rootPath . '/install/schema.sql';
            $sql = file_get_contents($sqlFile);
            $sql = str_replace('{prefix}', $dbPrefix, $sql);

            // 标准化换行符并按语句执行 SQL
            // 使用字符级状态机,正确处理字符串字面量内的分号(避免误切)
            $sql = str_replace("\r\n", "\n", $sql);
            $sql = str_replace("\r", "\n", $sql);
            $statements = [];
            $current = '';
            $inString = false;
            $len = strlen($sql);
            for ($i = 0; $i < $len; $i++) {
                $ch = $sql[$i];
                if ($ch === "'") {
                    // 处理转义的单引号('')
                    if ($inString && $i + 1 < $len && $sql[$i + 1] === "'") {
                        $current .= "''";
                        $i++;
                        continue;
                    }
                    $inString = !$inString;
                    $current .= $ch;
                } elseif ($ch === ';' && !$inString) {
                    if (trim($current) !== '') {
                        $statements[] = trim($current);
                    }
                    $current = '';
                } else {
                    $current .= $ch;
                }
            }
            if (trim($current) !== '') {
                $statements[] = trim($current);
            }
            foreach ($statements as $stmt) {
                // 跳过纯注释/空语句
                if (preg_match('/^\s*(--|#|\/\*)/', $stmt)) {
                    continue;
                }
                if (!empty($stmt)) {
                    $pdo->exec($stmt);
                }
            }

            // 创建管理员
            $passwordHash = password_hash($adminPassword, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO `{$dbPrefix}users` (username, email, password, nickname, role, is_active, email_verified) VALUES (?, ?, ?, ?, 'admin', 1, 1)");
            $stmt->execute([$adminUsername, $adminEmail, $passwordHash, $adminUsername]);
            $adminId = $pdo->lastInsertId();

            // 写入配置文件(使用var_export确保安全转义)
            // 自动检测应用基础路径(如 /v1)
            $detectedBasePath = '';
            if (!empty($_SERVER['SCRIPT_NAME'])) {
                if (preg_match('#^(.+)/install/install\.php$#i', $_SERVER['SCRIPT_NAME'], $m)) {
                    $detectedBasePath = rtrim($m[1], '/');
                }
            }

            $configContent = "<?php\n";
            $configContent .= "/**\n * 数据库配置文件 - 由安装程序自动生成\n */\n";
            $configContent .= "define('DB_HOST', " . var_export($dbHost, true) . ");\n";
            $configContent .= "define('DB_PORT', " . var_export($dbPort, true) . ");\n";
            $configContent .= "define('DB_NAME', " . var_export($dbName, true) . ");\n";
            $configContent .= "define('DB_USER', " . var_export($dbUser, true) . ");\n";
            $configContent .= "define('DB_PASS', " . var_export($dbPass, true) . ");\n";
            $configContent .= "define('DB_PREFIX', " . var_export($dbPrefix, true) . ");\n";
            if ($detectedBasePath) {
                $configContent .= "if (!defined('BASE_PATH')) { define('BASE_PATH', " . var_export($detectedBasePath, true) . "); }\n";
            }
            $configContent .= "\n";
            $configContent .= "// 安全:禁止直接访问\n";
            $configContent .= "if (!defined('ROOT_PATH')) { exit('Access Denied'); }\n";

            if (file_put_contents($configFile, $configContent) === false) {
                $errors[] = '配置文件写入失败,请检查目录权限';
            } else {
                // 创建锁文件
                file_put_contents($lockFile, date('Y-m-d H:i:s') . ' 安装完成,管理员ID:' . $adminId . "\n");
                $success = true;
                $step = 3;
            }

        } catch (PDOException $e) {
            $errors[] = '数据库连接失败: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>书签导航系统 - 安装向导</title>
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;background:#f0f2f5;color:#333;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
.container{background:#fff;border-radius:12px;box-shadow:0 4px 24px rgba(0,0,0,.08);max-width:640px;width:100%;overflow:hidden;}
.header{background:linear-gradient(135deg,#4a90d9,#357abd);color:#fff;padding:30px 40px;}
.header h1{font-size:24px;margin-bottom:6px;}
.header p{opacity:.85;font-size:14px;}
.steps{display:flex;padding:20px 40px;background:#fafafa;border-bottom:1px solid #eee;}
.step-item{flex:1;text-align:center;position:relative;}
.step-num{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:50%;background:#ddd;color:#fff;font-weight:bold;font-size:14px;}
.step-item.active .step-num{background:#4a90d9;}
.step-item.done .step-num{background:#5cb85c;}
.step-label{font-size:12px;color:#999;margin-top:6px;}
.step-item.active .step-label{color:#4a90d9;font-weight:600;}
.body{padding:30px 40px;}
.form-group{margin-bottom:20px;}
.form-group label{display:block;font-weight:600;margin-bottom:6px;font-size:14px;color:#555;}
.form-group input{width:100%;padding:10px 14px;border:1px solid #ddd;border-radius:6px;font-size:14px;transition:border-color .2s;}
.form-group input:focus{outline:none;border-color:#4a90d9;box-shadow:0 0 0 3px rgba(74,144,217,.1);}
.form-row{display:flex;gap:16px;}
.form-row .form-group{flex:1;}
.hint{font-size:12px;color:#999;margin-top:4px;}
.check-list{list-style:none;}
.check-list li{padding:10px 0;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;justify-content:space-between;}
.check-list li:last-child{border-bottom:none;}
.status-ok{color:#5cb85c;font-weight:600;}
.status-fail{color:#d9534f;font-weight:600;}
.btn{display:inline-block;padding:12px 32px;background:#4a90d9;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:600;cursor:pointer;text-decoration:none;transition:background .2s;}
.btn:hover{background:#357abd;}
.btn:disabled{background:#ccc;cursor:not-allowed;}
.btn-success{background:#5cb85c;}
.btn-success:hover{background:#4cae4c;}
.error-box{background:#fdf0f0;border:1px solid #f5c6c6;color:#d9534f;padding:14px 18px;border-radius:6px;margin-bottom:20px;}
.error-box ul{margin-left:20px;}
.success-box{text-align:center;padding:20px 0;}
.success-icon{font-size:64px;margin-bottom:16px;}
.success-box h2{color:#5cb85c;margin-bottom:12px;}
.success-box p{color:#666;margin-bottom:8px;}
.footer{text-align:center;padding:16px;color:#999;font-size:12px;background:#fafafa;}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <h1>书签导航系统</h1>
    <p>一键安装向导</p>
  </div>
  <div class="steps">
    <div class="step-item <?php echo $step >= 1 ? ($step > 1 ? 'done' : 'active') : ''; ?>">
      <div class="step-num"><?php echo $step > 1 ? '✓' : '1'; ?></div>
      <div class="step-label">环境检测</div>
    </div>
    <div class="step-item <?php echo $step >= 2 ? ($step > 2 ? 'done' : 'active') : ''; ?>">
      <div class="step-num"><?php echo $step > 2 ? '✓' : '2'; ?></div>
      <div class="step-label">配置信息</div>
    </div>
    <div class="step-item <?php echo $step >= 3 ? 'active' : ''; ?>">
      <div class="step-num">3</div>
      <div class="step-label">完成</div>
    </div>
  </div>
  <div class="body">

<?php if ($step === 1): ?>
  <h3 style="margin-bottom:20px;">运行环境检测</h3>
  <ul class="check-list">
    <li>
      <span>PHP 版本 (>= 7.4, 当前 <?php echo htmlspecialchars($phpVersion); ?>)</span>
      <span class="<?php echo $phpOk ? 'status-ok' : 'status-fail'; ?>"><?php echo $phpOk ? '✓ 通过' : '✗ 不满足'; ?></span>
    </li>
    <?php foreach ($extStatus as $ext => $ok): ?>
    <li>
      <span>PHP 扩展: <?php echo htmlspecialchars($ext); ?></span>
      <span class="<?php echo $ok ? 'status-ok' : 'status-fail'; ?>"><?php echo $ok ? '✓ 已安装' : '✗ 未安装'; ?></span>
    </li>
    <?php endforeach; ?>
    <?php foreach ($dirStatus as $dir => $ok): ?>
    <li>
      <span>目录可写: <?php echo htmlspecialchars(basename($dir) ?: $dir); ?></span>
      <span class="<?php echo $ok ? 'status-ok' : 'status-fail'; ?>"><?php echo $ok ? '✓ 可写' : '✗ 不可写'; ?></span>
    </li>
    <?php endforeach; ?>
  </ul>
  <div style="margin-top:24px;text-align:center;">
    <a href="?step=2" class="btn" <?php echo (!$phpOk || !$allExtOk || !$allDirOk) ? 'onclick="return false;" style="background:#ccc;cursor:not-allowed;"' : ''; ?>>下一步:配置信息</a>
  </div>
  <?php if (!$phpOk || !$allExtOk || !$allDirOk): ?>
  <p style="color:#d9534f;text-align:center;margin-top:12px;font-size:13px;">请先解决上述问题后再继续安装</p>
  <?php endif; ?>

<?php elseif ($step === 2): ?>
  <?php if (!empty($errors)): ?>
  <div class="error-box">
    <strong>请修正以下问题:</strong>
    <ul><?php foreach ($errors as $err): ?><li><?php echo htmlspecialchars($err); ?></li><?php endforeach; ?></ul>
  </div>
  <?php endif; ?>
  <form method="post" action="?step=2">
    <h3 style="margin-bottom:16px;">数据库配置</h3>
    <div class="form-row">
      <div class="form-group">
        <label>数据库主机</label>
        <input type="text" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>" required>
      </div>
      <div class="form-group">
        <label>端口</label>
        <input type="text" name="db_port" value="<?php echo htmlspecialchars($_POST['db_port'] ?? '3306'); ?>" required>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>数据库名</label>
        <input type="text" name="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>" placeholder="如 bookmark" required>
      </div>
      <div class="form-group">
        <label>表前缀</label>
        <input type="text" name="db_prefix" value="<?php echo htmlspecialchars($_POST['db_prefix'] ?? 'bm_'); ?>" required>
        <div class="hint">建议保持默认 bm_</div>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>数据库用户名</label>
        <input type="text" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>" required>
      </div>
      <div class="form-group">
        <label>数据库密码</label>
        <input type="password" name="db_pass" value="<?php echo htmlspecialchars($_POST['db_pass'] ?? ''); ?>">
      </div>
    </div>

    <h3 style="margin:28px 0 16px;">管理员账号</h3>
    <div class="form-group">
      <label>管理员用户名</label>
      <input type="text" name="admin_username" value="<?php echo htmlspecialchars($_POST['admin_username'] ?? 'admin'); ?>" required>
      <div class="hint">3-20位,字母开头,只能包含字母数字下划线</div>
    </div>
    <div class="form-group">
      <label>管理员邮箱(可选)</label>
      <input type="email" name="admin_email" value="<?php echo htmlspecialchars($_POST['admin_email'] ?? ''); ?>">
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>管理员密码</label>
        <input type="password" name="admin_password" required>
        <div class="hint">至少6位</div>
      </div>
      <div class="form-group">
        <label>确认密码</label>
        <input type="password" name="admin_password2" required>
      </div>
    </div>
    <div style="margin-top:24px;text-align:center;">
      <a href="?step=1" style="margin-right:16px;color:#999;text-decoration:none;">上一步</a>
      <button type="submit" class="btn">开始安装</button>
    </div>
  </form>

<?php elseif ($step === 3 && $success): ?>
  <div class="success-box">
    <div class="success-icon">🎉</div>
    <h2>安装成功!</h2>
    <p>系统已成功安装,配置文件已生成。</p>
    <p style="color:#d9534f;font-size:13px;margin-top:16px;">安全提示: 建议删除 install 目录或确保 install.lock 存在</p>
    <div style="margin-top:28px;">
      <a href="../index.php" class="btn btn-success">进入首页</a>
      <a href="../login.php" class="btn" style="margin-left:12px;">登录后台</a>
    </div>
  </div>
<?php endif; ?>

  </div>
  <div class="footer">书签导航系统 v1.4.1.9 &copy; <?php echo date('Y'); ?></div>
</div>
</body>
</html>
