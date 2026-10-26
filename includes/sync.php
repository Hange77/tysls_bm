<?php
/**
 * 浏览器插件同步 —— 共享辅助函数
 * 由 includes/api_init.php 自动加载，供 /api/v1/sync/* 与 /api/v1/me 调用。
 *
 * 同步范围约定：
 *  - 浏览器书签统一落在「浏览器书签」根分类（source='browser', ext_id='__browser_root__'，
 *    parent_id=0, user_id=当前用户）之下；
 *  - 浏览器文件夹 → 该根分类下的同名子分类（ext_id 锚定浏览器文件夹节点 ID）；
 *  - 同步范围 = 该根分类及其全部后代分类内的书签，与记录来源(source)无关，
 *    这样用户在网页端手动加入该区域的书签也能双向同步。
 */

const BROWSER_ROOT_EXT = '__browser_root__';
const BROWSER_ROOT_NAME = '浏览器书签';
const SYNC_RENAME_MARKER = '（浏览器）'; // 浏览器文件夹与系统同名分类冲突时，自动追加的改名标记
const DIFF_TOKEN_TTL = 600; // preview 令牌有效期（秒）

// ========== 同步偏好（存于 users.sync_prefs JSON） ==========

function api_get_sync_prefs($userId) {
    $row = DB::fetch("SELECT sync_prefs FROM " . DB_PREFIX . "users WHERE id = ?", [$userId]);
    $prefs = [];
    if ($row && !empty($row['sync_prefs'])) {
        $dec = json_decode($row['sync_prefs'], true);
        if (is_array($dec)) {
            $prefs = $dec;
        }
    }
    return [
        'delete_enabled'        => !empty($prefs['delete_enabled']),
        'trash_days'            => isset($prefs['trash_days']) ? (int)$prefs['trash_days'] : 30,
        'protected_category_ids'=> isset($prefs['protected_category_ids']) && is_array($prefs['protected_category_ids'])
            ? array_map('intval', $prefs['protected_category_ids']) : [],
    ];
}

function api_set_sync_prefs($userId, $prefs) {
    $cur = api_get_sync_prefs($userId);
    if (isset($prefs['delete_enabled'])) {
        $cur['delete_enabled'] = !empty($prefs['delete_enabled']);
    }
    if (isset($prefs['trash_days'])) {
        $cur['trash_days'] = max(1, (int)$prefs['trash_days']);
    }
    if (isset($prefs['protected_category_ids']) && is_array($prefs['protected_category_ids'])) {
        $cur['protected_category_ids'] = array_map('intval', $prefs['protected_category_ids']);
    }
    DB::update(DB_PREFIX . 'users', ['sync_prefs' => json_encode($cur, JSON_UNESCAPED_UNICODE)], 'id = ?', [$userId]);
    return $cur;
}

// ========== 浏览器书签根分类 ==========

function api_get_browser_root($userId) {
    return DB::fetch(
        "SELECT * FROM " . DB_PREFIX . "categories WHERE user_id = ? AND parent_id = 0 AND source = 'browser' AND ext_id = ?",
        [$userId, BROWSER_ROOT_EXT]
    );
}

function api_ensure_browser_root($userId) {
    $root = api_get_browser_root($userId);
    if ($root) {
        return $root;
    }
    $defaultPublic = setting('default_bookmark_public', '1') == '1' ? 1 : 0;
    $id = DB::insert(DB_PREFIX . 'categories', [
        'user_id'    => $userId,
        'parent_id'  => 0,
        'name'       => BROWSER_ROOT_NAME,
        'slug'       => make_slug(BROWSER_ROOT_NAME) ?: 'browser-bookmarks',
        'description'=> '浏览器插件同步专用根分类',
        'icon'       => '🔖',
        'sort'       => 0,
        'is_public'  => $defaultPublic,
        'password'   => '',
        'source'     => 'browser',
        'ext_id'     => BROWSER_ROOT_EXT,
        'deleted_at' => null,
    ]);
    log_operation('sync', 'category', $id, '创建浏览器书签根分类');
    $row = DB::fetch("SELECT * FROM " . DB_PREFIX . "categories WHERE id = ?", [$id]);
    if (!$row) {
        // 兜底：极端情况下插入成功但查询失败（如复制延迟），手动构造返回，避免 null 解引用
        $row = [
            'id'         => $id,
            'user_id'    => $userId,
            'parent_id'  => 0,
            'name'       => BROWSER_ROOT_NAME,
            'slug'       => 'browser-bookmarks',
            'icon'       => '🔖',
            'sort'       => 0,
            'is_public'  => $defaultPublic,
            'password'   => '',
            'source'     => 'browser',
            'ext_id'     => BROWSER_ROOT_EXT,
            'deleted_at' => null,
        ];
    }
    return $row;
}

/**
 * 取根分类的全部后代分类 ID（含根自身）
 */
function api_get_subtree_ids($userId, $rootId) {
    $ids = [(int)$rootId];
    $queue = [(int)$rootId];
    while ($queue) {
        $pid = array_shift($queue);
        $children = DB::fetchAll("SELECT id FROM " . DB_PREFIX . "categories WHERE user_id = ? AND parent_id = ?", [$userId, $pid]);
        foreach ($children as $c) {
            $cid = (int)$c['id'];
            if (!in_array($cid, $ids, true)) {
                $ids[] = $cid;
                $queue[] = $cid;
            }
        }
    }
    return $ids;
}

/**
 * 生成同一父分类下不重名的分类名。
 * 浏览器文件夹在系统无对应 ext_id 时一律新建；若同父级已存在同名分类（典型如系统已有
 * 「收藏夹栏」而浏览器也同步上来一个「收藏夹栏」），自动追加「（浏览器）」「（浏览器2）」等，
 * 避免把浏览器文件夹误合并进系统原有分类、进而误删系统书签。
 * @param array &$usedNames 本次同步内已占用（尚未落库）的名字集合，防止多个同名新建互相撞名
 */
function ensure_unique_category_name($userId, $parentId, $name, &$usedNames = []) {
    $prefix = DB_PREFIX;
    $exists = function ($n) use ($prefix, $userId, $parentId) {
        $row = DB::fetch(
            "SELECT id FROM {$prefix}categories WHERE user_id = ? AND parent_id = ? AND name = ? AND deleted_at IS NULL LIMIT 1",
            [$userId, (int)$parentId, $n]
        );
        return !empty($row);
    };
    $final = $name;
    $collide = $exists($final) || in_array($final, $usedNames, true);
    if ($collide) {
        $final = $name . SYNC_RENAME_MARKER;            // 收藏夹栏（浏览器）
        if ($exists($final) || in_array($final, $usedNames, true)) {
            $i = 2;
            // 编号形式：收藏夹栏（浏览器2）
            while ($exists($name . '（浏览器' . $i . '）') || in_array($name . '（浏览器' . $i . '）', $usedNames, true)) {
                $i++;
            }
            $final = $name . '（浏览器' . $i . '）';
        }
    }
    $usedNames[] = $final;
    return $final;
}

// ========== 预览令牌（一次性、限时） ==========
// 存储客户端提交的浏览器快照，push 时取出重算并应用，确保 preview==applied。

function api_store_preview($userId, $payload) {
    $dt = new_token();
    $file = rtrim(sys_get_temp_dir(), '/') . '/.bm_diff_' . hash('sha256', $dt);
    $data = [
        'user_id' => $userId,
        'exp'     => time() + DIFF_TOKEN_TTL,
        'payload' => $payload,
    ];
    @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $dt;
}

function api_consume_preview($dt) {
    if (!preg_match('/^[a-f0-9]{64}$/', $dt)) {
        return null;
    }
    $file = rtrim(sys_get_temp_dir(), '/') . '/.bm_diff_' . hash('sha256', $dt);
    if (!@file_exists($file)) {
        return null;
    }
    $raw = @file_get_contents($file);
    @unlink($file);
    if (!$raw) {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || empty($data['payload'])) {
        return null;
    }
    if (time() > (int)$data['exp']) {
        return null;
    }
    return $data['payload'];
}

// ========== 差异计算（browser -> system） ==========

/**
 * @param int   $userId
 * @param array $browserCats [{ext_id, parent_ext_id, name, updated_at?}]
 * @param array $browserBms  [{ext_id, category_ext_id, title, url, description?, icon?, updated_at?, deleted?}]
 * @param array $prefs       来自 api_get_sync_prefs
 * @return array ['summary'=>..., 'actions'=>['categories'=>[],'bookmarks'=>[]], 'deletes'=>[]]
 */
function compute_sync_diff($userId, $browserCats, $browserBms, $prefs) {
    $prefix = DB_PREFIX;
    $root = api_ensure_browser_root($userId);
    $rootId = (int)$root['id'];
    $rootExt = $root['ext_id'];

    $subtree = api_get_subtree_ids($userId, $rootId);
    $inClause = implode(',', $subtree);

    // 现有记录映射
    $catByExt = []; $catById = [];
    $cats = DB::fetchAll("SELECT * FROM {$prefix}categories WHERE user_id = ? AND id IN ({$inClause})", [$userId]);
    foreach ($cats as $c) { $catByExt[$c['ext_id']] = $c; $catById[(int)$c['id']] = $c; }

    $bmByExt = []; $bmById = [];
    $bms = DB::fetchAll("SELECT * FROM {$prefix}bookmarks WHERE user_id = ? AND category_id IN ({$inClause})", [$userId]);
    foreach ($bms as $b) {
        $bmByExt[$b['ext_id']] = $b;
        $bmById[(int)$b['id']] = $b;
    }

    $defaultPublic = setting('default_bookmark_public', '1') == '1' ? 1 : 0;

    $summary = [
        'add'      => ['cat' => 0, 'bm' => 0],
        'update'   => ['cat' => 0, 'bm' => 0],
        'delete'   => ['cat' => 0, 'bm' => 0],
        'conflict' => ['cat' => 0, 'bm' => 0],
        'skip'     => 0,
    ];
    $actions = ['categories' => [], 'bookmarks' => []];
    $deletes = [];
    $extToSysId = [$rootExt => $rootId, '' => $rootId];

    // ---- 分类 ----
    $usedNames = []; // 本次同步内已占用的分类名，避免多个同名新建互相撞名
    $browserCatByExt = [];
    foreach ($browserCats as $c) {
        if (!empty($c['ext_id'])) {
            $browserCatByExt[$c['ext_id']] = $c;
        }
    }
    $depthOf = function ($cat) use (&$browserCatByExt, $rootExt) {
        $d = 0; $cur = $cat; $seen = [];
        while (!empty($cur['parent_ext_id']) && $cur['parent_ext_id'] !== $rootExt && isset($browserCatByExt[$cur['parent_ext_id']])) {
            if (isset($seen[$cur['parent_ext_id']])) break;
            $seen[$cur['parent_ext_id']] = 1;
            $cur = $browserCatByExt[$cur['parent_ext_id']];
            $d++;
            if ($d > 30) break;
        }
        return $d;
    };
    $sortedCats = $browserCats;
    usort($sortedCats, function ($a, $b) use ($depthOf) {
        return $depthOf($a) - $depthOf($b);
    });

    $incomingCatExt = [];
    foreach ($sortedCats as $c) {
        $ext = (string)$c['ext_id'];
        if ($ext === '') { $summary['skip']++; continue; }
        $incomingCatExt[] = $ext;
        $parentExt = (string)($c['parent_ext_id'] ?? '');
        $pid = $extToSysId[$parentExt] ?? null;
        if ($pid === null) { $summary['skip']++; continue; }
        $pid = (int)$pid;
        $name = trim((string)($c['name'] ?? ''));
        if ($name === '') { $summary['skip']++; continue; }

        // 严格按 ext_id 锚定：不再按「父级 + 同名」合并，避免浏览器文件夹被误判为系统已有分类
        $match = isset($catByExt[$ext]) ? $catByExt[$ext] : null;
        if ($match) {
            $extToSysId[$ext] = (int)$match['id'];
            $upd = [];
            // 已因同名冲突自动改名的浏览器分类，保留改名结果，不再被浏览器原名称覆盖
            if ($match['name'] !== $name && strpos($match['name'], '（浏览器') === false) {
                $upd['name'] = $name;
            }
            $clientTs = !empty($c['updated_at']) ? strtotime($c['updated_at']) : null;
            if ($clientTs !== null && strtotime($match['updated_at']) > $clientTs) {
                $summary['conflict']['cat']++;
            } elseif (!empty($upd)) {
                $actions['categories'][] = ['op' => 'update', 'id' => (int)$match['id'], 'data' => $upd, 'deleted' => !empty($c['deleted'])];
                $summary['update']['cat']++;
            }
        } else {
            // 浏览器文件夹在系统无对应 ext_id → 一律新建；与系统同名时自动改名，绝不并入系统原有分类
            $name = ensure_unique_category_name($userId, $pid, $name, $usedNames);
            $extToSysId[$ext] = 'NEW_CAT_' . $ext;
            $actions['categories'][] = [
                'op'         => 'create', 'ext' => $ext, 'parent_id' => $pid,
                'name'       => $name, 'source' => 'browser', 'deleted' => !empty($c['deleted']),
            ];
            $summary['add']['cat']++;
        }
    }

    // ---- 书签 ----
    $incomingBmExt = [];
    foreach ($browserBms as $b) {
        $ext = (string)($b['ext_id'] ?? '');
        if ($ext === '') { $summary['skip']++; continue; }
        $incomingBmExt[] = $ext;
        $url = trim((string)($b['url'] ?? ''));
        $title = trim((string)($b['title'] ?? ''));
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) { $summary['skip']++; continue; }
        $domain = extract_domain($url);

        // 显式删除标记（浏览器侧已删）
        if (!empty($b['deleted']) && isset($bmByExt[$ext])) {
            $deletes[] = ['type' => 'bookmark', 'id' => (int)$bmByExt[$ext]['id']];
            continue;
        }

        // 严格按 ext_id 匹配：不再按 URL / 域名+标题回退合并，确保浏览器书签与系统书签各自独立镜像
        $match = isset($bmByExt[$ext]) ? $bmByExt[$ext] : null;

        if ($match) {
            $upd = [];
            if ($match['title'] !== $title) $upd['title'] = $title;
            if ($match['url'] !== $url) { $upd['url'] = $url; $upd['domain'] = $domain; }
            if (!empty($b['description']) && $match['description'] !== $b['description']) $upd['description'] = $b['description'];
            if (!empty($b['icon']) && $match['icon'] !== $b['icon']) $upd['icon'] = $b['icon'];
            $clientTs = !empty($b['updated_at']) ? strtotime($b['updated_at']) : null;
            if ($clientTs !== null && strtotime($match['updated_at']) > $clientTs) {
                $summary['conflict']['bm']++;
            } elseif (!empty($upd)) {
                $actions['bookmarks'][] = [
                    'op' => 'update', 'id' => (int)$match['id'], 'data' => $upd,
                    'category_ext_id' => (string)($b['category_ext_id'] ?? ''),
                    'was_unlinked' => false,
                ];
                $summary['update']['bm']++;
            }
        } else {
            $actions['bookmarks'][] = [
                'op' => 'create', 'ext' => $ext,
                'category_ext_id' => (string)($b['category_ext_id'] ?? ''),
                'title' => $title, 'url' => $url, 'description' => (string)($b['description'] ?? ''),
                'icon' => (string)($b['icon'] ?? ''), 'domain' => $domain, 'source' => 'browser',
            ];
            $summary['add']['bm']++;
        }
    }

    // ---- 删除传播（仅当允许同步删除） ----
    if (!empty($prefs['delete_enabled'])) {
        $protected = !empty($prefs['protected_category_ids']) ? $prefs['protected_category_ids'] : [];
        // 浏览器侧已不存在（absent）的 source='browser' 记录 => 软删除
        foreach ($catById as $cc) {
            if ($cc['ext_id'] === BROWSER_ROOT_EXT) continue;
            if ($cc['source'] === 'browser' && $cc['ext_id'] !== '' && !in_array($cc['ext_id'], $incomingCatExt, true)) {
                if (in_array((int)$cc['id'], $protected, true)) continue;
                $deletes[] = ['type' => 'category', 'id' => (int)$cc['id']];
            }
        }
        foreach ($bmById as $bb) {
            if ($bb['source'] === 'browser' && $bb['ext_id'] !== '' && !in_array($bb['ext_id'], $incomingBmExt, true)) {
                $deletes[] = ['type' => 'bookmark', 'id' => (int)$bb['id']];
            }
        }
    }

    // 统计删除（仅统计尚未软删除的）
    foreach ($deletes as $d) {
        if ($d['type'] === 'category') {
            $row = $catById[$d['id']] ?? null;
            if ($row && empty($row['deleted_at'])) $summary['delete']['cat']++;
        } else {
            $row = $bmById[$d['id']] ?? null;
            if ($row && empty($row['deleted_at'])) $summary['delete']['bm']++;
        }
    }

    return ['summary' => $summary, 'actions' => $actions, 'deletes' => $deletes];
}

// ========== 应用差异（browser -> system 写库） ==========

/**
 * 执行 compute_sync_diff 产出的 actions / deletes。
 * 分类先建后解析真实 ID，再处理书签，保证新建文件夹的从属关系正确。
 * @return array 实际执行计数
 */
function apply_sync_diff($userId, $diff, $prefs) {
    $prefix = DB_PREFIX;
    $root = api_ensure_browser_root($userId);
    $rootId = (int)$root['id'];
    $rootExt = $root['ext_id'];
    $defaultPublic = setting('default_bookmark_public', '1') == '1' ? 1 : 0;
    $protected = !empty($prefs['protected_category_ids']) ? $prefs['protected_category_ids'] : [];

    $extToSysId = [$rootExt => $rootId, '' => $rootId];
    $applied = ['add' => ['cat' => 0, 'bm' => 0], 'update' => ['cat' => 0, 'bm' => 0], 'delete' => ['cat' => 0, 'bm' => 0]];

    DB::beginTransaction();
    try {
        // 1) 分类
        foreach ($diff['actions']['categories'] as $act) {
            if ($act['op'] === 'create') {
                if (!empty($act['deleted'])) continue; // 已删除的文件夹无需创建
                $id = DB::insert($prefix . 'categories', [
                    'user_id'    => $userId,
                    'parent_id'  => (int)$act['parent_id'],
                    'name'       => $act['name'],
                    'slug'       => make_slug($act['name']) ?: ('cat-' . substr(md5($act['ext']), 0, 8)),
                    'description'=> '',
                    'icon'       => '',
                    'sort'       => 0,
                    'is_public'  => $defaultPublic,
                    'password'   => '',
                    'source'     => 'browser',
                    'ext_id'     => $act['ext'],
                    'deleted_at' => null,
                ]);
                $extToSysId[$act['ext']] = (int)$id;
                $applied['add']['cat']++;
            } elseif ($act['op'] === 'update') {
                if (!empty($act['deleted'])) continue;
                DB::update($prefix . 'categories', $act['data'], 'id = ? AND user_id = ?', [(int)$act['id'], $userId]);
                $applied['update']['cat']++;
            }
        }

        // 2) 书签
        foreach ($diff['actions']['bookmarks'] as $act) {
            $catId = $extToSysId[$act['category_ext_id']] ?? $rootId;
            $catId = is_int($catId) ? $catId : $rootId;
            if ($act['op'] === 'create') {
                if (!empty($act['deleted'])) continue;
                DB::insert($prefix . 'bookmarks', [
                    'user_id'    => $userId,
                    'category_id'=> $catId,
                    'title'      => $act['title'],
                    'url'        => $act['url'],
                    'description'=> $act['description'],
                    'icon'       => $act['icon'],
                    'sort'       => 0,
                    'is_public'  => $defaultPublic,
                    'click_count'=> 0,
                    'domain'     => $act['domain'],
                    'source'     => 'browser',
                    'ext_id'     => $act['ext'],
                    'deleted_at' => null,
                ]);
                $applied['add']['bm']++;
            } elseif ($act['op'] === 'update') {
                if (!empty($act['deleted'])) continue;
                $data = $act['data'];
                // 仅当该记录原本未锚定（was_unlinked）且文件夹已解析为真实 ID 时，才归位到浏览器文件夹
                if (!empty($act['was_unlinked']) && $catId !== $rootId && isset($extToSysId[$act['category_ext_id']])) {
                    $data['category_id'] = $catId;
                }
                DB::update($prefix . 'bookmarks', $data, 'id = ? AND user_id = ?', [(int)$act['id'], $userId]);
                $applied['update']['bm']++;
            }
        }

        // 3) 删除（软删除）
        foreach ($diff['deletes'] as $d) {
            if ($d['type'] === 'category') {
                if (in_array((int)$d['id'], $protected, true)) continue;
                // 级联软删除后代分类与其中书签
                $ids = api_get_subtree_ids($userId, $d['id']);
                DB::execute("UPDATE {$prefix}categories SET deleted_at = ? WHERE id IN (" . implode(',', $ids) . ") AND user_id = ?", [date('Y-m-d H:i:s'), $userId]);
                DB::execute("UPDATE {$prefix}bookmarks SET deleted_at = ? WHERE category_id IN (" . implode(',', $ids) . ") AND user_id = ?", [date('Y-m-d H:i:s'), $userId]);
                $applied['delete']['cat']++;
            } else {
                DB::update($prefix . 'bookmarks', ['deleted_at' => date('Y-m-d H:i:s')], 'id = ? AND user_id = ?', [(int)$d['id'], $userId]);
                $applied['delete']['bm']++;
            }
        }

        DB::commit();
    } catch (Exception $e) {
        DB::rollBack();
        throw $e;
    }
    return $applied;
}

// ========== 回收站清理（pull 时顺带执行） ==========

function api_purge_trash($userId, $trashDays) {
    $prefix = DB_PREFIX;
    $cutoff = date('Y-m-d H:i:s', time() - (int)$trashDays * 86400);
    try {
        DB::execute("DELETE FROM {$prefix}bookmarks WHERE user_id = ? AND deleted_at IS NOT NULL AND deleted_at < ?", [$userId, $cutoff]);
        DB::execute("DELETE FROM {$prefix}categories WHERE user_id = ? AND deleted_at IS NOT NULL AND deleted_at < ?", [$userId, $cutoff]);
    } catch (Exception $e) {
        error_log('api_purge_trash failed: ' . $e->getMessage());
    }
}
