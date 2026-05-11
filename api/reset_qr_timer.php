<?php
// api/reset_qr_timer.php
// Dipanggil dari tap overlay qr.php sebelum proses absen dimulai.
// Fungsi: reset rate limiter session agar request pertama tidak kena 429.

session_start();
header('Content-Type: application/json');

// Set flag bahwa halaman QR baru saja dibuka & user sudah tap
$_SESSION['qr_page_opened'] = time();

// Hapus timer lama agar request pertama langsung diizinkan
unset($_SESSION['last_qr_req']);

echo json_encode(['ok' => true, 'ts' => time()]);