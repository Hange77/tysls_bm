<?php
/**
 * 通用辅助函数 - util
 *
 * 基础工具函数：HTML 转义 / URL 生成 / 重定向 / 404-403 / JSON 响应 /
 * 输入校验 / slug / 截断 / 分页 / 闪存消息 / 令牌 / 操作日志 / 文本渲染。
 */

// ========== v1.3.9 邮件与令牌辅助 ==========

/**
 * 生成绝对 URL (用于邮件链接)
 * 优先使用后台配置的 site_url, 避免依赖可被伪造的 Host 头
 */
function abs_url($path = '') {
    $siteUrl = trim(setting('site_url', ''));
    if ($siteUrl) {
        // 仅取 scheme://host[:port], 路径部分交由 url() 生成(其已含子目录 BASE_PATH)
        // 避免子目录安装时 site_url 本身已带子目录, 再与 url() 叠加导致双重子目录(邮件链接 404)
        $p = parse_url($siteUrl);
        $base = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? 'localhost');
        if (!empty($p['port'])) {
            $base .= ':' . $p['port'];
        }
        return $base . url($path);
    }
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on')
        ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return $proto . '://' . $host . url($path);
}

/**
 * 生成静态资源URL
 */
function asset($path) {
    return url('assets/' . ltrim($path, '/'));
}

/**
 * 通用辅助函数
 */

/**
 * HTML转义输出,防止XSS
 */
function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/**
 * 获取客户端IP(兼容代理)
 */
function get_client_ip() {
    $ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
        $ip = $_SERVER['HTTP_X_REAL_IP'];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    // 验证IP格式
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function get_flash() {
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

/**
 * 检查是否为合法的内容类型
 */
function is_valid_content_type($type) {
    return in_array($type, ['html', 'text'], true);
}

/**
 * JSON响应
 */
function json_response($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 记录操作日志
 */
function log_operation($action, $targetType = '', $targetId = 0, $detail = '') {
    $user = Auth::user();
    $userId = $user ? $user['id'] : 0;
    $username = $user ? $user['username'] : 'guest';
    $ip = get_client_ip();
    $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    $detail = is_array($detail) ? json_encode($detail, JSON_UNESCAPED_UNICODE) : (string)$detail;

    try {
        DB::insert(DB_PREFIX . 'operation_logs', [
            'user_id'     => $userId,
            'username'    => $username,
            'action'      => $action,
            'target_type' => $targetType,
            'target_id'   => $targetId,
            'detail'      => substr($detail, 0, 1000),
            'ip'          => $ip,
            'user_agent'  => $ua,
        ]);
    } catch (Exception $e) {
        // 日志写入失败不影响主流程
        error_log('log_operation failed: ' . $e->getMessage());
    }
}

/**
 * 生成slug(URL友好)
 */
function make_slug($str) {
    $str = strtolower(trim($str));
    $str = preg_replace('/[^a-z0-9\-]/', '-', $str);
    $str = preg_replace('/-+/', '-', $str);
    $str = trim($str, '-');
    // 避免纯数字slug与ID冲突，纯数字时添加前缀
    if ($str !== '' && ctype_digit($str)) {
        $str = 'page-' . $str;
    }
    return $str;
}

/**
 * 生成高熵令牌(64 位十六进制)
 */
function new_token() {
    return bin2hex(random_bytes(32));
}

/**
 * 分页计算
 */
function paginate($total, $page, $perPage = 20) {
    $totalPages = max(1, ceil($total / $perPage));
    $page = max(1, min($page, $totalPages));
    $offset = ($page - 1) * $perPage;
    return [
        'page'        => $page,
        'per_page'    => $perPage,
        'total'       => $total,
        'total_pages' => $totalPages,
        'offset'      => $offset,
    ];
}

/**
 * 重定向
 */
function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * 渲染分页HTML
 */
function render_pagination($pagination, $baseUrl) {
    if ($pagination['total_pages'] <= 1) return '';
    $html = '<div class="pagination">';
    $sep = strpos($baseUrl, '?') !== false ? '&' : '?';
    if ($pagination['page'] > 1) {
        $html .= '<a href="' . e($baseUrl . $sep . 'page=' . ($pagination['page'] - 1)) . '">&laquo; 上一页</a>';
    }
    $html .= '<span class="current">第 ' . $pagination['page'] . ' / ' . $pagination['total_pages'] . ' 页</span>';
    if ($pagination['page'] < $pagination['total_pages']) {
        $html .= '<a href="' . e($baseUrl . $sep . 'page=' . ($pagination['page'] + 1)) . '">下一页 &raquo;</a>';
    }
    $html .= '</div>';
    return $html;
}

/**
 * 渲染「每页 N 条」设置控件(供列表顶部/底部复用)
 * $keepParams: 需保留在 URL 中的当前筛选/搜索参数(不含 per_page)
 * $current:    当前每页数量
 */
function render_perpage_selector($keepParams, $current) {
    $opts = [10, 20, 50, 100];
    $html = '<form method="get" class="per-page-form">';
    foreach ((array)$keepParams as $k => $v) {
        if ($k === 'per_page') continue;
        $html .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
    }
    $html .= '<label class="per-page-label">每页 <select name="per_page" class="form-control form-control-sm" onchange="this.form.submit()" style="width:auto;display:inline-block;">';
    foreach ($opts as $o) {
        $html .= '<option value="' . $o . '"' . ((int)$o === (int)$current ? ' selected' : '') . '>' . $o . '</option>';
    }
    $html .= '</select> 条</label></form>';
    return $html;
}

/**
 * 纯文本内容渲染(换行转br,自动链接)
 */
function render_text_content($text) {
    $text = e($text);
    $text = nl2br($text);
    // 自动链接URL
    $text = preg_replace(
        '/(https?:\/\/[^\s<]+)/',
        '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
        $text
    );
    return $text;
}

/**
 * 安全的密码验证(常量时间比较)
 */
function safe_password_verify($password, $hash) {
    if (empty($hash)) return false;
    return password_verify($password, $hash);
}

/**
 * 闪存消息(重定向后跨请求提示)
 */
function set_flash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

/**
 * 403
 */
function show_403($message = '没有权限访问') {
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>403</title>';
    echo '<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f5f5f5;}';
    echo '.box{text-align:center;}h1{font-size:72px;color:#333;margin:0;}p{color:#666;}a{color:#4a90d9;text-decoration:none;}</style></head>';
    echo '<body><div class="box"><h1>403</h1><p>' . e($message) . '</p><p><a href="' . url('') . '">返回首页</a></p></div></body></html>';
    exit;
}

/**
 * 404
 */
function show_404($message = '页面不存在') {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>404</title>';
    echo '<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f5f5f5;}';
    echo '.box{text-align:center;}h1{font-size:72px;color:#333;margin:0;}p{color:#666;}a{color:#4a90d9;text-decoration:none;}</style></head>';
    echo '<body><div class="box"><h1>404</h1><p>' . e($message) . '</p><p><a href="' . url('') . '">返回首页</a></p></div></body></html>';
    exit;
}

/**
 * 截断文本
 */
function truncate($str, $len = 100, $suffix = '...') {
    $str = strip_tags($str);
    if (mb_strlen($str, 'UTF-8') <= $len) return $str;
    return mb_substr($str, 0, $len, 'UTF-8') . $suffix;
}

/**
 * 生成URL(带基础路径)
 */
/**
 * 生成URL(基于应用根路径BASE_PATH,支持子目录安装)
 */
function url($path = '') {
    if (strpos($path, 'http') === 0) return $path;
    $base = defined('BASE_PATH') ? BASE_PATH : '';
    return $base . '/' . ltrim($path, '/');
}

/**
 * 验证邮箱
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * 验证URL
 */
function validate_url($url) {
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

/**
 * 验证用户名格式(3-20位,字母数字下划线,必须字母开头)
 */
function validate_username($username) {
    return preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,19}$/', $username);
}

