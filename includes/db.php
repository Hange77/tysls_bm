<?php
/**
 * 数据库操作类 (PDO 封装)
 * 所有查询均使用预处理语句,防止SQL注入
 */
class DB {
    private static $pdo = null;
    private static $prefix = '';

    /**
     * 初始化数据库连接
     */
    public static function init($host, $dbname, $user, $pass, $prefix = '') {
        if (self::$pdo !== null) return;
        self::$prefix = $prefix;
        $dsn = "mysql:host={$host};dbname={$dbname};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
        ]);
    }

    /**
     * 获取PDO实例
     */
    public static function pdo() {
        return self::$pdo;
    }

    /**
     * 执行查询(SELECT),返回PDOStatement
     */
    public static function query($sql, $params = []) {
        $stmt = self::$pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * 获取单行
     */
    public static function fetch($sql, $params = []) {
        return self::query($sql, $params)->fetch();
    }

    /**
     * 获取所有行
     */
    public static function fetchAll($sql, $params = []) {
        return self::query($sql, $params)->fetchAll();
    }

    /**
     * 获取单个值
     */
    public static function fetchColumn($sql, $params = []) {
        return self::query($sql, $params)->fetchColumn();
    }

    /**
     * 执行写入(INSERT/UPDATE/DELETE),返回受影响行数
     */
    public static function execute($sql, $params = []) {
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    /**
     * 插入数据,返回新ID
     */
    public static function insert($table, $data) {
        $fields = array_keys($data);
        $placeholders = array_map(function($f) { return ':' . $f; }, $fields);
        $sql = "INSERT INTO `{$table}` (`" . implode('`,`', $fields) . "`) VALUES (" . implode(',', $placeholders) . ")";
        self::query($sql, $data);
        return self::$pdo->lastInsertId();
    }

    /**
     * 更新数据
     */
    public static function update($table, $data, $where, $whereParams = []) {
        $sets = [];
        foreach (array_keys($data) as $field) {
            $sets[] = "`{$field}` = :set_{$field}";
        }
        $params = [];
        foreach ($data as $k => $v) {
            $params[':set_' . $k] = $v;
        }
        // 兼容位置占位符：SET 子句固定使用命名参数(:set_xxx)，
        // 若调用方 WHERE 使用位置占位符 «?»并传位置数组，
        // 直接合并会导致 PDO「mixed named and positional parameters」致命错误。
        // 故此处将 WHERE 中的 «?» 自动转换为命名占位符 :w0/:w1...
        if (self::hasPositionalPlaceholder($where)) {
            $i = 0;
            $where = preg_replace_callback('/\?/', function () use (&$i) {
                return ':w' . ($i++);
            }, $where);
            $idx = 0;
            foreach ($whereParams as $v) {
                $params[':w' . $idx] = $v;
                $idx++;
            }
        } else {
            foreach ($whereParams as $k => $v) {
                $params[$k] = $v;
            }
        }
        $sql = "UPDATE `{$table}` SET " . implode(', ', $sets) . " WHERE {$where}";
        return self::execute($sql, $params);
    }

    /**
     * 判断 WHERE 片段是否包含位置占位符 «?»（忽略字符串字面量中的问号场景较少，此处按需简单判定）
     */
    private static function hasPositionalPlaceholder($where) {
        return strpos($where, '?') !== false;
    }

    /**
     * 删除数据
     */
    public static function delete($table, $where, $params = []) {
        $sql = "DELETE FROM `{$table}` WHERE {$where}";
        return self::execute($sql, $params);
    }

    /**
     * 事务
     */
    public static function beginTransaction() { self::$pdo->beginTransaction(); }
    public static function commit() { self::$pdo->commit(); }
    public static function rollBack() { self::$pdo->rollBack(); }

    /**
     * 检查表是否存在
     */
    public static function tableExists($table) {
        $result = self::fetch("SHOW TABLES LIKE ?", [$table]);
        return !empty($result);
    }
}
