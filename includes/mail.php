<?php
/**
 * 邮件发送类 (SMTP 轻量实现, 无第三方依赖)
 * v1.3.9 新增: 支持邮箱验证 / 找回密码邮件
 *
 * 配置项(后台系统设置中维护, 存入 settings 表):
 *   mail_enabled   是否开启 (1/0)
 *   smtp_host      SMTP 服务器
 *   smtp_port      端口
 *   smtp_secure    加密方式: ssl | tls | none
 *   smtp_user      认证用户名
 *   smtp_pass      认证密码/授权码
 *   mail_from      发件人地址
 *   mail_from_name 发件人名称
 */
class Mailer {
    /**
     * 发送邮件
     * @param string $to 收件人邮箱
     * @param string $subject 主题
     * @param string $bodyHtml HTML 正文
     * @return array ['ok'=>bool, 'msg'=>string]
     */
    public static function send($to, $subject, $bodyHtml) {
        if (setting('mail_enabled', '0') !== '1') {
            return ['ok' => false, 'msg' => '邮件功能未开启(请在后台系统设置中配置 SMTP)'];
        }
        $host = trim(setting('smtp_host', ''));
        $port = (int)setting('smtp_port', 25);
        $secure = setting('smtp_secure', 'none'); // ssl | tls | none
        $user = setting('smtp_user', '');
        $pass = setting('smtp_pass', '');
        $from = trim(setting('mail_from', ''));
        $fromName = trim(setting('mail_from_name', ''));

        if (!$host || $port <= 0 || !$from) {
            return ['ok' => false, 'msg' => 'SMTP 配置不完整'];
        }
        if (!validate_email($to) || !validate_email($from)) {
            return ['ok' => false, 'msg' => '发件人或收件人邮箱格式不正确'];
        }
        // 防止邮件头注入: 关键字段不得包含换行
        if (preg_match('/[\r\n]/', $to) || preg_match('/[\r\n]/', $subject) || preg_match('/[\r\n]/', $from)) {
            return ['ok' => false, 'msg' => '参数包含非法字符'];
        }

        $scheme = ($secure === 'ssl') ? 'ssl' : 'tcp';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]]);
        $sock = @stream_socket_client($scheme . '://' . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$sock) {
            return ['ok' => false, 'msg' => "无法连接 SMTP 服务器: {$errstr}"];
        }
        stream_set_timeout($sock, 15);

        try {
            if (self::readCode($sock) !== 220) {
                throw new Exception('SMTP 握手失败');
            }

            // EHLO (STARTTLS 需要), 失败则退化为 HELO
            $ehloOk = false;
            self::cmd($sock, 'EHLO ' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'));
            if (self::readCode($sock) === 250) {
                $ehloOk = true;
            } elseif ($secure !== 'tls') {
                self::cmd($sock, 'HELO ' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'));
                $ehloOk = (self::readCode($sock) === 250);
            }
            if (!$ehloOk) {
                throw new Exception('SMTP 握手失败(EHLO/HELO)');
            }

            // STARTTLS
            if ($secure === 'tls') {
                self::cmd($sock, 'STARTTLS');
                if (self::readCode($sock) !== 220) {
                    throw new Exception('服务器不支持 STARTTLS');
                }
                if (!@stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception('TLS 加密协商失败');
                }
                self::cmd($sock, 'EHLO ' . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost'));
                if (self::readCode($sock) !== 250) {
                    throw new Exception('STARTTLS 后 EHLO 失败');
                }
            }

            // 认证(可选)
            if ($user !== '') {
                self::cmd($sock, 'AUTH LOGIN');
                if (self::readCode($sock) !== 334) {
                    throw new Exception('服务器不支持 AUTH LOGIN');
                }
                self::cmd($sock, base64_encode($user));
                if (self::readCode($sock) !== 334) {
                    throw new Exception('SMTP 用户名认证失败');
                }
                self::cmd($sock, base64_encode($pass));
                if (self::readCode($sock) !== 235) {
                    throw new Exception('SMTP 密码认证失败');
                }
            }

            // MAIL FROM
            self::cmd($sock, 'MAIL FROM:<' . $from . '>');
            if (self::readCode($sock) !== 250) {
                throw new Exception('MAIL FROM 失败');
            }
            // RCPT TO
            self::cmd($sock, 'RCPT TO:<' . $to . '>');
            if (self::readCode($sock) !== 250) {
                throw new Exception('RCPT TO 失败');
            }
            // DATA
            self::cmd($sock, 'DATA');
            if (self::readCode($sock) !== 354) {
                throw new Exception('DATA 指令失败');
            }

            $headers = self::buildHeaders($from, $fromName, $to, $subject);
            $body = self::encodeBody($bodyHtml);
            // 防止正文包含结束符 "\r\n.\r\n" 提前终止 DATA
            $body = str_replace("\r\n.\r\n", "\r\n..\r\n", $body);
            fwrite($sock, $headers . "\r\n" . $body . "\r\n.\r\n");
            if (self::readCode($sock) !== 250) {
                throw new Exception('邮件正文发送失败');
            }

            self::cmd($sock, 'QUIT');
        } catch (Exception $e) {
            @fclose($sock);
            return ['ok' => false, 'msg' => $e->getMessage()];
        }
        @fclose($sock);
        return ['ok' => true, 'msg' => '发送成功'];
    }

    private static function cmd($sock, $line) {
        fwrite($sock, $line . "\r\n");
    }

    /**
     * 读取 SMTP 响应, 返回三位状态码(多行响应取最后一行)
     */
    private static function readCode($sock) {
        $code = null;
        while (true) {
            $line = @fgets($sock, 515);
            if ($line === false) {
                break;
            }
            if (preg_match('/^(\d{3})(?:[- ])/', $line, $m)) {
                $code = (int)$m[1];
                // 行内第 4 个字符为空格表示响应结束(否则为续行)
                if (isset($line[3]) && ($line[3] === ' ' || $line[3] === "\t")) {
                    break;
                }
            }
        }
        return $code;
    }

    private static function buildHeaders($from, $fromName, $to, $subject) {
        $eFromName = $fromName !== '' ? '=?UTF-8?B?' . base64_encode($fromName) . '?= ' : '';
        $eSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
        $h = [];
        $h[] = 'From: ' . $eFromName . '<' . $from . '>';
        $h[] = 'Reply-To: ' . $from;
        $h[] = 'To: ' . $to;
        $h[] = 'Subject: ' . $eSubject;
        $h[] = 'Date: ' . date('r');
        $h[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $host . '>';
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'Content-Type: text/html; charset=UTF-8';
        $h[] = 'Content-Transfer-Encoding: base64';
        return implode("\r\n", $h);
    }

    private static function encodeBody($html) {
        return chunk_split(base64_encode($html), 76, "\r\n");
    }

    /**
     * 邮箱验证邮件模板
     */
    public static function verifyTemplate($siteName, $verifyUrl) {
        return self::wrap($siteName, '请验证您的邮箱', '
          <p>感谢您注册 ' . e($siteName) . '！</p>
          <p>请点击下面的按钮完成邮箱验证：</p>
          <p style="text-align:center;margin:28px 0;">
            <a href="' . e($verifyUrl) . '" style="background:#4a90d9;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;">验证邮箱</a>
          </p>
          <p>或复制以下链接到浏览器打开：<br><a href="' . e($verifyUrl) . '">' . e($verifyUrl) . '</a></p>
          <p style="color:#999;font-size:12px;">链接 24 小时内有效。若非本人操作,请忽略此邮件。</p>');
    }

    /**
     * 找回密码邮件模板
     */
    public static function resetTemplate($siteName, $resetUrl) {
        return self::wrap($siteName, '重置您的密码', '
          <p>您好,我们收到了重置 ' . e($siteName) . ' 账号密码的请求。</p>
          <p>请点击下面的按钮设置新密码：</p>
          <p style="text-align:center;margin:28px 0;">
            <a href="' . e($resetUrl) . '" style="background:#4a90d9;color:#fff;padding:12px 28px;border-radius:6px;text-decoration:none;display:inline-block;font-weight:600;">重置密码</a>
          </p>
          <p>或复制以下链接到浏览器打开：<br><a href="' . e($resetUrl) . '">' . e($resetUrl) . '</a></p>
          <p style="color:#999;font-size:12px;">链接 30 分钟内有效。若非本人操作,请忽略此邮件并尽快修改密码。</p>');
    }

    /**
     * 通用邮件外壳
     */
    public static function wrap($siteName, $title, $inner) {
        return '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>' . e($title) . '</title></head>
        <body style="margin:0;background:#f5f7fa;padding:24px;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'Helvetica Neue\',Arial,sans-serif;color:#333;">
        <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:10px;padding:32px;box-shadow:0 2px 12px rgba(0,0,0,.06);">
        <h2 style="color:#4a90d9;margin-top:0;">🔖 ' . e($siteName) . '</h2>
        <div style="font-size:15px;line-height:1.7;">' . $inner . '</div>
        <hr style="border:none;border-top:1px solid #eee;margin:24px 0;">
        <p style="color:#999;font-size:12px;margin:0;">此邮件由系统自动发送,请勿直接回复。</p>
        </div></body></html>';
    }
}
