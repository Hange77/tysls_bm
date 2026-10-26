<?php
/**
 * 用户认证类
 */
class Auth {
    private static $user = null;
    private static $initialized = false;

    /**
     * 初始化(从session恢复登录态)
     */
    public static function init() {
        if (self::$initialized) return;
        self::$initialized = true;
        if (!empty($_SESSION['user_id'])) {
            $user = DB::fetch(
                "SELECT * FROM " . DB_PREFIX . "users WHERE id = ? AND is_active = 1",
                [$_SESSION['user_id']]
            );
            if ($user) {
                self::$user = $user;
            } else {
                // 用户被禁用或不存在,清除会话
                self::logout(false);
            }
        }
    }

    /**
     * 当前登录用户
     */
    public static function user() {
        return self::$user;
    }

    /**
     * 是否已登录
     */
    public static function check() {
        return self::$user !== null;
    }

    /**
     * 是否为管理员
     */
    public static function isAdmin() {
        return self::$user && self::$user['role'] === 'admin';
    }

    /**
     * 是否为超级管理员(id=1)
     */
    public static function isSuperAdmin() {
        return self::$user && (int)self::$user['id'] === 1;
    }

    /**
     * 用户ID
     */
    public static function id() {
        return self::$user ? (int)self::$user['id'] : 0;
    }

    /**
     * 登录
     */
    public static function login($username, $password) {
        // 频率限制(防暴力破解): IP + 账号 双维度, 落地到临时目录, 不依赖会话
        $ipKey = 'login_ip_' . get_client_ip();
        $userKey = 'login_user_' . $username;
        $ipRl = rate_limit_check($ipKey, 20, 600);   // 同 IP 10 分钟最多 20 次失败
        $userRl = rate_limit_check($userKey, 5, 900); // 同账号 15 分钟最多 5 次失败
        if ($ipRl['limited'] || $userRl['limited']) {
            return 'locked';
        }

        $user = DB::fetch(
            "SELECT * FROM " . DB_PREFIX . "users WHERE username = ? OR email = ?",
            [$username, $username]
        );
        if (!$user || !safe_password_verify($password, $user['password'])) {
            rate_limit_hit($ipKey);
            rate_limit_hit($userKey);
            return false;
        }
        if (!$user['is_active']) {
            return 'disabled';
        }
        // 登录成功, 重置该 IP / 账号的失败计数
        rate_limit_reset($ipKey);
        rate_limit_reset($userKey);
        // 登录成功,更新登录信息
        DB::update(DB_PREFIX . 'users', [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => get_client_ip(),
        ], 'id = :id', [':id' => $user['id']]);

        // 防止会话固定攻击
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        self::$user = $user;

        log_operation('login', 'user', $user['id'], '用户登录');
        return true;
    }

    /**
     * 注册
     */
    public static function register($username, $email, $password) {
        // 检查用户名是否已存在
        $exists = DB::fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "users WHERE username = ? OR email = ?",
            [$username, $email]
        );
        if ($exists) return 'exists';

        $userId = DB::insert(DB_PREFIX . 'users', [
            'username' => $username,
            'email'    => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'nickname' => $username,
            'role'     => 'user',
            'is_active'=> 1,
        ]);

        log_operation('register', 'user', $userId, '用户注册');
        return $userId;
    }

    /**
     * 登出
     */
    public static function logout($log = true) {
        if ($log && self::$user) {
            log_operation('logout', 'user', self::$user['id'], '用户登出');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        self::$user = null;
    }

    /**
     * 要求登录,否则跳转登录页
     */
    public static function requireLogin() {
        if (!self::check()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? url('');
            redirect(url('login.php'));
        }
    }

    /**
     * 要求管理员权限
     */
    public static function requireAdmin() {
        self::requireLogin();
        if (!self::isAdmin()) {
            show_403('需要管理员权限');
        }
    }

    /**
     * 要求超级管理员权限
     */
    public static function requireSuperAdmin() {
        self::requireLogin();
        if (!self::isSuperAdmin()) {
            show_403('需要超级管理员权限');
        }
    }

    /**
     * 刷新当前用户信息(修改后调用)
     */
    public static function refresh() {
        if (self::$user) {
            self::$user = DB::fetch(
                "SELECT * FROM " . DB_PREFIX . "users WHERE id = ?",
                [self::$user['id']]
            );
        }
    }
}
