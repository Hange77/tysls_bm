<?php
/**
 * 后台管理 - 广告管理
 * 两个预设广告位：页头(top)、页尾(bottom)
 * 广告位可添加广告联盟的html或js代码
 * v1.3.8: 移除分类下方广告位（功能不稳定已下线）
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
$adminPage = 'ads';
$pageTitle = '广告管理';
$currentPage = 'admin';
$message = '';
$messageType = '';

// 处理删除
if (isset($_GET['delete']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $id = (int)$_GET['delete'];
    $item = DB::fetch("SELECT * FROM " . DB_PREFIX . "ads WHERE id = ?", [$id]);
    if ($item) {
        DB::delete(DB_PREFIX . 'ads', 'id = ?', [$id]);
        log_operation('delete', 'ad', $id, '删除广告: ' . $item['title']);
        $message = '广告已删除';
        $messageType = 'success';
    }
}

// 处理切换启用状态
if (isset($_GET['toggle']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $id = (int)$_GET['toggle'];
    $item = DB::fetch("SELECT * FROM " . DB_PREFIX . "ads WHERE id = ?", [$id]);
    if ($item) {
        $newStatus = $item['is_active'] ? 0 : 1;
        DB::update(DB_PREFIX . 'ads', ['is_active' => $newStatus], 'id = :id', [':id' => $id]);
        log_operation('update', 'ad', $id, ($newStatus ? '启用' : '停用') . '广告: ' . $item['title']);
        $message = '广告已' . ($newStatus ? '启用' : '停用');
        $messageType = 'success';
    }
}

// 处理保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ad'])) {
    CSRF::check();
    $id = (int)($_POST['id'] ?? 0);
    $position = $_POST['position'] ?? 'top';
    
    $title = trim($_POST['title'] ?? '');
    $code = $_POST['code'] ?? '';
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $sort = (int)($_POST['sort'] ?? 0);
    $displayScope = in_array($_POST['display_scope'] ?? '', ['all', 'site', 'user']) ? $_POST['display_scope'] : 'all';

    if (!in_array($position, ['top', 'bottom', 'category'])) {
        $message = '无效的广告位';
        $messageType = 'danger';
    } elseif (!$code) {
        $message = '广告代码不能为空';
        $messageType = 'danger';
    } else {
        $data = [
                'position' => $position,
                'category_id' => 0,
                'title' => $title,
                'code' => $code,
                'is_active' => $isActive,
                'sort' => $sort,
                'display_scope' => $displayScope,
            ];
            if ($id > 0) {
                DB::update(DB_PREFIX . 'ads', $data, 'id = :id', [':id' => $id]);
                log_operation('update', 'ad', $id, '编辑广告: ' . $title);
                set_flash('success', '广告已更新');
            } else {
                $newId = DB::insert(DB_PREFIX . 'ads', $data);
                log_operation('create', 'ad', $newId, '添加广告: ' . $title);
                set_flash('success', '广告已添加');
            }
            redirect(url('admin/ads.php'));
    }
}

// 编辑模式
$editItem = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $editItem = DB::fetch("SELECT * FROM " . DB_PREFIX . "ads WHERE id = ?", [$editId]);
}


// 列表
$items = DB::fetchAll("SELECT a.*, c.name as category_name FROM " . DB_PREFIX . "ads a LEFT JOIN " . DB_PREFIX . "categories c ON a.category_id = c.id ORDER BY a.position ASC, a.sort ASC, a.id DESC");

$positionLabels = ['top' => '页头', 'bottom' => '页尾'];
$scopeLabels = ['all' => '全站', 'site' => '网址大全', 'user' => '用户收藏夹'];

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
        <h3><?php echo $editItem ? '编辑广告' : '添加广告'; ?></h3>
        <a href="<?php echo url('admin/ads.php'); ?>" class="btn btn-sm btn-default">返回列表</a>
      </div>
      <form method="post" action="">
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="save_ad" value="1">
        <input type="hidden" name="id" value="<?php echo $editItem ? (int)$editItem['id'] : 0; ?>">
        <div class="form-row">
          <div class="form-group">
            <label>广告名称（管理用，不显示在前台）</label>
            <input type="text" name="title" class="form-control" placeholder="如：首页顶部联盟广告" value="<?php echo e($editItem['title'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <label>排序（小的在前）</label>
            <input type="number" name="sort" class="form-control" value="<?php echo (int)($editItem['sort'] ?? 0); ?>">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>广告位位置 *</label>
            <select name="position" id="adPosition" class="form-control">
              <option value="top" <?php echo ($editItem['position'] ?? 'top') === 'top' ? 'selected' : ''; ?>>页头（所有页面内容顶部）</option>
              <option value="bottom" <?php echo ($editItem['position'] ?? '') === 'bottom' ? 'selected' : ''; ?>>页尾（所有页面内容底部）</option>
              
            </select>
          </div>
          <div class="form-group">
            <label>显示范围 *</label>
            <select name="display_scope" class="form-control">
              <option value="all" <?php echo ($editItem['display_scope'] ?? 'all') === 'all' ? 'selected' : ''; ?>>全站显示（所有页面）</option>
              <option value="site" <?php echo ($editItem['display_scope'] ?? '') === 'site' ? 'selected' : ''; ?>>仅网址大全（首页/分类/搜索/页面）</option>
              <option value="user" <?php echo ($editItem['display_scope'] ?? '') === 'user' ? 'selected' : ''; ?>>仅用户收藏夹（用户首页/用户分类）</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label>广告代码 *（HTML / JS，支持广告联盟代码）</label>
          <textarea name="code" class="form-control" rows="8" required placeholder="粘贴广告联盟的HTML或JS代码" style="font-family:monospace;font-size:13px;"><?php echo $editItem['code'] ?? ''; ?></textarea>
          <div class="form-hint">注意：广告代码会原样输出到页面，请勿包含恶意代码。</div>
        </div>
        <div class="form-group">
          <div class="form-check">
            <input type="checkbox" name="is_active" id="ad_is_active" <?php echo (!isset($editItem) || $editItem['is_active']) ? 'checked' : ''; ?>>
            <label for="ad_is_active">启用（启用后在对应广告位显示）</label>
          </div>
        </div>
        <button type="submit" class="btn btn-primary"><?php echo $editItem ? '保存修改' : '添加广告'; ?></button>
        <a href="<?php echo url('admin/ads.php'); ?>" class="btn btn-default">取消</a>
      </form>
    </div>

    <?php else: ?>
    <!-- 列表 -->
    <div class="card">
      <div class="card-header">
        <h3>广告列表 (共 <?php echo count($items); ?> 条)</h3>
        <a href="<?php echo url('admin/ads.php?action=add'); ?>" class="btn btn-sm btn-primary">+ 添加广告</a>
      </div>
      <?php if ($items): ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>ID</th><th>广告名称</th><th>广告位</th><th>显示范围</th><th>代码预览</th><th>排序</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
        <?php foreach ($items as $item): ?>
        <tr>
          <td><?php echo (int)$item['id']; ?></td>
          <td><?php echo e($item['title'] ?: '-'); ?></td>
          <td><span class="badge badge-primary"><?php echo e($positionLabels[$item['position']] ?? $item['position']); ?></span></td>
          <td><span class="badge badge-muted"><?php echo e($scopeLabels[$item['display_scope'] ?? 'all'] ?? '全站'); ?></span></td>
          
          <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-family:monospace;font-size:12px;"><?php echo e($item['code']); ?></td>
          <td><?php echo (int)$item['sort']; ?></td>
          <td>
            <form method="post" action="?toggle=<?php echo (int)$item['id']; ?>" style="display:inline;">
              <?php echo CSRF::field(); ?>
              <button type="submit" class="btn btn-sm <?php echo $item['is_active'] ? 'btn-success' : 'btn-default'; ?>" style="padding:2px 10px;font-size:12px;"><?php echo $item['is_active'] ? '已启用' : '已停用'; ?></button>
            </form>
          </td>
          <td class="actions">
            <a href="?edit=<?php echo (int)$item['id']; ?>" class="btn btn-sm btn-default">编辑</a>
            <form method="post" action="?delete=<?php echo (int)$item['id']; ?>" style="display:inline;" onsubmit="return confirm('确定删除该广告吗?');"><?php echo CSRF::field(); ?><button type="submit" class="btn btn-sm btn-danger">删除</button></form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php else: ?>
      <div class="empty-state"><div class="empty-icon">📺</div><p>暂无广告</p><a href="<?php echo url('admin/ads.php?action=add'); ?>" class="btn btn-primary">添加第一条广告</a></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
