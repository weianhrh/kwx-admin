<?php
// /api/operat/points_mall.php  （现在操作 points_mall_h5）
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../Database.php';
$db = new Database();

function resp($code, $msg, $data = []) {
    echo json_encode(['code'=>$code,'msg'=>$msg,'data'=>$data], JSON_UNESCAPED_UNICODE);
    exit;
}
function g($k,$d=null){ return $_GET[$k] ?? $_POST[$k] ?? $d; }
function i($v){ return (int)$v; }
function s($v){ return trim((string)$v); }
function mb_len($str){ return function_exists('mb_strlen') ? mb_strlen($str,'UTF-8') : strlen($str); }

/* 登录校验 */
$token = $_COOKIE['session_token'] ?? null;
if (!$token) resp(1001,'用户未登录或会话已过期');
$user = $db->getUserBySessionToken($token);
if (!$user || empty($user['role_id'])) resp(1001,'用户未登录或无权访问');

$TABLE = 'points_mall_h5'; // ✅ 新表名

/* 字段白名单（和你的新表一致） */
$FIELDS = [
  'product_category','product_name','price','inventory','exchange_value','is_display',
  'product_introduce','product_image',
  'product_introduce_image1','product_introduce_image2',
  'product_introduce_image3','product_introduce_image4'
];

/* 统一校验函数（按你的列约束写死长度与语义） */
function validate_payload($data, $isUpdate=false){
    // 长度限制：和当前表保持一致
    $limit255 = ['product_category','product_name','product_image',
                 'product_introduce_image1','product_introduce_image2',
                 'product_introduce_image3','product_introduce_image4'];
    foreach($limit255 as $k){
        if (isset($data[$k]) && mb_len($data[$k]) > 255) {
            resp(1002, "$k 长度不能超过 255");
        }
    }
    if (isset($data['product_introduce']) && mb_len($data['product_introduce']) > 500) {
        resp(1002, "product_introduce 长度不能超过 500");
    }

    // price: DECIMAL(10,0) 当整数
    if (isset($data['price'])) {
        if (!is_numeric($data['price'])) resp(1002,'price 必须为数字(整数)');
        $data['price'] = (string)i($data['price']);
        if (i($data['price']) < 0) resp(1002,'price 不能小于 0');
    }

    // inventory: int
    if (isset($data['inventory'])) {
        if (!is_numeric($data['inventory'])) resp(1002,'inventory 必须为数字(整数)');
        $data['inventory'] = (string)i($data['inventory']);
        if (i($data['inventory']) < 0) resp(1002,'inventory 不能小于 0');
    }

    // ✅ exchange_value: int（你的注释：需要消耗/兑换的娃娃数量）
    if (isset($data['exchange_value'])) {
        if (!is_numeric($data['exchange_value'])) resp(1002,'exchange_value 必须为数字(整数)');
        $data['exchange_value'] = (string)i($data['exchange_value']);
        if (i($data['exchange_value']) < 0) resp(1002,'exchange_value 不能小于 0');
    }

    // is_display: 0=显示, 1=隐藏
    if (isset($data['is_display'])) {
        $v = i($data['is_display']); $v = ($v===0)?0:1;
        $data['is_display'] = (string)$v;
    }

    // 必填（新增时）
    if (!$isUpdate) {
        if (!isset($data['product_name']) || s($data['product_name'])==='') resp(1002,'product_name 必填');
        if (!isset($data['price'])) resp(1002,'price 必填');
        if (!isset($data['inventory'])) resp(1002,'inventory 必填');

        // ✅ 新字段必填：若前端不传，默认 1（你的表默认也是 1）
        if (!isset($data['exchange_value'])) $data['exchange_value'] = '1';

        if (!isset($data['is_display'])) $data['is_display'] = '0';
    }

    return $data;
}

/* 应用层唯一性：同分类下 product_name 不重复（若无分类则全局不重复） */
function check_unique_name($db, $table, $name, $category, $excludeId = null){
    $sql  = "SELECT id FROM {$table} WHERE product_name = ? ";
    $args = [$name];
    if ($category !== null && $category !== '') {
        $sql .= " AND product_category = ? ";
        $args[] = $category;
    }
    if ($excludeId) { $sql .= " AND id <> ? "; $args[] = (string)$excludeId; }
    $sql .= " LIMIT 1";
    $row = $db->query($sql, $args);
    return empty($row); // true=可用
}

$action = g('action','list');

try{
    switch($action){

        /* 列表 */
        case 'list': {
            $page    = max(1, i(g('page',1)));
            $size    = min(100, max(1, i(g('page_size',10))));
            $offset  = ($page-1)*$size;

            $where = ['1=1'];
            $args  = [];

            if (($kw=s(g('keyword','')))!==''){
                $where[] = '(product_name LIKE ? OR product_category LIKE ?)';
                $args[] = '%'.$kw.'%'; $args[] = '%'.$kw.'%';
            }
            if (($cat=s(g('category','')))!==''){ $where[]='product_category = ?'; $args[]=$cat; }
            if (($disp=g('is_display',null))!==null && $disp!==''){
                $where[]='is_display = ?'; $args[] = (string) (i($disp)===0?0:1);
            }

            $orderBy = g('order_by','id');
            $orderDir= strtolower(g('order_dir','desc'))==='asc'?'ASC':'DESC';
            $whitelist = ['id','price','inventory','exchange_value','created_at']; // ✅ 加入 exchange_value
            if (!in_array($orderBy,$whitelist,true)) $orderBy = 'id';

            $cnt = $db->query("SELECT COUNT(*) c FROM {$TABLE} WHERE ".implode(' AND ',$where), $args);
            $total = $cnt? (int)$cnt[0]['c'] : 0;

            $sql = "SELECT id,product_category,product_name,price,inventory,exchange_value,is_display,
                           product_introduce,product_image,
                           product_introduce_image1,product_introduce_image2,product_introduce_image3,product_introduce_image4,
                           created_at
                    FROM {$TABLE}
                    WHERE ".implode(' AND ',$where)."
                    ORDER BY $orderBy $orderDir
                    LIMIT $size OFFSET $offset";
            $items = $db->query($sql,$args) ?: [];
            resp(0,'ok',['page'=>$page,'page_size'=>$size,'total'=>$total,'items'=>$items]);
        }

        /* 详情 */
        case 'detail': {
            $id = i(g('id'));
            if ($id<=0) resp(1002,'缺少或非法的 id');
            $row = $db->query("SELECT * FROM {$TABLE} WHERE id = ? LIMIT 1",[ (string)$id ]);
            if (!$row) resp(1004,'记录不存在');
            resp(0,'ok',$row[0]);
        }

        /* 新增 */
        case 'create': {
            $payload = [];
            foreach ($FIELDS as $f) { if (isset($_POST[$f]) || isset($_GET[$f])) $payload[$f]=s(g($f)); }
            $payload = validate_payload($payload, false);

            $cat = $payload['product_category'] ?? '';
            if (!check_unique_name($db, $TABLE, $payload['product_name'], $cat, null)) {
                resp(2001, '同分类下已存在同名商品');
            }

            // INSERT（created_at 用默认 CURRENT_TIMESTAMP）
            $cols=[]; $qs=[]; $vals=[];
            foreach ($payload as $k=>$v){ $cols[]=$k; $qs[]='?'; $vals[]=(string)$v; }
            $sql = "INSERT INTO {$TABLE} (".implode(',',$cols).") VALUES (".implode(',',$qs).")";
            $aff = $db->query($sql,$vals,true);
            if ($aff===false) resp(1003,'新增失败');
            $newId = $db->getConnection()->insert_id;
            resp(0,'新增成功',['id'=>$newId]);
        }

        /* 更新 */
        case 'update': {
            $id = i(g('id'));
            if ($id<=0) resp(1002,'缺少或非法的 id');

            $payload = [];
            foreach ($FIELDS as $f) { if (isset($_POST[$f]) || isset($_GET[$f])) $payload[$f]=s(g($f)); }
            if (empty($payload)) resp(1002,'没有需要更新的字段');
            $payload = validate_payload($payload, true);

            // 唯一性（排除自己）
            if (isset($payload['product_name'])) {
                $cat = $payload['product_category'] ?? null;
                if ($cat===null){
                    $row = $db->query("SELECT product_category FROM {$TABLE} WHERE id=? LIMIT 1",[ (string)$id ]);
                    $cat = $row ? $row[0]['product_category'] : '';
                }
                if (!check_unique_name($db, $TABLE, $payload['product_name'], $cat, $id)) {
                    resp(2001, '同分类下已存在同名商品');
                }
            }

            $sets=[]; $vals=[];
            foreach ($payload as $k=>$v){ $sets[]="$k = ?"; $vals[]=(string)$v; }
            $vals[] = (string)$id;
            $sql = "UPDATE {$TABLE} SET ".implode(',',$sets)." WHERE id = ? LIMIT 1";
            $aff = $db->query($sql,$vals,true);
            if ($aff===false) resp(1003,'更新失败');
            resp(0,'更新成功',['affected'=>$aff]);
        }

        /* 删除（支持批量） */
        case 'delete': {
            $idsRaw = g('ids', g('id',''));
            $ids=[];
            if (is_array($idsRaw)) { foreach($idsRaw as $x){ $x=i($x); if($x>0)$ids[]=$x; } }
            else { foreach(explode(',', (string)$idsRaw) as $x){ $x=i($x); if($x>0)$ids[]=$x; } }
            $ids = array_values(array_unique($ids));
            if (!$ids) resp(1002,'缺少或非法的 id/ids');

            $ph = implode(',', array_fill(0,count($ids),'?'));
            $params = array_map('strval',$ids);

            $db->beginTransaction();
            try{
                $aff = $db->query("DELETE FROM {$TABLE} WHERE id IN ($ph)", $params, true);
                if ($aff===false) throw new Exception('删除失败');
                $db->commit();
                resp(0,'删除成功',['affected'=>$aff]);
            }catch(Throwable $e){
                $db->rollBack();
                $db->logToFile("points_mall_h5 delete error: ".$e->getMessage());
                resp(1003,'删除失败');
            }
        }

        /* 显隐切换（0=显示,1=隐藏） */
        case 'toggle_display': {
            $id = i(g('id')); $v = i(g('is_display',1))?1:0;
            if ($id<=0) resp(1002,'缺少或非法的 id');
            $aff = $db->query("UPDATE {$TABLE} SET is_display=? WHERE id=? LIMIT 1",[ (string)$v, (string)$id ], true);
            if ($aff===false) resp(1003,'更新失败');
            resp(0,'更新成功',['affected'=>$aff]);
        }

        default: resp(1002,'未知 action');
    }
} catch(Throwable $e){
    $db->logToFile("points_mall_h5 api error: ".$e->getMessage());
    resp(1005,'服务器异常');
}
