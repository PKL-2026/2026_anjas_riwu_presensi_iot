-- ============================================
-- MIGRASI DATABASE — Sistem Presensi IoT v3
-- Jalankan di phpMyAdmin atau CLI MariaDB
-- ============================================

-- 1. Tambah kolom status ke tabel siswa
ALTER TABLE siswa ADD COLUMN status ENUM('terkunci','terbuka') NOT NULL DEFAULT 'terbuka';

-- Update data lama: set semua siswa yang sudah ada sebagai 'terbuka'
UPDATE siswa SET status = 'terbuka' WHERE status = 'terbuka';

-- 2. Tabel antrian enrollment (web → ESP32)
CREATE TABLE IF NOT EXISTS antrian_enroll (
  id INT AUTO_INCREMENT PRIMARY KEY,
  finger_id VARCHAR(10) NOT NULL,
  status ENUM('pending','processing','done','failed') DEFAULT 'pending',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3. Tabel riwayat presensi harian
CREATE TABLE IF NOT EXISTS presensi (
  id INT AUTO_INCREMENT PRIMARY KEY,
  finger_id VARCHAR(10) NOT NULL,
  nama_siswa VARCHAR(100) NOT NULL,
  waktu_hadir DATETIME DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_waktu (waktu_hadir),
  INDEX idx_finger (finger_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4. Tabel status alat (heartbeat ESP32)
CREATE TABLE IF NOT EXISTS status_alat (
  id INT PRIMARY KEY DEFAULT 1,
  last_heartbeat DATETIME DEFAULT NULL,
  ip_address VARCHAR(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO status_alat (id) VALUES (1);

-- 5. Tabel pengaturan (key-value config WA & Google Spreadsheet)
CREATE TABLE IF NOT EXISTS pengaturan (
  config_key VARCHAR(50) PRIMARY KEY,
  config_value TEXT DEFAULT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO pengaturan (config_key) VALUES
  ('wa_nomor'),
  ('wa_api_key'),
  ('wa_grup_id'),
  ('gsheet_url');
