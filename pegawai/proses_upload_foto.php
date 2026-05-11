<?php
require_once __DIR__ . '/../config/init.php';

if (!isset($_SESSION['user_id']) || !is_pegawai()) {
    echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$fotoData = $input['foto'] ?? '';

if (!$fotoData || strpos($fotoData, 'data:image') !== 0) {
    echo json_encode(['success' => false, 'message' => 'Foto tidak valid.']);
    exit();
}

list(, $imgData) = explode(',', $fotoData);
$imgDecoded = base64_decode($imgData);
$filename = 'profil_' . $_SESSION['user_id'] . '_' . time() . '.jpg';
$filepath = '../uploads/selfie/' . $filename;

if (!file_put_contents($filepath, $imgDecoded)) {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan foto.']);
    exit();
}

// Simpan ke database dengan status pending
$stmt = $pdo->prepare("UPDATE users SET foto = ?, status_foto = 'pending' WHERE id = ?");
if ($stmt->execute([$filename, $_SESSION['user_id']])) {
    echo json_encode(['success' => true, 'message' => 'Foto berhasil diupload.']);
} else {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan ke database.']);
}
?>