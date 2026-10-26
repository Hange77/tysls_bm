<?php
/**
 * 首页数据聚合与渲染（v1.3.9.6 布局重构）
 * 两种布局由后台/界面设置决定，所有书签统一以小药丸展示，标题自动截断（含省略号）。
 * v1.3.9.8 升级版：通过 _home_view_ctx() 上下文复用 4 种排版给个人收藏夹(u.php)，
 * 默认行为（user_id=0, 不显示私有/便签/密码）与网址大全完全一致，对 index.php 零影响。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/**
 * 首页渲染视图上下文：让首页的 4 种排版可复用给用户个人收藏夹(u.php)。
 * 未调用 home_set_view_ctx() 时使用默认（即网址大全行为），index.php 无需任何改动。
 */
function _home_view_ctx() {
    return $GLOBALS['_home_view_ctx'] ?? [
        'user_id'          => 0,
        'can_view_private' => false,
        'show_notes'       => false,
        'check_password'   => false,
    ];
}

function home_set_view_ctx($userId = 0, $canViewPrivate = false, $showNotes = false, $checkPassword = false) {
    $GLOBALS['_home_view_ctx'] = [
        'user_id'          => (int)$userId,
        'can_view_private' => (bool)$canViewPrivate,
        'show_notes'       => (bool)$showNotes,
        'check_password'   => (bool)$checkPassword,
    ];
}

/**
 * 获取首页/收藏夹分类树（按上下文 user_id 取数；build_category_tree 为纯函数）
 */
function get_home_tree() {
    $ctx = _home_view_ctx();
    $sql = "SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = ? AND deleted_at IS NULL";
    if (!$ctx['can_view_private']) {
        $sql .= " AND is_public = 1";
    }
    $sql .= " ORDER BY sort ASC, id ASC";
    $allCats = DB::fetchAll($sql, [$ctx['user_id']]);
    return build_category_tree($allCats);
}

/**
 * 拉取某分类的直属公开书签（按上下文 user_id + 公开性过滤）
 */
function _home_direct_bookmarks($catId) {
    $ctx = _home_view_ctx();
    $sql = "SELECT * FROM " . DB_PREFIX . "bookmarks WHERE user_id = ? AND category_id = ? AND deleted_at IS NULL";
    if (!$ctx['can_view_private']) {
        $sql .= " AND is_public = 1";
    }
    $sql .= " ORDER BY sort ASC, id ASC";
    return DB::fetchAll($sql, [$ctx['user_id'], (int)$catId]);
}

/**
 * 拉取某分类的直属便签（按上下文 user_id + 公开性过滤）
 */
function _home_direct_notes($catId, $limit = 6) {
    $ctx = _home_view_ctx();
    $sql = "SELECT * FROM " . DB_PREFIX . "pages WHERE user_id = ? AND content_type = 'text' AND category_id = ? AND deleted_at IS NULL";
    if (!$ctx['can_view_private']) {
        $sql .= " AND is_public = 1";
    }
    $sql .= " ORDER BY created_at DESC LIMIT " . (int)$limit;
    return DB::fetchAll($sql, [$ctx['user_id'], (int)$catId]);
}

/**
 * 渲染便签网格（与 u.php 原样式保持一致）
 */
function _home_render_notes($notes) {
    if (empty($notes)) {
        return;
    }
    ?>
    <h3 style="font-size:15px;margin:20px 0 12px;color:var(--text-light);">便签</h3>
    <div class="note-grid">
      <?php foreach ($notes as $note): ?>
      <a href="<?php echo e(page_url($note)); ?>" class="note-card" style="text-decoration:none;color:inherit;display:block;">
        <h3><?php echo e($note['title']); ?></h3>
        <div class="note-content"><?php echo e(truncate($note['content'], 80)); ?></div>
        <div class="note-meta"><span><?php echo e(date('m-d H:i', strtotime($note['created_at']))); ?></span></div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php
}

/**
 * 拉取某分类的直属 HTML 页面（后台页面管理创建，content_type='html'）
 * 用于「页面/帮助」类站内链接展示，直接走 page_url() 不经 go.php
 * 注意：pages 表无 sort 字段，按 id 排序
 */
function _home_direct_pages($catId) {
    $ctx = _home_view_ctx();
    $sql = "SELECT * FROM " . DB_PREFIX . "pages WHERE user_id = ? AND content_type = 'html' AND category_id = ? AND deleted_at IS NULL";
    if (!$ctx['can_view_private']) {
        $sql .= " AND is_public = 1";
    }
    $sql .= " ORDER BY id ASC";
    return DB::fetchAll($sql, [$ctx['user_id'], (int)$catId]);
}

/**
 * 渲染分类下的 HTML 页面链接列表（站内直链，不经 go.php）
 * 复用书签小药丸(.bookmark-mini)样式，与书签视觉一致
 */
function _home_render_pages($pages) {
    if (empty($pages)) {
        return;
    }
    ?>
    <div class="pill-grid cat-page-pills">
      <?php foreach ($pages as $p): ?>
      <a href="<?php echo e(page_url($p)); ?>" class="bookmark-mini cat-page-pill" title="<?php echo e($p['title']); ?>">
        <span class="mini-icon">📄</span>
        <span class="mini-title"><?php echo e($p['title']); ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php
}

/**
 * 分类是否需要密码解锁（仅当上下文开启 check_password 时生效；网址大全默认关闭）
 */
function _home_cat_locked($cat) {
    $ctx = _home_view_ctx();
    if (!$ctx['check_password'] || empty($cat['password'])) {
        return false;
    }
    return empty($_SESSION['cat_unlocked_' . (int)$cat['id']]);
}

/**
 * 渲染分类密码解锁表单
 */
function _home_render_unlock_form($catId) {
    ?>
    <div class="card" style="text-align:center;padding:20px;margin:8px 0;">
      <p style="color:var(--text-muted);margin-bottom:10px;font-size:13px;">该分类需要密码</p>
      <form method="post" action="" style="max-width:260px;margin:0 auto;display:flex;gap:6px;">
        <?php echo CSRF::field(); ?>
        <input type="hidden" name="cat_id" value="<?php echo (int)$catId; ?>">
        <input type="password" name="cat_password" class="form-control form-control-sm" placeholder="输入密码" required>
        <button type="submit" class="btn btn-sm btn-primary">解锁</button>
      </form>
    </div>
    <?php
}

/**
 * 默认布局：顶部分类按序展开
 * - 每个顶级分类展示其直属书签（小药丸）
 * - 不内联子分类；点击「更多」进入分类页（分类页内子分类置顶）
 * - 仅含子分类、无直属书签的分类仍展示标题 + 更多，保证可进入
 */
function render_home_default($tree) {
    $ctx = _home_view_ctx();
    foreach ($tree as $cat) {
        $catId = (int)$cat['id'];
        $directBms = _home_direct_bookmarks($catId);
        $subCats = $cat['children'] ?? [];
        $notes = $ctx['show_notes'] ? _home_direct_notes($catId) : [];
        $pages = _home_direct_pages($catId);
        if (empty($directBms) && empty($subCats) && empty($notes) && empty($pages)) {
            continue;
        }
        $catLink = category_url($cat);
        ?>
        <div class="home-section home-default-section" id="cat-<?php echo $catId; ?>">
          <div class="section-title">
            <?php if ($cat['icon']): ?><span class="section-icon"><?php echo e($cat['icon']); ?></span><?php endif; ?>
            <a href="<?php echo e($catLink); ?>"><?php echo e($cat['name']); ?></a>
            <a href="<?php echo e($catLink); ?>" class="section-more">更多 &raquo;</a>
          </div>
          <?php if (_home_cat_locked($cat)): ?>
            <?php _home_render_unlock_form($catId); ?>
          <?php else: ?>
            <?php if (!empty($directBms)): ?>
            <div class="pill-grid">
              <?php foreach ($directBms as $bm): render_bookmark_pill($bm, $ctx['can_view_private']); endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($subCats)): ?>
            <div class="pill-grid" style="margin-top:8px;">
              <?php foreach ($subCats as $sc): ?>
              <a href="<?php echo e(category_url($sc)); ?>" class="bookmark-mini" title="<?php echo e($sc['name']); ?>">
                <?php if ($sc['icon']): ?><span class="mini-icon"><?php echo e($sc['icon']); ?></span><?php endif; ?>
                <span class="mini-title"><?php echo e($sc['name']); ?></span>
              </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($notes)): ?>
              <?php _home_render_notes($notes); ?>
            <?php endif; ?>
            <?php if (!empty($pages)): ?>
              <?php _home_render_pages($pages); ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
        <?php
    }
}

/**
 * 分类竖排布局：左侧分类导航，右侧书签面板
 * - 点击顶级分类：右侧展示其直属书签 + 自动展开的子分类
 * - 点击子分类：右侧仅展示该子分类的书签
 */
function render_home_vertical($tree) {
    $first = true;
    ?>
    <div class="home-vertical">
      <div class="vcat-nav" id="vcatNav">
        <?php foreach ($tree as $cat): ?>
          <?php $subCats = $cat['children'] ?? []; ?>
          <div class="vcat-nav-item">
            <a href="#" class="vcat-link<?php echo $first ? ' active' : ''; ?>" data-target="vcat-<?php echo (int)$cat['id']; ?>">
              <?php if ($cat['icon']): ?><span class="vcat-icon"><?php echo e($cat['icon']); ?></span><?php endif; ?>
              <span class="vcat-name"><?php echo e($cat['name']); ?></span>
            </a>
            <?php if (!empty($subCats)): ?>
            <div class="vcat-sub">
              <?php foreach ($subCats as $sc): ?>
              <a href="#" class="vcat-sub-link" data-target="vsub-<?php echo (int)$sc['id']; ?>">
                <?php if ($sc['icon']): ?><span class="vcat-icon"><?php echo e($sc['icon']); ?></span><?php endif; ?>
                <span><?php echo e($sc['name']); ?></span>
              </a>
              <?php endforeach; ?>
            </div>
            <?php endif; ?>
          </div>
          <?php $first = false; ?>
        <?php endforeach; ?>
      </div>
      <div class="vcat-content" id="vcatContent">
        <?php foreach ($tree as $cat): ?>
          <?php _render_vcat_panel($cat); ?>
        <?php endforeach; ?>
      </div>
    </div>
    <script>
    (function(){
      var nav = document.getElementById('vcatNav');
      var panels = document.querySelectorAll('.vcat-panel');
      function showPanel(id){
        panels.forEach(function(p){ p.classList.toggle('is-active', p.id === id); });
        document.querySelectorAll('.vcat-link, .vcat-sub-link').forEach(function(a){
          a.classList.toggle('active', a.dataset.target === id);
        });
      }
      if (nav) {
        nav.addEventListener('click', function(e){
          var link = e.target.closest('.vcat-link, .vcat-sub-link');
          if (!link) return;
          e.preventDefault();
          showPanel(link.dataset.target);
        });
      }
      if (panels.length) showPanel(panels[0].id);
    })();
    </script>
    <?php
}

/**
 * 渲染单个顶级分类面板（含其直属书签与子分类）
 */
function _render_vcat_panel($cat) {
    $ctx = _home_view_ctx();
    $catId = (int)$cat['id'];
    $directBms = _home_direct_bookmarks($catId);
    $subCats = $cat['children'] ?? [];
    $notes = $ctx['show_notes'] ? _home_direct_notes($catId) : [];
    $pages = _home_direct_pages($catId);
    ?>
    <div class="vcat-panel" id="vcat-<?php echo $catId; ?>">
      <?php if (_home_cat_locked($cat)): ?>
        <?php _home_render_unlock_form($catId); ?>
      <?php else: ?>
        <?php if (!empty($directBms)): ?>
        <div class="pill-grid">
          <?php foreach ($directBms as $bm): render_bookmark_pill($bm, $ctx['can_view_private']); endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($notes)): ?><?php _home_render_notes($notes); ?><?php endif; ?>
        <?php if (!empty($pages)): ?><?php _home_render_pages($pages); ?><?php endif; ?>
        <?php foreach ($subCats as $sc): ?>
          <?php $scBms = _home_direct_bookmarks($sc['id']); ?>
          <?php $scNotes = $ctx['show_notes'] ? _home_direct_notes($sc['id'], 4) : []; ?>
          <?php $scPages = _home_direct_pages($sc['id']); ?>
          <div class="vsub-block" id="vsub-<?php echo (int)$sc['id']; ?>">
            <div class="vsub-title">
              <?php if ($sc['icon']): ?><span class="vcat-icon"><?php echo e($sc['icon']); ?></span><?php endif; ?>
              <a href="<?php echo e(category_url($sc)); ?>"><?php echo e($sc['name']); ?></a>
            </div>
            <?php if (_home_cat_locked($sc)): ?>
              <?php _home_render_unlock_form($sc['id']); ?>
            <?php else: ?>
              <?php if (!empty($scBms)): ?>
              <div class="pill-grid">
                <?php foreach ($scBms as $bm): render_bookmark_pill($bm, $ctx['can_view_private']); endforeach; ?>
              </div>
              <?php endif; ?>
              <?php if (!empty($scNotes)): ?><?php _home_render_notes($scNotes); ?><?php endif; ?>
              <?php if (!empty($scPages)): ?><?php _home_render_pages($scPages); ?><?php endif; ?>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        <a href="<?php echo e(category_url($cat)); ?>" class="vcat-more-link">查看更多 &raquo;</a>
      <?php endif; ?>
    </div>
    <?php
    // 子分类独立面板（点击左侧子分类时只显示该子分类）
    foreach ($subCats as $sc):
      $scBms = _home_direct_bookmarks($sc['id']);
      $scNotes = $ctx['show_notes'] ? _home_direct_notes($sc['id'], 4) : [];
      $scPages = _home_direct_pages($sc['id']);
    ?>
    <div class="vcat-panel" id="vsub-<?php echo (int)$sc['id']; ?>">
      <div class="vsub-title">
        <?php if ($sc['icon']): ?><span class="vcat-icon"><?php echo e($sc['icon']); ?></span><?php endif; ?>
        <a href="<?php echo e(category_url($sc)); ?>"><?php echo e($sc['name']); ?></a>
      </div>
      <?php if (_home_cat_locked($sc)): ?>
        <?php _home_render_unlock_form($sc['id']); ?>
      <?php else: ?>
        <?php if (!empty($scBms)): ?>
        <div class="pill-grid">
          <?php foreach ($scBms as $bm): render_bookmark_pill($bm, $ctx['can_view_private']); endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($scNotes)): ?><?php _home_render_notes($scNotes); ?><?php endif; ?>
        <?php if (!empty($scPages)): ?><?php _home_render_pages($scPages); ?><?php endif; ?>
      <?php endif; ?>
      <a href="<?php echo e(category_url($sc)); ?>" class="vcat-more-link">查看更多 &raquo;</a>
    </div>
    <?php endforeach;
}

/**
 * 分类横排布局：顶部一级分类标签 → 二级子分类标签 → 书签（小药丸）
 * - 一级分类无子分类时，直接展示其直属书签
 * - 选中一级分类后：先显示子分类标签，子分类标签下方另起一行展示书签
 * - 「更多」跳转到该一级分类页
 */
function render_home_horizontal($tree) {
    $ctx = _home_view_ctx();
    ?>
    <div class="home-horizontal">
      <div class="hcat-tabs" id="hcatTabs">
        <?php foreach ($tree as $i => $cat): ?>
          <a href="#" class="hcat-tab<?php echo $i === 0 ? ' active' : ''; ?>" data-target="hcat-<?php echo (int)$cat['id']; ?>">
            <?php if ($cat['icon']): ?><span class="hcat-icon"><?php echo e($cat['icon']); ?></span><?php endif; ?>
            <span><?php echo e($cat['name']); ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <div class="hcat-content" id="hcatContent">
        <?php foreach ($tree as $i => $cat): ?>
          <?php $subCats = $cat['children'] ?? []; ?>
          <div class="hcat-panel" id="hcat-<?php echo (int)$cat['id']; ?>" <?php echo $i === 0 ? '' : 'hidden'; ?>>
            <?php if (_home_cat_locked($cat)): ?>
              <?php _home_render_unlock_form($cat['id']); ?>
            <?php else: ?>
              <?php if (!empty($subCats)): ?>
              <div class="hsub-tabs">
                <a href="#" class="hsub-tab active" data-target="hbm-all-<?php echo (int)$cat['id']; ?>">全部</a>
                <?php foreach ($subCats as $sc): ?>
                <a href="#" class="hsub-tab" data-target="hbm-<?php echo (int)$sc['id']; ?>"><?php echo e($sc['name']); ?></a>
                <?php endforeach; ?>
              </div>
              <?php endif; ?>
              <div class="hbm-groups">
                <div class="hbm-group" id="hbm-all-<?php echo (int)$cat['id']; ?>">
                  <?php $direct = _home_direct_bookmarks($cat['id']); ?>
                  <?php if (!empty($direct)): ?><div class="pill-grid"><?php foreach ($direct as $bm) render_bookmark_pill($bm, $ctx['can_view_private']); ?></div><?php endif; ?>
                  <?php if ($ctx['show_notes']): ?><?php _home_render_notes(_home_direct_notes($cat['id'])); ?><?php endif; ?>
                  <?php $_hp = _home_direct_pages($cat['id']); ?><?php if (!empty($_hp)): ?><?php _home_render_pages($_hp); ?><?php endif; ?>
                </div>
                <?php foreach ($subCats as $sc): ?>
                <div class="hbm-group" id="hbm-<?php echo (int)$sc['id']; ?>" hidden>
                  <?php $scBms = _home_direct_bookmarks($sc['id']); ?>
                  <?php if (!empty($scBms)): ?><div class="pill-grid"><?php foreach ($scBms as $bm) render_bookmark_pill($bm, $ctx['can_view_private']); ?></div><?php endif; ?>
                  <?php if ($ctx['show_notes']): ?><?php _home_render_notes(_home_direct_notes($sc['id'], 4)); ?><?php endif; ?>
                  <?php $_hp = _home_direct_pages($sc['id']); ?><?php if (!empty($_hp)): ?><?php _home_render_pages($_hp); ?><?php endif; ?>
                </div>
                <?php endforeach; ?>
              </div>
              <a href="<?php echo e(category_url($cat)); ?>" class="hcat-more-link">更多 &raquo;</a>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <script>
    (function(){
      var tabs = document.querySelectorAll('#hcatTabs .hcat-tab');
      var panels = document.querySelectorAll('.hcat-panel');
      tabs.forEach(function(t){
        t.addEventListener('click', function(e){
          e.preventDefault();
          tabs.forEach(function(x){ x.classList.toggle('active', x === t); });
          var id = t.dataset.target;
          panels.forEach(function(p){ p.hidden = (p.id !== id); });
        });
      });
      document.querySelectorAll('.hsub-tabs').forEach(function(row){
        var subTabs = row.querySelectorAll('.hsub-tab');
        var panel = row.closest('.hcat-panel');
        subTabs.forEach(function(st){
          st.addEventListener('click', function(e){
            e.preventDefault();
            subTabs.forEach(function(x){ x.classList.toggle('active', x === st); });
            var gid = st.dataset.target;
            panel.querySelectorAll('.hbm-group').forEach(function(g){ g.hidden = (g.id !== gid); });
          });
        });
      });
      if (panels.length) panels[0].hidden = false;
    })();
    </script>
    <?php
}

/**
 * 分类卡片布局：每屏多张分类卡片平铺（自动换行）
 * - 每张卡片 = 一个顶级分类
 * - 卡片内先展示子分类（小药丸，链接到子分类页），再展示直属书签（小药丸）
 * - 子分类 / 书签各自限制展示数量，超出部分通过「更多」进入该一级分类页
 * - 无子分类的一级分类：直接展示其直属书签
 * - 手机端卡片按分类顺序竖向堆叠（见 style.css @media）
 */
function render_home_card($tree) {
    $ctx = _home_view_ctx();
    $bookmarkLimit = 8;  // 每张卡片最多展示的直属书签数（配合 2~3 列，约 3~4 行）
    $subcatLimit = 4;    // 每张卡片最多展示的子分类数
    ?>
    <div class="home-card-grid">
      <?php foreach ($tree as $cat): ?>
        <?php
        $catId = (int)$cat['id'];
        $subCats = $cat['children'] ?? [];
        $directBms = _home_direct_bookmarks($catId);
        $notes = $ctx['show_notes'] ? _home_direct_notes($catId) : [];
        $pages = _home_direct_pages($catId);
        $catLink = category_url($cat);
        if (empty($subCats) && empty($directBms) && empty($notes) && empty($pages)) {
            continue;
        }
        $shownSubs = array_slice($subCats, 0, $subcatLimit);
        $moreSubs = count($subCats) > $subcatLimit;
        $shownBms = array_slice($directBms, 0, $bookmarkLimit);
        $moreBms = count($directBms) > $bookmarkLimit;
        ?>
        <div class="home-card" id="card-<?php echo $catId; ?>">
          <?php if (_home_cat_locked($cat)): ?>
            <div class="home-card-head">
              <?php if ($cat['icon']): ?><span class="home-card-icon"><?php echo e($cat['icon']); ?></span><?php endif; ?>
              <a href="<?php echo e($catLink); ?>" class="home-card-title"><?php echo e($cat['name']); ?></a>
            </div>
            <?php _home_render_unlock_form($catId); ?>
          <?php else: ?>
            <div class="home-card-head">
              <?php if ($cat['icon']): ?><span class="home-card-icon"><?php echo e($cat['icon']); ?></span><?php endif; ?>
              <a href="<?php echo e($catLink); ?>" class="home-card-title"><?php echo e($cat['name']); ?></a>
              <a href="<?php echo e($catLink); ?>" class="home-card-more">更多 &raquo;</a>
            </div>
            <?php if (!empty($subCats)): ?>
            <div class="home-card-subcats">
              <?php foreach ($shownSubs as $sc): ?>
              <a href="<?php echo e(category_url($sc)); ?>" class="bookmark-mini home-card-sub" title="<?php echo e($sc['name']); ?>"><?php echo e($sc['name']); ?></a>
              <?php endforeach; ?>
              <?php if ($moreSubs): ?>
              <a href="<?php echo e($catLink); ?>" class="bookmark-mini home-card-sub home-card-sub-more" title="查看全部子分类">+<?php echo (count($subCats) - $subcatLimit); ?></a>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($directBms)): ?>
            <div class="pill-grid home-card-bms">
              <?php foreach ($shownBms as $bm): render_bookmark_pill($bm, $ctx['can_view_private']); endforeach; ?>
              <?php if ($moreBms): ?>
              <a href="<?php echo e($catLink); ?>" class="bookmark-mini home-card-bm-more" title="查看更多书签">更多 &raquo;</a>
              <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($notes)): ?>
              <?php _home_render_notes($notes); ?>
            <?php endif; ?>
            <?php if (!empty($pages)): ?>
              <?php _home_render_pages($pages); ?>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
    <?php
}
