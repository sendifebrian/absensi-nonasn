<?php

// ── Helper: hitung base URL project dari SCRIPT_NAME ─────────────────
// Contoh: /absensi-nonasn/pegawai/dashboard.php → /absensi-nonasn
// Contoh: /absensi-nonasn/login.php             → /absensi-nonasn
function _auth_base_path(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $parts  = explode('/', trim($script, '/'));
    // Segmen pertama = nama folder project (misal: absensi-nonasn)
    return isset($parts[0]) && $parts[0] !== '' ? '/' . $parts[0] : '';
}

// ── Untuk halaman WEB: redirect ke login web jika belum login ─────────
function require_login(): void {
    if (!isset($_SESSION['user_id'])) {
        $base = _auth_base_path();
        header('Location: ' . $base . '/login.php');
        exit();
    }
}

// ── Untuk halaman QR: redirect ke login QR jika belum login ──────────
function require_qr_login(): void {
    if (!isset($_SESSION['user_id'])) {
        $base     = _auth_base_path();
        $qr_url   = $base . '/qr.php';
        $login_url = $base . '/login.php?return=' . urlencode($qr_url);
        header('Location: ' . $login_url);
        exit();
    }
}

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function is_pegawai(): bool {
    return ($_SESSION['role'] ?? '') === 'pegawai';
}

function is_active(): bool {
    return ($_SESSION['status'] ?? '') === 'aktif';
}