<?php
/**
 * 后台管理 - 界面主题（全局默认值）
 * v1.3.9.6: 设置全站默认的 主题颜色 / 深色模式 / 首页布局
 * 说明：这里保存的是「默认值」，优先级低于登录用户的个人设置；
 *       管理员自己的前台偏好在首页右上角「界面」面板中设置，仅作用于本人。
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
$adminPage = 'theme';
$pageTitle = '界面主题';
$currentPage = 'admin';
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_theme'])) {
    CSRF::check();
    $themes = get_theme_list();
    $defaultTheme = trim($_POST['default_theme'] ?? 'default');
    if (!isset($themes[$defaultTheme])) $defaultTheme = 'default';
    $defaultDark = trim($_POST['default_dark_mode'] ?? 'off');
    if (!in_array($defaultDark, ['off', 'on', 'auto'], true)) $defaultDark = 'off';
    $defaultLayout = trim($_POST['default_home_layout'] ?? 'default');
    if (!in_array($defaultLayout, ['default', 'vertical', 'horizontal', 'card'], true)) $defaultLayout = 'default';

    $settings = [
        'default_theme'        => $defaultTheme,
        'default_dark_mode'    => $defaultDark,
        'default_home_layout'  => $defaultLayout,
    ];
    foreach ($settings as $key => $value) {
        $exists = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "settings WHERE skey = ?", [$key]);
        if ($exists) {
            DB::update(DB_PREFIX . 'settings', ['svalue' => $value], 'skey = :k', [':k' => $key]);
        } else {
            DB::insert(DB_PREFIX . 'settings', ['skey' => $key, 'svalue' => $value, 'description' => '界面主题全局默认值']);
        }
    }
    // 强制刷新设置缓存
    $GLOBALS['settings'] = load_settings(true);
    log_operation('update', 'setting', 0, '修改界面主题全局默认');
    $message = '界面主题默认设置已保存';
    $messageType = 'success';
}

$currentTheme = setting('default_theme', 'default');
$currentDark = setting('default_dark_mode', 'off');
$currentLayout = setting('default_home_layout', 'default');
$themes = get_theme_list();

include __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="admin-main">
    <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header"><h3>界面主题（全局默认）</h3></div>
      <form method="post" action="">
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="save_theme" value="1">
        <div class="form-group">
          <label>默认主题颜色</label>
          <select name="default_theme" class="form-control">
            <?php foreach ($themes as $key => $t): ?>
            <option value="<?php echo e($key); ?>" <?php echo $currentTheme === $key ? 'selected' : ''; ?>><?php echo e($t['name']); ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-hint">未登录访客与未单独设置偏好的用户将使用此默认主题</div>
        </div>
        <div class="form-group">
          <label>默认深色模式</label>
          <select name="default_dark_mode" class="form-control">
            <option value="off" <?php echo $currentDark === 'off' ? 'selected' : ''; ?>>关闭（浅色）</option>
            <option value="on" <?php echo $currentDark === 'on' ? 'selected' : ''; ?>>开启（深色）</option>
            <option value="auto" <?php echo $currentDark === 'auto' ? 'selected' : ''; ?>>自动（跟随系统）</option>
          </select>
        </div>
        <div class="form-group">
          <label>默认首页布局</label>
          <select name="default_home_layout" class="form-control">
            <option value="default" <?php echo $currentLayout === 'default' ? 'selected' : ''; ?>>默认（顶部分类按序展开）</option>
            <option value="vertical" <?php echo $currentLayout === 'vertical' ? 'selected' : ''; ?>>分类竖排（左侧分类导航）</option>
            <option value="horizontal" <?php echo $currentLayout === 'horizontal' ? 'selected' : ''; ?>>分类横排（顶部分类标签）</option>
            <option value="card" <?php echo $currentLayout === 'card' ? 'selected' : ''; ?>>分类卡片（分类卡片平铺）</option>
          </select>
          <div class="form-hint">用户可在首页右上角「界面」面板中覆盖此默认布局</div>
        </div>
        <button type="submit" class="btn btn-primary">保存默认设置</button>
      </form>
    </div>
    <div class="card">
      <div class="card-header"><h3>说明</h3></div>
      <div class="card-body" style="line-height:1.9;color:var(--text-light);">
        <p>· 本页设置的是<strong>全站默认值</strong>，优先级低于每位登录用户的个人设置。</p>
        <p>· 访客（未登录）会使用此处设置的默认值，其临时调整仅保存在本机浏览器 Cookie 中。</p>
        <p>· 登录用户在首页右上角点击「界面」面板，可自选主题颜色、深色模式与首页布局，设置会保存到其账号。</p>
        <p>· 管理员本人的前台偏好，同样通过首页「界面」面板设置，仅作用于管理员自己。</p>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
