<?php
/**
 * api/qr_handoff_token.php
 * Paksa buka QRSES sendiri — TIDAK pakai init.php agar session tidak konflik
 */
ob_start();
session_name('QRSES');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (($_SESSION['login_via'] ?? '') !== 'qr' || empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit();
}

$token   = bin2hex(random_bytes(24));
$user_id = (int)$_SESSION['user_id'];

$_SESSION['qr_dashboard_token']   = $token;
$_SESSION['qr_dashboard_user_id'] = $user_id;
$_SESSION['qr_dashboard_expires'] = time() + 60;

session_write_close();

echo json_encode(['ok' => true, 'token' => $token]);
