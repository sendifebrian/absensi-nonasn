<?php
/**
 * config/auto_logout.php
 * Di-include dari config/init.php setelah session_start() dan $pdo siap.
 *
 * Aturan:
 * - Belum login (tidak ada user_id)  → skip
 * - login_via = 'qr'                 → tidak pernah timeout
 * - login_via = 'web' + remember     → tidak timeout (cookie 30 hari)
 * - login_via = 'web' + no remember  → timeout setelah INACTIVE_TIMEOUT detik
 */

define('INACTIVE_TIMEOUT', 30 * 60); // 30 menit

// ── Skip jika belum login ─────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    return;
}

$_al_via      = $_SESSION['login_via']   ?? 'web';
$_al_remember = $_SESSION['remember_me'] ?? false;

// ── QR atau remember me: tidak timeout ───────────────────────────────
if ($_al_via === 'qr' || $_al_remember) {
    $_SESSION['last_activity'] = time();
    return;
}

// ── Web tanpa remember: cek inaktivitas ──────────────────────────────
$_al_last = $_SESSION['last_activity'] ?? time();

if ((time() - $_al_last) <= INACTIVE_TIMEOUT) {
    $_SESSION['last_activity'] = time();
    return;
}

// ── TIMEOUT ───────────────────────────────────────────────────────────
$_al_uid = $_SESSION['user_id'] ?? null;

if ($_al_uid && isset($pdo)) {
    try {
        $pdo->prepare("
            UPDATE users
            SET web_remember_token = NULL, web_remember_expires = NULL
            WHERE id = ?
        ")->execute([$_al_uid]);
    } catch (Exception $e) { /* silent fail */ }
}

// Hapus web cookies saja (QR cookie dibiarkan)
$_al_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
          || str_contains($_SERVER['HTTP_HOST'] ?? '', 'ngrok');

$_al_cookie_del = [
    'expires'  => time() - 3600,
    'path'     => '/',
    'secure'   => $_al_https,
    'httponly' => true,
    'samesite' => $_al_https ? 'None' : 'Lax',
];
foreach (['web_remember', 'web_token'] as $_al_c) {
    setcookie($_al_c, '', $_al_cookie_del);
}

session_unset();
session_destroy();

// ── Hitung login URL yang benar ───────────────────────────────────────
// Strategi: gunakan BASE_PATH (filesystem) vs DOCUMENT_ROOT untuk
// menentukan URL root project secara akurat.
//
// Struktur project:
//   /absensi-nonasn/          ← project root
//   /absensi-nonasn/login.php
//   /absensi-nonasn/pegawai/dashboard.php
//   /absensi-nonasn/admin/dashboard.php
//   /absensi-nonasn/api/proses_qr_absen.php

$_al_scheme = $_al_https ? 'https' : 'http';
$_al_host   = $_SERVER['HTTP_HOST'];

// Metode 1: hitung URL root dari DOCUMENT_ROOT vs BASE_PATH (filesystem)
// Ini paling akurat dan tidak terpengaruh subfolder script yang sedang jalan.
if (defined('BASE_PATH') && !empty($_SERVER['DOCUMENT_ROOT'])) {
    $_al_doc_root  = realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'];
    $_al_base      = realpath(BASE_PATH) ?: BASE_PATH;
    // Selisih antara BASE_PATH dan DOCUMENT_ROOT = URL path project root
    // Misal: doc_root=/var/www/html, base=/var/www/html/absensi-nonasn
    //        → root_url = /absensi-nonasn
    $_al_root_url  = rtrim(substr($_al_base, strlen($_al_doc_root)), '/\\');
    // Pastikan diawali slash
    if ($_al_root_url !== '' && $_al_root_url[0] !== '/') {
        $_al_root_url = '/' . $_al_root_url;
    }
} else {
    // Metode 2 (fallback): pakai SCRIPT_NAME
    // Subfolder yang dikenal dalam project ini adalah 1 level: admin, pegawai, api, pages, config
    // Naik dari SCRIPT_NAME ke root project berdasarkan subfolder yang diketahui.
    $_al_script    = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $_al_page_dir  = dirname($_al_script);           // misal /absensi-nonasn/admin
    $_al_basename  = basename($_al_page_dir);        // misal admin

    if (in_array($_al_basename, ['admin', 'pegawai', 'api', 'pages', 'config'], true)) {
        $_al_root_url = rtrim(dirname($_al_page_dir), '/');   // naik 1 level
    } else {
        $_al_root_url = rtrim($_al_page_dir, '/');            // sudah di root
    }
    if ($_al_root_url === '/') $_al_root_url = '';
}

$_al_login_url = "{$_al_scheme}://{$_al_host}{$_al_root_url}/login.php?timeout=1";

header('Location: ' . $_al_login_url);
exit();