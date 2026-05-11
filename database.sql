-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql104.infinityfree.com
-- Generation Time: May 08, 2026 at 05:00 AM
-- Server version: 11.4.10-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_41834902_absensi_nonasn`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `tanggal` date NOT NULL,
  `mode` enum('wfo','wfh','wfa') NOT NULL DEFAULT 'wfo',
  `mode_login` enum('qr','web') DEFAULT NULL,
  `mode_login_pulang` enum('web','qr') DEFAULT NULL,
  `alamat_wfh` varchar(255) DEFAULT NULL,
  `jam_masuk` time DEFAULT NULL,
  `jam_pulang` time DEFAULT NULL,
  `lat_masuk` decimal(10,8) DEFAULT NULL,
  `lng_masuk` decimal(11,8) DEFAULT NULL,
  `lat_pulang` decimal(10,8) DEFAULT NULL,
  `lng_pulang` decimal(11,8) DEFAULT NULL,
  `foto_masuk` varchar(255) DEFAULT NULL,
  `foto_pulang` varchar(255) DEFAULT NULL,
  `status_masuk` enum('tepat waktu','terlambat') DEFAULT NULL,
  `status_pulang` enum('pulang tepat','pulang awal') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `cuti`
--

CREATE TABLE `cuti` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `tanggal_mulai` date NOT NULL,
  `tanggal_selesai` date NOT NULL,
  `alasan` text NOT NULL,
  `bukti` varchar(255) DEFAULT NULL,
  `status` enum('menunggu','disetujui','ditolak') DEFAULT 'menunggu',
  `catatan` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `hari_libur`
--

CREATE TABLE `hari_libur` (
  `id` int(11) NOT NULL,
  `tanggal` date NOT NULL,
  `keterangan` varchar(255) DEFAULT 'Hari Libur',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `tahun` year(4) GENERATED ALWAYS AS (year(`tanggal`)) STORED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `izin`
--

CREATE TABLE `izin` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `tanggal` date NOT NULL,
  `jenis` enum('izin','sakit') NOT NULL,
  `keterangan` text DEFAULT NULL,
  `bukti` varchar(255) DEFAULT NULL,
  `status` enum('disetujui','ditolak') DEFAULT 'disetujui',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL DEFAULT 1,
  `jam_masuk` time DEFAULT '08:00:00',
  `jam_pulang` time DEFAULT '17:00:00',
  `latitude` decimal(10,8) DEFAULT -6.20000000,
  `longitude` decimal(11,8) DEFAULT 106.80000000,
  `radius` int(11) DEFAULT 100,
  `toleransi_terlambat` int(11) NOT NULL DEFAULT 30 COMMENT 'Toleransi keterlambatan dalam menit (0 = tanpa toleransi)',
  `wa_admin` varchar(20) NOT NULL DEFAULT '6282130919861' COMMENT 'Nomor WhatsApp admin untuk reset password (format internasional: 628xxx tanpa + dan spasi)',
  `base_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'URL publik/ngrok untuk QR Code absensi',
  `maintenance_mode` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Maintenance mode: 1=aktif, 0=nonaktif',
  `maintenance_pesan` varchar(255) NOT NULL DEFAULT '' COMMENT 'Pesan tambahan maintenance'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `nama` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `jabatan` varchar(100) DEFAULT NULL,
  `unit_kerja` varchar(100) DEFAULT NULL,
  `nik` varchar(20) DEFAULT NULL,
  `tempat_lahir` varchar(100) DEFAULT NULL,
  `tgl_lahir` date DEFAULT NULL,
  `no_hp` varchar(20) DEFAULT NULL,
  `lat_rumah` decimal(10,8) DEFAULT NULL,
  `lng_rumah` decimal(11,8) DEFAULT NULL,
  `foto` varchar(255) DEFAULT NULL,
  `status_foto` enum('pending','disetujui','ditolak') DEFAULT 'pending',
  `password` varchar(255) NOT NULL,
  `role` enum('pegawai','admin') NOT NULL,
  `tipe` enum('pegawai','pengamat') NOT NULL DEFAULT 'pegawai' COMMENT 'Tipe: pegawai = kantor biasa, pengamat = pengamat hidrologi lapangan',
  `nama_pos` varchar(100) DEFAULT NULL COMMENT 'Nama pos pengamatan (hanya untuk tipe=pengamat)',
  `lat_pos` decimal(10,8) DEFAULT NULL COMMENT 'Latitude pos pengamatan (hanya untuk tipe=pengamat)',
  `lng_pos` decimal(11,8) DEFAULT NULL COMMENT 'Longitude pos pengamatan (hanya untuk tipe=pengamat)',
  `status` enum('aktif','nonaktif') DEFAULT 'aktif',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `has_seen_tour` tinyint(1) DEFAULT 0 COMMENT 'Flag untuk tracking apakah user sudah melihat tour (0=belum, 1=sudah)',
  `remember_token` varchar(128) DEFAULT NULL,
  `web_remember_token` varchar(128) DEFAULT NULL,
  `web_remember_expires` datetime DEFAULT NULL,
  `remember_expires` datetime DEFAULT NULL,
  `qr_handoff_token` varchar(128) DEFAULT NULL,
  `qr_handoff_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `wfa_schedule`
--

CREATE TABLE `wfa_schedule` (
  `id` int(11) NOT NULL,
  `tanggal_mulai` date NOT NULL,
  `tanggal_selesai` date NOT NULL,
  `keterangan` varchar(255) DEFAULT 'Work From Anywhere',
  `berlaku_untuk` enum('semua','unit_kerja','personal') DEFAULT 'semua',
  `unit_kerja` varchar(100) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


-- --------------------------------------------------------

--
-- Table structure for table `wfh_schedule`
--

CREATE TABLE `wfh_schedule` (
  `id` int(11) NOT NULL,
  `tanggal_mulai` date NOT NULL,
  `tanggal_selesai` date NOT NULL,
  `keterangan` varchar(255) DEFAULT 'Work From Home',
  `berlaku_untuk` enum('semua','unit_kerja','personal') DEFAULT 'semua',
  `unit_kerja` varchar(100) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
--


--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_user_tanggal` (`user_id`,`tanggal`);

--
-- Indexes for table `cuti`
--
ALTER TABLE `cuti`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `tanggal_mulai` (`tanggal_mulai`),
  ADD KEY `tanggal_selesai` (`tanggal_selesai`),
  ADD KEY `status` (`status`);

--
-- Indexes for table `hari_libur`
--
ALTER TABLE `hari_libur`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `tanggal` (`tanggal`);

--
-- Indexes for table `izin`
--
ALTER TABLE `izin`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `wfa_schedule`
--
ALTER TABLE `wfa_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tanggal_mulai` (`tanggal_mulai`),
  ADD KEY `tanggal_selesai` (`tanggal_selesai`);

--
-- Indexes for table `wfh_schedule`
--
ALTER TABLE `wfh_schedule`
  ADD PRIMARY KEY (`id`),
  ADD KEY `tanggal_mulai` (`tanggal_mulai`),
  ADD KEY `tanggal_selesai` (`tanggal_selesai`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `cuti`
--
ALTER TABLE `cuti`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `hari_libur`
--
ALTER TABLE `hari_libur`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `izin`
--
ALTER TABLE `izin`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `wfa_schedule`
--
ALTER TABLE `wfa_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- AUTO_INCREMENT for table `wfh_schedule`
--
ALTER TABLE `wfh_schedule`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `cuti`
--
ALTER TABLE `cuti`
  ADD CONSTRAINT `cuti_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `izin`
--
ALTER TABLE `izin`
  ADD CONSTRAINT `izin_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Data awal untuk tabel settings (sesuaikan dengan kebutuhan)
--

INSERT INTO `settings` (`id`, `jam_masuk`, `jam_pulang`, `latitude`, `longitude`, `radius`, `toleransi_terlambat`, `wa_admin`, `base_url`, `maintenance_mode`, `maintenance_pesan`) VALUES
(1, '07:30:00', '16:00:00', '-7.36534400', '108.56056200', 200, 115, '628xxxxxxxxxx', 'http://localhost/absensi-nonasn', 0, '');

--
-- Akun admin default untuk testing
-- Ganti password setelah pertama kali login!
--

INSERT INTO `users` (`nama`, `username`, `password`, `role`, `tipe`, `status`) VALUES
('Administrator', 'admin', SHA2('admin123', 256), 'admin', 'pegawai', 'aktif');

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
