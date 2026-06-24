<?php
require_once '../Database.php';
require_once '../RedisHelper.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// =============================
// 基础配置
// =============================

$SYSTEM_UNBAN_BY = 0;
$AUTO_UNBAN_REASON = "场地已到达封禁时间,已自动解封";

function logMessage($message) {
    $logFile = __DIR__ . '/auto_unban.log';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($logFile, "[$timestamp] $message\n", FILE_APPEND);
}

function isExpiredTime($timeText) {
    if (empty($timeText)) {
        return false;
    }

    $timestamp = strtotime($timeText);
    if ($timestamp === false) {
        return false;
    }

    return $timestamp <= time();
}

function bindStmtParams($stmt, $types, $params) {
    if ($types === '' || empty($params)) {
        return;
    }

    $bindParams = [];
    $bindParams[] = $types;

    foreach ($params as $key => $value) {
        $bindParams[] = &$params[$key];
    }

    call_user_func_array([$stmt, 'bind_param'], $bindParams);
}

function fetchOne($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    bindStmtParams($stmt, $types, $params);
    $stmt->execute();

    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    $stmt->close();

    return $row ?: null;
}

function fetchAllRows($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    bindStmtParams($stmt, $types, $params);
    $stmt->execute();

    $result = $stmt->get_result();
    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();

    return $rows;
}

function executeSql($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    bindStmtParams($stmt, $types, $params);
    $stmt->execute();

    $affectedRows = $stmt->affected_rows;
    $stmt->close();

    return $affectedRows;
}

function redisKeySuffix($key, $prefix) {
    if (strpos($key, $prefix) === 0) {
        return substr($key, strlen($prefix));
    }
    return '';
}

function safeDeleteRedisKey($redisHelper, $key) {
    try {
        $redisHelper->delete($key);
    } catch (Exception $e) {
        logMessage("⚠️ 删除 Redis Key 失败: {$key}, err=" . $e->getMessage());
    }
}

// =============================
// 设备解封：共用方法
// =============================

function unbanDeviceBySerial($conn, $redisHelper, $serialNumber, $source = '', $redisKey = '') {
    $serialNumber = trim((string)$serialNumber);

    if ($serialNumber === '') {
        return false;
    }

    $conn->begin_transaction();

    try {
        // 找最新一条正在生效的封禁记录
        $banRow = fetchOne(
            $conn,
            "SELECT id, ban_end_time 
             FROM device_bans 
             WHERE serial_number = ? AND status = 1 
             ORDER BY id DESC 
             LIMIT 1",
            "s",
            [$serialNumber]
        );

        // 有生效封禁记录，但数据库封禁时间还没到：不解封
        if ($banRow && !isExpiredTime($banRow['ban_end_time'])) {
            $conn->rollback();

            logMessage("⏳ 设备未到解封时间，跳过: {$serialNumber}, ban_end_time={$banRow['ban_end_time']}, source={$source}");
            return false;
        }

        $banId = $banRow ? (int)$banRow['id'] : 0;

        // 设备恢复共享状态
        executeSql(
            $conn,
            "UPDATE vehicles 
             SET is_banned = 0,
                 sharing_status = '正在共享',
                 start_status = 'true'
             WHERE serial_number = ?",
            "s",
            [$serialNumber]
        );

        // 有封禁记录才更新封禁记录
        if ($banId > 0) {
            executeSql(
                $conn,
                "UPDATE device_bans 
                 SET status = 2,
                     ban_end_time = NOW()
                 WHERE id = ? AND status = 1",
                "i",
                [$banId]
            );
        }

        $conn->commit();

        if ($redisKey !== '') {
            safeDeleteRedisKey($redisHelper, $redisKey);
        } else {
            safeDeleteRedisKey($redisHelper, 'device_ban:' . $serialNumber);
        }

        if ($banId > 0) {
            logMessage("✅ 自动解封设备成功: {$serialNumber}, ban_id={$banId}, source={$source}");
        } else {
            logMessage("⚠️ 设备无生效封禁记录，但已恢复车辆状态: {$serialNumber}, source={$source}");
        }

        return true;

    } catch (Exception $e) {
        $conn->rollback();
        logMessage("❌ 自动解封设备失败: {$serialNumber}, source={$source}, err=" . $e->getMessage());
        return false;
    }
}

// =============================
// 场地解封：共用方法
// =============================

function unbanVenueById($conn, $redisHelper, $venueId, $source = '', $redisKey = '') {
    global $SYSTEM_UNBAN_BY, $AUTO_UNBAN_REASON;

    $venueId = (int)$venueId;
    if ($venueId <= 0) {
        return false;
    }

    $conn->begin_transaction();

    try {
        // 找最新一条正在生效的场地封禁记录
        $banRow = fetchOne(
            $conn,
            "SELECT id, ban_end_time 
             FROM venue_bans 
             WHERE venue_id = ? AND status = 1 
             ORDER BY id DESC 
             LIMIT 1",
            "i",
            [$venueId]
        );

        // 有生效封禁记录，但数据库封禁时间还没到：不解封
        if ($banRow && !isExpiredTime($banRow['ban_end_time'])) {
            $conn->rollback();

            logMessage("⏳ 场地未到解封时间，跳过: {$venueId}, ban_end_time={$banRow['ban_end_time']}, source={$source}");
            return false;
        }

        // 没有生效封禁记录：说明 Redis 或状态异常，只恢复 venues 并清理 Redis
        if (!$banRow) {
            executeSql(
                $conn,
                "UPDATE venues 
                 SET is_banned = 0,
                     venue_status = '营业中'
                 WHERE id = ?",
                "i",
                [$venueId]
            );

            $conn->commit();

            if ($redisKey !== '') {
                safeDeleteRedisKey($redisHelper, $redisKey);
            } else {
                safeDeleteRedisKey($redisHelper, 'venue_ban:' . $venueId);
            }

            logMessage("⚠️ 场地无生效封禁记录，但已恢复场地状态: {$venueId}, source={$source}");
            return true;
        }

        $banId = (int)$banRow['id'];

        // 写入解封留存表
        executeSql(
            $conn,
            "INSERT INTO venue_unban_logs
             (venue_id, ban_id, unban_reason, unban_status, unban_by, created_at)
             VALUES (?, ?, ?, 1, ?, NOW())",
            "iisi",
            [$venueId, $banId, $AUTO_UNBAN_REASON, $SYSTEM_UNBAN_BY]
        );

        // 更新封禁记录
        executeSql(
            $conn,
            "UPDATE venue_bans 
             SET status = 2,
                 ban_end_time = NOW()
             WHERE id = ? AND status = 1",
            "i",
            [$banId]
        );

        // 恢复场地状态
        executeSql(
            $conn,
            "UPDATE venues 
             SET is_banned = 0,
                 venue_status = '营业中'
             WHERE id = ?",
            "i",
            [$venueId]
        );

        $conn->commit();

        if ($redisKey !== '') {
            safeDeleteRedisKey($redisHelper, $redisKey);
        } else {
            safeDeleteRedisKey($redisHelper, 'venue_ban:' . $venueId);
        }

        logMessage("✅ 自动解封场地成功: {$venueId}, ban_id={$banId}, source={$source}");
        return true;

    } catch (Exception $e) {
        $conn->rollback();
        logMessage("❌ 自动解封场地失败: {$venueId}, source={$source}, err=" . $e->getMessage());
        return false;
    }
}
// =============================
// 语音房解封：共用方法
// =============================

function unbanVoiceRoomByVenueId($conn, $venueId, $source = '') {
    $venueId = (int)$venueId;

    if ($venueId <= 0) {
        return false;
    }

    $conn->begin_transaction();

    try {
        // 找最新一条正在生效的语音房封禁记录
        $banRow = fetchOne(
            $conn,
            "SELECT id, ban_end_time
             FROM voice_room_ban_records
             WHERE venue_id = ? AND status = 1
             ORDER BY id DESC
             LIMIT 1",
            "i",
            [$venueId]
        );

        // 有生效封禁记录，但数据库封禁时间还没到：不解封
        if ($banRow && !isExpiredTime($banRow['ban_end_time'])) {
            $conn->rollback();

            logMessage("⏳ 语音房未到解封时间，跳过: venue_id={$venueId}, ban_end_time={$banRow['ban_end_time']}, source={$source}");
            return false;
        }

        // 没有生效封禁记录：兜底恢复 venues 状态
        if (!$banRow) {
            executeSql(
                $conn,
                "UPDATE venues
                 SET is_voice_room_banned = 0
                 WHERE id = ?",
                "i",
                [$venueId]
            );

            $conn->commit();

            logMessage("⚠️ 语音房无生效封禁记录，但已恢复状态: venue_id={$venueId}, source={$source}");
            return true;
        }

        $banId = (int)$banRow['id'];

        // 结束当前语音房封禁记录
        executeSql(
            $conn,
            "UPDATE voice_room_ban_records
             SET status = 2,
                 ban_end_time = NOW()
             WHERE venue_id = ? AND status = 1",
            "i",
            [$venueId]
        );

        // 恢复 venues 语音房状态
        executeSql(
            $conn,
            "UPDATE venues
             SET is_voice_room_banned = 0
             WHERE id = ?",
            "i",
            [$venueId]
        );

        $conn->commit();

        logMessage("✅ 自动解封语音房成功: venue_id={$venueId}, ban_id={$banId}, source={$source}");
        return true;

    } catch (Exception $e) {
        $conn->rollback();

        logMessage("❌ 自动解封语音房失败: venue_id={$venueId}, source={$source}, err=" . $e->getMessage());
        return false;
    }
}
// =============================
// 初始化
// =============================

$database = new Database();
$conn = $database->getConnection();

try {
    $conn->set_charset('utf8mb4');
} catch (Exception $e) {
    logMessage("⚠️ 设置 MySQL 字符集失败: " . $e->getMessage());
}

$redisHelper = new RedisHelper();
$redisHelper->connect();
$redisHelper->selectDb(1);

logMessage("========== 自动解封任务开始 ==========");

// =============================
// ① 扫描 Redis —— 自动解封设备
// =============================

try {
    $deviceKeys = $redisHelper->getAllKeys('device_ban:*');

    if (is_array($deviceKeys)) {
        foreach ($deviceKeys as $key) {
            $raw = $redisHelper->get($key);
            $data = json_decode($raw, true);

            if (!$data || !is_array($data)) {
                logMessage("⚠️ device_ban JSON异常，清理key: {$key}");
                safeDeleteRedisKey($redisHelper, $key);
                continue;
            }

            $banEndTime = $data['ban_end_time'] ?? '';
            if (!isExpiredTime($banEndTime)) {
                continue;
            }

            $serialNumber = trim((string)($data['serial_number'] ?? ''));

            // 兼容 Redis 里没有 serial_number 的情况，从 key 里兜底取
            if ($serialNumber === '') {
                $serialNumber = redisKeySuffix($key, 'device_ban:');
            }

            if ($serialNumber === '') {
                logMessage("⚠️ device_ban 缺少 serial_number，跳过: {$key}");
                continue;
            }

            unbanDeviceBySerial($conn, $redisHelper, $serialNumber, 'redis_device_ban', $key);
        }
    }
} catch (Exception $e) {
    logMessage("❌ 扫描 Redis 设备封禁失败: " . $e->getMessage());
}

// =============================
// ② 扫描 Redis —— 自动解封场地
// =============================

try {
    $venueKeys = $redisHelper->getAllKeys('venue_ban:*');

    if (is_array($venueKeys)) {
        foreach ($venueKeys as $key) {
            $raw = $redisHelper->get($key);
            $data = json_decode($raw, true);

            if (!$data || !is_array($data)) {
                logMessage("⚠️ venue_ban JSON异常，清理key: {$key}");
                safeDeleteRedisKey($redisHelper, $key);
                continue;
            }

            $banEndTime = $data['ban_end_time'] ?? '';
            if (!isExpiredTime($banEndTime)) {
                continue;
            }

            $venueId = (int)($data['venue_id'] ?? 0);

            // 兼容 Redis 里没有 venue_id 的情况，从 key 里兜底取
            if ($venueId <= 0) {
                $venueId = (int)redisKeySuffix($key, 'venue_ban:');
            }

            if ($venueId <= 0) {
                logMessage("⚠️ venue_ban 缺少 venue_id，跳过: {$key}");
                continue;
            }

            unbanVenueById($conn, $redisHelper, $venueId, 'redis_venue_ban', $key);
        }
    }
} catch (Exception $e) {
    logMessage("❌ 扫描 Redis 场地封禁失败: " . $e->getMessage());
}

// =============================
// ③ 扫描 MySQL —— 兜底解封设备
// 作用：Redis key 丢失时，也能根据 device_bans 到期记录自动解封。
// 注意：只处理“最新一条生效封禁记录已经到期”的设备，避免旧封禁到期误解新封禁。
// =============================

try {
    $targetVehicles = fetchAllRows(
        $conn,
        "SELECT 
            v.serial_number,
            v.sharing_status,
            db.id AS ban_id,
            db.ban_end_time
         FROM device_bans db
         INNER JOIN (
            SELECT serial_number, MAX(id) AS max_id
            FROM device_bans
            WHERE status = 1
            GROUP BY serial_number
         ) latest 
            ON latest.max_id = db.id
         INNER JOIN vehicles v
            ON v.serial_number = db.serial_number
         WHERE v.is_banned = 1
           AND db.ban_end_time IS NOT NULL
           AND db.ban_end_time <= NOW()
         ORDER BY db.id ASC"
    );

    foreach ($targetVehicles as $row) {
        $serialNumber = trim((string)$row['serial_number']);
        if ($serialNumber === '') {
            continue;
        }

        logMessage("🔍 MySQL补扫到期设备: {$serialNumber}, ban_id={$row['ban_id']}, 原sharing_status={$row['sharing_status']}");

        unbanDeviceBySerial($conn, $redisHelper, $serialNumber, 'mysql_device_sweep');
    }

} catch (Exception $e) {
    logMessage("❌ MySQL补扫设备失败: " . $e->getMessage());
}

// =============================
// ⑤ 扫描 MySQL —— 自动解封语音房
// 作用：根据 voice_room_ban_records 到期记录自动解除语音房封禁。
// 注意：只处理“最新一条生效封禁记录已经到期”的语音房。
// =============================

try {
    $targetVoiceRooms = fetchAllRows(
        $conn,
        "SELECT
            v.id AS venue_id,
            v.is_voice_room_banned,
            vr.id AS ban_id,
            vr.ban_end_time
         FROM voice_room_ban_records vr
         INNER JOIN (
            SELECT venue_id, MAX(id) AS max_id
            FROM voice_room_ban_records
            WHERE status = 1
            GROUP BY venue_id
         ) latest
            ON latest.max_id = vr.id
         INNER JOIN venues v
            ON v.id = vr.venue_id
         WHERE vr.ban_end_time IS NOT NULL
           AND vr.ban_end_time <= NOW()
         ORDER BY vr.id ASC"
    );

    foreach ($targetVoiceRooms as $row) {
        $venueId = (int)$row['venue_id'];

        if ($venueId <= 0) {
            continue;
        }

        logMessage("🔍 MySQL补扫到期语音房: venue_id={$venueId}, ban_id={$row['ban_id']}, 原is_voice_room_banned={$row['is_voice_room_banned']}");

        unbanVoiceRoomByVenueId($conn, $venueId, 'mysql_voice_room_sweep');
    }

} catch (Exception $e) {
    logMessage("❌ MySQL补扫语音房失败: " . $e->getMessage());
}

// =============================
// ④ 扫描 MySQL —— 兜底解封场地
// 作用：Redis key 丢失时，也能根据 venue_bans 到期记录自动解封。
// 注意：只处理“最新一条生效封禁记录已经到期”的场地。
// =============================

try {
    $targetVenues = fetchAllRows(
        $conn,
        "SELECT 
            v.id AS venue_id,
            v.venue_status,
            db.id AS ban_id,
            db.ban_end_time
         FROM venue_bans db
         INNER JOIN (
            SELECT venue_id, MAX(id) AS max_id
            FROM venue_bans
            WHERE status = 1
            GROUP BY venue_id
         ) latest 
            ON latest.max_id = db.id
         INNER JOIN venues v
            ON v.id = db.venue_id
         WHERE v.is_banned = 1
           AND db.ban_end_time IS NOT NULL
           AND db.ban_end_time <= NOW()
         ORDER BY db.id ASC"
    );

    foreach ($targetVenues as $row) {
        $venueId = (int)$row['venue_id'];
        if ($venueId <= 0) {
            continue;
        }

        logMessage("🔍 MySQL补扫到期场地: {$venueId}, ban_id={$row['ban_id']}, 原venue_status={$row['venue_status']}");

        unbanVenueById($conn, $redisHelper, $venueId, 'mysql_venue_sweep');
    }

} catch (Exception $e) {
    logMessage("❌ MySQL补扫场地失败: " . $e->getMessage());
}



// =============================
// ⑥ 扫描 MySQL —— 自动删除到期禁言记录
// 作用：banned_users.end_time 到期后，直接删除这条禁言记录
// 注意：end_time 为 NULL 的永久禁言不会删除
// =============================

try {
    $expiredMuteRow = fetchOne(
        $conn,
        "SELECT COUNT(*) AS total
         FROM banned_users
         WHERE end_time IS NOT NULL
           AND end_time <= NOW()"
    );

    $expiredMuteTotal = intval($expiredMuteRow['total'] ?? 0);

    if ($expiredMuteTotal <= 0) {
        logMessage("ℹ️ 没有到期禁言记录需要清理");
    } else {
        $deletedRows = executeSql(
            $conn,
            "DELETE FROM banned_users
             WHERE end_time IS NOT NULL
               AND end_time <= NOW()"
        );

        logMessage("✅ 自动删除到期禁言记录成功: {$deletedRows} 条，检测到期记录 {$expiredMuteTotal} 条");
    }

} catch (Exception $e) {
    logMessage("❌ 自动删除到期禁言记录失败: " . $e->getMessage());
}

// =============================
// ⑦ 扫描 MySQL —— 自动恢复旧逻辑到期禁言
// 作用：mute_user_logs.end_time 到期后，把 users.is_mute 改回 0，并删除这条临时日志
// 注意：这是修复旧逻辑禁言的临时措施
// =============================

try {
    $expiredOldMuteUsers = fetchAllRows(
        $conn,
        "SELECT 
            id,
            admin_uid,
            banned_uid,
            start_time,
            end_time
         FROM mute_user_logs
         WHERE end_time IS NOT NULL
           AND end_time <= NOW()
         ORDER BY id ASC"
    );

    if (empty($expiredOldMuteUsers)) {
        logMessage("ℹ️ 没有到期的旧逻辑禁言记录需要恢复");
    } else {
        foreach ($expiredOldMuteUsers as $row) {
            $logId = (int)$row['id'];
            $bannedUid = (int)$row['banned_uid'];

            if ($logId <= 0 || $bannedUid <= 0) {
                continue;
            }

            $conn->begin_transaction();

            try {
                // 恢复用户禁言状态
                executeSql(
                    $conn,
                    "UPDATE users 
                     SET is_mute = 0 
                     WHERE uid = ?",
                    "i",
                    [$bannedUid]
                );

                // 删除这条临时禁言日志
                executeSql(
                    $conn,
                    "DELETE FROM mute_user_logs 
                     WHERE id = ?",
                    "i",
                    [$logId]
                );

                $conn->commit();

                logMessage("✅ 旧逻辑禁言已自动恢复: banned_uid={$bannedUid}, log_id={$logId}, end_time={$row['end_time']}");

            } catch (Exception $e) {
                $conn->rollback();

                logMessage("❌ 旧逻辑禁言恢复失败: banned_uid={$bannedUid}, log_id={$logId}, err=" . $e->getMessage());
            }
        }
    }

} catch (Exception $e) {
    logMessage("❌ 扫描 mute_user_logs 到期禁言失败: " . $e->getMessage());
}

logMessage("========== 自动解封任务结束 ==========");