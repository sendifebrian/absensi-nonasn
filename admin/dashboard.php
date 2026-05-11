<?php
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/helper.php';
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_admin()) { header('Location: ../pegawai/dashboard.php'); exit(); }

$total_pegawai      = $pdo->query("SELECT COUNT(*) FROM users WHERE role='pegawai'")->fetchColumn();
$pegawai_aktif      = $pdo->query("SELECT COUNT(*) FROM users WHERE role='pegawai' AND status='aktif'")->fetchColumn();
$pegawai_nonaktif   = (int)$total_pegawai - (int)$pegawai_aktif;
$hadir_hari_ini     = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM attendance WHERE tanggal=CURDATE() AND jam_masuk IS NOT NULL")->fetchColumn();
$terlambat_hari_ini = $pdo->query("SELECT COUNT(*) FROM attendance WHERE tanggal=CURDATE() AND status_masuk='terlambat'")->fetchColumn();
$persen_hadir       = $total_pegawai > 0 ? round(($hadir_hari_ini / $total_pegawai) * 100, 1) : 0;

$today = date('Y-m-d');
$total_alpa_hari_ini = 0;
$stmt = $pdo->query("SELECT id FROM users WHERE role='pegawai' AND status='aktif'");
foreach($stmt->fetchAll() as $p) {
    $res = hitung_alpa_pegawai($pdo, $p['id'], $today, $today);
    $total_alpa_hari_ini += $res['alpa'];
}

$stmt = $pdo->prepare("SELECT tanggal, COUNT(DISTINCT user_id) AS cnt FROM attendance WHERE tanggal >= CURDATE() - INTERVAL 6 DAY AND jam_masuk IS NOT NULL GROUP BY tanggal");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
$chart_data = [];
for ($i = 6; $i >= 0; $i--) {
    $tgl = date('Y-m-d', strtotime("-{$i} days"));
    $chart_data[] = ['day' => date('D', strtotime($tgl)), 'label' => date('d M', strtotime($tgl)), 'count' => (int)($rows[$tgl] ?? 0)];
}

$recent = $pdo->query("SELECT u.nama, u.foto, a.tanggal, a.jam_masuk, a.status_masuk FROM attendance a JOIN users u ON a.user_id = u.id WHERE a.jam_masuk IS NOT NULL ORDER BY a.tanggal DESC, a.jam_masuk DESC LIMIT 6")->fetchAll(PDO::FETCH_ASSOC);
$tops   = $pdo->query("SELECT u.nama, u.jabatan, u.foto, COUNT(*) as total FROM attendance a JOIN users u ON a.user_id = u.id WHERE MONTH(a.tanggal)=MONTH(CURDATE()) AND YEAR(a.tanggal)=YEAR(CURDATE()) AND a.status_masuk='tepat waktu' GROUP BY u.id ORDER BY total DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);

$namaAdmin = $_SESSION['nama'] ?? 'Admin';
$hari_id  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
$bulan_id = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$tgl_indo = $hari_id[date('w')].', '.date('d').' '.$bulan_id[(int)date('m')].' '.date('Y');
$bulan_ini = $bulan_id[(int)date('m')].' '.date('Y');

function av($nama, $foto, $size=36) {
    $ini = strtoupper(substr(trim($nama),0,1));
    if (!empty($foto)) {
        return '<img src="'.htmlspecialchars(foto_url(basename($foto))).'" class="av-img" data-ini="'.$ini.'" style="width:'.$size.'px;height:'.$size.'px;border-radius:50%;object-fit:cover;border:2px solid rgba(201,168,76,.25);flex-shrink:0" alt="">';
    }
    return '<div style="width:'.$size.'px;height:'.$size.'px;border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#C9A84C,#E8C97A);color:#5C3D00;font-weight:800;font-size:'.($size*0.38).'px;display:flex;align-items:center;justify-content:center;">'.$ini.'</div>';
}
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

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
    --txt2:      #334155;
    --muted:     #64748B;
    --border:    #E2E8F4;
    --ok:        #059669;
    --ok-bg:     #ECFDF5;
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
    --r-sm:8px;
}

body { font-family: 'DM Sans', sans-serif; background: var(--surface); color: var(--txt); }

/* ── Layout ── */
.dw { padding: 1.5rem 1.5rem 3rem; max-width: 1440px; margin: 0 auto; }

/* ══════════════════════════════════
   HERO HEADER
══════════════════════════════════ */
.hero {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 55%, var(--navy-lit) 100%);
    border-radius: 22px;
    padding: 1.75rem 2rem;
    margin-bottom: 1.25rem;
    position: relative; overflow: hidden;
    box-shadow: 0 16px 48px rgba(10,22,40,.22);
}
.hero::before {
    content: '';
    position: absolute; top: -80px; right: -80px;
    width: 300px; height: 300px; border-radius: 50%;
    border: 1px solid rgba(201,168,76,.1);
    pointer-events: none;
}
.hero::after {
    content: '';
    position: absolute; bottom: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--gold) 35%, var(--gold-lit) 65%, transparent);
}
.hero-grid {
    display: grid;
    grid-template-columns: 1fr auto;
    gap: 1.5rem; align-items: center;
}
.hero-dots {
    position: absolute; top: 20px; right: 140px;
    width: 100px; height: 80px;
    background-image: radial-gradient(circle, rgba(201,168,76,.38) 1.5px, transparent 1.5px);
    background-size: 16px 16px; opacity: .28; pointer-events: none;
}
.hero-eyebrow {
    font-size: .65rem; font-weight: 700;
    letter-spacing: .2em; text-transform: uppercase;
    color: var(--gold); margin-bottom: .35rem;
}
.hero-name {
    font-family: 'Cormorant Garamond', serif;
    font-size: clamp(1.5rem, 3vw, 2rem);
    font-weight: 700; color: var(--white);
    line-height: 1.15; margin-bottom: .4rem;
}
.hero-date {
    font-size: .76rem; color: var(--silver);
    display: flex; align-items: center; gap: .4rem;
}
.hero-date svg { width: 13px; height: 13px; fill: var(--silver); }
.hero-right { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; justify-content: flex-end; }
.clock-card {
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 14px; padding: .85rem 1.35rem;
    text-align: center; backdrop-filter: blur(8px);
    min-width: 140px;
}
.clock-time {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.9rem; font-weight: 700;
    color: var(--white); letter-spacing: .04em;
    font-variant-numeric: tabular-nums; line-height: 1;
}
.clock-colon { color: var(--gold); animation: blink 1s step-end infinite; }
@keyframes blink { 50% { opacity: .25; } }
.clock-lbl { font-size: .58rem; text-transform: uppercase; letter-spacing: .1em; color: var(--silver); margin-top: .3rem; }
.tour-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .55rem 1rem;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: var(--r-sm); color: rgba(255,255,255,.75);
    font-family: 'DM Sans', sans-serif; font-size: .75rem; font-weight: 500;
    cursor: pointer; transition: all .2s; white-space: nowrap;
    backdrop-filter: blur(6px);
}
.tour-btn svg { width: 14px; height: 14px; fill: currentColor; }
.tour-btn:hover { background: rgba(201,168,76,.15); border-color: rgba(201,168,76,.3); color: var(--gold-lit); }

/* ══════════════════════════════════
   STAT CARDS — 2026 style
══════════════════════════════════ */
.stats-row {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 1rem; margin-bottom: 1.25rem;
}
.scard {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r);
    padding: 1.2rem 1.1rem 1rem;
    position: relative; overflow: hidden;
    box-shadow: var(--sh1);
    transition: transform .22s cubic-bezier(.22,1,.36,1), box-shadow .22s;
    cursor: default;
}
.scard:hover { transform: translateY(-3px); box-shadow: var(--sh3); }

/* Accent top bar */
.scard::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0;
    height: 3px; border-radius: var(--r) var(--r) 0 0;
}
.scard.c-green::before  { background: linear-gradient(90deg, #059669, #34D399); }
.scard.c-blue::before   { background: linear-gradient(90deg, #2563EB, #60A5FA); }
.scard.c-warn::before   { background: linear-gradient(90deg, #D97706, #FBBF24); }
.scard.c-red::before    { background: linear-gradient(90deg, #DC2626, #F87171); }
.scard.c-gold::before   { background: linear-gradient(90deg, var(--gold), var(--gold-lit)); }

/* Gold card special */
.scard.premium {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-lit) 100%);
    border: none;
    box-shadow: 0 12px 36px rgba(10,22,40,.22);
}
.scard.premium::before { background: linear-gradient(90deg, var(--gold), var(--gold-lit)); }

/* Corner decoration */
.scard-deco {
    position: absolute; bottom: -16px; right: -16px;
    width: 72px; height: 72px; border-radius: 50%;
    opacity: .055; pointer-events: none;
}
.scard.c-green .scard-deco  { background: #059669; }
.scard.c-blue  .scard-deco  { background: #2563EB; }
.scard.c-warn  .scard-deco  { background: #D97706; }
.scard.c-red   .scard-deco  { background: #DC2626; }
.scard.c-gold  .scard-deco  { background: var(--gold); }
.scard.premium .scard-deco  { background: var(--gold); opacity: .12; }

.scard-ico {
    width: 40px; height: 40px; border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: .85rem; flex-shrink: 0;
}
.scard.c-green .scard-ico  { background: var(--ok-bg); }
.scard.c-blue  .scard-ico  { background: var(--info-bg); }
.scard.c-warn  .scard-ico  { background: var(--warn-bg); }
.scard.c-red   .scard-ico  { background: var(--err-bg); }
.scard.c-gold  .scard-ico  { background: var(--gold-bg); }
.scard.premium .scard-ico  { background: rgba(201,168,76,.15); }
.scard-ico svg { width: 19px; height: 19px; }
.scard.c-green .scard-ico svg  { fill: var(--ok); }
.scard.c-blue  .scard-ico svg  { fill: var(--info); }
.scard.c-warn  .scard-ico svg  { fill: var(--warn); }
.scard.c-red   .scard-ico svg  { fill: var(--err); }
.scard.c-gold  .scard-ico svg  { fill: var(--gold); }
.scard.premium .scard-ico svg  { fill: var(--gold-lit); }

.scard-lbl {
    font-size: .68rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .09em;
    color: var(--muted); margin-bottom: .22rem;
}
.scard.premium .scard-lbl { color: rgba(255,255,255,.55); }

.scard-val {
    font-family: 'Cormorant Garamond', serif;
    font-size: 2rem; font-weight: 700;
    color: var(--navy); line-height: 1; margin-bottom: .3rem;
}
.scard.premium .scard-val { color: var(--white); }

.scard-sub { font-size: .71rem; color: var(--muted); display: flex; align-items: center; gap: .35rem; flex-wrap: wrap; }
.scard.premium .scard-sub { color: rgba(255,255,255,.45); }

.sub-dot { width: 5px; height: 5px; border-radius: 50%; display: inline-block; flex-shrink: 0; }

/* Progress bar inside card */
.scard-prog { margin-top: .55rem; }
.prog-track { height: 4px; background: var(--surf2); border-radius: 99px; overflow: hidden; }
.prog-fill  { height: 100%; border-radius: 99px; transition: width .8s cubic-bezier(.4,0,.2,1); }

/* ══════════════════════════════════
   QUICK ACTIONS
══════════════════════════════════ */
.panel {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r);
    box-shadow: var(--sh1);
    overflow: hidden;
    margin-bottom: 1.25rem;
}
.panel-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: .9rem 1.35rem;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(90deg, #FAFCFF, var(--white));
}
.panel-title {
    display: flex; align-items: center; gap: .55rem;
    font-size: .84rem; font-weight: 700; color: var(--navy);
}
.panel-title-ico {
    width: 28px; height: 28px; border-radius: 8px;
    background: var(--gold-bg); display: flex; align-items: center; justify-content: center;
}
.panel-title-ico svg { width: 14px; height: 14px; fill: var(--gold); }
.panel-link {
    font-size: .74rem; font-weight: 600; color: var(--gold);
    text-decoration: none; display: flex; align-items: center; gap: .2rem;
    transition: color .15s;
}
.panel-link svg { width: 14px; height: 14px; fill: currentColor; }
.panel-link:hover { color: var(--navy); }

.qa-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    gap: .75rem; padding: 1.1rem 1.35rem;
}
.qa-item {
    display: flex; flex-direction: column; align-items: center; gap: .5rem;
    padding: 1rem .75rem;
    border: 1.5px solid var(--border);
    border-radius: 14px; text-decoration: none;
    color: var(--txt2); font-size: .75rem; font-weight: 600;
    text-align: center; background: var(--white);
    transition: all .22s cubic-bezier(.22,1,.36,1);
    position: relative; overflow: hidden;
}
.qa-item::before {
    content: '';
    position: absolute; inset: 0;
    background: linear-gradient(135deg, var(--gold-bg), transparent);
    opacity: 0; transition: opacity .22s;
}
.qa-item:hover { border-color: var(--gold); transform: translateY(-3px); box-shadow: 0 8px 24px rgba(201,168,76,.14); color: var(--navy); }
.qa-item:hover::before { opacity: 1; }
.qa-ico {
    width: 46px; height: 46px; border-radius: 13px;
    display: flex; align-items: center; justify-content: center;
    transition: transform .22s;
    position: relative; z-index: 1;
}
.qa-ico svg { width: 22px; height: 22px; }
.qa-item:hover .qa-ico { transform: scale(1.1) translateY(-1px); }
.qa-lbl { font-size: .73rem; position: relative; z-index: 1; line-height: 1.3; }

/* ══════════════════════════════════
   MAIN GRID
══════════════════════════════════ */
.main-grid {
    display: grid;
    grid-template-columns: 1.65fr 1fr;
    gap: 1.25rem; margin-bottom: 1.25rem;
}

/* Chart */
.chart-wrap { padding: 1.1rem 1.35rem 1rem; height: 230px; }
.chart-wrap canvas { width: 100% !important; height: 100% !important; }

/* Performers */
.performers { padding: .5rem .85rem .85rem; display: flex; flex-direction: column; gap: .5rem; }
.perf-row {
    display: flex; align-items: center; gap: .75rem;
    padding: .8rem 1rem;
    border-radius: 12px;
    border: 1px solid var(--border);
    background: var(--white);
    transition: all .2s;
    position: relative; overflow: hidden;
}
.perf-row::before {
    content: '';
    position: absolute; left: 0; top: 0; bottom: 0;
    width: 3px; border-radius: 0 2px 2px 0;
}
.perf-row:nth-child(1)::before { background: linear-gradient(to bottom, #F59E0B, #FBBF24); }
.perf-row:nth-child(2)::before { background: linear-gradient(to bottom, #94A3B8, #CBD5E8); }
.perf-row:nth-child(3)::before { background: linear-gradient(to bottom, #CD7F32, #E09B45); }
.perf-row:hover { background: var(--surf2); transform: translateX(3px); }
.perf-av { position: relative; flex-shrink: 0; }
.perf-medal { position: absolute; bottom: -3px; right: -5px; font-size: .7rem; line-height: 1; }
.perf-name { font-size: .82rem; font-weight: 700; color: var(--navy); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.perf-role { font-size: .68rem; color: var(--muted); margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.perf-score { margin-left: auto; text-align: right; flex-shrink: 0; }
.perf-n {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.4rem; font-weight: 700; color: var(--navy); line-height: 1;
}
.perf-u { font-size: .62rem; color: var(--muted); }

/* ══════════════════════════════════
   ACTIVITY TABLE
══════════════════════════════════ */
.act-table { width: 100%; border-collapse: collapse; }
.act-table thead th {
    padding: .65rem 1.35rem;
    font-size: .63rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .09em;
    color: var(--silver); background: var(--surface);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.act-table tbody td {
    padding: .8rem 1.35rem;
    border-bottom: 1px solid #F5F7FD;
    vertical-align: middle; font-size: .82rem;
}
.act-table tbody tr:last-child td { border-bottom: none; }
.act-table tbody tr { transition: background .12s; }
.act-table tbody tr:hover { background: var(--surface); }
.act-user { display: flex; align-items: center; gap: .6rem; }
.act-name { font-weight: 600; color: var(--navy); font-size: .82rem; }
.act-date { color: var(--muted); font-size: .78rem; white-space: nowrap; }
.act-time {
    display: inline-flex; align-items: center; gap: .3rem;
    background: var(--surf2); border: 1px solid var(--border);
    color: var(--muted); padding: 3px 10px; border-radius: 99px;
    font-size: .73rem; font-weight: 600; white-space: nowrap;
}
.act-time svg { width: 11px; height: 11px; fill: var(--silver); }
.chip {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: 3px 10px; border-radius: 99px;
    font-size: .7rem; font-weight: 600; white-space: nowrap;
}
.chip svg { width: 11px; height: 11px; }
.chip-ok   { background: var(--ok-bg);   color: var(--ok);   border: 1px solid #A7F3D0; }
.chip-ok svg { fill: var(--ok); }
.chip-late { background: var(--warn-bg); color: var(--warn); border: 1px solid #FDE68A; }
.chip-late svg { fill: var(--warn); }

/* Badge pill */
.badge-pill {
    font-size: .63rem; font-weight: 600;
    padding: .2rem .65rem; border-radius: 99px;
    background: var(--gold-bg); color: #7A5C00;
    border: 1px solid var(--gold-pale);
}

/* Empty state */
.empty-state { text-align: center; padding: 2.5rem 1rem; }
.empty-state svg { width: 36px; height: 36px; fill: var(--ash); margin-bottom: .75rem; }
.empty-state p { font-size: .84rem; color: var(--muted); margin: 0; }

/* Tour button custom */
.driver-popover { font-family: 'DM Sans', sans-serif !important; border-radius: 16px !important; border: 1px solid rgba(201,168,76,.2) !important; box-shadow: 0 20px 60px rgba(0,0,0,.18) !important; }
.driver-popover-title { font-weight: 700 !important; font-size: .9rem !important; color: var(--navy) !important; }
.driver-popover-description { font-size: .78rem !important; color: var(--txt2) !important; line-height: 1.65 !important; }
.driver-popover-next-btn, .driver-popover-done-btn { background: var(--gold) !important; border: none !important; color: var(--navy) !important; font-weight: 700 !important; border-radius: 8px !important; }
.driver-popover-prev-btn { background: var(--surf2) !important; border: 1px solid var(--border) !important; border-radius: 8px !important; }

/* ══════════════════════════════════
   RESPONSIVE
══════════════════════════════════ */
@media (max-width: 1280px) {
    .stats-row { grid-template-columns: repeat(3, 1fr); }
    .qa-grid   { grid-template-columns: repeat(4, 1fr); }
}
@media (max-width: 1100px) {
    .main-grid { grid-template-columns: 1fr; }
    .stats-row { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 900px) {
    .qa-grid { grid-template-columns: repeat(4, 1fr); }
    .stats-row { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 768px) {
    .dw { padding: 1rem .9rem 2.5rem; }
    .hero { padding: 1.35rem 1.25rem; border-radius: 16px; }
    .hero-grid { grid-template-columns: 1fr; gap: 1rem; }
    .hero-right { justify-content: flex-start; }
    .clock-card { min-width: 120px; }
    .stats-row { grid-template-columns: repeat(2, 1fr); gap: .75rem; }
    .qa-grid { grid-template-columns: repeat(3, 1fr); gap: .6rem; padding: .85rem 1rem; }
    .scard-val { font-size: 1.65rem; }
    .act-table thead th, .act-table tbody td { padding-left: 1rem; padding-right: 1rem; }
    .panel-head { padding: .8rem 1rem; }
}
@media (max-width: 576px) {
    .stats-row { grid-template-columns: repeat(2, 1fr); gap: .65rem; }
    .qa-grid { grid-template-columns: repeat(2, 1fr); gap: .55rem; }
    .hero-name { font-size: 1.45rem; }
    .clock-time { font-size: 1.55rem; }
    .scard { padding: 1rem .85rem .85rem; }
    .scard-val { font-size: 1.5rem; }
    .d-xs-none { display: none !important; }
}
@media (max-width: 400px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
    .hero-dots { display: none; }
}

/* Animations */
@keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}
.fade-1 { animation: fadeUp .45s ease both; }
.fade-2 { animation: fadeUp .45s .07s ease both; }
.fade-3 { animation: fadeUp .45s .14s ease both; }
.fade-4 { animation: fadeUp .45s .21s ease both; }
.fade-5 { animation: fadeUp .45s .28s ease both; }
</style>

<div class="main-content">
<div class="dw">

    <!-- ═══ HERO ═══ -->
    <div class="hero fade-1" id="tour-header">
        <div class="hero-dots"></div>
        <div class="hero-grid">
            <div>
                <div class="hero-eyebrow">Dashboard Admin</div>
                <h1 class="hero-name"><?= htmlspecialchars($namaAdmin) ?></h1>
                <div class="hero-date">
                    <svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5C3.9 4 3 4.9 3 6v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/></svg>
                    <?= $tgl_indo ?>
                </div>
            </div>
            <div class="hero-right">
                <div class="clock-card">
                    <div class="clock-time">
                        <span id="ch">--</span><span class="clock-colon">:</span><span id="cm">--</span><span class="clock-colon">:</span><span id="cs">--</span>
                    </div>
                    <div class="clock-lbl">Waktu Sekarang · WIB</div>
                </div>
                <button class="tour-btn" id="btnTourAdmin" onclick="mulaiTour()">
                    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/></svg>
                    Panduan
                </button>
            </div>
        </div>
    </div>

    <!-- ═══ STAT CARDS ═══ -->
    <div class="stats-row fade-2" id="tour-stats">
        <!-- Total Pegawai -->
        <div class="scard c-green">
            <div class="scard-deco"></div>
            <div class="scard-ico">
                <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
            </div>
            <div class="scard-lbl">Total Pegawai</div>
            <div class="scard-val js-count" data-target="<?= $total_pegawai ?>">0</div>
            <div class="scard-sub">
                <span class="sub-dot" style="background:var(--ok)"></span><?= $pegawai_aktif ?> aktif
                <?php if($pegawai_nonaktif > 0): ?>
                &nbsp;·&nbsp;<span class="sub-dot" style="background:var(--ash)"></span><?= $pegawai_nonaktif ?> nonaktif
                <?php endif; ?>
            </div>
        </div>

        <!-- Hadir -->
        <div class="scard c-blue">
            <div class="scard-deco"></div>
            <div class="scard-ico">
                <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
            </div>
            <div class="scard-lbl">Hadir Hari Ini</div>
            <div class="scard-val js-count" data-target="<?= $hadir_hari_ini ?>">0</div>
            <div class="scard-sub"><span class="js-count-pct" data-target="<?= $persen_hadir ?>">0</span>% dari total pegawai</div>
            <div class="scard-prog">
                <div class="prog-track">
                    <div class="prog-fill js-prog" style="width:0%;background:linear-gradient(90deg,#2563EB,#60A5FA)" data-target="<?= $persen_hadir ?>"></div>
                </div>
            </div>
        </div>

        <!-- Terlambat -->
        <div class="scard c-warn">
            <div class="scard-deco"></div>
            <div class="scard-ico">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm.5 5v5.25l4.5 2.67-.75 1.23L11 13V7h1.5z"/></svg>
            </div>
            <div class="scard-lbl">Terlambat</div>
            <div class="scard-val js-count" data-target="<?= $terlambat_hari_ini ?>">0</div>
            <div class="scard-sub">pegawai masuk terlambat</div>
        </div>

        <!-- Alpa -->
        <div class="scard c-red">
            <div class="scard-deco"></div>
            <div class="scard-ico">
                <svg viewBox="0 0 24 24"><path d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z"/></svg>
            </div>
            <div class="scard-lbl">Alpa Hari Ini</div>
            <div class="scard-val js-count" data-target="<?= $total_alpa_hari_ini ?>">0</div>
            <div class="scard-sub">tidak hadir tanpa keterangan</div>
        </div>

        <!-- Tingkat Kehadiran — premium gold card -->
        <div class="scard premium c-gold">
            <div class="scard-deco"></div>
            <div class="scard-ico">
                <svg viewBox="0 0 24 24"><path d="M16 6l2.29 2.29-4.88 4.88-4-4L2 16.59 3.41 18l6-6 4 4 6.3-6.29L22 12V6z"/></svg>
            </div>
            <div class="scard-lbl">Tingkat Kehadiran</div>
            <div class="scard-val js-count js-pct" data-target="<?= $persen_hadir ?>">0%</div>
            <div class="scard-sub"><?= $bulan_ini ?></div>
        </div>
    </div>

    <!-- ═══ QUICK ACTIONS ═══ -->
    <div class="panel fade-3" id="tour-quickactions">
        <div class="panel-head">
            <div class="panel-title">
                <div class="panel-title-ico">
                    <svg viewBox="0 0 24 24"><path d="M7 2v11h3v9l7-12h-4l4-8z"/></svg>
                </div>
                Aksi Cepat
            </div>
        </div>
        <div class="qa-grid">
            <a href="pegawai.php" class="qa-item">
                <div class="qa-ico" style="background:#EFF6FF"><svg viewBox="0 0 24 24" fill="#2563EB" width="22" height="22"><path d="M15 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm-9-2V7H4v3H1v2h3v3h2v-3h3v-2H6zm9 4c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg></div>
                <span class="qa-lbl">Kelola Pegawai</span>
            </a>
            <a href="rekap.php" class="qa-item">
                <div class="qa-ico" style="background:#F0FDF4"><svg viewBox="0 0 24 24" fill="#059669" width="22" height="22"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg></div>
                <span class="qa-lbl">Rekap Absensi</span>
            </a>
            <a href="wfh.php" class="qa-item">
                <div class="qa-ico" style="background:#ECFDF5"><svg viewBox="0 0 24 24" fill="#059669" width="22" height="22"><path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z"/></svg></div>
                <span class="qa-lbl">Jadwal WFH</span>
            </a>
            <a href="wfa.php" class="qa-item">
                <div class="qa-ico" style="background:#F5F3FF"><svg viewBox="0 0 24 24" fill="#7C3AED" width="22" height="22"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z"/></svg></div>
                <span class="qa-lbl">Jadwal WFA</span>
            </a>
            <a href="cuti.php" class="qa-item">
                <div class="qa-ico" style="background:var(--warn-bg)"><svg viewBox="0 0 24 24" fill="#D97706" width="22" height="22"><path d="M17 12h-5v5h5v-5zM16 1v2H8V1H6v2H5c-1.11 0-1.99.9-1.99 2L3 19c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2h-1V1h-2zm3 18H5V8h14v11z"/></svg></div>
                <span class="qa-lbl">Kelola Cuti</span>
            </a>
            <a href="hari_libur.php" class="qa-item">
                <div class="qa-ico" style="background:#FEFCE8"><svg viewBox="0 0 24 24" fill="#CA8A04" width="22" height="22"><path d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm3.5-9l-2-3.5-1.5 3-1-1.5-2 3h9l-2.5-1z"/></svg></div>
                <span class="qa-lbl">Hari Libur</span>
            </a>
            <a href="settings.php" class="qa-item">
                <div class="qa-ico" style="background:#F5F3FF"><svg viewBox="0 0 24 24" fill="#7C3AED" width="22" height="22"><path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 9.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/></svg></div>
                <span class="qa-lbl">Pengaturan</span>
            </a>
        </div>
    </div>

    <!-- ═══ CHART + PERFORMERS ═══ -->
    <div class="main-grid fade-4" id="tour-chart">
        <div class="panel">
            <div class="panel-head">
                <div class="panel-title">
                    <div class="panel-title-ico">
                        <svg viewBox="0 0 24 24"><path d="M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zM16.2 13h2.8v6h-2.8v-6z"/></svg>
                    </div>
                    Kehadiran 7 Hari Terakhir
                </div>
            </div>
            <div class="chart-wrap">
                <canvas id="attendChart"></canvas>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div class="panel-title">
                    <div class="panel-title-ico">
                        <svg viewBox="0 0 24 24"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                    </div>
                    Top Performers
                </div>
                <span class="badge-pill"><?= $bulan_ini ?></span>
            </div>
            <div class="performers">
                <?php if (empty($tops)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24"><path d="M20 6h-2.18c.07-.44.18-.88.18-1.3 0-2.49-2.01-4.51-4.5-4.51-1.53 0-2.82.79-3.6 1.94C9.12 1.07 7.85.19 6.32.19 3.83.19 1.82 2.21 1.82 4.7c0 .42.11.86.18 1.3H0v2h20V6zM12 2.5c1.38 0 2.5 1.12 2.5 2.5 0 .44-.13.84-.33 1.19L10.83 5a2.49 2.49 0 0 1-.33-1.19c0-1.38 1.12-2.31 2.5-2.31zM3 8h18v2h-1v11H4V10H3V8zm3 3v2h2v-2H6zm0 3v2h2v-2H6zm0 3v2h2v-2H6zm3-6v2h2v-2H9zm0 3v2h2v-2H9zm0 3v2h2v-2H9zm3-6v2h2v-2h-2zm0 3v2h2v-2h-2zm3 0v2h2v-2h-2z"/></svg>
                    <p>Belum ada data bulan ini</p>
                </div>
                <?php else: ?>
                <?php $medals=[['🥇','#F59E0B'],['🥈','#94A3B8'],['🥉','#CD7F32']]; ?>
                <?php foreach($tops as $i => $p): ?>
                <div class="perf-row">
                    <div class="perf-av">
                        <?= av($p['nama'], $p['foto'], 38) ?>
                        <span class="perf-medal"><?= $medals[$i][0] ?></span>
                    </div>
                    <div style="flex:1;min-width:0">
                        <div class="perf-name"><?= htmlspecialchars($p['nama']) ?></div>
                        <div class="perf-role"><?= htmlspecialchars($p['jabatan'] ?? '-') ?></div>
                    </div>
                    <div class="perf-score">
                        <div class="perf-n"><?= $p['total'] ?></div>
                        <div class="perf-u">hari</div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ═══ AKTIVITAS TERBARU ═══ -->
    <div class="panel fade-5" id="tour-activity">
        <div class="panel-head">
            <div class="panel-title">
                <div class="panel-title-ico">
                    <svg viewBox="0 0 24 24"><path d="M13.49 5.48c1.1 0 2-.9 2-2s-.9-2-2-2-2 .9-2 2 .9 2 2 2zm-3.6 13.9l1-4.4 2.1 2v6h2v-7.5l-2.1-2 .6-3c1.3 1.5 3.3 2.5 5.5 2.5v-2c-1.9 0-3.5-1-4.3-2.4l-1-1.6c-.4-.6-1-1-1.7-1-.3 0-.5.1-.8.1l-5.2 2.2v4.7h2v-3.4l1.8-.7-1.6 8.1-4.9-1-.4 2 7 1.4z"/></svg>
                </div>
                Aktivitas Terbaru
            </div>
            <a href="rekap.php" class="panel-link">
                Lihat semua
                <svg viewBox="0 0 24 24"><path d="M8.59 16.59L13.17 12 8.59 7.41 10 6l6 6-6 6-1.41-1.41z"/></svg>
            </a>
        </div>
        <?php if (empty($recent)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24"><path d="M20 6h-2.18c.07-.44.18-.88.18-1.3C18 2.21 15.99.2 13.5.2c-1.53 0-2.82.79-3.6 1.94C9.12 1.07 7.85.19 6.32.19 3.83.19 1.82 2.21 1.82 4.7c0 .42.11.86.18 1.3H0v2h20V6z"/></svg>
            <p>Belum ada aktivitas absensi</p>
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table class="act-table">
                <thead>
                    <tr>
                        <th>Pegawai</th>
                        <th>Tanggal</th>
                        <th class="d-xs-none">Jam Masuk</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($recent as $a): ?>
                <tr>
                    <td>
                        <div class="act-user">
                            <?= av($a['nama'], $a['foto'], 32) ?>
                            <span class="act-name"><?= htmlspecialchars($a['nama']) ?></span>
                        </div>
                    </td>
                    <td class="act-date"><?= date('d M Y', strtotime($a['tanggal'])) ?></td>
                    <td class="d-xs-none">
                        <span class="act-time">
                            <svg viewBox="0 0 24 24"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm.5 5v5.25l4.5 2.67-.75 1.23L11 13V7h1.5z"/></svg>
                            <?= date('H:i', strtotime($a['jam_masuk'])) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($a['status_masuk'] === 'tepat waktu'): ?>
                        <span class="chip chip-ok">
                            <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                            Tepat Waktu
                        </span>
                        <?php else: ?>
                        <span class="chip chip-late">
                            <svg viewBox="0 0 24 24"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10 10-4.5 10-10S17.5 2 12 2zm.5 5v5.25l4.5 2.67-.75 1.23L11 13V7h1.5z"/></svg>
                            Terlambat
                        </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
/* ── Clock ── */
(function tick() {
    const n = new Date();
    document.getElementById('ch').textContent = String(n.getHours()).padStart(2,'0');
    document.getElementById('cm').textContent = String(n.getMinutes()).padStart(2,'0');
    document.getElementById('cs').textContent = String(n.getSeconds()).padStart(2,'0');
    setTimeout(tick, 1000);
})();

/* ── Chart (trigger saat scroll masuk viewport) ── */
const cd = <?= json_encode($chart_data) ?>;
let adminChartInstance = null;

function buildAdminChart() {
    if (adminChartInstance) return;
    const canvas = document.getElementById('attendChart');
    const ctx    = canvas.getContext('2d');
    const grd    = ctx.createLinearGradient(0, 0, 0, canvas.offsetHeight || 200);
    grd.addColorStop(0, 'rgba(201,168,76,.28)');
    grd.addColorStop(1, 'rgba(201,168,76,.01)');

    adminChartInstance = new Chart(ctx, {
        type: 'line',
        data: {
            labels: cd.map(d => d.day),
            datasets: [{
                data: cd.map(d => d.count),
                borderColor: '#C9A84C',
                backgroundColor: grd,
                borderWidth: 2.5, fill: true, tension: 0.42,
                pointBackgroundColor: cd.map(d => d.count > 0 ? '#C9A84C' : '#CBD5E8'),
                pointBorderColor: '#fff',
                pointBorderWidth: 2.5,
                pointRadius: 5, pointHoverRadius: 7,
                pointHoverBackgroundColor: '#E8C97A',
                spanGaps: true
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            animation: { duration: 1100, easing: 'easeInOutQuart' },
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0A1628',
                    titleColor: '#CBD5E8',
                    bodyColor: '#E8C97A',
                    borderColor: 'rgba(201,168,76,.35)',
                    borderWidth: 1, padding: 12,
                    displayColors: false,
                    titleFont: { family: 'DM Sans', size: 11 },
                    bodyFont:  { family: 'DM Sans', size: 12, weight: '600' },
                    cornerRadius: 8,
                    callbacks: {
                        title: i => cd[i[0].dataIndex].label,
                        label: c => c.parsed.y + ' pegawai hadir'
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, color: '#8A9BBE', font: { size: 10, family: 'DM Sans' } },
                    grid: { color: 'rgba(234,239,250,.7)' },
                    border: { display: false }
                },
                x: {
                    ticks: { color: '#8A9BBE', font: { size: 10, family: 'DM Sans' } },
                    grid: { display: false },
                    border: { display: false }
                }
            }
        }
    });
}

const chartObs = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting) {
        buildAdminChart();
        chartObs.disconnect();
    }
}, { threshold: 0.3 });
chartObs.observe(document.getElementById('attendChart'));

/* ── Animasi Counter & Progress Bar ── */
(function () {
    function easeOutQuart(t) { return 1 - Math.pow(1 - t, 4); }

    function animateCounter(el) {
        const target   = parseFloat(el.dataset.target);
        const isPct    = el.classList.contains('js-pct');
        if (target === 0) { el.textContent = isPct ? '0%' : '0'; return; }
        const duration = 1000;
        const start    = performance.now();
        function step(now) {
            const p = easeOutQuart(Math.min((now - start) / duration, 1));
            const v = Math.round(p * target * 10) / 10;
            el.textContent = isPct ? Math.round(p * target) + '%' : Math.round(p * target);
            if (now - start < duration) requestAnimationFrame(step);
            else el.textContent = isPct ? target + '%' : target;
        }
        requestAnimationFrame(step);
    }

    function animatePct(el) {
        const target   = parseFloat(el.dataset.target);
        const duration = 1000;
        const start    = performance.now();
        function step(now) {
            const p = easeOutQuart(Math.min((now - start) / duration, 1));
            el.textContent = Math.round(p * target);
            if (now - start < duration) requestAnimationFrame(step);
            else el.textContent = target;
        }
        requestAnimationFrame(step);
    }

    function animateProgress(fillEl) {
        const target   = parseFloat(fillEl.dataset.target);
        const duration = 1100;
        const start    = performance.now();
        function step(now) {
            const p = easeOutQuart(Math.min((now - start) / duration, 1));
            fillEl.style.width = (p * target).toFixed(1) + '%';
            if (now - start < duration) requestAnimationFrame(step);
            else fillEl.style.width = target + '%';
        }
        requestAnimationFrame(step);
    }

    function observeOnce(el, cb) {
        const obs = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting) { cb(); obs.disconnect(); }
        }, { threshold: 0.4 });
        obs.observe(el);
    }

    // Counter semua angka stat card
    document.querySelectorAll('.js-count').forEach(el => observeOnce(el, () => animateCounter(el)));

    // Persen teks dalam sub (hadir card)
    document.querySelectorAll('.js-count-pct').forEach(el => observeOnce(el, () => animatePct(el)));

    // Progress bar hadir
    const prog = document.querySelector('.js-prog');
    if (prog) observeOnce(prog, () => animateProgress(prog));
})();



/* ── Avatar fallback ── */
document.querySelectorAll('img.av-img').forEach(img => {
    img.addEventListener('error', function() {
        const ini  = this.getAttribute('data-ini') || '?';
        const size = this.style.width || '36px';
        const fs   = parseFloat(size) * 0.38 + 'px';
        const div  = document.createElement('div');
        div.textContent = ini;
        div.style.cssText = `width:${size};height:${size};border-radius:50%;flex-shrink:0;background:linear-gradient(135deg,#C9A84C,#E8C97A);color:#5C3D00;font-weight:800;font-size:${fs};display:flex;align-items:center;justify-content:center;`;
        this.parentNode.replaceChild(div, this);
    });
});

/* ── Tour ── */
const TOUR_KEY = 'bbws_admin_tour_v3';
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
            showProgress: true,
            progressText: '{{current}} / {{total}}',
            nextBtnText: 'Berikutnya →',
            prevBtnText: '← Kembali',
            doneBtnText: '✓ Selesai',
            allowClose: true,
            smoothScroll: true,
            animate: true,
            popoverClass: 'driver-popover',
            overlayColor: 'rgba(10,22,40,.8)',
            onDestroyStarted: () => { localStorage.setItem(TOUR_KEY,'1'); drv.destroy(); },
            steps: [
                { popover:{ title:'👋 Selamat Datang, Admin!', description:'Panduan singkat dashboard sistem absensi BBWS Citanduy.', side:'over', align:'center' } },
                { element:'#tour-header',     popover:{ title:'📅 Panel Identitas', description:'Nama admin yang login, tanggal hari ini, dan jam realtime.',     side:'bottom', align:'start' } },
                { element:'#tour-stats',      popover:{ title:'📊 Statistik Harian', description:'Ringkasan: Total Pegawai, Hadir, Terlambat, Alpa, dan Tingkat Kehadiran.',                 side:'bottom', align:'center' } },
                { element:'#tour-quickactions', popover:{ title:'⚡ Aksi Cepat', description:'Shortcut ke fitur utama: pegawai, rekap, WFH, WFA, cuti, libur, pengaturan.',             side:'bottom', align:'center' } },
                { element:'#tour-chart',      popover:{ title:'📈 Grafik & Top Performers', description:'Tren kehadiran 7 hari terakhir dan pegawai terbaik bulan ini.',  side:'top',    align:'center' } },
                { element:'#tour-activity',   popover:{ title:'🕐 Aktivitas Terbaru', description:'Log absensi pegawai terkini. Klik "Lihat semua" untuk rekap lengkap.',  side:'top',    align:'start' } },
                { element:'#btnTourAdmin',    popover:{ title:'❓ Buka Panduan', description:'Klik tombol ini kapan saja untuk mengulang panduan ini.',         side:'bottom', align:'end' } },
                { popover:{ title:'🎉 Siap Digunakan!', description:'Dashboard siap. Semua fitur dapat diakses dari sini.', side:'over', align:'center' } }
            ]
        });
        drv.drive();
    });
}

if (!localStorage.getItem(TOUR_KEY)) {
    window.addEventListener('load', () => setTimeout(mulaiTour, 800));
}
</script>

<?php include '../templates/footer.php'; ?>