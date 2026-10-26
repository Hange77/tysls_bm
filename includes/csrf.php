<?php
/**
 * CSRF 防护
 */
class CSRF {
    const TOKEN_KEY = '_csrf_token';

    /**
     * 生成/获取CSRF Token
     */
    public static function token() {
        if (empty($_SESSION[self::TOKEN_KEY])) {
            $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::TOKEN_KEY];
    }

    /**
     * 输出隐藏的token input字段
     */
    public static function field() {
        return '<input type="hidden" name="' . self::TOKEN_KEY . '" value="' . e(self::token()) . '">';
    }

    /**
     * 验证CSRF Token
     */
    public static function verify($token = null) {
        if ($token === null) {
            $token = $_POST[self::TOKEN_KEY] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        }
        if (empty($_SESSION[self::TOKEN_KEY]) || empty($token)) {
            return false;
        }
        // 常量时间比较
        return hash_equals($_SESSION[self::TOKEN_KEY], $token);
    }

    /**
     * 验证并在失败时终止
     */
    public static function check() {
        if (!self::verify()) {
            http_response_code(419);
            echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>验证失败</title>';
            echo '<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0;background:#f5f5f5;}';
            echo '.box{text-align:center;}h1{color:#d9534f;}p{color:#666;}a{color:#4a90d9;}</style></head>';
            echo '<body><div class="box"><h1>请求验证失败</h1><p>页面已过期,请刷新后重试。</p><p><a href="javascript:history.back()">返回</a></p></div></body></html>';
            exit;
        }
    }
}
