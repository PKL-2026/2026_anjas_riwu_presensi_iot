<?php
/**
 * cek_antrian.php — Endpoint polling antrian enrollment untuk ESP32
 * 
 * GET → Return antrian pending pertama sebagai JSON
 *       Jika ada: {"enroll": true, "finger_id": "X", "antrian_id": N}
 *       Jika tidak: {"enroll": false}
 */
require_once 'koneksi.php';

header('Content-Type: application/json');

$stmt = $pdo->query("SELECT id, finger_id FROM antrian_enroll WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1");
$row = $stmt->fetch();

if ($row) {
    // Ubah status menjadi processing agar tidak di-poll ulang
    $update = $pdo->prepare("UPDATE antrian_enroll SET status = 'processing' WHERE id = ?");
    $update->execute([$row['id']]);
    
    echo json_encode([
        "enroll" => true,
        "finger_id" => $row['finger_id'],
        "antrian_id" => (int)$row['id']
    ]);
} else {
    echo json_encode(["enroll" => false]);
}
