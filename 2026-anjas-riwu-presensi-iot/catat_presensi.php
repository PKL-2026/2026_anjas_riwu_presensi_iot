<?php
/**
 * catat_presensi.php — Endpoint pencatatan kehadiran dari ESP32
 * 
 * POST finger_id=X 
 * Logic Tambahan Kamis:
 * 1st tap = Masuk
 * 2nd tap = Pulang
 * 3rd tap = Sudah presensi (no db insert)
 */
require_once 'koneksi.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["status" => "error", "msg" => "Method not allowed"]);
    exit;
}

$finger_id = $_POST['finger_id'] ?? '';

if (empty($finger_id)) {
    echo json_encode(["status" => "error", "msg" => "finger_id required"]);
    exit;
}

// Lookup nama siswa dari database
$stmt = $pdo->prepare("SELECT nama, status FROM siswa WHERE id = ?");
$stmt->execute([$finger_id]);
$siswa = $stmt->fetch();

if (!$siswa) {
    echo json_encode(["status" => "tidak_dikenal"]);
    exit;
}

if ($siswa['status'] === 'terkunci') {
    echo json_encode(["status" => "tidak_dikenal"]);
    exit;
}

// Ambil pengaturan API dari DB
$gsheet_url = '';
$wa_token = '';
$wa_target = '';
$stmtCfg = $pdo->query("SELECT config_key, config_value FROM pengaturan WHERE config_key IN ('gsheet_url', 'wa_api_key', 'wa_grup_id')");
while ($row = $stmtCfg->fetch()) {
    if ($row['config_key'] == 'gsheet_url') $gsheet_url = $row['config_value'];
    if ($row['config_key'] == 'wa_api_key') $wa_token = $row['config_value'];
    if ($row['config_key'] == 'wa_grup_id') $wa_target = $row['config_value'];
}

// Helper untuk mengirim ke WA Fonnte
function kirimNotifWA($token, $target, $namaSiswa) {
    if (empty($token) || empty($target)) return;
    
    $waktu = date('d M Y H:i');
    $pesan = "*Selamat*, *$namaSiswa* telah berhasil presensi \n WAKTU : $waktu.\n ";
    
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://api.fonnte.com/send',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 3, // Timeout cepat agar ESP32 tidak menunggu
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_POSTFIELDS => array(
          'target' => $target,
          'message' => $pesan, 
          'countryCode' => '62',
      ),
      CURLOPT_HTTPHEADER => array(
        "Authorization: $token"
      ),
    ));
    curl_exec($curl);
    curl_close($curl);
}

// Helper untuk mengirim ke GSheet
function kirimKeGsheet($url, $nama, $finger_id, $type) {
    if (empty($url)) return;
    
    $data = [
        'nama' => $nama,
        'finger_id' => $finger_id,
        'type' => $type,
        'waktu' => date('Y-m-d H:i:s')
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3); // Timeout cepat agar ESP32 tidak menunggu terlalu lama
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_exec($ch);
    curl_close($ch);
}

// Cek presensi hari ini
$today = date('Y-m-d');
$stmtCek = $pdo->prepare("SELECT type FROM presensi WHERE finger_id = ? AND DATE(waktu_hadir) = ? ORDER BY waktu_hadir ASC");
$stmtCek->execute([$finger_id, $today]);
$records = $stmtCek->fetchAll();

$hasMasuk = false;
$hasPulang = false;

foreach ($records as $r) {
    if ($r['type'] === 'masuk') $hasMasuk = true;
    if ($r['type'] === 'pulang') $hasPulang = true;
}

$status = "";

if (!$hasMasuk) {
    // Absen Masuk (1st tap)
    $stmtInsert = $pdo->prepare("INSERT INTO presensi (finger_id, nama_siswa, waktu_hadir, type) VALUES (?, ?, NOW(), 'masuk')");
    $stmtInsert->execute([$finger_id, $siswa['nama']]);
    $status = "masuk";
    
    // Integrasi GSheet untuk 'masuk'
    kirimKeGsheet($gsheet_url, $siswa['nama'], $finger_id, 'masuk');
    
    // Integrasi Notifikasi WA
    kirimNotifWA($wa_token, $wa_target, $siswa['nama']);
    
} else if (!$hasPulang) {
    // Absen Pulang (2nd tap)
    $stmtInsert = $pdo->prepare("INSERT INTO presensi (finger_id, nama_siswa, waktu_hadir, type) VALUES (?, ?, NOW(), 'pulang')");
    $stmtInsert->execute([$finger_id, $siswa['nama']]);
    $status = "pulang";
    
    // Integrasi GSheet untuk 'pulang'
    kirimKeGsheet($gsheet_url, $siswa['nama'], $finger_id, 'pulang');
    
} else {
    // Sudah presensi (3rd tap+)
    $status = "sudah";
}

echo json_encode([
    "status" => $status,
    "nama" => $siswa['nama']
]);
