<?php
date_default_timezone_set('Asia/Jakarta');
require_once 'koneksi.php';

// -- Auto-create tabel pengaturan jika belum ada (Task Thursday) --
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `pengaturan` (
        `config_key` VARCHAR(50) NOT NULL PRIMARY KEY,
        `config_value` TEXT DEFAULT NULL,
        `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    // Insert default keys jika belum ada
    $pdo->exec("INSERT IGNORE INTO `pengaturan` (`config_key`, `config_value`) VALUES
        ('wa_nomor', ''), ('wa_api_key', ''), ('wa_grup_id', ''), ('gsheet_url', ''),
        ('jadwal_masuk', '07:00'), ('jadwal_pulang', '14:00')");
} catch (Exception $e) {
    // Gagal create tabel, abaikan
}

// -- Tambahan Kamis: Alter presensi table for type --
try {
    $pdo->exec("ALTER TABLE `presensi` ADD COLUMN `type` ENUM('masuk','pulang') NOT NULL DEFAULT 'masuk'");
} catch (Exception $e) {
    // Kolom mungkin sudah ada, abaikan
}

// ============================================================
// 1. ENDPOINT HTTP POST DARI ESP32 (Tanpa session browser)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['nama']) && !isset($_POST['action'])) {
    $waktu_sekarang = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO siswa (id, nama, status, waktu) VALUES (?, ?, 'terkunci', ?) 
                           ON DUPLICATE KEY UPDATE nama = VALUES(nama), waktu = ?");
    $stmt->execute([$_POST['id'], $_POST['nama'], $waktu_sekarang, $waktu_sekarang]);
    echo "OK";
    exit;
}

// ============================================================
// 2. PROTEKSI SESSION ADMIN
// ============================================================
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$admin_id = $_SESSION['admin_id'] ?? 1;

// ============================================================
// 3. HANDLE AKSI BACKEND
// ============================================================
$flash_msg = '';
$flash_type = '';

$action = $_POST['action'] ?? '';

// -- AJAX: Mulai Enroll (Bikin Antrian) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'mulai_enroll_ajax') {
    header('Content-Type: application/json');
    $id = trim($_POST['finger_id'] ?? '');
    if (!empty($id)) {
        // Hapus antrian lama jika ada yang nyangkut
        $pdo->prepare("DELETE FROM antrian_enroll WHERE finger_id = ?")->execute([$id]);
        
        $stmt = $pdo->prepare("INSERT INTO antrian_enroll (finger_id, status) VALUES (?, 'pending')");
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success']);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'ID kosong']);
    }
    exit;
}

// -- AJAX: Batal Enroll (Timeout/Cancel) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'batal_enroll_ajax') {
    $id = trim($_POST['finger_id'] ?? '');
    if (!empty($id)) {
        $pdo->prepare("DELETE FROM antrian_enroll WHERE finger_id = ? AND status IN ('pending', 'processing')")->execute([$id]);
    }
    exit;
}

// -- Update Profil --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_profil') {
    $nama_lengkap = trim($_POST['nama_lengkap'] ?? '');
    
    // Handle Upload Foto
    $foto_profil = null;
    if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] === UPLOAD_ERR_OK) {
        $tmp_name = $_FILES['foto_profil']['tmp_name'];
        $name = basename($_FILES['foto_profil']['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (in_array($ext, $allowed)) {
            $new_name = 'admin_' . time() . '.' . $ext;
            $upload_dir = 'uploads/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            if (move_uploaded_file($tmp_name, $upload_dir . $new_name)) {
                $foto_profil = $new_name;
            }
        }
    }

    if (!empty($nama_lengkap)) {
        if ($foto_profil) {
            $stmt = $pdo->prepare("UPDATE users SET nama_lengkap = ?, foto_profil = ? WHERE id = ?");
            $stmt->execute([$nama_lengkap, $foto_profil, $admin_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET nama_lengkap = ? WHERE id = ?");
            $stmt->execute([$nama_lengkap, $admin_id]);
        }
        $flash_msg = "Profil berhasil diperbarui!";
        $flash_type = 'success';
        header("Location: index.php?page=dashboard&msg=" . urlencode($flash_msg) . "&type=success");
        exit;
    }
}

// -- Reset Profil (Hapus Foto) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reset_profil') {
    $stmt = $pdo->prepare("SELECT foto_profil FROM users WHERE id = ?");
    $stmt->execute([$admin_id]);
    $u = $stmt->fetch();
    if ($u && $u['foto_profil']) {
        @unlink('uploads/' . $u['foto_profil']);
        $pdo->prepare("UPDATE users SET foto_profil = NULL WHERE id = ?")->execute([$admin_id]);
    }
    $flash_msg = "Foto profil berhasil di-reset ke setelan bawaan.";
    $flash_type = 'success';
    header("Location: index.php?page=dashboard&msg=" . urlencode($flash_msg) . "&type=success");
    exit;
}

// -- Tambah Catatan Pribadi --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'tambah_catatan') {
    $judul = trim($_POST['judul'] ?? '');
    $isi = trim($_POST['isi'] ?? '');
    if (!empty($judul) && !empty($isi)) {
        $waktu_sekarang = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO catatan (judul, isi, created_at) VALUES (?, ?, ?)");
        $stmt->execute([$judul, $isi, $waktu_sekarang]);
        $flash_msg = "Catatan berhasil ditambahkan!";
        $flash_type = 'success';
        header("Location: index.php?page=dashboard&msg=" . urlencode($flash_msg) . "&type=success");
        exit;
    }
}

// -- Edit Catatan --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_catatan') {
    $id = $_POST['catatan_id'] ?? '';
    $judul = trim($_POST['judul'] ?? '');
    $isi = trim($_POST['isi'] ?? '');
    if (!empty($id) && !empty($judul) && !empty($isi)) {
        $stmt = $pdo->prepare("UPDATE catatan SET judul = ?, isi = ? WHERE id = ?");
        $stmt->execute([$judul, $isi, $id]);
        $flash_msg = "Catatan berhasil diperbarui!";
        $flash_type = 'success';
        header("Location: index.php?page=dashboard&msg=" . urlencode($flash_msg) . "&type=success");
        exit;
    }
}

// -- AJAX: Simpan Jadwal Masuk / Pulang (Tambahan Kamis) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'simpan_jadwal_ajax') {
    header('Content-Type: application/json');
    $jenis = $_POST['jenis'] ?? ''; // 'masuk' atau 'pulang'
    $waktu = $_POST['waktu'] ?? '';
    if (in_array($jenis, ['masuk', 'pulang']) && !empty($waktu)) {
        $key = 'jadwal_' . $jenis;
        $stmt = $pdo->prepare("UPDATE pengaturan SET config_value = ? WHERE config_key = ?");
        $stmt->execute([$waktu, $key]);
        $pdo->query("INSERT INTO pengaturan (config_key, config_value) VALUES ('jadwal_last_update', '".time()."') ON DUPLICATE KEY UPDATE config_value = '".time()."'");
        echo json_encode(['status' => 'success', 'msg' => "Jadwal $jenis berhasil disimpan!"]);
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Data tidak valid']);
    }
    exit;
}

// -- Hapus Catatan --
if (isset($_GET['hapus_catatan'])) {
    $stmt = $pdo->prepare("DELETE FROM catatan WHERE id = ?");
    $stmt->execute([$_GET['hapus_catatan']]);
    header('Location: index.php?page=dashboard&msg=' . urlencode("Catatan berhasil dihapus.") . '&type=success');
    exit;
}

// -- Tambah Manual (Mode Pendaftaran -> Simpan ID Hantu) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'tambah_manual') {
    $id = trim($_POST['tambah_id'] ?? '');
    $nama = trim($_POST['tambah_nama'] ?? '');
    $id_num = intval($id);
    if ($id_num < 1) $id_num = 1;
    if ($id_num > 51) $id_num = 51;
    $id = (string)$id_num;

    if (!empty($id) && !empty($nama)) {
        $cek = $pdo->prepare("SELECT id FROM siswa WHERE id = ?");
        $cek->execute([$id]);
        if ($cek->fetch()) {
            echo "<script>alert('Sudah terdaftar');</script>";
            $flash_msg = "Gagal! ID #$id sudah terdaftar.";
            $flash_type = 'error';
        } else {
            $waktu_sekarang = date('Y-m-d H:i:s');
            $stmt = $pdo->prepare("INSERT INTO siswa (id, nama, status, waktu) VALUES (?, ?, 'terkunci', ?)");
            $stmt->execute([$id, $nama, $waktu_sekarang]);
            $flash_msg = "Berhasil! ID #$id ($nama) ditambahkan sebagai ID tanpa sidik jari  (terkunci)";
            header('Location: index.php?page=' . urlencode($page) . '&msg=' . urlencode($flash_msg) . '&type=success');
            exit;
        }
    }
}

// -- Tambah Auto-Save (Hasil Enroll Asli) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'tambah_real') {
    $id = trim($_POST['tambah_id'] ?? '');
    $nama = trim($_POST['tambah_nama'] ?? '');
    $id_num = intval($id);
    if ($id_num < 1) $id_num = 1;
    if ($id_num > 51) $id_num = 51;
    $id = (string)$id_num;

    if (!empty($id) && !empty($nama)) {
        // Hapus antrian
        $pdo->prepare("DELETE FROM antrian_enroll WHERE finger_id = ?")->execute([$id]);
        
        $waktu_sekarang = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare("INSERT INTO siswa (id, nama, status, waktu) VALUES (?, ?, 'terbuka', ?) ON DUPLICATE KEY UPDATE nama = VALUES(nama), status = 'terbuka', waktu = ?");
        $stmt->execute([$id, $nama, $waktu_sekarang, $waktu_sekarang]);
        $flash_msg = "Luar Biasa! Sidik Jari Asli untuk ID #$id ($nama) telah tersimpan permanen 🔓";
        header('Location: index.php?page=' . urlencode($page) . '&msg=' . urlencode($flash_msg) . '&type=success');
        exit;
    }
}

// -- Edit Nama Siswa --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_siswa') {
    $stmt = $pdo->prepare("UPDATE siswa SET nama = ? WHERE id = ?");
    $stmt->execute([$_POST['edit_nama'], $_POST['edit_id']]);
    $flash_msg = "Nama siswa ID #" . $_POST['edit_id'] . " berhasil diubah.";
    $flash_type = 'success';
    header('Location: index.php?page=kelola&msg=' . urlencode($flash_msg) . '&type=success');
    exit;
}

// -- Hapus Siswa --
if (isset($_GET['hapus_siswa'])) {
    $stmt = $pdo->prepare("DELETE FROM siswa WHERE id = ?");
    $stmt->execute([$_GET['hapus_siswa']]);
    header('Location: index.php?page=kelola&msg=' . urlencode("Siswa ID #" . $_GET['hapus_siswa'] . " berhasil dihapus.") . '&type=success');
    exit;
}

// -- Buka Kunci (Insert antrian enroll) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'buka_kunci') {
    $finger_id = $_POST['finger_id'] ?? '';
    if (!empty($finger_id)) {
        // Hapus sisa antrian
        $pdo->prepare("DELETE FROM antrian_enroll WHERE finger_id = ?")->execute([$finger_id]);
        $stmt = $pdo->prepare("INSERT INTO antrian_enroll (finger_id, status) VALUES (?, 'pending')");
        $stmt->execute([$finger_id]);
        header('Location: index.php?page=kelola&msg=' . urlencode("Antrian enrollment untuk ID #$finger_id dibuat. Alat ESP32 memiliki waktu ~1 Menit untuk enroll.") . '&type=success');
        exit;
    }
}

// -- Tambah Riwayat Manual --
// -- Tambah Riwayat Manual --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'tambah_riwayat') {
    $finger_id = $_POST['riwayat_finger_id'] ?? '';
    $waktu = $_POST['riwayat_waktu'] ?? date('Y-m-d H:i:s');
    
    if (!empty($finger_id)) {
        // Ambil nama siswa
        $siswa = $pdo->prepare("SELECT nama FROM siswa WHERE id = ?");
        $siswa->execute([$finger_id]);
        $s = $siswa->fetch();
        $nama = $s ? $s['nama'] : 'Unknown';
        
        // Ambil tanggal dari input waktu (format YYYY-MM-DD)
        $tanggal = date('Y-m-d', strtotime($waktu));
        
        // Cek riwayat yang sudah ada di tanggal tersebut
        $stmtCek = $pdo->prepare("SELECT type FROM presensi WHERE finger_id = ? AND DATE(waktu_hadir) = ?");
        $stmtCek->execute([$finger_id, $tanggal]);
        $records = $stmtCek->fetchAll();
        
        $hasMasuk = false;
        $hasPulang = false;
        foreach ($records as $r) {
            if ($r['type'] === 'masuk') $hasMasuk = true;
            if ($r['type'] === 'pulang') $hasPulang = true;
        }
        
        if ($hasMasuk && $hasPulang) {
            // Jika sudah ada masuk & pulang
            header('Location: index.php?page=riwayat&msg=' . urlencode("Gagal! Presensi $nama sudah komplit (Masuk & Pulang) di tanggal tersebut.") . '&type=error');
            exit;
        }
        
        // Tentukan tipe yang hilang
        $type = 'masuk';
        if ($hasMasuk && !$hasPulang) $type = 'pulang';
        if (!$hasMasuk && $hasPulang) $type = 'masuk';
        
        $stmt = $pdo->prepare("INSERT INTO presensi (finger_id, nama_siswa, waktu_hadir, type) VALUES (?, ?, ?, ?)");
        $stmt->execute([$finger_id, $nama, $waktu, $type]);
        
        header('Location: index.php?page=riwayat&msg=' . urlencode("Berhasil merekam absen $type untuk $nama.") . '&type=success');
        exit;
    }
}

// -- Edit Riwayat --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'edit_riwayat') {
    $stmt = $pdo->prepare("UPDATE presensi SET waktu_hadir = ? WHERE id = ?");
    $stmt->execute([$_POST['edit_waktu'], $_POST['edit_riwayat_id']]);
    header('Location: index.php?page=riwayat&msg=' . urlencode("Riwayat berhasil diubah.") . '&type=success');
    exit;
}

// -- AJAX: Cek Status Fonnte --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'cek_fonnte_ajax') {
    $token = '';
    $stmtCfg = $pdo->query("SELECT config_value FROM pengaturan WHERE config_key = 'wa_api_key'");
    if ($cfg = $stmtCfg->fetch()) {
        $token = $cfg['config_value'];
    }
    
    if (empty($token)) {
        echo json_encode(['status' => 'offline']);
        exit;
    }

    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://api.fonnte.com/device',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 3,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => 'POST',
      CURLOPT_HTTPHEADER => array(
        "Authorization: $token"
      ),
    ));
    $response = curl_exec($curl);
    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpcode == 200) {
        $res = json_decode($response, true);
        if (isset($res['device_status']) && $res['device_status'] === 'connect') {
            echo json_encode(['status' => 'online']);
            exit;
        }
    }
    echo json_encode(['status' => 'offline']);
    exit;
}

// -- Hapus Riwayat --
if (isset($_GET['hapus_riwayat'])) {
    $stmt = $pdo->prepare("DELETE FROM presensi WHERE id = ?");
    $stmt->execute([$_GET['hapus_riwayat']]);
    header('Location: index.php?page=riwayat&msg=' . urlencode("Riwayat berhasil dihapus.") . '&type=success');
    exit;
}

// -- Hapus Semua Siswa (Kamis Update) --
if (isset($_GET['hapus_semua_siswa'])) {
    $pdo->query("DELETE FROM siswa");
    header('Location: index.php?page=kelola&msg=' . urlencode("Semua data siswa berhasil dihapus.") . '&type=success');
    exit;
}

// -- Hapus Semua Riwayat (Kamis Update) --
if (isset($_GET['hapus_semua_riwayat'])) {
    $pdo->query("DELETE FROM presensi");
    header('Location: index.php?page=riwayat&msg=' . urlencode("Semua data riwayat berhasil dihapus.") . '&type=success');
    exit;
}

// -- Kirim Pesan Uji Coba Demo (Kamis Update) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'demo_kirim_wa') {
    $demo_siswa_id = $_POST['demo_siswa'] ?? '';
    
    // Ambil API Key & Grup ID dari DB
    $cfgs = [];
    try {
        $stmtCfg = $pdo->query("SELECT config_key, config_value FROM pengaturan WHERE config_key IN ('wa_api_key', 'wa_grup_id')");
        while ($row = $stmtCfg->fetch()) {
            $cfgs[$row['config_key']] = $row['config_value'];
        }
    } catch (Exception $e) {}
    $token = $cfgs['wa_api_key'] ?? '';
    $target = $cfgs['wa_grup_id'] ?? '';
    
    if (empty($token) || empty($target)) {
        header('Location: index.php?page=demo&msg=' . urlencode("Gagal: API Key atau ID Grup WA belum diatur di Pengaturan!") . '&type=error');
        exit;
    }
    
    // Ambil nama siswa
    $namaSiswa = 'Siswa Demo';
    if (!empty($demo_siswa_id)) {
        $sSiswa = $pdo->prepare("SELECT nama FROM siswa WHERE id = ?");
        $sSiswa->execute([$demo_siswa_id]);
        if ($s = $sSiswa->fetch()) $namaSiswa = $s['nama'];
    }
    
    $waktu = date('d M Y H:i');
    $pesan = "*[UJI COBA]*\nHalo, siswa *$namaSiswa* telah berhasil presensi pada $waktu.\n\n_Pesan otomatis dari Sistem Presensi IoT_";
    
    // Kirim ke Fonnte
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => 'https://api.fonnte.com/send',
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => '',
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 0,
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
    $response = curl_exec($curl);
    $httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    $resObj = json_decode($response, true);
    if ($httpcode == 200 && isset($resObj['status']) && $resObj['status'] === true) {
        header('Location: index.php?page=demo&msg=' . urlencode("Pesan uji coba berhasil dikirim ke grup!") . '&type=success');
        exit;
    } else {
        $reason = $resObj['reason'] ?? $resObj['detail'] ?? 'Kesalahan tidak diketahui atau target salah';
        header('Location: index.php?page=demo&msg=' . urlencode("Gagal kirim: " . $reason) . '&type=error');
        exit;
    }
}

// -- Simpan Pengaturan (Task Thursday) --
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'simpan_pengaturan') {
    $confirmed = $_POST['confirmed_sections'] ?? [];
    if (!is_array($confirmed)) $confirmed = [$confirmed];
    
    $keys_wa = ['wa_nomor', 'wa_api_key', 'wa_grup_id'];
    $keys_gsheet = ['gsheet_url'];
    $updated = false;
    
    if (in_array('wa', $confirmed)) {
        foreach ($keys_wa as $key) {
            $val = trim($_POST[$key] ?? '');
            $stmt = $pdo->prepare("UPDATE pengaturan SET config_value = ? WHERE config_key = ?");
            $stmt->execute([$val, $key]);
        }
        $updated = true;
    }
    if (in_array('gsheet', $confirmed)) {
        foreach ($keys_gsheet as $key) {
            $val = trim($_POST[$key] ?? '');
            $stmt = $pdo->prepare("UPDATE pengaturan SET config_value = ? WHERE config_key = ?");
            $stmt->execute([$val, $key]);
        }
        $updated = true;
    }
    
    if ($updated) {
        header('Location: index.php?page=pengaturan&msg=' . urlencode("Pengaturan berhasil disimpan!") . '&type=success');
    } else {
        header('Location: index.php?page=pengaturan&msg=' . urlencode("Tidak ada section yang di-confirm.") . '&type=error');
    }
    exit;
}

// ============================================================
// 4. FLASH MESSAGE DARI REDIRECT
// ============================================================
if (isset($_GET['msg'])) {
    $flash_msg = $_GET['msg'];
    $flash_type = $_GET['type'] ?? 'success';
}

// ============================================================
// 5. ROUTING HALAMAN & QUERY DATA
// ============================================================
$page = $_GET['page'] ?? 'dashboard';
if (!in_array($page, ['dashboard', 'kelola', 'daftar', 'riwayat', 'pengaturan', 'demo'])) $page = 'dashboard';

// Fetch Admin User Data
$stmtAdmin = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmtAdmin->execute([$admin_id]);
$adminData = $stmtAdmin->fetch();
$adminNama = $adminData['nama_lengkap'] ?? 'Admin';
$adminFoto = $adminData['foto_profil'] ? 'uploads/' . $adminData['foto_profil'] : '';

// Fetch Data Catatan
$catatanData = [];
$jadwal_masuk = '07:00';
$jadwal_pulang = '14:00';

if ($page === 'dashboard') {
    $catatanData = $pdo->query("SELECT * FROM catatan ORDER BY created_at DESC")->fetchAll();
    
    // Fetch Jadwal
    $stmtJadwal = $pdo->query("SELECT config_key, config_value FROM pengaturan WHERE config_key IN ('jadwal_masuk', 'jadwal_pulang')");
    while ($row = $stmtJadwal->fetch()) {
        if ($row['config_key'] === 'jadwal_masuk') $jadwal_masuk = $row['config_value'];
        if ($row['config_key'] === 'jadwal_pulang') $jadwal_pulang = $row['config_value'];
    }
}

// Data siswa (dengan pencarian Kamis Update)
$searchSiswa = trim($_GET['search_siswa'] ?? '');
$sqlSiswa = "SELECT * FROM siswa";
$paramsSiswa = [];
if ($searchSiswa !== '') {
    $sqlSiswa .= " WHERE nama LIKE ? OR id LIKE ? OR status LIKE ? OR waktu LIKE ?";
    $paramsSiswa = array_fill(0, 4, "%$searchSiswa%");
}
$sqlSiswa .= " ORDER BY waktu DESC";
$stmtSiswa = $pdo->prepare($sqlSiswa);
$stmtSiswa->execute($paramsSiswa);
$dataSiswa = $stmtSiswa->fetchAll();

// Data riwayat (dengan sorting & pencarian Kamis Update)
$searchRiwayat = trim($_GET['search_riwayat'] ?? '');
$sort_col = $_GET['sort'] ?? 'waktu_hadir';
$sort_dir = $_GET['order'] ?? 'DESC';
if (!in_array($sort_col, ['waktu_hadir', 'nama_siswa', 'finger_id'])) $sort_col = 'waktu_hadir';
if (!in_array(strtoupper($sort_dir), ['ASC', 'DESC'])) $sort_dir = 'DESC';

$sqlRiwayat = "SELECT * FROM presensi";
$paramsRiwayat = [];
if ($searchRiwayat !== '') {
    $sqlRiwayat .= " WHERE nama_siswa LIKE ? OR finger_id LIKE ? OR type LIKE ? OR waktu_hadir LIKE ?";
    $paramsRiwayat = array_fill(0, 4, "%$searchRiwayat%");
}
$sqlRiwayat .= " ORDER BY $sort_col $sort_dir";
$stmtRiwayat = $pdo->prepare($sqlRiwayat);
$stmtRiwayat->execute($paramsRiwayat);
$dataRiwayat = $stmtRiwayat->fetchAll();

// Data pengaturan (Task Thursday)
// Data pengaturan (Task Thursday)
$configData = [];
try {
    $rows = $pdo->query("SELECT config_key, config_value FROM pengaturan")->fetchAll();
    foreach ($rows as $row) {
        $configData[$row['config_key']] = $row['config_value'] ?? '';
    }
} catch (Exception $e) {
    // Tabel belum ada, abaikan
}

// Hitung statistik
$totalSiswa = count($dataSiswa);
$totalTerkunci = 0;
$totalTerbuka = 0;
foreach ($dataSiswa as $s) {
    if ($s['status'] === 'terkunci') $totalTerkunci++;
    else $totalTerbuka++;
}
$totalPresensi = count($dataRiwayat);
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Sistem Presensi IoT — Dashboard Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <style>
    /* ========== RESET & BASE ========== */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
      background: #0a0f1e;
      color: #e2e8f0;
      min-height: 100vh;
      overflow-x: hidden;
    }

    /* ========== LAYOUT ========== */
    .app { display: flex; min-height: 100vh; }

    /* ========== SIDEBAR ========== */
    .sidebar {
      width: 260px;
      min-height: 100vh;
      background: linear-gradient(180deg, #0d1a0f 0%, #0a1510 40%, #0d1117 100%);
      border-right: 1px solid rgba(34, 197, 94, 0.15);
      display: flex;
      flex-direction: column;
      padding: 0;
      position: fixed;
      left: 0;
      top: 0;
      bottom: 0;
      z-index: 100;
      transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      backdrop-filter: blur(20px);
    }
    .sidebar-header {
      padding: 1.5rem;
      border-bottom: 1px solid rgba(34, 197, 94, 0.1);
      text-align: center;
    }
    .sidebar-logo {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 0.75rem;
    }
    .sidebar-logo .logo-icon {
      width: 42px;
      height: 42px;
      background: linear-gradient(135deg, #22c55e, #16a34a);
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
      box-shadow: 0 0 20px rgba(34, 197, 94, 0.3);
    }
    .sidebar-logo .logo-text {
      font-size: 1.1rem;
      font-weight: 700;
      color: #f0fdf4;
      letter-spacing: -0.02em;
    }
    .sidebar-logo .logo-sub {
      font-size: 0.65rem;
      color: rgba(34, 197, 94, 0.7);
      text-transform: uppercase;
      letter-spacing: 0.1em;
      font-weight: 500;
    }

    .sidebar-nav {
      flex: 1;
      padding: 1rem 0.75rem;
      display: flex;
      flex-direction: column;
      gap: 4px;
    }
    .nav-item {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.8rem 1rem;
      border-radius: 10px;
      color: #94a3b8;
      text-decoration: none;
      font-size: 0.9rem;
      font-weight: 500;
      transition: all 0.2s ease;
      position: relative;
      overflow: hidden;
    }
    .nav-item:hover {
      background: rgba(34, 197, 94, 0.08);
      color: #bbf7d0;
    }
    .nav-item.active {
      background: linear-gradient(135deg, rgba(34, 197, 94, 0.15), rgba(34, 197, 94, 0.05));
      color: #22c55e;
      box-shadow: inset 0 0 0 1px rgba(34, 197, 94, 0.2);
    }
    .nav-item.active::before {
      content: '';
      position: absolute;
      left: 0;
      top: 20%;
      bottom: 20%;
      width: 3px;
      background: #22c55e;
      border-radius: 0 3px 3px 0;
      box-shadow: 0 0 8px rgba(34, 197, 94, 0.5);
    }
    .nav-icon { font-size: 1.15rem; width: 24px; text-align: center; }
    .nav-label { font-size: 0.88rem; }

    .sidebar-footer {
      padding: 1rem 0.75rem;
      border-top: 1px solid rgba(34, 197, 94, 0.1);
    }
    .nav-logout {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      padding: 0.8rem 1rem;
      border-radius: 10px;
      color: #f87171;
      text-decoration: none;
      font-size: 0.88rem;
      font-weight: 500;
      transition: all 0.2s ease;
      cursor: pointer;
      border: none;
      background: none;
      width: 100%;
      text-align: left;
    }
    .nav-logout:hover {
      background: rgba(239, 68, 68, 0.1);
      color: #fca5a5;
    }

    /* ========== MAIN CONTENT ========== */
    .main-content {
      flex: 1;
      margin-left: 260px;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    /* ========== TOP BAR ========== */
    .top-bar {
      padding: 1rem 2rem;
      background: rgba(10, 15, 30, 0.8);
      backdrop-filter: blur(10px);
      border-bottom: 1px solid rgba(255,255,255,0.05);
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 50;
    }
    .top-bar-left { display: flex; align-items: center; gap: 1rem; }
    .hamburger {
      display: none;
      background: none;
      border: 1px solid rgba(255,255,255,0.1);
      color: #e2e8f0;
      font-size: 1.3rem;
      cursor: pointer;
      padding: 0.4rem 0.6rem;
      border-radius: 8px;
    }
    .page-title { font-size: 1.25rem; font-weight: 600; color: #f1f5f9; }
    .top-bar-right { display: flex; align-items: center; gap: 1rem; }
    .date-display { font-size: 0.8rem; color: #64748b; font-weight: 500;}
    .admin-badge {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      padding: 0.4rem 0.8rem;
      border-radius: 20px;
      font-size: 0.8rem;
      font-weight: 600;
      transition: all 0.3s ease;
    }
    .admin-badge.online {
      border: 2px solid #22c55e;
      color: #bbf7d0;
      box-shadow: 0 0 12px rgba(34, 197, 94, 0.25);
    }
    .admin-badge.offline {
      border: 2px solid #ef4444;
      color: #fca5a5;
      box-shadow: 0 0 12px rgba(239, 68, 68, 0.25);
    }
    .status-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      animation: pulse 2s infinite;
    }
    .status-dot.on { background: #22c55e; }
    .status-dot.off { background: #ef4444; }
    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.4; }
    }

    /* ========== PAGE CONTENT ========== */
    .page-content {
      padding: 1.5rem 2rem 3rem 2rem;
      flex: 1;
    }

    /* ========== DASHBOARD SPECIFIC ========== */
    .dashboard-header {
      background: linear-gradient(135deg, rgba(34,197,94,0.1), rgba(16,185,129,0.05));
      border: 1px solid rgba(34, 197, 94, 0.15);
      border-radius: 16px;
      padding: 2rem;
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 2rem;
      box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    }
    .profile-info h1 {
      font-size: 1.8rem;
      color: #f8fafc;
      margin-bottom: 0.2rem;
    }
    .profile-info p {
      color: #22c55e;
      font-weight: 500;
      font-size: 0.95rem;
    }
    .profile-avatar {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      border: 3px solid #22c55e;
      object-fit: cover;
      cursor: pointer;
      box-shadow: 0 0 20px rgba(34,197,94,0.3);
      transition: transform 0.3s ease;
    }
    .profile-avatar:hover {
      transform: scale(1.05);
    }
    .profile-avatar-placeholder {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      border: 3px solid #22c55e;
      background: #111827;
      color: #22c55e;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 2rem;
      font-weight: bold;
      cursor: pointer;
      box-shadow: 0 0 20px rgba(34,197,94,0.3);
      transition: transform 0.3s ease;
    }
    .profile-avatar-placeholder:hover {
      transform: scale(1.05);
    }

    .shortcuts-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 1rem;
      margin-bottom: 2rem;
    }
    .shortcut-card {
      background: #1e293b;
      border: 1px solid rgba(255,255,255,0.05);
      border-radius: 12px;
      padding: 1.5rem;
      display: flex;
      align-items: center;
      gap: 1.5rem;
      text-decoration: none;
      color: #e2e8f0;
      transition: all 0.3s ease;
      cursor: pointer;
    }
    .shortcut-card:hover {
      transform: translateY(-4px);
      border-color: rgba(34,197,94,0.3);
      box-shadow: 0 10px 20px rgba(0,0,0,0.2);
    }
    .shortcut-icon {
      width: 50px;
      height: 50px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.5rem;
    }
    .sc-1 { background: rgba(34, 197, 94, 0.15); color: #4ade80; }
    .sc-2 { background: rgba(59, 130, 246, 0.15); color: #60a5fa; }
    .sc-3 { background: rgba(245, 158, 11, 0.15); color: #fbbf24; }
    .sc-4 { background: rgba(239, 68, 68, 0.15); color: #f87171; }
    
    .shortcut-content h3 { font-size: 1.1rem; margin-bottom: 0.2rem; }
    .shortcut-content p { font-size: 0.8rem; color: #94a3b8; }

    .catatan-section {
      background: #111827;
      border: 1px solid rgba(255,255,255,0.05);
      border-radius: 16px;
      padding: 1.5rem;
    }
    .catatan-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 1.5rem;
      border-bottom: 1px solid rgba(255,255,255,0.05);
      padding-bottom: 1rem;
    }
    .catatan-list {
      display: flex;
      flex-direction: column;
      gap: 1rem;
    }
    .catatan-item {
      background: #1e293b;
      border-left: 4px solid #22c55e;
      padding: 1rem 1.2rem;
      border-radius: 8px;
    }
    .catatan-item h4 { color: #f1f5f9; margin-bottom: 0.4rem; font-size: 1.05rem; }
    .catatan-meta { font-size: 0.75rem; color: #64748b; margin-bottom: 0.6rem; }
    .catatan-body { font-size: 0.9rem; color: #cbd5e1; line-height: 1.5; white-space: pre-wrap;}
    .catatan-actions {
      margin-top: 0.8rem;
      display: flex;
      gap: 0.5rem;
      justify-content: flex-end;
    }

    /* ========== STATS CARDS ========== */
    .stats-row {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 1rem;
      margin-bottom: 1.5rem;
    }
    .stat-card {
      background: linear-gradient(135deg, #1e293b, #162032);
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 12px;
      padding: 1.2rem;
      display: flex;
      align-items: center;
      gap: 1rem;
    }
    .stat-icon {
      width: 44px;
      height: 44px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
    }
    .stat-icon.green { background: rgba(34, 197, 94, 0.15); }
    .stat-icon.blue { background: rgba(59, 130, 246, 0.15); }
    .stat-icon.amber { background: rgba(245, 158, 11, 0.15); }
    .stat-icon.purple { background: rgba(168, 85, 247, 0.15); }
    .stat-value { font-size: 1.5rem; font-weight: 700; color: #f1f5f9; }
    .stat-label { font-size: 0.75rem; color: #64748b; font-weight: 500; }

    /* ========== TABLE ========== */
    .table-wrapper {
      background: #111827;
      border: 1px solid rgba(255,255,255,0.06);
      border-radius: 12px;
      overflow: hidden;
    }
    .table-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 1rem 1.25rem;
      border-bottom: 1px solid rgba(255,255,255,0.06);
      flex-wrap: wrap;
      gap: 0.75rem;
    }
    .table-title { font-size: 0.95rem; font-weight: 600; color: #cbd5e1; }
    .table-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
    table { width: 100%; border-collapse: collapse; }
    thead th {
      padding: 0.75rem 1rem;
      text-align: left;
      font-size: 0.7rem;
      font-weight: 600;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      background: rgba(0,0,0,0.2);
      border-bottom: 1px solid rgba(255,255,255,0.06);
      white-space: nowrap;
    }
    thead th a { color: #64748b; text-decoration: none; }
    thead th a:hover { color: #22c55e; }
    thead th .sort-arrow { font-size: 0.6rem; margin-left: 4px; }
    tbody td {
      padding: 0.75rem 1rem;
      font-size: 0.85rem;
      border-bottom: 1px solid rgba(255,255,255,0.03);
      color: #cbd5e1;
    }
    tbody tr { transition: background 0.15s ease; }
    tbody tr:hover { background: rgba(34, 197, 94, 0.04); }
    tbody tr:last-child td { border-bottom: none; }
    .empty-row { text-align: center; color: #475569; padding: 2.5rem 1rem !important; font-style: italic; }

    /* ========== BADGES ========== */
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 10px;
      border-radius: 20px;
      font-size: 0.72rem;
      font-weight: 600;
    }
    .badge-locked {
      background: rgba(245, 158, 11, 0.15);
      color: #fbbf24;
      border: 1px solid rgba(245, 158, 11, 0.3);
    }
    .badge-unlocked {
      background: rgba(34, 197, 94, 0.15);
      color: #4ade80;
      border: 1px solid rgba(34, 197, 94, 0.3);
    }
    .id-tag { font-weight: 700; color: #22c55e; font-family: 'Inter', monospace; }

    /* ========== BUTTONS ========== */
    .btn {
      padding: 0.4rem 0.9rem;
      border: none;
      border-radius: 8px;
      cursor: pointer;
      font-size: 0.78rem;
      font-weight: 600;
      font-family: 'Inter', sans-serif;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      text-decoration: none;
      transition: all 0.2s ease;
      white-space: nowrap;
    }
    .btn-green {
      background: linear-gradient(135deg, #22c55e, #16a34a);
      color: white;
      box-shadow: 0 2px 8px rgba(34, 197, 94, 0.3);
    }
    .btn-green:hover { box-shadow: 0 4px 16px rgba(34, 197, 94, 0.4); transform: translateY(-1px); }
    .btn-blue {
      background: rgba(59, 130, 246, 0.15);
      color: #60a5fa;
      border: 1px solid rgba(59, 130, 246, 0.3);
    }
    .btn-blue:hover { background: rgba(59, 130, 246, 0.25); }
    .btn-red {
      background: rgba(239, 68, 68, 0.12);
      color: #f87171;
      border: 1px solid rgba(239, 68, 68, 0.25);
    }
    .btn-red:hover { background: rgba(239, 68, 68, 0.22); }
    .btn-amber {
      background: rgba(245, 158, 11, 0.12);
      color: #fbbf24;
      border: 1px solid rgba(245, 158, 11, 0.25);
    }
    .btn-amber:hover { background: rgba(245, 158, 11, 0.22); }
    .btn-outline {
      background: rgba(255,255,255,0.05);
      color: #94a3b8;
      border: 1px solid rgba(255,255,255,0.1);
    }
    .btn-outline:hover { background: rgba(255,255,255,0.1); color: #e2e8f0; }

    /* ========== FORM (Mode Pendaftaran & Modals) ========== */
    .register-card {
      background: linear-gradient(135deg, #111827, #0f1629);
      border: 1px solid rgba(34, 197, 94, 0.12);
      border-radius: 16px;
      padding: 2rem;
      max-width: 520px;
    }
    .register-card h3 {
      color: #22c55e;
      font-size: 1.1rem;
      margin-bottom: 0.3rem;
    }
    .register-card p {
      color: #64748b;
      font-size: 0.82rem;
      margin-bottom: 1.5rem;
    }
    .form-group { margin-bottom: 1.2rem; }
    .form-label {
      display: block;
      font-size: 0.78rem;
      font-weight: 600;
      color: #94a3b8;
      margin-bottom: 0.4rem;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .form-input {
      width: 100%;
      padding: 0.7rem 1rem;
      background: #0a0f1e;
      border: 1px solid rgba(255,255,255,0.1);
      border-radius: 8px;
      color: #e2e8f0;
      font-size: 0.9rem;
      font-family: 'Inter', sans-serif;
      transition: border-color 0.2s ease;
    }
    .form-input:focus {
      outline: none;
      border-color: #22c55e;
      box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.15);
    }
    textarea.form-input {
      resize: vertical;
      min-height: 100px;
    }
    .form-hint {
      font-size: 0.72rem;
      color: #ef4444;
      margin-top: 4px;
      display: none;
    }
    .form-hint.visible { display: block; }
    select.form-input {
      appearance: none;
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M1.5 5.5l6.5 6.5 6.5-6.5'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 12px center;
      padding-right: 2.5rem;
    }

    /* ========== MODALS ========== */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.85);
      backdrop-filter: blur(8px);
      align-items: center;
      justify-content: center;
      z-index: 200;
      padding: 1rem;
    }
    .modal-overlay.show { display: flex; }
    .modal-box {
      background: #0f172a;
      border: 1px solid rgba(255,255,255,0.15);
      border-radius: 16px;
      padding: 1.5rem;
      width: 100%;
      max-width: 450px;
      box-shadow: 0 25px 50px rgba(0,0,0,0.5);
      animation: modalIn 0.25s ease;
      max-height: 90vh;
      overflow-y: auto;
    }
    @keyframes modalIn {
      from { opacity: 0; transform: scale(0.9) translateY(20px); }
      to { opacity: 1; transform: scale(1) translateY(0); }
    }
    .modal-box h3 {
      font-size: 1rem;
      color: #22c55e;
      margin-bottom: 1rem;
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .modal-box .form-group { margin-bottom: 1rem; }
    .modal-footer {
      display: flex;
      gap: 0.5rem;
      justify-content: flex-end;
      margin-top: 1.2rem;
    }

    /* ========== TOAST ========== */
    .toast {
      position: fixed;
      top: 1.5rem;
      right: 1.5rem;
      padding: 0.8rem 1.2rem;
      border-radius: 10px;
      font-size: 0.85rem;
      font-weight: 500;
      z-index: 300;
      display: flex;
      align-items: center;
      gap: 0.5rem;
      animation: toastIn 0.4s ease, toastOut 0.4s ease 4s forwards;
      max-width: 400px;
    }
    .toast-success {
      background: rgba(34, 197, 94, 0.15);
      color: #4ade80;
      border: 1px solid rgba(34, 197, 94, 0.3);
    }
    .toast-error {
      background: rgba(239, 68, 68, 0.15);
      color: #f87171;
      border: 1px solid rgba(239, 68, 68, 0.3);
    }
    @keyframes toastIn { from { opacity: 0; transform: translateX(30px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes toastOut { from { opacity: 1; } to { opacity: 0; transform: translateY(-10px); } }

    /* ========== MOBILE OVERLAY ========== */
    .sidebar-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.5);
      z-index: 99;
    }

    /* ========== PRINT STYLES ========== */
    @media print {
      .sidebar, .top-bar, .table-actions, .table-header, .stats-row, .btn, .toast, .modal-overlay, .hamburger, .sidebar-overlay, .dashboard-header, .shortcuts-grid, .catatan-section { display: none !important; }
      .main-content { margin-left: 0 !important; }
      .page-content { padding: 0 !important; }
      body { background: white; color: black; }
      table { border: 1px solid #ccc; }
      thead th { background: #f0f0f0; color: #333; border: 1px solid #ccc; }
      tbody td { color: #333; border: 1px solid #eee; }
      .badge { border: 1px solid #999; color: #333; background: #f5f5f5; }
      .id-tag { color: #333; }
      .print-header { display: block !important; text-align: center; margin-bottom: 1rem; }
      .print-header h2 { font-size: 1.2rem; color: black; }
      .print-header p { font-size: 0.85rem; color: #666; }
    }
    .print-header { display: none; }

    /* ========== RESPONSIVE ========== */
    @media (max-width: 768px) {
      .sidebar {
        transform: translateX(-100%);
        width: 280px;
      }
      .sidebar.open { transform: translateX(0); }
      .sidebar-overlay.show { display: block; }
      .main-content { margin-left: 0; }
      .hamburger { display: block; }
      .top-bar { padding: 0.8rem 1rem; flex-direction: column; align-items: flex-start; gap: 0.5rem; }
      .top-bar-left { width: 100%; justify-content: flex-start; }
      .top-bar-right { align-self: flex-end; margin-top: -38px; }
      .date-display { display: none; } /* Sembunyikan tanggal di mobile agar tidak nabrak */
      .page-content { padding: 1rem; }
      .page-title { font-size: 1rem; }
      .stats-row { grid-template-columns: repeat(2, 1fr); }
      .table-wrapper { overflow-x: auto; }
      table { min-width: 600px; }
      .register-card { padding: 1.2rem; }
      .admin-badge { font-size: 0.7rem; padding: 0.3rem 0.6rem; }
      .dashboard-header { flex-direction: column-reverse; text-align: center; gap: 1.5rem; }
      .shortcuts-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 480px) {
      .stats-row { grid-template-columns: 1fr; }
      .table-header { flex-direction: column; align-items: flex-start; }
    }

    /* ========== PENGATURAN PAGE (Task Thursday) ========== */
    .pengaturan-grid {
      display: grid;
      grid-template-columns: 1fr 1fr 1fr;
      gap: 1.5rem;
      align-items: start;
    }
    .config-card {
      background: linear-gradient(135deg, #111827, #0f1629);
      border: 1px solid rgba(255,255,255,0.08);
      border-radius: 16px;
      padding: 1.5rem;
      transition: all 0.3s ease;
    }
    .config-card.confirmed {
      border-color: rgba(34, 197, 94, 0.4);
      box-shadow: 0 0 20px rgba(34, 197, 94, 0.1);
    }
    .config-card-header {
      display: flex;
      align-items: center;
      gap: 0.75rem;
      margin-bottom: 1.2rem;
      padding-bottom: 0.8rem;
      border-bottom: 1px solid rgba(255,255,255,0.06);
    }
    .config-card-icon {
      width: 42px;
      height: 42px;
      border-radius: 10px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.3rem;
      flex-shrink: 0;
    }
    .config-card-icon.wa { background: rgba(37, 211, 102, 0.15); }
    .config-card-icon.gsheet { background: rgba(66, 133, 244, 0.15); }
    .config-card-icon.hint { background: rgba(245, 158, 11, 0.15); }
    .config-card-title {
      font-size: 1rem;
      font-weight: 600;
      color: #f1f5f9;
    }
    .config-card-subtitle {
      font-size: 0.72rem;
      color: #64748b;
      margin-top: 2px;
    }
    .config-confirmed-badge {
      display: none;
      margin-left: auto;
      font-size: 0.7rem;
      color: #22c55e;
      font-weight: 600;
      background: rgba(34, 197, 94, 0.1);
      padding: 3px 8px;
      border-radius: 12px;
      border: 1px solid rgba(34, 197, 94, 0.3);
    }
    .config-card.confirmed .config-confirmed-badge { display: inline-flex; }

    .config-card .form-group { margin-bottom: 1rem; }
    .config-card .form-group:last-of-type { margin-bottom: 1.2rem; }

    .btn-confirm-section {
      width: 100%;
      padding: 0.6rem;
      border: 1px solid rgba(239, 68, 68, 0.4);
      background: rgba(239, 68, 68, 0.1);
      color: #f87171;
      border-radius: 8px;
      cursor: pointer;
      font-size: 0.82rem;
      font-weight: 600;
      font-family: 'Inter', sans-serif;
      transition: all 0.25s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }
    .btn-confirm-section:hover {
      background: rgba(239, 68, 68, 0.2);
      border-color: rgba(239, 68, 68, 0.6);
      transform: translateY(-1px);
    }
    .btn-confirm-section.confirmed {
      border-color: rgba(34, 197, 94, 0.4);
      background: rgba(34, 197, 94, 0.1);
      color: #4ade80;
    }
    .btn-confirm-section.confirmed:hover {
      background: rgba(34, 197, 94, 0.2);
      border-color: rgba(34, 197, 94, 0.6);
    }

    .hint-box {
      background: rgba(245, 158, 11, 0.06);
      border: 1px solid rgba(245, 158, 11, 0.15);
      border-radius: 10px;
      padding: 1rem 1.2rem;
      font-size: 0.8rem;
      color: #fbbf24;
      line-height: 1.6;
    }
    .hint-box strong {
      display: block;
      margin-bottom: 0.5rem;
      font-size: 0.85rem;
      color: #fde68a;
    }
    .hint-box ol {
      padding-left: 1.2rem;
      margin: 0;
    }
    .hint-box li {
      margin-bottom: 0.3rem;
    }

    .btn-simpan-utama {
      width: 100%;
      padding: 0.8rem;
      border: none;
      border-radius: 10px;
      cursor: pointer;
      font-size: 0.95rem;
      font-weight: 700;
      font-family: 'Inter', sans-serif;
      background: linear-gradient(135deg, #eab308, #ca8a04);
      color: #0a0f1e;
      box-shadow: 0 4px 15px rgba(234, 179, 8, 0.3);
      transition: all 0.25s ease;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      margin-top: 1rem;
    }
    .btn-simpan-utama:hover {
      box-shadow: 0 6px 25px rgba(234, 179, 8, 0.45);
      transform: translateY(-2px);
    }
    .btn-simpan-utama:active {
      transform: translateY(0);
    }

    /* Popup Mini */
    .popup-mini-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.6);
      backdrop-filter: blur(4px);
      align-items: center;
      justify-content: center;
      z-index: 300;
    }
    .popup-mini-overlay.show { display: flex; }
    .popup-mini-box {
      background: #1e293b;
      border: 1px solid rgba(255,255,255,0.12);
      border-radius: 14px;
      padding: 1.5rem 2rem;
      text-align: center;
      max-width: 340px;
      width: 90%;
      box-shadow: 0 20px 40px rgba(0,0,0,0.4);
      animation: modalIn 0.25s ease;
    }
    .popup-mini-icon {
      font-size: 2rem;
      margin-bottom: 0.5rem;
    }
    .popup-mini-text {
      font-size: 0.9rem;
      color: #e2e8f0;
      font-weight: 500;
    }
    .popup-mini-close {
      margin-top: 1rem;
      padding: 0.4rem 1.5rem;
      border: 1px solid rgba(255,255,255,0.15);
      background: rgba(255,255,255,0.05);
      color: #94a3b8;
      border-radius: 8px;
      cursor: pointer;
      font-size: 0.8rem;
      font-family: 'Inter', sans-serif;
      transition: all 0.2s ease;
    }
    .popup-mini-close:hover {
      background: rgba(255,255,255,0.1);
      color: #e2e8f0;
    }

    /* Responsive Pengaturan */
    @media (max-width: 768px) {
      .pengaturan-grid {
        grid-template-columns: 1fr;
      }
    }
  </style>
</head>
<body>

<!-- ==================== SIDEBAR ==================== -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <div class="logo-icon">🔒</div>
      <div>
        <div class="logo-text">Presensi IoT</div>
        <div class="logo-sub">Fingerprint System</div>
      </div>
    </div>
  </div>
  <nav class="sidebar-nav">
    <a href="?page=dashboard" class="nav-item <?= $page === 'dashboard' ? 'active' : '' ?>">
      <span class="nav-icon">🏠</span><span class="nav-label">Dashboard Utama</span>
    </a>
    <a href="?page=kelola" class="nav-item <?= $page === 'kelola' ? 'active' : '' ?>">
      <span class="nav-icon">👥</span><span class="nav-label">Kelola Siswa</span>
    </a>
    <a href="?page=daftar" class="nav-item <?= $page === 'daftar' ? 'active' : '' ?>">
      <span class="nav-icon">🖐️</span><span class="nav-label">Mode Pendaftaran</span>
    </a>
    <a href="?page=riwayat" class="nav-item <?= $page === 'riwayat' ? 'active' : '' ?>">
      <span class="nav-icon">📋</span><span class="nav-label">Riwayat Presensi</span>
    </a>
    <a href="?page=pengaturan" class="nav-item <?= $page === 'pengaturan' ? 'active' : '' ?>">
      <span class="nav-icon">⚙️</span><span class="nav-label">Pengaturan</span>
    </a>
    <a href="?page=demo" class="nav-item <?= $page === 'demo' ? 'active' : '' ?>">
      <span class="nav-icon">📲</span><span class="nav-label">Demo</span>
      <span id="navFonnteIndicator" style="margin-left:auto; display:inline-block; width:10px; height:10px; border-radius:50%; background-color: #94a3b8; box-shadow: 0 0 5px #94a3b8;" title="Status Fonnte: Checking..."></span>
    </a>
  </nav>
  <div class="sidebar-footer">
    <button class="nav-logout" onclick="konfirmasiLogout()">
      <span class="nav-icon">🚪</span><span class="nav-label">Logout</span>
    </button>
  </div>
</aside>

<!-- ==================== MAIN CONTENT ==================== -->
<div class="main-content">
  <!-- TOP BAR -->
  <header class="top-bar">
    <div class="top-bar-left">
      <button class="hamburger" onclick="toggleSidebar()">☰</button>
      <span class="page-title">
        <?php
        if ($page === 'dashboard') echo '🏠 Dashboard';
        elseif ($page === 'kelola') echo '👥 Kelola Siswa';
        elseif ($page === 'daftar') echo '🖐️ Mode Pendaftaran';
        elseif ($page === 'riwayat') echo '📋 Riwayat Presensi';
        elseif ($page === 'pengaturan') echo '⚙️ Pengaturan';
        elseif ($page === 'demo') echo '📲 Demo Fonnte';
        ?>
      </span>
    </div>
    <div class="top-bar-right">
      <span class="date-display" id="realtimeDate"><?= date('l, d M Y - H:i') ?></span>
      <div class="admin-badge offline" id="adminBadge">
        <span class="status-dot off" id="statusDot"></span>
        <span>Admin</span>
        <span id="statusLabel" style="font-weight:400;font-size:0.7rem;opacity:0.7">(Offline)</span>
      </div>
    </div>
  </header>

  <div class="page-content">

    <!-- ==================== HALAMAN: DASHBOARD UTAMA ==================== -->
    <?php if ($page === 'dashboard'): ?>
    
    <div class="dashboard-header">
      <div class="profile-info">
        <h1>Hai, <?= htmlspecialchars($adminNama) ?> 👋</h1>
        <p>Selamat datang di Pusat Kontrol Sistem Presensi IoT.</p>
      </div>
      <div class="profile-avatar-container" onclick="bukaModalProfil()">
        <?php if ($adminFoto): ?>
          <img src="<?= htmlspecialchars($adminFoto) ?>" alt="Admin Avatar" class="profile-avatar">
        <?php else: ?>
          <div class="profile-avatar-placeholder">
            <?= strtoupper(substr($adminNama, 0, 1)) ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <div class="schedule-cards-container" style="display: flex; gap: 1rem; margin-bottom: 0.5rem; flex-wrap: wrap;">
      <div class="shortcut-card" style="flex:1; min-width: 250px;">
        <div class="shortcut-icon sc-1" style="background:#dcfce7; color:#16a34a;">🟢</div>
        <div class="shortcut-content">
          <h3>Jadwal Masuk</h3>
          <div style="display:flex; align-items:center; gap:0.5rem; margin-top:0.5rem;">
            <input type="time" id="jadwal_masuk_input" class="form-control" style="width:auto; padding:0.4rem; border-radius:4px; border:1px solid #ddd;" value="<?= htmlspecialchars($jadwal_masuk) ?>">
            <button class="btn btn-green" style="padding:0.4rem 0.8rem; font-size:0.85rem;" onclick="simpanJadwal('masuk')">Confirm</button>
          </div>
        </div>
      </div>
      <div class="shortcut-card" style="flex:1; min-width: 250px;">
        <div class="shortcut-icon sc-4" style="background:#fee2e2; color:#ef4444;">🔴</div>
        <div class="shortcut-content">
          <h3>Jadwal Pulang</h3>
          <div style="display:flex; align-items:center; gap:0.5rem; margin-top:0.5rem;">
            <input type="time" id="jadwal_pulang_input" class="form-control" style="width:auto; padding:0.4rem; border-radius:4px; border:1px solid #ddd;" value="<?= htmlspecialchars($jadwal_pulang) ?>">
            <button class="btn btn-red" style="padding:0.4rem 0.8rem; font-size:0.85rem;" onclick="simpanJadwal('pulang')">Confirm</button>
          </div>
        </div>
      </div>
    </div>
    <div style="text-align: right; margin-bottom: 1.5rem;">
      <a href="?page=riwayat" style="color:#64748b; font-size:0.85rem; text-decoration:none; font-weight:500;">→ Riwayat Presensi</a>
    </div>

    <div class="catatan-section">
      <div class="catatan-header">
        <h3 style="color:#22c55e; font-size:1.2rem; display:flex; align-items:center; gap:0.5rem;">📝 Catatan Pribadi</h3>
        <button class="btn btn-green" onclick="bukaModalTambahCatatan()">➕ Create</button>
      </div>
      
      <div class="catatan-list">
        <?php if (empty($catatanData)): ?>
          <div class="empty-row" style="padding:1.5rem 0!important;">Belum ada catatan yang ditambahkan.</div>
        <?php else: foreach ($catatanData as $c): ?>
          <div class="catatan-item">
            <h4><?= htmlspecialchars($c['judul']) ?></h4>
            <div class="catatan-meta">Dibuat pada: <?= date('d M Y, H:i', strtotime($c['created_at'])) ?></div>
            <div class="catatan-body"><?= htmlspecialchars($c['isi']) ?></div>
            <div class="catatan-actions">
              <button class="btn btn-blue" style="padding: 0.3rem 0.6rem; font-size:0.7rem;" onclick="bukaEditCatatan(<?= $c['id'] ?>, '<?= htmlspecialchars(addslashes($c['judul'])) ?>', '<?= htmlspecialchars(addslashes($c['isi'])) ?>')">✏️ Edit</button>
              <a href="?hapus_catatan=<?= $c['id'] ?>" class="btn btn-red" style="padding: 0.3rem 0.6rem; font-size:0.7rem;" onclick="return confirm('Yakin ingin menghapus catatan ini?')">🗑️ Hapus</a>
            </div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- ==================== HALAMAN: KELOLA SISWA ==================== -->
    <?php elseif ($page === 'kelola'): ?>
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon green">👥</div>
        <div><div class="stat-value"><?= $totalSiswa ?></div><div class="stat-label">Total Siswa</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon blue">🔓</div>
        <div><div class="stat-value"><?= $totalTerbuka ?></div><div class="stat-label">Aktif (Terbuka)</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon amber">🔒</div>
        <div><div class="stat-value"><?= $totalTerkunci ?></div><div class="stat-label">ID Hantu (Terkunci)</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple">📋</div>
        <div><div class="stat-value"><?= $totalPresensi ?></div><div class="stat-label">Total Presensi</div></div>
      </div>
    </div>

    <div class="table-wrapper">
      <div class="table-header" style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <span class="table-title">📋 Daftar Seluruh Siswa</span>
        <div class="table-actions" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
          <form method="GET" style="display:flex; gap:5px; margin:0;">
            <input type="hidden" name="page" value="kelola">
            <input type="text" name="search_siswa" class="form-input" placeholder="Cari Nama/ID/Status/Waktu..." value="<?= htmlspecialchars($_GET['search_siswa'] ?? '') ?>" style="padding:0.4rem; max-width:250px;">
            <button type="submit" class="btn btn-blue" style="padding:0.4rem 0.8rem;">🔍 Cari</button>
            <?php if(isset($_GET['search_siswa'])): ?>
              <a href="?page=kelola" class="btn btn-red" style="padding:0.4rem 0.8rem;">🔄 Refresh</a>
            <?php endif; ?>
          </form>
          <a href="?hapus_semua_siswa=1" class="btn btn-red" onclick="return confirm('Konfirmasi nih hapus semua data siswa?')">⚠️ Hapus Semua</a>
        </div>
      </div>
      <table>
        <thead>
          <tr>
            <th>ID Finger</th>
            <th>Nama Siswa</th>
            <th>Status</th>
            <th>Waktu Pendaftaran</th>
            <th>Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($dataSiswa)): ?>
            <tr><td colspan="5" class="empty-row">Belum ada data siswa terdaftar.</td></tr>
          <?php else: foreach ($dataSiswa as $row): ?>
            <tr>
              <td><span class="id-tag">#<?= htmlspecialchars($row['id']) ?></span></td>
              <td><?= htmlspecialchars($row['nama']) ?></td>
              <td>
                <?php if ($row['status'] === 'terkunci'): ?>
                  <span class="badge badge-locked">🔒 Terkunci</span>
                <?php else: ?>
                  <span class="badge badge-unlocked">🔓 Terbuka</span>
                <?php endif; ?>
              </td>
              <td style="color:#64748b;font-size:0.8rem"><?= htmlspecialchars($row['waktu']) ?></td>
              <td>
                <button class="btn btn-blue" onclick="bukaEditSiswa('<?= $row['id'] ?>', '<?= htmlspecialchars(addslashes($row['nama'])) ?>', '<?= $row['status'] ?>')">✏️ Edit</button>
                <a href="?hapus_siswa=<?= $row['id'] ?>&page=kelola" class="btn btn-red" onclick="return confirm('Yakin ingin menghapus siswa ID #<?= $row['id'] ?>?')">🗑️ Hapus</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ==================== HALAMAN: MODE PENDAFTARAN ==================== -->
    <?php elseif ($page === 'daftar'): ?>
    <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; align-items: flex-start;">
      <!-- Kiri: Form Pendaftaran -->
      <div class="register-card" style="flex: 1; min-width: 300px; margin: 0;">
        <h3>🖐️ Pendaftaran Siswa Baru</h3>
        <p>Masukkan ID Fingerprint (1-51) dan nama siswa untuk mendaftarkan ke sistem.</p>
        
        <div class="form-group">
          <label class="form-label">ID Fingerprint</label>
          <input type="number" class="form-input" id="inputId" min="1" max="51" step="1" placeholder="Masukkan ID (1-51)" required oninput="validasiId(this)">
          <div class="form-hint" id="idHint">⚠️ Maks 51 — nilai telah disesuaikan otomatis</div>
        </div>
        <div class="form-group">
          <label class="form-label">Nama Siswa</label>
          <input type="text" class="form-input" id="inputNama" placeholder="Masukkan nama lengkap siswa" required>
        </div>
        <div style="display:flex; gap:1rem; margin-top:1rem;">
          <button class="btn btn-green" style="padding:0.7rem 1.5rem;font-size:0.9rem; flex:1;" onclick="prosesEnrollID()">
            🖐️ Enroll ID
          </button>
          <button class="btn btn-outline" style="padding:0.7rem 1.5rem;font-size:0.9rem; border: 2px solid white; color: white;" onclick="prosesSimpanManual()">
            💾 Simpan
          </button>
        </div>
      </div>

      <!-- Kanan: Petunjuk -->
      <div class="register-card" style="flex: 1; min-width: 300px; margin: 0; background: #1e293b; border: 1px solid #334155;">
        <h3 style="color:#60a5fa;">💡 Petunjuk Pendaftaran</h3>
        <p style="color:#cbd5e1; font-size:0.85rem; margin-bottom:1rem;">Ikuti langkah berikut agar pendaftaran sidik jari berhasil pada alat ESP32:</p>
        
        <ol style="color:#cbd5e1; font-size:0.85rem; padding-left:1.2rem; line-height:1.6;">
          <li style="margin-bottom:0.5rem;">Pastikan alat presensi (ESP32) dalam <strong>mode Standby</strong> (terkoneksi Wi-Fi).</li>
          <li style="margin-bottom:0.5rem;">Ketikkan angka <strong>ID Fingerprint</strong> (1-51) yang belum pernah dipakai.</li>
          <li style="margin-bottom:0.5rem;">Ketikkan <strong>Nama Siswa</strong> dengan lengkap.</li>
          <li style="margin-bottom:0.5rem;">Klik tombol <strong style="color:#4ade80;">🖐️ Enroll ID</strong>.</li>
          <li style="margin-bottom:0.5rem;">Tunggu popup <strong>"Menunggu Sidik Jari..."</strong> muncul di layar web.</li>
          <li style="margin-bottom:0.5rem;">Alat akan berbunyi bip, <strong>tempelkan jari siswa ke scanner</strong> untuk pertama kalinya sampai enroll selesai.</li>
          <li style="margin-bottom:0.5rem;">Selesai! Pendaftaran berhasil jika alat berbunyi bip 2x dan muncul pesan sukses.</li>
        </ol>
        
        <div style="margin-top: 1rem; padding: 0.8rem; background: rgba(239,68,68,0.1); border-left: 4px solid #ef4444; border-radius: 4px;">
          <p style="margin:0; font-size:0.8rem; color:#f87171;"><strong>Gagal/Ingin Batal?</strong> Klik <strong>Cancel</strong> pada popup, proses pendaftaran di alat akan otomatis terhenti dalam beberapa detik tanpa perlu menunggu timeout habis.</p>
        </div>
      </div>
    </div>



    <!-- ==================== HALAMAN: RIWAYAT ==================== -->
    <?php elseif ($page === 'riwayat'): ?>
    <div class="print-header">
      <h2>Laporan Presensi Siswa</h2>
      <p>Sistem Presensi IoT - Dicetak pada <?= date('d M Y H:i') ?></p>
    </div>
    
    <div class="table-wrapper">
      <div class="table-header" style="display:flex; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <span class="table-title">📋 Riwayat Presensi Harian</span>
        <div class="table-actions" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
          <form method="GET" style="display:flex; gap:5px; margin:0;">
            <input type="hidden" name="page" value="riwayat">
            <input type="text" name="search_riwayat" class="form-input" placeholder="Cari Nama/ID/Jenis/Waktu..." value="<?= htmlspecialchars($_GET['search_riwayat'] ?? '') ?>" style="padding:0.4rem; max-width:250px;">
            <button type="submit" class="btn btn-blue" style="padding:0.4rem 0.8rem;">🔍 Cari</button>
            <?php if(isset($_GET['search_riwayat'])): ?>
              <a href="?page=riwayat" class="btn btn-red" style="padding:0.4rem 0.8rem;">🔄 Refresh</a>
            <?php endif; ?>
          </form>
          <a href="?hapus_semua_riwayat=1" class="btn btn-red" onclick="return confirm('Konfirmasi nih hapus semua data riwayat?')">⚠️ Hapus Semua</a>
          <button class="btn btn-green" onclick="bukaModalTambahRiwayat()">➕ Tambah Manual</button>
          <a href="<?= htmlspecialchars(!empty($configData['gsheet_url']) ? $configData['gsheet_url'] : '#') ?>" target="_blank" class="btn btn-outline" style="text-decoration:none; display:inline-flex; align-items:center;">🖨️ Google Spreadsheet</a>
        </div>
      </div>
      <table>
        <thead>
          <tr>
            <th><a href="?page=riwayat&sort=waktu_hadir&order=<?= $sort_dir === 'ASC' ? 'DESC' : 'ASC' ?>">Waktu Presensi <?= $sort_col === 'waktu_hadir' ? '<span class="sort-arrow">'.($sort_dir === 'ASC' ? '▲' : '▼').'</span>' : '' ?></a></th>
            <th><a href="?page=riwayat&sort=nama_siswa&order=<?= $sort_dir === 'ASC' ? 'DESC' : 'ASC' ?>">Nama Siswa <?= $sort_col === 'nama_siswa' ? '<span class="sort-arrow">'.($sort_dir === 'ASC' ? '▲' : '▼').'</span>' : '' ?></a></th>
            <th><a href="?page=riwayat&sort=finger_id&order=<?= $sort_dir === 'ASC' ? 'DESC' : 'ASC' ?>">ID Finger <?= $sort_col === 'finger_id' ? '<span class="sort-arrow">'.($sort_dir === 'ASC' ? '▲' : '▼').'</span>' : '' ?></a></th>
            <th>Jenis</th>
            <th class="table-actions-header">Aksi</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($dataRiwayat)): ?>
            <tr><td colspan="5" class="empty-row">Belum ada riwayat presensi ter-record.</td></tr>
          <?php else: foreach ($dataRiwayat as $r): ?>
            <tr>
              <td style="color:#64748b;font-size:0.8rem"><?= date('d M Y - H:i', strtotime($r['waktu_hadir'])) ?></td>
              <td style="font-weight:500;"><?= htmlspecialchars($r['nama_siswa']) ?></td>
              <td><span class="id-tag">#<?= htmlspecialchars($r['finger_id']) ?></span></td>
              <td>
                <?php if(($r['type'] ?? 'masuk') === 'masuk'): ?>
                  <span style="background:#dcfce7; color:#16a34a; padding:0.2rem 0.5rem; border-radius:12px; font-size:0.75rem; font-weight:bold;">Masuk</span>
                <?php else: ?>
                  <span style="background:#fee2e2; color:#ef4444; padding:0.2rem 0.5rem; border-radius:12px; font-size:0.75rem; font-weight:bold;">Pulang</span>
                <?php endif; ?>
              </td>
              <td class="table-actions-cell">
                <button class="btn btn-blue" onclick="bukaEditRiwayat('<?= $r['id'] ?>', '<?= $r['waktu_hadir'] ?>')">✏️ Edit</button>
                <a href="?hapus_riwayat=<?= $r['id'] ?>&page=riwayat" class="btn btn-red" onclick="return confirm('Hapus riwayat ini?')">🗑️ Hapus</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

    <!-- ==================== HALAMAN: PENGATURAN (Task Thursday) ==================== -->
    <?php elseif ($page === 'pengaturan'): ?>
    <form method="POST" id="formPengaturan">
      <input type="hidden" name="action" value="simpan_pengaturan">
      <!-- Hidden fields untuk confirmed sections, di-populate oleh JS -->
      <div id="confirmedFieldsContainer"></div>

      <div class="pengaturan-grid">
        <!-- ===== CARD 1: WhatsApp ===== -->
        <div class="config-card" id="cardWA">
          <div class="config-card-header">
            <div class="config-card-icon wa">💬</div>
            <div>
              <div class="config-card-title">WhatsApp</div>
              <div class="config-card-subtitle">Konfigurasi bot & grup notifikasi</div>
            </div>
            <span class="config-confirmed-badge">✅ Confirmed</span>
          </div>
          <div class="form-group">
            <label class="form-label">Nomor WA Bot</label>
            <input type="text" class="form-input config-input" name="wa_nomor" id="inputWaNomor"
              value="<?= htmlspecialchars($configData['wa_nomor'] ?? '') ?>"
              data-original="<?= htmlspecialchars($configData['wa_nomor'] ?? '') ?>"
              placeholder="Contoh: 628123456789">
          </div>
          <div class="form-group">
            <label class="form-label">API Key WA</label>
            <input type="text" class="form-input config-input" name="wa_api_key" id="inputWaApiKey"
              value="<?= htmlspecialchars($configData['wa_api_key'] ?? '') ?>"
              data-original="<?= htmlspecialchars($configData['wa_api_key'] ?? '') ?>"
              placeholder="API Key dari provider WA Gateway">
          </div>
          <div class="form-group">
            <label class="form-label">ID Grup WA</label>
            <input type="text" class="form-input config-input" name="wa_grup_id" id="inputWaGrupId"
              value="<?= htmlspecialchars($configData['wa_grup_id'] ?? '') ?>"
              data-original="<?= htmlspecialchars($configData['wa_grup_id'] ?? '') ?>"
              placeholder="ID grup WhatsApp tujuan">
          </div>
          <button type="button" class="btn-confirm-section" id="btnConfirmWA" onclick="toggleConfirm('wa')">
            <span class="confirm-icon">🔴</span>
            <span class="confirm-text">Confirm Perubahan</span>
          </button>
        </div>

        <!-- ===== CARD 2: Google Spreadsheet ===== -->
        <div class="config-card" id="cardGsheet">
          <div class="config-card-header">
            <div class="config-card-icon gsheet">📊</div>
            <div>
              <div class="config-card-title">Google Spreadsheet</div>
              <div class="config-card-subtitle">Sinkronisasi data ke Google Sheets</div>
            </div>
            <span class="config-confirmed-badge">✅ Confirmed</span>
          </div>
          <div class="form-group">
            <label class="form-label">URL / Email Google Spreadsheet</label>
            <input type="text" class="form-input config-input" name="gsheet_url" id="inputGsheetUrl"
              value="<?= htmlspecialchars($configData['gsheet_url'] ?? '') ?>"
              data-original="<?= htmlspecialchars($configData['gsheet_url'] ?? '') ?>"
              placeholder="https://script.google.com/macros/s/... atau email">
          </div>
          <button type="button" class="btn-confirm-section" id="btnConfirmGsheet" onclick="toggleConfirm('gsheet')">
            <span class="confirm-icon">🔴</span>
            <span class="confirm-text">Confirm Perubahan</span>
          </button>
        </div>

        <!-- ===== CARD 3: Hint & Simpan ===== -->
        <div class="config-col-3" style="display: flex; flex-direction: column; gap: 1rem;">
          <!-- CARD HINT -->
          <div class="config-card config-card-hint" style="background: transparent; border: none; padding: 0;">
            <div class="hint-box">
              <strong>💡 Petunjuk Penggunaan</strong>
              <ol>
                <li>Edit kolom konfigurasi yang ingin diubah</li>
                <li>Klik tombol <strong>Confirm</strong> pada setiap section yang sudah diedit</li>
                <li>Setelah semua section yang diubah sudah di-confirm, klik tombol <strong>Simpan</strong></li>
                <li>Halaman akan otomatis refresh jika penyimpanan berhasil</li>
              </ol>
            </div>
          </div>

          <!-- ===== CARD SIMPAN ===== -->
          <div class="config-card config-card-simpan" style="background: transparent; border: none; padding: 0;">
            <button type="button" class="btn-simpan-utama" onclick="simpanPengaturan()" style="margin-top: 0;">
              💾 Simpan Semua Perubahan
            </button>
          </div>
        </div>
      </div>
    </form>

    <!-- ==================== HALAMAN: DEMO FONNTE (Kamis Update) ==================== -->
    <?php elseif ($page === 'demo'): ?>
    <div class="pengaturan-grid">
      <div class="config-card" style="grid-column: 1 / -1; max-width: 600px; margin: 0 auto;">
        <div class="config-card-header">
          <div class="config-card-icon wa">📲</div>
          <div>
            <div class="config-card-title">Demo Pesan WhatsApp</div>
            <div class="config-card-subtitle" style="display:flex; align-items:center; gap:0.5rem; margin-top:0.5rem;">
              <span>Uji coba pengiriman pesan via Fonnte</span>
              <span style="border-left:1px solid #475569; height:12px;"></span>
              <span id="demoFonnteIndicator" style="display:inline-block; width:10px; height:10px; border-radius:50%; background-color:#94a3b8; box-shadow: 0 0 5px #94a3b8;"></span>
              <span id="demoFonnteText" style="font-size:0.85rem; font-weight:600; color:#cbd5e1;">Checking...</span>
            </div>
          </div>
        </div>

        <form method="POST">
          <input type="hidden" name="action" value="demo_kirim_wa">
          
          <div class="form-group">
            <label class="form-label">Preview Format Pesan</label>
            <div class="hint-box" style="white-space: pre-wrap; font-family: monospace;">*[UJI COBA]*
Halo, siswa *[Nama Siswa]* telah berhasil presensi pada <?= date('d M Y H:i') ?>.

_Pesan otomatis dari Sistem Presensi IoT_</div>
          </div>

          <div class="form-group">
            <label class="form-label">Pilih Siswa (Opsional)</label>
            <select name="demo_siswa" class="form-input">
              <option value="">-- Gunakan Nama "Siswa Demo" --</option>
              <?php foreach ($pdo->query("SELECT id, nama FROM siswa ORDER BY nama ASC") as $s): ?>
                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['nama']) ?> (ID: <?= $s['id'] ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <button type="submit" class="btn-simpan-utama" onclick="return confirm('Kirim pesan uji coba ke grup sekarang?')">
            🚀 Kirim Pesan Uji Coba
          </button>
        </form>
      </div>
    </div>

    <?php endif; ?>

  </div>
</div>

<!-- ==================== MODAL: EDIT PROFIL ADMIN ==================== -->
<div class="modal-overlay" id="modalProfil">
  <div class="modal-box">
    <h3>👤 Edit Profil Admin</h3>
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="update_profil">
      <div class="form-group">
        <label class="form-label">Nama Lengkap</label>
        <input type="text" class="form-input" name="nama_lengkap" value="<?= htmlspecialchars($adminNama) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Foto Profil Baru (Opsional)</label>
        <input type="file" class="form-input" name="foto_profil" accept="image/*" style="padding-top: 0.5rem">
        <div style="font-size:0.75rem; color:#64748b; margin-top:4px;">Format: JPG, PNG, WEBP. Maks 2MB.</div>
      </div>
      <div class="modal-footer" style="justify-content: space-between;">
        <button type="button" class="btn btn-red" onclick="document.getElementById('formResetProfil').submit()" <?= !$adminData['foto_profil'] ? 'disabled style="opacity:0.5"' : '' ?>>🗑️ Reset Default</button>
        <div style="display:flex; gap:0.5rem;">
          <button type="button" class="btn btn-outline" onclick="tutupModal('modalProfil')">Batal</button>
          <button type="submit" class="btn btn-green">💾 Simpan</button>
        </div>
      </div>
    </form>
    <form id="formResetProfil" method="POST" style="display:none">
      <input type="hidden" name="action" value="reset_profil">
    </form>
  </div>
</div>

<!-- ==================== MODAL: TAMBAH CATATAN ==================== -->
<div class="modal-overlay" id="modalTambahCatatan">
  <div class="modal-box">
    <h3>➕ Create Catatan Pribadi</h3>
    <form method="POST">
      <input type="hidden" name="action" value="tambah_catatan">
      <div class="form-group">
        <label class="form-label">Judul</label>
        <input type="text" class="form-input" name="judul" placeholder="Contoh: Pengingat Update Server" required>
      </div>
      <div class="form-group">
        <label class="form-label">Isi Catatan</label>
        <textarea class="form-input" name="isi" placeholder="Tulis catatan lengkap di sini..." required></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="tutupModal('modalTambahCatatan')">Batal</button>
        <button type="submit" class="btn btn-green">💾 Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- ==================== MODAL: EDIT CATATAN ==================== -->
<div class="modal-overlay" id="modalEditCatatan">
  <div class="modal-box">
    <h3>✏️ Edit Catatan</h3>
    <form method="POST">
      <input type="hidden" name="action" value="edit_catatan">
      <input type="hidden" name="catatan_id" id="editCatatanId">
      <div class="form-group">
        <label class="form-label">Judul</label>
        <input type="text" class="form-input" name="judul" id="editCatatanJudul" required>
      </div>
      <div class="form-group">
        <label class="form-label">Isi Catatan</label>
        <textarea class="form-input" name="isi" id="editCatatanIsi" required></textarea>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="tutupModal('modalEditCatatan')">Batal</button>
        <button type="submit" class="btn btn-green">💾 Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- ==================== MODAL: EDIT SISWA ==================== -->
<div class="modal-overlay" id="modalEditSiswa">
  <div class="modal-box">
    <h3>✏️ Edit Data Siswa</h3>
    <form method="POST">
      <input type="hidden" name="action" value="edit_siswa">
      <input type="hidden" name="edit_id" id="editSiswaId">
      <div class="form-group">
        <label class="form-label">ID Finger</label>
        <input type="text" class="form-input" id="editSiswaIdDisplay" disabled style="opacity:0.5">
      </div>
      <div class="form-group">
        <label class="form-label">Nama Siswa</label>
        <input type="text" class="form-input" name="edit_nama" id="editSiswaNama" required>
      </div>
      <!-- Tombol Buka Kunci (hanya tampil jika terkunci) -->
      <div id="bukaKunciSection" style="display:none; margin-bottom:1rem;">
        <div style="background:rgba(245,158,11,0.1); border:1px solid rgba(245,158,11,0.25); border-radius:8px; padding:0.8rem; font-size:0.8rem; color:#fbbf24; margin-bottom:0.8rem;">
          🔒 Siswa ini berstatus <strong>terkunci</strong> (ID Hantu). Klik tombol di bawah untuk memulai enrollment sidik jari.
        </div>
      </div>
      <div class="modal-footer">
        <div id="bukaKunciBtnWrapper" style="display:none; margin-right:auto;">
          <button type="button" class="btn btn-amber" onclick="prosesbukaKunci()">🔓 Buka Kunci</button>
        </div>
        <button type="button" class="btn btn-outline" onclick="tutupModal('modalEditSiswa')">Batal</button>
        <button type="submit" class="btn btn-green">💾 Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- ==================== MODAL: TAMBAH RIWAYAT ==================== -->
<div class="modal-overlay" id="modalTambahRiwayat">
  <div class="modal-box">
    <h3>➕ Tambah Riwayat Presensi</h3>
    <form method="POST">
      <input type="hidden" name="action" value="tambah_riwayat">
      <div class="form-group">
        <label class="form-label">Pilih Siswa</label>
        <select class="form-input" name="riwayat_finger_id" required>
          <option value="">-- Pilih Siswa --</option>
          <?php 
            $hariIni = date('Y-m-d');
            $stmtKomplit = $pdo->prepare("SELECT finger_id, GROUP_CONCAT(type) as types FROM presensi WHERE DATE(waktu_hadir) = ? GROUP BY finger_id");
            $stmtKomplit->execute([$hariIni]);
            $komplitIds = [];
            while($row = $stmtKomplit->fetch()) {
                if(strpos($row['types'], 'masuk') !== false && strpos($row['types'], 'pulang') !== false) {
                    $komplitIds[] = $row['finger_id'];
                }
            }
          ?>
          <?php foreach ($dataSiswa as $s): 
            $isKomplit = in_array($s['id'], $komplitIds);
          ?>
            <option value="<?= $s['id'] ?>" <?= $isKomplit ? 'disabled style="color:#94a3b8; background:#f1f5f9;"' : '' ?>>
              #<?= $s['id'] ?> — <?= htmlspecialchars($s['nama']) ?> <?= $isKomplit ? '(Sudah Komplit)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Waktu Presensi</label>
        <input type="datetime-local" class="form-input" name="riwayat_waktu" value="<?= date('Y-m-d\TH:i') ?>" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="tutupModal('modalTambahRiwayat')">Batal</button>
        <button type="submit" class="btn btn-green">💾 Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- ==================== MODAL: EDIT RIWAYAT ==================== -->
<div class="modal-overlay" id="modalEditRiwayat">
  <div class="modal-box">
    <h3>✏️ Edit Waktu Presensi</h3>
    <form method="POST">
      <input type="hidden" name="action" value="edit_riwayat">
      <input type="hidden" name="edit_riwayat_id" id="editRiwayatId">
      <div class="form-group">
        <label class="form-label">Waktu Presensi</label>
        <input type="datetime-local" class="form-input" name="edit_waktu" id="editRiwayatWaktu" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="tutupModal('modalEditRiwayat')">Batal</button>
        <button type="submit" class="btn btn-green">💾 Simpan</button>
      </div>
    </form>
  </div>
</div>

<!-- ==================== MODAL: KONFIRMASI ==================== -->
<div class="modal-overlay" id="modalConfirm">
  <div class="modal-box" style="text-align:center">
    <div style="font-size:2.5rem;margin-bottom:0.5rem" id="confirmIcon">⚠️</div>
    <h3 style="justify-content:center" id="confirmTitle">Konfirmasi</h3>
    <p style="color:#94a3b8;font-size:0.85rem;margin-bottom:1rem" id="confirmMsg">Apakah Anda yakin?</p>
    <div class="modal-footer" style="justify-content:center">
      <button class="btn btn-outline" onclick="tutupModal('modalConfirm')" id="btnConfirmCancel">Batal</button>
      <button class="btn btn-green" id="confirmBtn" onclick="">Konfirmasi</button>
    </div>
  </div>
</div>

<!-- ==================== MODAL: ENROLL WAIT ==================== -->
<div class="modal-overlay" id="modalEnrollWait" style="z-index: 250;">
  <div class="modal-box" style="text-align:center">
    <div style="font-size:3rem;margin-bottom:0.5rem;animation: pulse 1.5s infinite;">🖐️</div>
    <h3 style="justify-content:center; color:#22c55e;">Menunggu Sidik Jari...</h3>
    <p style="color:#94a3b8;font-size:0.85rem;margin-bottom:1rem">Silakan tempelkan jari pada sensor sebanyak 2x.</p>
    <div style="font-size:2rem; font-weight:bold; color:#f8fafc; margin-bottom:1rem;" id="enrollCountdown">60</div>
    <div class="modal-footer" style="justify-content:center">
      <button class="btn btn-red" onclick="batalEnroll()">Batalkan</button>
    </div>
  </div>
</div>

<!-- ==================== TOAST ==================== -->
<?php if (!empty($flash_msg)): ?>
<div class="toast toast-<?= $flash_type === 'error' ? 'error' : 'success' ?>" id="toastMsg">
  <span><?= $flash_type === 'error' ? '❌' : '✅' ?></span>
  <span><?= htmlspecialchars($flash_msg) ?></span>
</div>
<?php endif; ?>

<!-- ==================== FORM BUKA KUNCI ==================== -->
<form id="formBukaKunci" method="POST" style="display:none">
  <input type="hidden" name="action" value="buka_kunci">
  <input type="hidden" name="finger_id" id="bukaKunciId">
</form>

<!-- ==================== POPUP MINI (Task Thursday) ==================== -->
<div class="popup-mini-overlay" id="popupMini">
  <div class="popup-mini-box">
    <div class="popup-mini-icon" id="popupMiniIcon">⚠️</div>
    <div class="popup-mini-text" id="popupMiniText">Pesan</div>
    <button class="popup-mini-close" onclick="tutupPopupMini()">Tutup</button>
  </div>
</div>

<!-- Form tersembunyi untuk submit -->
<form id="formDaftarManual" method="POST" style="display:none">
  <input type="hidden" name="action" value="tambah_manual">
  <input type="hidden" name="tambah_id" id="hiddenIdManual">
  <input type="hidden" name="tambah_nama" id="hiddenNamaManual">
</form>
<form id="formDaftarReal" method="POST" style="display:none">
  <input type="hidden" name="action" value="tambah_real">
  <input type="hidden" name="tambah_id" id="hiddenIdReal">
  <input type="hidden" name="tambah_nama" id="hiddenNamaReal">
</form>

<script>
// ========== SIDEBAR TOGGLE (Mobile) ==========
function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('show');
}

// ========== REALTIME DATE/TIME (DASHBOARD) ==========
function updateTime() {
  const el = document.getElementById('realtimeDate');
  if(!el) return;
  const now = new Date();
  const options = { timeZone: 'Asia/Jakarta', weekday: 'long', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute:'2-digit' };
  el.textContent = now.toLocaleDateString('id-ID', options).replace(/\./g, ':');
}
setInterval(updateTime, 60000);

// ========== STATUS ALAT POLLING ==========
let isDeviceOnline = false;
function cekStatusAlat() {
  fetch('status_alat.php')
    .then(r => r.json())
    .then(data => {
      isDeviceOnline = data.online;
      const badge = document.getElementById('adminBadge');
      const dot = document.getElementById('statusDot');
      const label = document.getElementById('statusLabel');
      
      if (data.online) {
        badge.className = 'admin-badge online';
        dot.className = 'status-dot on';
        label.textContent = '(Online)';
      } else {
        badge.className = 'admin-badge offline';
        dot.className = 'status-dot off';
        label.textContent = '(Offline)';
      }
    })
    .catch(() => { isDeviceOnline = false; });
}
cekStatusAlat();
setInterval(cekStatusAlat, 10000);

// ========== VALIDASI ID ==========
function validasiId(el) {
  const hint = document.getElementById('idHint');
  if (parseInt(el.value) > 51) {
    el.value = 51;
    hint.classList.add('visible');
    setTimeout(() => hint.classList.remove('visible'), 3000);
  }
  if (parseInt(el.value) < 1 && el.value !== '') {
    el.value = 1;
  }
}

// ========== ENROLL ID LOGIC ==========
let enrollTimer = null;
let pollTimer = null;
let enrollTimeLeft = 60;
let autoSaveTimer = null;
let autoSaveTimeLeft = 10;
let currentEnrollId = '';
let currentEnrollNama = '';

function prosesEnrollID() {
  const id = document.getElementById('inputId').value.trim();
  const nama = document.getElementById('inputNama').value.trim();
  if (!id || !nama) { alert('Harap isi ID dan Nama!'); return; }
  if (!isDeviceOnline) { alert('Alat sedang offline! Pastikan ESP32 menyala.'); return; }

  currentEnrollId = id;
  currentEnrollNama = nama;

  // Kirim AJAX mulai enroll
  const formData = new FormData();
  formData.append('action', 'mulai_enroll_ajax');
  formData.append('finger_id', id);

  fetch('index.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if (data.status === 'success') {
        document.getElementById('modalEnrollWait').classList.add('show');
        enrollTimeLeft = 60;
        document.getElementById('enrollCountdown').textContent = enrollTimeLeft;
        
        // Timer countdown
        enrollTimer = setInterval(() => {
          enrollTimeLeft--;
          document.getElementById('enrollCountdown').textContent = enrollTimeLeft;
          if (enrollTimeLeft <= 0) {
            batalEnroll(true); // timeout
          }
        }, 1000);

        // Polling status
        pollTimer = setInterval(cekStatusEnroll, 2000);
      } else {
        alert('Gagal membuat antrian: ' + (data.msg || 'Error'));
      }
    });
}

function cekStatusEnroll() {
  fetch('cek_status_enroll_ajax.php?finger_id=' + currentEnrollId)
    .then(r => r.json())
    .then(data => {
      if (data.status === 'done') {
        // SUCCESS
        clearInterval(enrollTimer);
        clearInterval(pollTimer);
        mulaiAutoSave();
      } else if (data.status === 'failed') {
        // FAILED
        batalEnroll(false, 'Gagal mengambil sidik jari. Jari tidak cocok atau dilepas terlalu cepat.');
      }
    });
}

function batalEnroll(isTimeout = false, customMsg = '') {
  clearInterval(enrollTimer);
  clearInterval(pollTimer);
  tutupModal('modalEnrollWait');

  // Kirim batal AJAX
  const formData = new FormData();
  formData.append('action', 'batal_enroll_ajax');
  formData.append('finger_id', currentEnrollId);
  fetch('index.php', { method: 'POST', body: formData });

  const msg = isTimeout ? 'Waktu habis (60s). Pendaftaran sidik jari dibatalkan.' : (customMsg || 'Pendaftaran sidik jari dibatalkan.');
  setTimeout(() => { alert('❌ ' + msg); }, 300);
}

function mulaiAutoSave() {
  tutupModal('modalEnrollWait');
  autoSaveTimeLeft = 10;
  
  const msg = `Enroll berhasil! Data akan disimpan otomatis dalam <span id="autoSaveCount" style="color:#22c55e;font-weight:bold">${autoSaveTimeLeft}</span> detik.`;
  
  document.getElementById('confirmIcon').textContent = '✅';
  document.getElementById('confirmTitle').textContent = 'Silahkan Save';
  document.getElementById('confirmMsg').innerHTML = msg;
  
  const btnSave = document.getElementById('confirmBtn');
  btnSave.textContent = 'Save Now';
  btnSave.className = 'btn btn-green';
  
  // Sembunyikan tombol batal agar flow tidak terpotong (opsional)
  document.getElementById('btnConfirmCancel').style.display = 'none';

  btnSave.onclick = function() {
    clearInterval(autoSaveTimer);
    eksekusiSaveReal();
  };

  document.getElementById('modalConfirm').classList.add('show');

  autoSaveTimer = setInterval(() => {
    autoSaveTimeLeft--;
    document.getElementById('autoSaveCount').textContent = autoSaveTimeLeft;
    if (autoSaveTimeLeft <= 0) {
      clearInterval(autoSaveTimer);
      eksekusiSaveReal();
    }
  }, 1000);
}

function eksekusiSaveReal() {
  document.getElementById('hiddenIdReal').value = currentEnrollId;
  document.getElementById('hiddenNamaReal').value = currentEnrollNama;
  document.getElementById('formDaftarReal').submit();
}

// ========== SIMPAN MANUAL (GHOST ID) ==========
function prosesSimpanManual() {
  const id = document.getElementById('inputId').value.trim();
  const nama = document.getElementById('inputNama').value.trim();
  if (!id || !nama) { alert('Harap isi ID dan Nama!'); return; }

  document.getElementById('btnConfirmCancel').style.display = 'block'; // Pastikan tombol batal muncul
  bukaConfirm(
    '',
    'Simpan Manual (ID Hantu)',
    'Menyimpan tanpa sidik jari. Siswa akan berstatus Terkunci dan tidak bisa absen sebelum melakukan Buka Kunci + Enroll nantinya. Lanjutkan?',
    function() {
      tutupModal('modalConfirm');
      setTimeout(() => {
        bukaConfirm(
          '',
          'Yakin Nih?',
          'Serius mau tambah tanpa sidik jari? Nanti bisa dibuka kuncinya pas alat sudah aktif kok!',
          function() {
            document.getElementById('hiddenIdManual').value = id;
            document.getElementById('hiddenNamaManual').value = nama;
            document.getElementById('formDaftarManual').submit();
          }
        );
      }, 300);
    }
  );
}

// ========== KONFIRMASI MODAL ==========
function bukaConfirm(icon, title, msg, callback) {
  document.getElementById('confirmIcon').textContent = icon;
  document.getElementById('confirmTitle').textContent = title;
  document.getElementById('confirmMsg').textContent = msg;
  document.getElementById('confirmBtn').onclick = callback;
  document.getElementById('confirmBtn').textContent = 'Konfirmasi';
  document.getElementById('confirmBtn').className = 'btn btn-green';
  document.getElementById('btnConfirmCancel').style.display = 'block';
  document.getElementById('modalConfirm').classList.add('show');
}

// ========== MODAL UTILS ==========
function tutupModal(id) {
  document.getElementById(id).classList.remove('show');
}

// ========== DASHBOARD ADMIN PROFIL ==========
function bukaModalProfil() {
  document.getElementById('modalProfil').classList.add('show');
}

// === AJAX SIMPAN JADWAL ===
function simpanJadwal(jenis) {
  const waktu = document.getElementById('jadwal_' + jenis + '_input').value;
  if(!waktu) return alert('Waktu tidak boleh kosong!');
  
  const formData = new FormData();
  formData.append('action', 'simpan_jadwal_ajax');
  formData.append('jenis', jenis);
  formData.append('waktu', waktu);

  fetch('index.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      if(data.status === 'success') {
        alert("jadwal berhasil dirubah");
      } else {
        alert(data.msg || 'Terjadi kesalahan');
      }
    })
    .catch(e => {
      alert('Koneksi bermasalah');
    });
}
function bukaModalTambahCatatan() {
  document.getElementById('modalTambahCatatan').classList.add('show');
}
function bukaEditCatatan(id, judul, isi) {
  document.getElementById('editCatatanId').value = id;
  document.getElementById('editCatatanJudul').value = judul;
  document.getElementById('editCatatanIsi').value = isi;
  document.getElementById('modalEditCatatan').classList.add('show');
}

// ========== EDIT SISWA ==========
let currentEditSiswaId = '';
function bukaEditSiswa(id, nama, status) {
  currentEditSiswaId = id;
  document.getElementById('editSiswaId').value = id;
  document.getElementById('editSiswaIdDisplay').value = '#' + id;
  document.getElementById('editSiswaNama').value = nama;
  
  const kunciSection = document.getElementById('bukaKunciSection');
  const kunciBtnWrapper = document.getElementById('bukaKunciBtnWrapper');
  
  if (status === 'terkunci') {
    kunciSection.style.display = 'block';
    kunciBtnWrapper.style.display = 'block';
  } else {
    kunciSection.style.display = 'none';
    kunciBtnWrapper.style.display = 'none';
  }
  
  document.getElementById('modalEditSiswa').classList.add('show');
}

function prosesbukaKunci() {
  if (!isDeviceOnline) {
    alert('⚠️ Alat tidak aktif! Nyalakan ESP32 terlebih dahulu agar bisa memulai enrollment sidik jari.');
    return;
  }
  
  document.getElementById('btnConfirmCancel').style.display = 'block';
  bukaConfirm(
    '🔓',
    'Buka Kunci Sidik Jari',
    'Antrian enrollment akan dibuat. ESP32 akan masuk mode enroll untuk ID #' + currentEditSiswaId + '. Pastikan siswa siap menempelkan jari.',
    function() {
      tutupModal('modalConfirm');
      tutupModal('modalEditSiswa'); // Tutup modal edit
      
      currentEnrollId = currentEditSiswaId;
      currentEnrollNama = document.getElementById('editSiswaNama').value;
      
      const formData = new FormData();
      formData.append('action', 'mulai_enroll_ajax');
      formData.append('finger_id', currentEnrollId);

      fetch('index.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
          if (data.status === 'success') {
            document.getElementById('modalEnrollWait').classList.add('show');
            enrollTimeLeft = 60;
            document.getElementById('enrollCountdown').textContent = enrollTimeLeft;
            
            enrollTimer = setInterval(() => {
              enrollTimeLeft--;
              document.getElementById('enrollCountdown').textContent = enrollTimeLeft;
              if (enrollTimeLeft <= 0) {
                batalEnroll(true);
              }
            }, 1000);

            pollTimer = setInterval(cekStatusEnroll, 2000);
          } else {
            alert('Gagal membuat antrian: ' + (data.msg || 'Error'));
          }
        });
    }
  );
}

// ========== RIWAYAT ==========
function bukaModalTambahRiwayat() { document.getElementById('modalTambahRiwayat').classList.add('show'); }
function bukaEditRiwayat(id, waktu) {
  document.getElementById('editRiwayatId').value = id;
  const dt = waktu.replace(' ', 'T').substring(0, 16);
  document.getElementById('editRiwayatWaktu').value = dt;
  document.getElementById('modalEditRiwayat').classList.add('show');
}

// ========== LOGOUT ==========
// --- AJAX: Cek Status Fonnte ---
document.addEventListener('DOMContentLoaded', function() {
  const formData = new FormData();
  formData.append('action', 'cek_fonnte_ajax');
  
  fetch('index.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
      const isOnline = data.status === 'online';
      const color = isOnline ? '#22c55e' : '#ef4444';
      const text = isOnline ? 'Online' : 'Offline';
      
      const navIndicator = document.getElementById('navFonnteIndicator');
      if (navIndicator) {
        navIndicator.style.backgroundColor = color;
        navIndicator.style.boxShadow = `0 0 5px ${color}`;
        navIndicator.title = `Status Fonnte: ${text}`;
      }
      
      const demoIndicator = document.getElementById('demoFonnteIndicator');
      const demoText = document.getElementById('demoFonnteText');
      if (demoIndicator && demoText) {
        demoIndicator.style.backgroundColor = color;
        demoIndicator.style.boxShadow = `0 0 8px ${color}`;
        demoText.innerText = text.toUpperCase();
      }
    })
    .catch(e => console.error('Gagal cek Fonnte', e));
});

function confirmDelete(url) {
  document.getElementById('btnConfirmCancel').style.display = 'block';
  bukaConfirm(
    '🚪',
    'Yakin Ingin Logout?',
    'Anda akan keluar dari dashboard admin dan kembali ke halaman login.',
    function() { window.location.href = 'logout.php'; }
  );
}

function konfirmasiLogout() {
  document.getElementById('btnConfirmCancel').style.display = 'block';
  bukaConfirm(
    '🚪',
    'Yakin Ingin Logout?',
    'Anda akan keluar dari dashboard admin dan kembali ke halaman login.',
    function() { window.location.href = 'logout.php'; }
  );
}

// ========== TOAST AUTO DISMISS ==========
setTimeout(() => {
  const t = document.getElementById('toastMsg');
  if (t) t.remove();
}, 5000);

// ========== ESCAPE & BACKDROP ==========
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.show').forEach(m => {
      // Cegah tutup kalau modal tunggu enroll (biar batal secara benar via tombol)
      if(m.id !== 'modalEnrollWait') m.classList.remove('show');
    });
    // Tutup popup mini juga
    tutupPopupMini();
  }
});
document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', (e) => {
    if (e.target === overlay && overlay.id !== 'modalEnrollWait') overlay.classList.remove('show');
  });
});

// ========== PENGATURAN PAGE LOGIC (Task Thursday) ==========
const confirmedSections = { wa: false, gsheet: false };

function toggleConfirm(section) {
  const btn = document.getElementById(section === 'wa' ? 'btnConfirmWA' : 'btnConfirmGsheet');
  const card = document.getElementById(section === 'wa' ? 'cardWA' : 'cardGsheet');
  if (!btn || !card) return;

  confirmedSections[section] = !confirmedSections[section];

  if (confirmedSections[section]) {
    btn.classList.add('confirmed');
    card.classList.add('confirmed');
    btn.querySelector('.confirm-icon').textContent = '✅';
    btn.querySelector('.confirm-text').textContent = 'Confirmed';
  } else {
    btn.classList.remove('confirmed');
    card.classList.remove('confirmed');
    btn.querySelector('.confirm-icon').textContent = '🔴';
    btn.querySelector('.confirm-text').textContent = 'Confirm Perubahan';
  }
}

function simpanPengaturan() {
  // Cek apakah ada perubahan di input
  const inputs = document.querySelectorAll('.config-input');
  let hasChanges = false;
  let changedSections = new Set();

  inputs.forEach(input => {
    const original = input.getAttribute('data-original') || '';
    if (input.value.trim() !== original) {
      hasChanges = true;
      // Determine which section this input belongs to
      const name = input.name;
      if (name.startsWith('wa_')) changedSections.add('wa');
      if (name.startsWith('gsheet')) changedSections.add('gsheet');
    }
  });

  // Case: Tidak ada perubahan sama sekali
  if (!hasChanges) {
    showPopupMini('ℹ️', 'Tidak ada perubahan');
    return;
  }

  // Case: Ada perubahan tapi belum di-confirm
  let allChangedConfirmed = true;
  changedSections.forEach(sec => {
    if (!confirmedSections[sec]) allChangedConfirmed = false;
  });

  if (!allChangedConfirmed) {
    showPopupMini('⚠️', 'Silahkan confirm perubahan');
    return;
  }

  // Case: Ada perubahan dan sudah di-confirm -> submit form
  const container = document.getElementById('confirmedFieldsContainer');
  container.innerHTML = '';
  Object.keys(confirmedSections).forEach(sec => {
    if (confirmedSections[sec]) {
      const input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'confirmed_sections[]';
      input.value = sec;
      container.appendChild(input);
    }
  });

  document.getElementById('formPengaturan').submit();
}

function showPopupMini(icon, text) {
  document.getElementById('popupMiniIcon').textContent = icon;
  document.getElementById('popupMiniText').textContent = text;
  document.getElementById('popupMini').classList.add('show');
}

function tutupPopupMini() {
  const el = document.getElementById('popupMini');
  if (el) el.classList.remove('show');
}

// Klik backdrop popup mini untuk tutup
const popupMiniEl = document.getElementById('popupMini');
if (popupMiniEl) {
  popupMiniEl.addEventListener('click', (e) => {
    if (e.target === popupMiniEl) tutupPopupMini();
  });
}
</script>
</body>
</html>
