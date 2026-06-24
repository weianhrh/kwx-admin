<?php
header('Content-Type: application/json');

$path = $_POST['path'] ?? '';
$content = $_POST['content'] ?? '';

if (!$path || !$content) {
  echo json_encode(['code' => 1, 'msg' => '缺少参数']);
  exit;
}

// 校验合法路径（只允许写 iframe/link 目录）
if (!preg_match('#^/iframe/link/[\w-]+\.html$#', $path)) {
  echo json_encode(['code' => 2, 'msg' => '路径非法']);
  exit;
}

// 写入文件
$root = dirname(__DIR__, 2) . '/res/views';
$fullPath = $root . $path;
@mkdir(dirname($fullPath), 0777, true);

if (file_put_contents($fullPath, $content) !== false) {
  echo json_encode(['code' => 0, 'msg' => '创建成功']);
} else {
  echo json_encode(['code' => 3, 'msg' => '写入失败']);
}
