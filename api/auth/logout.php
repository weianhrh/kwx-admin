<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

auth_json_headers();
auth_handle_options();

// 多端共用同一个 Token：普通退出只删除当前浏览器 Cookie，不能清空数据库 Token，
// 否则其中一个人退出会让同账号的其他浏览器全部掉线。
auth_clear_cookie();
auth_out(0, '已退出当前设备');
