<?php
header('Content-Type: application/json; charset=utf-8');

require_once '../Database.php'; // 按你的实际路径改

function json_out($code, $msg, $data = [], $extra = []) {
    echo json_encode(array_merge([
        'code' => $code,
        'msg'  => $msg,
        'data' => $data
    ], $extra), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensure_default_messages($conn) {
    $sql = "SELECT id, sort, is_show_game, is_show_gift 
            FROM voice_room_default_messages 
            ORDER BY sort ASC, id ASC";
    $result = $conn->query($sql);

    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    if (count($rows) === 0) {
        $insertSql = "INSERT INTO voice_room_default_messages
            (messageType, userName, text, giftImageURL, vip, is_only_self, sort, is_enable, is_show_game, is_show_gift)
            VALUES
            ('system', '系统', '欢迎来到语音房！', '', 0, 0, 1, 1, 0, 1),
            ('system', '系统', '文明聊天，请勿发送违规内容。', '', 0, 0, 2, 1, 0, 1)";
        $conn->query($insertSql);
        return;
    }

    if (count($rows) === 1) {
        $first = $rows[0];
        $is_show_game = isset($first['is_show_game']) ? (int)$first['is_show_game'] : 0;
        $is_show_gift = isset($first['is_show_gift']) ? (int)$first['is_show_gift'] : 1;

        $stmt = $conn->prepare("INSERT INTO voice_room_default_messages
            (messageType, userName, text, giftImageURL, vip, is_only_self, sort, is_enable, is_show_game, is_show_gift)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $messageType = 'system';
        $userName = '系统';
        $text = '文明聊天，请勿发送违规内容。';
        $giftImageURL = '';
        $vip = 0;
        $is_only_self = 0;
        $sort = 2;
        $is_enable = 1;
        $stmt->bind_param(
            "ssssiiiiii",
            $messageType,
            $userName,
            $text,
            $giftImageURL,
            $vip,
            $is_only_self,
            $sort,
            $is_enable,
            $is_show_game,
            $is_show_gift
        );
        $stmt->execute();
        $stmt->close();
    }
}

function get_global_switch($conn) {
    $sql = "SELECT is_show_game, is_show_gift
            FROM voice_room_default_messages
            ORDER BY sort ASC, id ASC
            LIMIT 1";
    $result = $conn->query($sql);

    if ($result && $row = $result->fetch_assoc()) {
        return [
            'is_show_game' => isset($row['is_show_game']) ? (int)$row['is_show_game'] : 0,
            'is_show_gift' => isset($row['is_show_gift']) ? (int)$row['is_show_gift'] : 1
        ];
    }

    return [
        'is_show_game' => 0,
        'is_show_gift' => 1
    ];
}

function get_enabled_messages($conn) {
    $sql = "SELECT id, messageType, userName, text, giftImageURL, vip, is_only_self, sort
            FROM voice_room_default_messages
            WHERE is_enable = 1
            ORDER BY sort ASC, id ASC";
    $result = $conn->query($sql);

    $data = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $data[] = [
                'id'           => (int)$row['id'],
                'messageType'  => (string)$row['messageType'],
                'userName'     => (string)$row['userName'],
                'text'         => (string)$row['text'],
                'giftImageURL' => (string)$row['giftImageURL'],
                'vip'          => (int)$row['vip'],
                'is_only_self' => (int)$row['is_only_self'],
                'sort'         => (int)$row['sort']
            ];
        }
    }

    return $data;
}

function get_first_two_messages($conn) {
    $sql = "SELECT id, text, sort
            FROM voice_room_default_messages
            ORDER BY sort ASC, id ASC
            LIMIT 2";
    $result = $conn->query($sql);

    $rows = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    return [
        'msg1_id' => isset($rows[0]['id']) ? (int)$rows[0]['id'] : 0,
        'msg1_text' => isset($rows[0]['text']) ? (string)$rows[0]['text'] : '欢迎来到语音房！',
        'msg2_id' => isset($rows[1]['id']) ? (int)$rows[1]['id'] : 0,
        'msg2_text' => isset($rows[1]['text']) ? (string)$rows[1]['text'] : '文明聊天，请勿发送违规内容。'
    ];
}

function update_message_text($conn, $id, $text, $defaultSort, $is_show_game, $is_show_gift) {
    $text = trim($text);
    if ($text === '') {
        $text = $defaultSort == 1 ? '欢迎来到语音房！' : '文明聊天，请勿发送违规内容。';
    }

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE voice_room_default_messages
                                SET text = ?, messageType = 'system', userName = '系统'
                                WHERE id = ?");
        $stmt->bind_param("si", $text, $id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $conn->prepare("INSERT INTO voice_room_default_messages
            (messageType, userName, text, giftImageURL, vip, is_only_self, sort, is_enable, is_show_game, is_show_gift)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $messageType = 'system';
        $userName = '系统';
        $giftImageURL = '';
        $vip = 0;
        $is_only_self = 0;
        $sort = $defaultSort;
        $is_enable = 1;
        $stmt->bind_param(
            "ssssiiiiii",
            $messageType,
            $userName,
            $text,
            $giftImageURL,
            $vip,
            $is_only_self,
            $sort,
            $is_enable,
            $is_show_game,
            $is_show_gift
        );
        $stmt->execute();
        $stmt->close();
    }
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    ensure_default_messages($conn);

    $action = $_REQUEST['action'] ?? '';

    // 给 App 用：默认 GET
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($action === '' || $action === 'app')) {
        $switch = get_global_switch($conn);
        $messages = get_enabled_messages($conn);

        echo json_encode([
            'code' => 200,
            'msg'  => '获取成功',
            'is_show_game' => (int)$switch['is_show_game'],
            'is_show_gift' => (int)$switch['is_show_gift'],
            'data' => $messages
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // 给后台 HTML 读取
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'get_admin') {
        $switch = get_global_switch($conn);
        $texts  = get_first_two_messages($conn);

        json_out(200, '获取成功', [
            'is_show_game' => (int)$switch['is_show_game'],
            'is_show_gift' => (int)$switch['is_show_gift'],
            'msg1_id'      => (int)$texts['msg1_id'],
            'msg1_text'    => (string)$texts['msg1_text'],
            'msg2_id'      => (int)$texts['msg2_id'],
            'msg2_text'    => (string)$texts['msg2_text']
        ]);
    }

    // 给后台 HTML 保存
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
        $is_show_game = isset($_POST['is_show_game']) ? (int)$_POST['is_show_game'] : 0;
        $is_show_gift = isset($_POST['is_show_gift']) ? (int)$_POST['is_show_gift'] : 1;

        $msg1_id = isset($_POST['msg1_id']) ? (int)$_POST['msg1_id'] : 0;
        $msg2_id = isset($_POST['msg2_id']) ? (int)$_POST['msg2_id'] : 0;

        $msg1_text = trim($_POST['msg1_text'] ?? '');
        $msg2_text = trim($_POST['msg2_text'] ?? '');

        $is_show_game = $is_show_game == 1 ? 1 : 0;
        $is_show_gift = $is_show_gift == 1 ? 1 : 0;

        $database->beginTransaction();

        try {
            // 全表统一更新全局开关
            $stmt = $conn->prepare("UPDATE voice_room_default_messages
                                    SET is_show_game = ?, is_show_gift = ?");
            $stmt->bind_param("ii", $is_show_game, $is_show_gift);
            $stmt->execute();
            $stmt->close();

            // 更新前两条文案
            update_message_text($conn, $msg1_id, $msg1_text, 1, $is_show_game, $is_show_gift);
            update_message_text($conn, $msg2_id, $msg2_text, 2, $is_show_game, $is_show_gift);

            $database->commit();
        } catch (Exception $e) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            json_out(500, '保存失败：' . $e->getMessage());
        }

        json_out(200, '保存成功');
    }

    json_out(400, '无效请求');

} catch (Exception $e) {
    json_out(500, '服务器异常：' . $e->getMessage());
}