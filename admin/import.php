<?php
/**
 * v1.2 后台管理 - 批量导入系统书签
 * 支持 HTML 书签文件（浏览器导出）和 CSV 文件
 */
require_once __DIR__ . '/../includes/init.php';
Auth::requireAdmin();
$adminPage = 'import';
$pageTitle = '批量导入';
$currentPage = 'admin';

$message = '';
$messageType = '';
$importResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::check();
    $categoryId = (int)($_POST['category_id'] ?? 0);

    if ($categoryId <= 0) {
        $message = '请选择目标分类';
        $messageType = 'danger';
    } elseif (empty($_FILES['import_file']['name'])) {
        $message = '请选择要导入的文件';
        $messageType = 'danger';
    } else {
        $cat = DB::fetch("SELECT id FROM " . DB_PREFIX . "categories WHERE id = ? AND user_id = 0", [$categoryId]);
        if (!$cat) {
            $message = '所选系统分类不存在';
            $messageType = 'danger';
        } else {
            $fileName = $_FILES['import_file']['name'];
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $content = file_get_contents($_FILES['import_file']['tmp_name']);
            $links = [];

            if ($ext === 'html' || $ext === 'htm') {
                $links = parse_bookmark_html($content);
            } elseif ($ext === 'csv') {
                $links = parse_links_csv($content);
            } else {
                $message = '不支持的文件格式，请上传 .html 或 .csv 文件';
                $messageType = 'danger';
            }

            if (!$message && empty($links)) {
                $message = '未解析到有效链接';
                $messageType = 'danger';
            } elseif (!$message) {
                $added = 0;
                $failed = 0;
                foreach ($links as $link) {
                    $title = trim($link['title'] ?? '');
                    $url = trim($link['url'] ?? '');
                    $description = trim($link['description'] ?? '');
                    if (!$title || !$url || !filter_var($url, FILTER_VALIDATE_URL)) {
                        $failed++;
                        continue;
                    }
                    DB::insert(DB_PREFIX . 'bookmarks', [
                        'user_id' => 0,
                        'category_id' => $categoryId,
                        'title' => $title,
                        'url' => $url,
                        'description' => $description,
                        'is_public' => 1,
                        'sort' => 0,
                        'domain' => extract_domain($url),
                    ]);
                    $added++;
                }
                $importResult = ['added' => $added, 'failed' => $failed, 'total' => count($links)];
                $message = "导入完成：成功 {$added} 条，失败 {$failed} 条";
                $messageType = 'success';
                log_operation('import', 'bookmark', $categoryId, "后台批量导入 {$added} 条系统书签");
            }
        }
    }
}

// 获取系统分类
$systemCategories = DB::fetchAll(
    "SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = 0 ORDER BY sort ASC, id ASC"
);
$catTree = build_category_tree($systemCategories);

include __DIR__ . '/../includes/header.php';
?>
<div class="admin-layout">
  <?php include __DIR__ . '/sidebar.php'; ?>
  <div class="admin-main">
    <?php if ($message): ?>
    <div class="alert alert-<?php echo $messageType; ?>"><?php echo e($message); ?></div>
    <?php endif; ?>

    <?php if ($importResult): ?>
    <div class="import-stats">
      <div class="import-stat"><div class="num"><?php echo (int)$importResult['total']; ?></div><div class="label">解析总数</div></div>
      <div class="import-stat"><div class="num" style="color:#52c41a;"><?php echo (int)$importResult['added']; ?></div><div class="label">成功导入</div></div>
      <div class="import-stat"><div class="num" style="color:#ff4d4f;"><?php echo (int)$importResult['failed']; ?></div><div class="label">失败</div></div>
    </div>
    <?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h3>批量导入系统书签</h3>
        <a href="<?php echo url('admin/bookmarks.php'); ?>" class="btn btn-sm btn-default">返回书签管理</a>
      </div>
      <div style="padding:20px;">
        <form method="post" action="" enctype="multipart/form-data">
          <?php echo CSRF::field(); ?>
          <div class="form-group">
            <label>目标系统分类 *</label>
            <select name="category_id" class="form-control" required>
              <option value="">请选择分类</option>
              <?php echo render_category_options($catTree); ?>
            </select>
          </div>
          <div class="form-group">
            <label>导入文件 *</label>
            <input type="file" name="import_file" accept=".html,.htm,.csv" required class="form-control">
            <div class="form-hint">支持浏览器导出的 HTML 书签文件（Chrome/Edge/Firefox）和 CSV 文件，将导入到上方选择的系统分类中</div>
          </div>
          <button type="submit" class="btn btn-primary">开始导入</button>
          <a href="<?php echo url('admin/export.php'); ?>" class="btn btn-default">导出系统书签</a>
        </form>
      </div>
    </div>

    <div class="card" style="margin-top:16px;">
      <div class="card-header"><h3>CSV 文件格式说明</h3></div>
      <div style="padding:20px;font-size:13px;color:var(--text2);line-height:1.8;">
        CSV 文件第一行为表头（可选），每行格式：<code>标题,URL,描述</code><br>
        示例：<br>
        <code>百度,https://www.baidu.com,搜索引擎</code><br>
        <code>GitHub,https://github.com,代码托管</code>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
