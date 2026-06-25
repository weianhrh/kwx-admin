<?php
class Database {
    protected $connection;
    protected $host = "localhost";
    protected $user = "5grc";
    protected $password = "f6eca7806e73cd25";
    protected $database = "5grc";

    public function __construct() {
        $this->connection = new mysqli($this->host, $this->user, $this->password, $this->database);

        if ($this->connection->connect_error) {
            die("连接失败: " . $this->connection->connect_error);
        }
    }
     public function isValidToken($token) {
        $sql = "SELECT uid FROM admin_users WHERE session_token = ? AND session_expires > NOW()";
        return $this->query($sql, [$token]);
    }
     // 开始事务
    public function beginTransaction() {
        $this->connection->begin_transaction();
    }

    // 提交事务
    public function commit() {
        $this->connection->commit();
    }

    // 回滚事务
    public function rollBack() {
        $this->connection->rollback();
    }
    public function getUserBySessionToken($token) {
        $stmt = $this->prepare("SELECT * FROM admin_users WHERE session_token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            return $row;
        } else {
            return null;
        }
    }
    public function prepare($query) {
        $stmt = $this->connection->prepare($query);
        if ($stmt === false) {
            throw new Exception("Prepare statement failed: " . $this->connection->error);
        }
        return $stmt;
    }

    public function query($sql, $params = [], $isUpdate = false) {
        $stmt = $this->connection->prepare($sql);
        if (!$stmt) {
            error_log("预处理SQL语句失败: " . $this->connection->error);
        // 输出 SQL 语句和错误信息
        // echo "SQL 错误：" . $e->getMessage() . "<br>";
        // echo "SQL 语句：" . $sql . "<br>";
        // var_dump($params); // 打印参数
            return false;
        }

        if ($params) {
            $types = str_repeat("s", count($params)); // 假设所有参数都是字符串类型
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            error_log("执行SQL语句失败: " . $stmt->error);
            $stmt->close();
            return false;
        }

        if ($isUpdate) {
            $affectedRows = $stmt->affected_rows;
            error_log("SQL写操作完成，受影响行数: $affectedRows");
            $stmt->close();
            return $affectedRows;
        } else {
            $result = $stmt->get_result();
            if ($result === false) {
                error_log("获取结果集失败: " . $stmt->error);
                $stmt->close();
                return false;
            }

            $data = [];
            while ($row = $result->fetch_assoc()) {
                $data[] = $row;
            }
            $stmt->close();
            return $data;
        }
    }

    public function escape($value) {
        return $this->connection->real_escape_string($value);
    }

    public function close() {
        $this->connection->close();
    }

    public function getConnection() {
        return $this->connection;
    }
    // public function logToFile($message, $logFile = 'log/monitoring.log')  { 
    //         // 获取日志文件所在的目录 
    //         $logDir = dirname($logFile); 
         
    //         // 检查日志目录是否存在，如果不存在则创建它 
    //         if (!file_exists($logDir)) { 
    //             if (!mkdir($logDir, 0755, true)) { 
    //                 // 处理创建目录失败的情况 
    //                 trigger_error("Failed to create log directory: $logDir", E_USER_WARNING); 
    //                 return false; 
    //             } 
    //         } 
         
    //         // 生成时间戳 
    //         $timestamp = date('Y-m-d H:i:s'); 
            
    //         // 组合日志信息 
    //         $logMessage = "[$timestamp] $message" . PHP_EOL; 
         
    //         // 将日志信息写入文件 
    //         if (file_put_contents($logFile, $logMessage, FILE_APPEND) === false) { 
    //             // 处理写入文件失败的情况 
    //             trigger_error("Failed to write to log file: $logFile", E_USER_WARNING); 
    //             return false; 
    //         } 
         
    //         return true; 
    //     } 
    public function logToFile($message, $logFile = null) {
    // Database.php 在 /api/lib/Database.php
    // dirname(__DIR__) 就是 /api
    $apiRoot = dirname(__DIR__);

    // 默认固定写到 /www/wwwroot/kwx.rcxxzk.cn/api/log/monitoring.log
    if (empty($logFile) || $logFile === 'log/monitoring.log') {
        $logFile = $apiRoot . '/log/monitoring.log';
    } else {
        // 如果传入的不是绝对路径，也统一转成 api 目录下的路径
        $logFile = str_replace('\\', '/', $logFile);
        if ($logFile[0] !== '/') {
            $logFile = $apiRoot . '/' . ltrim($logFile, '/');
        }
    }

    $logDir = dirname($logFile);

    // 创建目录，失败也不要输出 Warning
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    // 目录不可写时，直接返回 false，不要 trigger_error
    if (!is_dir($logDir) || !is_writable($logDir)) {
        return false;
    }

    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[$timestamp] $message" . PHP_EOL;

    // 写失败也静默返回 false，避免污染 JSON
    return @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX) !== false;
}

}


