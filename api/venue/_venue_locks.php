<?php
require_once '../RedisHelper.php';
// /api/venue/_venue_locks.php
class VenueLocks {
    const DB_INDEX = 6;                   // 使用 DB4
    const LOCK_SEC = 1296000; //864000;              // 10 天
    private $r;

    public function __construct() {
        $this->r = new RedisHelper();
        $this->r->connect();
        $this->r->selectDb(self::DB_INDEX);
    }

    private function key($type, $venue_id) {
        // $type: 'name' | 'image'
        return "venue_{$type}_lock:{$venue_id}";
    }

    public function get($type, $venue_id) {
        $k = $this->key($type, $venue_id);
        $raw = $this->r->get($k);
        if ($raw === false || $raw === null) return null;

        $info = json_decode($raw, true) ?: [];
        // 补充 TTL 和 until_ts/iso（保险起见）
        $ttl = $this->r->getNative()->ttl($k);
        $info['ttl'] = $ttl;
        if ($ttl > 0) {
            $info['until_ts']  = time() + $ttl;
            $info['until_iso'] = date('Y-m-d H:i:s', $info['until_ts']);
        }
        return $info;
    }

    public function isLocked($type, $venue_id) {
        return $this->get($type, $venue_id) !== null;
    }

    public function set($type, $venue_id, $reason = '', $by_uid = 0) {
        $k = $this->key($type, $venue_id);
        $until_ts = time() + self::LOCK_SEC;
        $payload = [
            'venue_id'   => (int)$venue_id,
            'type'       => $type,
            'set_ts'     => time(),
            'until_ts'   => $until_ts,
            'until_iso'  => date('Y-m-d H:i:s', $until_ts),
            'reason'     => $reason,
            'by_uid'     => (int)$by_uid,
        ];
        $this->r->save($k, json_encode($payload, JSON_UNESCAPED_UNICODE), self::LOCK_SEC);
        return $payload;
    }

    public function clear($type, $venue_id) {
        $this->r->getNative()->del($this->key($type, $venue_id));
    }
}
