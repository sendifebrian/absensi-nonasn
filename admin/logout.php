<?php
// admin/logout.php - Logout admin dengan session WEBSES yang benar
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/helper.php';

// Hapus remember token dari database + cookie (jika ada)
if (isset($_SESSION['user_id'])) {
    clear_remember_token($pdo, $_SESSION['user_id']);
}

// Hapus semua session
session_unset();
session_destroy();

header('Location: ../login.php?logged_out=1');
exit();