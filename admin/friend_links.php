<?php
/**
 * 后台管理 - 首页友情链接
 * v1.4.1: 新增。系统级，仅在首页 footer 上方展示。
 * 字段：名称 / 链接 / 图标(外链图片URL) / 描述(后台备注+悬浮提示) / 排序 / 显隐
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
$adminPage = 'friend_links';
$pageTitle = '友情链接';
$currentPage = 'admin';
$message = '';
$messageType = '';

// 删除
if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $id = (int)$_GET['delete'];
    $item = DB::fetch("SELECT * FROM " . DB_PREFIX . "friend_links WHERE id = ?", [$id]);
    if ($item) {
        DB::delete(DB_PREFIX . 'friend_links', 'id = ?', [$id]);
        log_operation('delete', 'friend_link', $id, '删除友情链接: ' . $item['name']);
        $message = '友情链接已删除';
        $messageType = 'success';
    }
}

// 切换显隐
if (isset($_GET['toggle']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $id = (int)$_GET['toggle'];
    $item = DB::fetch("SELECT * FROM " . DB_PREFIX . "friend_links WHERE id = ?", [$id]);
    if ($item) {
        $newStatus = $item['is_active'] ? 0 : 1;
        DB::update(DB_PREFIX . 'friend_links', ['is_active' => $newStatus], 'id = ?', [$id]);
        log_operation('update', 'friend_link', $id, ($newStatus ? '显示' : '隐藏') . '友情链接: ' . $item['name']);
        $message = $newStatus ? '已显示' : '已隐藏';
        $messageType = 'success';
    }
}

// 保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_link'])) {
    CSRF::check();
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $url = trim($_POST['url'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $sort = (int)($_POST['sort'] ?? 0);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    if (!$name || !$url) {
        $message = '名称和链接不能为空';
        $messageType = 'danger';
    } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
        $message = '链接必须是合法的 URL(http/https)';
        $messageType = 'danger';
    } elseif ($icon !== '' && !filter_var($icon, FILTER_VALIDATE_URL)) {
        $message = '图标必须是合法的图片 URL(http/https)';
        $messageType = 'danger';
    } else {
        $data = [
            'name' => $name,
            'url' => $url,
            'icon' => $icon,
            'description' => $description,
            'sort' => $sort,
            'is_active' => $isActive,
        ];
        if ($id > 0) {
            DB::update(DB_PREFIX . 'friend_links', $data, 'id = ?', [$id]);
            log_operation('update', 'friend_link', $id, '编辑友情链接: ' . $name);
            $message = '友情链接已更新';
        } else {
            $newId = DB::insert(DB_PREFIX . 'friend_links', $data);
            log_operation('create', 'friend_link', $newId, '添加友情链接: ' . $name);
            $message = '友情链接已添加';
        }
        $messageType = 'success';
        redirect(url('admin/friend_links.php'));
    }
}

// 编辑模式
$editItem = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editItem = DB::fetch("SELECT * FROM " . DB_PREFIX . "friend_links WHERE id = ?", [$editId]);
}

// 列表
$items = DB::fetchAll("SELECT * FROM " . DB_PREFIX . "friend_links ORDER BY sort ASC, id ASC");

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
        <h3><?php echo $editItem ? '编辑友情链接' : '添加友情链接'; ?></h3>
        <a href="<?php echo url('admin/friend_links.php'); ?>" class="btn btn-sm btn-default">返回列表</a>
      </div>
      <form method="post" action="">
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="save_link" value="1">
        <input type="hidden" name="id" value="<?php echo $editItem ? (int)$editItem['id'] : 0; ?>">
        <div class="form-row">
          <div class="form-group">
            <label>名称 *</label>
            <input type="text" name="name" class="form-control" placeholder="如：示例网站" value="<?php echo e($editItem['name'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label>链接 *</label>
            <input type="url" name="url" class="form-control" placeholder="https://example.com" value="<?php echo e($editItem['url'] ?? ''); ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>图标(外链图片URL，可选)</label>
            <input type="url" name="icon" class="form-control" placeholder="https://example.com/favicon.png" value="<?php echo e($editItem['icon'] ?? ''); ?>">
            <?php if (!empty($editItem['icon'])): ?>
            <div class="form-hint" style="margin-top:6px;">预览：<img src="<?php echo e($editItem['icon']); ?>" style="height:18px;vertical-align:middle;border-radius:3px;"></div>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label>排序(小的在前)</label>
            <input type="number" name="sort" class="form-control" value="<?php echo (int)($editItem['sort'] ?? 0); ?>">
          </div>
        </div>
        <div class="form-group">
          <label>描述(后台备注，首页作为链接悬浮提示，不单独展示)</label>
          <textarea name="description" class="form-control" rows="2" placeholder="可选"><?php echo e($editItem['description'] ?? ''); ?></textarea>
        </div>
        <div class="form-group">
          <div class="form-check">
            <input type="checkbox" name="is_active" id="is_active" <?php echo (!isset($editItem) || $editItem['is_active']) ? 'checked' : ''; ?>>
            <label for="is_active">显示（取消勾选则首页不展示）</label>
          </div>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo $editItem ? '保存修改' : '添加链接'; ?></button>
        <a href="<?php echo url('admin/friend_links.php'); ?>" class="btn btn-default">取消</a>
      </form>
    </div>

    <?php else: ?>
    <!-- 列表 -->
    <div class="card">
      <div class="card-header">
        <h3>友情链接 (共 <?php echo count($items); ?> 条)</h3>
        <a href="<?php echo url('admin/friend_links.php?action=add'); ?>" class="btn btn-sm btn-primary">+ 添加链接</a>
      </div>
      <?php if ($items): ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>ID</th><th>名称</th><th>链接</th><th>描述</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
        <tr>
          <td><?php echo (int)$item['id']; ?></td>
          <td>
            <?php if ($item['icon']): ?><img src="<?php echo e($item['icon']); ?>" alt="" style="height:16px;width:16px;object-fit:contain;border-radius:3px;vertical-align:middle;margin-right:4px;"><?php endif; ?>
            <?php echo e($item['name']); ?>
          </td>
          <td style="max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><a href="<?php echo e($item['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo e($item['url']); ?></a></td>
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:var(--text-muted);"><?php echo e(truncate($item['description'], 30)); ?></td>
          <td><?php echo (int)$item['sort']; ?></td>
          <td>
            <form method="post" action="?toggle=<?php echo (int)$item['id']; ?>" style="display:inline;">
              <?php echo CSRF::field(); ?>
              <button type="submit" class="btn btn-sm <?php echo $item['is_active'] ? 'btn-success' : 'btn-default'; ?>" style="padding:2px 10px;font-size:12px;"><?php echo $item['is_active'] ? '显示中' : '已隐藏'; ?></button>
            </form>
          </td>
          <td class="actions">
            <a href="?edit=<?php echo (int)$item['id']; ?>" class="btn btn-sm btn-default">编辑</a>
            <form method="post" action="?delete=<?php echo (int)$item['id']; ?>" style="display:inline;" onsubmit="return confirm('确定删除该友情链接吗?');"><?php echo CSRF::field(); ?><button type="submit" class="btn btn-sm btn-danger">删除</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?>
      <div class="empty-state"><div class="empty-icon">🤝</div><p>暂无友情链接</p><a href="<?php echo url('admin/friend_links.php?action=add'); ?>" class="btn btn-primary">添加第一条</a></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
