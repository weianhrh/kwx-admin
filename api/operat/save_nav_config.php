<?php
$data = file_get_contents("php://input");
$json = json_decode($data, true);

if (!isset($json['navItems']) || !isset($json['navItems2'])) {
  echo json_encode(['code' => 1, 'msg' => '格式错误']);
  exit;
}

file_put_contents(__DIR__ . '/../../res/navItems.json', json_encode($json['navItems'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
file_put_contents(__DIR__ . '/../../res/navItems2.json', json_encode($json['navItems2'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode(['code' => 0, 'msg' => '保存成功']);
