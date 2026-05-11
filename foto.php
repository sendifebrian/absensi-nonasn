<?php
// ============================================================
// foto.php — Proxy aman untuk melayani file dari /uploads/
// Hanya user yang sudah login yang bisa mengakses
// Contoh URL: foto.php?type=selfie&file=selfie_2_20260424.jpg
// ============================================================
require_once __DIR__ . '/config/init.php';
require_login();

$type     = $_GET['type'] ?? '';
$filename = $_GET['file']  ?? '';

// Hanya izinkan type yang dikenal
$allowed_types = ['selfie', 'bukti'];
if (!in_array($type, $allowed_types)) {
    http_response_code(400);
    exit('Tipe file tidak valid.');
}

// Sanitasi nama file — hapus path traversal (../, /, dll)
$filename = basename($filename);

// Izinkan gambar + PDF untuk kedua type
// (bukti izin/cuti/sakit juga disimpan di folder selfie/)
$ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
$allowed_ext = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

if (!in_array($ext, $allowed_ext)) {
    http_response_code(400);
    exit('Ekstensi file tidak diizinkan.');
}

// Pastikan file ada
$filepath = __DIR__ . '/uploads/' . $type . '/' . $filename;
if (!file_exists($filepath)) {
    http_response_code(404);
    exit('File tidak ditemukan.');
}

// Kirim file ke browser
$mime_types = [
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'pdf'  => 'application/pdf',
];

// PDF dibuka inline di browser (bukan download)
if ($ext === 'pdf') {
    header('Content-Disposition: inline; filename="' . $filename . '"');
}

header('Content-Type: ' . $mime_types[$ext]);
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=3600');
readfile($filepath);
exit();
?>
