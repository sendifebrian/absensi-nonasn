<?php
define('BASE_PATH', dirname(__DIR__));
date_default_timezone_set('Asia/Jakarta');

$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            str_contains($_SERVER['HTTP_HOST'] ?? '', 'ngrok');

// ── Deteksi konteks QR atau Web ──────────────────────────────────────
$_current_file = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
$_is_qr_context = in_array($_current_file, ['qr.php', 'scan.php', 'proses_qr_absen.php', 'qr_handoff_token.php'])
    || (
        $_current_file === 'login.php' && (
            str_contains($_SERVER['QUERY_STRING'] ?? '', 'qr.php') ||
            str_contains($_SERVER['QUERY_STRING'] ?? '', 'scan.php') ||
            str_contains($_POST['return_url'] ?? '', 'qr.php') ||
            str_contains($_POST['return_url'] ?? '', 'scan.php')
        )
    );

// ══════════════════════════════════════════════════════════════════════
// SOLUSI DEFINITIF: session disimpan di folder TERPISAH per konteks
// Folder WEBSES: BASE_PATH/sessions/web/
// Folder QRSES:  BASE_PATH/sessions/qr/
// Dengan ini, tidak mungkin ada file session yang tercampur,
// bahkan jika session ID PHP kebetulan sama nilainya.
// ══════════════════════════════════════════════════════════════════════
$_sess_dir_web = BASE_PATH . '/sessions/web';
$_sess_dir_qr  = BASE_PATH . '/sessions/qr';

// Buat folder jika belum ada
if (!is_dir($_sess_dir_web)) @mkdir($_sess_dir_web, 0700, true);
if (!is_dir($_sess_dir_qr))  @mkdir($_sess_dir_qr,  0700, true);

$_sess_name = $_is_qr_context ? 'QRSES' : 'WEBSES';
$_sess_dir  = $_is_qr_context ? $_sess_dir_qr : $_sess_dir_web;

session_name($_sess_name);
session_save_path($_sess_dir);  // ← KUNCI: folder berbeda = tidak bisa bocor

session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $is_https,
    'httponly' => true,
    'samesite' => $is_https ? 'None' : 'Lax',
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Validasi konteks session (lapisan keamanan tambahan) ─────────────
// Jika sess_context tidak cocok, session ini bukan milik konteks ini.
// Pengecualian: qr_dashboard_mode=true berarti user masuk via QR handoff ke dashboard web,
// sehingga login_via='qr' tapi sess_context='web' — ini valid dan tidak boleh dihapus.
if (isset($_SESSION['user_id'])) {
    $via   = $_SESSION['login_via']        ?? '';
    $ctx   = $_SESSION['sess_context']     ?? '';
    $qr_dm = !empty($_SESSION['qr_dashboard_mode']); // mode QR handoff ke dashboard

    if ($_is_qr_context) {
        // Konteks QR: harus login_via='qr' dan sess_context='qr'
        $mismatch = ($via !== 'qr' || $ctx !== 'qr');
    } else {
        // Konteks Web: izinkan login_via='web' ATAU login_via='qr' jika qr_dashboard_mode aktif
        $mismatch = $ctx !== 'web' || ($via !== 'web' && !($via === 'qr' && $qr_dm));
    }

    if ($mismatch) {
        session_unset();
        session_destroy();
        session_start();
    }
}

require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/config/auth.php';
require_once BASE_PATH . '/config/helper.php';
require_once BASE_PATH . '/config/auto_logout.php';
require_once BASE_PATH . '/config/maintenance.php';
check_maintenance_mode($pdo);
