<?php
date_default_timezone_set('Asia/Jakarta');
$host    = 'db';
$db      = 'databases_2026_anjas_riwu_presensi_iot'; // Sesuaikan dengan database barumu
$user    = 'root';                                   // Pakai root
$pass    = 'root';                                   // Password root
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}
$pdo->exec("SET time_zone = '+07:00'");
?>
