<?php
require_once '../Database.php';

// 日志记录函数
function logMessage_log($message) {
    $logFile = __DIR__ . '/checkin_fill.log';
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] $message\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

$database = new Database();
$conn = $database->getConnection();

try {
    $database->beginTransaction();

    $sql = "
        INSERT INTO checkin_log (uid, checkin_time)
        SELECT
            CASE
                WHEN x.hour_num >= 9 AND x.hour_num < 22 THEN 10001
                ELSE 10130
            END AS uid,
            x.rand_checkin_time
        FROM
        (
            SELECT
                a.work_day,
                a.hour_num,
                TIMESTAMP(a.work_day, MAKETIME(a.hour_num, 0, 0))
                    + INTERVAL FLOOR(RAND() * 3600) SECOND AS rand_checkin_time
            FROM
            (
                SELECT
                    d.work_day,
                    h.hour_num,
                    GREATEST(
                        0,
                        3 - (
                            SELECT COUNT(*)
                            FROM checkin_log c
                            WHERE c.checkin_time >= TIMESTAMP(d.work_day, MAKETIME(h.hour_num, 0, 0))
                              AND c.checkin_time <  TIMESTAMP(d.work_day, MAKETIME(h.hour_num, 0, 0)) + INTERVAL 1 HOUR
                        )
                    ) AS need_cnt
                FROM
                (
                    SELECT CURDATE() AS work_day
                    UNION ALL
                    SELECT DATE_SUB(CURDATE(), INTERVAL 1 DAY) AS work_day
                ) d
                JOIN
                (
                    SELECT 0 AS hour_num UNION ALL
                    SELECT 1 UNION ALL
                    SELECT 2 UNION ALL
                    SELECT 3 UNION ALL
                    SELECT 4 UNION ALL
                    SELECT 9 UNION ALL
                    SELECT 10 UNION ALL
                    SELECT 11 UNION ALL
                    SELECT 12 UNION ALL
                    SELECT 13 UNION ALL
                    SELECT 14 UNION ALL
                    SELECT 15 UNION ALL
                    SELECT 16 UNION ALL
                    SELECT 17 UNION ALL
                    SELECT 18 UNION ALL
                    SELECT 19 UNION ALL
                    SELECT 20 UNION ALL
                    SELECT 21 UNION ALL
                    SELECT 22 UNION ALL
                    SELECT 23
                ) h
                WHERE
                    (d.work_day = CURDATE() AND h.hour_num BETWEEN 0 AND 4)
                    OR
                    (d.work_day = DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND h.hour_num BETWEEN 9 AND 23)
            ) a
            JOIN
            (
                SELECT 1 AS n UNION ALL
                SELECT 2 UNION ALL
                SELECT 3
            ) b
              ON b.n <= a.need_cnt
        ) x
        ORDER BY x.work_day, x.hour_num, x.rand_checkin_time
    ";

    $result = $conn->query($sql);

    if ($result === false) {
        throw new Exception('SQL执行失败: ' . $conn->error);
    }

    $insertedRows = $conn->affected_rows;

    $database->commit();

    $msg = '补签成功，插入 ' . $insertedRows . ' 条记录';
    logMessage_log($msg);
    echo $msg . PHP_EOL;

} catch (Exception $e) {
    if ($database->inTransaction()) {
        $database->rollBack();
    }

    $err = '补签失败：' . $e->getMessage();
    logMessage_log($err);
    echo $err . PHP_EOL;
} finally {
    $database->close();
}