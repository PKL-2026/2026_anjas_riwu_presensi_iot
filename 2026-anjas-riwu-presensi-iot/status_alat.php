<?php
/**
 * status_alat.php — Heartbeat endpoint untuk ESP32
 * 
 * ESP32 POST heartbeat=1 → update timestamp
 * Web GET → return JSON {"online": true/false, "last": "timestamp", "ip": "..."}
 */
require_once 'koneksi.php';

header('Content-Type: application/json');

// ESP32 mengirim heartbeat
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['heartbeat'])) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $pdo->prepare("UPDATE status_alat SET last_heartbeat = NOW(), ip_address = ? WHERE id = 1");
    $stmt->execute([$ip]);
    
    // Ambil timestamp update jadwal terakhir
    $stmtJadwal = $pdo->query("SELECT config_value FROM pengaturan WHERE config_key = 'jadwal_last_update'");
    $row = $stmtJadwal->fetch();
    $jadwal_update = $row ? $row['config_value'] : "0";

    echo json_encode(["status" => "ok", "jadwal_update" => $jadwal_update]);
    exit;
}

// Web mengecek status alat
$stmt = $pdo->query("SELECT last_heartbeat, ip_address FROM status_alat WHERE id = 1");
$row = $stmt->fetch();

$online = false;
$last = null;
$ip = null;

if ($row && $row['last_heartbeat']) {
    $last = $row['last_heartbeat'];
    $ip = $row['ip_address'];
    $diff = time() - strtotime($last);
    $online = ($diff < 15); // Online jika heartbeat < 15 detik yang lalu
}

echo json_encode([
    "online" => $online,
    "last" => $last,
    "ip" => $ip
]);
