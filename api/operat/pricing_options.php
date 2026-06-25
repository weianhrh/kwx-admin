<?php
require_once '../Database.php';       
require_once '../lib/venue_scope.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
$database = new Database();
$session_token = $_COOKIE['session_token'] ?? null;

if (!$session_token) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或会话已过期', 'data' => []]);
    exit;
}

$user = $database->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) {
    echo json_encode(['code' => 1001, 'msg' => '用户未登录或无权访问', 'data' => []]);
    exit;
}

$role_id = $user['role_id'];
$venue_id = venue_scope_resolve_single_id($database, $user, venue_scope_requested_id(array_merge($_GET, $_POST)));
if ($venue_id <= 0) {
    echo json_encode(['code' => 1003, 'msg' => '未绑定或无权访问该场地', 'data' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

// ✅ 统一资费费率配置，后面只改这里
$RATE_MIN = 0.8;
$RATE_MAX = 3.0;

function calcPricingRate($battery, $minutes) {
    $minutes = intval($minutes);
    if ($minutes <= 0) {
        return 0;
    }
    return round(floatval($battery) / $minutes, 2);
}

$inputJSON = file_get_contents("php://input");
$inputData = json_decode($inputJSON, true);

// 兼容 application/x-www-form-urlencoded
if (!$inputData) {
    $inputData = $_POST;
}

// 先查询当前场地是否有套餐
$pricingOptionsSql = "SELECT * FROM PricingOptions WHERE BindLocation = ?";
$stmt = $database->getConnection()->prepare($pricingOptionsSql);
$hasPricingOptions = false;
if ($stmt) {
    $stmt->bind_param("i", $venue_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $hasPricingOptions = true;
    }
    $stmt->close();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if (isset($inputData['action']) && $inputData['action'] === 'insert_default' && !$hasPricingOptions) {
        try {
            $insertSql = "
                INSERT INTO PricingOptions (Minutes, Battery, Notes, Title, BindLocation, PricingType) 
                VALUES (?, ?, ?, ?, ?, ?)
            ";
            $stmt = $database->getConnection()->prepare($insertSql);

            // 第一个默认套餐
            $defaultMinutes1 = 4;
            $defaultBattery1 = 5.0;
            $defaultNotes1 = "不要结束订单，遇到问题，换车驾驶";
            $defaultTitle1 = "4分钟5电池(福利)";
            $defaultBindLocation = $venue_id; 
            $defaultPricingType = "按次计费";

            $stmt->bind_param("iissis", $defaultMinutes1, $defaultBattery1, $defaultNotes1, $defaultTitle1, $defaultBindLocation, $defaultPricingType);
            
            if (!$stmt->execute()) {
                throw new Exception("插入第一个默认套餐失败: " . $stmt->error);
            }

            // 第二个默认套餐
            $defaultMinutes2 = 10;
            $defaultBattery2 = 10;
            $defaultNotes2 = "推荐用于常规使用";
            $defaultTitle2 = "10分钟10电池(推荐)";

            $stmt->bind_param("iissis", $defaultMinutes2, $defaultBattery2, $defaultNotes2, $defaultTitle2, $defaultBindLocation, $defaultPricingType);
            
            if (!$stmt->execute()) {
                throw new Exception("插入第二个默认套餐失败: " . $stmt->error);
            }

            echo json_encode(['code' => 0, 'msg' => '默认套餐生成成功', 'data' => []]);

            $stmt->close();
        } catch (Exception $e) {
            echo json_encode(['code' => 3, 'msg' => '默认套餐生成失败: ' . $e->getMessage(), 'data' => []]);
        }
        $database->close();
        exit;
    } elseif (isset($inputData['action']) && $inputData['action'] === 'add') {
        try {
            // 先检查当前套餐数量
            $countSql = "SELECT COUNT(*) as count FROM PricingOptions WHERE BindLocation = ?";
            $stmt = $database->getConnection()->prepare($countSql);
            $stmt->bind_param("i", $venue_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if ($row['count'] >= 4) {
                echo json_encode([
                    'venue_id' => $venue_id,
                    'code' => 4,
                    'msg' => '套餐数量已达上限（4个），不能继续添加',
                    'data' => []
                ]);
                $database->close();
                exit;
            }

            $minutes = $inputData['minutes'] ?? null;
            $battery = isset($inputData['battery']) ? floatval($inputData['battery']) : null;
            $notes = $inputData['notes'] ?? '';
            $Title = $inputData['packageName'] ?? '';

            if (!$minutes || !$battery || !$Title) {
                throw new Exception("缺少必要参数");
            }
            if ($minutes > 120) {
                echo json_encode([
                    'code' => 6,
                    'msg' => "可驾驶时长不能超过 120 分钟，当前输入为 {$minutes}",
                    'data' => []
                ]);
                $database->close();
                exit;
            }
            
            if (!preg_match('/^\d+$/', $minutes)) {
                echo json_encode(['code' => 7, 'msg' => '可驾驶时长必须为纯整数']);
                exit;
            }
            
            if (!preg_match('/^\d+(\.\d+)?$/', $battery)) {
                echo json_encode(['code' => 8, 'msg' => '电池费用必须为纯数字，可为小数']);
                exit;
            }
            if ($battery < 1) {
                echo json_encode([
                    'code' => 1,
                    'msg' => "套餐不能低于 1 元钱，当前输入金额为 ". $battery,
                    'data' => []
                ]);
                $database->close();
                exit;
            }
            $rate = calcPricingRate($battery, $minutes);
            
            if ($role_id != 1 && ($rate < $RATE_MIN || $rate > $RATE_MAX)) {
                echo json_encode([
                    'code' => 5,
                    'msg' => "费率不在允许范围 ({$RATE_MIN}~{$RATE_MAX} 元/分钟)，当前费率 {$rate}，提交无效",
                    'data' => []
                ]);
                exit;
            }
             if ($minutes < 2) {
                echo json_encode(['code' => 7, 'msg' => '最低可驾驶时长不得低于2分钟']);
                exit;
            }
            $insertSql = "
                INSERT INTO PricingOptions (Minutes, Battery, Notes, Title, BindLocation, PricingType) 
                VALUES (?, ?, ?, ?, ?, ?)
            ";
            $stmt = $database->getConnection()->prepare($insertSql);
            $pricingType = "按次计费"; // 假设默认的计费类型
            $stmt->bind_param("iissis", $minutes, $battery, $notes, $Title, $venue_id, $pricingType);

            if ($stmt->execute()) {
                echo json_encode(['code' => 0, 'msg' => '套餐添加成功', 'data' => []]);
            } else {
                throw new Exception("插入套餐失败: ". $stmt->error);
            }

            $stmt->close();
        } catch (Exception $e) {
            echo json_encode([
                'code' => 2,
                'msg' => '添加套餐失败: '. $e->getMessage(),
                'data' => []
            ]);
        }
        $database->close();
        exit;
    } elseif (isset($inputData['action']) && $inputData['action'] === 'delete') {
        try {
            $id = $inputData['id'] ?? null;
            if (!$id) {
                throw new Exception("缺少必要参数");
            }

            $deleteSql = "DELETE FROM PricingOptions WHERE ID = ? AND BindLocation = ?";
            $stmt = $database->getConnection()->prepare($deleteSql);
            $stmt->bind_param("ii", $id, $venue_id);

            if ($stmt->execute()) {
                echo json_encode(['code' => 0, 'msg' => '套餐删除成功', 'data' => []]);
            } else {
                throw new Exception("删除套餐失败: ". $stmt->error);
            }

            $stmt->close();
        } catch (Exception $e) {
            echo json_encode([
                'code' => 2,
                'msg' => '删除套餐失败: '. $e->getMessage(),
                'data' => []
            ]);
        }
        $database->close();
        exit;
    }

    try {
        // Using inputData (json) instead of $_POST to avoid inconsistency
        $id = $inputData['id'] ?? null;
        $minutes = $inputData['minutes'] ?? null;
        $battery = isset($inputData['battery']) ? floatval($inputData['battery']) : null;
        $notes = $inputData['notes'] ?? '';
        $Title = $inputData['packageName'] ?? '';
        
        if (!$id || !$minutes || !$battery || !$Title) {
            throw new Exception("缺少必要参数");
        }
$minutesRaw = $inputData['minutes'] ?? null;
$batteryRaw = $inputData['battery'] ?? null;

// ✅ 可驾驶时长校验
if (!preg_match('/^\d+$/', (string)$minutesRaw)) {
    echo json_encode(['code' => 7, 'msg' => '可驾驶时长必须为纯整数']);
    exit;
}

$minutes = intval($minutesRaw);

if ($minutes < 2) {
    echo json_encode(['code' => 7, 'msg' => '最低可驾驶时长不得低于2分钟']);
    exit;
}

if ($minutes > 120) {
    echo json_encode([
        'code' => 6,
        'msg' => "可驾驶时长不能超过 120 分钟，当前输入为 {$minutes}",
        'data' => []
    ]);
    $database->close();
    exit;
}

// ✅ 电池费用校验
if (!preg_match('/^\d+(\.\d+)?$/', (string)$batteryRaw)) {
    echo json_encode(['code' => 8, 'msg' => '电池费用必须为纯数字，可为小数']);
    exit;
}

$battery = floatval($batteryRaw);

if ($battery < 1) {
    echo json_encode([
        'code' => 1,
        'msg' => "套餐不能低于 1 元钱，当前输入金额为 " . $battery,
        'data' => []
    ]);
    $database->close();
    exit;
}

// ✅ 编辑套餐也走统一费率变量
$rate = calcPricingRate($battery, $minutes);

if ($role_id != 1 && ($rate < $RATE_MIN || $rate > $RATE_MAX)) {
    echo json_encode([
        'code' => 5,
        'msg' => "费率不在允许范围 ({$RATE_MIN}~{$RATE_MAX} 元/分钟)，当前费率 {$rate}，提交无效",
        'data' => []
    ]);
    exit;
}
        $stmt = $database->getConnection()->prepare("
            UPDATE PricingOptions 
            SET Minutes = ?, Battery = ?, Notes = ?, Title = ? 
            WHERE ID = ? AND BindLocation = ?
        ");

        if (!$stmt) {
            throw new Exception("Prepare failed: ". $database->getConnection()->error);
        }

        $stmt->bind_param("iissii", $minutes, $battery, $notes, $Title, $id, $venue_id);

        if ($stmt->execute()) {
            echo json_encode(['code' => 0, 'msg' => '更新成功', 'data' => []]);
        } else {
            throw new Exception("Execute failed: ". $stmt->error);
        }

        $stmt->close();
    } catch (Exception $e) {
        echo json_encode([
            'code' => 2,
            'msg' => '更新失败: '. $e->getMessage(),
            'data' => []
        ]);
    }
    $database->close();
    exit;
}

// 查询当前场地资费套餐信息
$pricingOptionsSql = "SELECT * FROM PricingOptions WHERE BindLocation = ?";
$stmt = $database->getConnection()->prepare($pricingOptionsSql);
if ($stmt) {
    $stmt->bind_param("i", $venue_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pricingOptions = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $pricingOptions = [];
}

// 输出 JSON
echo json_encode([
    'code' => 0,
    'msg' => '',
    'data' => $pricingOptions
]);

$database->close();
?>
