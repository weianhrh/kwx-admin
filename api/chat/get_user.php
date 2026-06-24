<?php
// /api/get_user.php
require_once '../Database.php';
header('Content-Type: application/json; charset=utf-8');

define('AGENT_UID', 10001); // 如果有固定客服UID，方便特殊处理

$db = new Database();

// 允许同时带 token 和 uid：
// - uid > 0 时优先按 uid 查；
// - 否则按 token 查“自己”
$authToken = isset($_GET['token']) ? trim($_GET['token']) : '';
$reqUid    = isset($_GET['uid'])   ? intval($_GET['uid'])   : 0;

try {
    if ($reqUid > 0) {
        // 1) 按 uid 查任意用户（仅返回必要字段）
        if ($reqUid === AGENT_UID) {
            // 客服固定头像（没有就留空）
            echo json_encode([
                'code' => 200,
                'data' => [
                    'uid'        => AGENT_UID,
                    'nickname'   => '客服',
                    'headimgurl' => '002.png', // 改成你的客服头像地址
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $u = $db->query("SELECT uid, nickname, headimgurl FROM users WHERE uid = ? LIMIT 1", [$reqUid]);
        if ($u && isset($u[0]['uid'])) {
            echo json_encode(['code'=>200, 'data'=>$u[0]], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['code'=>404, 'msg'=>'用户未找到']);
        }
        exit;
    }

    // 2) 没有 uid，按 token 查“自己”
    if ($authToken !== '') {
        $self = $db->query("SELECT uid, nickname, headimgurl, energy, vip FROM users WHERE token = ? LIMIT 1", [$authToken]);
        if ($self && isset($self[0]['uid'])) {
            echo json_encode(['code'=>200, 'data'=>$self[0]], JSON_UNESCAPED_UNICODE);
        } else {
            echo json_encode(['code'=>404, 'msg'=>'用户未找到']);
        }
        exit;
    }

    echo json_encode(['code'=>400, 'msg'=>'缺少参数 uid 或 token']);
} catch (Throwable $e) {
    echo json_encode(['code'=>500, 'msg'=>'服务器异常: '.$e->getMessage()]);
}
