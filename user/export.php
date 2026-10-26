<?php
/**
 * v1.2 用户中心 - 批量导出书签
 * 支持 HTML 书签格式和 CSV 格式
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireLogin();
$userId = Auth::id();
$userPage = 'export';
$pageTitle = '批量导出';
$currentPage = 'user';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $format = $_POST['format'] ?? 'html';
    $categoryId = (int)($_POST['category_id'] ?? 0);

    $where = "WHERE b.user_id = ? AND b.deleted_at IS NULL";
    $params = [$userId];
    if ($categoryId > 0) {
        $childIds = get_category_descendants($categoryId, $userId);
        $allIds = array_merge([$categoryId], $childIds);
        $placeholders = implode(',', array_fill(0, count($allIds), '?'));
        $where .= " AND b.category_id IN ({$placeholders})";
        $params = array_merge($params, $allIds);
    }
    $bookmarks = DB::fetchAll(
        "SELECT b.*, c.name as category_name FROM " . DB_PREFIX . "bookmarks b 
         LEFT JOIN " . DB_PREFIX . "categories c ON b.category_id = c.id 
         {$where} ORDER BY b.category_id, b.sort ASC, b.id ASC",
        $params
    );

    if (empty($bookmarks)) {
        $message = '没有可导出的书签';
        $messageType = 'danger';
    } else {
        $categories = DB::fetchAll("SELECT id, name FROM " . DB_PREFIX . "categories WHERE user_id = ? AND deleted_at IS NULL", [$userId]);
        $date = date('Ymd_His');

        if ($format === 'csv') {
            $content = export_links_csv($bookmarks);
            $filename = "my_bookmarks_{$date}.csv";
            header('Content-Type: text/csv; charset=UTF-8');
        } else {
            $content = export_bookmarks_html($bookmarks, $categories);
            $filename = "my_bookmarks_{$date}.html";
            header('Content-Type: text/html; charset=UTF-8');
        }
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        header('Pragma: no-cache');
        header('Expires: 0');
        echo $content;
        exit;
    }
}

// 获取用户分类
$userCategories = DB::fetchAll(
    "SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = ? AND deleted_at IS NULL ORDER BY sort ASC, id ASC",
    [$userId]
);
$catTree = build_category_tree($userCategories);

// 统计
$totalBookmarks = DB::fetchColumn("SELECT COUNT(*) FROM " . DB_PREFIX . "bookmarks WHERE user_id = ? AND deleted_at IS NULL", [$userId]);
$catStats = [];
$result = DB::query("SELECT category_id, COUNT(*) as cnt FROM " . DB_PREFIX . "bookmarks WHERE user_id = ? AND deleted_at IS NULL GROUP BY category_id", [$userId]);
while ($row = $result->fetch()) $catStats[$row['category_id']] = $row['cnt'];

include __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <div class="user-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="user-main">
      <?php if (!empty($message)): ?>
      <div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
      <?php endif; ?>

      <div class="card">
        <div class="card-header">
          <h3>批量导出我的书签</h3>
          <a href="<?php echo url('user/import.php'); ?>" class="btn btn-sm btn-default">导入书签</a>
        </div>
        <div style="padding:20px;">
          <div class="import-stats" style="margin-bottom:20px;">
            <div class="import-stat"><div class="num"><?php echo (int)$totalBookmarks; ?></div><div class="label">我的书签总数</div></div>
            <div class="import-stat"><div class="num"><?php echo count($userCategories); ?></div><div class="label">我的分类数</div></div>
          </div>

          <form method="post" action="">
            <?php echo CSRF::field(); ?>
            <div class="form-row">
              <div class="form-group">
                <label>导出格式</label>
                <select name="format" class="form-control">
                  <option value="html">HTML 书签格式（可导入浏览器）</option>
                  <option value="csv">CSV 格式（可用Excel打开）</option>
                </select>
              </div>
              <div class="form-group">
                <label>导出范围</label>
                <select name="category_id" class="form-control">
                  <option value="0">全部分类</option>
                  <?php echo render_category_options($catTree); ?>
                </select>
              </div>
            </div>
            <button type="submit" class="btn btn-primary">开始导出</button>
          </form>
        </div>
      </div>

      <div class="card" style="margin-top:16px;">
        <div class="card-header"><h3>格式说明</h3></div>
        <div style="padding:20px;font-size:13px;color:var(--text2);line-height:1.8;">
          <strong>HTML 书签格式</strong>：标准 Netscape Bookmark File Format，可直接导入 Chrome、Edge、Firefox 等浏览器，按分类分组导出。<br>
          <strong>CSV 格式</strong>：包含标题、URL、描述、分类、点击数、添加时间，UTF-8 编码（带BOM），可直接用 Excel 打开。
        </div>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
