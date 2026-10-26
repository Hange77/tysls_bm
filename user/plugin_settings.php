<?php
/**
 * 用户中心 - 插件设置（浏览器书签同步插件）
 * v1.4.0.2: 新增。供普通用户查看服务器地址、使用步骤、下载插件、管理本人令牌。
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();

$userId = Auth::id();
$userPage = 'plugin';
$pageTitle = '插件设置';
$currentPage = 'user';

$message = '';
$messageType = '';

// 处理令牌吊销（仅限本人）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke_token') {
    CSRF::check();
    $tid = (int)($_POST['token_id'] ?? 0);
    if ($tid > 0) {
        $row = DB::fetch("SELECT id FROM " . DB_PREFIX . "api_tokens WHERE id = ? AND user_id = ?", [$tid, $userId]);
        if ($row) {
            DB::update(DB_PREFIX . 'api_tokens', ['revoked_at' => date('Y-m-d H:i:s')], 'id = ?', [$tid]);
            $message = '令牌已吊销，使用该令牌的插件将立即失效。';
            $messageType = 'success';
        }
    }
}

// 站点地址（供插件填写「服务器地址」）
$siteUrl = setting('site_url');
if (!$siteUrl) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $siteUrl = $proto . '://' . ($_SERVER['HTTP_HOST'] ?? '');
}
$siteUrl = rtrim($siteUrl, '/');

// 管理员配置的插件下载地址
$pluginDownloadUrl = trim((string)setting('plugin_download_url', ''));

// 本人令牌
$myTokens = DB::fetchAll(
    "SELECT * FROM " . DB_PREFIX . "api_tokens WHERE user_id = ? ORDER BY created_at DESC",
    [$userId]
);

include __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="user-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="user-main">
      <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div><?php endif; ?>

      <div class="card">
        <div class="card-header"><h3>🧩 浏览器书签同步插件</h3></div>
        <div class="card-body">
          <p>安装官方浏览器插件（支持 Chrome / Edge / Firefox）后，可将本账号的书签与系统「浏览器书签」分类双向同步。</p>
          <ol class="plugin-steps">
            <li>在浏览器（Chrome / Edge / Firefox）中开启开发者模式，加载已解压的扩展程序。</li>
            <li>打开扩展「设置」，填写下方<b>服务器地址</b>、你的<b>用户名</b>与<b>密码</b>，点击「保存并登录」。</li>
            <li>选择一个浏览器书签文件夹作为同步根（默认新建「书签导航」），并选择同步方向。</li>
            <li>点击「立即同步」或开启「自动同步」，书签将进入系统「浏览器书签」分类。</li>
          </ol>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3>服务器地址</h3></div>
        <div class="card-body">
          <div class="form-group">
            <label>插件「服务器地址」请填写</label>
            <input type="text" class="form-control" readonly value="<?php echo e($siteUrl); ?>" onclick="this.select();">
            <div class="form-hint">点击输入框可全选复制。</div>
          </div>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3>插件下载</h3></div>
        <div class="card-body">
          <?php if ($pluginDownloadUrl): ?>
            <p>点击下方按钮下载官方插件扩展包（解压后「加载已解压的扩展程序」即可使用）：</p>
            <a href="<?php echo e($pluginDownloadUrl); ?>" class="btn btn-primary" target="_blank" rel="noopener">⬇️ 下载浏览器插件</a>
          <?php else: ?>
            <p class="form-hint">站点管理员尚未配置插件下载地址，请联系管理员获取安装包。</p>
          <?php endif; ?>
        </div>
      </div>

      <div class="card">
        <div class="card-header"><h3>我的令牌</h3></div>
        <div class="card-body">
          <p class="form-hint">令牌即「个人访问令牌(PAT)」，插件登录后自动创建。吊销后使用该令牌的插件将立即失效。</p>
          <div class="table-wrap" style="margin-top:10px;">
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
                      <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('确定吊销该令牌？使用该令牌的插件将立即失效。');">吊销</button>
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
</div>
<style>
  .plugin-steps { margin: 6px 0 0 18px; line-height: 1.9; }
  .plugin-steps li { margin-bottom: 2px; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
  .badge-success { background: #e6f7ec; color: #1a7f37; }
  .badge-danger { background: #fdecea; color: #c0392b; }
  .empty { color: #999; text-align: center; padding: 18px 0; }
  code { background: #f3f4f6; padding: 1px 6px; border-radius: 4px; font-size: 13px; }
</style>
<?php include __DIR__ . '/../includes/footer.php'; ?>
