<?php
require_once __DIR__ . '/../config/init.php';
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_pegawai()) {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $jenis      = $_POST['jenis'] ?? 'izin'; // 'izin' atau 'sakit'
    $keterangan = trim($_POST['keterangan'] ?? '');
    $today      = date('Y-m-d');
    
    // Validasi
    if (!in_array($jenis, ['izin', 'sakit'])) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Jenis tidak valid.'];
        header('Location: absensi.php');
        exit();
    }
    if (!$keterangan) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Keterangan wajib diisi.'];
        header('Location: absensi.php');
        exit();
    }
    
    // Cek apakah sudah ada izin/sakit hari ini (langsung valid)
    $stmt = $pdo->prepare("SELECT id FROM izin WHERE user_id = ? AND tanggal = ? AND status = 'disetujui'");
    $stmt->execute([$_SESSION['user_id'], $today]);
    if ($stmt->fetch()) {
        $_SESSION['alert'] = ['type' => 'warning', 'message' => 'Anda sudah mengajukan izin/sakit hari ini.'];
        header('Location: absensi.php');
        exit();
    }
    
    // Simpan bukti jika ada
    $bukti = null;
    if (!empty($_FILES['bukti']['name'])) {
        $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
        $ext = strtolower(pathinfo($_FILES['bukti']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed) && $_FILES['bukti']['size'] <= 2097152) {
            $filename = 'izin_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
            $target = '../uploads/selfie/' . $filename;
            if (move_uploaded_file($_FILES['bukti']['tmp_name'], $target)) {
                $bukti = $filename;
            }
        }
    }
    
    // ✅ LANGSUNG DISIMPAN DENGAN STATUS 'disetujui' (tanpa approval)
    $stmt = $pdo->prepare("INSERT INTO izin (user_id, tanggal, jenis, keterangan, bukti, status) VALUES (?, ?, ?, ?, ?, 'disetujui')");
    $stmt->execute([$_SESSION['user_id'], $today, $jenis, $keterangan, $bukti]);
    
    $_SESSION['alert'] = [
        'type' => 'success', 
        'message' => '<strong>Berhasil!</strong> Pengajuan ' . ($jenis === 'sakit' ? 'sakit' : 'izin') . ' langsung valid. Anda tidak bisa absen hari ini.'
    ];
    header('Location: absensi.php');
    exit();
}
?>