<?php
require_once __DIR__ . '/../config/init.php';
require_login();
if (!is_admin()) { header('Location: dashboard.php'); exit(); }

$today = date('Y-m-d');

$stmt = $pdo->query("SELECT w.*, u.nama AS nama_pegawai, u.unit_kerja AS unit_pegawai FROM wfh_schedule w LEFT JOIN users u ON w.user_id = u.id ORDER BY w.tanggal_mulai DESC");
$jadwal_list = $stmt->fetchAll();

$stmt = $pdo->query("SELECT id, nama, unit_kerja, lat_rumah, lng_rumah, foto FROM users WHERE role = 'pegawai' AND status = 'aktif' ORDER BY nama");
$pegawai_koordinat = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM wfh_schedule WHERE tanggal_mulai <= ? AND tanggal_selesai >= ? AND berlaku_untuk = 'semua'");
$stmt->execute([$today, $today]);
$wfh_aktif_semua = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM wfh_schedule WHERE tanggal_selesai >= ?");
$stmt->execute([$today]);
$total_aktif = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM wfh_schedule WHERE tanggal_selesai < ?");
$stmt->execute([$today]);
$total_selesai = $stmt->fetchColumn();

$pegawai_dengan_koordinat = 0;
$pegawai_tanpa_koordinat  = 0;
foreach ($pegawai_koordinat as $p) {
    if ($p['lat_rumah'] && $p['lng_rumah']) $pegawai_dengan_koordinat++;
    else $pegawai_tanpa_koordinat++;
}

$bulan = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
function fmt_tgl($tgl, $bulan) {
    $dt = new DateTime($tgl);
    return $dt->format('d') . ' ' . $bulan[(int)$dt->format('n')] . ' ' . $dt->format('Y');
}

$pegawai_peta_json = json_encode(array_values(array_filter(
    $pegawai_koordinat, fn($p) => $p['lat_rumah'] && $p['lng_rumah']
)));
?>
<?php include '../templates/header.php'; ?>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">

<style>
/* ══════════════════════════════════════════
   DESIGN TOKENS
══════════════════════════════════════════ */
:root {
    --navy:       #0A1628;
    --navy-mid:   #0F2241;
    --navy-lit:   #1A3560;
    --navy-soft:  #243970;
    --gold:       #C9A84C;
    --gold-lit:   #E8C97A;
    --gold-pale:  #F5E9C8;
    --gold-bg:    #FEFAEF;
    --silver:     #8A9BBE;
    --ash:        #CBD5E8;
    --white:      #FFFFFF;
    --surface:    #F8FAFF;
    --surface-2:  #F0F4FC;
    --txt:        #0A1628;
    --txt-2:      #334155;
    --muted:      #64748B;
    --border:     #E2E8F4;
    --ok:         #059669;
    --ok-bg:      #ECFDF5;
    --ok-border:  #A7F3D0;
    --err:        #DC2626;
    --err-bg:     #FEF2F2;
    --err-border: #FECACA;
    --warn:       #D97706;
    --warn-bg:    #FFFBEB;
    --warn-border:#FDE68A;
    --info:       #2563EB;
    --info-bg:    #EFF6FF;
    --info-border:#BFDBFE;
    --r-sm:       8px;
    --r-md:       12px;
    --r-lg:       18px;
    --r-xl:       24px;
    --shadow-sm:  0 1px 3px rgba(10,22,40,.06), 0 1px 2px rgba(10,22,40,.04);
    --shadow-md:  0 4px 16px rgba(10,22,40,.08), 0 1px 4px rgba(10,22,40,.05);
    --shadow-lg:  0 12px 40px rgba(10,22,40,.12), 0 4px 12px rgba(10,22,40,.06);
    --shadow-gold:0 8px 32px rgba(201,168,76,.18);
}

*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'DM Sans', sans-serif; background: var(--surface); color: var(--txt); }
.main-content { padding-top: 0; }

/* ── Page header ── */
.pg-header {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 55%, var(--navy-lit) 100%);
    padding: 2rem 2rem 3.5rem;
    position: relative;
    overflow: hidden;
}
.pg-header::before {
    content: '';
    position: absolute; top: -60px; right: -60px;
    width: 260px; height: 260px; border-radius: 50%;
    border: 1px solid rgba(201,168,76,.12);
    pointer-events: none;
}
.pg-header::after {
    content: '';
    position: absolute; bottom: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, transparent, var(--gold) 40%, var(--gold-lit) 60%, transparent);
}
.pg-header-dots {
    position: absolute; top: 20px; right: 80px;
    width: 90px; height: 90px;
    background-image: radial-gradient(circle, rgba(201,168,76,.4) 1.5px, transparent 1.5px);
    background-size: 16px 16px;
    opacity: .3; pointer-events: none;
}
.pg-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: clamp(1.5rem, 4vw, 2rem);
    font-weight: 700; color: var(--white);
    line-height: 1.2; margin-bottom: .3rem;
}
.pg-sub { font-size: .82rem; color: var(--silver); letter-spacing: .04em; }
.pg-eyebrow {
    font-size: .68rem; font-weight: 600;
    letter-spacing: .18em; text-transform: uppercase;
    color: var(--gold); margin-bottom: .5rem;
}

/* ── Content lift ── */
.content-lift {
    margin-top: -2rem;
    padding: 0 1.5rem 2.5rem;
    position: relative; z-index: 2;
}
@media (max-width: 575px) { .content-lift { padding: 0 1rem 2rem; } }

/* ── WFH Active Banner ── */
.wfh-banner {
    background: linear-gradient(135deg, #065F46 0%, #047857 100%);
    border-radius: var(--r-lg);
    padding: 1rem 1.25rem;
    display: flex; align-items: center; gap: 1rem;
    box-shadow: 0 8px 24px rgba(5,150,105,.2);
    margin-bottom: 1.5rem;
    animation: slideDown .5s cubic-bezier(.22,1,.36,1) both;
}
.banner-ico {
    width: 44px; height: 44px;
    background: rgba(255,255,255,.15);
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.banner-ico svg { width: 22px; height: 22px; fill: #fff; }
.banner-text strong { display: block; color: #fff; font-size: .9rem; font-weight: 600; }
.banner-text span { color: rgba(255,255,255,.72); font-size: .78rem; }
.live-pill {
    margin-left: auto;
    display: flex; align-items: center; gap: 6px;
    background: rgba(255,255,255,.15);
    border-radius: 99px; padding: 4px 10px;
    font-size: .7rem; font-weight: 600; color: #fff;
    flex-shrink: 0; white-space: nowrap;
}
.live-dot {
    width: 7px; height: 7px; border-radius: 50%;
    background: #6EE7B7;
    box-shadow: 0 0 0 3px rgba(110,231,183,.3);
    animation: pulseDot 2s infinite;
}
@keyframes pulseDot {
    0%,100%{ box-shadow:0 0 0 3px rgba(110,231,183,.3); }
    50%     { box-shadow:0 0 0 7px rgba(110,231,183,.05); }
}

/* ── Stats Grid ── */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}
@media (max-width: 767px) { .stats-grid { grid-template-columns: repeat(2, 1fr); gap: .75rem; } }

.stat-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    padding: 1.1rem 1.1rem 1rem;
    box-shadow: var(--shadow-sm);
    position: relative; overflow: hidden;
    transition: transform .2s, box-shadow .2s;
    animation: fadeUp .5s ease both;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-md); }
.stat-card::after {
    content: '';
    position: absolute; top: 0; left: 0; right: 0;
    height: 3px; border-radius: var(--r-lg) var(--r-lg) 0 0;
}
.stat-card.c-gold::after  { background: linear-gradient(90deg, var(--gold), var(--gold-lit)); }
.stat-card.c-blue::after  { background: linear-gradient(90deg, #2563EB, #60A5FA); }
.stat-card.c-green::after { background: linear-gradient(90deg, var(--ok), #34D399); }
.stat-card.c-gray::after  { background: linear-gradient(90deg, #64748B, #94A3B8); }

.stat-ico-wrap {
    width: 38px; height: 38px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: .75rem;
}
.stat-ico-wrap svg { width: 18px; height: 18px; }
.stat-card.c-gold  .stat-ico-wrap { background: var(--gold-bg); }
.stat-card.c-gold  .stat-ico-wrap svg { fill: var(--gold); }
.stat-card.c-blue  .stat-ico-wrap { background: var(--info-bg); }
.stat-card.c-blue  .stat-ico-wrap svg { fill: var(--info); }
.stat-card.c-green .stat-ico-wrap { background: var(--ok-bg); }
.stat-card.c-green .stat-ico-wrap svg { fill: var(--ok); }
.stat-card.c-gray  .stat-ico-wrap { background: #F1F5F9; }
.stat-card.c-gray  .stat-ico-wrap svg { fill: #64748B; }

.stat-val {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.75rem; font-weight: 700;
    color: var(--navy); line-height: 1;
    margin-bottom: .2rem;
}
.stat-lbl { font-size: .74rem; color: var(--muted); font-weight: 500; }

/* ── Tabs ── */
.tab-nav {
    display: flex; gap: 0;
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    padding: .3rem;
    margin-bottom: 1.5rem;
    box-shadow: var(--shadow-sm);
}
.tab-btn {
    flex: 1; display: flex; align-items: center; justify-content: center;
    gap: .5rem; padding: .65rem 1rem;
    border: none; background: none; cursor: pointer;
    border-radius: var(--r-md);
    font-family: 'DM Sans', sans-serif;
    font-size: .82rem; font-weight: 500;
    color: var(--muted);
    transition: all .22s ease;
}
.tab-btn svg { width: 16px; height: 16px; flex-shrink: 0; }
.tab-btn.active {
    background: linear-gradient(135deg, var(--navy), var(--navy-lit));
    color: var(--white);
    box-shadow: 0 4px 12px rgba(10,22,40,.22);
}
.tab-btn.active svg { fill: var(--white); opacity: .9; }
.tab-btn:not(.active):hover { background: var(--surface-2); color: var(--navy); }
.tab-badge {
    font-size: .65rem; font-weight: 700;
    padding: 2px 7px; border-radius: 99px;
    background: var(--err-bg); color: var(--err);
}
.tab-btn.active .tab-badge { background: rgba(255,255,255,.2); color: var(--white); }

/* ── Cards ── */
.wfh-card {
    background: var(--white);
    border: 1px solid var(--border);
    border-radius: var(--r-xl);
    box-shadow: var(--shadow-sm);
    overflow: hidden;
    animation: fadeUp .4s ease both;
}
.card-header-bar {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1.1rem 1.4rem;
    border-bottom: 1px solid var(--border);
    background: linear-gradient(90deg, #FAFCFF, var(--white));
}
.card-title {
    display: flex; align-items: center; gap: .55rem;
    font-size: .84rem; font-weight: 600; color: var(--navy);
}
.card-title svg { width: 15px; height: 15px; fill: var(--gold); }
.count-chip {
    font-size: .7rem; font-weight: 600;
    background: var(--surface-2);
    color: var(--muted);
    padding: 3px 10px; border-radius: 99px;
}

/* ── Table ── */
.wfh-table { width: 100%; border-collapse: collapse; }
.wfh-table thead th {
    font-size: .68rem; font-weight: 600;
    text-transform: uppercase; letter-spacing: .08em;
    color: var(--silver); padding: .7rem 1.1rem;
    background: var(--surface); border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
.wfh-table tbody td {
    padding: .85rem 1.1rem; vertical-align: middle;
    border-bottom: 1px solid #F8FAFF;
    font-size: .84rem;
}
.wfh-table tbody tr:last-child td { border-bottom: none; }
.wfh-table tbody tr { transition: background .12s; }
.wfh-table tbody tr:hover { background: var(--surface); }
.wfh-table tbody tr.row-warn { background: #FFFBEB; }
.wfh-table tbody tr.row-warn:hover { background: #FEF9E3; }

/* ── Pegawai cell ── */
.pgw-cell { display: flex; align-items: center; gap: .7rem; }
.avatar {
    width: 36px; height: 36px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: .8rem; font-weight: 700; flex-shrink: 0;
    border: 2px solid transparent;
}
.avatar.av-navy { background: linear-gradient(135deg, var(--navy), var(--navy-lit)); color: var(--white); border-color: rgba(201,168,76,.3); }
.avatar.av-gold { background: var(--gold-bg); color: var(--gold); border-color: rgba(201,168,76,.25); }
.avatar.av-blue { background: var(--info-bg); color: var(--info); border-color: rgba(37,99,235,.2); }
.pgw-name { font-size: .84rem; font-weight: 600; color: var(--navy); line-height: 1.3; }
.pgw-unit { font-size: .72rem; color: var(--muted); margin-top: 1px; }

/* ── Status badges ── */
.status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: .7rem; font-weight: 600;
    padding: 4px 10px; border-radius: 99px;
    white-space: nowrap;
}
.status-badge .bd { width: 6px; height: 6px; border-radius: 50%; }
.sb-green { background: var(--ok-bg);   color: var(--ok);   border: 1px solid var(--ok-border); }
.sb-green .bd { background: var(--ok); }
.sb-blue  { background: var(--info-bg); color: var(--info); border: 1px solid var(--info-border); }
.sb-blue  .bd { background: var(--info); }
.sb-gray  { background: #F1F5F9; color: #64748B; border: 1px solid #CBD5E8; }
.sb-gray  .bd { background: #94A3B8; }

/* ── Period display ── */
.period-main { font-size: .83rem; font-weight: 600; color: var(--navy); }
.period-sub  { font-size: .72rem; color: var(--muted); margin-top: 1px; }

/* ── Action buttons ── */
.action-btn {
    width: 32px; height: 32px;
    border: 1px solid var(--border);
    border-radius: var(--r-sm);
    background: var(--white);
    color: var(--muted);
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: .8rem;
    transition: all .15s ease;
    text-decoration: none;
}
.action-btn svg { width: 14px; height: 14px; }
.action-btn:hover { background: var(--surface-2); color: var(--navy); border-color: var(--ash); }
.action-btn.danger:hover { background: var(--err-bg); color: var(--err); border-color: var(--err-border); }
.action-btn:disabled { opacity: .4; cursor: not-allowed; pointer-events: none; }

/* ── Empty state ── */
.empty-state { text-align: center; padding: 3.5rem 2rem; }
.empty-ico {
    width: 64px; height: 64px; border-radius: 50%;
    background: var(--surface-2);
    display: flex; align-items: center; justify-content: center;
    margin: 0 auto 1rem;
}
.empty-ico svg { width: 28px; height: 28px; fill: var(--ash); }
.empty-state p { font-size: .9rem; color: var(--muted); margin: 0 0 .3rem; }
.empty-state small { font-size: .78rem; color: var(--ash); }

/* ── Buttons ── */
.btn-primary-navy {
    display: inline-flex; align-items: center; gap: .45rem;
    padding: .6rem 1.2rem;
    background: linear-gradient(135deg, var(--navy), var(--navy-lit));
    color: var(--white); border: none; border-radius: var(--r-md);
    font-family: 'DM Sans', sans-serif;
    font-size: .84rem; font-weight: 600;
    cursor: pointer; text-decoration: none;
    box-shadow: 0 4px 12px rgba(10,22,40,.2);
    transition: all .2s ease;
    white-space: nowrap;
}
.btn-primary-navy svg { width: 15px; height: 15px; fill: var(--white); }
.btn-primary-navy:hover { background: linear-gradient(135deg, var(--navy-mid), var(--navy-soft)); transform: translateY(-1px); color: var(--white); }

.btn-gold-outline {
    display: inline-flex; align-items: center; gap: .45rem;
    padding: .6rem 1.2rem;
    background: var(--gold-bg);
    color: var(--navy); border: 1px solid rgba(201,168,76,.35); border-radius: var(--r-md);
    font-family: 'DM Sans', sans-serif;
    font-size: .84rem; font-weight: 600;
    cursor: pointer; text-decoration: none;
    transition: all .2s ease;
    white-space: nowrap;
}
.btn-gold-outline svg { width: 15px; height: 15px; fill: var(--gold); }
.btn-gold-outline:hover { background: var(--gold-pale); border-color: var(--gold); color: var(--navy); }

/* ── Search / Filter ── */
.filter-bar {
    display: flex; gap: .75rem; flex-wrap: wrap;
    padding: 1rem 1.4rem;
    border-bottom: 1px solid var(--border);
    background: var(--surface);
}
.search-wrap { position: relative; flex: 1; min-width: 180px; }
.search-wrap svg {
    position: absolute; left: .75rem; top: 50%; transform: translateY(-50%);
    width: 15px; height: 15px; fill: var(--silver); pointer-events: none;
}
.search-input {
    width: 100%; padding: .55rem .75rem .55rem 2.2rem;
    border: 1px solid var(--border); border-radius: var(--r-md);
    font-family: 'DM Sans', sans-serif; font-size: .84rem; color: var(--txt);
    background: var(--white); outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.search-input:focus { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(201,168,76,.1); }
.filter-select {
    padding: .55rem .9rem;
    border: 1px solid var(--border); border-radius: var(--r-md);
    font-family: 'DM Sans', sans-serif; font-size: .84rem; color: var(--txt);
    background: var(--white); outline: none; cursor: pointer;
}
.filter-select:focus { border-color: var(--gold); }

/* ── Coord value ── */
.coord-val { font-size: .78rem; font-weight: 600; font-variant-numeric: tabular-nums; }
.coord-val.has { color: var(--ok); }
.coord-val.empty { color: var(--ash); }

/* ── Modal ── */
.modal-content { border-radius: var(--r-xl) !important; border: none !important; box-shadow: var(--shadow-lg) !important; }
.modal-header-custom { padding: 1.5rem 1.5rem .5rem; border: none; }
.modal-icon {
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg, var(--navy), var(--navy-lit));
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 1rem;
}
.modal-icon svg { width: 22px; height: 22px; fill: var(--white); }
.modal-title-text {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.35rem; font-weight: 700; color: var(--navy);
}
.modal-sub-text { font-size: .78rem; color: var(--muted); margin-top: .2rem; }
.modal-divider { height: 1px; background: var(--border); margin: 0 1.5rem; }
.modal-body-custom { padding: 1.25rem 1.5rem; }
.modal-footer-custom {
    padding: 1rem 1.5rem 1.5rem;
    border: none; display: flex; gap: .6rem; justify-content: flex-end;
}

/* ── Form fields ── */
.field-label {
    display: block; font-size: .78rem; font-weight: 600;
    color: var(--navy); margin-bottom: .4rem; letter-spacing: .02em;
}
.field-input {
    width: 100%; padding: .65rem .85rem;
    border: 1.5px solid var(--border); border-radius: var(--r-md);
    font-family: 'DM Sans', sans-serif; font-size: .87rem; color: var(--txt);
    background: var(--surface); outline: none;
    transition: border-color .2s, background .2s, box-shadow .2s;
}
.field-input:focus { border-color: var(--gold); background: var(--white); box-shadow: 0 0 0 3px rgba(201,168,76,.1); }

/* ── Toggle group ── */
.toggle-group { display: flex; gap: .5rem; flex-wrap: wrap; }
.toggle-opt { position: relative; }
.toggle-opt input { display: none; }
.toggle-opt label {
    display: flex; align-items: center; gap: .4rem;
    padding: .5rem 1rem; border: 1.5px solid var(--border);
    border-radius: var(--r-md); font-size: .82rem; font-weight: 500;
    color: var(--muted); background: var(--surface);
    cursor: pointer; user-select: none;
    transition: all .15s;
}
.toggle-opt label svg { width: 14px; height: 14px; fill: var(--muted); }
.toggle-opt input:checked + label {
    border-color: var(--navy); background: var(--navy); color: var(--white);
}
.toggle-opt input:checked + label svg { fill: var(--white); }

/* ── Info note ── */
.info-note {
    display: flex; align-items: flex-start; gap: .6rem;
    background: var(--info-bg); border: 1px solid var(--info-border);
    border-radius: var(--r-md); padding: .75rem 1rem;
    font-size: .78rem; color: #1E40AF; line-height: 1.55;
}
.info-note svg { width: 15px; height: 15px; fill: var(--info); flex-shrink: 0; margin-top: .1rem; }

/* ── Multi-select pegawai ── */
.pgw-search-wrap {
    position: relative; margin-bottom: .5rem;
}
.pgw-search-wrap svg {
    position: absolute; left: .7rem; top: 50%; transform: translateY(-50%);
    width: 14px; height: 14px; fill: var(--silver); pointer-events: none;
}
.pgw-search-input {
    width: 100%; padding: .55rem .75rem .55rem 2rem;
    border: 1.5px solid var(--border); border-radius: var(--r-md);
    font-family: 'DM Sans', sans-serif; font-size: .83rem; color: var(--txt);
    background: var(--white); outline: none;
    transition: border-color .2s, box-shadow .2s;
}
.pgw-search-input:focus { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(201,168,76,.1); }
.pgw-pills-wrap {
    display: flex; flex-wrap: wrap; gap: 5px;
    margin-bottom: .5rem; min-height: 4px;
}
.selected-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 8px 3px 10px;
    background: var(--navy); color: var(--white);
    border-radius: 99px; font-size: .72rem; font-weight: 600;
    animation: fadeUp .15s ease;
}
.selected-pill .pill-x {
    background: none; border: none; color: rgba(255,255,255,.65);
    cursor: pointer; padding: 0; line-height: 1; font-size: 1rem;
    display: flex; align-items: center;
    transition: color .1s;
}
.selected-pill .pill-x:hover { color: #fff; }
.pgw-list {
    max-height: 200px; overflow-y: auto;
    border: 1.5px solid var(--border); border-radius: var(--r-md);
    background: var(--white);
}
.pgw-check-item {
    display: flex; align-items: center; gap: .65rem;
    padding: .6rem .85rem;
    border-bottom: 1px solid var(--border);
    cursor: pointer;
    transition: background .1s;
}
.pgw-check-item:last-child { border-bottom: none; }
.pgw-check-item:hover { background: var(--surface-2); }
.pgw-check-item input[type=checkbox] {
    width: 16px; height: 16px; flex-shrink: 0;
    accent-color: var(--navy); cursor: pointer;
}
.pgw-check-item.is-checked { background: var(--gold-bg); }
.pgw-check-item.is-checked:hover { background: var(--gold-pale); }
.pgw-item-initial {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    background: var(--gold-bg); color: var(--gold);
    border: 1px solid rgba(201,168,76,.3);
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; font-weight: 700;
}
.pgw-item-name { font-size: .83rem; font-weight: 600; color: var(--navy); display: block; }
.pgw-item-unit { font-size: .71rem; color: var(--muted); display: block; margin-top: 1px; }
.pgw-no-result {
    padding: 1.5rem; text-align: center;
    color: var(--muted); font-size: .8rem;
}
.pgw-count-info { font-size: .72rem; color: var(--muted); margin-top: .4rem; text-align: right; }
.pgw-count-info strong { color: var(--navy); }
.pgw-select-all-row {
    display: flex; align-items: center; gap: .5rem;
    padding: .5rem .85rem;
    border-bottom: 2px solid var(--border);
    background: var(--surface);
}
.pgw-select-all-row label {
    font-size: .78rem; font-weight: 600; color: var(--navy); cursor: pointer;
}
.pgw-select-all-row input[type=checkbox] {
    width: 15px; height: 15px; accent-color: var(--navy); cursor: pointer;
}

/* ── Map section ── */
#mapKoordinat {
    height: 440px; border-radius: var(--r-lg);
    border: 1px solid var(--border);
}
@media (max-width: 575px) { #mapKoordinat { height: 300px; } }
.map-note { display: flex; align-items: center; gap: .4rem; font-size: .74rem; color: var(--muted); margin-top: .75rem; }
.map-note svg { width: 13px; height: 13px; fill: var(--silver); flex-shrink: 0; }

/* ── Page alert ── */
.page-alert {
    display: flex; align-items: center; gap: .75rem;
    padding: .85rem 1.1rem; border-radius: var(--r-md);
    font-size: .83rem; margin-bottom: 1.25rem;
    animation: slideDown .4s ease;
}
.page-alert.success { background: var(--ok-bg); color: #065F46; border: 1px solid var(--ok-border); border-left: 3px solid var(--ok); }
.page-alert.danger  { background: var(--err-bg); color: #991B1B; border: 1px solid var(--err-border); border-left: 3px solid var(--err); }
.page-alert svg { width: 16px; height: 16px; flex-shrink: 0; fill: currentColor; }
.alert-close { margin-left: auto; background: none; border: none; cursor: pointer; color: currentColor; opacity: .6; padding: 0; line-height: 1; }

/* ── Leaflet custom ── */
.marker-wrap { display: flex; flex-direction: column; align-items: center; }
.marker-pin {
    width: 38px; height: 38px; border-radius: 50%;
    border: 2.5px solid var(--gold); background: var(--white);
    display: flex; align-items: center; justify-content: center;
    box-shadow: 0 3px 8px rgba(0,0,0,.2); overflow: hidden;
}
.marker-pin img { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
.marker-lbl {
    margin-top: 3px; background: rgba(10,22,40,.85); color: #fff;
    padding: 2px 8px; border-radius: 10px; font-size: 9px;
    white-space: nowrap; max-width: 90px;
    overflow: hidden; text-overflow: ellipsis;
    font-family: 'DM Sans', sans-serif;
}

/* ── Animations ── */
@keyframes fadeUp   { from{opacity:0;transform:translateY(14px)} to{opacity:1;transform:translateY(0)} }
@keyframes slideDown{ from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }
.animate-1 { animation-delay: .05s; }
.animate-2 { animation-delay: .1s; }
.animate-3 { animation-delay: .15s; }
.animate-4 { animation-delay: .2s; }

/* ── Tour button ── */
.tour-btn-hd {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .55rem 1rem;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(201,168,76,.25);
    border-radius: var(--r-md); color: var(--silver);
    font-family: 'DM Sans', sans-serif; font-size: .76rem; font-weight: 500;
    cursor: pointer; transition: all .2s; white-space: nowrap;
}
.tour-btn-hd svg { width: 15px; height: 15px; fill: currentColor; }
.tour-btn-hd:hover { background: rgba(201,168,76,.12); border-color: var(--gold); color: var(--gold-lit); }

/* Driver.js overrides */
.driver-popover {
    font-family: 'DM Sans', sans-serif !important;
    border-radius: 18px !important;
    border: 1px solid rgba(201,168,76,.2) !important;
    box-shadow: 0 20px 60px rgba(0,0,0,.2) !important;
    padding: 1.25rem 1.35rem !important;
    max-width: 300px !important;
}
.driver-popover-title {
    font-family: 'Cormorant Garamond', serif !important;
    font-size: 1.05rem !important; font-weight: 700 !important;
    color: #0A1628 !important;
    padding-bottom: .5rem !important;
    border-bottom: 2px solid #C9A84C !important;
    margin-bottom: .65rem !important;
}
.driver-popover-description { font-size: .78rem !important; color: #334155 !important; line-height: 1.65 !important; }
.driver-popover-navigation-btns { display:flex !important; align-items:center !important; gap:.35rem !important; margin-top:.85rem !important; }
.driver-popover-next-btn, .driver-popover-done-btn {
    background: linear-gradient(135deg,#0F2241,#1A3560) !important; border:none !important;
    color:#fff !important; border-radius:8px !important;
    padding:.38rem .9rem !important; font-size:.74rem !important; font-weight:700 !important;
    cursor:pointer !important;
}
.driver-popover-prev-btn {
    background:#F0F4FC !important; border:1px solid #E2E8F4 !important;
    color:#64748B !important; border-radius:8px !important;
    padding:.38rem .9rem !important; font-size:.74rem !important; font-weight:500 !important;
    cursor:pointer !important;
}

/* Responsive */
@media (max-width: 767px) {
    .pg-header { padding: 1.5rem 1rem 3rem; }
    .content-lift { padding: 0 .75rem 2rem; }
    .tab-btn { font-size: .75rem; padding: .55rem .5rem; }
    .card-header-bar { padding: .85rem 1rem; }
    .wfh-table thead th { padding: .6rem .75rem; }
    .wfh-table tbody td { padding: .7rem .75rem; }
    .filter-bar { padding: .75rem 1rem; gap: .5rem; }
    .modal-body-custom, .modal-footer-custom, .modal-header-custom { padding-left: 1.1rem; padding-right: 1.1rem; }
    .modal-divider { margin: 0 1.1rem; }
}
@media (max-width: 480px) {
    .live-pill { display: none; }
    .wfh-banner { flex-wrap: wrap; gap: .6rem; }
}
</style>

<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<div class="main-content">

    <!-- Page Header -->
    <div class="pg-header" id="tour-header">
        <div class="pg-header-dots"></div>
        <div class="d-flex align-items-start justify-content-between gap-3">
            <div>
                <div class="pg-eyebrow">Manajemen SDM</div>
                <h1 class="pg-title">Kelola WFH</h1>
                <p class="pg-sub">Jadwal kerja dari rumah &amp; koordinat lokasi pegawai</p>
            </div>
            <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                <button class="tour-btn-hd" id="btnTour" onclick="mulaiTour()">
                    <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 17h-2v-2h2v2zm2.07-7.75l-.9.92C13.45 12.9 13 13.5 13 15h-2v-.5c0-1.1.45-2.1 1.17-2.83l1.24-1.26c.37-.36.59-.86.59-1.41 0-1.1-.9-2-2-2s-2 .9-2 2H8c0-2.21 1.79-4 4-4s4 1.79 4 4c0 .88-.36 1.68-.93 2.25z"/></svg>
                    Panduan
                </button>
                <button class="btn-primary-navy" data-bs-toggle="modal" data-bs-target="#modalWFH" onclick="resetModal()">
                    <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                    Tambah Jadwal
                </button>
            </div>
        </div>
    </div>

    <!-- Content -->
    <div class="content-lift">

        <!-- Alert -->
        <?php if (isset($_SESSION['alert'])): ?>
        <div class="page-alert <?= $_SESSION['alert']['type'] === 'success' ? 'success' : 'danger' ?>">
            <svg viewBox="0 0 24 24"><?= $_SESSION['alert']['type'] === 'success' ? '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11"/>' : '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>' ?></svg>
            <span><?= htmlspecialchars($_SESSION['alert']['message']) ?></span>
            <button class="alert-close" onclick="this.parentElement.remove()">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </button>
        </div>
        <?php unset($_SESSION['alert']); endif; ?>

        <!-- WFH Active Banner -->
        <?php if ($wfh_aktif_semua): ?>
        <div class="wfh-banner" id="tour-banner">
            <div class="banner-ico">
                <svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>
            </div>
            <div class="banner-text">
                <strong>WFH Berlaku Hari Ini</strong>
                <span>Semua pegawai dapat absen dari lokasi rumah masing-masing</span>
            </div>
            <div class="live-pill">
                <div class="live-dot"></div>
                Aktif
            </div>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tab-nav" id="tour-tabs" role="tablist">
            <button class="tab-btn active" id="tab-jadwal" onclick="switchTab('jadwal')" role="tab">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 4h-1V2h-2v2H8V2H6v2H5C3.9 4 3 4.9 3 6v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zM7 11h5v5H7z"/></svg>
                Jadwal WFH
            </button>
            <button class="tab-btn" id="tab-koordinat" onclick="switchTab('koordinat')" role="tab">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z"/></svg>
                Koordinat Rumah
                <?php if ($pegawai_tanpa_koordinat > 0): ?>
                <span class="tab-badge"><?= $pegawai_tanpa_koordinat ?></span>
                <?php endif; ?>
            </button>
        </div>

        <!-- ════ TAB: JADWAL WFH ════ -->
        <div id="panel-jadwal">

            <!-- Stats -->
            <div class="stats-grid mb-4" id="tour-stats">
                <div class="stat-card c-blue animate-1">
                    <div class="stat-ico-wrap">
                        <svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5C3.9 4 3 4.9 3 6v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zM7 11h5v5H7z"/></svg>
                    </div>
                    <div class="stat-val"><?= $total_aktif ?></div>
                    <div class="stat-lbl">Jadwal Aktif</div>
                </div>
                <div class="stat-card c-gray animate-2">
                    <div class="stat-ico-wrap">
                        <svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5C3.9 4 3 4.9 3 6v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z" opacity=".5"/></svg>
                    </div>
                    <div class="stat-val"><?= $total_selesai ?></div>
                    <div class="stat-lbl">Sudah Selesai</div>
                </div>
                <div class="stat-card c-green animate-3">
                    <div class="stat-ico-wrap">
                        <svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/></svg>
                    </div>
                    <div class="stat-val" style="font-size:1.25rem"><?= $wfh_aktif_semua ? 'Aktif' : 'Tidak' ?></div>
                    <div class="stat-lbl">WFH Hari Ini</div>
                </div>
                <div class="stat-card c-gold animate-4">
                    <div class="stat-ico-wrap">
                        <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                    </div>
                    <div class="stat-val"><?= count($pegawai_koordinat) ?></div>
                    <div class="stat-lbl">Total Pegawai</div>
                </div>
            </div>

            <!-- Table -->
            <div class="wfh-card" id="tour-table">
                <div class="card-header-bar">
                    <div class="card-title">
                        <svg viewBox="0 0 24 24"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>
                        Daftar Jadwal WFH
                    </div>
                    <span class="count-chip"><?= count($jadwal_list) ?> jadwal</span>
                </div>

                <?php if (empty($jadwal_list)): ?>
                <div class="empty-state">
                    <div class="empty-ico">
                        <svg viewBox="0 0 24 24"><path d="M19 4h-1V2h-2v2H8V2H6v2H5C3.9 4 3 4.9 3 6v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zm-7-6h-2v-2h2v2zm0-4h-2V8h2v2z"/></svg>
                    </div>
                    <p>Belum ada jadwal WFH</p>
                    <small>Klik "Tambah Jadwal" untuk mulai</small>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="wfh-table">
                        <thead>
                            <tr>
                                <th style="width:40px">#</th>
                                <th>Pegawai</th>
                                <th>Periode</th>
                                <th class="d-none d-lg-table-cell">Keterangan</th>
                                <th>Status</th>
                                <th class="text-center" style="width:90px">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($jadwal_list as $i => $j):
                            $todayDt = new DateTime($today);
                            $mulai   = new DateTime($j['tanggal_mulai']);
                            $selesai = new DateTime($j['tanggal_selesai']);
                            if ($todayDt >= $mulai && $todayDt <= $selesai)
                                [$slbl, $scls] = ['Berlangsung','sb-green'];
                            elseif ($todayDt < $mulai)
                                [$slbl, $scls] = ['Akan Datang','sb-blue'];
                            else
                                [$slbl, $scls] = ['Selesai','sb-gray'];
                            $is_semua = $j['berlaku_untuk'] === 'semua';
                        ?>
                        <tr>
                            <td style="color:var(--silver);font-size:.78rem"><?= $i+1 ?></td>
                            <td>
                                <div class="pgw-cell">
                                    <?php if ($is_semua): ?>
                                    <div class="avatar av-blue">
                                        <svg viewBox="0 0 24 24" width="16" height="16" fill="#2563EB"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                                    </div>
                                    <div>
                                        <div class="pgw-name">Semua Pegawai</div>
                                        <div class="pgw-unit">Berlaku untuk semua</div>
                                    </div>
                                    <?php else: ?>
                                    <div class="avatar av-gold"><?= strtoupper(substr($j['nama_pegawai'] ?? 'P', 0, 1)) ?></div>
                                    <div>
                                        <div class="pgw-name"><?= htmlspecialchars($j['nama_pegawai'] ?? '-') ?></div>
                                        <div class="pgw-unit"><?= htmlspecialchars($j['unit_pegawai'] ?? '-') ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="period-main"><?= fmt_tgl($j['tanggal_mulai'], $bulan) ?></div>
                                <?php if ($j['tanggal_mulai'] !== $j['tanggal_selesai']): ?>
                                <div class="period-sub">s/d <?= fmt_tgl($j['tanggal_selesai'], $bulan) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="d-none d-lg-table-cell" style="color:var(--muted);font-size:.8rem">
                                <?= htmlspecialchars($j['keterangan']) ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $scls ?>">
                                    <span class="bd"></span><?= $slbl ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <button class="action-btn" title="Edit"
                                    onclick="bukaEdit(
                                        <?= $j['id'] ?>,
                                        '<?= $j['berlaku_untuk'] ?>',
                                        '<?= $j['user_id'] ?? '' ?>',
                                        '<?= $j['tanggal_mulai'] ?>',
                                        '<?= $j['tanggal_selesai'] ?>',
                                        '<?= htmlspecialchars($j['keterangan'],ENT_QUOTES) ?>'
                                    )">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                </button>
                                <button class="action-btn danger" title="Hapus" onclick="hapus(<?= $j['id'] ?>)">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19c0 1.1.9 2 2 2h8c1.1 0 2-.9 2-2V7H6v12zM19 4h-3.5l-1-1h-5l-1 1H5v2h14V4z"/></svg>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ════ TAB: KOORDINAT ════ -->
        <div id="panel-koordinat" style="display:none">

            <!-- Stats -->
            <div class="stats-grid mb-4">
                <div class="stat-card c-green animate-1">
                    <div class="stat-ico-wrap"><svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg></div>
                    <div class="stat-val"><?= $pegawai_dengan_koordinat ?></div>
                    <div class="stat-lbl">Sudah Set</div>
                </div>
                <div class="stat-card c-gray animate-2">
                    <div class="stat-ico-wrap"><svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg></div>
                    <div class="stat-val"><?= $pegawai_tanpa_koordinat ?></div>
                    <div class="stat-lbl">Belum Set</div>
                </div>
                <div class="stat-card c-blue animate-3">
                    <div class="stat-ico-wrap"><svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/></svg></div>
                    <div class="stat-val"><?= count($pegawai_koordinat) ?></div>
                    <div class="stat-lbl">Total Pegawai</div>
                </div>
                <div class="stat-card c-gold animate-4">
                    <div class="stat-ico-wrap"><svg viewBox="0 0 24 24"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5z"/></svg></div>
                    <div class="stat-val"><?= $pegawai_dengan_koordinat ?></div>
                    <div class="stat-lbl">Terpetakan</div>
                </div>
            </div>

            <!-- Filter + Table -->
            <div class="wfh-card mb-4">
                <div class="filter-bar">
                    <div class="search-wrap">
                        <svg viewBox="0 0 24 24"><path d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" stroke="#8A9BBE" stroke-width="2" fill="none" stroke-linecap="round"/></svg>
                        <input type="text" class="search-input" id="searchKoordinat" placeholder="Cari nama pegawai...">
                    </div>
                    <select class="filter-select" id="filterStatus">
                        <option value="all">Semua Status</option>
                        <option value="ada">Sudah Set Koordinat</option>
                        <option value="belum">Belum Set</option>
                    </select>
                    <button class="btn-gold-outline" onclick="lihatSemuaDiPeta()">
                        <svg viewBox="0 0 24 24"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5z"/></svg>
                        Lihat di Peta
                    </button>
                </div>
                <div class="table-responsive">
                    <table class="wfh-table" id="tabelKoordinat">
                        <thead>
                            <tr>
                                <th style="width:40px">#</th>
                                <th>Nama Pegawai</th>
                                <th class="d-none d-md-table-cell">Unit Kerja</th>
                                <th class="text-center d-none d-sm-table-cell">Latitude</th>
                                <th class="text-center d-none d-sm-table-cell">Longitude</th>
                                <th class="text-center">Status</th>
                                <th class="text-center" style="width:90px">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($pegawai_koordinat as $i => $p):
                            $ada = $p['lat_rumah'] && $p['lng_rumah'];
                        ?>
                        <tr class="<?= $ada ? '' : 'row-warn' ?>" data-nama="<?= htmlspecialchars(strtolower($p['nama'])) ?>" data-status="<?= $ada ? 'ada' : 'belum' ?>">
                            <td style="color:var(--silver);font-size:.78rem"><?= $i+1 ?></td>
                            <td>
                                <div class="pgw-cell">
                                    <div class="avatar <?= $ada ? 'av-navy' : 'av-gold' ?>"><?= strtoupper(substr($p['nama'],0,1)) ?></div>
                                    <div class="pgw-name"><?= htmlspecialchars($p['nama']) ?></div>
                                </div>
                            </td>
                            <td class="d-none d-md-table-cell pgw-unit"><?= htmlspecialchars($p['unit_kerja'] ?? '-') ?></td>
                            <td class="text-center d-none d-sm-table-cell">
                                <span class="coord-val <?= $ada ? 'has' : 'empty' ?>"><?= $ada ? number_format($p['lat_rumah'],6) : '—' ?></span>
                            </td>
                            <td class="text-center d-none d-sm-table-cell">
                                <span class="coord-val <?= $ada ? 'has' : 'empty' ?>"><?= $ada ? number_format($p['lng_rumah'],6) : '—' ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($ada): ?>
                                <span class="status-badge sb-green"><span class="bd"></span>Sudah Set</span>
                                <?php else: ?>
                                <span class="status-badge sb-gray"><span class="bd"></span>Belum Set</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($ada): ?>
                                <button class="action-btn" title="Lihat di Peta" onclick="fokusKeLokasi(<?= (float)$p['lat_rumah'] ?>,<?= (float)$p['lng_rumah'] ?>,'<?= htmlspecialchars(addslashes($p['nama'])) ?>','<?= htmlspecialchars($p['foto']??'') ?>')">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z"/></svg>
                                </button>
                                <?php else: ?>
                                <button class="action-btn" disabled title="Belum ada koordinat">
                                    <svg viewBox="0 0 24 24" fill="currentColor" opacity=".3"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z"/></svg>
                                </button>
                                <?php endif; ?>
                                <a href="pegawai.php?edit=<?= $p['id'] ?>" class="action-btn" title="Edit Profil">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Map -->
            <div class="wfh-card" id="tour-peta">
                <div class="card-header-bar">
                    <div class="card-title">
                        <svg viewBox="0 0 24 24"><path d="M20.5 3l-.16.03L15 5.1 9 3 3.36 4.9c-.21.07-.36.25-.36.48V20.5c0 .28.22.5.5.5l.16-.03L9 18.9l6 2.1 5.64-1.9c.21-.07.36-.25.36-.48V3.5c0-.28-.22-.5-.5-.5z"/></svg>
                        Peta Koordinat Rumah Pegawai
                    </div>
                    <span class="count-chip"><?= $pegawai_dengan_koordinat ?> terpetakan</span>
                </div>
                <div style="padding:1.25rem">
                    <div id="mapKoordinat"></div>
                    <p class="map-note">
                        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        Lingkaran kuning = radius validasi WFH (100m). Klik marker untuk melihat detail pegawai.
                    </p>
                </div>
            </div>
        </div>

    </div><!-- /content-lift -->
</div><!-- /main-content -->

<!-- ════ MODAL WFH ════ -->
<div class="modal fade" id="modalWFH" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header-custom">
                <div class="modal-icon" id="mIcon">
                    <svg viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>
                </div>
                <div class="modal-title-text" id="mJudul">Tambah Jadwal WFH</div>
                <div class="modal-sub-text" id="mSub">Isi detail jadwal Work From Home</div>
                <button type="button" style="position:absolute;top:1.25rem;right:1.25rem;background:none;border:none;cursor:pointer;color:var(--muted)" data-bs-dismiss="modal">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="modal-divider"></div>
            <form id="formWFH" action="proses_wfh.php" method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" id="fAction" value="tambah">
                <input type="hidden" name="id"     id="fId"     value="">
                <div class="modal-body-custom">

                    <!-- Berlaku untuk -->
                    <div class="mb-3">
                        <label class="field-label">Berlaku Untuk</label>
                        <div class="toggle-group">
                            <div class="toggle-opt">
                                <input type="radio" name="berlaku_untuk" value="semua" id="rSemua" checked>
                                <label for="rSemua">
                                    <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                                    Semua Pegawai
                                </label>
                            </div>
                            <div class="toggle-opt">
                                <input type="radio" name="berlaku_untuk" value="personal" id="rPersonal">
                                <label for="rPersonal">
                                    <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                                    Pegawai Tertentu
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Multi-select pegawai -->
                    <div class="mb-3" id="wrapPgw" style="display:none">
                        <label class="field-label">
                            Pilih Pegawai
                            <span id="isEditNote" style="font-weight:400;color:var(--muted);display:none"> (saat edit: pilih satu pegawai)</span>
                        </label>

                        <!-- Search -->
                        <div class="pgw-search-wrap">
                            <svg viewBox="0 0 24 24"><path d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z" stroke="#8A9BBE" stroke-width="2" fill="none" stroke-linecap="round"/></svg>
                            <input type="text" id="pgwSearch" class="pgw-search-input" placeholder="Cari nama pegawai..." oninput="filterDaftarPgw(this.value)">
                        </div>

                        <!-- Pills pegawai terpilih -->
                        <div class="pgw-pills-wrap" id="pgwPills"></div>

                        <!-- Daftar checkbox -->
                        <div class="pgw-list" id="pgwList">
                            <!-- Select all (hanya muncul saat tambah) -->
                            <div class="pgw-select-all-row" id="pgwSelectAllRow">
                                <input type="checkbox" id="pgwSelectAll" onchange="toggleSelectAll(this.checked)">
                                <label for="pgwSelectAll">Pilih semua pegawai</label>
                            </div>
                            <?php foreach ($pegawai_koordinat as $p): ?>
                            <label class="pgw-check-item"
                                   data-nama="<?= strtolower(htmlspecialchars($p['nama'])) ?>"
                                   data-uid="<?= $p['id'] ?>">
                                <input type="checkbox"
                                       name="user_id[]"
                                       value="<?= $p['id'] ?>"
                                       data-nama="<?= htmlspecialchars($p['nama'],ENT_QUOTES) ?>"
                                       onchange="onCheckPgw(this)">
                                <div class="pgw-item-initial"><?= strtoupper(substr($p['nama'],0,1)) ?></div>
                                <div>
                                    <span class="pgw-item-name"><?= htmlspecialchars($p['nama']) ?></span>
                                    <?php if ($p['unit_kerja']): ?>
                                    <span class="pgw-item-unit"><?= htmlspecialchars($p['unit_kerja']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </label>
                            <?php endforeach; ?>
                            <div class="pgw-no-result" id="pgwNoResult" style="display:none">
                                Tidak ada pegawai yang cocok
                            </div>
                        </div>
                        <div class="pgw-count-info" id="pgwCountInfo"><strong>0</strong> pegawai dipilih</div>
                    </div>

                    <!-- Periode -->
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="field-label" for="iMulai">Tanggal Mulai</label>
                            <input type="date" name="tanggal_mulai" id="iMulai" class="field-input" value="<?= $today ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="field-label" for="iSelesai">Tanggal Selesai</label>
                            <input type="date" name="tanggal_selesai" id="iSelesai" class="field-input" value="<?= $today ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="field-label" for="iKet">Keterangan</label>
                        <input type="text" name="keterangan" id="iKet" class="field-input" placeholder="Contoh: Kebijakan pemerintah" value="Work From Home">
                    </div>

                    <div class="info-note">
                        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        Selama WFH aktif, validasi radius kantor <strong>dinonaktifkan</strong>. Pegawai absen dari koordinat rumah masing-masing.
                    </div>
                </div>
                <div class="modal-footer-custom">
                    <button type="button" style="padding:.6rem 1.2rem;border:1px solid var(--border);border-radius:var(--r-md);background:var(--white);color:var(--muted);font-family:'DM Sans',sans-serif;font-size:.84rem;font-weight:500;cursor:pointer" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn-primary-navy" id="btnSimpan">
                        <svg viewBox="0 0 24 24"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg>
                        <span id="btnLabel">Simpan</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Leaflet + SweetAlert -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
const PEGAWAI_PETA = <?= $pegawai_peta_json ?>;
let isEditMode = false;

/* ── Tab switching ── */
function switchTab(tab) {
    ['jadwal','koordinat'].forEach(t => {
        document.getElementById('panel-'+t).style.display = t===tab ? 'block' : 'none';
        document.getElementById('tab-'+t).classList.toggle('active', t===tab);
    });
    if (tab === 'koordinat') setTimeout(initMap, 180);
}

/* ═══════════════════════════════
   MULTI-SELECT PEGAWAI
═══════════════════════════════ */

// Toggle tampilkan/sembunyikan panel pegawai
document.querySelectorAll('input[name="berlaku_untuk"]').forEach(r => {
    r.addEventListener('change', function() {
        const show = this.value === 'personal';
        document.getElementById('wrapPgw').style.display = show ? 'block' : 'none';
        if (!show) resetMultiSelect();
    });
});

// Filter daftar berdasarkan pencarian
function filterDaftarPgw(q) {
    q = q.toLowerCase().trim();
    let adaHasil = false;
    document.querySelectorAll('#pgwList .pgw-check-item').forEach(el => {
        const cocok = el.dataset.nama.includes(q);
        el.style.display = cocok ? '' : 'none';
        if (cocok) adaHasil = true;
    });
    document.getElementById('pgwNoResult').style.display = adaHasil ? 'none' : 'block';
}

// Handler saat checkbox dicentang/uncentang
function onCheckPgw(cb) {
    const item = cb.closest('.pgw-check-item');
    item.classList.toggle('is-checked', cb.checked);
    renderPills();
    syncSelectAll();
}

// Select all / deselect all
function toggleSelectAll(checked) {
    document.querySelectorAll('#pgwList input[type=checkbox]').forEach(cb => {
        const item = cb.closest('.pgw-check-item');
        if (item.style.display !== 'none') {
            cb.checked = checked;
            item.classList.toggle('is-checked', checked);
        }
    });
    renderPills();
}

function syncSelectAll() {
    const all      = document.querySelectorAll('#pgwList .pgw-check-item:not([style*="none"]) input[type=checkbox]');
    const checked  = document.querySelectorAll('#pgwList .pgw-check-item:not([style*="none"]) input[type=checkbox]:checked');
    const saEl     = document.getElementById('pgwSelectAll');
    if (!saEl) return;
    saEl.indeterminate = checked.length > 0 && checked.length < all.length;
    saEl.checked       = all.length > 0 && checked.length === all.length;
}

// Render pills pegawai terpilih
function renderPills() {
    const checked = document.querySelectorAll('#pgwList input[type=checkbox]:checked');
    const wrap    = document.getElementById('pgwPills');
    const count   = document.getElementById('pgwCountInfo');
    wrap.innerHTML = '';
    checked.forEach(cb => {
        const pill = document.createElement('span');
        pill.className = 'selected-pill';
        pill.innerHTML = `${escHtml(cb.dataset.nama)}<button type="button" class="pill-x" onclick="uncheckPgw('${cb.value}')" title="Hapus">×</button>`;
        wrap.appendChild(pill);
    });
    count.innerHTML = `<strong>${checked.length}</strong> pegawai dipilih`;
}

function uncheckPgw(val) {
    const cb = document.querySelector(`#pgwList input[value="${val}"]`);
    if (cb) { cb.checked = false; cb.closest('.pgw-check-item').classList.remove('is-checked'); }
    renderPills();
    syncSelectAll();
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Reset semua pilihan
function resetMultiSelect() {
    document.querySelectorAll('#pgwList input[type=checkbox]').forEach(cb => {
        cb.checked = false;
        cb.closest('.pgw-check-item').classList.remove('is-checked');
        cb.closest('.pgw-check-item').style.display = '';
    });
    document.getElementById('pgwPills').innerHTML = '';
    document.getElementById('pgwCountInfo').innerHTML = '<strong>0</strong> pegawai dipilih';
    document.getElementById('pgwSearch').value = '';
    document.getElementById('pgwNoResult').style.display = 'none';
    const saEl = document.getElementById('pgwSelectAll');
    if (saEl) { saEl.checked = false; saEl.indeterminate = false; }
}

/* ── Modal helpers ── */
function resetModal() {
    isEditMode = false;
    document.getElementById('mJudul').textContent   = 'Tambah Jadwal WFH';
    document.getElementById('mSub').textContent     = 'Isi detail jadwal Work From Home';
    document.getElementById('fAction').value        = 'tambah';
    document.getElementById('fId').value            = '';
    document.getElementById('btnLabel').textContent = 'Simpan';
    document.getElementById('rSemua').checked       = true;
    document.getElementById('wrapPgw').style.display= 'none';
    document.getElementById('isEditNote').style.display = 'none';
    document.getElementById('pgwSelectAllRow').style.display = '';
    resetMultiSelect();
    const t = new Date().toISOString().split('T')[0];
    document.getElementById('iMulai').value   = t;
    document.getElementById('iSelesai').value = t;
    document.getElementById('iKet').value     = 'Work From Home';
}

function bukaEdit(id, berlaku, userId, mulai, selesai, ket) {
    isEditMode = true;
    document.getElementById('mJudul').textContent   = 'Edit Jadwal WFH';
    document.getElementById('mSub').textContent     = 'Perbarui detail jadwal Work From Home';
    document.getElementById('fAction').value        = 'edit';
    document.getElementById('fId').value            = id;
    document.getElementById('btnLabel').textContent = 'Perbarui';

    if (berlaku === 'semua') {
        document.getElementById('rSemua').checked       = true;
        document.getElementById('wrapPgw').style.display= 'none';
    } else {
        document.getElementById('rPersonal').checked    = true;
        document.getElementById('wrapPgw').style.display= 'block';
        document.getElementById('isEditNote').style.display = 'inline';
        // Sembunyikan "pilih semua" saat edit
        document.getElementById('pgwSelectAllRow').style.display = 'none';
        resetMultiSelect();
        // Pre-check pegawai yang dipilih
        if (userId) {
            const cb = document.querySelector(`#pgwList input[value="${userId}"]`);
            if (cb) {
                cb.checked = true;
                cb.closest('.pgw-check-item').classList.add('is-checked');
                renderPills();
            }
        }
    }

    document.getElementById('iMulai').value   = mulai;
    document.getElementById('iSelesai').value = selesai;
    document.getElementById('iKet').value     = ket;
    new bootstrap.Modal(document.getElementById('modalWFH')).show();
}

/* ── Form validation ── */
document.getElementById('formWFH').addEventListener('submit', function(e) {
    const m = new Date(document.getElementById('iMulai').value);
    const s = new Date(document.getElementById('iSelesai').value);
    if (s < m) {
        e.preventDefault();
        Swal.fire({ icon:'error', title:'Tanggal Tidak Valid', text:'Tanggal selesai tidak boleh sebelum tanggal mulai.', confirmButtonColor:'#0F2241' });
        return;
    }
    // Validasi minimal 1 pegawai dipilih jika personal
    const berlaku = document.querySelector('input[name="berlaku_untuk"]:checked').value;
    if (berlaku === 'personal') {
        const checked = document.querySelectorAll('#pgwList input[type=checkbox]:checked');
        if (checked.length === 0) {
            e.preventDefault();
            Swal.fire({ icon:'warning', title:'Pilih Pegawai', text:'Pilih minimal satu pegawai terlebih dahulu.', confirmButtonColor:'#0F2241' });
        }
    }
});

/* ── Hapus ── */
function hapus(id) {
    Swal.fire({
        icon:'warning', title:'Hapus Jadwal?',
        text:'Jadwal WFH ini akan dihapus secara permanen.',
        showCancelButton:true,
        confirmButtonText:'Hapus', cancelButtonText:'Batal',
        confirmButtonColor:'#DC2626', cancelButtonColor:'#64748B',
    }).then(r => {
        if (r.isConfirmed) {
            const f = document.createElement('form');
            f.method='POST'; f.action='proses_wfh.php';
            f.innerHTML='<input name="csrf_token" value="<?php echo htmlspecialchars(csrf_generate(), ENT_QUOTES, "UTF-8"); ?>"><input name="action" value="hapus"><input name="id" value="'+id+'">';
            document.body.appendChild(f); f.submit();
        }
    });
}

/* ── Filter koordinat ── */
document.getElementById('searchKoordinat').addEventListener('input', filterKoordinat);
document.getElementById('filterStatus').addEventListener('change', filterKoordinat);
function filterKoordinat() {
    const s = document.getElementById('searchKoordinat').value.toLowerCase();
    const f = document.getElementById('filterStatus').value;
    document.querySelectorAll('#tabelKoordinat tbody tr').forEach(row => {
        const ok = row.dataset.nama.includes(s) && (f==='all' || row.dataset.status===f);
        row.style.display = ok ? '' : 'none';
    });
}

/* ── Map ── */
let mapKoordinat = null, mapMarkers = [];

function initMap() {
    if (mapKoordinat) { setTimeout(() => mapKoordinat.invalidateSize(), 100); return; }
    mapKoordinat = L.map('mapKoordinat').setView([-7.365355,108.560654], 13);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom:19, attribution:'&copy; OpenStreetMap'
    }).addTo(mapKoordinat);

    PEGAWAI_PETA.forEach(p => {
        const lat = parseFloat(p.lat_rumah), lng = parseFloat(p.lng_rumah);
        if (isNaN(lat)||isNaN(lng)) return;
        const foto = p.foto ? '/absensi-nonasn/foto.php?type=selfie&file='+encodeURIComponent(p.foto) : '../assets/img/default-avatar.png';
        const icon = L.divIcon({
            className:'',
            html:`<div class="marker-wrap"><div class="marker-pin"><img src="${foto}" onerror="this.style.display='none'"></div><div class="marker-lbl">${p.nama}</div></div>`,
            iconSize:[72,58], iconAnchor:[36,58], popupAnchor:[0,-52]
        });
        const m = L.marker([lat,lng],{icon}).addTo(mapKoordinat).bindPopup(`
            <div style="text-align:center;padding:6px 4px;min-width:140px;font-family:'DM Sans',sans-serif">
                <div style="width:52px;height:52px;border-radius:50%;border:2px solid #C9A84C;margin:0 auto 8px;overflow:hidden">
                    <img src="${foto}" style="width:100%;height:100%;object-fit:cover" onerror="this.parentElement.innerHTML='<div style=background:#F0F4FC;width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-weight:700;color:#0A1628;font-size:1.1rem>${p.nama.charAt(0)}</div>'">
                </div>
                <div style="font-weight:700;color:#0A1628;font-size:.84rem">${p.nama}</div>
                <div style="color:#64748B;font-size:.72rem;margin:.2rem 0 .5rem">${p.unit_kerja||'-'}</div>
                <span style="background:#FEFAEF;color:#92400E;font-size:.68rem;padding:2px 8px;border-radius:99px;border:1px solid #F5E9C8">Radius 100m</span>
            </div>`);
        L.circle([lat,lng],{color:'#C9A84C',fillColor:'#FEFAEF',fillOpacity:.22,radius:100,weight:1.5}).addTo(mapKoordinat);
        mapMarkers.push(m);
    });

    if (mapMarkers.length > 0) {
        mapKoordinat.fitBounds(L.featureGroup(mapMarkers).getBounds().pad(.25));
    }
}

function lihatSemuaDiPeta() {
    switchTab('koordinat');
    setTimeout(() => {
        initMap();
        if (!mapMarkers.length) {
            Swal.fire({icon:'info',title:'Belum Ada Koordinat',text:'Belum ada pegawai yang memiliki koordinat rumah.',confirmButtonColor:'#0F2241'});
            return;
        }
        mapKoordinat.fitBounds(L.featureGroup(mapMarkers).getBounds().pad(.2),{animate:true,duration:.8});
    }, 520);
}

function fokusKeLokasi(lat, lng, nama, foto) {
    switchTab('koordinat');
    setTimeout(() => {
        initMap();
        mapKoordinat.setView([lat,lng],17,{animate:true,duration:.8});
        const fotoUrl = foto ? '/absensi-nonasn/foto.php?type=selfie&file='+encodeURIComponent(foto) : '../assets/img/default-avatar.png';
        L.popup({maxWidth:220}).setLatLng([lat,lng]).setContent(`
            <div style="text-align:center;padding:6px 4px;font-family:'DM Sans',sans-serif">
                <div style="width:52px;height:52px;border-radius:50%;border:2px solid #C9A84C;margin:0 auto 8px;overflow:hidden">
                    <img src="${fotoUrl}" style="width:100%;height:100%;object-fit:cover">
                </div>
                <div style="font-weight:700;color:#0A1628;font-size:.84rem">${nama}</div>
                <span style="background:#FEFAEF;color:#92400E;font-size:.68rem;padding:2px 8px;border-radius:99px;border:1px solid #F5E9C8">Radius 100m</span>
            </div>`).openOn(mapKoordinat);
    }, 520);
}

/* ═══════════════════════════════
   TOUR PANDUAN — Driver.js
═══════════════════════════════ */
const TOUR_KEY = 'bbws_wfh_tour_v1';

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
            overlayColor: 'rgba(10,22,40,.82)',
            onDestroyStarted: () => { localStorage.setItem(TOUR_KEY,'1'); drv.destroy(); },
            steps: [
                { popover: { title: '🏠 Selamat Datang di Kelola WFH', description: 'Halaman ini untuk mengatur jadwal <strong>Work From Home</strong> dan memantau koordinat rumah pegawai sebagai titik absensi WFH.', side:'over', align:'center' } },
                { element:'#tour-header', popover: { title:'📋 Header Halaman', description:'Tombol <strong>Tambah Jadwal</strong> untuk membuat jadwal WFH baru. Bisa untuk semua pegawai atau <strong>beberapa pegawai sekaligus</strong>.', side:'bottom', align:'start' } },
                { element:'#tour-tabs', popover: { title:'📑 Dua Tab Utama', description:'<strong>Jadwal WFH</strong> — kelola periode WFH.<br><br><strong>Koordinat Rumah</strong> — pantau & petakan lokasi rumah pegawai.', side:'bottom', align:'start' } },
                { element:'#tour-stats', popover: { title:'📊 Statistik Jadwal', description:'Ringkasan cepat: jumlah jadwal aktif, jadwal selesai, status WFH hari ini, dan total pegawai.', side:'bottom', align:'center' } },
                { element:'#tour-table', popover: { title:'📅 Tabel Jadwal WFH', description:'Daftar semua jadwal WFH. Status otomatis: Berlangsung, Akan Datang, atau Selesai.', side:'top', align:'center' } },
                { element:'#tab-koordinat', popover: { title:'📍 Tab Koordinat Rumah', description:'Badge merah menunjukkan pegawai yang belum memiliki koordinat rumah.', side:'bottom', align:'start' } },
                { popover: { title:'🎉 Siap Digunakan!', description:'Mulai dengan menambahkan jadwal WFH. Saat memilih "Pegawai Tertentu", Anda bisa memilih <strong>lebih dari satu pegawai</strong> sekaligus!', side:'over', align:'center' } }
            ]
        });
        drv.drive();
    });
}

if (!localStorage.getItem(TOUR_KEY)) {
    window.addEventListener('load', () => setTimeout(mulaiTour, 700));
}
</script>

<?php include '../templates/footer.php'; ?>