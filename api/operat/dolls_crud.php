<?php
require_once '../Mydatabases.php';
header('Content-Type: application/json; charset=utf-8');

function respond($code, $msg, $data = []) {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
function read_json_body() {
    static $parsed = null;
    if ($parsed !== null) return $parsed;
    $raw = file_get_contents('php://input');
    if (!$raw) return $parsed = [];
    $data = json_decode($raw, true);
    return $parsed = (is_array($data) ? $data : []);
}
function get_param($key, $default = null) {
    $json = read_json_body();
    if (array_key_exists($key, $json)) return $json[$key];
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key]))  return $_GET[$key];
    return $default;
}
function pick_fields($src, $fields) {
    $dst = [];
    foreach ($fields as $f) {
        if (isset($src[$f])) $dst[$f] = $src[$f];
    }
    return $dst;
}

$db = new Database();
$session_token = $_COOKIE['session_token'] ?? null;
if (!$session_token) respond(1001, '用户未登录或会话已过期');
$user = $db->getUserBySessionToken($session_token);
if (!$user || !$user['role_id']) respond(1001, '用户未登录或无权访问');

$FIELDS = ['doll_id','doll_name','doll_machine_integral','doll_image','report_status'];

$action = get_param('action', 'list');

switch ($action) {
    case 'list': {
        $page = max(1, intval(get_param('page', 1)));
        $size = min(100, max(1, intval(get_param('size', 50))));
        $offset = ($page - 1) * $size;

        $where = " WHERE 1=1 ";
        $params = [];

        $q_doll_id = trim((string)get_param('doll_id', ''));
        if ($q_doll_id !== '') { $where .= " AND doll_id = ? "; $params[] = $q_doll_id; }
        $q_name = trim((string)get_param('doll_name', ''));
        if ($q_name !== '') { $where .= " AND doll_name LIKE ? "; $params[] = "%{$q_name}%"; }
        $q_status = get_param('report_status', null);
        if ($q_status !== null && $q_status !== '') { $where .= " AND report_status = ? "; $params[] = strval($q_status); }

        $total = 0;
        $row_cnt = $db->query("SELECT COUNT(*) AS c FROM dolls {$where}", $params);
        if ($row_cnt && isset($row_cnt[0]['c'])) $total = intval($row_cnt[0]['c']);

        $params_list = $params;
        $params_list[] = strval($offset);
        $params_list[] = strval($size);
        $list = $db->query("SELECT id, doll_id, doll_name, doll_machine_integral, doll_image, report_status, created_at
                            FROM dolls {$where} ORDER BY id DESC LIMIT ?, ?", $params_list) ?: [];
        respond(0, 'ok', ['page'=>$page,'size'=>$size,'total'=>$total,'list'=>$list]);
    }

    case 'get': {
        $id = get_param('id', null);
        $doll_id = get_param('doll_id', null);
        if ($id === null && $doll_id === null) respond(1002, '参数缺失：id 或 doll_id');
        $rows = ($id !== null)
            ? $db->query("SELECT * FROM dolls WHERE id = ? LIMIT 1", [strval($id)])
            : $db->query("SELECT * FROM dolls WHERE doll_id = ? LIMIT 1", [strval($doll_id)]);
        if (!$rows || count($rows) === 0) respond(404, '未找到记录');
        respond(0, 'ok', $rows[0]);
    }

    case 'save': {
        $payload = read_json_body();
        $items = [];
        if (isset($payload['items']) && is_array($payload['items'])) {
            $items = $payload['items'];
        } elseif (is_array($payload) && !isset($payload['action'])) {
            $items = [$payload];
        } else {
            respond(1004, '请求体为空或格式不正确（支持 {items:[...]} 或 直接传单对象）');
        }
        if (count($items) === 0) respond(1004, 'items 为空');

        $mode = get_param('mode', 'upsert');
        $reject_dup = intval(get_param('reject_duplicates', 1)) === 1;
        if ($reject_dup) $mode = 'insert_only'; // 强制只插入，不覆盖

        // 自动命名
        $auto_name  = intval(get_param('auto_name', 0)) === 1;
        $name_prefix = (string)get_param('name_prefix', '');
        $start_no   = intval(get_param('start_no', 1));
        $pad_len    = max(0, intval(get_param('pad_len', 0)));
        $auto_counter = $start_no;

        // 先规范化，收集 id / name
        $norm = [];
        foreach ($items as $idx => $item) {
            $data = pick_fields($item, $FIELDS);
            if (!isset($data['doll_id']) || trim($data['doll_id']) === '') {
                $norm[] = ['idx'=>$idx,'invalid'=>true,'reason'=>'缺少 doll_id'];
                continue;
            }
            if ((!isset($data['doll_name']) || $data['doll_name']==='') && $auto_name) {
                $num = (string)$auto_counter;
                if ($pad_len > 0) $num = str_pad($num, $pad_len, '0', STR_PAD_LEFT);
                $data['doll_name'] = $name_prefix . $num;
                $auto_counter++;
            }
            if (!isset($data['doll_name']) || $data['doll_name']==='') $data['doll_name'] = '未命名';

            $norm[] = [
                'idx'=>$idx,
                'doll_id'=>strval($data['doll_id']),
                'doll_name'=>strval($data['doll_name']),
                'doll_machine_integral'=>strval(intval($data['doll_machine_integral'] ?? 0)),
                'doll_image'=>strval($data['doll_image'] ?? ''),
                'report_status'=>strval(intval($data['report_status'] ?? 1)),
            ];
        }

        // 批次内重复统计
        $idCounts = []; $nameCounts = [];
        foreach ($norm as $n) {
            if (!empty($n['invalid'])) continue;
            $idCounts[$n['doll_id']] = ($idCounts[$n['doll_id']] ?? 0) + 1;
            $nameCounts[$n['doll_name']] = ($nameCounts[$n['doll_name']] ?? 0) + 1;
        }

        // 查库内已存在的 id / name
        $ids = array_keys($idCounts);
        $names = array_keys($nameCounts);
        $idsInDb = []; $namesInDb = [];
        if (count($ids) > 0) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = $db->query("SELECT doll_id FROM dolls WHERE doll_id IN ($ph)", $ids);
            if ($rows) $idsInDb = array_flip(array_column($rows, 'doll_id'));
        }
        if (count($names) > 0) {
            $ph = implode(',', array_fill(0, count($names), '?'));
            $rows = $db->query("SELECT doll_name FROM dolls WHERE doll_name IN ($ph)", $names);
            if ($rows) $namesInDb = array_flip(array_column($rows, 'doll_name'));
        }

        // 过滤出要写入的项，并记录重复原因
        $toWrite = [];
        $skipped_detail = [];
        $fail = 0;
        foreach ($norm as $n) {
            if (!empty($n['invalid'])) {
                $fail++;
                $skipped_detail[] = ['idx'=>$n['idx'],'doll_id'=>$n['doll_id']??null,'doll_name'=>$n['doll_name']??null,'reason'=>$n['reason']];
                continue;
            }
            $reasons = [];
            if (($idCounts[$n['doll_id']] ?? 0) > 1)   $reasons[] = 'doll_id 在本次批次中重复';
            if (($nameCounts[$n['doll_name']] ?? 0) > 1)$reasons[] = '名称在本次批次中重复';
            if (isset($idsInDb[$n['doll_id']]))        $reasons[] = 'doll_id 已存在';
            if (isset($namesInDb[$n['doll_name']]))    $reasons[] = '名称已存在';

            if ($reject_dup && count($reasons) > 0) {
                $skipped_detail[] = ['idx'=>$n['idx'],'doll_id'=>$n['doll_id'],'doll_name'=>$n['doll_name'],'reason'=>implode('；', $reasons)];
            } else {
                $toWrite[] = $n;
            }
        }

        // 写库
        $sql_insert_only = "INSERT INTO dolls (doll_id, doll_name, doll_machine_integral, doll_image, report_status, created_at)
                            VALUES (?, ?, ?, ?, ?, NOW())";
        $sql_upsert = "INSERT INTO dolls (doll_id, doll_name, doll_machine_integral, doll_image, report_status, created_at)
                       VALUES (?, ?, ?, ?, ?, NOW())
                       ON DUPLICATE KEY UPDATE
                         doll_name = VALUES(doll_name),
                         doll_machine_integral = VALUES(doll_machine_integral),
                         doll_image = VALUES(doll_image),
                         report_status = VALUES(report_status)";
        $sql_update_only = "UPDATE dolls SET doll_name=?, doll_machine_integral=?, doll_image=?, report_status=? WHERE doll_id=?";

        $ok = 0;
        try {
            $db->beginTransaction();
            foreach ($toWrite as $n) {
                if ($mode === 'insert_only') {
                    $aff = $db->query($sql_insert_only, [$n['doll_id'],$n['doll_name'],$n['doll_machine_integral'],$n['doll_image'],$n['report_status']], true);
                } elseif ($mode === 'update_only') {
                    $aff = $db->query($sql_update_only, [$n['doll_name'],$n['doll_machine_integral'],$n['doll_image'],$n['report_status'],$n['doll_id']], true);
                } else {
                    $aff = $db->query($sql_upsert, [$n['doll_id'],$n['doll_name'],$n['doll_machine_integral'],$n['doll_image'],$n['report_status']], true);
                }
                if ($aff === false) {
                    $skipped_detail[] = ['idx'=>$n['idx'],'doll_id'=>$n['doll_id'],'doll_name'=>$n['doll_name'],'reason'=>'执行失败'];
                } else {
                    $ok++;
                }
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            $db->logToFile("save 异常: ".$e->getMessage());
            respond(500, '保存失败（事务已回滚）');
        }

        $skipped = count($skipped_detail);

        // 如果是单条且重复导致未写入，给 409
        if (count($items) === 1 && $ok === 0 && $skipped > 0 && $fail === 0) {
            respond(409, '存在重复，未录入', [
                'ok'=>$ok, 'skipped'=>$skipped, 'skipped_detail'=>$skipped_detail,
                'mode'=>$mode, 'auto_used'=>$auto_name?1:0
            ]);
        }

        respond(0, '保存完成', [
            'ok'=>$ok, 'skipped'=>$skipped, 'skipped_detail'=>$skipped_detail,
            'mode'=>$mode, 'auto_used'=>$auto_name?1:0,
            'start_no'=>$start_no, 'pad_len'=>$pad_len, 'name_prefix'=>$name_prefix
        ]);
    }

    case 'update': {
        $payload = read_json_body() ?: $_POST;
        $id = $payload['id'] ?? null;
        if ($id === null) respond(1005, '缺少参数：id');

        // 可选：更新时也做名称唯一校验
        $reject_dup = intval(get_param('reject_duplicates', 0)) === 1;
        if ($reject_dup && isset($payload['doll_name'])) {
            $name = strval($payload['doll_name']);
            $rows = $db->query("SELECT id FROM dolls WHERE doll_name = ? AND id <> ? LIMIT 1", [$name, strval($id)]);
            if ($rows && count($rows)>0) {
                respond(409, '名称已存在，未保存', [
                    'skipped_detail' => [['idx'=>0,'doll_id'=>null,'doll_name'=>$name,'reason'=>'名称已存在']]
                ]);
            }
        }

        $data = pick_fields($payload, $GLOBALS['FIELDS']);
        if (empty($data)) respond(1006, '无可更新字段');

        $sets = []; $params = [];
        foreach ($data as $k => $v) {
            $sets[] = "{$k} = ?";
            if ($k === 'doll_machine_integral' || $k === 'report_status') $params[] = strval(intval($v));
            else $params[] = strval($v);
        }
        $params[] = strval($id);

        $sql = "UPDATE dolls SET " . implode(', ', $sets) . " WHERE id = ? LIMIT 1";
        $aff = $db->query($sql, $params, true);
        if ($aff === false) respond(500, '更新失败');
        respond(0, '更新成功', ['affected' => $aff]);
    }

    case 'delete': {
        $id = get_param('id', null);
        if ($id === null) respond(1007, '缺少参数：id');
        $aff = $db->query("DELETE FROM dolls WHERE id = ? LIMIT 1", [strval($id)], true);
        if ($aff === false) respond(500, '删除失败');
        respond(0, '删除成功', ['affected' => $aff]);
    }

    default:
        respond(400, '未知 action');
}
