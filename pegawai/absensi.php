<?php
// ============================================================
// pegawai/absensi.php — Portal Absensi + Riwayat (GABUNGAN)
// Fix: channel QR/WEB (CASE WHEN), alpa konsisten, foto kondisional
// Update: pagination dengan teks Sebelumnya / Selanjutnya
// ============================================================
require_once __DIR__ . '/../config/init.php';
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_pegawai()) { header('Location: dashboard.php'); exit(); }

$uid   = (int)$_SESSION['user_id'];
$today = date('Y-m-d');
$now   = new DateTime('now', new DateTimeZone('Asia/Jakarta'));

// ── Cek hari libur ────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT COUNT(*) FROM hari_libur WHERE tanggal = ?");
$stmt->execute([$today]);
if ($stmt->fetchColumn()) {
    $_SESSION['alert'] = ['type'=>'info','message'=>'Hari ini hari libur. Tidak dapat melakukan absensi.'];
    header('Location: dashboard.php'); exit();
}

// ── Mode WFH/WFA ─────────────────────────────────────────────
$is_wfa = function_exists('is_wfa_hari_ini') && is_wfa_hari_ini($pdo, $uid, $today);
$is_wfh = !$is_wfa && function_exists('is_wfh_hari_ini') && is_wfh_hari_ini($pdo, $uid, $today);
$mode   = $is_wfa ? 'wfa' : ($is_wfh ? 'wfh' : 'wfo');

$koordinat_rumah = null;
if ($is_wfh) {
    $stmt = $pdo->prepare("SELECT lat_rumah, lng_rumah FROM users WHERE id = ?");
    $stmt->execute([$uid]);
    $koordinat_rumah = $stmt->fetch();
    if (!$koordinat_rumah['lat_rumah'] || !$koordinat_rumah['lng_rumah']) {
        $_SESSION['alert'] = ['type'=>'warning','message'=>'Koordinat rumah belum diatur. Silakan lengkapi profil Anda.'];
        header('Location: profil.php?setup_wfh=1'); exit();
    }
}

// ── Cek izin & cuti ──────────────────────────────────────────
$stmt = $pdo->prepare("SELECT jenis, keterangan FROM izin WHERE user_id=? AND tanggal=? AND status='disetujui' LIMIT 1");
$stmt->execute([$uid, $today]);
$izinRecord      = $stmt->fetch();
$sudah_izin      = (bool)$izinRecord;
$izin_jenis      = $izinRecord['jenis']      ?? null;
$izin_keterangan = $izinRecord['keterangan'] ?? null;

$stmt = $pdo->prepare("SELECT id,alasan,status FROM cuti WHERE user_id=? AND ? BETWEEN tanggal_mulai AND tanggal_selesai LIMIT 1");
$stmt->execute([$uid, $today]);
$cutiRecord  = $stmt->fetch();
$sudah_cuti  = $cutiRecord && $cutiRecord['status'] === 'disetujui';
$cuti_alasan = $cutiRecord['alasan'] ?? null;

// ── Filter riwayat ────────────────────────────────────────────
$filterBulan = $_GET['bulan'] ?? date('m');
$filterTahun = $_GET['tahun'] ?? date('Y');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 10;

// ── Rentang tanggal untuk bulan yang dipilih ─────────────────
$ab_range_awal  = "{$filterTahun}-{$filterBulan}-01";
$ab_range_akhir = date('Y-m-t', strtotime($ab_range_awal));

// ── Query riwayat hadir — CASE WHEN untuk channel ─────
// BUG FIX: tambahkan filter AND tanggal BETWEEN agar hanya ambil bulan dipilih
$stmt = $pdo->prepare("
    SELECT tanggal, jam_masuk, jam_pulang, status_masuk, status_pulang,
           foto_masuk, foto_pulang,
           CASE
               WHEN mode_login IS NOT NULL THEN mode_login
               WHEN foto_masuk IS NOT NULL THEN 'web'
               ELSE 'qr'
           END AS channel_masuk,
           CASE
               WHEN mode_login_pulang IS NOT NULL THEN mode_login_pulang
               WHEN foto_pulang IS NOT NULL THEN 'web'
               ELSE 'qr'
           END AS channel_pulang,
           'hadir' AS jenis, NULL AS keterangan
    FROM attendance
    WHERE user_id = ? AND jam_masuk IS NOT NULL
      AND tanggal BETWEEN ? AND ?
    ORDER BY tanggal DESC
");
$stmt->execute([$uid, $ab_range_awal, $ab_range_akhir]);
$riwayatHadir = $stmt->fetchAll();

// ── Query riwayat izin/sakit ──────────────────────────────────
// BUG FIX: tambahkan filter AND tanggal BETWEEN agar hanya ambil bulan dipilih
$stmt = $pdo->prepare("
    SELECT tanggal, NULL AS jam_masuk, NULL AS jam_pulang,
           NULL AS status_masuk, NULL AS status_pulang,
           NULL AS foto_masuk, NULL AS foto_pulang,
           NULL AS channel_masuk, NULL AS channel_pulang,
           jenis, keterangan,
           NULL AS tanggal_mulai, NULL AS tanggal_selesai
    FROM izin
    WHERE user_id = ? AND status = 'disetujui'
      AND tanggal BETWEEN ? AND ?
    ORDER BY tanggal DESC
");
$stmt->execute([$uid, $ab_range_awal, $ab_range_akhir]);
$riwayatIzin = $stmt->fetchAll();

// ── Query riwayat cuti — expand per hari kerja ───────────────
$stmtCuti = $pdo->prepare("
    SELECT tanggal_mulai, tanggal_selesai, alasan
    FROM cuti
    WHERE user_id = ? AND status = 'disetujui'
      AND tanggal_selesai >= ? AND tanggal_mulai <= ?
    ORDER BY tanggal_mulai DESC
");
$stmtCuti->execute([$uid, $ab_range_awal, $ab_range_akhir]);
$cutiRawRw = $stmtCuti->fetchAll();

// Load hari libur untuk skip tanggal merah
$liburStmtRw = $pdo->query("SELECT tanggal FROM hari_libur");
$liburDaysRw = array_flip($liburStmtRw->fetchAll(PDO::FETCH_COLUMN));

$riwayatCuti = [];
foreach ($cutiRawRw as $c) {
    // Batasi expand hanya ke dalam range bulan yang dipilih
    $expandMulai   = max($c['tanggal_mulai'], $ab_range_awal);
    $expandSelesai = min($c['tanggal_selesai'], $ab_range_akhir);
    $period = new DatePeriod(
        new DateTime($expandMulai),
        new DateInterval('P1D'),
        (new DateTime($expandSelesai))->modify('+1 day')
    );
    foreach ($period as $dt) {
        $ds   = $dt->format('Y-m-d');
        $hari = (int)$dt->format('N'); // 6=Sabtu, 7=Minggu
        if ($hari >= 6 || isset($liburDaysRw[$ds])) continue;
        $riwayatCuti[] = [
            'tanggal'         => $ds,
            'jam_masuk'       => null,
            'jam_pulang'      => null,
            'status_masuk'    => null,
            'status_pulang'   => null,
            'foto_masuk'      => null,
            'foto_pulang'     => null,
            'channel_masuk'   => null,
            'channel_pulang'  => null,
            'jenis'           => 'cuti',
            'keterangan'      => $c['alasan'],
            'tanggal_mulai'   => $c['tanggal_mulai'],
            'tanggal_selesai' => $c['tanggal_selesai'],
        ];
    }
}

// ── Gabung — semua data sudah difilter bulan/tahun di SQL & expand ──
// BUG FIX: tidak perlu filter PHP lagi karena semua query sudah pakai BETWEEN
$riwayatAll = array_merge($riwayatHadir, $riwayatIzin, $riwayatCuti);
usort($riwayatAll, fn($a,$b) => strcmp($b['tanggal'], $a['tanggal']));
$riwayatFiltered = array_values($riwayatAll);

// ── Pagination ────────────────────────────────────────────────
$totalRows   = count($riwayatFiltered);
$totalPages  = max(1, (int)ceil($totalRows / $perPage));
$page        = min($page, $totalPages);
$offset      = ($page - 1) * $perPage;
$riwayatPage = array_slice($riwayatFiltered, $offset, $perPage);

// ── Ringkasan statistik ───────────────────────────────────────
$rwHadir = $rwTerlambat = $rwIzinSakit = $rwCuti = 0;
foreach ($riwayatFiltered as $r) {
    if ($r['jenis'] === 'hadir')                     $rwHadir++;
    elseif (in_array($r['jenis'], ['izin','sakit']))  $rwIzinSakit++;
    elseif ($r['jenis'] === 'cuti')                   $rwCuti++;
    if (($r['status_masuk'] ?? '') === 'terlambat')   $rwTerlambat++;
}

// ── Hitung alpa konsisten ─────
$rwAlpa = 0;
if (function_exists('hitung_alpa_pegawai')) {
    $range_awal  = "{$filterTahun}-{$filterBulan}-01";
    $range_akhir = min(date('Y-m-t', strtotime($range_awal)), date('Y-m-d'));

    $stmtCAt    = $pdo->prepare("SELECT created_at FROM users WHERE id = ?");
    $stmtCAt->execute([$uid]);
    $rawCAt     = $stmtCAt->fetchColumn();
    $tgl_daftar = $rawCAt ? date('Y-m-d', strtotime($rawCAt)) : $range_awal;
    $start_hitung = max($range_awal, $tgl_daftar);

    if ($start_hitung <= $range_akhir) {
        $resAlpa = hitung_alpa_pegawai($pdo, $uid, $start_hitung, $range_akhir);
        $rwAlpa  = $resAlpa['alpa'];
    }
}

// ── Dropdown bulan & tahun ────────────────────────────────────
$namaBulanList = ['01'=>'Januari','02'=>'Februari','03'=>'Maret','04'=>'April','05'=>'Mei','06'=>'Juni',
                  '07'=>'Juli','08'=>'Agustus','09'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];
$tahunList     = range(date('Y'), date('Y') - 3);

// ── Status absensi hari ini ───────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id=? AND tanggal=?");
$stmt->execute([$uid, $today]);
$record = $stmt->fetch();

$sudah_masuk  = $record && $record['jam_masuk'];
$sudah_pulang = $record && $record['jam_pulang'];
$boleh_pulang = $sudah_masuk && !$sudah_pulang && !$sudah_izin && !$sudah_cuti;

// ── Settings jam kerja ────────────────────────────────────────
$stmt    = $pdo->query("SELECT jam_masuk, jam_pulang, latitude, longitude, radius, toleransi_terlambat FROM settings LIMIT 1");
$setting = $stmt->fetch(PDO::FETCH_ASSOC);
if (!isset($setting['toleransi_terlambat'])) $setting['toleransi_terlambat'] = 30;

date_default_timezone_set('Asia/Jakarta');
$nowDt = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
$today = $nowDt->format('Y-m-d');

$jam_masuk_dt  = DateTime::createFromFormat('Y-m-d H:i:s', $today . ' ' . $setting['jam_masuk'],  new DateTimeZone('Asia/Jakarta'));
$jam_pulang_dt = DateTime::createFromFormat('Y-m-d H:i:s', $today . ' ' . $setting['jam_pulang'], new DateTimeZone('Asia/Jakarta'));

$toleransi_menit       = max(0, min(180, (int)$setting['toleransi_terlambat']));
$batas_toleransi_dt    = clone $jam_masuk_dt;
$batas_toleransi_dt->modify("+{$toleransi_menit} minutes");
$batas_toleransi_display = $batas_toleransi_dt->format('H:i');
$waktunya_pulang         = $nowDt >= $jam_pulang_dt;

$nama_hari  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$nama_bulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$tanggal_indo = $nama_hari[date('w')] . ', ' . date('j') . ' ' . $nama_bulan[date('n')] . ' ' . date('Y');

// ── Helper path foto ─────────────────────────────────────────
function fotoUrl($filename) {
    return foto_url($filename);
}
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">

<style>
/* ============================================================
   CSS VARIABLES & RESET
   ============================================================ */
:root {
    --navy-950:#0a1628;--navy-900:#0f2241;--navy-800:#172d54;--navy-700:#1e3a6e;
    --navy-600:#25478a;--navy-300:#6d93d4;--navy-100:#dce8f8;--navy-50:#eef4fc;
    --gold-600:#b8963e;--gold-500:#c9a84c;--gold-400:#d4af37;--gold-300:#dfc06a;
    --gold-200:#edd89d;--gold-100:#f6edcd;--gold-50:#fdf8ed;
    --ash-700:#374151;--ash-500:#6b7280;--ash-400:#9ca3af;--ash-300:#d1d5db;
    --ash-200:#e5e7eb;--ash-100:#f3f4f6;--ash-50:#f9fafb;--white:#ffffff;
    --success:#059669;--warning:#d97706;--danger:#dc2626;--info:#0369a1;
    --radius-sm:8px;--radius-md:12px;--radius-lg:16px;--radius-xl:20px;
    --shadow-sm:0 1px 3px rgba(0,0,0,.08);
    --shadow-md:0 4px 12px rgba(0,0,0,.08);
    --shadow-lg:0 8px 24px rgba(0,0,0,.10);
    --shadow-gold:0 4px 16px rgba(212,175,55,.22);
    --transition:.22s cubic-bezier(.4,0,.2,1);
    --font:'Inter',system-ui,-apple-system,sans-serif;
}
.ab-page *,.ab-page *::before,.ab-page *::after{box-sizing:border-box;}
.ab-page{font-family:var(--font);color:var(--ash-700);background:var(--ash-50);min-height:100vh;padding:0 0 4rem;}

/* ── Hero ── */
.ab-hero{background:var(--navy-900);background-image:radial-gradient(ellipse 80% 60% at 70% -10%,rgba(180,150,50,.18) 0%,transparent 60%);padding:2.5rem 2rem 3.5rem;position:relative;overflow:hidden;}
.ab-hero::after{content:'';position:absolute;bottom:-1px;left:0;right:0;height:40px;background:var(--ash-50);clip-path:ellipse(55% 100% at 50% 100%);}
.ab-hero-inner{max-width:800px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:1.5rem;flex-wrap:wrap;}
.ab-hero-title{color:var(--white);font-size:1.5rem;font-weight:700;letter-spacing:-.02em;margin:0 0 .25rem;}
.ab-hero-date{color:rgba(255,255,255,.55);font-size:.82rem;display:flex;align-items:center;gap:.4rem;}
.ab-hero-meta{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);border-radius:var(--radius-md);padding:.75rem 1.25rem;text-align:center;}
.ab-hero-meta-label{color:rgba(255,255,255,.45);font-size:.7rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;display:block;margin-bottom:.25rem;}
.ab-hero-meta-value{color:var(--gold-300);font-size:.95rem;font-weight:700;}

/* ── Container ── */
.ab-container{max-width:800px;margin:0 auto;padding:0 1.25rem;transform:translateY(-1.5rem);}

/* ── Mode strip ── */
.ab-mode-strip{border-radius:var(--radius-lg);padding:1rem 1.25rem;display:flex;align-items:center;gap:1rem;margin-bottom:1.25rem;border:1px solid transparent;}
.ab-mode-strip.wfo{background:var(--navy-50);border-color:var(--navy-100);}
.ab-mode-strip.wfh{background:#ecfdf5;border-color:#a7f3d0;}
.ab-mode-strip.wfa{background:#f5f3ff;border-color:#c4b5fd;}
.ab-mode-strip.cuti{background:#eff6ff;border-color:#bfdbfe;}
.ab-mode-strip.izin{background:var(--gold-50);border-color:var(--gold-200);}
.ab-mode-icon{width:40px;height:40px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.ab-mode-icon svg{width:20px;height:20px;}
.ab-mode-strip.wfo .ab-mode-icon{background:var(--navy-100);color:var(--navy-700);}
.ab-mode-strip.wfh .ab-mode-icon{background:#d1fae5;color:#065f46;}
.ab-mode-strip.wfa .ab-mode-icon{background:#ede9fe;color:#5b21b6;}
.ab-mode-strip.cuti .ab-mode-icon{background:#dbeafe;color:#1e40af;}
.ab-mode-strip.izin .ab-mode-icon{background:var(--gold-100);color:var(--gold-600);}
.ab-mode-body{flex:1;}
.ab-mode-title{font-size:.875rem;font-weight:700;margin:0 0 .1rem;}
.ab-mode-strip.wfo .ab-mode-title{color:var(--navy-800);}
.ab-mode-strip.wfh .ab-mode-title{color:#065f46;}
.ab-mode-strip.wfa .ab-mode-title{color:#4c1d95;}
.ab-mode-strip.cuti .ab-mode-title{color:#1e40af;}
.ab-mode-strip.izin .ab-mode-title{color:var(--gold-600);}
.ab-mode-desc{font-size:.78rem;color:var(--ash-500);margin:0;}
.ab-mode-chip{font-size:.7rem;font-weight:700;letter-spacing:.06em;padding:.25rem .7rem;border-radius:20px;white-space:nowrap;text-transform:uppercase;}
.ab-mode-strip.wfo .ab-mode-chip{background:var(--navy-800);color:var(--white);}
.ab-mode-strip.wfh .ab-mode-chip{background:#10b981;color:var(--white);}
.ab-mode-strip.wfa .ab-mode-chip{background:#7c3aed;color:var(--white);}
.ab-mode-strip.cuti .ab-mode-chip{background:#3b82f6;color:var(--white);}
.ab-mode-strip.izin .ab-mode-chip{background:var(--gold-400);color:var(--navy-900);}

/* ── Status card ── */
.ab-status-card{background:var(--white);border:1px solid var(--ash-200);border-radius:var(--radius-lg);padding:1.5rem;display:flex;align-items:center;gap:1.25rem;margin-bottom:1.25rem;box-shadow:var(--shadow-sm);}
.ab-status-icon{width:52px;height:52px;border-radius:var(--radius-md);display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.ab-status-icon svg{width:24px;height:24px;}
.ab-status-icon.complete{background:#dcfce7;color:#16a34a;}
.ab-status-icon.masuk{background:#dbeafe;color:#1d4ed8;}
.ab-status-icon.cuti{background:#e0f2fe;color:#0369a1;}
.ab-status-icon.izin{background:var(--gold-100);color:var(--gold-600);}
.ab-status-icon.empty{background:var(--ash-100);color:var(--ash-400);}
.ab-status-body h5{font-size:1rem;font-weight:700;color:var(--navy-900);margin:0 0 .2rem;}
.ab-status-body p{font-size:.82rem;color:var(--ash-500);margin:0;}
.ab-status-body strong{color:var(--navy-800);}

/* ── Card umum ── */
.ab-card{background:var(--white);border:1px solid var(--ash-200);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);overflow:hidden;margin-bottom:1.25rem;transition:box-shadow var(--transition);}
.ab-card:hover{box-shadow:var(--shadow-md);}
.ab-card-header{padding:1.125rem 1.5rem;border-bottom:1px solid var(--ash-100);display:flex;align-items:center;gap:.75rem;}
.ab-card-header-icon{width:34px;height:34px;background:var(--gold-50);border:1px solid var(--gold-100);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:var(--gold-600);flex-shrink:0;}
.ab-card-header-icon svg{width:17px;height:17px;}
.ab-card-title{font-size:.92rem;font-weight:700;color:var(--navy-900);margin:0;}
.ab-card-body{padding:1.5rem;}

/* ── Select dropdown ── */
.ab-select-wrapper{position:relative;margin-bottom:1.25rem;}
.ab-select{width:100%;appearance:none;-webkit-appearance:none;background:var(--ash-50);border:1.5px solid var(--ash-200);border-radius:var(--radius-sm);padding:.85rem 3rem .85rem 1rem;font-size:.9rem;font-weight:600;color:var(--navy-900);cursor:pointer;transition:border-color var(--transition),box-shadow var(--transition);font-family:var(--font);}
.ab-select:focus{outline:none;border-color:var(--gold-400);box-shadow:0 0 0 3px rgba(212,175,55,.15);background:var(--white);}
.ab-select-arrow{position:absolute;right:1rem;top:50%;transform:translateY(-50%);pointer-events:none;color:var(--ash-400);}
.ab-select-arrow svg{width:18px;height:18px;}

/* ── Section panels ── */
.ab-section{display:none;}
.ab-section.visible{display:block;animation:abFadeSlide .3s cubic-bezier(.4,0,.2,1);}
@keyframes abFadeSlide{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

/* ── GPS bar ── */
.ab-gps-bar{border-radius:var(--radius-sm);padding:.7rem 1rem;font-size:.82rem;display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;transition:all .4s ease;}
.ab-gps-bar svg{width:16px;height:16px;flex-shrink:0;}
.ab-gps-bar.loading{background:var(--ash-100);color:var(--ash-500);border:1px solid var(--ash-200);}
.ab-gps-bar.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.ab-gps-bar.error{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3;}
.ab-gps-bar.wfa{background:#f5f3ff;color:#5b21b6;border:1px solid #c4b5fd;}

/* ── Kamera ── */
.ab-cam-wrap{text-align:center;}
.ab-cam-shell{position:relative;display:inline-block;width:100%;max-width:420px;border-radius:var(--radius-xl);overflow:hidden;background:#0a0f1a;aspect-ratio:4/3;box-shadow:0 8px 32px rgba(10,22,40,.35),0 0 0 1px rgba(255,255,255,.06);}
.ab-cam-shell video{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:1;transform:scaleX(-1);}
.ab-cam-preview{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:3;display:none;}
.ab-face-canvas{position:absolute;inset:0;width:100%;height:100%;z-index:2;pointer-events:none;}
.ab-face-status{display:inline-flex;align-items:center;gap:.4rem;margin-top:.625rem;font-size:.78rem;font-weight:600;letter-spacing:.03em;padding:.3rem .9rem;border-radius:20px;transition:all .3s ease;}
.ab-face-status.detecting{background:var(--ash-100);color:var(--ash-500);}
.ab-face-status.ok{background:#d1fae5;color:#065f46;}
.ab-face-status.no-face{background:#fee2e2;color:#991b1b;}
.ab-face-status .fs-dot{width:8px;height:8px;border-radius:50%;background:currentColor;flex-shrink:0;}
.ab-face-status.ok .fs-dot{animation:dotBlink 1.2s ease-in-out infinite;}
@keyframes dotBlink{0%,100%{opacity:1}50%{opacity:.3}}
.ab-cam-corner{position:absolute;width:20px;height:20px;border-color:var(--gold-400);border-style:solid;opacity:.7;z-index:4;}
.ab-cam-corner.tl{top:10px;left:10px;border-width:2px 0 0 2px;border-radius:4px 0 0 0;}
.ab-cam-corner.tr{top:10px;right:10px;border-width:2px 2px 0 0;border-radius:0 4px 0 0;}
.ab-cam-corner.bl{bottom:10px;left:10px;border-width:0 0 2px 2px;border-radius:0 0 0 4px;}
.ab-cam-corner.br{bottom:10px;right:10px;border-width:0 2px 2px 0;border-radius:0 0 4px 0;}
.ab-cam-status-bar{position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,.65));padding:.5rem 1rem .75rem;display:flex;align-items:center;justify-content:center;gap:.5rem;z-index:4;}
.ab-cam-dot{width:8px;height:8px;border-radius:50%;background:var(--gold-400);animation:pulseCam 1.5s infinite;}
@keyframes pulseCam{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.75)}}
.ab-cam-label{color:rgba(255,255,255,.8);font-size:.7rem;letter-spacing:.04em;font-weight:500;}
.ab-cam-loading{position:absolute;inset:0;z-index:5;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.75rem;background:#0a0f1a;}
.ab-cam-loading .spin{width:36px;height:36px;border:3px solid rgba(212,175,55,.2);border-top-color:var(--gold-400);border-radius:50%;animation:spin .75s linear infinite;}
@keyframes spin{to{transform:rotate(360deg)}}
.ab-cam-loading span{color:rgba(255,255,255,.5);font-size:.78rem;}
.ab-capture-btn{margin-top:.875rem;display:inline-flex;align-items:center;gap:.5rem;background:var(--navy-900);border:1.5px solid var(--gold-500);color:var(--gold-300);font-size:.875rem;font-weight:700;letter-spacing:.02em;padding:.7rem 1.75rem;border-radius:var(--radius-sm);cursor:pointer;transition:all var(--transition);font-family:var(--font);}
.ab-capture-btn svg{width:18px;height:18px;}
.ab-capture-btn:hover:not(:disabled){background:var(--gold-500);color:var(--navy-900);box-shadow:var(--shadow-gold);}
.ab-capture-btn:disabled{opacity:.4;cursor:not-allowed;}
.ab-capture-btn.retake{background:transparent;border-color:var(--ash-300);color:var(--ash-500);}
.ab-capture-btn.retake:hover:not(:disabled){background:var(--ash-100);color:var(--ash-700);box-shadow:none;}

/* ── Form field ── */
.ab-field{margin-bottom:1.25rem;}
.ab-label{display:block;font-size:.78rem;font-weight:700;color:var(--ash-500);letter-spacing:.06em;text-transform:uppercase;margin-bottom:.5rem;}
.ab-input,.ab-textarea{width:100%;background:var(--ash-50);border:1.5px solid var(--ash-200);border-radius:var(--radius-sm);padding:.75rem 1rem;font-size:.875rem;color:var(--navy-900);font-family:var(--font);transition:border-color var(--transition),box-shadow var(--transition);}
.ab-input:focus,.ab-textarea:focus{outline:none;border-color:var(--gold-400);box-shadow:0 0 0 3px rgba(212,175,55,.12);background:var(--white);}
.ab-textarea{resize:vertical;min-height:90px;}
.ab-file-label{display:flex;align-items:center;gap:.75rem;padding:.75rem 1rem;border:1.5px dashed var(--ash-200);border-radius:var(--radius-sm);cursor:pointer;transition:all var(--transition);color:var(--ash-500);font-size:.85rem;}
.ab-file-label:hover{border-color:var(--gold-400);background:var(--gold-50);color:var(--gold-600);}
.ab-file-label svg{width:20px;height:20px;}
.ab-file-input{display:none;}

/* ── Tombol submit ── */
.ab-submit-btn{width:100%;padding:.9rem 1.5rem;background:var(--navy-900);background-image:linear-gradient(135deg,var(--navy-800) 0%,var(--navy-950) 100%);border:1.5px solid var(--navy-700);border-radius:var(--radius-sm);color:var(--white);font-size:.9rem;font-weight:700;letter-spacing:.03em;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.6rem;transition:all var(--transition);font-family:var(--font);position:relative;overflow:hidden;}
.ab-submit-btn::after{content:'';position:absolute;inset:0;background:linear-gradient(135deg,rgba(212,175,55,.12),transparent);opacity:0;transition:opacity var(--transition);}
.ab-submit-btn:hover:not(:disabled)::after{opacity:1;}
.ab-submit-btn:hover:not(:disabled){border-color:var(--gold-500);box-shadow:0 4px 16px rgba(15,34,65,.35);transform:translateY(-1px);}
.ab-submit-btn:disabled{opacity:.45;cursor:not-allowed;transform:none!important;}
.ab-submit-btn svg{width:18px;height:18px;}
.ab-submit-btn .btn-chip{background:rgba(212,175,55,.18);border:1px solid rgba(212,175,55,.3);border-radius:4px;font-size:.68rem;padding:.1rem .45rem;letter-spacing:.08em;}
.ab-submit-btn.gold-variant{background:var(--gold-400);background-image:linear-gradient(135deg,var(--gold-300) 0%,var(--gold-600) 100%);border-color:var(--gold-500);color:var(--navy-900);}
.ab-submit-btn.gold-variant:hover:not(:disabled){box-shadow:var(--shadow-gold);}
.ab-submit-hint{display:flex;align-items:center;justify-content:center;gap:.35rem;font-size:.75rem;color:var(--ash-400);margin-top:.6rem;min-height:1.2em;transition:color .2s;}
.ab-submit-hint svg{width:13px;height:13px;}
.ab-submit-hint.hint-ready{color:var(--success);}
.ab-submit-hint.hint-warn{color:var(--warning);}

/* ── Alert ── */
.ab-alert{border-radius:var(--radius-sm);padding:.85rem 1.1rem;font-size:.83rem;display:flex;align-items:flex-start;gap:.65rem;margin-bottom:1rem;line-height:1.5;}
.ab-alert svg{width:16px;height:16px;flex-shrink:0;margin-top:.05rem;}
.ab-alert.warn{background:#fffbeb;border:1px solid #fde68a;color:#92400e;}
.ab-alert.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;}
.ab-divider{border:none;border-top:1px solid var(--ash-100);margin:1.25rem 0;}

/* ── Cuti CTA ── */
.ab-cuti-cta{background:var(--navy-50);border:1px solid var(--navy-100);border-radius:var(--radius-md);padding:1.25rem 1.5rem;display:flex;align-items:center;gap:1rem;text-decoration:none;transition:all var(--transition);margin-bottom:1.25rem;}
.ab-cuti-cta:hover{background:var(--navy-100);transform:translateY(-1px);box-shadow:var(--shadow-md);}
.ab-cuti-cta-icon{width:44px;height:44px;background:var(--navy-800);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:var(--gold-300);flex-shrink:0;}
.ab-cuti-cta-icon svg{width:22px;height:22px;}
.ab-cuti-cta-body h6{font-size:.875rem;font-weight:700;color:var(--navy-900);margin:0 0 .15rem;}
.ab-cuti-cta-body p{font-size:.78rem;color:var(--ash-500);margin:0;}
.ab-cuti-cta-arrow{margin-left:auto;color:var(--ash-400);}

/* ── Session alert ── */
.ab-session-alert{border-radius:var(--radius-md);padding:1rem 1.25rem;display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;font-size:.85rem;}
.ab-session-alert svg{width:20px;height:20px;flex-shrink:0;}
.ab-session-alert.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;}
.ab-session-alert.warning{background:var(--gold-50);border:1px solid var(--gold-200);color:var(--gold-600);}
.ab-session-alert.success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;}

/* ════════════════════════════════════════════════
   RIWAYAT ABSENSI
   ════════════════════════════════════════════════ */
.rw-header-actions{margin-left:auto;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
.rw-mini-select{font-size:.73rem;padding:.28rem .55rem;border:1px solid var(--ash-200);border-radius:6px;background:var(--ash-50);color:var(--navy-800);cursor:pointer;font-family:var(--font);}
.rw-mini-select:focus{outline:none;border-color:var(--gold-400);}
.rw-export-btn{display:inline-flex;align-items:center;gap:.35rem;font-size:.72rem;font-weight:700;padding:.3rem .75rem;border-radius:6px;background:var(--danger);color:#fff;text-decoration:none;letter-spacing:.03em;transition:all .2s;border:none;cursor:pointer;white-space:nowrap;}
.rw-export-btn:hover{background:#b91c1c;transform:translateY(-1px);}
.rw-export-btn svg{width:13px;height:13px;}

.rw-stats-row{display:grid;grid-template-columns:repeat(4,1fr);border-bottom:1px solid var(--ash-100);}
.rw-stat-item{padding:.875rem .5rem;text-align:center;border-right:1px solid var(--ash-100);}
.rw-stat-item:last-child{border-right:none;}
.rw-stat-num{display:block;font-size:1.4rem;font-weight:700;line-height:1;}
.rw-stat-lbl{display:block;font-size:.68rem;color:var(--ash-500);margin-top:.2rem;letter-spacing:.04em;}
.rw-stat-hadir .rw-stat-num{color:var(--success);}
.rw-stat-terlambat .rw-stat-num{color:var(--warning);}
.rw-stat-izin .rw-stat-num{color:var(--gold-600);}
.rw-stat-alpa .rw-stat-num{color:var(--danger);}

.rw-list{padding:.75rem 1rem 1rem;}
.rw-item{border:1px solid var(--ash-200);border-radius:10px;margin-bottom:.625rem;overflow:hidden;transition:border-color .2s;}
.rw-item.rw-open{border-color:var(--ash-300);}
.rw-row{display:flex;align-items:center;gap:.75rem;padding:.875rem 1rem;cursor:pointer;user-select:none;background:var(--white);transition:background .15s;}
.rw-row:hover{background:var(--ash-50);}
.rw-date-box{width:44px;height:44px;border-radius:8px;display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;}
.rw-date-num{font-size:1.2rem;font-weight:700;line-height:1;}
.rw-date-mon{font-size:.6rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;}
.rw-box-hadir{background:#e8f5e9;color:#065f46;}
.rw-box-terlambat{background:#fef3e2;color:#92400e;}
.rw-box-izin{background:#fdf8ed;color:var(--gold-600);}
.rw-box-sakit{background:#fee2e2;color:#9f1239;}
.rw-box-cuti{background:#e0e7ff;color:#3730a3;}
.rw-meta{flex:1;min-width:0;}
.rw-meta-top{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;}
.rw-meta-day{font-size:.855rem;font-weight:600;color:var(--navy-900);}
.rw-badge{font-size:.66rem;font-weight:700;padding:.18rem .5rem;border-radius:20px;letter-spacing:.03em;}
.rw-badge-hadir{background:#e8f5e9;color:#065f46;}
.rw-badge-terlambat{background:#fef3e2;color:#92400e;}
.rw-badge-izin{background:var(--gold-50);color:var(--gold-600);}
.rw-badge-sakit{background:#fee2e2;color:#9f1239;}
.rw-badge-cuti{background:#e0e7ff;color:#3730a3;}
.rw-chevron{color:var(--ash-400);transition:transform .25s cubic-bezier(.4,0,.2,1);flex-shrink:0;}
.rw-item.rw-open .rw-chevron{transform:rotate(180deg);}
.rw-detail{display:none;border-top:1px solid var(--ash-100);background:var(--ash-50);padding:1rem;}
.rw-item.rw-open .rw-detail{display:block;animation:rwSlide .22s cubic-bezier(.4,0,.2,1);}
@keyframes rwSlide{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.rw-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.875rem;}
.rw-detail-block{background:var(--white);border:1px solid var(--ash-200);border-radius:8px;padding:.75rem;}
.rw-detail-block-label{font-size:.66rem;font-weight:700;color:var(--ash-400);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.25rem;}
.rw-detail-block-val{font-size:1.05rem;font-weight:700;color:var(--navy-900);}
.rw-detail-block-sub{font-size:.72rem;margin-top:.15rem;}
.rw-sub-ok{color:var(--success);}
.rw-sub-warn{color:var(--warning);}

/* ── Badge channel QR / WEB ── */
.channel-badge{font-size:.58rem;font-weight:700;padding:1px 7px;border-radius:10px;letter-spacing:.04em;vertical-align:middle;margin-left:5px;display:inline-block;}
.channel-badge.qr{background:var(--navy-900);color:var(--gold-400);}
.channel-badge.web{background:#DBEAFE;color:#1E40AF;}

/* ── Foto ── */
.rw-foto-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;}
.rw-foto-card{background:var(--white);border:1px solid var(--ash-200);border-radius:8px;overflow:hidden;}
.rw-foto-card-label{font-size:.66rem;font-weight:700;color:var(--ash-400);text-transform:uppercase;letter-spacing:.07em;padding:.45rem .75rem;border-bottom:1px solid var(--ash-100);display:flex;align-items:center;}
.rw-foto-card-img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block;cursor:pointer;transition:opacity .2s;}
.rw-foto-card-img:hover{opacity:.88;}
.rw-foto-placeholder{width:100%;aspect-ratio:4/3;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.4rem;background:var(--ash-100);}
.rw-foto-placeholder svg{color:var(--ash-300);}
.rw-foto-placeholder span{font-size:.68rem;color:var(--ash-400);}
.rw-foto-placeholder .qr-note{font-size:.6rem;color:var(--ash-400);}

.rw-keterangan{margin-top:.75rem;padding:.625rem .875rem;background:var(--white);border:1px solid var(--ash-200);border-radius:8px;font-size:.82rem;color:var(--ash-500);line-height:1.55;}
.rw-keterangan strong{color:var(--ash-700);font-weight:700;}
.rw-empty{padding:2.5rem 1rem;text-align:center;font-size:.85rem;color:var(--ash-400);}

/* ════════════════════════════════════════════════
   PAGINATION — UPDATE dengan teks Sebelumnya/Selanjutnya
   ════════════════════════════════════════════════ */
.rw-pagination{
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:.875rem 1.25rem;
    border-top:1px solid var(--ash-100);
    gap:.75rem;
    flex-wrap:wrap;
}
.rw-page-info{font-size:.78rem;color:var(--ash-500);}
.rw-page-info strong{color:var(--navy-800);}
.rw-page-btns{
    display:flex;
    align-items:center;
    gap:.3rem;
    flex-wrap:wrap;
}
/* Tombol bulat angka */
.rw-page-btn{
    min-width:32px;
    height:32px;
    border-radius:6px;
    border:1px solid var(--ash-200);
    background:var(--white);
    color:var(--ash-500);
    font-size:.8rem;
    font-weight:600;
    cursor:pointer;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    transition:all .18s;
    text-decoration:none;
    padding:0 .4rem;
    white-space:nowrap;
    font-family:var(--font);
    line-height:1;
}
.rw-page-btn:hover:not(.disabled):not(.active){
    background:var(--ash-50);
    border-color:var(--ash-300);
    color:var(--navy-800);
}
.rw-page-btn.active{
    background:var(--navy-900);
    border-color:var(--navy-800);
    color:var(--white);
}
.rw-page-btn.disabled{
    opacity:.4;
    cursor:not-allowed;
    pointer-events:none;
}
.rw-page-btn svg{width:14px;height:14px;}

/* Tombol teks Sebelumnya / Selanjutnya */
.rw-page-btn.btn-nav-text{
    padding:0 .65rem;
    font-size:.75rem;
    gap:.25rem;
    color:var(--navy-800);
    border-color:var(--ash-200);
    background:var(--ash-50);
    font-weight:700;
}
.rw-page-btn.btn-nav-text:hover:not(.disabled){
    background:var(--navy-900);
    border-color:var(--navy-800);
    color:var(--white);
}
.rw-page-btn.btn-nav-text.disabled{
    color:var(--ash-400);
    background:var(--white);
}
.rw-page-ellipsis{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:28px;
    height:32px;
    font-size:.8rem;
    color:var(--ash-400);
    cursor:default;
    user-select:none;
}

/* ── Lightbox foto ── */
.rw-lightbox{position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,.85);display:flex;align-items:center;justify-content:center;opacity:0;pointer-events:none;transition:opacity .25s;}
.rw-lightbox.open{opacity:1;pointer-events:all;}
.rw-lightbox img{max-width:92vw;max-height:88vh;border-radius:8px;}
.rw-lightbox-close{position:absolute;top:1rem;right:1rem;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1.25rem;}
.rw-lightbox-close:hover{background:rgba(255,255,255,.22);}

/* ── Help FAB ── */
.ab-help-btn{position:fixed;bottom:2rem;right:2rem;width:52px;height:52px;background:var(--navy-900);border:1.5px solid var(--gold-500);border-radius:50%;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 4px 20px rgba(15,34,65,.4);color:var(--gold-300);transition:all var(--transition);z-index:900;}
.ab-help-btn svg{width:22px;height:22px;}
.ab-help-btn:hover{transform:scale(1.08);}

/* ── Responsive ── */
@media(max-width:600px){
    .ab-hero{padding:1.75rem 1.25rem 3rem;}
    .ab-hero-inner{flex-direction:column;}
    .ab-hero-meta{width:100%;}
    .ab-container{padding:0 .875rem;}
    .ab-cam-shell{max-width:100%;}
    .ab-status-card{flex-direction:column;text-align:center;}
    .rw-stats-row{grid-template-columns:repeat(2,1fr);}
    .rw-stat-item:nth-child(2){border-right:none;}
    .rw-stat-item:nth-child(3){border-top:1px solid var(--ash-100);}
    .rw-pagination{flex-direction:column;align-items:flex-start;gap:.5rem;}
    .rw-page-btns{gap:.25rem;}
    .rw-page-btn.btn-nav-text{font-size:.7rem;padding:0 .5rem;}
}
</style>

<!-- ══════════════════ HTML ══════════════════ -->
<div class="ab-page">

<div class="ab-hero">
    <div class="ab-hero-inner">
        <div data-aos="fade-right" data-aos-duration="600">
            <h1 class="ab-hero-title">Portal Absensi</h1>
            <p class="ab-hero-date">
                <svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14" style="opacity:.6"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd"/></svg>
                <?= $tanggal_indo ?>
            </p>
        </div>
        <div class="ab-hero-meta" data-aos="fade-left" data-aos-duration="600" data-aos-delay="100">
            <span class="ab-hero-meta-label">Jam Kerja</span>
            <span class="ab-hero-meta-value"><?= $jam_masuk_dt->format('H:i') ?> &ndash; <?= $jam_pulang_dt->format('H:i') ?></span>
        </div>
        <?php if (!$sudah_masuk && !$sudah_izin && !$sudah_cuti): ?>
        <div style="width:100%;text-align:center;font-size:.75rem;color:rgba(255,255,255,.55);margin-top:.25rem">
            Toleransi keterlambatan: <strong style="color:rgba(255,255,255,.75)"><?= $toleransi_menit ?> menit</strong>
            (batas tepat waktu: <?= $batas_toleransi_display ?>)
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="ab-container">

    <?php if (isset($_SESSION['alert'])): ?>
    <div class="ab-session-alert <?= $_SESSION['alert']['type'] ?>">
        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9z" clip-rule="evenodd"/></svg>
        <span><?= $_SESSION['alert']['message'] ?></span>
    </div>
    <?php unset($_SESSION['alert']); endif; ?>

    <!-- Mode Strip -->
    <?php $sc = $sudah_cuti ? 'cuti' : ($sudah_izin ? 'izin' : $mode); ?>
    <div class="ab-mode-strip <?= $sc ?>" data-aos="fade-up" data-aos-delay="80">
        <div class="ab-mode-icon">
            <?php if($sudah_cuti): ?><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 0 1 2-2h4.586A2 2 0 0 1 12 2.586L15.414 6A2 2 0 0 1 16 7.414V16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4zm2 6a1 1 0 0 1 1-1h6a1 1 0 1 1 0 2H7a1 1 0 0 1-1-1zm1 3a1 1 0 1 0 0 2h6a1 1 0 1 0 0-2H7z" clip-rule="evenodd"/></svg>
            <?php elseif($sudah_izin): ?><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9z" clip-rule="evenodd"/></svg>
            <?php elseif($is_wfa): ?><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM4.332 8.027a6.012 6.012 0 0 1 1.912-2.706C6.512 5.73 6.974 6 7.5 6A1.5 1.5 0 0 1 9 7.5V8a2 2 0 0 0 4 0 2 2 0 0 1 1.523-1.943A5.977 5.977 0 0 1 16 10c0 .34-.028.675-.083 1H15a2 2 0 0 0-2 2v2.197A5.973 5.973 0 0 1 10 16v-2a2 2 0 0 0-2-2 2 2 0 0 1-2-2 2 2 0 0 0-1.668-1.973z" clip-rule="evenodd"/></svg>
            <?php elseif($is_wfh): ?><svg viewBox="0 0 20 20" fill="currentColor"><path d="M9.293 2.293a1 1 0 0 1 1.414 0l7 7A1 1 0 0 1 17 11h-1v6a1 1 0 0 1-1 1h-2a1 1 0 0 1-1-1v-3a1 1 0 0 0-1-1H9a1 1 0 0 0-1 1v3a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1v-6H3a1 1 0 0 1-.707-1.707l7-7z"/></svg>
            <?php else: ?><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 16.5v-13h-.25a.75.75 0 0 1 0-1.5h12.5a.75.75 0 0 1 0 1.5H16v13h.25a.75.75 0 0 1 0 1.5h-3.5a.75.75 0 0 1-.75-.75v-2.5a.75.75 0 0 0-.75-.75h-2.5a.75.75 0 0 0-.75.75v2.5a.75.75 0 0 1-.75.75h-3.5a.75.75 0 0 1 0-1.5H4z" clip-rule="evenodd"/></svg><?php endif; ?>
        </div>
        <div class="ab-mode-body">
            <p class="ab-mode-title"><?php
                if($sudah_cuti) echo 'Cuti Disetujui';
                elseif($sudah_izin) echo ucfirst($izin_jenis).' Hari Ini';
                elseif($is_wfa) echo 'Work From Anywhere Aktif';
                elseif($is_wfh) echo 'Work From Home Aktif';
                else echo 'Work From Office';
            ?></p>
            <p class="ab-mode-desc"><?php
                if($sudah_cuti) echo htmlspecialchars($cuti_alasan);
                elseif($sudah_izin) echo htmlspecialchars($izin_keterangan ?: 'Tidak ada keterangan');
                elseif($is_wfa) echo 'Validasi GPS tidak diperlukan — foto selfie tetap wajib.';
                elseif($is_wfh) echo 'Validasi radius 100 m dari koordinat rumah yang telah terdaftar.';
                else echo 'Validasi GPS radius '.(int)$setting['radius'].' m dari lokasi kantor.';
            ?></p>
        </div>
        <span class="ab-mode-chip"><?php
            if($sudah_cuti) echo 'Cuti';
            elseif($sudah_izin) echo ucfirst($izin_jenis);
            else echo strtoupper($mode);
        ?></span>
    </div>

    <!-- Status card hari ini -->
    <div class="ab-status-card" data-aos="fade-up" data-aos-delay="140" id="statusCard">
        <?php if($sudah_pulang): ?>
        <div class="ab-status-icon complete"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5z" clip-rule="evenodd"/></svg></div>
        <div class="ab-status-body"><h5>Absensi Lengkap</h5><p>Masuk <strong><?= date('H:i',strtotime($record['jam_masuk'])) ?></strong> &bull; Pulang <strong><?= date('H:i',strtotime($record['jam_pulang'])) ?></strong></p></div>
        <?php elseif($sudah_cuti): ?>
        <div class="ab-status-icon cuti"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4 4a2 2 0 0 1 2-2h4.586A2 2 0 0 1 12 2.586L15.414 6A2 2 0 0 1 16 7.414V16a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V4z" clip-rule="evenodd"/></svg></div>
        <div class="ab-status-body"><h5>Cuti Disetujui</h5><p><?= htmlspecialchars($cuti_alasan) ?></p></div>
        <?php elseif($sudah_izin): ?>
        <div class="ab-status-icon izin"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9z" clip-rule="evenodd"/></svg></div>
        <div class="ab-status-body"><h5><?= ucfirst($izin_jenis) ?></h5><p><?= htmlspecialchars($izin_keterangan ?: 'Tanpa keterangan') ?></p></div>
        <?php elseif($sudah_masuk): ?>
        <div class="ab-status-icon masuk"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5z" clip-rule="evenodd"/></svg></div>
        <div class="ab-status-body"><h5>Sudah Absen Masuk</h5><p>Pukul <strong><?= date('H:i',strtotime($record['jam_masuk'])) ?></strong> &bull; Menunggu absen pulang</p></div>
        <?php else: ?>
        <div class="ab-status-icon empty"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg></div>
        <div class="ab-status-body"><h5>Belum Absensi</h5><p>Pilih jenis kehadiran di bawah untuk memulai.</p></div>
        <?php endif; ?>
    </div>

    <?php if(!$sudah_masuk && !$sudah_izin && !$sudah_cuti): ?>

    <!-- Dropdown jenis kehadiran -->
    <div class="ab-card" data-aos="fade-up" data-aos-delay="180" id="cardJenis">
        <div class="ab-card-header">
            <div class="ab-card-header-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2 4.75A.75.75 0 0 1 2.75 4h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 4.75zm0 10.5a.75.75 0 0 1 .75-.75h7.5a.75.75 0 0 1 0 1.5h-7.5a.75.75 0 0 1-.75-.75zM2 10a.75.75 0 0 1 .75-.75h14.5a.75.75 0 0 1 0 1.5H2.75A.75.75 0 0 1 2 10z" clip-rule="evenodd"/></svg></div>
            <h6 class="ab-card-title">Jenis Kehadiran</h6>
        </div>
        <div class="ab-card-body">
            <div class="ab-select-wrapper">
                <select class="ab-select" id="selectJenis" onchange="onJenisChange(this.value)">
                    <option value="">— Pilih jenis kehadiran —</option>
                    <option value="masuk">Absen Masuk &nbsp;&bull;&nbsp; <?= strtoupper($mode) ?></option>
                    <option value="izin">Izin &nbsp;&bull;&nbsp; Keperluan Mendesak</option>
                    <option value="sakit">Sakit &nbsp;&bull;&nbsp; Tanpa Surat Dokter</option>
                </select>
                <div class="ab-select-arrow"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06z" clip-rule="evenodd"/></svg></div>
            </div>
        </div>
    </div>

    <!-- Panel Masuk -->
    <div id="panelMasuk" class="ab-section">
        <div class="ab-card">
            <div class="ab-card-header">
                <div class="ab-card-header-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4.25A2.25 2.25 0 0 1 5.25 2h5.5A2.25 2.25 0 0 1 13 4.25v2a.75.75 0 0 1-1.5 0v-2a.75.75 0 0 0-.75-.75h-5.5a.75.75 0 0 0-.75.75v11.5c0 .414.336.75.75.75h5.5a.75.75 0 0 0 .75-.75v-2a.75.75 0 0 1 1.5 0v2A2.25 2.25 0 0 1 10.75 18h-5.5A2.25 2.25 0 0 1 3 15.75V4.25z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M6 10a.75.75 0 0 1 .75-.75h9.546l-1.048-.943a.75.75 0 1 1 1.004-1.114l2.5 2.25a.75.75 0 0 1 0 1.114l-2.5 2.25a.75.75 0 1 1-1.004-1.114l1.048-.943H6.75A.75.75 0 0 1 6 10z" clip-rule="evenodd"/></svg></div>
                <h6 class="ab-card-title">Absen Masuk <span style="font-size:.72rem;font-weight:600;color:var(--ash-400);margin-left:.4rem"><?= strtoupper($mode) ?></span></h6>
            </div>
            <div class="ab-card-body">
                <?php if($is_wfa): ?>
                <div class="ab-gps-bar wfa" id="gpsBarMasuk">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM4.332 8.027a6.012 6.012 0 0 1 1.912-2.706C6.512 5.73 6.974 6 7.5 6A1.5 1.5 0 0 1 9 7.5V8a2 2 0 0 0 4 0 2 2 0 0 1 1.523-1.943A5.977 5.977 0 0 1 16 10c0 .34-.028.675-.083 1H15a2 2 0 0 0-2 2v2.197A5.973 5.973 0 0 1 10 16v-2a2 2 0 0 0-2-2 2 2 0 0 1-2-2 2 2 0 0 0-1.668-1.973z" clip-rule="evenodd"/></svg>
                    <span><strong>Mode WFA</strong> &mdash; Validasi lokasi tidak diperlukan.</span>
                </div>
                <?php else: ?>
                <div class="ab-gps-bar loading" id="gpsBarMasuk">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.69 18.933l.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 0 0 .281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 1 0 3 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 0 0 2.273 1.765 11.842 11.842 0 0 0 .976.544l.062.029.018.008.006.003zM10 11.25a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5z" clip-rule="evenodd"/></svg>
                    <span><?= $is_wfh ? 'Mendeteksi lokasi WFH…' : 'Mendeteksi lokasi GPS…' ?></span>
                </div>
                <?php endif; ?>

                <?php if($is_wfh): ?>
                <div class="ab-field" style="margin-top:.75rem">
                    <label class="ab-label">Alamat WFH <span style="opacity:.55;font-weight:400;text-transform:none;letter-spacing:0">(opsional)</span></label>
                    <input type="text" id="alamatWFH" class="ab-input" placeholder="Cth: Jl. Mawar No. 5, Ciamis">
                </div>
                <?php endif; ?>

                <div class="ab-cam-wrap">
                    <div class="ab-cam-shell" id="camShellMasuk">
                        <div class="ab-cam-loading" id="camLoadMasuk"><div class="spin"></div><span>Memuat kamera…</span></div>
                        <video id="videoMasuk" autoplay playsinline muted></video>
                        <canvas id="faceCanvasMasuk" class="ab-face-canvas"></canvas>
                        <img id="previewMasuk" class="ab-cam-preview" alt="Selfie">
                        <div class="ab-cam-corner tl"></div><div class="ab-cam-corner tr"></div>
                        <div class="ab-cam-corner bl"></div><div class="ab-cam-corner br"></div>
                        <div class="ab-cam-status-bar"><div class="ab-cam-dot" id="camDotMasuk"></div><span class="ab-cam-label" id="camLabelMasuk">KAMERA AKTIF</span></div>
                    </div>
                    <div class="ab-face-status detecting" id="faceStatusMasuk"><span class="fs-dot"></span><span id="faceTextMasuk">Mendeteksi wajah…</span></div>
                    <br>
                    <button class="ab-capture-btn" id="captureMasuk" disabled>
                        <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/><path fill-rule="evenodd" d="M.664 10.59a1.651 1.651 0 0 1 0-1.186A10.004 10.004 0 0 1 10 3c4.257 0 7.893 2.66 9.336 6.41.147.381.146.804 0 1.186A10.004 10.004 0 0 1 10 17c-4.257 0-7.893-2.66-9.336-6.41zM14 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0z" clip-rule="evenodd"/></svg>
                        Ambil Foto Selfie
                    </button>
                </div>

                <hr class="ab-divider">
                <form id="formMasuk">
                    <input type="hidden" name="type"      value="masuk">
                    <input type="hidden" name="latitude"  id="latMasuk"  value="0">
                    <input type="hidden" name="longitude" id="lngMasuk"  value="0">
                    <input type="hidden" name="foto"      id="fotoMasuk">
                    <input type="hidden" name="mode"      value="<?= $mode ?>">
                    <button type="submit" class="ab-submit-btn" id="submitMasuk" disabled>
                        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M3.105 2.289a.75.75 0 0 0-.826.95l1.414 4.925A1.5 1.5 0 0 0 5.135 9.25h6.115a.75.75 0 0 1 0 1.5H5.135a1.5 1.5 0 0 0-1.442 1.086l-1.414 4.926a.75.75 0 0 0 .826.95 28.896 28.896 0 0 0 15.293-7.154.75.75 0 0 0 0-1.115A28.897 28.897 0 0 0 3.105 2.289z"/></svg>
                        Kirim Absen Masuk <span class="btn-chip"><?= strtoupper($mode) ?></span>
                    </button>
                    <div class="ab-submit-hint" id="hintMasuk">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9z" clip-rule="evenodd"/></svg>
                        Foto selfie diperlukan untuk melanjutkan
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Panel Izin/Sakit -->
    <div id="panelIzin" class="ab-section">
        <div class="ab-card">
            <div class="ab-card-header">
                <div class="ab-card-header-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9z" clip-rule="evenodd"/></svg></div>
                <h6 class="ab-card-title" id="izinCardTitle">Pengajuan Izin</h6>
            </div>
            <div class="ab-card-body">
                <div class="ab-alert warn" style="margin-bottom:1.25rem">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 5zm0 9a1 1 0 1 0 0-2 1 1 0 0 0 0 2z" clip-rule="evenodd"/></svg>
                    <span>Setelah dikirim, status langsung <strong>valid</strong> dan Anda tidak dapat absen hari ini.</span>
                </div>
                <form method="POST" enctype="multipart/form-data" action="proses_izin.php">
                    <input type="hidden" name="jenis" id="izinJenisVal">
                    <div class="ab-field">
                        <label class="ab-label">Keterangan</label>
                        <textarea name="keterangan" class="ab-textarea" placeholder="Contoh: Urusan keluarga mendesak…" required></textarea>
                    </div>
                    <div class="ab-field">
                        <label class="ab-label">Dokumen Pendukung <span style="opacity:.55;font-weight:400;text-transform:none;letter-spacing:0">(opsional)</span></label>
                        <label class="ab-file-label">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M15.621 4.379a3 3 0 0 0-4.242 0l-7 7a3 3 0 0 0 4.241 4.243h.001l.497-.5a.75.75 0 0 1 1.064 1.057l-.498.501-.002.002a4.5 4.5 0 0 1-6.364-6.364l7-7a4.5 4.5 0 0 1 6.368 6.36l-3.455 3.553A2.625 2.625 0 1 1 9.52 9.52l3.45-3.451a.75.75 0 1 1 1.061 1.06l-3.45 3.451a1.125 1.125 0 0 0 1.587 1.595l3.454-3.553a3 3 0 0 0 0-4.243z" clip-rule="evenodd"/></svg>
                            <span id="fileLabelText">Pilih file &bull; JPG, PNG, PDF &bull; Maks 2 MB</span>
                            <input type="file" name="bukti" class="ab-file-input" accept=".jpg,.jpeg,.png,.pdf" onchange="onFileChange(this)">
                        </label>
                    </div>
                    <button type="submit" class="ab-submit-btn gold-variant">
                        <svg viewBox="0 0 20 20" fill="currentColor"><path d="M3.105 2.289a.75.75 0 0 0-.826.95l1.414 4.925A1.5 1.5 0 0 0 5.135 9.25h6.115a.75.75 0 0 1 0 1.5H5.135a1.5 1.5 0 0 0-1.442 1.086l-1.414 4.926a.75.75 0 0 0 .826.95 28.896 28.896 0 0 0 15.293-7.154.75.75 0 0 0 0-1.115A28.897 28.897 0 0 0 3.105 2.289z"/></svg>
                        <span id="izinSubmitLabel">Kirim Pengajuan</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Cuti CTA -->
    <?php if(!$sudah_cuti && !$sudah_izin && !$sudah_masuk): ?>
    <a href="cuti.php" class="ab-cuti-cta" data-aos="fade-up" data-aos-delay="220" id="cardCuti">
        <div class="ab-cuti-cta-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd"/></svg></div>
        <div class="ab-cuti-cta-body"><h6>Ajukan Cuti Resmi</h6><p>Cuti tahunan &bull; memerlukan persetujuan atasan</p></div>
        <div class="ab-cuti-cta-arrow"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 10a.75.75 0 0 1 .75-.75h10.638L10.23 5.29a.75.75 0 1 1 1.04-1.08l5.5 5.25a.75.75 0 0 1 0 1.08l-5.5 5.25a.75.75 0 1 1-1.04-1.08l4.158-3.96H3.75A.75.75 0 0 1 3 10z" clip-rule="evenodd"/></svg></div>
    </a>
    <?php endif; ?>

    <?php endif; ?>

    <!-- Absen Pulang -->
    <?php if($boleh_pulang): ?>
    <div class="ab-card" data-aos="fade-up" data-aos-delay="200" id="cardPulang">
        <div class="ab-card-header">
            <div class="ab-card-header-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4.25A2.25 2.25 0 0 1 5.25 2h5.5A2.25 2.25 0 0 1 13 4.25v2a.75.75 0 0 1-1.5 0v-2a.75.75 0 0 0-.75-.75h-5.5a.75.75 0 0 0-.75.75v11.5c0 .414.336.75.75.75h5.5a.75.75 0 0 0 .75-.75v-2a.75.75 0 0 1 1.5 0v2A2.25 2.25 0 0 1 10.75 18h-5.5A2.25 2.25 0 0 1 3 15.75V4.25z" clip-rule="evenodd"/><path fill-rule="evenodd" d="M19 10a.75.75 0 0 0-.75-.75H8.704l1.048-.943a.75.75 0 1 0-1.004-1.114l-2.5 2.25a.75.75 0 0 0 0 1.114l2.5 2.25a.75.75 0 1 0 1.004-1.114l-1.048-.943h9.546A.75.75 0 0 0 19 10z" clip-rule="evenodd"/></svg></div>
            <h6 class="ab-card-title">Absen Pulang <span style="font-size:.72rem;font-weight:600;color:var(--ash-400);margin-left:.4rem"><?= strtoupper($mode) ?></span></h6>
        </div>
        <div class="ab-card-body">
            <?php if(!$waktunya_pulang): ?>
            <div class="ab-alert warn">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16zm.75-13a.75.75 0 0 0-1.5 0v5c0 .414.336.75.75.75h4a.75.75 0 0 0 0-1.5h-3.25V5z" clip-rule="evenodd"/></svg>
                <span>Absen pulang tersedia mulai pukul <strong><?= $jam_pulang_dt->format('H:i') ?></strong></span>
            </div>
            <button class="ab-submit-btn" disabled>
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 1a4.5 4.5 0 0 0-4.5 4.5V9H5a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-.5V5.5A4.5 4.5 0 0 0 10 1zm3 8V5.5a3 3 0 1 0-6 0V9h6z" clip-rule="evenodd"/></svg>
                Terkunci &mdash; Belum waktunya
            </button>
            <?php else: ?>
            <?php if($is_wfa): ?>
            <div class="ab-gps-bar wfa" id="gpsBarPulang"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16zM4.332 8.027a6.012 6.012 0 0 1 1.912-2.706C6.512 5.73 6.974 6 7.5 6A1.5 1.5 0 0 1 9 7.5V8a2 2 0 0 0 4 0 2 2 0 0 1 1.523-1.943A5.977 5.977 0 0 1 16 10c0 .34-.028.675-.083 1H15a2 2 0 0 0-2 2v2.197A5.973 5.973 0 0 1 10 16v-2a2 2 0 0 0-2-2 2 2 0 0 1-2-2 2 2 0 0 0-1.668-1.973z" clip-rule="evenodd"/></svg><span><strong>WFA</strong> &mdash; Lokasi tidak divalidasi.</span></div>
            <?php else: ?>
            <div class="ab-gps-bar loading" id="gpsBarPulang" style="margin-bottom:.75rem"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9.69 18.933l.003.001C9.89 19.02 10 19 10 19s.11.02.308-.066l.002-.001.006-.003.018-.008a5.741 5.741 0 0 0 .281-.14c.186-.096.446-.24.757-.433.62-.384 1.445-.966 2.274-1.765C15.302 14.988 17 12.493 17 9A7 7 0 1 0 3 9c0 3.492 1.698 5.988 3.355 7.584a13.731 13.731 0 0 0 2.273 1.765 11.842 11.842 0 0 0 .976.544l.062.029.018.008.006.003zM10 11.25a2.25 2.25 0 1 0 0-4.5 2.25 2.25 0 0 0 0 4.5z" clip-rule="evenodd"/></svg><span>Mendeteksi lokasi GPS…</span></div>
            <?php endif; ?>

            <div class="ab-cam-wrap">
                <div class="ab-cam-shell" id="camShellPulang">
                    <div class="ab-cam-loading" id="camLoadPulang"><div class="spin"></div><span>Memuat kamera…</span></div>
                    <video id="videoPulang" autoplay playsinline muted></video>
                    <canvas id="faceCanvasPulang" class="ab-face-canvas"></canvas>
                    <img id="previewPulang" class="ab-cam-preview" alt="Selfie pulang">
                    <div class="ab-cam-corner tl"></div><div class="ab-cam-corner tr"></div>
                    <div class="ab-cam-corner bl"></div><div class="ab-cam-corner br"></div>
                    <div class="ab-cam-status-bar"><div class="ab-cam-dot" id="camDotPulang"></div><span class="ab-cam-label" id="camLabelPulang">KAMERA AKTIF</span></div>
                </div>
                <div class="ab-face-status detecting" id="faceStatusPulang"><span class="fs-dot"></span><span id="faceTextPulang">Mendeteksi wajah…</span></div>
                <br>
                <button class="ab-capture-btn" id="capturePulang" disabled>
                    <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M10 12.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z"/><path fill-rule="evenodd" d="M.664 10.59a1.651 1.651 0 0 1 0-1.186A10.004 10.004 0 0 1 10 3c4.257 0 7.893 2.66 9.336 6.41.147.381.146.804 0 1.186A10.004 10.004 0 0 1 10 17c-4.257 0-7.893-2.66-9.336-6.41zM14 10a4 4 0 1 1-8 0 4 4 0 0 1 8 0z" clip-rule="evenodd"/></svg>
                    Ambil Foto Selfie
                </button>
            </div>

            <hr class="ab-divider">
            <form id="formPulang">
                <input type="hidden" name="type"      value="pulang">
                <input type="hidden" name="latitude"  id="latPulang"  value="0">
                <input type="hidden" name="longitude" id="lngPulang"  value="0">
                <input type="hidden" name="foto"      id="fotoPulang">
                <input type="hidden" name="mode"      value="<?= $mode ?>">
                <button type="submit" class="ab-submit-btn" id="submitPulang" disabled>
                    <svg viewBox="0 0 20 20" fill="currentColor"><path d="M3.105 2.289a.75.75 0 0 0-.826.95l1.414 4.925A1.5 1.5 0 0 0 5.135 9.25h6.115a.75.75 0 0 1 0 1.5H5.135a1.5 1.5 0 0 0-1.442 1.086l-1.414 4.926a.75.75 0 0 0 .826.95 28.896 28.896 0 0 0 15.293-7.154.75.75 0 0 0 0-1.115A28.897 28.897 0 0 0 3.105 2.289z"/></svg>
                    Kirim Absen Pulang <span class="btn-chip"><?= strtoupper($mode) ?></span>
                </button>
                <div class="ab-submit-hint" id="hintPulang">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9z" clip-rule="evenodd"/></svg>
                    Foto selfie diperlukan untuk melanjutkan
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ══════════════════════════════════════
         RIWAYAT ABSENSI
         ══════════════════════════════════════ -->
    <div class="ab-card" data-aos="fade-up" data-aos-delay="260">
        <div class="ab-card-header" style="flex-wrap:wrap;gap:.5rem;">
            <div class="ab-card-header-icon"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.75 2a.75.75 0 0 1 .75.75V4h7V2.75a.75.75 0 0 1 1.5 0V4h.25A2.75 2.75 0 0 1 18 6.75v8.5A2.75 2.75 0 0 1 15.25 18H4.75A2.75 2.75 0 0 1 2 15.25v-8.5A2.75 2.75 0 0 1 4.75 4H5V2.75A.75.75 0 0 1 5.75 2zm-1 5.5c-.69 0-1.25.56-1.25 1.25v6.5c0 .69.56 1.25 1.25 1.25h10.5c.69 0 1.25-.56 1.25-1.25v-6.5c0-.69-.56-1.25-1.25-1.25H4.75z" clip-rule="evenodd"/></svg></div>
            <h6 class="ab-card-title">Riwayat Absensi</h6>
            <div class="rw-header-actions">
                <form method="GET" style="display:flex;gap:.35rem;align-items:center">
                    <select name="bulan" class="rw-mini-select" onchange="this.form.submit()">
                        <?php foreach($namaBulanList as $k=>$v): ?>
                        <option value="<?= $k ?>" <?= $filterBulan===$k?'selected':'' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="tahun" class="rw-mini-select" onchange="this.form.submit()">
                        <?php foreach($tahunList as $t): ?>
                        <option value="<?= $t ?>" <?= $filterTahun==$t?'selected':'' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <a href="export_pdf.php?bulan=<?= $filterBulan ?>&tahun=<?= $filterTahun ?>" target="_blank" class="rw-export-btn">
                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 17a1 1 0 0 1 1-1h12a1 1 0 1 1 0 2H4a1 1 0 0 1-1-1zm3.293-7.707a1 1 0 0 1 1.414 0L9 10.586V3a1 1 0 1 1 2 0v7.586l1.293-1.293a1 1 0 1 1 1.414 1.414l-3 3a1 1 0 0 1-1.414 0l-3-3a1 1 0 0 1 0-1.414z" clip-rule="evenodd"/></svg>
                    Export PDF
                </a>
            </div>
        </div>

        <!-- Statistik ringkasan -->
        <div class="rw-stats-row">
            <div class="rw-stat-item rw-stat-hadir"><span class="rw-stat-num"><?= $rwHadir ?></span><span class="rw-stat-lbl">Hadir</span></div>
            <div class="rw-stat-item rw-stat-terlambat"><span class="rw-stat-num"><?= $rwTerlambat ?></span><span class="rw-stat-lbl">Terlambat</span></div>
            <div class="rw-stat-item rw-stat-izin"><span class="rw-stat-num"><?= $rwIzinSakit ?></span><span class="rw-stat-lbl">Izin/Sakit</span></div>
            <div class="rw-stat-item rw-stat-alpa"><span class="rw-stat-num"><?= $rwAlpa ?></span><span class="rw-stat-lbl">Alpa</span></div>
        </div>

        <!-- Daftar riwayat -->
        <div class="rw-list">
        <?php if (empty($riwayatFiltered)): ?>
            <div class="rw-empty">
                <svg viewBox="0 0 20 20" fill="currentColor" width="40" height="40" style="opacity:.25;display:block;margin:0 auto .75rem"><path d="M9 2a1 1 0 0 0 0 2h2a1 1 0 1 0 0-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 0 1 2-2 3 3 0 0 0 3 3h2a3 3 0 0 0 3-3 2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5zm3 4a1 1 0 0 0 0 2h.01a1 1 0 1 0 0-2H7zm2 0a1 1 0 0 1 1-1h2a1 1 0 1 1 0 2h-2a1 1 0 0 1-1-1zm-2 4a1 1 0 0 0 0 2h.01a1 1 0 1 0 0-2H7zm2 0a1 1 0 0 1 1-1h2a1 1 0 1 1 0 2h-2a1 1 0 0 1-1-1z" clip-rule="evenodd"/></svg>
                Tidak ada data absensi untuk <?= $namaBulanList[$filterBulan] ?> <?= $filterTahun ?>
            </div>
        <?php else: ?>
        <?php
        $namaHariArr  = ['Sun'=>'Minggu','Mon'=>'Senin','Tue'=>'Selasa','Wed'=>'Rabu','Thu'=>'Kamis','Fri'=>'Jumat','Sat'=>'Sabtu'];
        $namaBulanArr = ['1'=>'Jan','2'=>'Feb','3'=>'Mar','4'=>'Apr','5'=>'Mei','6'=>'Jun','7'=>'Jul','8'=>'Ags','9'=>'Sep','10'=>'Okt','11'=>'Nov','12'=>'Des'];
        $namaBulanFull= ['1'=>'Januari','2'=>'Februari','3'=>'Maret','4'=>'April','5'=>'Mei','6'=>'Juni','7'=>'Juli','8'=>'Agustus','9'=>'September','10'=>'Oktober','11'=>'November','12'=>'Desember'];

        foreach ($riwayatPage as $idx => $r):
            $globalIdx = $offset + $idx;
            $tglObj    = new DateTime($r['tanggal']);
            $sc        = ($r['jenis'] === 'cuti') ? 'cuti'
                       : (in_array($r['jenis'],['izin','sakit']) ? $r['jenis']
                       : (($r['status_masuk']==='terlambat') ? 'terlambat' : 'hadir'));
            $lbl       = ['hadir'=>'Hadir','terlambat'=>'Terlambat','izin'=>'Izin','sakit'=>'Sakit','cuti'=>'Cuti'][$sc];
            $hariStr   = $namaHariArr[$tglObj->format('D')];
            $tglStr    = $hariStr.', '.(int)$tglObj->format('j').' '.$namaBulanFull[(int)$tglObj->format('n')].' '.$tglObj->format('Y');

            $urlFotoMasuk  = fotoUrl($r['foto_masuk']  ?? null);
            $urlFotoPulang = fotoUrl($r['foto_pulang'] ?? null);

            $chMasuk  = $r['channel_masuk']  ?? 'qr';
            $chPulang = $r['channel_pulang'] ?? 'qr';
        ?>
            <div class="rw-item" id="rwItem<?= $globalIdx ?>">
                <div class="rw-row" onclick="rwToggle(<?= $globalIdx ?>)">
                    <div class="rw-date-box rw-box-<?= $sc ?>">
                        <span class="rw-date-num"><?= $tglObj->format('d') ?></span>
                        <span class="rw-date-mon"><?= $namaBulanArr[(int)$tglObj->format('n')] ?></span>
                    </div>
                    <div class="rw-meta">
                        <div class="rw-meta-top">
                            <span class="rw-meta-day"><?= $tglStr ?></span>
                            <span class="rw-badge rw-badge-<?= $sc ?>"><?= $lbl ?></span>
                        </div>
                        <div style="font-size:.775rem;color:var(--ash-500);margin-top:.2rem;">
                            <?php if ($r['jam_masuk'] && $r['jam_pulang']): ?>
                                Masuk <?= date('H:i',strtotime($r['jam_masuk'])) ?> &bull; Pulang <?= date('H:i',strtotime($r['jam_pulang'])) ?>
                            <?php elseif ($r['jam_masuk']): ?>
                                Masuk <?= date('H:i',strtotime($r['jam_masuk'])) ?> &bull; Pulang belum tercatat
                            <?php elseif ($r['jenis'] === 'cuti'): ?>
                                <i class="bi bi-calendar-check me-1"></i>Periode: <?= date('d/m/Y', strtotime($r['tanggal_mulai'])) ?> – <?= date('d/m/Y', strtotime($r['tanggal_selesai'])) ?>
                                <?php if (!empty($r['keterangan'])): ?> &bull; <?= htmlspecialchars(mb_substr($r['keterangan'],0,35)).(mb_strlen($r['keterangan'])>35?'…':'') ?><?php endif; ?>
                            <?php elseif ($r['keterangan']): ?>
                                <?= htmlspecialchars(mb_substr($r['keterangan'],0,45)).(mb_strlen($r['keterangan'])>45?'…':'') ?>
                            <?php else: ?>
                                Tidak hadir
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="rw-chevron" id="rwChev<?= $globalIdx ?>">
                        <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06z" clip-rule="evenodd"/></svg>
                    </div>
                </div>

                <div class="rw-detail" id="rwDetail<?= $globalIdx ?>">
                    <?php if ($r['jam_masuk'] || $r['jam_pulang']): ?>
                    <div class="rw-detail-grid">
                        <div class="rw-detail-block">
                            <div class="rw-detail-block-label">Jam Masuk</div>
                            <div class="rw-detail-block-val"><?= $r['jam_masuk'] ? date('H:i',strtotime($r['jam_masuk'])) : '—' ?></div>
                            <?php if ($r['status_masuk']==='terlambat'): ?>
                            <div class="rw-detail-block-sub rw-sub-warn">Terlambat dari jam kerja</div>
                            <?php elseif ($r['jam_masuk']): ?>
                            <div class="rw-detail-block-sub rw-sub-ok">Tepat waktu</div>
                            <?php endif; ?>
                        </div>
                        <div class="rw-detail-block">
                            <div class="rw-detail-block-label">Jam Pulang</div>
                            <div class="rw-detail-block-val"><?= $r['jam_pulang'] ? date('H:i',strtotime($r['jam_pulang'])) : '—' ?></div>
                            <?php if ($r['status_pulang']==='pulang awal'): ?>
                            <div class="rw-detail-block-sub rw-sub-warn">Pulang lebih awal</div>
                            <?php elseif ($r['jam_pulang']): ?>
                            <div class="rw-detail-block-sub rw-sub-ok">Sudah absen pulang</div>
                            <?php else: ?>
                            <div class="rw-detail-block-sub" style="color:var(--ash-400)">Belum absen pulang</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="rw-foto-row">
                        <!-- Foto Masuk -->
                        <div class="rw-foto-card">
                            <div class="rw-foto-card-label">
                                Foto Masuk
                                <span class="channel-badge <?= $chMasuk ?>"><?= strtoupper($chMasuk) ?></span>
                            </div>
                            <?php if ($chMasuk === 'qr'): ?>
                                <div class="rw-foto-placeholder">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                        <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                                        <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="3" height="3" rx=".5"/>
                                        <rect x="18" y="14" width="3" height="3" rx=".5"/><rect x="14" y="18" width="3" height="3" rx=".5"/>
                                        <rect x="18" y="18" width="3" height="3" rx=".5"/>
                                    </svg>
                                    <span>Absen via QR Code</span>
                                    <span class="qr-note">Tanpa foto selfie</span>
                                </div>
                            <?php elseif ($urlFotoMasuk): ?>
                                <img src="<?= htmlspecialchars($urlFotoMasuk) ?>"
                                     class="rw-foto-card-img" alt="Foto masuk" loading="lazy"
                                     onclick="rwLightbox(this.src)"
                                     onerror="this.parentElement.innerHTML='<div class=\'rw-foto-placeholder\'><span>Foto tidak ditemukan</span></div>'">
                            <?php else: ?>
                                <div class="rw-foto-placeholder">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                                    <span>Belum ada foto</span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Foto Pulang -->
                        <div class="rw-foto-card">
                            <div class="rw-foto-card-label">
                                Foto Pulang
                                <?php if (!empty($r['jam_pulang'])): ?>
                                <span class="channel-badge <?= $chPulang ?>"><?= strtoupper($chPulang) ?></span>
                                <?php endif; ?>
                            </div>
                            <?php if (empty($r['jam_pulang'])): ?>
                                <div class="rw-foto-placeholder">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <span>Belum absen pulang</span>
                                </div>
                            <?php elseif ($chPulang === 'qr'): ?>
                                <div class="rw-foto-placeholder">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28">
                                        <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                                        <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="3" height="3" rx=".5"/>
                                        <rect x="18" y="14" width="3" height="3" rx=".5"/><rect x="14" y="18" width="3" height="3" rx=".5"/>
                                        <rect x="18" y="18" width="3" height="3" rx=".5"/>
                                    </svg>
                                    <span>Absen via QR Code</span>
                                    <span class="qr-note">Tanpa foto selfie</span>
                                </div>
                            <?php elseif ($urlFotoPulang): ?>
                                <img src="<?= htmlspecialchars($urlFotoPulang) ?>"
                                     class="rw-foto-card-img" alt="Foto pulang" loading="lazy"
                                     onclick="rwLightbox(this.src)"
                                     onerror="this.parentElement.innerHTML='<div class=\'rw-foto-placeholder\'><span>Foto tidak ditemukan</span></div>'">
                            <?php else: ?>
                                <div class="rw-foto-placeholder">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" width="28" height="28"><rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                                    <span>Foto tidak tersedia</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if ($r['keterangan']): ?>
                    <div class="rw-keterangan"><strong>Keterangan:</strong> <?= htmlspecialchars($r['keterangan']) ?></div>
                    <?php endif; ?>
                    <?php if (!$r['jam_masuk'] && !$r['keterangan']): ?>
                    <div style="text-align:center;padding:.5rem;font-size:.8rem;color:var(--ash-400)">Tidak ada data jam hadir</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </div>

        <!-- ══════════════════════════════════════
             PAGINATION — dengan teks Sebelumnya / Selanjutnya
             ══════════════════════════════════════ -->
        <?php if ($totalPages > 1 || $totalRows > 0): ?>
        <div class="rw-pagination">

            <!-- Info data -->
            <div class="rw-page-info">
                Menampilkan <strong><?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRows) ?></strong>
                dari <strong><?= $totalRows ?></strong> data
                &bull; <?= $namaBulanList[$filterBulan] ?> <?= $filterTahun ?>
            </div>

            <?php if ($totalPages > 1): ?>
            <div class="rw-page-btns">

                <?php
                // ── URL helper ──────────────────────────────────────────────
                $urlPage = fn($p) => "?bulan={$filterBulan}&tahun={$filterTahun}&page={$p}";

                // ── Ikon panah kiri ─────────────────────────────────────────
                $icoLeft  = '<svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M11.78 5.22a.75.75 0 0 1 0 1.06L8.06 10l3.72 3.72a.75.75 0 1 1-1.06 1.06l-4.25-4.25a.75.75 0 0 1 0-1.06l4.25-4.25a.75.75 0 0 1 1.06 0z" clip-rule="evenodd"/></svg>';
                $icoRight = '<svg viewBox="0 0 20 20" fill="currentColor" width="14" height="14"><path fill-rule="evenodd" d="M8.22 5.22a.75.75 0 0 1 1.06 0l4.25 4.25a.75.75 0 0 1 0 1.06l-4.25 4.25a.75.75 0 0 1-1.06-1.06L11.94 10 8.22 6.28a.75.75 0 0 1 0-1.06z" clip-rule="evenodd"/></svg>';
                ?>

                <!-- ◀ Ikon panah kiri -->
                <?php if ($page <= 1): ?>
                    <span class="rw-page-btn disabled"><?= $icoLeft ?></span>
                <?php else: ?>
                    <a href="<?= $urlPage($page - 1) ?>" class="rw-page-btn"><?= $icoLeft ?></a>
                <?php endif; ?>

                <!-- Teks "Sebelumnya" -->
                <?php if ($page <= 1): ?>
                    <span class="rw-page-btn btn-nav-text disabled"><?= $icoLeft ?> Sebelumnya</span>
                <?php else: ?>
                    <a href="<?= $urlPage($page - 1) ?>" class="rw-page-btn btn-nav-text"><?= $icoLeft ?> Sebelumnya</a>
                <?php endif; ?>

                <!-- Nomor halaman dengan ellipsis -->
                <?php
                $startP = max(1, $page - 2);
                $endP   = min($totalPages, $page + 2);
                ?>

                <?php if ($startP > 1): ?>
                    <a href="<?= $urlPage(1) ?>" class="rw-page-btn">1</a>
                    <?php if ($startP > 2): ?>
                        <span class="rw-page-ellipsis">…</span>
                    <?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $startP; $p <= $endP; $p++): ?>
                    <a href="<?= $urlPage($p) ?>"
                       class="rw-page-btn <?= $p === $page ? 'active' : '' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>

                <?php if ($endP < $totalPages): ?>
                    <?php if ($endP < $totalPages - 1): ?>
                        <span class="rw-page-ellipsis">…</span>
                    <?php endif; ?>
                    <a href="<?= $urlPage($totalPages) ?>" class="rw-page-btn"><?= $totalPages ?></a>
                <?php endif; ?>

                <!-- Teks "Selanjutnya" -->
                <?php if ($page >= $totalPages): ?>
                    <span class="rw-page-btn btn-nav-text disabled">Selanjutnya <?= $icoRight ?></span>
                <?php else: ?>
                    <a href="<?= $urlPage($page + 1) ?>" class="rw-page-btn btn-nav-text">Selanjutnya <?= $icoRight ?></a>
                <?php endif; ?>

                <!-- ▶ Ikon panah kanan -->
                <?php if ($page >= $totalPages): ?>
                    <span class="rw-page-btn disabled"><?= $icoRight ?></span>
                <?php else: ?>
                    <a href="<?= $urlPage($page + 1) ?>" class="rw-page-btn"><?= $icoRight ?></a>
                <?php endif; ?>

            </div>
            <?php endif; ?>

        </div>
        <?php endif; ?>

    </div><!-- /.ab-card riwayat -->

</div><!-- /.ab-container -->
</div><!-- /.ab-page -->

<!-- Lightbox -->
<div class="rw-lightbox" id="rwLightboxEl" onclick="this.classList.remove('open')">
    <button class="rw-lightbox-close" onclick="event.stopPropagation();document.getElementById('rwLightboxEl').classList.remove('open')">&times;</button>
    <img id="rwLightboxImg" src="" alt="Foto absen">
</div>

<button class="ab-help-btn" id="tourBtn" title="Panduan penggunaan">
    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0zM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9z" clip-rule="evenodd"/></svg>
</button>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.umd.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>

<script>
AOS.init({ duration:550, once:true, easing:'ease-out-cubic' });

const isWFA    = <?= $is_wfa?'true':'false' ?>;
const isWFH    = <?= $is_wfh?'true':'false' ?>;
const MODE     = '<?= $mode ?>';
const kantorLat= <?= (float)($setting['latitude']  ?? -7.365355) ?>;
const kantorLng= <?= (float)($setting['longitude'] ?? 108.560654)?>;
const radius   = <?= (int)($setting['radius']   ?? 100) ?>;
const rumahLat = <?= ($koordinat_rumah&&$koordinat_rumah['lat_rumah'])?(float)$koordinat_rumah['lat_rumah']:'null' ?>;
const rumahLng = <?= ($koordinat_rumah&&$koordinat_rumah['lng_rumah'])?(float)$koordinat_rumah['lng_rumah']:'null' ?>;

let masukGpsOk  = isWFA;
let pulangGpsOk = isWFA;
let streamMasuk = null, streamPulang = null;
let faceApiReady = false;

const MODEL_URL = 'https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.13/model';
async function loadModels() {
    try {
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(MODEL_URL),
            faceapi.nets.faceLandmark68TinyNet.loadFromUri(MODEL_URL),
            faceapi.nets.faceExpressionNet.loadFromUri(MODEL_URL),
        ]);
        faceApiReady = true;
    } catch(e) { console.warn('[face-api] Model load failed:', e); }
}
loadModels();

const ICON = {
    face:`<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="5"/><path d="M3 21c0-4.418 4.03-8 9-8s9 3.582 9 8"/></svg>`,
    blink:`<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>`,
    smile:`<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>`,
    check:`<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>`,
    warn:`<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>`,
    scan:`<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7V5a2 2 0 0 1 2-2h2"/><path d="M17 3h2a2 2 0 0 1 2 2v2"/><path d="M21 17v2a2 2 0 0 1-2 2h-2"/><path d="M7 21H5a2 2 0 0 1-2-2v-2"/><rect x="7" y="7" width="10" height="10" rx="1"/></svg>`,
    eye:`<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/></svg>`,
    multi:`<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>`,
};

function hav(lat1,lng1,lat2,lng2){
    const R=6371000,φ1=lat1*Math.PI/180,φ2=lat2*Math.PI/180;
    const Δφ=(lat2-lat1)*Math.PI/180,Δλ=(lng2-lng1)*Math.PI/180;
    const a=Math.sin(Δφ/2)**2+Math.cos(φ1)*Math.cos(φ2)*Math.sin(Δλ/2)**2;
    return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}

function setBar(el,cls,html){
    el.className=`ab-gps-bar ${cls}`;
    const icon=el.querySelector('svg');
    el.innerHTML=(icon?icon.outerHTML:'')+`<span>${html}</span>`;
}

function setupGPS(barId,latId,lngId,cb){
    if(isWFA){cb(true);return;}
    const bar=document.getElementById(barId);
    if(!bar){cb(false);return;}
    if(!navigator.geolocation){setBar(bar,'error','GPS tidak didukung browser ini.');cb(false);return;}
    navigator.geolocation.getCurrentPosition(pos=>{
        const{latitude:lat,longitude:lng}=pos.coords;
        document.getElementById(latId).value=lat;
        document.getElementById(lngId).value=lng;
        let refLat,refLng,refR,label;
        if(isWFH&&rumahLat&&rumahLng){refLat=rumahLat;refLng=rumahLng;refR=100;label='rumah';}
        else{refLat=kantorLat;refLng=kantorLng;refR=radius;label='kantor';}
        const d=hav(refLat,refLng,lat,lng);
        const ok=d<=refR;
        setBar(bar,ok?'ok':'error',ok?`<strong>Lokasi Valid</strong> &mdash; ${Math.round(d)} m dari ${label}`:`<strong>Di Luar Radius</strong> &mdash; ${Math.round(d)} m dari ${label} (radius: ${refR} m)`);
        cb(ok);
    },err=>{
        setBar(bar,'error',err.code===1?'Izinkan akses lokasi di browser.':'Gagal mengambil lokasi GPS.');cb(false);
    },{enableHighAccuracy:true,timeout:14000,maximumAge:0});
}

function calcEyeOpenness(eyePts){
    // EAR (Eye Aspect Ratio) yang ternormalisasi terhadap lebar mata
    // Rumus: (h1 + h2) / (2 * lebar_mata)
    // Hasilnya konsisten tidak peduli jarak wajah ke kamera
    const dist=(a,b)=>Math.hypot(a.x-b.x,a.y-b.y);
    const h1=dist(eyePts[1],eyePts[5]);
    const h2=dist(eyePts[2],eyePts[4]);
    const w=dist(eyePts[0],eyePts[3]);
    return w>0?(h1+h2)/(2*w):0;
}

function onJenisChange(val){
    document.getElementById('panelMasuk').classList.remove('visible');
    document.getElementById('panelIzin').classList.remove('visible');
    if(val==='masuk'){document.getElementById('panelMasuk').classList.add('visible');initMasuk();}
    else if(val==='izin'||val==='sakit'){
        document.getElementById('izinJenisVal').value=val;
        document.getElementById('izinCardTitle').textContent=val==='sakit'?'Pengajuan Sakit':'Pengajuan Izin';
        document.getElementById('izinSubmitLabel').textContent=val==='sakit'?'Kirim Laporan Sakit':'Kirim Pengajuan Izin';
        document.getElementById('panelIzin').classList.add('visible');
    }
}
function onFileChange(el){document.getElementById('fileLabelText').textContent=el.files.length?el.files[0].name:'Pilih file \u2022 JPG, PNG, PDF \u2022 Maks 2 MB';}

function startFaceLoop(video,canvas,statusEl,textEl,capBtn,fotoInputId){
    const ctx=canvas.getContext('2d',{willReadFrequently:true});
    const optFar=new faceapi.TinyFaceDetectorOptions({inputSize:416,scoreThreshold:0.25});
    const optNear=new faceapi.TinyFaceDetectorOptions({inputSize:224,scoreThreshold:0.30});
    let livenessStep=0,blinkDetected=false,smileDetected=false,blinkFrame=0,eyeWasOpen=true,earBaseline=null;
    let consOk=0,consNone=0,consMult=0;
    const CONFIRM=3;
    let currentState='idle';

    function setStatus(state,customHtml){
        if(currentState===state&&!customHtml)return;
        currentState=state;
        const hasFoto=(document.getElementById(fotoInputId)?.value||'').length>50;
        const map={
            'done':{cls:'ok',icon:ICON.check,text:'Verifikasi lulus — siap ambil foto'},
            'multi':{cls:'no-face',icon:ICON.multi,text:'Hanya 1 wajah yang diizinkan'},
            'none':{cls:'no-face',icon:ICON.scan,text:'Wajah tidak terdeteksi — dekatkan ke kamera'},
            'step0':{cls:'detecting',icon:ICON.face,text:'Wajah ditemukan — tahan sebentar'},
            'step1':{cls:'detecting',icon:ICON.blink,text:'Kedipkan mata Anda'},
            'step2':{cls:'detecting',icon:ICON.smile,text:'Senyumlah ke kamera'},
            'idle':{cls:'detecting',icon:ICON.scan,text:'Mendeteksi wajah…'},
        };
        const cfg=map[state]||map['idle'];
        statusEl.className=`ab-face-status ${cfg.cls}`;
        statusEl.innerHTML=customHtml||`<span class="fs-dot"></span>${cfg.icon}<span style="margin-left:4px">${cfg.text}</span>`;
        if(!hasFoto) capBtn.disabled=(state!=='done');
    }

    function syncCanvasSize(){const s=canvas.parentElement;if(canvas.width!==s.clientWidth)canvas.width=s.clientWidth;if(canvas.height!==s.clientHeight)canvas.height=s.clientHeight;}

    function drawIdleOval(ctx,canvas,color,lbl){
        const W=canvas.width,H=canvas.height,cx=W/2,cy=H*.46,rx=W*.24,ry=H*.38;
        ctx.save();ctx.fillStyle='rgba(0,0,0,0.50)';ctx.fillRect(0,0,W,H);
        ctx.globalCompositeOperation='destination-out';ctx.beginPath();ctx.ellipse(cx,cy,rx,ry,0,0,Math.PI*2);ctx.fill();ctx.restore();
        ctx.save();ctx.beginPath();ctx.ellipse(cx,cy,rx,ry,0,0,Math.PI*2);ctx.strokeStyle=color;ctx.lineWidth=2.5;ctx.setLineDash([8,6]);ctx.stroke();ctx.restore();
        if(lbl){ctx.save();ctx.fillStyle='rgba(255,255,255,0.75)';ctx.font=`500 ${Math.round(W*.033)}px -apple-system,sans-serif`;ctx.textAlign='center';ctx.textBaseline='middle';ctx.fillText(lbl,cx,cy+ry+W*.055);ctx.restore();}
    }

    function drawOvalFromDet(ctx,det,video,color){
        const W=ctx.canvas.width,H=ctx.canvas.height,vW=video.videoWidth||W,vH=video.videoHeight||H;
        const scale=Math.max(W/vW,H/vH),offX=(W-vW*scale)/2,offY=(H-vH*scale)/2;
        const box=det.detection.box,mirX=vW-box.x-box.width;
        const cx=offX+(mirX+box.width/2)*scale,cy=offY+(box.y+box.height/2)*scale;
        const rx=(box.width/2)*scale*1.15,ry=(box.height/2)*scale*1.30;
        ctx.save();ctx.fillStyle='rgba(0,0,0,0.42)';ctx.fillRect(0,0,W,H);
        ctx.globalCompositeOperation='destination-out';ctx.beginPath();ctx.ellipse(cx,cy,rx,ry,0,0,Math.PI*2);ctx.fill();ctx.restore();
        ctx.save();ctx.beginPath();ctx.ellipse(cx,cy,rx,ry,0,0,Math.PI*2);ctx.strokeStyle=color;ctx.lineWidth=3;ctx.shadowColor=color;ctx.shadowBlur=14;ctx.stroke();ctx.restore();
        const bL=Math.min(rx,ry)*.35;
        ctx.save();ctx.strokeStyle=color;ctx.lineWidth=3;ctx.lineCap='round';
        [[cx-rx,cy-ry,1,1],[cx+rx,cy-ry,-1,1],[cx-rx,cy+ry,1,-1],[cx+rx,cy+ry,-1,-1]].forEach(([x,y,sx,sy])=>{ctx.beginPath();ctx.moveTo(x+sx*bL*.3,y);ctx.lineTo(x,y);ctx.lineTo(x,y+sy*bL*.8);ctx.stroke();});
        ctx.restore();
    }

    async function detect(){
        if((document.getElementById(fotoInputId)?.value||'').length>50){ctx.clearRect(0,0,canvas.width,canvas.height);return;}
        syncCanvasSize();
        if(!faceApiReady||!video.videoWidth||video.readyState<2){drawIdleOval(ctx,canvas,'rgba(255,255,255,0.35)','Arahkan wajah ke sini');setTimeout(()=>requestAnimationFrame(detect),100);return;}
        let dets=[];
        try{dets=await faceapi.detectAllFaces(video,optFar).withFaceLandmarks(true).withFaceExpressions();if(dets.length===0)dets=await faceapi.detectAllFaces(video,optNear).withFaceLandmarks(true).withFaceExpressions();}catch(e){}
        ctx.clearRect(0,0,canvas.width,canvas.height);
        if(dets.length===0){consNone++;consOk=consMult=0;drawIdleOval(ctx,canvas,consNone>=CONFIRM?'#ef4444':'rgba(255,255,255,0.35)','Dekatkan wajah ke kamera');if(consNone>=CONFIRM)setStatus('none');setTimeout(()=>requestAnimationFrame(detect),66);return;}
        if(dets.length>1){consMult++;consOk=consNone=0;dets.forEach(d=>drawOvalFromDet(ctx,d,video,'#ef4444'));if(consMult>=CONFIRM){setStatus('multi');livenessStep=blinkFrame=0;blinkDetected=smileDetected=false;eyeWasOpen=true;earBaseline=null;}setTimeout(()=>requestAnimationFrame(detect),66);return;}
        consOk++;consNone=consMult=0;
        const det=dets[0],landmarks=det.landmarks,expressions=det.expressions;
        if(livenessStep===0&&consOk>=CONFIRM)livenessStep=1;
        if(livenessStep===1&&!blinkDetected){
            const open=(calcEyeOpenness(landmarks.getLeftEye())+calcEyeOpenness(landmarks.getRightEye()))/2;
            // Baseline: ambil nilai tertinggi, lalu turun perlahan (EWM)
            // Dengan EAR ternormalisasi, nilai baseline stabil di ~0.25-0.35
            if(earBaseline===null)earBaseline=open;else if(open>earBaseline)earBaseline=open;else earBaseline=earBaseline*.93+open*.07;
            const dropPct=earBaseline>0?(earBaseline-open)/earBaseline:0;
            // Threshold 25% drop = kedipan nyata (lebih sensitif dari sebelumnya 12% absolut)
            const isClose=dropPct>0.25;
            // blinkFrame >= 1 = cukup 1 frame tutup, tapi harus diikuti frame buka (anti-noise)
            if(isClose){blinkFrame++;}else{if(blinkFrame>=1){blinkDetected=true;livenessStep=2;consOk=0;}blinkFrame=0;eyeWasOpen=true;}
        }
        if(livenessStep===2&&!smileDetected){if((expressions?.happy??0)>0.70)smileDetected=true;}
        const ovalColor=smileDetected?'#10b981':livenessStep===0?'#3b82f6':'#f59e0b';
        drawOvalFromDet(ctx,det,video,ovalColor);
        if(smileDetected)setStatus('done');
        else if(livenessStep===2)setStatus('step2');
        else if(livenessStep===1)setStatus('step1');
        else setStatus('step0');
        setTimeout(()=>requestAnimationFrame(detect),66);
    }
    requestAnimationFrame(detect);
}

async function initKamera(videoId,canvasId,loadId,capBtnId,fotoId,statusId,textId,camDotId,camLabelId,onStream){
    const video=document.getElementById(videoId),canvas=document.getElementById(canvasId);
    const loading=document.getElementById(loadId),capBtn=document.getElementById(capBtnId);
    const statusEl=document.getElementById(statusId),textEl=document.getElementById(textId);
    const camDot=document.getElementById(camDotId),camLbl=document.getElementById(camLabelId);
    if(!video)return null;
    try{
        const stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:1280},height:{ideal:960},frameRate:{ideal:30}}});
        video.srcObject=stream;
        await new Promise(res=>{video.onloadedmetadata=res;});
        await video.play();
        if(loading)loading.style.display='none';
        onStream(stream);
        startFaceLoop(video,canvas,statusEl,textEl,capBtn,fotoId);
    }catch(e){
        if(loading)loading.innerHTML=`<svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,.35)" stroke-width="1.5"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><line x1="2" y1="2" x2="22" y2="22" stroke="rgba(255,255,255,.4)"/></svg><span style="color:rgba(255,255,255,.55);font-size:.78rem;text-align:center;padding:0 1rem">Kamera tidak dapat diakses.<br>Izinkan akses di browser.</span>`;
    }

    function doCapture(){
        const cvs=document.createElement('canvas');cvs.width=video.videoWidth||1280;cvs.height=video.videoHeight||960;
        const ctx2=cvs.getContext('2d');ctx2.translate(cvs.width,0);ctx2.scale(-1,1);ctx2.drawImage(video,0,0,cvs.width,cvs.height);
        const img=cvs.toDataURL('image/jpeg',0.92);
        document.getElementById(fotoId).value=img;
        const prevId=videoId.replace('video','preview');
        document.getElementById(prevId).src=img;document.getElementById(prevId).style.display='block';
        canvas.style.display='none';
        if(camDot){camDot.style.background='#10b981';camDot.style.animation='none';}
        if(camLbl)camLbl.textContent='FOTO DIAMBIL';
        capBtn.innerHTML=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M23 4v6h-6"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg> Ambil Ulang`;
        capBtn.classList.add('retake');capBtn.onclick=doRetake;
        checkReady(fotoId);
    }

    function doRetake(){
        document.getElementById(fotoId).value='';
        const prevId=videoId.replace('video','preview');
        document.getElementById(prevId).style.display='none';canvas.style.display='';
        if(camDot){camDot.style.background='';camDot.style.animation='';}
        if(camLbl)camLbl.textContent='KAMERA AKTIF';
        capBtn.innerHTML=`<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="18" height="18"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg> Ambil Foto Selfie`;
        capBtn.classList.remove('retake');capBtn.onclick=doCapture;capBtn.disabled=true;
        statusEl.className='ab-face-status detecting';
        statusEl.innerHTML=`<span class="fs-dot"></span>${ICON.scan}<span style="margin-left:4px">Mendeteksi wajah…</span>`;
        startFaceLoop(video,canvas,statusEl,textEl,capBtn,fotoId);
        checkReady(fotoId);
    }

    capBtn.onclick=doCapture;
    return video.srcObject;
}

function checkReady(fotoId){
    const isMasuk=fotoId==='fotoMasuk';
    const btn=document.getElementById(isMasuk?'submitMasuk':'submitPulang');
    const hint=document.getElementById(isMasuk?'hintMasuk':'hintPulang');
    if(!btn)return;
    const foto=(document.getElementById(fotoId)?.value||'').trim();
    const gpsOk=isMasuk?masukGpsOk:pulangGpsOk;
    const hasFoto=foto.length>50;
    const ready=hasFoto&&(gpsOk||isWFA);
    btn.disabled=!ready;
    if(!hint)return; 
    if(ready){hint.className='ab-submit-hint hint-ready';hint.innerHTML=`${ICON.check} Siap dikirim`;}
    else if(!hasFoto){hint.className='ab-submit-hint';hint.innerHTML=`${ICON.eye} Foto selfie diperlukan untuk melanjutkan`;}
    else{hint.className='ab-submit-hint hint-warn';hint.innerHTML=`${ICON.warn} Lokasi Anda di luar radius yang diizinkan`;}
}

async function initMasuk(){
    if(streamMasuk)return;
    if(!isWFA)setupGPS('gpsBarMasuk','latMasuk','lngMasuk',ok=>{masukGpsOk=ok;checkReady('fotoMasuk');});
    streamMasuk=await initKamera('videoMasuk','faceCanvasMasuk','camLoadMasuk','captureMasuk','fotoMasuk','faceStatusMasuk','faceTextMasuk','camDotMasuk','camLabelMasuk',s=>{streamMasuk=s;});
}

<?php if($boleh_pulang && $waktunya_pulang): ?>
(async()=>{
    if(!isWFA)setupGPS('gpsBarPulang','latPulang','lngPulang',ok=>{pulangGpsOk=ok;checkReady('fotoPulang');});
    streamPulang=await initKamera('videoPulang','faceCanvasPulang','camLoadPulang','capturePulang','fotoPulang','faceStatusPulang','faceTextPulang','camDotPulang','camLabelPulang',s=>{streamPulang=s;});
})();
<?php endif; ?>

function bindSubmit(formId,submitId,fotoId,latId,lngId,type,gpsRef){
    const form=document.getElementById(formId);
    if(!form)return;
    form.addEventListener('submit',async e=>{
        e.preventDefault();
        const foto=(document.getElementById(fotoId)?.value||'').trim();
        if(!gpsRef()&&!isWFA){Swal.fire({icon:'error',title:'Lokasi Tidak Valid',text:'Anda berada di luar radius yang diizinkan.',confirmButtonColor:'#0f2241'});return;}
        if(foto.length<50){Swal.fire({icon:'warning',title:'Foto Belum Diambil',text:'Silakan ambil foto selfie terlebih dahulu.',confirmButtonColor:'#0f2241'});return;}
        const btn=document.getElementById(submitId),ori=btn.innerHTML;
        btn.disabled=true;btn.innerHTML=`<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="animation:spin .75s linear infinite"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Mengirim…`;
        const alamat=(MODE==='wfh'&&type==='masuk')?(document.getElementById('alamatWFH')?.value||''):'';
        try{
            const r=await fetch('proses_absen.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({type,latitude:document.getElementById(latId).value,longitude:document.getElementById(lngId).value,foto,mode:MODE,alamat})});
            const d=await r.json();
            if(d.success){Swal.fire({icon:'success',title:'Absensi Berhasil',text:d.message,confirmButtonColor:'#059669'}).then(()=>location.reload());}
            else{Swal.fire({icon:'error',title:'Gagal',text:d.message,confirmButtonColor:'#0f2241'});btn.disabled=false;btn.innerHTML=ori;}
        }catch(e){Swal.fire({icon:'error',title:'Koneksi Gagal',text:'Tidak dapat menghubungi server.',confirmButtonColor:'#0f2241'});btn.disabled=false;btn.innerHTML=ori;}
    });
}
<?php if(!$sudah_masuk&&!$sudah_izin&&!$sudah_cuti): ?>
bindSubmit('formMasuk','submitMasuk','fotoMasuk','latMasuk','lngMasuk','masuk',()=>masukGpsOk);
<?php endif; ?>
<?php if($boleh_pulang&&$waktunya_pulang): ?>
bindSubmit('formPulang','submitPulang','fotoPulang','latPulang','lngPulang','pulang',()=>pulangGpsOk);
<?php endif; ?>

/* Accordion riwayat */
function rwToggle(idx){
    const item=document.getElementById('rwItem'+idx);
    const detail=document.getElementById('rwDetail'+idx);
    const isOpen=item.classList.toggle('rw-open');
    detail.style.display=isOpen?'block':'none';
}

/* Lightbox */
function rwLightbox(src){document.getElementById('rwLightboxImg').src=src;document.getElementById('rwLightboxEl').classList.add('open');}
document.addEventListener('keydown',e=>{if(e.key==='Escape')document.getElementById('rwLightboxEl').classList.remove('open');});

/* Tour */
const driverObj=window.driver.js.driver({showProgress:true,smoothScroll:true,nextBtnText:'Lanjut →',prevBtnText:'← Kembali',doneBtnText:'Selesai',steps:[
    {element:'#statusCard',popover:{title:'Status Kehadiran',description:'Menampilkan status absensi Anda secara real-time.',side:'bottom',align:'start'}},
    ...(document.getElementById('cardJenis')?[{element:'#cardJenis',popover:{title:'Pilih Jenis Kehadiran',description:'Pilih Absen Masuk, Izin, atau Sakit.',side:'bottom',align:'start'}}]:[]),
    ...(document.getElementById('cardPulang')?[{element:'#cardPulang',popover:{title:'Absen Pulang',description:'Aktif setelah absen masuk dan jam pulang tiba.',side:'top',align:'start'}}]:[]),
    {element:'#tourBtn',popover:{title:'Tombol Panduan',description:'Klik kapan saja untuk mengulang tur ini.',side:'top',align:'end'}},
]});
document.getElementById('tourBtn').addEventListener('click',()=>driverObj.drive());
if(!localStorage.getItem('ab_tour_v3')){setTimeout(()=>{driverObj.drive();localStorage.setItem('ab_tour_v3','1');},1200);}
</script>

<?php include '../templates/footer.php'; ?> 