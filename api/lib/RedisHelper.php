<?php
class RedisHelper {
    private $redis;
    private $isConnected = false;

    public function __construct() {
        $this->redis = new Redis();
    }

    public function connect($host = '127.0.0.1', $port = 6379, $timeout = 0) {
        try {
            $this->isConnected = $this->redis->connect($host, $port, $timeout);
            if (!$this->isConnected) {
                throw new Exception("无法连接到Redis服务器：$host:$port");
            }
        } catch (RedisException $e) {
            // 可以在这里记录日志
            throw new Exception("连接Redis服务器异常：" . $e->getMessage());
        }
    }
    public function getAllKeys($pattern = '*') {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            return $this->redis->keys($pattern);
        } catch (RedisException $e) {
            throw new Exception("获取Redis键时出现异常：" . $e->getMessage());
        }
    }
    public function exists($key) {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            return $this->redis->exists($key) > 0;
        } catch (RedisException $e) {
            throw new Exception("检查 Redis 键是否存在时发生异常：" . $e->getMessage());
        }
    }


    public function selectDb($dbIndex) {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            $this->redis->select($dbIndex);
        } catch (RedisException $e) {
            // 处理异常
            throw new Exception("选择Redis数据库时出现异常：" . $e->getMessage());
        }
    }

    public function save($key, $value, $ttl = 0) {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            if ($ttl > 0) {
                $this->redis->setex($key, $ttl, $value);
            } else {
                $this->redis->set($key, $value);
            }
        } catch (RedisException $e) {
            throw new Exception("保存数据到Redis时出现异常：" . $e->getMessage());
        }
    }

    public function get($key) {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            return $this->redis->get($key);
        } catch (RedisException $e) {
            throw new Exception("从Redis获取数据时出现异常：" . $e->getMessage());
        }
    }
    public function getNative() {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        return $this->redis;
    }
    public function ttl($key) {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            return $this->redis->ttl($key);
        } catch (RedisException $e) {
            throw new Exception("获取键 TTL 时发生异常：" . $e->getMessage());
        }
    }

    public function delete($key) {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            return $this->redis->del($key);
        } catch (RedisException $e) {
            throw new Exception("删除 Redis 键时发生异常：" . $e->getMessage());
        }
    }
    public function hMSet($key, array $assocArray) {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            // 所有值强制转为字符串，确保 Python decode_responses=True 能正确解析
            $stringified = array_map(function ($v) {
                return is_scalar($v) ? strval($v) : json_encode($v, JSON_UNESCAPED_UNICODE);
            }, $assocArray);
    
            return $this->redis->hMSet($key, $stringified);
        } catch (RedisException $e) {
            throw new Exception("设置 Redis 哈希字段时发生异常：" . $e->getMessage());
        }
    }
    public function set($key, $value, $expire = 0) {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            $result = $this->redis->set($key, $value);
            if ($expire > 0) {
                $this->redis->expire($key, $expire);
            }
            return $result;
        } catch (RedisException $e) {
            throw new Exception("设置Redis键值失败：" . $e->getMessage());
        }
    }
    public function scan($pattern = '*', $count = 100) {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            $it = NULL;
            $keys = [];
            while ($arr = $this->redis->scan($it, $pattern, $count)) {
                foreach ($arr as $k) $keys[] = $k;
            }
            return $keys;
        } catch (RedisException $e) {
            throw new Exception("SCAN 时发生异常：" . $e->getMessage());
        }
    }
    
        /**
     * 把 Redis 里保存的 \u4f60\u597d 这种字符串，转成正常 UTF-8 中文
     */
    public static function unicodeToUtf8($str)
    {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
            $code = hexdec($matches[1]);
            // UCS-2BE -> UTF-8
            return mb_convert_encoding(pack('n', $code), 'UTF-8', 'UCS-2BE');
        }, $str);
    }

    /**
     * 把带中文的字符串转成 \uXXXX 形式，便于按你现在 Redis 的格式存
     */
    public static function utf8ToUnicode($str)
    {
        $result = '';
        $len = mb_strlen($str, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($str, $i, 1, 'UTF-8');
            $code = unpack('n', mb_convert_encoding($char, 'UCS-2BE', 'UTF-8'))[1];
            if ($code < 0x80) {
                // ASCII 直接原样
                $result .= $char;
            } else {
                $result .= sprintf('\\u%04x', $code);
            }
        }
        return $result;
    }

    public function expire($key, $seconds) {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            return $this->redis->expire($key, $seconds);
        } catch (RedisException $e) {
            throw new Exception("设置键过期时间失败：" . $e->getMessage());
        }
    }


    public function close() {
        if ($this->isConnected) {
            $this->redis->close();
            $this->isConnected = false;
        }
    }
    public function setWithExpiration($key, $value, $expireTime) {
        if (!$this->isConnected) {
            throw new Exception("Redis服务器未连接。");
        }
        try {
            // 设置键值并指定过期时间
            $this->redis->setex($key, $expireTime, $value);
        } catch (RedisException $e) {
            throw new Exception("设置键的过期时间时发生异常：" . $e->getMessage());
        }
    }

}


?>
