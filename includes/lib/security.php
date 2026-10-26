<?php
/**
 * 通用辅助函数 - security
 *
 * 安全辅助：频率限制（防暴力/邮件轰炸）/ 令牌用户查找 / 域名提取。
 */

// ========== v1.3.9 频率限制(防暴力破解 / 邮件轰炸) ==========
// 基于 客户端IP + 业务key 的轻量限频, 数据落地到系统临时目录(不依赖项目可写目录, 也不改库表)
// 说明: 会话级限频可被清 cookie / 换浏览器绕过, 故改用 IP 维度; 多用户共享主机下 IP 哈希可能被同机其他应用干扰, 但风险可接受

/**
 * 限频计数文件(按 IP + key)
 */
function _ratelimit_file($key) {
    $ip = get_client_ip();
    $safe = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $ip);
    $dir = rtrim(sys_get_temp_dir(), '/');
    return $dir . '/.bm_rl_' . md5($key . '|' . $safe);
}

/**
 * 读取某 key 在时间窗口内的命中时间戳(自动清理过期)
 */
function _ratelimit_hits($key, $window) {
    $file = _ratelimit_file($key);
    $hits = [];
    if (@file_exists($file)) {
        $data = @file_get_contents($file);
        if ($data) { $dec = json_decode($data, true); if (is_array($dec)) $hits = $dec; }
    }
    $now = time();
    $hits = array_values(array_filter($hits, function ($t) use ($now, $window) {
        return $t > $now - $window;
    }));
    return $hits;
}

// ========== v1.3.9.3 书签增强: 域名提取(用于聚合筛选/查重) ==========

/**
 * 从 URL 提取域名(小写, 去 www. 前缀), 用于聚合筛选与重复检测
 */
function extract_domain($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return '';
    $host = strtolower($host);
    if (strpos($host, 'www.') === 0) $host = substr($host, 4);
    return $host;
}

/**
 * 检查是否超限
 * @return array ['limited'=>bool, 'retry_after'=>int(秒)]
 */
function rate_limit_check($key, $max, $window) {
    $hits = _ratelimit_hits($key, $window);
    if (count($hits) < $max) {
        return ['limited' => false, 'retry_after' => 0];
    }
    // 最早一条 + 窗口 = 解除时间
    $retryAfter = (int)($hits[0] + $window - time());
    if ($retryAfter < 0) $retryAfter = 0;
    return ['limited' => true, 'retry_after' => $retryAfter];
}

/**
 * 记录一次命中
 */
function rate_limit_hit($key) {
    $file = _ratelimit_file($key);
    $hits = _ratelimit_hits($key, 3600); // 仅保留近 1 小时
    $hits[] = time();
    @file_put_contents($file, json_encode($hits), LOCK_EX);
}

/**
 * 清空某 key 的计数(登录成功后调用, 重置锁定)
 */
function rate_limit_reset($key) {
    $file = _ratelimit_file($key);
    if (@file_exists($file)) @unlink($file);
}

/**
 * 通过找回密码令牌查找用户
 */
function user_by_reset_token($token) {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $rows = DB::fetchAll("SELECT * FROM " . DB_PREFIX . "users WHERE reset_token != '' AND reset_expire > ?", [time()]);
    foreach ($rows as $u) {
        if (password_verify($token, $u['reset_token'])) return $u;
    }
    return null;
}

/**
 * 通过邮箱验证令牌查找用户(令牌以 password_hash 存储)
 */
function user_by_verify_token($token) {
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $rows = DB::fetchAll("SELECT * FROM " . DB_PREFIX . "users WHERE verify_token != '' AND verify_expire > ?", [time()]);
    foreach ($rows as $u) {
        if (password_verify($token, $u['verify_token'])) return $u;
    }
    return null;
}

