<?php
// ============================================================
// CSRF Token Helpers
// ============================================================

if (!function_exists('csrf_generate')) {
    function csrf_generate(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('csrf_field')) {
    // Echo hidden input siap pakai di dalam form
    function csrf_field(): void {
        $token = csrf_generate();
        echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('csrf_verify')) {
    // Validasi token — panggil di awal handler POST, matikan jika gagal
    function csrf_verify(): void {
        $token      = $_POST['csrf_token'] ?? '';
        $valid      = $_SESSION['csrf_token'] ?? '';
        if (!$valid || !hash_equals($valid, $token)) {
            http_response_code(403);
            die('Permintaan tidak valid (CSRF). Silakan muat ulang halaman dan coba lagi.');
        }
        // Rotate token setelah dipakai
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

// ============================================================
// Foto URL Helper — akses foto lewat proxy foto.php
// ============================================================

if (!function_exists('foto_url')) {
    /**
     * Menghasilkan URL aman untuk menampilkan foto lewat foto.php
     * @param string|null $filename  Nama file saja (bukan path), misal: selfie_2_20260424.jpg
     * @param string      $type      'selfie' atau 'bukti'
     * @return string|null           URL lengkap ke foto.php, atau null jika filename kosong
     */
    function foto_url(?string $filename, string $type = 'selfie'): ?string {
        if (!$filename) return null;
        $filename = basename($filename); // pastikan tidak ada path traversal
        // Hitung subfolder project dari SCRIPT_NAME
        // Contoh: /absensi-nonasn/admin/dashboard.php → /absensi-nonasn
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $parts  = explode('/', trim($script, '/'));
        $base   = isset($parts[0]) && $parts[0] !== '' ? '/' . $parts[0] : '';
        return $base . '/foto.php?type=' . urlencode($type) . '&file=' . urlencode($filename);
    }
}

if (!function_exists('is_hari_libur')) {
    function is_hari_libur(PDO $pdo, string $tanggal): bool {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM hari_libur WHERE tanggal = ?");
        $stmt->execute([$tanggal]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

// ============================================================
// config/helper.php
// Helper functions untuk sistem absensi BBWS Citanduy
// ============================================================

/**
 * Cek apakah user tertentu sedang dalam jadwal WFH pada tanggal tertentu.
 */
function is_wfh_hari_ini(PDO $pdo, int $user_id, string $tanggal = ''): bool
{
    if (!$tanggal) {
        $tanggal = date('Y-m-d');
    }

    $stmt = $pdo->prepare("SELECT unit_kerja FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user       = $stmt->fetch();
    $unit_kerja = $user['unit_kerja'] ?? '';

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM wfh_schedule
        WHERE tanggal_mulai  <= :tgl1
          AND tanggal_selesai >= :tgl2
          AND (
                berlaku_untuk = 'semua'
             OR (berlaku_untuk = 'unit_kerja' AND unit_kerja = :uk)
             OR (berlaku_untuk = 'personal'   AND user_id   = :uid)
          )
    ");
    $stmt->execute([
        ':tgl1' => $tanggal,
        ':tgl2' => $tanggal,
        ':uk'   => $unit_kerja,
        ':uid'  => $user_id,
    ]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Cek apakah user tertentu sedang dalam jadwal WFA pada tanggal tertentu.
 */
function is_wfa_hari_ini(PDO $pdo, int $user_id, string $tanggal = ''): bool
{
    if (!$tanggal) {
        $tanggal = date('Y-m-d');
    }

    $stmt = $pdo->prepare("SELECT unit_kerja FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user       = $stmt->fetch();
    $unit_kerja = $user['unit_kerja'] ?? '';

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM wfa_schedule
        WHERE tanggal_mulai  <= :tgl1
          AND tanggal_selesai >= :tgl2
          AND (
                berlaku_untuk = 'semua'
             OR (berlaku_untuk = 'unit_kerja' AND unit_kerja = :uk)
             OR (berlaku_untuk = 'personal'   AND user_id   = :uid)
          )
    ");
    $stmt->execute([
        ':tgl1' => $tanggal,
        ':tgl2' => $tanggal,
        ':uk'   => $unit_kerja,
        ':uid'  => $user_id,
    ]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Format tanggal ke format Indonesia
 * Contoh: 2026-04-02 → Kamis, 02 April 2026
 */
function format_tanggal_id(string $tanggal): string
{
    $hari_id  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
    $bulan_id = ['','Januari','Februari','Maret','April','Mei','Juni',
                 'Juli','Agustus','September','Oktober','November','Desember'];

    $ts = strtotime($tanggal);
    return $hari_id[date('w', $ts)] . ', '
         . date('d', $ts) . ' '
         . $bulan_id[(int)date('m', $ts)] . ' '
         . date('Y', $ts);
}

/**
 * Sanitasi output HTML untuk mencegah XSS
 */
function e(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect ke URL tertentu lalu stop eksekusi
 */
function redirect(string $url): void
{
    header('Location: ' . $url);
    exit();
}

// ════════════════════════════════════════════════════════════════════════
// FUNGSI: HITUNG HARI KERJA EFEKTIF & ALPA (STANDAR INSTANSI)
// ════════════════════════════════════════════════════════════════════════

/**
 * Hitung jumlah hari kerja efektif dalam rentang tanggal
 * (Senin-Jumat, exclude libur nasional dari tabel hari_libur)
 */
function hitung_hari_kerja_efektif(PDO $pdo, string $start, string $end): int
{
    if ($start > $end) return 0;

    $awal  = new DateTime($start);
    $akhir = new DateTime($end);

    $period = new DatePeriod($awal, new DateInterval('P1D'), (clone $akhir)->modify('+1 day'));

    $stmt = $pdo->prepare("SELECT tanggal FROM hari_libur WHERE tanggal BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $libur = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $count = 0;
    foreach ($period as $tgl) {
        $hari = (int)$tgl->format('N');
        $ds   = $tgl->format('Y-m-d');
        if ($hari <= 5 && !isset($libur[$ds])) {
            $count++;
        }
    }
    return $count;
}

/**
 * Hitung jumlah alpa pegawai dalam rentang tanggal
 */
function hitung_alpa_pegawai(PDO $pdo, int $user_id, string $start, string $end): array
{
    if ($start > $end) return ['alpa' => 0, 'hari_kerja' => 0];

    $awal  = new DateTime($start);
    $akhir = new DateTime($end);

    $period = new DatePeriod($awal, new DateInterval('P1D'), (clone $akhir)->modify('+1 day'));

    $stmt = $pdo->prepare("SELECT tanggal FROM attendance WHERE user_id=? AND jam_masuk IS NOT NULL AND tanggal BETWEEN ? AND ?");
    $stmt->execute([$user_id, $start, $end]);
    $hadir = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare("SELECT tanggal FROM izin WHERE user_id=? AND status='disetujui' AND tanggal BETWEEN ? AND ?");
    $stmt->execute([$user_id, $start, $end]);
    $izin = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare("SELECT tanggal_mulai, tanggal_selesai FROM cuti WHERE user_id=? AND status='disetujui' AND tanggal_selesai >= ? AND tanggal_mulai <= ?");
    $stmt->execute([$user_id, $start, $end]);
    $cuti = [];
    foreach ($stmt->fetchAll() as $c) {
        $p = new DatePeriod(
            new DateTime($c['tanggal_mulai']),
            new DateInterval('P1D'),
            (clone new DateTime($c['tanggal_selesai']))->modify('+1 day')
        );
        foreach ($p as $d) $cuti[$d->format('Y-m-d')] = true;
    }

    $stmt = $pdo->prepare("SELECT tanggal FROM hari_libur WHERE tanggal BETWEEN ? AND ?");
    $stmt->execute([$start, $end]);
    $libur = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $stmt = $pdo->prepare("SELECT unit_kerja FROM users WHERE id=?");
    $stmt->execute([$user_id]);
    $uk = $stmt->fetchColumn() ?? '';

    $wfhWfaDates = [];
    foreach (['wfh_schedule', 'wfa_schedule'] as $tbl) {
        $stmt = $pdo->prepare("
            SELECT tanggal_mulai, tanggal_selesai FROM $tbl
            WHERE tanggal_selesai >= ? AND tanggal_mulai <= ?
              AND (berlaku_untuk = 'semua'
                OR (berlaku_untuk = 'unit_kerja' AND unit_kerja = ?)
                OR (berlaku_untuk = 'personal'   AND user_id   = ?))
        ");
        $stmt->execute([$start, $end, $uk, $user_id]);
        foreach ($stmt->fetchAll() as $s) {
            $p = new DatePeriod(
                new DateTime($s['tanggal_mulai']),
                new DateInterval('P1D'),
                (clone new DateTime($s['tanggal_selesai']))->modify('+1 day')
            );
            foreach ($p as $d) $wfhWfaDates[$d->format('Y-m-d')] = true;
        }
    }

    $alpa = 0; $hari_kerja = 0;
    foreach ($period as $tgl) {
        $ds   = $tgl->format('Y-m-d');
        $hari = (int)$tgl->format('N');

        if ($hari > 5) continue;
        if (isset($libur[$ds])) continue;

        $hari_kerja++;

        if (isset($hadir[$ds]) || isset($izin[$ds]) || isset($cuti[$ds]) || isset($wfhWfaDates[$ds])) {
            continue;
        }
        $alpa++;
    }

    return ['alpa' => $alpa, 'hari_kerja' => $hari_kerja];
}

// ════════════════════════════════════════════════════════════════════════
// HELPER: Cek apakah request via HTTPS
// ════════════════════════════════════════════════════════════════════════
if (!function_exists('is_https_request')) {
    function is_https_request(): bool {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || str_contains($_SERVER['HTTP_HOST'] ?? '', 'ngrok');
    }
}

// ════════════════════════════════════════════════════════════════════════
// FUNGSI BANTU QR CODE ATTENDANCE
// ════════════════════════════════════════════════════════════════════════

if (!function_exists('hitung_jarak_gps')) {
    function hitung_jarak_gps($lat1, $lon1, $lat2, $lon2): float {
        $R  = 6371000;
        $φ1 = deg2rad($lat1); $φ2 = deg2rad($lat2);
        $Δφ = deg2rad($lat2 - $lat1);
        $Δλ = deg2rad($lon2 - $lon1);
        $a  = sin($Δφ/2)**2 + cos($φ1)*cos($φ2)*sin($Δλ/2)**2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}

if (!function_exists('cek_status_absen_hari_ini')) {
    function cek_status_absen_hari_ini($pdo, $user_id, $tanggal) {
        $stmt = $pdo->prepare("SELECT id, jam_masuk, jam_pulang FROM attendance WHERE user_id = ? AND tanggal = ?");
        $stmt->execute([$user_id, $tanggal]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        return [
            'sudah_masuk'  => $record && !empty($record['jam_masuk']),
            'sudah_pulang' => $record && !empty($record['jam_pulang']),
            'record'       => $record,
        ];
    }
}

// ════════════════════════════════════════════════════════════════════════
// SESSION & REMEMBER TOKEN
// ════════════════════════════════════════════════════════════════════════
//
// QRSES  = cookie khusus scan QR di HP (login_via = 'qr')
// WEBSES = cookie browser web biasa   (login_via = 'web')
// Keduanya TIDAK pernah saling mengisi. init.php yang enforce ini.
// ════════════════════════════════════════════════════════════════════════

// ── validate_qr_session ───────────────────────────────────────────────
// Dipanggil dari qr.php dan proses_qr_absen.php (konteks QRSES).
if (!function_exists('validate_qr_session')) {
    function validate_qr_session($pdo): bool {
        if (isset($_SESSION['user_id']) &&
            ($_SESSION['role']         ?? '') === 'pegawai' &&
            ($_SESSION['login_via']    ?? '') === 'qr' &&
            ($_SESSION['sess_context'] ?? '') === 'qr'
        ) {
            return true;
        }
        if (!empty($_COOKIE['qr_remember']) && !empty($_COOKIE['qr_token'])) {
            $stmt = $pdo->prepare("
                SELECT id, username, nama, role, status FROM users
                WHERE  remember_token = ? AND status = 'aktif' AND role = 'pegawai'
                  AND  (remember_expires IS NULL OR remember_expires > NOW())
                LIMIT 1
            ");
            $stmt->execute([$_COOKIE['qr_token']]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($user) {
                $_SESSION['user_id']       = $user['id'];
                $_SESSION['username']      = $user['username'];
                $_SESSION['nama']          = $user['nama'];
                $_SESSION['role']          = $user['role'];
                $_SESSION['login_via']     = 'qr';
                $_SESSION['sess_context']  = 'qr';
                $_SESSION['remember_me']   = true;
                $_SESSION['last_activity'] = time();
                return true;
            }
        }
        return false;
    }
}

// ── restore_web_session ───────────────────────────────────────────────
// Dipanggil dari dashboard.php (konteks WEBSES). Tidak menyentuh QRSES.
if (!function_exists('restore_web_session')) {
    function restore_web_session($pdo): bool {
        // Session valid: login_via='web' ATAU login_via='qr' dengan qr_dashboard_mode aktif
        $via   = $_SESSION['login_via']        ?? '';
        $ctx   = $_SESSION['sess_context']     ?? '';
        $qr_dm = !empty($_SESSION['qr_dashboard_mode']);

        if (isset($_SESSION['user_id']) && $ctx === 'web'
            && ($via === 'web' || ($via === 'qr' && $qr_dm))) {
            return true;
        }
        // Ada data nyasar di WEBSES — bersihkan kecuali jika ini qr_dashboard_mode yang valid
        if (isset($_SESSION['user_id'])) {
            session_unset(); session_destroy(); session_start();
            return false;
        }
        if (empty($_COOKIE['web_remember']) || empty($_COOKIE['web_token'])) {
            return false;
        }
        $stmt = $pdo->prepare("
            SELECT id, username, nama, role, status FROM users
            WHERE  web_remember_token = ? AND status = 'aktif'
              AND  (web_remember_expires IS NULL OR web_remember_expires > NOW())
            LIMIT 1
        ");
        $stmt->execute([$_COOKIE['web_token']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) return false;
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['nama']          = $user['nama'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['login_via']     = 'web';
        $_SESSION['sess_context']  = 'web';
        $_SESSION['came_from_qr']  = false;
        $_SESSION['remember_me']   = true;
        $_SESSION['last_activity'] = time();
        return true;
    }
}

// ── restore_from_qr_handoff ───────────────────────────────────────────
// Dipanggil dari dashboard.php saat ada ?qr_from=TOKEN.
// Validasi token dari DB, set WEBSES bersih dengan came_from_qr=true.
if (!function_exists('restore_from_qr_handoff')) {
    function restore_from_qr_handoff($pdo): bool {
        $token = $_GET['qr_from'] ?? '';
        if (!$token) return false;

        // Validasi dari DB (qr_handoff_token) — paling reliable
        $stmt = $pdo->prepare("
            SELECT id, username, nama, role, status FROM users
            WHERE  qr_handoff_token = ? AND status = 'aktif' AND role = 'pegawai'
              AND  (qr_handoff_expires IS NULL OR qr_handoff_expires > NOW())
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Fallback: buka QRSES sebentar untuk cek session token
        if (!$user) {
            $web_name = session_name();
            $web_id   = session_id();
            session_write_close();
            session_name('QRSES');
            session_start();

            $st  = $_SESSION['qr_dashboard_token']   ?? '';
            $uid = $_SESSION['qr_dashboard_user_id'] ?? 0;
            $exp = $_SESSION['qr_dashboard_expires'] ?? 0;
            $ok  = $st && hash_equals($st, $token) && $uid && $exp > time();
            if ($ok) unset($_SESSION['qr_dashboard_token'], $_SESSION['qr_dashboard_user_id'], $_SESSION['qr_dashboard_expires']);

            session_write_close();
            session_name($web_name);
            session_id($web_id);
            session_start();

            if (!$ok || !$uid) return false;

            $s2 = $pdo->prepare("SELECT id, username, nama, role, status FROM users WHERE id = ? AND status = 'aktif' AND role = 'pegawai' LIMIT 1");
            $s2->execute([$uid]);
            $user = $s2->fetch(PDO::FETCH_ASSOC);
            if (!$user) return false;
        }

        // Hapus token one-time
        $pdo->prepare("UPDATE users SET qr_handoff_token = NULL, qr_handoff_expires = NULL WHERE id = ?")
            ->execute([$user['id']]);

        // Set WEBSES bersih — buang semua data lama dulu
        session_unset();
        $_SESSION['user_id']          = $user['id'];
        $_SESSION['username']         = $user['username'];
        $_SESSION['nama']             = $user['nama'];
        $_SESSION['role']             = $user['role'];
        // ── FIX: login_via tetap 'qr' agar navbar/sidebar tampil badge QR yang benar
        // sess_context tetap 'web' agar require_login() dan halaman pegawai tetap bisa diakses
        // qr_dashboard_mode = true sebagai flag tambahan yang tidak akan hilang saat navigate
        $_SESSION['login_via']        = 'qr';
        $_SESSION['sess_context']     = 'web';
        $_SESSION['came_from_qr']     = true;
        $_SESSION['qr_dashboard_mode'] = true;
        $_SESSION['remember_me']      = false;
        $_SESSION['last_activity']    = time();
        return true;
    }
}

// ════════════════════════════════════════════════════════════════════════
// FUNGSI LOGOUT: Hapus web remember token dari DB dan cookie
// ════════════════════════════════════════════════════════════════════════
if (!function_exists('clear_remember_token')) {
    function clear_remember_token(PDO $pdo, int $user_id): void {
        // Hapus token dari database
        try {
            $pdo->prepare("UPDATE users SET web_remember_token = NULL, web_remember_expires = NULL WHERE id = ?")
                ->execute([$user_id]);
        } catch (Exception $e) { /* silent */ }

        // Hapus web cookies
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                  || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
                  || str_contains($_SERVER['HTTP_HOST'] ?? '', 'ngrok');

        $cookie_opts = [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => $is_https,
            'httponly' => true,
            'samesite' => $is_https ? 'None' : 'Lax',
        ];
        setcookie('web_remember', '', $cookie_opts);
        setcookie('web_token',    '', $cookie_opts);
    }
}

// ════════════════════════════════════════════════════════════════════════
// FUNGSI QR REMEMBER TOKEN
// generate_remember_token() : buat token acak 48-char hex
// update_remember_token()   : simpan token QR ke DB (kolom remember_token)
// ════════════════════════════════════════════════════════════════════════
if (!function_exists('generate_remember_token')) {
    function generate_remember_token(): string {
        return bin2hex(random_bytes(24)); // 48 karakter hex, kriptografis aman
    }
}

if (!function_exists('update_remember_token')) {
    function update_remember_token(PDO $pdo, int $user_id, string $token): void {
        try {
            $expires = date('Y-m-d H:i:s', time() + 2592000); // 30 hari
            $pdo->prepare(
                "UPDATE users SET remember_token = ?, remember_expires = ? WHERE id = ?"
            )->execute([$token, $expires, $user_id]);
        } catch (Exception $e) { /* silent */ }
    }
}
