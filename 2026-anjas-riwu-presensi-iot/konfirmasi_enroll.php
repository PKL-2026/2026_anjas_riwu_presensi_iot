<?php
/**
 * konfirmasi_enroll.php — ESP32 konfirmasi enrollment selesai
 * 
 * POST finger_id=X&status=done    → Enrollment berhasil
 * POST finger_id=X&status=failed  → Enrollment gagal
 */
require_once 'koneksi.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

$finger_id = $_POST['finger_id'] ?? '';
$status = $_POST['status'] ?? 'done';

if (empty($finger_id)) {
    echo json_encode(["error" => "finger_id required"]);
    exit;
}

if ($status === 'done') {
    // Enrollment berhasil: update siswa menjadi terbuka
    $stmt = $pdo->prepare("UPDATE siswa SET status = 'terbuka' WHERE id = ?");
    $stmt->execute([$finger_id]);
    
    // Update antrian menjadi done
    $stmt2 = $pdo->prepare("UPDATE antrian_enroll SET status = 'done' WHERE finger_id = ? AND status = 'processing'");
    $stmt2->execute([$finger_id]);
    
    echo json_encode(["result" => "ok", "message" => "Enrollment berhasil, status terbuka"]);
} else {
    // Enrollment gagal: kembalikan antrian ke pending atau set failed
    $stmt = $pdo->prepare("UPDATE antrian_enroll SET status = 'failed' WHERE finger_id = ? AND status = 'processing'");
    $stmt->execute([$finger_id]);
    
    echo json_encode(["result" => "failed", "message" => "Enrollment gagal"]);
}
