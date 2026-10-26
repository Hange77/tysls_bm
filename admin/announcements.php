<?php
/**
 * 后台管理 - 站点公告管理
 * 功能: 公告的增删改查、启用/停用、排序、过期时间、跳转链接
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
$adminPage = 'announcements';
$pageTitle = '站点公告';
$currentPage = 'admin';
$message = '';
$messageType = '';

// 处理删除
if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $id = (int)$_GET['delete'];
    $item = DB::fetch("SELECT * FROM " . DB_PREFIX . "announcements WHERE id = ?", [$id]);
    if ($item) {
        DB::delete(DB_PREFIX . 'announcements', 'id = ?', [$id]);
        log_operation('delete', 'announcement', $id, '删除公告: ' . $item['title']);
        $message = '公告已删除';
        $messageType = 'success';
    }
}

// 处理切换启用状态
if (isset($_GET['toggle']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $id = (int)$_GET['toggle'];
    $item = DB::fetch("SELECT * FROM " . DB_PREFIX . "announcements WHERE id = ?", [$id]);
    if ($item) {
        $newStatus = $item['is_active'] ? 0 : 1;
        DB::update(DB_PREFIX . 'announcements', ['is_active' => $newStatus], 'id = :id', [':id' => $id]);
        log_operation('update', 'announcement', $id, ($newStatus ? '启用' : '停用') . '公告: ' . $item['title']);
        $message = '公告已' . ($newStatus ? '启用' : '停用');
        $messageType = 'success';
    }
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_announcement'])) {
    CSRF::check();
    $id = (int)($_POST['id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $linkUrl = trim($_POST['link_url'] ?? '');
    $sort = (int)($_POST['sort'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $expireAt = trim($_POST['expire_at'] ?? '');

    if (!$content) {
        $message = '公告内容不能为空';
        $messageType = 'danger';
    } else {
        $data = [
            'title' => $title,
            'content' => $content,
            'link_url' => $linkUrl,
            'sort' => $sort,
            'is_active' => $isActive,
            'expire_at' => $expireAt !== '' ? $expireAt : null,
        ];
        if ($id > 0) {
            DB::update(DB_PREFIX . 'announcements', $data, 'id = :id', [':id' => $id]);
            log_operation('update', 'announcement', $id, '编辑公告: ' . $title);
            set_flash('success', '公告已更新');
        } else {
            $newId = DB::insert(DB_PREFIX . 'announcements', $data);
            log_operation('create', 'announcement', $newId, '添加公告: ' . $title);
            set_flash('success', '公告已添加');
        }
        redirect(url('admin/announcements.php'));
    }
}

// 编辑模式
$editItem = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editItem = DB::fetch("SELECT * FROM " . DB_PREFIX . "announcements WHERE id = ?", [$editId]);
}

// ========== 处理跑马灯设置保存 ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_marquee_settings'])) {
    CSRF::check();
    $marqueeSettings = [
        'marquee_enabled' => isset($_POST['marquee_enabled']) ? '1' : '0',
        'marquee_direction' => in_array($_POST['marquee_direction'] ?? '', ['horizontal', 'vertical']) ? $_POST['marquee_direction'] : 'horizontal',
        'marquee_speed' => max(5, min(120, (int)($_POST['marquee_speed'] ?? 30))),
        'show_announcements_on_user_page' => isset($_POST['show_announcements_on_user_page']) ? '1' : '0',
    ];
    foreach ($marqueeSettings as $key => $value) {
        $exists = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "settings WHERE skey = ?", [$key]);
        if ($exists) {
            DB::update(DB_PREFIX . 'settings', ['svalue' => $value], 'skey = :k', [':k' => $key]);
        } else {
            DB::insert(DB_PREFIX . 'settings', ['skey' => $key, 'svalue' => $value]);
        }
    }
    $GLOBALS['settings'] = null;
    $GLOBALS['settings'] = load_settings();
    log_operation('update', 'setting', 0, '修改公告跑马灯设置');
    $message = '设置已保存';
    $messageType = 'success';
}

// 列表
$items = DB::fetchAll("SELECT * FROM " . DB_PREFIX . "announcements ORDER BY sort ASC, id DESC");

include __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="admin-main">
    <?php if ($message): ?><div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div><?php endif; ?>

    <?php if (isset($_GET['action']) && $_GET['action'] === 'add' || $editItem): ?>
    <!-- 添加/编辑表单 -->
    <div class="card">
      <div class="card-header">
        <h3><?php echo $editItem ? '编辑公告' : '添加公告'; ?></h3>
        <a href="<?php echo url('admin/announcements.php'); ?>" class="btn btn-sm btn-default">返回列表</a>
      </div>
      <form method="post" action="">
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="save_announcement" value="1">
        <input type="hidden" name="id" value="<?php echo $editItem ? (int)$editItem['id'] : 0; ?>">
        <div class="form-row">
          <div class="form-group">
            <label>公告标题（可选，显示在内容前）</label>
            <input type="text" name="title" class="form-control" placeholder="如：系统通知" value="<?php echo e($editItem['title'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label>排序（小的在前）</label>
            <input type="number" name="sort" class="form-control" value="<?php echo (int)($editItem['sort'] ?? 0); ?>">
          </div>
        </div>
        <div class="form-group">
          <label>公告内容 *</label>
          <input type="text" name="content" class="form-control" required placeholder="输入公告内容，将在首页跑马灯显示" value="<?php echo e($editItem['content'] ?? ''); ?>">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>点击跳转链接（可选，留空不跳转）</label>
            <input type="text" name="link_url" class="form-control" placeholder="https://example.com" value="<?php echo e($editItem['link_url'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label>过期时间（可选，留空永不过期）</label>
            <input type="datetime-local" name="expire_at" class="form-control" value="<?php echo $editItem && $editItem['expire_at'] ? date('Y-m-d\TH:i', strtotime($editItem['expire_at'])) : ''; ?>">
          </div>
        </div>
        <div class="form-group">
          <div class="form-check">
            <input type="checkbox" name="is_active" id="ann_is_active" <?php echo (!isset($editItem) || $editItem['is_active']) ? 'checked' : ''; ?>>
            <label for="ann_is_active">启用（启用后显示在首页跑马灯）</label>
          </div>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo $editItem ? '保存修改' : '添加公告'; ?></button>
        <a href="<?php echo url('admin/announcements.php'); ?>" class="btn btn-default">取消</a>
      </form>
    </div>
    <?php else: ?>
    <!-- 跑马灯设置 -->
    <div class="card" style="margin-bottom:20px;">
      <div class="card-header"><h3>公告跑马灯设置</h3></div>
      <form method="post" action="">
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="save_marquee_settings" value="1">
        <div class="form-group">
          <div class="form-check"><input type="checkbox" name="marquee_enabled" id="marquee_enabled" <?php echo setting('marquee_enabled', '1') === '1' ? 'checked' : ''; ?>><label for="marquee_enabled">启用首页公告跑马灯</label></div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>滚动方向</label>
            <select name="marquee_direction" class="form-control">
              <option value="horizontal" <?php echo setting('marquee_direction', 'horizontal') === 'horizontal' ? 'selected' : ''; ?>>横向（向左滚动）</option>
              <option value="vertical" <?php echo setting('marquee_direction', 'horizontal') === 'vertical' ? 'selected' : ''; ?>>纵向（向上滚动）</option>
            </select>
          </div>
          <div class="form-group">
            <label>滚动速度（秒/轮，数值越大越慢）</label>
            <input type="number" name="marquee_speed" class="form-control" min="5" max="120" value="<?php echo (int)setting('marquee_speed', '30'); ?>">
          </div>
        </div>
        <div class="form-group">
          <label style="font-weight:600;margin-bottom:8px;">用户收藏夹页面显示设置</label>
          <div class="form-check"><input type="checkbox" name="show_announcements_on_user_page" id="show_ann" <?php echo setting('show_announcements_on_user_page', '1') === '1' ? 'checked' : ''; ?>><label for="show_ann">在用户收藏夹首页显示公告跑马灯</label></div>
          <div class="form-hint">广告的显示范围请在「广告管理」中为每条广告单独设置。</div>
        </div>
        <button type="submit" class="btn btn-primary">保存设置</button>
      </form>
    </div>
    <!-- 列表 -->
    <div class="card">
      <div class="card-header">
        <h3>公告列表 (共 <?php echo count($items); ?> 条)</h3>
        <a href="<?php echo url('admin/announcements.php?action=add'); ?>" class="btn btn-sm btn-primary">+ 添加公告</a>
      </div>
      <?php if ($items): ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>ID</th><th>标题</th><th>内容</th><th>跳转链接</th><th>排序</th><th>状态</th><th>过期时间</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
        <tr>
          <td><?php echo (int)$item['id']; ?></td>
          <td><?php echo e($item['title'] ?: '-'); ?></td>
          <td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo e($item['content']); ?></td>
          <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo e($item['link_url'] ?: '-'); ?></td>
          <td><?php echo (int)$item['sort']; ?></td>
          <td>
            <form method="post" action="?toggle=<?php echo (int)$item['id']; ?>" style="display:inline;">
              <?php echo CSRF::field(); ?>
              <button type="submit" class="btn btn-sm <?php echo $item['is_active'] ? 'btn-success' : 'btn-default'; ?>" style="padding:2px 10px;font-size:12px;"><?php echo $item['is_active'] ? '已启用' : '已停用'; ?></button>
            </form>
          </td>
          <td><?php echo $item['expire_at'] ? e(date('Y-m-d H:i', strtotime($item['expire_at']))) : '永不过期'; ?></td>
          <td class="actions">
            <a href="?edit=<?php echo (int)$item['id']; ?>" class="btn btn-sm btn-default">编辑</a>
            <form method="post" action="?delete=<?php echo (int)$item['id']; ?>" style="display:inline;" onsubmit="return confirm('确定删除该公告吗?');"><?php echo CSRF::field(); ?><button type="submit" class="btn btn-sm btn-danger">删除</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?>
      <div class="empty-state"><div class="empty-icon">📢</div><p>暂无公告</p><a href="<?php echo url('admin/announcements.php?action=add'); ?>" class="btn btn-primary">添加第一条公告</a></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
