<?php
require_once '../Database.php';
$database = new Database();

$zone_id = $_GET['zone_id'] ?? 0;
$query = "SELECT id, venue_name,image_url, start_time, queue_length, zone_id FROM venues WHERE zone_id = ?";
$stmt = $database->prepare($query);
$stmt->bind_param("i", $zone_id);
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_all(MYSQLI_ASSOC);

echo json_encode(['code' => 0, 'data' => $data]);
