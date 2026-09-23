<?php
// Endpoint untuk AJAX dari Web guna mengecek status antrian_enroll 
// Apakah ESP32 sudah menyelesaikan enroll (status berubah jadi 'done' atau 'failed')
date_default_timezone_set('Asia/Jakarta');
require_once 'koneksi.php';

header('Content-Type: application/json');

if (!isset($_GET['finger_id'])) {
    echo json_encode(['error' => 'No finger_id provided']);
    exit;
}

$finger_id = $_GET['finger_id'];

$stmt = $pdo->prepare("SELECT status FROM antrian_enroll WHERE finger_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$finger_id]);
$row = $stmt->fetch();

if ($row) {
    echo json_encode(['status' => $row['status']]);
} else {
    // Jika data tidak ada (mungkin terhapus), anggap not_found
    echo json_encode(['status' => 'not_found']);
}
