<?php
/**
 * 后台管理 - 系统设置
 * v1.3.9: 新增 SMTP 邮件配置(邮箱验证/找回密码)、站点URL、可见版本号
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
$adminPage = 'settings';
$pageTitle = '系统设置';
$currentPage = 'admin';
$message = '';
$messageType = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    CSRF::check();
    // 默认搜索范围/引擎：site=站内 / my=我的收藏 / ext:NAME=某站外引擎（须与已启用引擎匹配，否则回退站内）
    $rawDef = trim($_POST['search_default_mode'] ?? 'site');
    if ($rawDef === 'site' || $rawDef === 'my') {
        $searchDefaultMode = $rawDef;
    } elseif (preg_match('/^ext:(.+)$/', $rawDef, $m)) {
        $defName = $m[1];
        $ej2 = setting('search_engines', '');
        $valid = false;
        if ($ej2) {
            $d2 = json_decode($ej2, true);
            if (is_array($d2)) {
                foreach ($d2 as $e2) {
                    if (!empty($e2['enabled']) && ($e2['name'] ?? '') === $defName) { $valid = true; break; }
                }
            }
        }
        $searchDefaultMode = $valid ? $rawDef : 'site';
    } else {
        $searchDefaultMode = 'site';
    }
    $settings = [
        'site_name' => trim($_POST['site_name'] ?? ''),
        'site_description' => trim($_POST['site_description'] ?? ''),
        'site_keywords' => trim($_POST['site_keywords'] ?? ''),
        'site_logo' => trim($_POST['site_logo'] ?? ''),
        'site_url' => trim($_POST['site_url'] ?? ''),
        'allow_register' => isset($_POST['allow_register']) ? '1' : '0',
        'default_bookmark_public' => isset($_POST['default_bookmark_public']) ? '1' : '0',
        'bookmark_desc_mode' => in_array($_POST['bookmark_desc_mode'] ?? 'off', ['off', 'hover', 'below'], true) ? $_POST['bookmark_desc_mode'] : 'off',
        'search_default_mode' => $searchDefaultMode,
        'favicon_mode' => in_array($_POST['favicon_mode'] ?? 'auto', ['auto', 'off'], true) ? $_POST['favicon_mode'] : 'auto',
        'favicon_service_url' => trim($_POST['favicon_service_url'] ?? ''),
        'icp_number' => trim($_POST['icp_number'] ?? ''),
        'footer_extra_code' => $_POST['footer_extra_code'] ?? '',
        'analytics_code' => $_POST['analytics_code'] ?? '',
        'rewrite_enabled' => isset($_POST['rewrite_enabled']) ? '1' : '0',
        'recycle_retention_days' => max(0, intval($_POST['recycle_retention_days'] ?? 30)),
        'mail_enabled' => isset($_POST['mail_enabled']) ? '1' : '0',
        'smtp_host' => trim($_POST['smtp_host'] ?? ''),
        'smtp_port' => trim($_POST['smtp_port'] ?? ''),
        'smtp_secure' => in_array($_POST['smtp_secure'] ?? '', ['ssl', 'tls', 'none']) ? $_POST['smtp_secure'] : 'none',
        'smtp_user' => trim($_POST['smtp_user'] ?? ''),
        'mail_from' => trim($_POST['mail_from'] ?? ''),
        'mail_from_name' => trim($_POST['mail_from_name'] ?? ''),
    ];
    foreach ($settings as $key => $value) {
        $exists = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "settings WHERE skey = ?", [$key]);
        if ($exists) {
            DB::update(DB_PREFIX . 'settings', ['svalue' => $value], 'skey = :k', [':k' => $key]);
        } else {
            DB::insert(DB_PREFIX . 'settings', ['skey' => $key, 'svalue' => $value]);
        }
    }
    // SMTP 密码仅在填写时更新(留空保持原值)
    if (isset($_POST['smtp_pass']) && $_POST['smtp_pass'] !== '') {
        $exists = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "settings WHERE skey = ?", ['smtp_pass']);
        if ($exists) {
            DB::update(DB_PREFIX . 'settings', ['svalue' => $_POST['smtp_pass']], 'skey = :k', [':k' => 'smtp_pass']);
        } else {
            DB::insert(DB_PREFIX . 'settings', ['skey' => 'smtp_pass', 'svalue' => $_POST['smtp_pass']]);
        }
    }
    // 聚合搜索 - 站外搜索引擎配置（动态数组，存 JSON）
    $skipNoPlaceholder = 0;
    if (isset($_POST['engines']) && is_array($_POST['engines'])) {
        $list = [];
        foreach ($_POST['engines'] as $e) {
            $name = trim($e['name'] ?? '');
            $tpl = trim($e['url_template'] ?? '');
            if ($name === '' || $tpl === '') continue;
            // URL 模板必须包含 %s 占位符，否则点击后无法带上关键词，跳过该行
            if (strpos($tpl, '%s') === false) { $skipNoPlaceholder++; continue; }
            $list[] = [
                'name' => $name,
                'url_template' => $tpl,
                'enabled' => !empty($e['enabled']) ? 1 : 0,
                'sort' => (int)($e['sort'] ?? 0),
            ];
        }
        $engJson = json_encode($list, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $engExists = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "settings WHERE skey = ?", ['search_engines']);
        if ($engExists) {
            DB::update(DB_PREFIX . 'settings', ['svalue' => $engJson], 'skey = :k', [':k' => 'search_engines']);
        } else {
            DB::insert(DB_PREFIX . 'settings', ['skey' => 'search_engines', 'svalue' => $engJson]);
        }
    }
    // 强制刷新设置缓存（修复保存后仍显示旧值的问题）
    $GLOBALS['settings'] = load_settings(true);
    log_operation('update', 'setting', 0, '修改系统设置');
    $message = '设置已保存';
    if ($skipNoPlaceholder > 0) {
        $message .= '（已忽略 ' . $skipNoPlaceholder . ' 个缺少 %s 占位符的引擎，请补全 URL 模板）';
    }
    $messageType = 'success';
}

// 发送测试邮件
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_mail'])) {
    CSRF::check();
    $testEmail = trim($_POST['test_email'] ?? '');
    if (!validate_email($testEmail)) {
        $message = '测试邮箱格式不正确';
        $messageType = 'danger';
    } else {
        $site = setting('site_name', '书签导航');
        $res = Mailer::send($testEmail, '[' . $site . '] 测试邮件', Mailer::wrap($site, '测试邮件', '<p>这是一封来自 ' . e($site) . ' 的测试邮件,如果您能收到,说明 SMTP 配置正确。</p>'));
        $message = $res['ok'] ? '测试邮件已发送至 ' . $testEmail : '发送失败: ' . $res['msg'];
        $messageType = $res['ok'] ? 'success' : 'danger';
    }
}
include __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="admin-main">
    <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header"><h3>基本设置</h3></div>
      <form method="post" action="">
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="save_settings" value="1">
        <div class="form-group">
          <label>网站名称</label>
          <input type="text" name="site_name" class="form-control" value="<?php echo e(setting('site_name', '书签导航')); ?>">
        </div>
        <div class="form-group">
          <label>网站Logo地址（图片URL，留空使用默认图标）</label>
          <input type="text" name="site_logo" class="form-control" placeholder="https://example.com/logo.png" value="<?php echo e(setting('site_logo', '')); ?>">
          <?php if (setting('site_logo', '')): ?>
          <div class="form-hint" style="margin-top:6px;">当前Logo预览：<img src="<?php echo e(setting('site_logo', '')); ?>" style="height:24px;vertical-align:middle;border-radius:4px;"></div>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label>站点URL(可选,用于邮件中的链接,留空自动检测)</label>
          <input type="text" name="site_url" class="form-control" placeholder="https://your-domain.com" value="<?php echo e(setting('site_url', '')); ?>">
          <div class="form-hint">配置后可避免邮件验证/找回密码链接受服务器 Host 头影响</div>
        </div>
        <div class="form-group">
          <label>网站描述</label>
          <input type="text" name="site_description" class="form-control" value="<?php echo e(setting('site_description', '')); ?>">
        </div>
        <div class="form-group">
          <label>网站关键词</label>
          <input type="text" name="site_keywords" class="form-control" value="<?php echo e(setting('site_keywords', '')); ?>">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>ICP备案号</label>
            <input type="text" name="icp_number" class="form-control" value="<?php echo e(setting('icp_number', '')); ?>">
          </div>
          <div class="form-group">
            <label>&nbsp;</label>
            <div class="form-check"><input type="checkbox" name="allow_register" id="allow_register" <?php echo setting('allow_register', '1') === '1' ? 'checked' : ''; ?>><label for="allow_register">允许用户注册</label></div>
          </div>
        </div>
        <div class="form-group">
          <label>其他页脚代码（HTML，显示在ICP备案号后面，可放公安备案代码、帮助/联系链接等）</label>
          <textarea name="footer_extra_code" class="form-control" rows="3" placeholder="如：<a href='#'>公安备案号</a> | <a href='#'>帮助</a>" style="font-family:monospace;font-size:13px;"><?php echo e(setting('footer_extra_code', '')); ?></textarea>
        </div>
        <div class="form-group">
          <div class="form-check"><input type="checkbox" name="default_bookmark_public" id="default_bookmark_public" <?php echo setting('default_bookmark_public', '1') === '1' ? 'checked' : ''; ?>><label for="default_bookmark_public">用户添加书签默认公开</label></div>
        </div>

        <h4 style="margin:24px 0 12px;padding-top:16px;border-top:1px solid var(--border);">书签展示设置</h4>
        <div class="form-group">
          <label>书签描述显示方式</label>
          <select name="bookmark_desc_mode" class="form-control" style="max-width:240px;">
            <option value="off" <?php echo setting('bookmark_desc_mode', 'off') === 'off' ? 'selected' : ''; ?>>关闭（不显示描述，默认）</option>
            <option value="hover" <?php echo setting('bookmark_desc_mode', 'off') === 'hover' ? 'selected' : ''; ?>>悬停提示（鼠标悬停显示描述）</option>
            <option value="below" <?php echo setting('bookmark_desc_mode', 'off') === 'below' ? 'selected' : ''; ?>>标题下方显示（小字）</option>
          </select>
          <div class="form-hint">仅影响前台书签小药丸（首页 / 分类页 / 搜索页）；搜索结果卡片默认已展示描述，不受此设置影响。</div>
        </div>
        <div class="form-group">
          <label>自动获取站点 Favicon</label>
          <select name="favicon_mode" class="form-control" style="max-width:240px;">
            <option value="auto" <?php echo setting('favicon_mode', 'auto') === 'auto' ? 'selected' : ''; ?>>开启（无图标书签自动获取）</option>
            <option value="off" <?php echo setting('favicon_mode', 'auto') === 'off' ? 'selected' : ''; ?>>关闭（统一显示 🌐）</option>
          </select>
          <div class="form-hint">仅对未设置图标的书签生效；已手动设置图标的书签不受影响。加载失败时自动回退为 🌐。</div>
        </div>
        <div class="form-group">
          <label>Favicon 服务地址</label>
          <select id="faviconPreset" class="form-control" style="max-width:300px;margin-bottom:6px;">
            <option value="">— 选择预设 / 自定义 —</option>
            <option value="https://icons.duckduckgo.com/ip3/{domain}.ico">DuckDuckGo（默认）</option>
            <option value="https://www.google.com/s2/favicons?domain={domain}&amp;sz=32">Google</option>
            <option value="https://www.bing.com/favicon.ico?url={domain}">Bing</option>
          </select>
          <input type="text" name="favicon_service_url" class="form-control" style="max-width:480px;" placeholder="https://icons.duckduckgo.com/ip3/{domain}.ico" value="<?php echo e(setting('favicon_service_url', '')); ?>">
          <div class="form-hint">用 <code>{domain}</code> 代表站点域名（如 github.com）。留空使用默认 DuckDuckGo 服务。</div>
        </div>
        <script>
        document.getElementById('faviconPreset').addEventListener('change', function(){
          var v = this.value; if (v) document.querySelector('input[name="favicon_service_url"]').value = v;
        });
        </script>
        <div class="form-group">
          <div class="form-check"><input type="checkbox" name="rewrite_enabled" id="rewrite_enabled" <?php echo setting('rewrite_enabled', '0') === '1' ? 'checked' : ''; ?>><label for="rewrite_enabled">开启伪静态（需服务器已配置 rewrite 规则，开启后分类/页面链接显示为 /category/xxx、/page/xxx 格式）</label></div>
        </div>

        <h4 style="margin:24px 0 12px;padding-top:16px;border-top:1px solid var(--border);">聚合搜索 · 站外引擎</h4>
        <div class="form-hint" style="margin-bottom:10px;">勾选启用的搜索引擎会显示在搜索结果页，点击在新窗口打开对应引擎搜索结果。URL 模板必须包含 <code>%s</code> 作为关键词占位符（例如 <code>https://cn.bing.com/search?q=%s</code>）。名称或模板为空的行将被忽略。</div>
        <div class="form-group">
          <label>默认搜索范围 / 引擎</label>
          <select name="search_default_mode" class="form-control" style="max-width:280px;">
            <option value="site" <?php echo setting('search_default_mode', 'site') === 'site' ? 'selected' : ''; ?>>站内（搜索本站全部内容）</option>
            <option value="my" <?php echo setting('search_default_mode', 'site') === 'my' ? 'selected' : ''; ?>>我的收藏（仅登录用户）</option>
            <?php
            $ej = setting('search_engines', '');
            if ($ej) {
                $d = json_decode($ej, true);
                if (is_array($d)) {
                    foreach ($d as $e) {
                        if (!empty($e['enabled'])) {
                            $val = 'ext:' . $e['name'];
                            echo '<option value="' . e($val) . '"' . (setting('search_default_mode', 'site') === $val ? ' selected' : '') . '>' . e($e['name']) . '（站外引擎）</option>';
                        }
                    }
                }
            }
            ?>
          </select>
          <div class="form-hint">搜索条默认选中的选项卡。访客若设为“我的收藏”或无匹配引擎时，会自动回退到“站内”。</div>
        </div>
        <div id="engineList">
          <?php
          $curEngines = [];
          $ej = setting('search_engines', '');
          if ($ej) { $d = json_decode($ej, true); if (is_array($d)) $curEngines = $d; }
          if (empty($curEngines)) $curEngines = [['name' => '', 'url_template' => '', 'enabled' => 1, 'sort' => 0]];
          foreach ($curEngines as $ei => $e):
          ?>
          <div class="engine-row" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;">
            <input type="text" name="engines[<?php echo $ei; ?>][name]" class="form-control" style="width:120px;" placeholder="名称" value="<?php echo e($e['name'] ?? ''); ?>">
            <input type="text" name="engines[<?php echo $ei; ?>][url_template]" class="form-control" style="flex:1;min-width:240px;" placeholder="URL模板(需含%s)" value="<?php echo e($e['url_template'] ?? ''); ?>">
            <label style="font-size:13px;color:var(--text-muted);"><input type="checkbox" name="engines[<?php echo $ei; ?>][enabled]" value="1" <?php echo (!empty($e['enabled'])) ? 'checked' : ''; ?>> 启用</label>
            <input type="number" name="engines[<?php echo $ei; ?>][sort]" class="form-control" style="width:70px;" placeholder="排序" value="<?php echo (int)($e['sort'] ?? 0); ?>">
            <button type="button" class="btn btn-sm btn-default" onclick="this.parentNode.remove()">删除</button>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" class="btn btn-sm btn-default" onclick="addEngineRow()">+ 添加引擎</button>
        <script>
        function addEngineRow() {
          var box = document.getElementById('engineList');
          var i = box.children.length;
          var div = document.createElement('div');
          div.className = 'engine-row';
          div.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap;';
          div.innerHTML = '<input type="text" name="engines[' + i + '][name]" class="form-control" style="width:120px;" placeholder="名称">'
            + '<input type="text" name="engines[' + i + '][url_template]" class="form-control" style="flex:1;min-width:240px;" placeholder="URL模板(需含%s)">'
            + '<label style="font-size:13px;color:var(--text-muted);"><input type="checkbox" name="engines[' + i + '][enabled]" value="1" checked> 启用</label>'
            + '<input type="number" name="engines[' + i + '][sort]" class="form-control" style="width:70px;" placeholder="排序">'
            + '<button type="button" class="btn btn-sm btn-default" onclick="this.parentNode.remove()">删除</button>';
          box.appendChild(div);
        }
        </script>

        <div class="form-group">
          <label>统计代码（HTML，输出到页面最底部 body 结束前，如百度统计 / Google Analytics。注意：本字段内容显示在整页最底部，不会紧跟 ICP 备案号——若想放在 ICP 备案号后面，请用上方的"其他页脚代码"）</label>
          <textarea name="analytics_code" class="form-control" rows="4" placeholder="如百度统计、Google Analytics等代码"><?php echo e(setting('analytics_code', '')); ?></textarea>
        </div>

        <h4 style="margin:24px 0 12px;padding-top:16px;border-top:1px solid var(--border);">回收站设置</h4>
        <div class="form-group">
          <label>回收站保留天数（删除的书签/分类/便签/页面在此期限内可恢复；到期由插件或定时任务自动清理）</label>
          <input type="number" name="recycle_retention_days" class="form-control" min="0" value="<?php echo e(intval(setting('recycle_retention_days', '30'))); ?>" style="max-width:160px;">
          <div class="form-hint">填 0 表示永久保留，不自动清理。默认 30 天。插件同步删除传播也遵循此设定。</div>
        </div>

        <h4 style="margin:24px 0 12px;padding-top:16px;border-top:1px solid var(--border);">邮件设置(SMTP)</h4>
        <div class="form-group">
          <div class="form-check"><input type="checkbox" name="mail_enabled" id="mail_enabled" <?php echo setting('mail_enabled', '0') === '1' ? 'checked' : ''; ?>><label for="mail_enabled">开启邮件功能(用于邮箱验证 / 找回密码)</label></div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>SMTP 服务器</label>
            <input type="text" name="smtp_host" class="form-control" placeholder="如 smtp.qq.com" value="<?php echo e(setting('smtp_host', '')); ?>">
          </div>
          <div class="form-group">
            <label>端口</label>
            <input type="text" name="smtp_port" class="form-control" placeholder="465 / 587 / 25" value="<?php echo e(setting('smtp_port', '')); ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>加密方式</label>
            <select name="smtp_secure" class="form-control">
              <option value="none" <?php echo setting('smtp_secure', 'none') === 'none' ? 'selected' : ''; ?>>无</option>
              <option value="ssl" <?php echo setting('smtp_secure', '') === 'ssl' ? 'selected' : ''; ?>>SSL</option>
              <option value="tls" <?php echo setting('smtp_secure', '') === 'tls' ? 'selected' : ''; ?>>TLS (STARTTLS)</option>
            </select>
          </div>
          <div class="form-group">
            <label>发件人地址</label>
            <input type="email" name="mail_from" class="form-control" placeholder="如 noreply@example.com" value="<?php echo e(setting('mail_from', '')); ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>SMTP 用户名</label>
            <input type="text" name="smtp_user" class="form-control" value="<?php echo e(setting('smtp_user', '')); ?>">
          </div>
          <div class="form-group">
            <label>SMTP 密码 / 授权码</label>
            <input type="password" name="smtp_pass" class="form-control" placeholder="留空表示不修改" autocomplete="new-password">
          </div>
        </div>
        <div class="form-group">
          <label>发件人名称</label>
          <input type="text" name="mail_from_name" class="form-control" placeholder="书签导航" value="<?php echo e(setting('mail_from_name', '')); ?>">
        </div>
        <button type="submit" class="btn btn-primary">保存设置</button>
      </form>
    </div>
    <div class="card">
      <div class="card-header"><h3>邮件发送测试</h3></div>
      <form method="post" action="">
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="test_mail" value="1">
        <div class="form-row">
          <div class="form-group">
            <label>测试接收邮箱</label>
            <input type="email" name="test_email" class="form-control" placeholder="输入一个可收到邮件的邮箱" value="<?php echo e($_POST['test_email'] ?? ''); ?>">
          </div>
          <div class="form-group" style="display:flex;align-items:flex-end;">
            <button type="submit" class="btn btn-default">发送测试邮件</button>
          </div>
        </div>
        <div class="form-hint">需先在上方开启邮件功能并保存 SMTP 配置, 再点击发送测试。</div>
      </form>
    </div>
    <div class="card">
      <div class="card-header"><h3>系统信息</h3></div>
      <table class="table">
        <tr><th style="width:160px;">PHP版本</th><td><?php echo e(PHP_VERSION); ?></td></tr>
        <tr><th>MySQL版本</th><td><?php echo e(DB::pdo()->getAttribute(PDO::ATTR_SERVER_VERSION)); ?></td></tr>
        <tr><th>数据库表前缀</th><td><?php echo e(DB_PREFIX); ?></td></tr>
        <tr><th>服务器时间</th><td><?php echo e(date('Y-m-d H:i:s')); ?></td></tr>
        <tr><th>软件版本</th><td>书签导航系统 v1.4.1.9</td></tr>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
