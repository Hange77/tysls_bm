<?php
/**
 * 后台管理 - 插件设置（浏览器书签同步插件）
 * v1.4.0.2: 新增「插件下载地址」配置（UPSERT 至 settings.plugin_download_url），供用户侧展示下载链接。
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
$adminPage = 'plugin';
$pageTitle = '插件设置';
$currentPage = 'admin';

$message = '';
$messageType = '';

// 处理令牌操作 / 下载地址保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_download_url') {
        $url = trim((string)($_POST['plugin_download_url'] ?? ''));
        if ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            $message = '下载地址格式不正确，请填写完整的 http(s) 链接。';
            $messageType = 'danger';
        } else {
            save_setting('plugin_download_url', $url);
            $GLOBALS['settings'] = load_settings(true); // 强制刷新缓存，避免保存后仍显示旧值
            $message = '插件下载地址已保存，用户侧「插件设置」将展示该下载链接。';
            $messageType = 'success';
        }
    } elseif ($action === 'create_token') {
        $name = trim($_POST['token_name'] ?? '');
        $name = $name ?: ('插件-' . date('Y-m-d'));
        $token = new_token();
        DB::insert(DB_PREFIX . 'api_tokens', [
            'user_id'     => Auth::id(),
            'name'       => $name,
            'token_hash' => hash('sha256', $token),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $message = '已生成新令牌，请立即复制保存（仅显示一次）：<code style="user-select:all;">' . e($token) . '</code>';
        $messageType = 'success';
    } elseif ($action === 'revoke_token') {
        $tid = (int)($_POST['token_id'] ?? 0);
        if ($tid > 0) {
            DB::update(DB_PREFIX . 'api_tokens', ['revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [$tid]);
            $message = '令牌已吊销，使用该令牌的插件将立即失效。';
            $messageType = 'success';
        }
    }
}

// 站点地址与 API 端点
$siteUrl = setting('site_url');
if (!$siteUrl) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $siteUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? 'your-domain.com');
}
$apiEndpoint = rtrim($siteUrl, '/') . '/api/v1/';

// 本人令牌
$myTokens = DB::fetchAll(
    "SELECT * FROM " . DB_PREFIX . "api_tokens WHERE user_id = ? ORDER BY created_at DESC",
    [Auth::id()]
);

// 全站令牌概览（管理员安全审计用）
$allTokens = DB::fetchAll(
    "SELECT t.*, u.username FROM " . DB_PREFIX . "api_tokens t
     LEFT JOIN " . DB_PREFIX . "users u ON t.user_id = u.id
     ORDER BY t.created_at DESC LIMIT 200"
);

include __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="admin-main">
    <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header"><h3>插件接口信息</h3></div>
      <div class="card-body">
        <div class="form-group">
          <label>API 端点地址（插件「服务器地址」请填此项去掉 /api/v1/ 后的站点地址）</label>
          <input type="text" class="form-control" readonly value="<?php echo e($apiEndpoint); ?>">
          <div class="form-hint">插件设置中的「服务器地址」应填写：<code><?php echo e(rtrim($apiEndpoint, '/')); ?></code> 的上一级，即 <code><?php echo e(rtrim($siteUrl, '/')); ?></code></div>
        </div>
        <div class="form-group">
          <label>插件使用步骤</label>
          <ol class="plugin-steps">
            <li>在浏览器（Chrome / Edge / Firefox）中加载已解压的扩展程序（开发者模式）。</li>
            <li>打开扩展「设置」，填写上方<b>站点地址</b>、你的<b>用户名</b>与<b>密码</b>，点击「保存并登录」。</li>
            <li>选择一个浏览器书签文件夹作为同步根（默认新建「书签导航」），并选择同步方向。</li>
            <li>点击「立即同步」或开启「自动同步」，书签将进入系统「浏览器书签」分类。</li>
          </ol>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>插件下载地址配置</h3></div>
      <div class="card-body">
        <form method="post" action="">
          <?php echo CSRF::field(); ?>
          <input type="hidden" name="action" value="save_download_url">
          <div class="form-group">
            <label>插件下载地址（单一全局地址）</label>
            <input type="url" name="plugin_download_url" class="form-control" style="max-width:520px;"
                   placeholder="https://example.com/bookmark_extension.zip"
                   value="<?php echo e(trim((string)setting('plugin_download_url', ''))); ?>">
            <div class="form-hint">填写后，用户侧「我的收藏 → 插件设置」将展示该下载链接，方便用户直接获取安装包。留空则提示用户联系管理员。</div>
          </div>
          <button type="submit" class="btn btn-primary">保存下载地址</button>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>我的令牌</h3></div>
      <div class="card-body">
        <form method="post" action="" class="inline-form">
          <?php echo CSRF::field(); ?>
          <input type="hidden" name="action" value="create_token">
          <div class="form-group inline">
            <input type="text" name="token_name" class="form-control" placeholder="令牌名称（如：我的笔记本）">
            <button type="submit" class="btn btn-primary">生成新令牌</button>
          </div>
          <div class="form-hint">令牌即「个人访问令牌(PAT)」，插件登录后自动创建；也可在此手动生成并复制到支持 Bearer 的工具中。</div>
        </form>
        <div class="table-wrap" style="margin-top:14px;">
          <table class="table">
            <thead><tr><th>名称</th><th>创建时间</th><th>最近使用</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php if (!$myTokens): ?>
              <tr><td colspan="5" class="empty">暂无令牌，插件登录后将自动生成。</td></tr>
            <?php else: foreach ($myTokens as $t): ?>
              <tr>
                <td><?php echo e($t['name']); ?></td>
                <td><?php echo e($t['created_at']); ?></td>
                <td><?php echo $t['last_used_at'] ? e($t['last_used_at']) : '从未'; ?></td>
                <td><?php echo $t['revoked_at'] ? '<span class="badge badge-danger">已吊销</span>' : '<span class="badge badge-success">有效</span>'; ?></td>
                <td>
                  <?php if (!$t['revoked_at']): ?>
                  <form method="post" action="" style="display:inline;">
                    <?php echo CSRF::field(); ?>
                    <input type="hidden" name="action" value="revoke_token">
                    <input type="hidden" name="token_id" value="<?php echo (int)$t['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定吊销该令牌？插件将立即失效。');">吊销</button>
                  </form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><h3>全站令牌概览（安全审计）</h3></div>
      <div class="card-body">
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>用户</th><th>名称</th><th>创建时间</th><th>最近使用</th><th>状态</th><th>操作</th></tr></thead>
            <tbody>
            <?php if (!$allTokens): ?>
              <tr><td colspan="6" class="empty">暂无令牌。</td></tr>
            <?php else: foreach ($allTokens as $t): ?>
              <tr>
                <td><?php echo e($t['username'] ?? '已删除用户'); ?></td>
                <td><?php echo e($t['name']); ?></td>
                <td><?php echo e($t['created_at']); ?></td>
                <td><?php echo $t['last_used_at'] ? e($t['last_used_at']) : '从未'; ?></td>
                <td><?php echo $t['revoked_at'] ? '<span class="badge badge-danger">已吊销</span>' : '<span class="badge badge-success">有效</span>'; ?></td>
                <td>
                  <?php if (!$t['revoked_at']): ?>
                  <form method="post" action="" style="display:inline;">
                    <?php echo CSRF::field(); ?>
                    <input type="hidden" name="action" value="revoke_token">
                    <input type="hidden" name="token_id" value="<?php echo (int)$t['id']; ?>">
                    <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定吊销该用户令牌？其插件将立即失效。');">吊销</button>
                  </form>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</div>
<style>
  .plugin-steps { margin: 6px 0 0 18px; line-height: 1.9; }
  .plugin-steps li { margin-bottom: 2px; }
  .inline-form .inline { display: flex; gap: 8px; align-items: center; }
  .inline-form .inline .form-control { flex: 1; max-width: 320px; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
  .badge-success { background: #e6f7ec; color: #1a7f37; }
  .badge-danger { background: #fdecea; color: #c0392b; }
  .empty { color: #999; text-align: center; padding: 18px 0; }
  code { background: #f3f4f6; padding: 1px 6px; border-radius: 4px; font-size: 13px; }
</style>
<?php include __DIR__ . '/../includes/footer.php'; ?>
