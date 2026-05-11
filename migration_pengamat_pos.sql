-- ============================================================
-- MIGRASI: Tambah dukungan Pengamat Pos
-- Jalankan sekali di database absensi_nonasn
-- ============================================================

ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `tipe`     ENUM('pegawai','pengamat') NOT NULL DEFAULT 'pegawai' COMMENT 'Tipe pengguna: pegawai biasa atau pengamat pos' AFTER `role`,
    ADD COLUMN IF NOT EXISTS `nama_pos` VARCHAR(150)  DEFAULT NULL COMMENT 'Nama pos pengamatan (hanya untuk tipe=pengamat)' AFTER `tipe`,
    ADD COLUMN IF NOT EXISTS `lat_pos`  DECIMAL(11,8) DEFAULT NULL COMMENT 'Latitude koordinat pos pengamatan' AFTER `nama_pos`,
    ADD COLUMN IF NOT EXISTS `lng_pos`  DECIMAL(11,8) DEFAULT NULL COMMENT 'Longitude koordinat pos pengamatan' AFTER `lat_pos`;

-- Index untuk query tipe (opsional, untuk performa filter)
CREATE INDEX IF NOT EXISTS `idx_users_tipe` ON `users` (`tipe`);
