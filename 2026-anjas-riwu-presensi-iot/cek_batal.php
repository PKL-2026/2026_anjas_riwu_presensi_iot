<?php
/**
 * cek_batal.php — Endpoint mengecek apakah admin membatalkan pendaftaran
 */
require_once 'koneksi.php';

header('Content-Type: application/json');

$finger_id = $_GET['finger_id'] ?? '';

if (empty($finger_id)) {
    echo json_encode(["cancelled" => true]);
    exit;
}

// Jika row tidak ada, berarti sudah dibatalkan (dihapus) oleh admin
$stmt = $pdo->prepare("SELECT id FROM antrian_enroll WHERE finger_id = ? AND status = 'processing'");
$stmt->execute([$finger_id]);
$row = $stmt->fetch();

echo json_encode(["cancelled" => !$row]);
