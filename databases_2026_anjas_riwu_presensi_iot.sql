-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: db:3306
-- Generation Time: Sep 23, 2026 at 02:20 PM
-- Server version: 10.6.28-MariaDB-ubu2204
-- PHP Version: 8.3.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `databases_2026_anjas_riwu_presensi_iot`
--

-- --------------------------------------------------------

--
-- Table structure for table `antrian_enroll`
--

CREATE TABLE `antrian_enroll` (
  `id` int(11) NOT NULL,
  `finger_id` varchar(10) NOT NULL,
  `status` enum('pending','processing','done','failed') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `antrian_enroll`
--

INSERT INTO `antrian_enroll` (`id`, `finger_id`, `status`, `created_at`) VALUES
(9, '80', 'done', '2026-09-22 04:54:52'),
(13, '2', 'done', '2026-09-22 06:49:15');

-- --------------------------------------------------------

--
-- Table structure for table `catatan`
--

CREATE TABLE `catatan` (
  `id` int(11) NOT NULL,
  `judul` varchar(150) NOT NULL,
  `isi` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `catatan`
--

INSERT INTO `catatan` (`id`, `judul`, `isi`, `created_at`) VALUES
(1, 'Notes', 'free notes', '2026-09-16 16:39:01'),
(4, 'Tes', 'tes', '2026-09-17 00:21:58'),
(5, 'Update', 'Malem --n', '2026-09-17 00:31:25');

-- --------------------------------------------------------

--
-- Table structure for table `pengaturan`
--

CREATE TABLE `pengaturan` (
  `config_key` varchar(50) NOT NULL,
  `config_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pengaturan`
--

INSERT INTO `pengaturan` (`config_key`, `config_value`, `updated_at`) VALUES
('gsheet_url', 'https://script.google.com/macros/s/AKfycbx18rZ0uYiK8W5n08SqFe4tpFrnmcqkf_aly-lpZPbVQgiSZ9VK_yqHQBG0xJtkE-Q/exec', '2026-09-18 04:18:25'),
('jadwal_last_update', '1790131345', '2026-09-23 02:42:25'),
('jadwal_masuk', '08:00', '2026-09-18 07:16:34'),
('jadwal_pulang', '12:00', '2026-09-23 00:08:40'),
('wa_api_key', 'TroSsY6ijt8DsemJiEKW', '2026-09-18 04:34:28'),
('wa_grup_id', '120363404664943643@g.us', '2026-09-18 04:50:25'),
('wa_nomor', '6283829259730', '2026-09-18 04:35:19');

-- --------------------------------------------------------

--
-- Table structure for table `presensi`
--

CREATE TABLE `presensi` (
  `id` int(11) NOT NULL,
  `finger_id` varchar(10) NOT NULL,
  `nama_siswa` varchar(100) NOT NULL,
  `waktu_hadir` datetime DEFAULT current_timestamp(),
  `type` enum('masuk','pulang') NOT NULL DEFAULT 'masuk'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `presensi`
--

INSERT INTO `presensi` (`id`, `finger_id`, `nama_siswa`, `waktu_hadir`, `type`) VALUES
(1, '378', 'jawa', '2026-09-16 03:00:00', 'masuk'),
(40, '7', 'KIRI', '2026-09-22 08:58:14', 'pulang'),
(43, '7', 'KIRI', '2026-09-22 09:15:58', 'masuk'),
(44, '3', 'jempol kanan', '2026-09-22 09:18:14', 'masuk'),
(45, '3', 'jempol kanan', '2026-09-22 09:25:42', 'pulang'),
(51, '3', 'jempol kanan', '2026-09-23 10:56:11', 'masuk'),
(59, '3', 'jempol kanan', '2026-09-23 13:26:10', 'pulang'),
(61, '7', 'KIRI', '2026-09-23 16:38:08', 'masuk');

-- --------------------------------------------------------

--
-- Table structure for table `siswa`
--

CREATE TABLE `siswa` (
  `id` varchar(10) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `waktu` datetime DEFAULT current_timestamp(),
  `status` enum('terkunci','terbuka') NOT NULL DEFAULT 'terbuka'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `siswa`
--

INSERT INTO `siswa` (`id`, `nama`, `waktu`, `status`) VALUES
('3', 'jempol kanan', '2026-09-22 15:55:09', 'terbuka'),
('7', 'KIRI', '2026-09-22 15:57:24', 'terbuka');

-- --------------------------------------------------------

--
-- Table structure for table `status_alat`
--

CREATE TABLE `status_alat` (
  `id` int(11) NOT NULL DEFAULT 1,
  `last_heartbeat` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `status_alat`
--

INSERT INTO `status_alat` (`id`, `last_heartbeat`, `ip_address`) VALUES
(1, '2026-09-23 16:58:59', '192.168.18.115');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `nama_lengkap` varchar(100) DEFAULT 'Admin',
  `foto_profil` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `created_at`, `nama_lengkap`, `foto_profil`) VALUES
(1, 'admin', 'admin', '2026-09-16 09:35:41', 'Admin', 'admin_1789579783.jpg');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `antrian_enroll`
--
ALTER TABLE `antrian_enroll`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `catatan`
--
ALTER TABLE `catatan`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `pengaturan`
--
ALTER TABLE `pengaturan`
  ADD PRIMARY KEY (`config_key`);

--
-- Indexes for table `presensi`
--
ALTER TABLE `presensi`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_waktu` (`waktu_hadir`),
  ADD KEY `idx_finger` (`finger_id`);

--
-- Indexes for table `siswa`
--
ALTER TABLE `siswa`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `status_alat`
--
ALTER TABLE `status_alat`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `antrian_enroll`
--
ALTER TABLE `antrian_enroll`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `catatan`
--
ALTER TABLE `catatan`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `presensi`
--
ALTER TABLE `presensi`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=62;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
