<?php
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/helper.php';

// ── Handoff dari QR: ?qr_from=TOKEN ──────────────────────────────────
if (!empty($_GET['qr_from'])) {
    restore_from_qr_handoff($pdo);
    if (isset($_SESSION['user_id'])) {
        $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
        header('Location: ' . $base . '/dashboard.php');
        exit();
    }
} else {
    restore_web_session($pdo);
}

require_login();
if (!is_pegawai()) {
    $base = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
    header('Location: ' . $base . '/admin/dashboard.php');
    exit();
}

/* ── Settings ── */
$setting = $pdo->query("SELECT jam_masuk, jam_pulang FROM settings LIMIT 1")->fetch();
$today   = date('Y-m-d');

/* ── Attendance today ── */
$stmt = $pdo->prepare("SELECT * FROM attendance WHERE user_id=? AND tanggal=?");
$stmt->execute([$_SESSION['user_id'], $today]);
$record = $stmt->fetch();

$stmt = $pdo->prepare("SELECT * FROM izin WHERE user_id=? AND tanggal=? AND status='disetujui'");
$stmt->execute([$_SESSION['user_id'], $today]);
$recordIzin = $stmt->fetch();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM hari_libur WHERE tanggal=?");
$stmt->execute([$today]);
$isLibur = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT id, alasan, tanggal_mulai, tanggal_selesai FROM cuti WHERE user_id=? AND ? BETWEEN tanggal_mulai AND tanggal_selesai AND status='disetujui' LIMIT 1");
$stmt->execute([$_SESSION['user_id'], $today]);
$recordCuti = $stmt->fetch();

$status_hari_ini = 'belum_absen';
if ($isLibur)        { $status_hari_ini = 'libur'; }
elseif ($recordCuti) { $status_hari_ini = 'cuti'; }
elseif ($recordIzin) { $status_hari_ini = $recordIzin['jenis']; }
elseif ($record)     { $status_hari_ini = ($record['jam_masuk'] && $record['jam_pulang']) ? 'hadir_lengkap' : 'hadir'; }

/* ── Stats ── */
$stats = ['hadir'=>0,'sakit'=>0,'izin'=>0,'alpa'=>0];
$stmt = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE user_id=? AND jam_masuk IS NOT NULL AND jam_pulang IS NOT NULL");
$stmt->execute([$_SESSION['user_id']]); $stats['hadir'] = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM izin WHERE user_id=? AND jenis='sakit' AND status='disetujui'");
$stmt->execute([$_SESSION['user_id']]); $stats['sakit'] = $stmt->fetchColumn();
$stmt = $pdo->prepare("SELECT COUNT(*) FROM izin WHERE user_id=? AND jenis='izin' AND status='disetujui'");
$stmt->execute([$_SESSION['user_id']]); $stats['izin'] = $stmt->fetchColumn();

$bulan_ini = date('Y-m-01'); $akhir_bulan = date('Y-m-t');
$stmt = $pdo->prepare("SELECT DATE(created_at) FROM users WHERE id=?");
$stmt->execute([$_SESSION['user_id']]);
$tgl_daftar   = $stmt->fetchColumn() ?? $bulan_ini;
$start_hitung = max($bulan_ini, $tgl_daftar);
$end_hitung   = min($akhir_bulan, $today);
if ($start_hitung <= $end_hitung) {
    $res = hitung_alpa_pegawai($pdo, $_SESSION['user_id'], $start_hitung, $end_hitung);
    $stats['alpa'] = $res['alpa'];
}

$total_hk     = $stats['hadir'] + $stats['sakit'] + $stats['izin'] + $stats['alpa'];
$persen_hadir = $total_hk > 0 ? round(($stats['hadir'] / $total_hk) * 100) : 0;

/* ── User info ── */
$stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
$stmt->execute([$_SESSION['user_id']]);
$userInfo = $stmt->fetch();

/* ── Profile completeness ── */
$profileFields  = ['nik','no_hp','tempat_lahir','tgl_lahir','unit_kerja','email','foto'];
$filledCount    = 0;
foreach ($profileFields as $f) { if (!empty($userInfo[$f])) $filledCount++; }
$profilePercent = round(($filledCount / count($profileFields)) * 100);
$profileMissing = [];
$fieldLabels    = ['nik'=>'NIK','no_hp'=>'No. HP','tempat_lahir'=>'Tempat Lahir','tgl_lahir'=>'Tanggal Lahir','unit_kerja'=>'Unit Kerja','email'=>'Email','foto'=>'Foto Profil'];
foreach ($profileFields as $f) { if (empty($userInfo[$f])) $profileMissing[] = $fieldLabels[$f]; }

/* ── Chart 7 hari ── */
$stmt = $pdo->prepare("SELECT DATE(tanggal) d, COUNT(*) c FROM attendance WHERE user_id=? AND tanggal >= CURDATE() - INTERVAL 6 DAY AND jam_masuk IS NOT NULL GROUP BY DATE(tanggal)");
$stmt->execute([$_SESSION['user_id']]);
$chartRows  = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $tgl = date('Y-m-d', strtotime("-{$i} days"));
    $chart_data[] = ['day'=>date('D', strtotime($tgl)), 'label'=>date('d M', strtotime($tgl)), 'hadir'=>isset($chartRows[$tgl]) ? 1 : 0];
}

/* ── Week riwayat ── */
$senin  = date('Y-m-d', strtotime('monday this week'));
$minggu = date('Y-m-d', strtotime('sunday this week'));
$stmt   = $pdo->prepare("SELECT * FROM attendance WHERE user_id=? AND tanggal BETWEEN ? AND ? ORDER BY tanggal ASC");
$stmt->execute([$_SESSION['user_id'], $senin, $minggu]);
$minggu_att = [];
foreach ($stmt->fetchAll() as $r) { $minggu_att[$r['tanggal']] = $r; }
$stmt = $pdo->prepare("SELECT * FROM izin WHERE user_id=? AND tanggal BETWEEN ? AND ? AND status='disetujui'");
$stmt->execute([$_SESSION['user_id'], $senin, $minggu]);
$minggu_izin = [];
foreach ($stmt->fetchAll() as $r) { $minggu_izin[$r['tanggal']] = $r; }

// Cuti mingguan — expand per hari dalam minggu ini
$stmt = $pdo->prepare("SELECT tanggal_mulai, tanggal_selesai, alasan FROM cuti WHERE user_id=? AND status='disetujui' AND tanggal_mulai <= ? AND tanggal_selesai >= ?");
$stmt->execute([$_SESSION['user_id'], $minggu, $senin]);
$minggu_cuti = [];
foreach ($stmt->fetchAll() as $c) {
    $period = new DatePeriod(new DateTime($c['tanggal_mulai']), new DateInterval('P1D'), (new DateTime($c['tanggal_selesai']))->modify('+1 day'));
    foreach ($period as $dt) {
        $ds = $dt->format('Y-m-d');
        if ($ds >= $senin && $ds <= $minggu) $minggu_cuti[$ds] = $c;
    }
}
$stmt = $pdo->prepare("SELECT tanggal FROM hari_libur WHERE tanggal BETWEEN ? AND ?");
$stmt->execute([$senin, $minggu]);
$minggu_libur = array_flip(array_column($stmt->fetchAll(), 'tanggal'));

/* ── Pengajuan terbaru ── */
$stmt = $pdo->prepare("(SELECT 'cuti' AS jenis,status,alasan AS ket,created_at FROM cuti WHERE user_id=? ORDER BY created_at DESC LIMIT 3) UNION ALL (SELECT jenis,status,keterangan,created_at FROM izin WHERE user_id=? ORDER BY created_at DESC LIMIT 3) ORDER BY created_at DESC LIMIT 4");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$pengajuan_list = $stmt->fetchAll();

/* ── Locale ── */
$h         = (int)date('H');
$greeting  = $h < 11 ? 'Pagi' : ($h < 15 ? 'Siang' : ($h < 18 ? 'Sore' : 'Malam'));
$hari_id   = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$bulan_id  = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$tgl_indo  = $hari_id[date('w')].', '.date('d').' '.$bulan_id[(int)date('m')].' '.date('Y');
$blabel    = $bulan_id[(int)date('m')].' '.date('Y');
$namaDepan = explode(' ', $userInfo['nama'] ?? 'Pegawai')[0];
$nama_hari = ['Senin','Selasa','Rabu','Kamis','Jumat','Sabtu','Minggu'];

/* ── Hero pill class ── */
$sPillClass = 'hero-status-pill ';
$sPillLabel = '';
if ($isLibur)
    { $sPillClass .= 's-libur'; $sPillLabel = 'Hari Libur'; }
elseif (in_array($status_hari_ini, ['hadir','hadir_lengkap']))
    { $sPillClass .= 's-hadir'; $sPillLabel = 'Hadir'; }
elseif ($status_hari_ini === 'belum_absen')
    { $sPillClass .= 's-belum'; $sPillLabel = 'Belum Absen'; }
else
    { $sPillClass .= 's-belum'; $sPillLabel = ucfirst($status_hari_ini); }
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<style>
/* ══════════════════════════════════
   TOKENS
══════════════════════════════════ */
:root {
    --navy:      #0A1628;
    --navy-mid:  #0F2241;
    --navy-lit:  #1A3560;
    --navy-soft: #243970;
    --gold:      #C9A84C;
    --gold-lit:  #E8C97A;
    --gold-pale: #F5E9C8;
    --gold-bg:   #FEFAEF;
    --silver:    #8A9BBE;
    --ash:       #CBD5E8;
    --white:     #FFFFFF;
    --surface:   #F6F8FE;
    --surf2:     #EEF2FA;
    --txt:       #0A1628;
    --muted:     #64748B;
    --border:    #E2E8F4;
    --ok:        #059669;
    --ok-bg:     #ECFDF5;
    --ok-b:      #A7F3D0;
    --err:       #DC2626;
    --err-bg:    #FEF2F2;
    --warn:      #D97706;
    --warn-bg:   #FFFBEB;
    --info:      #2563EB;
    --info-bg:   #EFF6FF;
    --sh1: 0 1px 3px rgba(10,22,40,.05), 0 1px 2px rgba(10,22,40,.03);
    --sh2: 0 4px 16px rgba(10,22,40,.08);
    --sh3: 0 12px 40px rgba(10,22,40,.12);
    --r:   14px;
    --rsm: 8px;
}
body { font-family:'DM Sans',sans-serif; background:var(--surface); color:var(--txt); }
.dw  { padding:1.5rem 1.5rem 3rem; max-width:1440px; margin:0 auto; box-sizing:border-box; }

/* ══════════════════════════════════
   HERO
══════════════════════════════════ */
.hero {
    background: #0f2241;
    background-image: radial-gradient(
        ellipse 80% 60% at 70% -10%,
        rgba(180,150,50,.18) 0%,
        transparent 60%
    );
    border-radius: 20px;
    margin-bottom: 1.25rem;
    position: relative;
    overflow: hidden;
    box-shadow: 0 8px 32px rgba(10,22,40,.22), 0 1px 0 rgba(201,168,76,.12) inset;
    width: 100%;
    box-sizing: border-box;
}
.hero::after {
    content: '';
    position: absolute;
    bottom: -1px; left: 0; right: 0;
    height: 36px;
    background: var(--surface);
    clip-path: ellipse(55% 100% at 50% 100%);
    pointer-events: none;
}
.hero-grid-decor {
    position: absolute;
    top: 0; right: 0; bottom: 0; width: 45%;
    background-image: radial-gradient(circle, rgba(212,175,55,.22) 1px, transparent 1px);
    background-size: 22px 22px;
    opacity: .18;
    pointer-events: none;
    mask-image: linear-gradient(to left, rgba(0,0,0,.7) 0%, transparent 100%);
    -webkit-mask-image: linear-gradient(to left, rgba(0,0,0,.7) 0%, transparent 100%);
}
.hero-inner {
    display: grid;
    grid-template-columns: 1fr auto auto;
    align-items: stretch;
    min-height: 168px;
}
.hero-left {
    padding: 2rem 2rem 3.5rem;
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-width: 0;
}
.hero-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    font-size: .6rem;
    font-weight: 700;
    letter-spacing: .22em;
    text-transform: uppercase;
    color: rgba(201,168,76,.85);
    margin-bottom: .5rem;
}
.hero-eyebrow-line {
    width: 20px; height: 1.5px;
    background: linear-gradient(90deg, rgba(201,168,76,.85), transparent);
    border-radius: 99px;
}
.hero-greet {
    color: rgba(255,255,255,.45);
    font-size: .78rem;
    font-weight: 400;
    letter-spacing: .03em;
    margin-bottom: .05rem;
}
.hero-name {
    font-size: clamp(1.35rem, 3vw, 1.9rem);
    font-weight: 700;
    color: #fff;
    line-height: 1.15;
    letter-spacing: -.02em;
    margin: 0 0 .55rem;
}
.hero-meta {
    display: flex;
    align-items: center;
    gap: .5rem;
    flex-wrap: wrap;
}
.hero-meta-chip {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    font-size: .7rem;
    color: rgba(255,255,255,.52);
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 99px;
    padding: .28rem .72rem;
}
.hero-meta-chip svg { width: 11px; height: 11px; fill: rgba(138,155,190,.8); flex-shrink: 0; }
.hero-meta-div { width: 1px; height: 12px; background: rgba(255,255,255,.12); }

.hero-status-pill {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    font-size: .68rem;
    font-weight: 600;
    padding: .3rem .8rem;
    border-radius: 99px;
    border: 1px solid rgba(255,255,255,.1);
    background: rgba(255,255,255,.06);
    color: rgba(255,255,255,.6);
    white-space: nowrap;
}
.hero-status-pill.s-hadir  { border-color:rgba(5,150,105,.35); background:rgba(5,150,105,.12); color:#6ee7b7; }
.hero-status-pill.s-hadir .spill-dot { background:#6ee7b7; animation:psDot 2s ease infinite; }
.hero-status-pill.s-belum  { border-color:rgba(251,191,36,.25); background:rgba(251,191,36,.08); color:#fcd34d; }
.hero-status-pill.s-belum .spill-dot { background:#fcd34d; }
.hero-status-pill.s-libur  { border-color:rgba(37,99,235,.3); background:rgba(37,99,235,.1); color:#93c5fd; }
.spill-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
@keyframes psDot { 0%,100%{opacity:1} 50%{opacity:.4} }

.hero-clock-panel {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 1.75rem 2rem 3.5rem;
    border-left: 1px solid rgba(255,255,255,.07);
    border-right: 1px solid rgba(255,255,255,.07);
    gap: .75rem;
    background: rgba(255,255,255,.025);
    min-width: 160px;
}
.analog-clock-wrap { position:relative; width:80px; height:80px; flex-shrink:0; }
.aclock-svg { width:80px; height:80px; filter:drop-shadow(0 4px 14px rgba(201,168,76,.18)); }
.aclock-face   { fill:rgba(10,22,40,.7); stroke:rgba(201,168,76,.4); stroke-width:1.5; }
.aclock-rim    { fill:none; stroke:rgba(201,168,76,.15); stroke-width:4; }
.aclock-center { fill:#c9a84c; }
.aclock-hr     { stroke:#fff; stroke-width:3; stroke-linecap:round; transform-origin:40px 40px; }
.aclock-min    { stroke:#c9a84c; stroke-width:2; stroke-linecap:round; transform-origin:40px 40px; }
.aclock-sec    { stroke:#f87171; stroke-width:1.5; stroke-linecap:round; transform-origin:40px 40px; }
.aclock-tick   { stroke:rgba(255,255,255,.28); stroke-width:1; }
.aclock-tick-5 { stroke:rgba(201,168,76,.5); stroke-width:1.5; }

.hero-digital-time {
    font-size: 1.45rem;
    font-weight: 700;
    color: #fff;
    letter-spacing: .06em;
    font-variant-numeric: tabular-nums;
    line-height: 1;
    text-align: center;
}
.hero-digital-colon { color:rgba(212,175,55,.9); animation:colonBlink 1s step-end infinite; }
@keyframes colonBlink { 50%{opacity:.22} }
.hero-digital-lbl {
    font-size: .55rem;
    text-transform: uppercase;
    letter-spacing: .12em;
    color: rgba(255,255,255,.28);
    margin-top: .2rem;
    text-align: center;
}
.hero-jk-label {
    display: block;
    color: rgba(255,255,255,.38);
    font-size: .58rem;
    font-weight: 700;
    letter-spacing: .12em;
    text-transform: uppercase;
    text-align: center;
    margin-bottom: .15rem;
    margin-top: .6rem;
}
.hero-jk-value {
    display: block;
    color: rgba(212,175,55,.9);
    font-size: .9rem;
    font-weight: 700;
    letter-spacing: .02em;
    text-align: center;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.hero-actions {
    display: flex;
    flex-direction: column;
    align-items: stretch;
    justify-content: center;
    padding: 1.75rem 1.75rem 3.5rem;
    gap: .65rem;
    min-width: 188px;
}

.btn-absen-hero {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .45rem;
    padding: .78rem 1.25rem;
    background: linear-gradient(135deg, #c9a84c, #e8c97a);
    color: #0f2241;
    border: none;
    border-radius: 10px;
    font-size: .82rem;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    box-shadow: 0 6px 20px rgba(201,168,76,.35);
    transition: all .22s cubic-bezier(.22,1,.36,1);
    letter-spacing: .01em;
    white-space: nowrap;
    font-family: inherit;
}
.btn-absen-hero svg { width:15px; height:15px; fill:#0f2241; flex-shrink:0; }
.btn-absen-hero:hover { transform:translateY(-2px); box-shadow:0 10px 28px rgba(201,168,76,.48); color:#0f2241; }
.btn-absen-hero.pulang {
    background: linear-gradient(135deg,#172d54,#243970);
    color: rgba(212,175,55,.9);
    box-shadow: none;
    border: 1px solid rgba(212,175,55,.25);
}
.btn-absen-hero.pulang svg { fill:rgba(212,175,55,.9); }
.btn-absen-hero.pulang:hover { box-shadow:0 6px 20px rgba(15,34,65,.4); color:rgba(212,175,55,1); }
.btn-absen-hero.ghost {
    background: rgba(255,255,255,.06);
    color: rgba(255,255,255,.6);
    box-shadow: none;
    border: 1px solid rgba(255,255,255,.1);
}
.btn-absen-hero.ghost svg { fill:rgba(255,255,255,.6); }
.btn-absen-hero.ghost:hover { background:rgba(255,255,255,.1); color:rgba(255,255,255,.9); transform:translateY(-1px); box-shadow:none; }

.tour-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    padding: .62rem 1rem;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.11);
    border-radius: 10px;
    color: rgba(255,255,255,.55);
    font-size: .78rem;
    font-weight: 500;
    cursor: pointer;
    transition: all .2s;
    letter-spacing: .01em;
    white-space: nowrap;
    font-family: inherit;
}
.tour-btn svg { width:14px; height:14px; fill:currentColor; flex-shrink:0; }
.tour-btn:hover { background:rgba(201,168,76,.12); border-color:rgba(201,168,76,.28); color:rgba(255,255,255,.88); }

@media (max-width: 960px) {
    .hero-inner { grid-template-columns:1fr auto; grid-template-rows:auto auto; }
    .hero-left  { padding:1.75rem 1.5rem 3.5rem; }
    .hero-clock-panel { border-right:none; border-bottom:1px solid rgba(255,255,255,.07); padding:1.5rem 1.5rem 3.5rem; }
    .hero-actions { padding:1.5rem 1.4rem 3.5rem; }
}
@media (max-width: 700px) {
    .hero-inner {
        display: flex;
        flex-direction: column;
        width: 100%;
    }
    .hero-left {
        padding: 1.5rem 1.25rem 1rem;
        width: 100%;
        box-sizing: border-box;
        min-width: 0;
    }
    .hero-clock-panel {
        flex-direction: row;
        border-left: none;
        border-right: none;
        border-top: 1px solid rgba(255,255,255,.07);
        padding: 1rem 1.25rem;
        min-width: 0;
        width: 100%;
        box-sizing: border-box;
        gap: 1.25rem;
        justify-content: flex-start;
    }
    .hero-actions {
        flex-direction: row;
        flex-wrap: wrap;
        border-left: none;
        border-top: 1px solid rgba(255,255,255,.07);
        padding: 1rem 1.25rem 3rem;
        min-width: 0;
        width: 100%;
        box-sizing: border-box;
        gap: .6rem;
    }
    .hero-grid-decor { display: none; }
}
@media (max-width: 480px) {
    .hero-left  { padding: 1.35rem 1.1rem .85rem; }
    .hero-name  { font-size: 1.35rem; }
    .hero-clock-panel { padding: .85rem 1.1rem; }
    .hero-actions { padding: .85rem 1.1rem 2.75rem; gap: .5rem; }
    .btn-absen-hero, .tour-btn { font-size: .76rem; padding: .62rem .95rem; }
    .hero-jk-value { font-size: .82rem; }
    .hero-digital-time { font-size: 1.2rem; }
}

/* ══════════════════════════════════
   MAIN LAYOUT GRID
══════════════════════════════════ */
.dash-grid {
    display: grid;
    grid-template-columns: 310px 1fr;
    gap: 1.25rem;
    align-items: start;
    box-sizing: border-box;
    width: 100%;
}
@media(max-width:1100px) { .dash-grid { grid-template-columns:1fr; } }

/* ══════════════════════════════════
   PROFILE CARD
══════════════════════════════════ */
.profile-card {
    background:var(--white); border:1px solid var(--border);
    border-radius:var(--r); box-shadow:var(--sh1); overflow:hidden;
    position:sticky; top:80px;
}
.pc-header {
    background:linear-gradient(145deg,var(--navy) 0%,var(--navy-lit) 100%);
    padding:1.5rem 1.25rem 3.5rem; position:relative; overflow:hidden; text-align:center;
}
.pc-header::after {
    content:''; position:absolute; bottom:0; left:0; right:0; height:2px;
    background:linear-gradient(90deg,transparent,var(--gold) 40%,var(--gold-lit) 60%,transparent);
}
.pc-header-dots {
    position:absolute; top:10px; right:10px; width:70px; height:60px;
    background-image:radial-gradient(circle,rgba(201,168,76,.35) 1.5px,transparent 1.5px);
    background-size:13px 13px; opacity:.22; pointer-events:none;
}
.pc-avatar-wrap { position:relative; display:inline-block; margin-bottom:.75rem; }
.pc-avatar {
    width:82px; height:82px; border-radius:50%;
    border:3px solid var(--gold);
    box-shadow:0 0 0 4px rgba(201,168,76,.18), 0 8px 24px rgba(0,0,0,.3);
    object-fit:cover; display:block;
}
.pc-avatar-ini {
    width:82px; height:82px; border-radius:50%;
    border:3px solid var(--gold);
    box-shadow:0 0 0 4px rgba(201,168,76,.18), 0 8px 24px rgba(0,0,0,.3);
    background:linear-gradient(135deg,var(--navy-soft),var(--navy-lit));
    display:flex; align-items:center; justify-content:center;
    font-size:2.2rem; font-weight:700; color:var(--gold);
}
.pc-status-dot {
    position:absolute; bottom:4px; right:4px;
    width:18px; height:18px; border-radius:50%;
    background:var(--ok); border:2.5px solid var(--navy-lit);
    box-shadow:0 0 0 3px rgba(5,150,105,.2);
    animation:pDot 2.5s ease-in-out infinite;
}
@keyframes pDot { 0%,100%{box-shadow:0 0 0 3px rgba(5,150,105,.2)} 50%{box-shadow:0 0 0 6px rgba(5,150,105,.04)} }
.pc-name { font-size:1.15rem; font-weight:700; color:var(--white); line-height:1.25; }
.pc-role { font-size:.72rem; color:rgba(255,255,255,.58); margin-top:.2rem; }

.pc-body { padding:0 1.1rem 1.1rem; margin-top:-2rem; position:relative; z-index:2; }
.pc-id-card {
    background:var(--white); border:1px solid var(--border);
    border-radius:12px; padding:.9rem 1rem; margin-bottom:.85rem;
    box-shadow:0 2px 12px rgba(10,22,40,.07);
}
.pc-id-row { display:flex; align-items:center; gap:.6rem; padding:.4rem 0; }
.pc-id-row:not(:last-child) { border-bottom:1px solid var(--surf2); }
.pc-id-ico { width:28px; height:28px; border-radius:7px; background:var(--surf2); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.pc-id-ico svg { width:13px; height:13px; fill:var(--silver); }
.pc-id-lbl { font-size:.62rem; font-weight:600; text-transform:uppercase; letter-spacing:.08em; color:var(--silver); }
.pc-id-val { font-size:.8rem; font-weight:600; color:var(--navy); word-break:break-word; }
.pc-id-val.empty { color:var(--ash); font-style:italic; font-weight:400; font-size:.74rem; }

.pc-complete {
    background:linear-gradient(135deg,var(--gold-bg),#FFF8E7);
    border:1px solid rgba(201,168,76,.25);
    border-radius:12px; padding:.85rem 1rem; margin-bottom:.85rem;
}
.pc-complete-head { display:flex; align-items:center; justify-content:space-between; margin-bottom:.5rem; }
.pc-complete-lbl { font-size:.74rem; font-weight:700; color:var(--navy); }
.pc-complete-pct { font-size:1.05rem; font-weight:700; color:var(--gold); }
.pc-prog-track { height:5px; background:rgba(201,168,76,.2); border-radius:99px; overflow:hidden; }
.pc-prog-fill  { height:100%; background:linear-gradient(90deg,var(--gold),var(--gold-lit)); border-radius:99px; }
.pc-missing { font-size:.7rem; color:var(--warn); margin-top:.5rem; display:flex; align-items:center; gap:.35rem; }
.pc-missing svg { width:12px; height:12px; fill:var(--warn); flex-shrink:0; }

.btn-to-profil {
    display:flex; align-items:center; justify-content:center; gap:.4rem;
    width:100%; padding:.65rem;
    background:linear-gradient(135deg,var(--navy),var(--navy-lit));
    color:var(--white); border:none; border-radius:var(--rsm);
    font-size:.78rem; font-weight:600; font-family:inherit;
    text-decoration:none; cursor:pointer; transition:all .2s;
    box-shadow:0 4px 12px rgba(10,22,40,.18); margin-top:.1rem;
}
.btn-to-profil svg { width:14px; height:14px; fill:var(--white); }
.btn-to-profil:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(10,22,40,.26); color:var(--white); }

.pc-stats { display:grid; grid-template-columns:repeat(2,1fr); gap:.5rem; margin-bottom:.85rem; }
.pc-stat {
    background:var(--surface); border:1px solid var(--border); border-radius:10px;
    padding:.65rem .7rem; text-align:center;
}
.pc-stat-val { font-size:1.45rem; font-weight:700; color:var(--navy); line-height:1; }
.pc-stat-lbl { font-size:.62rem; font-weight:600; text-transform:uppercase; letter-spacing:.07em; color:var(--silver); margin-top:.15rem; }
.pc-stat.s-ok   { border-top:2.5px solid var(--ok);   } .pc-stat.s-ok   .pc-stat-val { color:var(--ok);   }
.pc-stat.s-late { border-top:2.5px solid var(--err);  } .pc-stat.s-late .pc-stat-val { color:var(--err);  }
.pc-stat.s-iz   { border-top:2.5px solid var(--warn); } .pc-stat.s-iz   .pc-stat-val { color:var(--warn); }
.pc-stat.s-pct  { border-top:2.5px solid var(--gold); } .pc-stat.s-pct  .pc-stat-val { color:var(--gold); }

/* ══════════════════════════════════
   PANEL UMUM
══════════════════════════════════ */
.panel {
    background:var(--white); border:1px solid var(--border);
    border-radius:var(--r); box-shadow:var(--sh1); overflow:hidden;
    margin-bottom:1.25rem;
    box-sizing: border-box;
    width: 100%;
}
.panel-head {
    display:flex; align-items:center; justify-content:space-between;
    padding:.9rem 1.35rem; border-bottom:1px solid var(--border);
    background:linear-gradient(90deg,#FAFCFF,var(--white));
}
.panel-title { display:flex; align-items:center; gap:.5rem; font-size:.84rem; font-weight:700; color:var(--navy); }
.panel-ico   { width:28px; height:28px; border-radius:8px; background:var(--gold-bg); display:flex; align-items:center; justify-content:center; }
.panel-ico svg { width:14px; height:14px; fill:var(--gold); }
.panel-link  { font-size:.74rem; font-weight:600; color:var(--gold); text-decoration:none; display:flex; align-items:center; gap:.2rem; transition:color .15s; }
.panel-link:hover { color:var(--navy); }
.panel-link svg { width:14px; height:14px; fill:currentColor; }

.status-body { display:flex; align-items:center; gap:1.75rem; padding:1.25rem 1.35rem; flex-wrap:wrap; }
.status-info { flex:1; min-width:160px; }
.status-lbl  { font-size:.62rem; font-weight:600; text-transform:uppercase; letter-spacing:.1em; color:var(--silver); margin-bottom:.5rem; }
.status-jam  {
    display:inline-flex; align-items:center; gap:.45rem; margin-top:.55rem;
    padding:.48rem .85rem; background:var(--surf2); border:1px solid var(--border);
    border-radius:var(--rsm); font-size:.73rem; color:var(--muted);
}
.status-jam svg { width:13px; height:13px; fill:var(--silver); flex-shrink:0; }
.status-jam strong { color:var(--navy); font-weight:700; }
.status-ket { font-size:.73rem; color:var(--muted); font-style:italic; margin-top:.38rem; }

.status-badge {
    display:inline-flex; align-items:center; gap:.38rem;
    padding:.35rem .9rem; border-radius:99px; font-size:.75rem; font-weight:700;
}
.sb-hadir  { background:var(--ok-bg);   color:var(--ok);   border:1px solid var(--ok-b); }
.sb-izin   { background:#FEF9C3; color:#854D0E; border:1px solid #FDE68A; }
.sb-sakit  { background:var(--err-bg);  color:var(--err);  border:1px solid #FECACA; }
.sb-cuti   { background:#EDE9FE; color:#3730a3; border:1px solid #C4B5FD; }
.sb-libur  { background:var(--info-bg); color:var(--info); border:1px solid #BFDBFE; }
.sb-belum  { background:var(--surf2);   color:var(--muted);border:1px solid var(--border); }
.sb-dot { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.sb-hadir .sb-dot { background:var(--ok); animation:pulseDot 2s infinite; }
@keyframes pulseDot { 0%,100%{box-shadow:0 0 0 2px rgba(5,150,105,.25)} 50%{box-shadow:0 0 0 5px rgba(5,150,105,.05)} }

.time-boxes { display:flex; align-items:center; gap:.85rem; }
.tsep       { color:var(--ash); }
.tsep svg   { width:18px; height:18px; fill:var(--ash); }
.tbox {
    min-width:112px; text-align:center; padding:.95rem .85rem;
    border-radius:14px; border:1.5px solid var(--border); background:var(--surface);
}
.tbox.tb-in  { background:var(--ok-bg);   border-color:var(--ok-b); }
.tbox.tb-out { background:var(--info-bg); border-color:#BFDBFE; }
.tbox-ico  { margin-bottom:.38rem; }
.tbox-ico svg { width:19px; height:19px; }
.tb-in  .tbox-ico svg { fill:var(--ok); }
.tb-out .tbox-ico svg { fill:var(--info); }
.tb-emp .tbox-ico svg { fill:var(--ash); }
.tbox-lbl { font-size:.6rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em; color:var(--muted); margin-bottom:.28rem; }
.tbox-val { font-size:1.5rem; font-weight:700; line-height:1; margin-bottom:.32rem; font-variant-numeric:tabular-nums; }
.tb-in  .tbox-val { color:var(--ok); }
.tb-out .tbox-val { color:var(--info); }
.tb-emp .tbox-val { color:var(--ash); }
.tbox-chip { display:inline-flex; align-items:center; font-size:.65rem; font-weight:700; padding:3px 9px; border-radius:99px; }
.tc-ok   { background:var(--ok-bg);  color:var(--ok);   border:1px solid var(--ok-b); }
.tc-late { background:var(--err-bg); color:var(--err);  border:1px solid #FECACA; }
.tc-none { background:var(--surf2);  color:var(--muted);border:1px solid var(--border); }

.qa-grid {
    display:grid; grid-template-columns:repeat(4,1fr);
    gap:.75rem; padding:1.1rem 1.35rem;
}
.qa-item {
    display:flex; flex-direction:column; align-items:center; gap:.45rem;
    padding:1.05rem .75rem; border:1.5px solid var(--border);
    border-radius:14px; text-decoration:none; color:var(--txt);
    font-size:.74rem; font-weight:600; text-align:center;
    background:var(--white); transition:all .22s cubic-bezier(.22,1,.36,1);
    position:relative; overflow:hidden;
}
.qa-item::before { content:''; position:absolute; inset:0; background:linear-gradient(135deg,var(--gold-bg),transparent); opacity:0; transition:opacity .22s; }
.qa-item.qa-ok:hover  { border-color:var(--gold); transform:translateY(-3px); box-shadow:0 8px 24px rgba(201,168,76,.14); color:var(--navy); }
.qa-item.qa-ok:hover::before { opacity:1; }
.qa-item.qa-off { opacity:.4; pointer-events:none; }
.qa-ico { width:46px; height:46px; border-radius:13px; display:flex; align-items:center; justify-content:center; transition:transform .22s; position:relative; z-index:1; }
.qa-ico svg { width:22px; height:22px; }
.qa-item:hover .qa-ico { transform:scale(1.1) translateY(-1px); }
.qa-lbl { font-size:.73rem; position:relative; z-index:1; line-height:1.3; }
.qa-sub { font-size:.64rem; color:var(--muted); position:relative; z-index:1; }

.sec-grid { display:grid; grid-template-columns:1.6fr 1fr; gap:1.25rem; margin-bottom:1.25rem; }
@media(max-width:900px) { .sec-grid { grid-template-columns:1fr; } }
.chart-wrap { padding:1.1rem 1.35rem 1rem; height:200px; }
.chart-wrap canvas { width:100%!important; height:100%!important; }

.pj-list { padding:.5rem .85rem .85rem; display:flex; flex-direction:column; gap:.5rem; }
.pj-row {
    display:flex; align-items:center; gap:.7rem; padding:.75rem .9rem;
    border-radius:12px; border:1px solid var(--border); background:var(--white);
    transition:background .15s; position:relative; overflow:hidden;
}
.pj-row::before { content:''; position:absolute; left:0; top:0; bottom:0; width:3px; border-radius:0 2px 2px 0; }
.pj-row.st-menunggu::before  { background:#FBBF24; }
.pj-row.st-disetujui::before { background:var(--ok); }
.pj-row.st-ditolak::before   { background:var(--err); }
.pj-row:hover { background:var(--surf2); }
.pj-ico { width:34px; height:34px; border-radius:9px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.pj-ico svg { width:16px; height:16px; }
.pj-type { font-size:.8rem; font-weight:700; color:var(--navy); text-transform:capitalize; }
.pj-ket  { font-size:.68rem; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-top:1px; }
.pj-chip { display:inline-flex; font-size:.65rem; font-weight:700; padding:3px 9px; border-radius:99px; white-space:nowrap; flex-shrink:0; }
.chip-menunggu  { background:#FFFBEB; color:#92400E; border:1px solid #FDE68A; }
.chip-disetujui { background:var(--ok-bg); color:var(--ok); border:1px solid var(--ok-b); }
.chip-ditolak   { background:var(--err-bg); color:var(--err); border:1px solid #FECACA; }

.empty-state { text-align:center; padding:2.5rem 1rem; }
.empty-state svg { width:34px; height:34px; fill:var(--ash); margin-bottom:.6rem; }
.empty-state p { font-size:.82rem; color:var(--muted); margin:0; }

.week-wrap { overflow-x:auto; }
.week-tbl  { width:100%; border-collapse:collapse; min-width:500px; }
.week-tbl thead th {
    font-size:.62rem; font-weight:700; text-transform:uppercase; letter-spacing:.08em;
    color:var(--silver); padding:.65rem 1.35rem; border-bottom:1px solid var(--border);
    background:var(--surface); white-space:nowrap; text-align:left;
}
.week-tbl tbody td { font-size:.8rem; padding:.72rem 1.35rem; border-bottom:1px solid #F5F7FD; vertical-align:middle; }
.week-tbl tbody tr:last-child td { border-bottom:none; }
.week-tbl tbody tr:hover td { background:var(--surface); }
.week-tbl tbody tr.today td { background:rgba(5,150,105,.03); }
.week-tbl tbody tr.future td { opacity:.48; }
.wd-d { font-weight:600; font-size:.8rem; color:var(--navy); }
.wd-n { font-size:.68rem; color:var(--muted); margin-top:2px; display:flex; align-items:center; gap:.3rem; }
.today-pip { font-size:.6rem; font-weight:700; background:var(--ok-bg); color:var(--ok); border:1px solid var(--ok-b); border-radius:99px; padding:1px 7px; }
.wt-v  { font-weight:700; font-size:.82rem; font-variant-numeric:tabular-nums; }
.wt-na { color:var(--ash); font-size:.76rem; }
.wb { display:inline-flex; font-size:.68rem; font-weight:700; padding:3px 10px; border-radius:99px; white-space:nowrap; }
.wb-ok   { background:var(--ok-bg);   color:var(--ok);   border:1px solid var(--ok-b); }
.wb-late { background:var(--err-bg);  color:var(--err);  border:1px solid #FECACA; }
.wb-iz   { background:var(--warn-bg); color:var(--warn); border:1px solid #FDE68A; }
.wb-lib  { background:var(--info-bg); color:var(--info); border:1px solid #BFDBFE; }
.wb-cut  { background:#F5F3FF; color:#7C3AED; border:1px solid #DDD6FE; }
.wb-alp  { background:var(--surf2); color:var(--muted); border:1px solid var(--border); }
.wmode   { font-size:.65rem; font-weight:700; letter-spacing:.05em; background:var(--surf2); color:var(--muted); border:1px solid var(--border); border-radius:6px; padding:2px 8px; }

@keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
.f1{animation:fadeUp .42s ease both} .f2{animation:fadeUp .42s .07s ease both}
.f3{animation:fadeUp .42s .14s ease both} .f4{animation:fadeUp .42s .21s ease both}
.f5{animation:fadeUp .42s .28s ease both}

/* ══════════════════════════════════
   RESPONSIVE
══════════════════════════════════ */
@media(max-width:900px) {
    .dash-grid { grid-template-columns:1fr; }
    .profile-card { position:static; }
}
@media(max-width:768px) {
    .status-body { flex-direction:column; align-items:stretch; gap:1rem; }
    .time-boxes  { justify-content:center; }
    .qa-grid     { grid-template-columns:repeat(2,1fr); gap:.6rem; padding:.85rem 1rem; }
    /* pc-stats 2 kolom di mobile — JANGAN 4 kolom */
    .pc-stats    { grid-template-columns:repeat(2,1fr); }
}
@media(max-width:576px) {
    .tbox { min-width:95px; padding:.85rem .65rem; }
    .tbox-val { font-size:1.3rem; }
    .week-tbl thead th,.week-tbl tbody td { padding-left:1rem; padding-right:1rem; }
    .d-xs-none { display:none!important; }
}

/* ══════════════════════════════════
   FIX MOBILE — SATU BLOK, TIDAK KONFLIK
   Sidebar hilang di <992px → hapus margin-left
   Hero & semua konten harus selebar .dw
══════════════════════════════════ */
@media (max-width: 991.98px) {
    .main-content {
        margin-left: 0 !important;
    }
}

@media (max-width: 768px) {
    /* Pastikan body tidak overflow horizontal */
    body {
        overflow-x: hidden;
    }

    /* .main-content: reset semua spacing dari sidebar */
    .main-content {
        width: 100% !important;
        max-width: 100% !important;
        padding: 0 !important;
        box-sizing: border-box !important;
    }

    /* .dw: padding seragam kiri-kanan, inilah referensi lebar */
    .dw {
        padding: 1rem 1rem 2.5rem !important;
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        margin: 0 !important;
    }

    /* .hero: ikuti lebar .dw sepenuhnya, TIDAK ada margin sendiri */
    .hero {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        margin: 0 0 1.25rem 0 !important;
        border-radius: 14px !important;
    }

    /* Semua konten di bawah hero ikut lebar yang sama */
    .dash-grid {
        display: block !important;
        width: 100% !important;
        box-sizing: border-box !important;
    }
    .dash-grid > div {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        min-width: 0 !important;
    }
    .profile-card,
    .panel,
    .sec-grid {
        width: 100% !important;
        max-width: 100% !important;
        box-sizing: border-box !important;
        min-width: 0 !important;
    }
}

@media (max-width: 480px) {
    .dw {
        padding: .85rem .85rem 2.5rem !important;
    }
    .hero {
        border-radius: 12px !important;
    }
}
</style>

<div class="main-content">
<div class="dw">

    <!-- HERO -->
    <div class="hero f1" id="tour-header">
        <div class="hero-grid-decor"></div>
        <div class="hero-inner">

            <!-- Kiri: Identitas -->
            <div class="hero-left">
                <div class="hero-eyebrow">
                    <span class="hero-eyebrow-line"></span>
                    Dashboard Pegawai · BBWS Citanduy
                </div>
                <div class="hero-greet">Selamat <?= $greeting ?></div>
                <h1 class="hero-name"><?= htmlspecialchars($userInfo['nama'] ?? 'Pegawai') ?></h1>
                <div class="hero-meta">
                    <span class="hero-meta-chip">
                        <svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5C3.9 4 3 4.9 3 6v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
                        <?= $tgl_indo ?>
                    </span>
                    <?php if (!empty($userInfo['jabatan'])): ?>
                    <div class="hero-meta-div"></div>
                    <span class="hero-meta-chip">
                        <svg viewBox="0 0 24 24"><path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-2 .89-2 2v11c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-6 0h-4V4h4v2z"/></svg>
                        <?= htmlspecialchars($userInfo['jabatan']) ?>
                    </span>
                    <?php endif; ?>
                    <div class="hero-meta-div"></div>
                    <span class="<?= $sPillClass ?>">
                        <span class="spill-dot"></span>
                        <?= $sPillLabel ?>
                    </span>
                </div>
            </div>

            <!-- Tengah: Clock panel -->
            <div class="hero-clock-panel">
                <div class="analog-clock-wrap">
                    <svg class="aclock-svg" viewBox="0 0 80 80" id="analogClock">
                        <circle cx="40" cy="40" r="38" class="aclock-rim"/>
                        <circle cx="40" cy="40" r="36" class="aclock-face"/>
                        <?php
                        for ($ti = 0; $ti < 60; $ti++) {
                            $ang = $ti * 6;
                            $r1  = $ti % 5 === 0 ? 28 : 31;
                            $r2  = 34;
                            $rad = deg2rad($ang - 90);
                            $x1  = 40 + $r1 * cos($rad);
                            $y1  = 40 + $r1 * sin($rad);
                            $x2  = 40 + $r2 * cos($rad);
                            $y2  = 40 + $r2 * sin($rad);
                            $cls = $ti % 5 === 0 ? 'aclock-tick-5' : 'aclock-tick';
                            echo '<line x1="'.round($x1,2).'" y1="'.round($y1,2).'" x2="'.round($x2,2).'" y2="'.round($y2,2).'" class="'.$cls.'"/>';
                        }
                        ?>
                        <line x1="40" y1="40" x2="40" y2="22" class="aclock-hr"  id="hrHand"/>
                        <line x1="40" y1="40" x2="40" y2="16" class="aclock-min" id="minHand"/>
                        <line x1="40" y1="44" x2="40" y2="13" class="aclock-sec" id="secHand"/>
                        <circle cx="40" cy="40" r="3.5" class="aclock-center"/>
                        <circle cx="40" cy="40" r="1.4" fill="#0f2241"/>
                    </svg>
                </div>
                <div>
                    <div class="hero-digital-time">
                        <span id="ch">--</span><span class="hero-digital-colon">:</span><span id="cm">--</span><span class="hero-digital-colon">:</span><span id="cs">--</span>
                    </div>
                    <div class="hero-digital-lbl">WIB · Waktu Indonesia Barat</div>
                    <span class="hero-jk-label">Jam Kerja</span>
                    <span class="hero-jk-value">
                        <?= date('H:i', strtotime($setting['jam_masuk'])) ?> &ndash; <?= date('H:i', strtotime($setting['jam_pulang'])) ?>
                    </span>
                </div>
            </div>

            <!-- Kanan: Tombol aksi -->
            <div class="hero-actions">
                <span class="<?= $sPillClass ?>" style="align-self:flex-start">
                    <span class="spill-dot"></span>
                    <?= $sPillLabel ?>
                </span>

                <?php if (!$isLibur && $status_hari_ini === 'belum_absen'): ?>
                <a href="absensi.php" class="btn-absen-hero">
                    <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                    Absen Sekarang
                </a>
                <?php elseif (!$isLibur && $status_hari_ini === 'hadir'): ?>
                <a href="absensi.php" class="btn-absen-hero pulang">
                    <svg viewBox="0 0 24 24"><path d="M13 17l5-5-5-5v3H4v4h9v3zm4-14H5c-1.11 0-2 .89-2 2v4h2V5h12v14H5v-4H3v4c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V5c0-1.11-.9-2-2-2z"/></svg>
                    Absen Pulang
                </a>
                <?php elseif ($status_hari_ini === 'cuti'): ?>
                <span class="btn-absen-hero ghost" style="cursor:default;opacity:.85;pointer-events:none;">
                    <svg viewBox="0 0 24 24"><path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z"/></svg>
                    Anda Sedang Cuti
                </span>
                <?php elseif ($status_hari_ini === 'sakit'): ?>
                <span class="btn-absen-hero ghost" style="cursor:default;opacity:.85;pointer-events:none;">
                    <svg viewBox="0 0 24 24"><path d="M10.5 2h3v3H17v3h-3v3h-3V8H8V5h2.5V2zm1.5 10c-3.87 0-7 3.13-7 7h14c0-3.87-3.13-7-7-7z"/></svg>
                    Semoga Cepat Sembuh 🙏
                </span>
                <?php elseif ($status_hari_ini === 'izin'): ?>
                <span class="btn-absen-hero ghost" style="cursor:default;opacity:.85;pointer-events:none;">
                    <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                    Anda Sedang Izin
                </span>
                <?php else: ?>
                <a href="absensi.php" class="btn-absen-hero ghost">
                    <svg viewBox="0 0 24 24"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg>
                    Lihat Riwayat
                </a>
                <?php endif; ?>

                <button class="tour-btn" id="btnTour" onclick="mulaiTour()">
                    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8z"/><path d="M15.31 7.38L13.11 13.11 7.38 15.31 9.58 9.58z"/></svg>
                    Panduan Fitur
                </button>
            </div>

        </div>
    </div><!-- /.hero -->

    <!-- MAIN GRID -->
    <div class="dash-grid">

        <!-- PROFILE CARD kiri -->
        <div class="f2" id="tour-profile">
            <div class="profile-card">
                <div class="pc-header">
                    <div class="pc-header-dots"></div>
                    <div class="pc-avatar-wrap">
                        <?php if (!empty($userInfo['foto'])): ?>
                        <img src="<?= htmlspecialchars(foto_url(basename($userInfo['foto']))) ?>" class="pc-avatar" alt="">
                        <?php else: ?>
                        <div class="pc-avatar-ini"><?= strtoupper(substr($userInfo['nama'] ?? 'P', 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="pc-status-dot" title="Akun Aktif"></div>
                    </div>
                    <div class="pc-name"><?= htmlspecialchars($userInfo['nama'] ?? '-') ?></div>
                    <div class="pc-role"><?= htmlspecialchars($userInfo['jabatan'] ?? 'Pegawai Non-ASN') ?></div>
                </div>

                <div class="pc-body">
                    <div class="pc-stats">
                        <div class="pc-stat s-ok">
                            <div class="pc-stat-val js-counter" data-target="<?= $stats['hadir'] ?>">0</div>
                            <div class="pc-stat-lbl">Hadir</div>
                        </div>
                        <div class="pc-stat s-late">
                            <div class="pc-stat-val js-counter" data-target="<?= $stats['sakit'] ?>">0</div>
                            <div class="pc-stat-lbl">Sakit</div>
                        </div>
                        <div class="pc-stat s-iz">
                            <div class="pc-stat-val js-counter" data-target="<?= $stats['izin'] ?>">0</div>
                            <div class="pc-stat-lbl">Izin</div>
                        </div>
                        <div class="pc-stat s-pct">
                            <div class="pc-stat-val js-counter js-pct" data-target="<?= $persen_hadir ?>">0%</div>
                            <div class="pc-stat-lbl">Hadir</div>
                        </div>
                    </div>

                    <div class="pc-id-card">
                        <?php
                        $idFields = [
                            ['icon'=>'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM6 20V4h5v7h7v9H6z','lbl'=>'NIK','val'=>$userInfo['nik']],
                            ['icon'=>'M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z','lbl'=>'No. HP','val'=>$userInfo['no_hp']],
                            ['icon'=>'M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z','lbl'=>'Email','val'=>$userInfo['email']],
                            ['icon'=>'M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z','lbl'=>'Unit Kerja','val'=>$userInfo['unit_kerja']],
                            ['icon'=>'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z','lbl'=>'Tempat Lahir','val'=>$userInfo['tempat_lahir']],
                            ['icon'=>'M19 4h-1V2h-2v2H8V2H6v2H5C3.9 4 3 4.9 3 6v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z','lbl'=>'Tgl Lahir','val'=>$userInfo['tgl_lahir'] ? date('d F Y',strtotime($userInfo['tgl_lahir'])) : null],
                        ];
                        foreach($idFields as $f):
                        ?>
                        <div class="pc-id-row">
                            <div class="pc-id-ico">
                                <svg viewBox="0 0 24 24"><path d="<?= $f['icon'] ?>"/></svg>
                            </div>
                            <div style="flex:1;min-width:0">
                                <div class="pc-id-lbl"><?= $f['lbl'] ?></div>
                                <div class="pc-id-val <?= empty($f['val']) ? 'empty' : '' ?>"><?= !empty($f['val']) ? htmlspecialchars($f['val']) : 'Belum diisi' ?></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="pc-complete">
                        <div class="pc-complete-head">
                            <span class="pc-complete-lbl">Kelengkapan Profil</span>
                            <span class="pc-complete-pct js-prog-pct" data-target="<?= $profilePercent ?>">0%</span>
                        </div>
                        <div class="pc-prog-track">
                            <div class="pc-prog-fill js-prog-fill" style="width:0%" data-target="<?= $profilePercent ?>"></div>
                        </div>
                        <?php if (!empty($profileMissing)): ?>
                        <div class="pc-missing">
                            <svg viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
                            <?= implode(', ', array_slice($profileMissing, 0, 3)) ?><?= count($profileMissing) > 3 ? ' +'.( count($profileMissing)-3).' lagi' : '' ?> belum diisi
                        </div>
                        <?php endif; ?>
                    </div>

                    <a href="profil.php" class="btn-to-profil">
                        <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                        <?= $profilePercent < 100 ? 'Lengkapi Profil Saya' : 'Lihat & Edit Profil' ?>
                    </a>
                </div>
            </div>
        </div>

        <!-- KOLOM KANAN -->
        <div>

            <!-- Status hari ini -->
            <?php
            $sCfg = [
                'hadir'         => ['lbl'=>'Hadir Hari Ini',  'cls'=>'sb-hadir', 'dot'=>true],
                'hadir_lengkap' => ['lbl'=>'Hadir Lengkap',   'cls'=>'sb-hadir', 'dot'=>true],
                'izin'          => ['lbl'=>'Izin',             'cls'=>'sb-izin',  'dot'=>false],
                'sakit'         => ['lbl'=>'Sakit',            'cls'=>'sb-sakit', 'dot'=>false],
                'cuti'          => ['lbl'=>'Cuti',             'cls'=>'sb-cuti',  'dot'=>false],
                'libur'         => ['lbl'=>'Hari Libur',       'cls'=>'sb-libur', 'dot'=>false],
                'belum_absen'   => ['lbl'=>'Belum Absen',      'cls'=>'sb-belum', 'dot'=>false],
            ];
            $sc = $sCfg[$status_hari_ini] ?? $sCfg['belum_absen'];
            ?>
            <div class="panel f2" id="tour-status">
                <div class="panel-head">
                    <div class="panel-title">
                        <div class="panel-ico"><svg viewBox="0 0 24 24"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67V7z"/></svg></div>
                        Status Kehadiran Hari Ini
                    </div>
                    <span class="status-badge <?= $sc['cls'] ?>">
                        <?php if($sc['dot']): ?><span class="sb-dot"></span><?php endif; ?>
                        <?= $sc['lbl'] ?>
                    </span>
                </div>
                <div class="status-body">
                    <div class="status-info">
                        <div class="status-lbl">Jam Kerja Berlaku</div>
                        <?php if ($status_hari_ini === 'cuti' && $recordCuti): ?>
                        <div class="status-ket" style="color:#3730a3;">
                            📅 Cuti disetujui: <?= date('d/m/Y', strtotime($recordCuti['tanggal_mulai'])) ?> – <?= date('d/m/Y', strtotime($recordCuti['tanggal_selesai'])) ?>
                            <?php if (!empty($recordCuti['alasan'])): ?><br><small>Alasan: <?= htmlspecialchars(mb_substr($recordCuti['alasan'],0,60)) ?></small><?php endif; ?>
                        </div>
                        <?php elseif ($status_hari_ini === 'sakit' && $recordIzin && $recordIzin['keterangan']): ?>
                        <div class="status-ket" style="color:#9f1239;">🏥 <?= htmlspecialchars($recordIzin['keterangan']) ?></div>
                        <?php elseif ($status_hari_ini === 'izin' && $recordIzin && $recordIzin['keterangan']): ?>
                        <div class="status-ket">📋 <?= htmlspecialchars($recordIzin['keterangan']) ?></div>
                        <?php endif; ?>
                        <div class="status-jam">
                            <svg viewBox="0 0 24 24"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm.5 5v5.25l4.5 2.67-.75 1.23L11 13V7h1.5z"/></svg>
                            Masuk <strong><?= date('H:i',strtotime($setting['jam_masuk'])) ?></strong>&nbsp;—&nbsp;Pulang <strong><?= date('H:i',strtotime($setting['jam_pulang'])) ?></strong> WIB
                        </div>
                    </div>
                    <div class="time-boxes">
                        <div class="tbox <?= ($record && $record['jam_masuk']) ? 'tb-in' : 'tb-emp' ?>">
                            <div class="tbox-ico"><svg viewBox="0 0 24 24"><path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5zm9 12h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-8v2h8v14z"/></svg></div>
                            <div class="tbox-lbl">Masuk</div>
                            <div class="tbox-val"><?= ($record && $record['jam_masuk']) ? date('H:i',strtotime($record['jam_masuk'])) : '--:--' ?></div>
                            <?php if ($record && $record['jam_masuk']): ?>
                            <span class="tbox-chip <?= $record['status_masuk']==='tepat waktu' ? 'tc-ok' : 'tc-late' ?>"><?= ucwords($record['status_masuk']) ?></span>
                            <?php else: ?><span class="tbox-chip tc-none">Belum</span><?php endif; ?>
                        </div>
                        <div class="tsep"><svg viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg></div>
                        <div class="tbox <?= ($record && $record['jam_pulang']) ? 'tb-out' : 'tb-emp' ?>">
                            <div class="tbox-ico"><svg viewBox="0 0 24 24"><path d="M13 17l5-5-5-5v3H4v4h9v3zm4-14H5c-1.11 0-2 .89-2 2v4h2V5h12v14H5v-4H3v4c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V5c0-1.11-.9-2-2-2z"/></svg></div>
                            <div class="tbox-lbl">Pulang</div>
                            <div class="tbox-val"><?= ($record && $record['jam_pulang']) ? date('H:i',strtotime($record['jam_pulang'])) : '--:--' ?></div>
                            <?php if ($record && $record['jam_pulang']): ?>
                            <span class="tbox-chip <?= $record['status_pulang']==='pulang tepat' ? 'tc-ok' : 'tc-late' ?>"><?= ucwords($record['status_pulang']) ?></span>
                            <?php else: ?><span class="tbox-chip tc-none">Belum</span><?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Quick Actions -->
            <?php
            $bisaMasuk  = !$isLibur && $status_hari_ini === 'belum_absen';
            $bisaPulang = !$isLibur && $status_hari_ini === 'hadir' && $record && $record['jam_masuk'] && !$record['jam_pulang'];
            ?>
            <div class="panel f3" id="tour-actions">
                <div class="panel-head">
                    <div class="panel-title">
                        <div class="panel-ico"><svg viewBox="0 0 24 24"><path d="M7 2v11h3v9l7-12h-4l4-8z"/></svg></div>
                        Aksi Cepat
                    </div>
                </div>
                <div class="qa-grid">
                    <a href="<?= $bisaMasuk ? 'absensi.php' : '#' ?>" class="qa-item <?= $bisaMasuk ? 'qa-ok' : 'qa-off' ?>">
                        <div class="qa-ico" style="background:var(--ok-bg)"><svg viewBox="0 0 24 24" fill="var(--ok)"><path d="M11 7L9.6 8.4l2.6 2.6H2v2h10.2l-2.6 2.6L11 17l5-5-5-5zm9 12h-8v2h8c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-8v2h8v14z"/></svg></div>
                        <span class="qa-lbl">Absen Masuk</span>
                        <span class="qa-sub"><?= $bisaMasuk ? 'Tap untuk absen' : ($isLibur ? 'Hari libur' : 'Sudah absen') ?></span>
                    </a>
                    <a href="<?= $bisaPulang ? 'absensi.php' : '#' ?>" class="qa-item <?= $bisaPulang ? 'qa-ok' : 'qa-off' ?>">
                        <div class="qa-ico" style="background:var(--info-bg)"><svg viewBox="0 0 24 24" fill="var(--info)"><path d="M13 17l5-5-5-5v3H4v4h9v3zm4-14H5c-1.11 0-2 .89-2 2v4h2V5h12v14H5v-4H3v4c0 1.1.89 2 2 2h12c1.1 0 2-.9 2-2V5c0-1.11-.9-2-2-2z"/></svg></div>
                        <span class="qa-lbl">Absen Pulang</span>
                        <span class="qa-sub"><?= $bisaPulang ? 'Tap untuk absen' : 'Belum waktunya' ?></span>
                    </a>
                    <a href="riwayat.php" class="qa-item qa-ok">
                        <div class="qa-ico" style="background:#F5F3FF"><svg viewBox="0 0 24 24" fill="#7C3AED"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg></div>
                        <span class="qa-lbl">Riwayat</span>
                        <span class="qa-sub">Histori absensi</span>
                    </a>
                    <a href="cuti.php" class="qa-item qa-ok">
                        <div class="qa-ico" style="background:var(--warn-bg)"><svg viewBox="0 0 24 24" fill="var(--warn)"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11z"/></svg></div>
                        <span class="qa-lbl">Cuti & Izin</span>
                        <span class="qa-sub">Ajukan permohonan</span>
                    </a>
                </div>
            </div>

            <!-- Chart + Pengajuan -->
            <div class="sec-grid f4" id="tour-chart">
                <div class="panel" style="margin-bottom:0">
                    <div class="panel-head">
                        <div class="panel-title">
                            <div class="panel-ico"><svg viewBox="0 0 24 24"><path d="M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zM16.2 13h2.8v6h-2.8v-6z"/></svg></div>
                            Kehadiran 7 Hari
                        </div>
                    </div>
                    <div class="chart-wrap"><canvas id="attendChart"></canvas></div>
                </div>

                <div class="panel" style="margin-bottom:0">
                    <div class="panel-head">
                        <div class="panel-title">
                            <div class="panel-ico"><svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM6 20V4h5v7h7v9H6z"/></svg></div>
                            Pengajuan Terbaru
                        </div>
                        <a href="cuti.php" class="panel-link">Semua <svg viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg></a>
                    </div>
                    <div class="pj-list">
                        <?php if (empty($pengajuan_list)): ?>
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                            <p>Belum ada pengajuan</p>
                        </div>
                        <?php else: ?>
                        <?php foreach($pengajuan_list as $pj):
                            $icoS = $pj['jenis']==='cuti' ? '#F5F3FF' : ($pj['jenis']==='sakit' ? 'var(--err-bg)' : 'var(--warn-bg)');
                            $icoC = $pj['jenis']==='cuti' ? '#7C3AED' : ($pj['jenis']==='sakit' ? 'var(--err)' : 'var(--warn)');
                        ?>
                        <div class="pj-row st-<?= $pj['status'] ?>">
                            <div class="pj-ico" style="background:<?= $icoS ?>">
                                <svg viewBox="0 0 24 24" fill="<?= $icoC ?>"><path d="M19 3h-1V1h-2v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V8h14v11z"/></svg>
                            </div>
                            <div style="flex:1;min-width:0">
                                <div class="pj-type"><?= ucfirst($pj['jenis']) ?></div>
                                <div class="pj-ket"><?= htmlspecialchars(mb_strimwidth($pj['ket']??'-',0,35,'…')) ?></div>
                            </div>
                            <span class="pj-chip chip-<?= $pj['status'] ?>"><?= ucfirst($pj['status']) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Riwayat minggu ini -->
            <div class="panel f5" id="tour-riwayat" style="margin-top:1.25rem">
                <div class="panel-head">
                    <div class="panel-title">
                        <div class="panel-ico"><svg viewBox="0 0 24 24"><path d="M13 3a9 9 0 0 0-9 9H1l3.89 3.89.07.14L9 12H6c0-3.87 3.13-7 7-7s7 3.13 7 7-3.13 7-7 7c-1.93 0-3.68-.79-4.94-2.06l-1.42 1.42A8.954 8.954 0 0 0 13 21a9 9 0 0 0 0-18zm-1 5v5l4.28 2.54.72-1.21-3.5-2.08V8H12z"/></svg></div>
                        Riwayat Minggu Ini
                    </div>
                    <a href="riwayat.php" class="panel-link">Semua <svg viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg></a>
                </div>
                <div class="week-wrap">
                    <table class="week-tbl">
                        <thead>
                            <tr>
                                <th style="width:24%">Hari / Tanggal</th>
                                <th style="width:16%">Masuk</th>
                                <th style="width:16%">Pulang</th>
                                <th style="width:26%">Status</th>
                                <th style="width:18%" class="d-xs-none">Mode</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php for ($i=0; $i<7; $i++):
                            $tgl     = date('Y-m-d', strtotime("monday this week +{$i} days"));
                            $rec     = $minggu_att[$tgl] ?? null;
                            $izin    = $minggu_izin[$tgl] ?? null;
                            $cuti    = $minggu_cuti[$tgl] ?? null;
                            $isLib   = isset($minggu_libur[$tgl]);
                            $isToday = ($tgl === $today);
                            $isFut   = ($tgl > $today);
                            $tgl_fmt = date('d', strtotime($tgl)).' '.$bulan_id[(int)date('m',strtotime($tgl))];

                            if ($isLib)                              { $bc='wb-lib';  $bl='Libur'; }
                            elseif ($cuti)                           { $bc='wb-cut'; $bl='Cuti'; }
                            elseif ($izin)                           { $bc=($izin['jenis']==='sakit'?'wb-late':'wb-iz'); $bl=ucfirst($izin['jenis']); }
                            elseif ($rec && $rec['jam_masuk'])       { $bc=($rec['status_masuk']==='terlambat'?'wb-late':'wb-ok'); $bl=($rec['status_masuk']==='terlambat'?'Terlambat':'Tepat Waktu'); }
                            elseif ($isFut)                          { $bc=''; $bl=''; }
                            else                                     { $bc='wb-alp'; $bl=($isToday?'Belum Absen':'Alpa'); }
                        ?>
                        <tr class="<?= $isToday?'today':'' ?> <?= $isFut?'future':'' ?>">
                            <td>
                                <div class="wd-d"><?= $tgl_fmt ?></div>
                                <div class="wd-n"><?= $nama_hari[$i] ?><?php if($isToday): ?><span class="today-pip">Hari ini</span><?php endif; ?></div>
                            </td>
                            <td><?= ($rec&&$rec['jam_masuk']) ? '<span class="wt-v">'.date('H:i',strtotime($rec['jam_masuk'])).'</span>' : '<span class="wt-na">—</span>' ?></td>
                            <td><?= ($rec&&$rec['jam_pulang']) ? '<span class="wt-v">'.date('H:i',strtotime($rec['jam_pulang'])).'</span>' : '<span class="wt-na">—</span>' ?></td>
                            <td><?= (!$isFut && $bl) ? '<span class="wb '.$bc.'">'.$bl.'</span>' : '<span class="wt-na">—</span>' ?></td>
                            <td class="d-xs-none"><?= ($rec&&$rec['mode']) ? '<span class="wmode">'.strtoupper($rec['mode']).'</span>' : '<span class="wt-na">—</span>' ?></td>
                        </tr>
                        <?php endfor; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div><!-- /kolom kanan -->
    </div><!-- /dash-grid -->
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/* ── Analog + Digital Clock ── */
(function tick() {
    const n  = new Date();
    const h  = n.getHours() % 12;
    const m  = n.getMinutes();
    const s  = n.getSeconds();
    const ms = n.getMilliseconds();

    document.getElementById('ch').textContent = String(n.getHours()).padStart(2,'0');
    document.getElementById('cm').textContent = String(m).padStart(2,'0');
    document.getElementById('cs').textContent = String(s).padStart(2,'0');

    const sDeg = (s + ms/1000) * 6;
    const mDeg = (m + s/60) * 6;
    const hDeg = (h + m/60) * 30;

    const hr  = document.getElementById('hrHand');
    const min = document.getElementById('minHand');
    const sec = document.getElementById('secHand');
    if (hr)  hr.style.transform  = `rotate(${hDeg}deg)`;
    if (min) min.style.transform = `rotate(${mDeg}deg)`;
    if (sec) sec.style.transform = `rotate(${sDeg}deg)`;

    setTimeout(tick, 50);
})();

/* ── Chart 7 Hari — Line Gradient (BMKG style) ── */
const cd  = <?= json_encode($chart_data) ?>;
const rawData = cd.map(d => d.hadir);
let attendChartInstance = null;

function buildAttendChart() {
    if (attendChartInstance) return; // jangan render ulang jika sudah ada

    const canvas = document.getElementById('attendChart');
    const ctx = canvas.getContext('2d');

    // Gradient fill — dibuat setelah canvas punya ukuran nyata
    const gradFill = ctx.createLinearGradient(0, 0, 0, canvas.offsetHeight || 180);
    gradFill.addColorStop(0,   'rgba(201,168,76,0.45)');
    gradFill.addColorStop(0.5, 'rgba(201,168,76,0.15)');
    gradFill.addColorStop(1,   'rgba(201,168,76,0.00)');

    attendChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: cd.map(d => d.day),
            datasets: [{
                data: rawData,
                borderColor: '#C9A84C',
                borderWidth: 2.5,
                pointBackgroundColor: rawData.map(v => v ? '#C9A84C' : '#CBD5E8'),
                pointBorderColor:     rawData.map(v => v ? '#fff'    : '#94a3b8'),
                pointBorderWidth: 2,
                pointRadius: 5,
                pointHoverRadius: 7,
                pointHoverBackgroundColor: '#E8C97A',
                pointHoverBorderColor: '#fff',
                pointHoverBorderWidth: 2.5,
                fill: true,
                backgroundColor: gradFill,
                tension: 0.42,
                spanGaps: true
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            animation: { duration: 1000, easing: 'easeInOutQuart' },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0f2241',
                    titleColor: '#CBD5E8',
                    bodyColor: '#E8C97A',
                    borderColor: 'rgba(201,168,76,.4)',
                    borderWidth: 1,
                    padding: { x: 14, y: 10 },
                    displayColors: false,
                    cornerRadius: 8,
                    callbacks: {
                        title: i => cd[i[0].dataIndex].label,
                        label: c => c.parsed.y ? '● Hadir' : '○ Tidak Hadir'
                    }
                }
            },
            scales: {
                y: {
                    display: true,
                    beginAtZero: true,
                    max: 1.4,
                    min: -0.1,
                    grid: { color: 'rgba(138,155,190,0.08)', drawBorder: false },
                    border: { display: false, dash: [4,4] },
                    ticks: { display: false }
                },
                x: {
                    ticks: {
                        color: '#8A9BBE',
                        font: { size: 10, family: 'DM Sans', weight: '500' },
                        padding: 6
                    },
                    grid: { display: false },
                    border: { display: false }
                }
            }
        }
    });
}

// Trigger animasi saat chart masuk viewport saat scroll
const chartObserver = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            buildAttendChart();
            chartObserver.disconnect(); // cukup sekali
        }
    });
}, { threshold: 0.3 }); // 30% elemen kelihatan baru trigger

chartObserver.observe(document.getElementById('attendChart'));

/* ── Animasi Counter & Progress Bar ── */
(function() {
    // Easing: easeOutQuart — cepat di awal, melambat di akhir
    function easeOutQuart(t) { return 1 - Math.pow(1 - t, 4); }

    // Counter angka (0 → target)
    function animateCounter(el) {
        const target = parseInt(el.dataset.target, 10);
        const isPct  = el.classList.contains('js-pct');
        if (target === 0) { el.textContent = isPct ? '0%' : '0'; return; }
        const duration = 900;
        const start    = performance.now();
        function step(now) {
            const elapsed  = Math.min(now - start, duration);
            const progress = easeOutQuart(elapsed / duration);
            const current  = Math.round(progress * target);
            el.textContent = isPct ? current + '%' : current;
            if (elapsed < duration) requestAnimationFrame(step);
            else el.textContent = isPct ? target + '%' : target;
        }
        requestAnimationFrame(step);
    }

    // Progress bar width (0% → target%)
    function animateProgress(fillEl, pctEl) {
        const target   = parseInt(fillEl.dataset.target, 10);
        const duration = 1000;
        const start    = performance.now();
        function step(now) {
            const elapsed  = Math.min(now - start, duration);
            const progress = easeOutQuart(elapsed / duration);
            const current  = progress * target;
            fillEl.style.width = current.toFixed(1) + '%';
            if (pctEl) pctEl.textContent = Math.round(current) + '%';
            if (elapsed < duration) requestAnimationFrame(step);
            else {
                fillEl.style.width = target + '%';
                if (pctEl) pctEl.textContent = target + '%';
            }
        }
        requestAnimationFrame(step);
    }

    // Trigger saat elemen masuk viewport
    function observeOnce(el, callback) {
        const obs = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) { callback(); obs.disconnect(); }
            });
        }, { threshold: 0.4 });
        obs.observe(el);
    }

    // Pasang observer ke setiap counter
    document.querySelectorAll('.js-counter').forEach(el => {
        observeOnce(el, () => animateCounter(el));
    });

    // Pasang observer ke progress bar
    const fillEl = document.querySelector('.js-prog-fill');
    const pctEl  = document.querySelector('.js-prog-pct');
    if (fillEl) {
        observeOnce(fillEl, () => animateProgress(fillEl, pctEl));
    }
})();

/* ── Tour ── */
const TOUR_KEY = 'bbws_dpg_v6';
function muatDriver(cb) {
    if (window.driver?.js) { cb(); return; }
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/driver.js/1.3.1/driver.js.iife.js';
    s.onload = () => setTimeout(cb, 150);
    document.head.appendChild(s);
}
function mulaiTour() {
    muatDriver(() => {
        const drv = window.driver.js.driver({
            showProgress: true, progressText: '{{current}} / {{total}}',
            nextBtnText: 'Berikutnya →', prevBtnText: '← Kembali', doneBtnText: '✓ Selesai',
            allowClose: true, smoothScroll: true, animate: true,
            overlayColor: 'rgba(10,22,40,.82)',
            onDestroyStarted: () => { localStorage.setItem(TOUR_KEY,'1'); drv.destroy(); },
            steps: [
                { popover:{ title:'Selamat Datang', description:'Dashboard absensi ASN. Panduan ini memperkenalkan semua fitur utama.', side:'over', align:'center' } },
                { element:'#tour-header',  popover:{ title:'Header & Jam', description:'Identitas pegawai, jam realtime, jam kerja, dan tombol absen yang berubah sesuai kondisi hari ini.', side:'bottom', align:'start' } },
                { element:'#tour-profile', popover:{ title:'Kartu Identitas', description:'Foto, data diri, dan statistik kehadiran. Lengkapi profil untuk memaksimalkan sistem.', side:'right', align:'start' } },
                { element:'#tour-status',  popover:{ title:'Status Hari Ini', description:'Jam masuk, jam pulang, dan status kehadiran aktual.', side:'bottom', align:'start' } },
                { element:'#tour-actions', popover:{ title:'Aksi Cepat', description:'Tombol aktif/nonaktif otomatis menyesuaikan kondisi hari ini.', side:'bottom', align:'center' } },
                { element:'#tour-chart',   popover:{ title:'Grafik & Pengajuan', description:'Tren kehadiran 7 hari dan status pengajuan cuti/izin terkini.', side:'top', align:'center' } },
                { element:'#tour-riwayat', popover:{ title:'Riwayat Minggu Ini', description:'Detail harian: jam masuk, pulang, status, dan mode kerja.', side:'top', align:'start' } },
                { element:'#btnTour',      popover:{ title:'Buka Panduan', description:'Klik tombol ini kapan saja untuk mengulang tur.', side:'bottom', align:'end' } },
                { popover:{ title:'Siap Digunakan', description:'Semua fitur telah diperkenalkan. Catat kehadiran Anda tepat waktu setiap hari.', side:'over', align:'center' } }
            ]
        });
        drv.drive();
    });
}
if (!localStorage.getItem(TOUR_KEY)) {
    window.addEventListener('load', () => setTimeout(mulaiTour, 900));
}
</script>

<?php include '../templates/footer.php'; ?>