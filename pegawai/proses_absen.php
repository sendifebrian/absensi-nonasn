<?php
// ============================================================
// pegawai/proses_absen.php  — Absen via WEB (selfie + GPS)
// mode_login = 'web' selalu
// ============================================================
require_once __DIR__ . '/../config/init.php';

header('Content-Type: application/json');
date_default_timezone_set('Asia/Jakarta');

// ── 1. Cek sesi ──────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Sesi tidak valid.']);
    exit();
}

// ── 2. Ambil input ───────────────────────────────────────────
$input      = json_decode(file_get_contents('php://input'), true);
$type       = $input['type']       ?? null;
$latitude   = $input['latitude']   ?? null;
$longitude  = $input['longitude']  ?? null;
$fotoData   = $input['foto']       ?? null;
$mode       = $input['mode']       ?? 'wfo';
$alamat_wfh = $input['alamat']     ?? null;

// ── 3. Validasi type & foto ──────────────────────────────────
if (!in_array($type, ['masuk', 'pulang']) || !$fotoData) {
    echo json_encode(['success' => false, 'message' => 'Data absensi tidak lengkap (type/foto).']);
    exit();
}

// ── 4. Validasi format foto ──────────────────────────────────
$fotoData = trim($fotoData);
if (!preg_match('/^data:image\/(jpeg|jpg|png|webp);base64,/i', $fotoData)) {
    if (strpos($fotoData, 'data:') === 0) {
        $fotoData = substr($fotoData, 5);
    }
    if (!preg_match('/^image\/(jpeg|jpg|png|webp);base64,/i', $fotoData)) {
        echo json_encode(['success' => false, 'message' => 'Format foto tidak valid.']);
        exit();
    }
}

// ── 5. Cek hari libur ────────────────────────────────────────
$today = date('Y-m-d');
$stmt  = $pdo->prepare("SELECT COUNT(*) FROM hari_libur WHERE tanggal = ?");
$stmt->execute([$today]);
if ($stmt->fetchColumn()) {
    echo json_encode(['success' => false, 'message' => 'Hari ini hari libur. Tidak bisa absen.']);
    exit();
}

// ── 6. Cek cuti aktif ────────────────────────────────────────
$stmt = $pdo->prepare("SELECT alasan FROM cuti WHERE user_id=? AND ? BETWEEN tanggal_mulai AND tanggal_selesai AND status='disetujui' LIMIT 1");
$stmt->execute([$_SESSION['user_id'], $today]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Anda sedang dalam periode cuti yang disetujui.']);
    exit();
}

// ── 7. Cek izin aktif ────────────────────────────────────────
$stmt = $pdo->prepare("SELECT jenis FROM izin WHERE user_id=? AND tanggal=? AND status='disetujui' LIMIT 1");
$stmt->execute([$_SESSION['user_id'], $today]);
if ($stmt->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Anda sudah mengajukan izin/sakit hari ini.']);
    exit();
}

// ── 8. Ambil settings ────────────────────────────────────────
$stmt    = $pdo->query("SELECT * FROM settings LIMIT 1");
$setting = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$setting) {
    echo json_encode(['success' => false, 'message' => 'Pengaturan sistem belum diisi oleh admin.']);
    exit();
}

// ── 9. Waktu sekarang ────────────────────────────────────────
$tz       = new DateTimeZone('Asia/Jakarta');
$now      = new DateTime('now', $tz);
$today_tz = $now->format('Y-m-d');

$toleransi_menit = max(0, min(180, (int)($setting['toleransi_terlambat'] ?? 30)));

function makeJamDt(string $today_tz, string $jam_str, DateTimeZone $tz): DateTime {
    if (strlen($jam_str) === 5) $jam_str .= ':00';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $today_tz . ' ' . $jam_str, $tz);
    return $dt ?: new DateTime($today_tz . ' ' . $jam_str, $tz);
}

$jam_masuk_dt  = makeJamDt($today_tz, $setting['jam_masuk'],  $tz);
$jam_pulang_dt = makeJamDt($today_tz, $setting['jam_pulang'], $tz);

// ── 10. Validasi jam pulang ──────────────────────────────────
if ($type === 'pulang' && $now < $jam_pulang_dt) {
    echo json_encode([
        'success' => false,
        'message' => 'Belum waktunya absen pulang. Silakan tunggu hingga jam ' . $jam_pulang_dt->format('H:i') . '.'
    ]);
    exit();
}

// ── 11. Fungsi Haversine ─────────────────────────────────────
function getDistance($lat1, $lon1, $lat2, $lon2): float {
    $R  = 6371000;
    $φ1 = deg2rad($lat1); $φ2 = deg2rad($lat2);
    $Δφ = deg2rad($lat2 - $lat1);
    $Δλ = deg2rad($lon2 - $lon1);
    $a  = sin($Δφ/2)**2 + cos($φ1)*cos($φ2)*sin($Δλ/2)**2;
    return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

// ── 12. Validasi lokasi ──────────────────────────────────────
if ($mode === 'wfo') {
    if (!$latitude || !$longitude) {
        echo json_encode(['success' => false, 'message' => 'Lokasi tidak terdeteksi. Aktifkan GPS.']);
        exit();
    }
    // Hanya validasi radius untuk absen MASUK
    if ($type === 'masuk') {
        $jarak = getDistance($setting['latitude'], $setting['longitude'], $latitude, $longitude);
        if ($jarak > $setting['radius']) {
            echo json_encode([
                'success' => false,
                'message' => "Anda berada di luar radius {$setting['radius']} meter dari kantor."
            ]);
            exit();
        }
    }
} elseif ($mode === 'wfh') {
    $stmt = $pdo->prepare("SELECT lat_rumah, lng_rumah FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $rumah = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rumah['lat_rumah'] || !$rumah['lng_rumah']) {
        echo json_encode(['success' => false, 'message' => 'Koordinat rumah belum diatur di profil Anda.']);
        exit();
    }
    if (!$latitude || !$longitude) {
        echo json_encode(['success' => false, 'message' => 'Lokasi tidak terdeteksi. Aktifkan GPS untuk absen WFH.']);
        exit();
    }
    $jarak_rumah = getDistance($rumah['lat_rumah'], $rumah['lng_rumah'], $latitude, $longitude);
    if ($jarak_rumah > 100) {
        echo json_encode([
            'success' => false,
            'message' => "Anda berada di luar radius rumah ({$jarak_rumah}m). Absen WFH hanya berlaku dalam radius 100m."
        ]);
        exit();
    }
}
// mode 'wfa' → tidak ada validasi lokasi

// ── 13. Simpan foto selfie ───────────────────────────────────
list(, $imgData) = explode(',', $fotoData, 2);
$imgDecoded = base64_decode($imgData, true);
if ($imgDecoded === false || strlen($imgDecoded) < 100) {
    echo json_encode(['success' => false, 'message' => 'Data foto rusak atau tidak valid.']);
    exit();
}

$filename = 'selfie_' . $_SESSION['user_id'] . '_' . date('Ymd_His') . '.jpg';
$filepath = '../uploads/selfie/' . $filename;

if (!is_dir(dirname($filepath))) {
    mkdir(dirname($filepath), 0755, true);
}
if (!file_put_contents($filepath, $imgDecoded)) {
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan foto.']);
    exit();
}

// ── 14. Tentukan status ──────────────────────────────────────
$status_masuk  = null;
$status_pulang = null;

if ($type === 'masuk') {
    $batas_toleransi = clone $jam_masuk_dt;
    $batas_toleransi->modify("+{$toleransi_menit} minutes");
    $status_masuk = ($now <= $batas_toleransi) ? 'tepat waktu' : 'terlambat';
} else {
    $status_pulang = ($now >= $jam_pulang_dt) ? 'pulang tepat' : 'pulang awal';
}

// ── 15. Simpan ke database ───────────────────────────────────
// mode_login = 'web' selalu untuk file ini
$stmt = $pdo->prepare("SELECT id, jam_masuk FROM attendance WHERE user_id = ? AND tanggal = ?");
$stmt->execute([$_SESSION['user_id'], $today]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

try {
    $pdo->beginTransaction();

    if ($row) {
        $id = $row['id'];
        if ($type === 'masuk') {
            // Update absen masuk — set mode_login = 'web'
            $stmt = $pdo->prepare("
                UPDATE attendance
                SET jam_masuk=?, lat_masuk=?, lng_masuk=?, foto_masuk=?,
                    status_masuk=?, mode=?, alamat_wfh=?, mode_login='web'
                WHERE id=?
            ");
            $stmt->execute([
                $now->format('H:i:s'),
                ($mode === 'wfa') ? null : $latitude,
                ($mode === 'wfa') ? null : $longitude,
                $filename, $status_masuk, $mode,
                ($mode === 'wfh') ? $alamat_wfh : null,
                $id
            ]);
        } else {
            // Update absen pulang — set mode_login_pulang = 'web'
            $stmt = $pdo->prepare("
                UPDATE attendance
                SET jam_pulang=?, lat_pulang=?, lng_pulang=?,
                    foto_pulang=?, status_pulang=?, mode_login_pulang='web'
                WHERE id=?
            ");
            $stmt->execute([
                $now->format('H:i:s'),
                ($mode === 'wfa') ? null : $latitude,
                ($mode === 'wfa') ? null : $longitude,
                $filename, $status_pulang, $id
            ]);
        }
    } else {
        if ($type === 'masuk') {
            // Insert baru — mode_login = 'web'
            $stmt = $pdo->prepare("
                INSERT INTO attendance
                    (user_id, tanggal, mode, alamat_wfh, jam_masuk,
                     lat_masuk, lng_masuk, foto_masuk, status_masuk, mode_login)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'web')
            ");
            $stmt->execute([
                $_SESSION['user_id'], $today, $mode,
                ($mode === 'wfh') ? $alamat_wfh : null,
                $now->format('H:i:s'),
                ($mode === 'wfa') ? null : $latitude,
                ($mode === 'wfa') ? null : $longitude,
                $filename, $status_masuk
            ]);
        } else {
            // Insert baru langsung pulang (edge case) — mode_login_pulang = 'web'
            $stmt = $pdo->prepare("
                INSERT INTO attendance
                    (user_id, tanggal, mode, jam_pulang,
                     lat_pulang, lng_pulang, foto_pulang, status_pulang, mode_login_pulang)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'web')
            ");
            $stmt->execute([
                $_SESSION['user_id'], $today, $mode,
                $now->format('H:i:s'),
                ($mode === 'wfa') ? null : $latitude,
                ($mode === 'wfa') ? null : $longitude,
                $filename, $status_pulang
            ]);
        }
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    // Hapus foto yang sudah terlanjur disimpan
    @unlink($filepath);
    error_log("proses_absen.php Error [uid:{$_SESSION['user_id']}]: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Gagal menyimpan data. Hubungi admin.']);
    exit();
}

// ── 16. Response ─────────────────────────────────────────────
$modeLabel = match($mode) {
    'wfh'   => ' (WFH)',
    'wfa'   => ' (WFA)',
    default => ''
};

$statusInfo = '';
if ($type === 'masuk' && $status_masuk) {
    $batas_display = clone $jam_masuk_dt;
    $batas_display->modify("+{$toleransi_menit} minutes");
    if ($status_masuk === 'tepat waktu') {
        $statusInfo = ' — Tepat Waktu (batas ' . $batas_display->format('H:i') . ')';
    } else {
        $selisih    = $jam_masuk_dt->diff($now);
        $mntTelat   = ($selisih->h * 60 + $selisih->i);
        $statusInfo = " — Terlambat {$mntTelat} menit dari jam masuk ({$jam_masuk_dt->format('H:i')})";
    }
}

echo json_encode([
    'success' => true,
    'message' => "Absen {$type} berhasil.{$modeLabel}{$statusInfo}",
    'status'  => $type === 'masuk' ? $status_masuk : $status_pulang,
    'waktu'   => $now->format('H:i:s')
]);