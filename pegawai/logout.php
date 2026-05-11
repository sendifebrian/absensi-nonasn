<?php
// pegawai/logout.php - Logout user dengan cleanup token "Ingat Saya"

// ✅ FIX: Gunakan ../ untuk naik ke root project sebelum masuk ke config/
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/helper.php';

// Hapus remember token dari database + cookie (jika user login)
if (isset($_SESSION['user_id'])) {
    clear_remember_token($pdo, $_SESSION['user_id']);
}

// Hapus semua session
session_unset();
session_destroy();

// ✅ FIX: Redirect ke login.php di root (naik satu level dengan ../)
header('Location: ../login.php?logged_out=1');
exit();
?>