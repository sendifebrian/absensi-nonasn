<?php
// ============================================================
// api/proses_qr_absen.php  — Absen via QR Code
// mode_login = 'qr' selalu, TANPA foto selfie
// ============================================================

// Tangkap semua output (termasuk PHP error/notice) agar tidak merusak JSON
ob_start();

// Handler error: kalau ada PHP error, return JSON error bukan HTML
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    ob_clean();
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $errstr . ' (line ' . $errline . ')',
        'type'    => 'server_error',
    ]);
    exit();
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Fatal error: ' . $err['message'] . ' (line ' . $err['line'] . ')',
            'type'    => 'server_error',
        ]);
    }
});

// ── Base path helper ──────────────────────────────────────────
$_script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$base_path   = rtrim(dirname($_script_dir), '/\\');

// ── Paksa QRSES sebelum init.php (init.php cek $_current_file) ──
// proses_qr_absen.php sudah ada di list _is_qr_context di init.php
// jadi cukup pastikan session belum dimulai sebelum init.php jalan
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/helper.php';

// ── Rate Limiter ──────────────────────────────────────────────
$now_ts = time();

$is_fresh_page = (isset($_SESSION['qr_page_opened'])
               && ($now_ts - $_SESSION['qr_page_opened']) < 30)
               || isset($_SESSION['qr_fresh_request']);

unset($_SESSION['qr_fresh_request']);

if (!$is_fresh_page
    && isset($_SESSION['last_qr_req'])
    && ($now_ts - $_SESSION['last_qr_req']) < 3
) {
    http_response_code(429);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'message' => 'Tunggu 3 detik sebelum scan lagi.',
        'type'    => 'rate_limited',
    ]);
    exit();
}

$_SESSION['last_qr_req'] = $now_ts;
unset($_SESSION['qr_page_opened']);

header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Jakarta');

// ── Helper Response ──────────────────────────────────────────
function respond($ok, $msg, $data = [], $type = 'info') {
    if (ob_get_level()) ob_clean();
    echo json_encode(array_merge([
        'success'   => $ok,
        'message'   => $msg,
        'type'      => $type,
        'timestamp' => date('Y-m-d H:i:s'),
    ], $data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (ob_get_level()) ob_end_flush();
    exit();
}

// ── 1. Validasi Input ─────────────────────────────────────────
$raw = json_decode(file_get_contents('php://input'), true);

if (!$raw
    || !isset($raw['latitude'])
    || !isset($raw['longitude'])
    || $raw['latitude'] === ''
    || $raw['longitude'] === ''
) {
    respond(false, 'Data lokasi tidak lengkap.', [], 'error');
}

$lat = floatval($raw['latitude']);
$lng = floatval($raw['longitude']);

if ($lat === 0.0 && $lng === 0.0) {
    respond(false, 'Koordinat GPS tidak valid (0,0). Coba lagi.', [], 'error');
}

// ── 2. Cek Session / Remember Me ─────────────────────────────
// ✅ FIX Bug 1: redirect pakai $base_path, bukan hardcode '/'
if (!validate_qr_session($pdo)) {
    respond(false, 'Sesi habis. Silakan login ulang.', [
        'redirect' => $base_path . '/login.php?return=' . urlencode($base_path . '/qr.php'),
        'type'     => 'login_required',
    ], 'warning');
}

// ── 3. Blokir non-pegawai ────────────────────────────────────
// ✅ FIX Bug 2: redirect pakai $base_path, bukan hardcode '/'
if (($_SESSION['role'] ?? '') !== 'pegawai') {
    error_log("QR Access Denied: Role '{$_SESSION['role']}' user_id={$_SESSION['user_id']} at " . date('Y-m-d H:i:s'));
    respond(false, 'Akses QR hanya untuk akun pegawai.', [
        'redirect' => $base_path . '/login.php',
        'type'     => 'role_not_allowed',
    ], 'warning');
}

$uid   = (int) $_SESSION['user_id'];
$tz    = new DateTimeZone('Asia/Jakarta');
$today = (new DateTime('now', $tz))->format('Y-m-d');
$now   = new DateTime('now', $tz);

// ── 4. Cek Hari Libur ────────────────────────────────────────
if (is_hari_libur($pdo, $today)) {
    respond(false, 'Hari ini adalah hari libur nasional. Tidak perlu absen.', [], 'warning');
}

// ── 5. Cek Cuti Aktif ────────────────────────────────────────
$stmtCuti = $pdo->prepare(
    "SELECT alasan FROM cuti
     WHERE user_id = ? AND ? BETWEEN tanggal_mulai AND tanggal_selesai AND status = 'disetujui'
     LIMIT 1"
);
$stmtCuti->execute([$uid, $today]);
$activeCuti = $stmtCuti->fetch(PDO::FETCH_ASSOC);
if ($activeCuti) {
    respond(false,
        'Anda sedang dalam periode cuti yang disetujui. Tidak dapat melakukan absensi.',
        ['type' => 'cuti_active', 'alasan' => $activeCuti['alasan'] ?? ''],
        'warning'
    );
}

// ── 6. Cek Izin Aktif ────────────────────────────────────────
$stmtIzin = $pdo->prepare(
    "SELECT jenis, keterangan FROM izin
     WHERE user_id = ? AND tanggal = ? AND status = 'disetujui'
     LIMIT 1"
);
$stmtIzin->execute([$uid, $today]);
$activeIzin = $stmtIzin->fetch(PDO::FETCH_ASSOC);
if ($activeIzin) {
    respond(false,
        'Anda memiliki izin ' . $activeIzin['jenis'] . ' yang disetujui hari ini.',
        ['type' => 'izin_active', 'jenis' => $activeIzin['jenis'] ?? ''],
        'warning'
    );
}

// ── 7. Ambil Settings ────────────────────────────────────────
$stmt = $pdo->query(
    "SELECT jam_masuk, jam_pulang, latitude, longitude, radius, toleransi_terlambat
     FROM settings LIMIT 1"
);
$set = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$set) {
    respond(false, 'Pengaturan kantor belum dikonfigurasi admin.', [], 'error');
}

// ── 8. Cek Status Absen Hari Ini ─────────────────────────────
$statusAbsen = cek_status_absen_hari_ini($pdo, $uid, $today);

// ── 9. Tentukan Tipe Absensi ─────────────────────────────────
if (!$statusAbsen['sudah_masuk']) {
    $type = 'masuk';
} elseif ($statusAbsen['sudah_masuk'] && !$statusAbsen['sudah_pulang']) {
    $type = 'pulang';
} else {
    // Sudah masuk DAN sudah pulang
    $record = $statusAbsen['record'];
    respond(true, 'Anda sudah absen masuk dan pulang hari ini.', [
        'type'       => 'done',
        'state'      => 'done',
        'jam_masuk'  => $record['jam_masuk']  ? date('H:i', strtotime($record['jam_masuk']))  : '--:--',
        'jam_pulang' => $record['jam_pulang'] ? date('H:i', strtotime($record['jam_pulang'])) : '--:--',
    ], 'info');
}

// ── 10. Idempotency guard ────────────────────────────────────
// ✅ FIX Bug 3: redirect pakai $base_path, bukan hardcode '/'
if ($type === 'masuk' && $statusAbsen['sudah_masuk']) {
    $existing = $statusAbsen['record'];
    respond(true, "Anda sudah absen masuk hari ini pukul " . date('H:i', strtotime($existing['jam_masuk'])) . ".", [
        'type'               => 'success',
        'status'             => $existing['status_masuk'] ?? 'tepat waktu',
        'jam'                => date('H:i', strtotime($existing['jam_masuk'])),
        'redirect'           => $base_path . '/pegawai/dashboard.php',
        'jam_pulang_setting' => date('H:i', strtotime($set['jam_pulang'])),
    ], 'success');
}

// ── 11. Validasi GPS (hanya untuk absen MASUK) ───────────────
if ($type === 'masuk') {
    $jarak = hitung_jarak_gps($set['latitude'], $set['longitude'], $lat, $lng);
    if ($jarak > $set['radius']) {
        respond(false,
            "Di luar radius kantor. Jarak Anda {$jarak}m, batas {$set['radius']}m.",
            ['distance' => round($jarak)],
            'error'
        );
    }
}
// Absen PULANG via QR: GPS tidak divalidasi radius (hanya disimpan)

// ── 12. Parse Jam & Toleransi ────────────────────────────────
$jm  = DateTime::createFromFormat('H:i:s', $set['jam_masuk'],  $tz);
$jp  = DateTime::createFromFormat('H:i:s', $set['jam_pulang'], $tz);
$tol = max(0, min(180, (int)($set['toleransi_terlambat'] ?? 30)));

$batas = clone $jm;
$batas->modify("+{$tol} minutes");

$nowHi   = (int)$now->format('Hi');
$jmHi    = (int)$jm->format('Hi');
$jpHi    = (int)$jp->format('Hi');
$batasHi = (int)$batas->format('Hi');

// ── 13. Tentukan Status ──────────────────────────────────────
$status = $detail = '';

if ($type === 'masuk') {
    if ($nowHi <= $batasHi) {
        $status = 'tepat waktu';
        $detail = "Tepat Waktu (batas {$batas->format('H:i')})";
    } else {
        $status  = 'terlambat';
        $selisih = abs(
            (int)$now->format('H') * 60 + (int)$now->format('i')
          - (int)$jm->format('H')  * 60 - (int)$jm->format('i')
        );
        $detail = "Terlambat {$selisih} menit dari jam {$jm->format('H:i')}";
    }
} elseif ($type === 'pulang') {
    if ($nowHi >= $jpHi) {
        $status = 'pulang tepat';
        $detail = 'Pulang Tepat Waktu';
    } else {
        // Belum waktunya pulang
        $nowMenit  = (int)$now->format('H') * 60 + (int)$now->format('i');
        $jpMenit   = (int)$jp->format('H')  * 60 + (int)$jp->format('i');
        $sisa      = $jpMenit - $nowMenit;
        $sisaStr   = floor($sisa / 60) > 0
            ? floor($sisa / 60) . " jam " . ($sisa % 60) . " menit"
            : "{$sisa} menit";

        respond(false,
            "Belum bisa absen pulang. Waktu pulang {$jp->format('H:i')} ({$sisaStr} lagi).",
            [
                'type'               => 'waiting_pulang',
                'state'              => 'waiting_pulang',
                'jam_masuk'          => $statusAbsen['record']
                    ? date('H:i', strtotime($statusAbsen['record']['jam_masuk']))
                    : '--:--',
                'jam_pulang_setting' => $jp->format('H:i'),
                'sisa_menit'         => $sisa,
            ],
            'warning'
        );
    }
}

// ── 14. Simpan ke Database ───────────────────────────────────
// mode_login / mode_login_pulang = 'qr' selalu untuk file ini
// Foto = NULL karena QR tidak pakai selfie
try {
    $pdo->beginTransaction();

    if ($type === 'pulang' && $statusAbsen['record']) {
        // Update pulang — mode_login_pulang = 'qr', foto_pulang = NULL
        $pdo->prepare(
            "UPDATE attendance
             SET jam_pulang=?, lat_pulang=?, lng_pulang=?,
                 status_pulang=?, mode_login_pulang='qr'
             WHERE id=?"
        )->execute([
            $now->format('H:i:s'), $lat, $lng, $status,
            $statusAbsen['record']['id'],
        ]);
    } else {
        // Insert masuk — mode_login = 'qr', foto_masuk = NULL
        $pdo->prepare(
            "INSERT INTO attendance
                (user_id, tanggal, jam_masuk, lat_masuk, lng_masuk,
                 status_masuk, mode, mode_login)
             VALUES (?, ?, ?, ?, ?, ?, 'wfo', 'qr')"
        )->execute([
            $uid, $today, $now->format('H:i:s'), $lat, $lng, $status,
        ]);
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    error_log("QR Absen Error [UID:{$uid}][{$today}]: " . $e->getMessage());
    respond(false, 'Gagal menyimpan data. Hubungi admin.', [], 'error');
}

// ── 15. Perbarui Remember Cookie ─────────────────────────────
if (isset($_COOKIE['qr_remember']) && $_COOKIE['qr_remember'] === '1') {
    $tok = generate_remember_token();
    update_remember_token($pdo, $uid, $tok);

    $cookieOpts = [
        'path'     => '/',
        'secure'   => is_https_request(),
        'httponly' => true,
        'samesite' => is_https_request() ? 'Strict' : 'Lax',
        'expires'  => time() + 2592000,
    ];
    setcookie('qr_remember', '1',  $cookieOpts);
    setcookie('qr_token',    $tok, $cookieOpts);
}

// ── 16. Ambil jam_masuk untuk response pulang ────────────────
$jam_masuk_resp = '--:--';
if ($type === 'pulang' && $statusAbsen['record']) {
    $jam_masuk_resp = date('H:i', strtotime($statusAbsen['record']['jam_masuk']));
}

// ── 17. Response Sukses ──────────────────────────────────────
// ✅ FIX Bug 4: redirect pakai $base_path, bukan hardcode '/'
respond(true, "Absen {$type} berhasil. {$detail}", [
    'type_absen'         => $type,
    'state'              => $type === 'pulang' ? 'done_pulang' : 'done_masuk',
    'status'             => $status,
    'jam'                => $now->format('H:i'),
    'jam_masuk'          => $type === 'pulang' ? $jam_masuk_resp : $now->format('H:i'),
    'detail'             => $detail,
    'redirect'           => $base_path . '/pegawai/dashboard.php',
    'jam_pulang_setting' => date('H:i', strtotime($set['jam_pulang'])),
], 'success');