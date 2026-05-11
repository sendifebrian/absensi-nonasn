<?php
if (function_exists('opcache_reset')) { @opcache_reset(); }
clearstatcache();

require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/helper.php';
$is_qr_page = false;
if (!$is_qr_page) { restore_web_session($pdo); }
require_login();
if (!is_admin()) { header('Location: dashboard.php'); exit(); }

// ── 1. Daftar pegawai aktif ────────────────────────────────────
$stmt = $pdo->query("SELECT id, nama, created_at, unit_kerja, foto, status_foto FROM users WHERE role = 'pegawai' AND status = 'aktif' ORDER BY nama");
$pegawaiList = $stmt->fetchAll();

// ── 2. Smart filter ────────────────────────────────────────────
$pegawai_id = $_GET['pegawai_id'] ?? null;
$tgl_awal   = !empty($_GET['tgl_awal'])  ? $_GET['tgl_awal']  : null;
$tgl_akhir  = !empty($_GET['tgl_akhir']) ? $_GET['tgl_akhir'] : null;

// Tahun & bulan selalu punya nilai default = sekarang
$tahun = !empty($_GET['tahun']) ? (int)$_GET['tahun'] : (int)date('Y');
$bulan = !empty($_GET['bulan']) ? (int)$_GET['bulan'] : (int)date('n');

// Prioritas filter:
// 1) Jika tgl_awal & tgl_akhir diisi → pakai rentang tanggal, abaikan bulan/tahun
// 2) Selainnya → pakai bulan + tahun
if ($tgl_awal && $tgl_akhir) {
    $periode_awal  = $tgl_awal;
    $periode_akhir = $tgl_akhir;
    // Sinkronkan bulan/tahun agar dropdown tetap konsisten dengan rentang
    $bulan = (int)date('n', strtotime($tgl_awal));
    $tahun = (int)date('Y', strtotime($tgl_awal));
} else {
    // Bulan/tahun mode — tgl_awal/tgl_akhir mengikuti bulan yang dipilih
    $periode_awal  = date('Y-m-d', mktime(0,0,0,$bulan,1,$tahun));
    $periode_akhir = date('Y-m-t', strtotime($periode_awal));
    // Sync tgl_awal/tgl_akhir agar input date di form terisi sesuai bulan
    $tgl_awal  = $periode_awal;
    $tgl_akhir = $periode_akhir;
}

$hari_ini    = date('Y-m-d');
$range_awal  = $periode_awal;
$range_akhir = min($periode_akhir, $hari_ini);

// ── 3. Query kehadiran (dengan foto & mode_login) ──────────────
$conditions = []; $params = [];
if ($pegawai_id) { $conditions[] = "a.user_id = ?"; $params[] = $pegawai_id; }
// Selalu pakai rentang tanggal (tgl_awal/tgl_akhir selalu terisi)
$conditions[] = "a.tanggal BETWEEN ? AND ?"; $params[] = $tgl_awal; $params[] = $tgl_akhir;
$where = "WHERE " . implode(" AND ", $conditions);

$sql = "SELECT u.nama, u.jabatan, u.unit_kerja, u.foto as foto_profil, u.status_foto,
               a.id as att_id, a.user_id, a.tanggal,
               a.jam_masuk, a.jam_pulang,
               a.status_masuk, a.status_pulang,
               a.mode, a.alamat_wfh,
               a.foto_masuk, a.foto_pulang,
               a.mode_login, a.mode_login_pulang,
               a.lat_masuk, a.lng_masuk, a.lat_pulang, a.lng_pulang,
               'hadir' as jenis,
               NULL as keterangan, NULL as catatan, NULL as bukti
        FROM attendance a
        JOIN users u ON u.id = a.user_id
        $where
        ORDER BY a.tanggal DESC, u.nama";
$stmt = $pdo->prepare($sql); $stmt->execute($params); $hadirData = $stmt->fetchAll();

// ── 4. Query izin (dengan bukti) ──────────────────────────────
$condIzin = []; $paramsIzin = [];
if ($pegawai_id) { $condIzin[] = "i.user_id = ?"; $paramsIzin[] = $pegawai_id; }
$condIzin[] = "i.tanggal BETWEEN ? AND ?"; $paramsIzin[] = $tgl_awal; $paramsIzin[] = $tgl_akhir;
$whereIzin = "WHERE i.status = 'disetujui' AND " . implode(" AND ", $condIzin);

$sqlIzin = "SELECT u.nama, u.jabatan, u.unit_kerja, u.foto as foto_profil, u.status_foto,
                   NULL as att_id, i.user_id, i.tanggal,
                   NULL as jam_masuk, NULL as jam_pulang,
                   NULL as status_masuk, NULL as status_pulang,
                   NULL as mode, NULL as alamat_wfh,
                   NULL as foto_masuk, NULL as foto_pulang,
                   NULL as mode_login, NULL as mode_login_pulang,
                   NULL as lat_masuk, NULL as lng_masuk, NULL as lat_pulang, NULL as lng_pulang,
                   i.jenis, i.keterangan, NULL as catatan, i.bukti
            FROM izin i
            JOIN users u ON u.id = i.user_id
            $whereIzin
            ORDER BY i.tanggal DESC, u.nama";
$stmt = $pdo->prepare($sqlIzin); $stmt->execute($paramsIzin); $izinData = $stmt->fetchAll();

// ── 5. Query cuti (dengan bukti) ──────────────────────────────
$condCuti = []; $paramsCuti = [];
if ($pegawai_id) { $condCuti[] = "c.user_id = ?"; $paramsCuti[] = $pegawai_id; }
$condCuti[] = "c.tanggal_mulai <= ? AND c.tanggal_selesai >= ?"; $paramsCuti[] = $tgl_akhir; $paramsCuti[] = $tgl_awal;
$whereCuti = "WHERE c.status = 'disetujui' AND " . implode(" AND ", $condCuti);

$sqlCuti = "SELECT u.nama, u.jabatan, u.unit_kerja, u.foto as foto_profil, u.status_foto,
                   NULL as att_id, c.user_id, c.tanggal_mulai as tanggal,
                   NULL as jam_masuk, NULL as jam_pulang,
                   NULL as status_masuk, NULL as status_pulang,
                   NULL as mode, NULL as alamat_wfh,
                   NULL as foto_masuk, NULL as foto_pulang,
                   NULL as mode_login, NULL as mode_login_pulang,
                   NULL as lat_masuk, NULL as lng_masuk, NULL as lat_pulang, NULL as lng_pulang,
                   'cuti' as jenis, c.alasan as keterangan, c.catatan, c.bukti,
                   c.tanggal_mulai, c.tanggal_selesai
            FROM cuti c
            JOIN users u ON u.id = c.user_id
            $whereCuti
            ORDER BY c.tanggal_mulai DESC, u.nama";
$stmt = $pdo->prepare($sqlCuti); $stmt->execute($paramsCuti); $cutiData = $stmt->fetchAll();

$rekap = array_merge($hadirData, $izinData, $cutiData);
usort($rekap, fn($a, $b) => strcmp($b['tanggal'], $a['tanggal']));

// ── 6. Statistik ──────────────────────────────────────────────
$totalHadir = $totalIzin = $totalSakit = $totalCuti = $totalTerlambat = 0;
$totalWFO = $totalWFH = $totalWFA = 0;
foreach ($rekap as $r) {
    if ($r['jenis'] === 'hadir') {
        $totalHadir++;
        if ($r['mode'] === 'wfo') $totalWFO++;
        elseif ($r['mode'] === 'wfh') $totalWFH++;
        elseif ($r['mode'] === 'wfa') $totalWFA++;
        if (($r['status_masuk'] ?? '') === 'terlambat') $totalTerlambat++;
    } elseif ($r['jenis'] === 'izin')  $totalIzin++;
    elseif ($r['jenis'] === 'sakit')   $totalSakit++;
    elseif ($r['jenis'] === 'cuti')    $totalCuti++;
}

// ── 7. Hitung alpa ────────────────────────────────────────────
$stats = ['alpa' => 0];
$pegawaiAlpaList = [];
$total_hari_kerja_periode = 0;

$awal_dt  = new DateTime($range_awal);
$akhir_dt = new DateTime($range_akhir);
$libur_stmt = $pdo->prepare("SELECT tanggal FROM hari_libur WHERE tanggal BETWEEN ? AND ?");
$libur_stmt->execute([$range_awal, $range_akhir]);
$libur_days = array_flip($libur_stmt->fetchAll(PDO::FETCH_COLUMN));
for ($d = clone $awal_dt; $d <= $akhir_dt; $d->modify('+1 day')) {
    $ds = $d->format('Y-m-d');
    if ((int)$d->format('N') <= 5 && !isset($libur_days[$ds])) $total_hari_kerja_periode++;
}

foreach ($pegawaiList as $p) {
    if ($pegawai_id && $p['id'] != $pegawai_id) continue;
    $start_p = max($range_awal, date('Y-m-d', strtotime($p['created_at'])));
    if ($start_p > $range_akhir) continue;

    $hadir_stmt = $pdo->prepare("SELECT tanggal FROM attendance WHERE user_id=? AND jam_masuk IS NOT NULL AND tanggal BETWEEN ? AND ?");
    $hadir_stmt->execute([$p['id'], $start_p, $range_akhir]);
    $hadir_dates = array_flip($hadir_stmt->fetchAll(PDO::FETCH_COLUMN));

    $izin_stmt = $pdo->prepare("SELECT tanggal FROM izin WHERE user_id=? AND status='disetujui' AND tanggal BETWEEN ? AND ?");
    $izin_stmt->execute([$p['id'], $start_p, $range_akhir]);
    $izin_dates = array_flip($izin_stmt->fetchAll(PDO::FETCH_COLUMN));

    $cuti_stmt = $pdo->prepare("SELECT tanggal_mulai, tanggal_selesai FROM cuti WHERE user_id=? AND status='disetujui' AND tanggal_selesai >= ? AND tanggal_mulai <= ?");
    $cuti_stmt->execute([$p['id'], $start_p, $range_akhir]);
    $cuti_dates = [];
    foreach ($cuti_stmt->fetchAll() as $c) {
        $period = new DatePeriod(new DateTime($c['tanggal_mulai']), new DateInterval('P1D'), (new DateTime($c['tanggal_selesai']))->modify('+1 day'));
        foreach ($period as $date) $cuti_dates[$date->format('Y-m-d')] = true;
    }

    $wfhWfa_dates = [];
    $uk = $p['unit_kerja'] ?? '';
    foreach (['wfh_schedule','wfa_schedule'] as $tbl) {
        $sw = $pdo->prepare("SELECT tanggal_mulai, tanggal_selesai FROM $tbl WHERE tanggal_selesai >= ? AND tanggal_mulai <= ? AND (berlaku_untuk='semua' OR (berlaku_untuk='unit_kerja' AND unit_kerja=?) OR (berlaku_untuk='personal' AND user_id=?))");
        $sw->execute([$start_p, $range_akhir, $uk, $p['id']]);
        foreach ($sw->fetchAll() as $s) {
            $period = new DatePeriod(new DateTime($s['tanggal_mulai']), new DateInterval('P1D'), (new DateTime($s['tanggal_selesai']))->modify('+1 day'));
            foreach ($period as $date) $wfhWfa_dates[$date->format('Y-m-d')] = true;
        }
    }

    $alpa_count = 0;
    $alpa_dates = [];
    for ($d = clone (new DateTime($start_p)); $d <= new DateTime($range_akhir); $d->modify('+1 day')) {
        $ds = $d->format('Y-m-d'); $hari = (int)$d->format('N');
        if ($hari > 5 || isset($libur_days[$ds])) continue;
        if (isset($hadir_dates[$ds]) || isset($izin_dates[$ds]) || isset($cuti_dates[$ds]) || isset($wfhWfa_dates[$ds])) continue;
        $alpa_count++;
        $alpa_dates[] = $ds;
    }
    if ($alpa_count > 0) $pegawaiAlpaList[] = ['id'=>$p['id'],'nama'=>$p['nama'],'alpa'=>$alpa_count,'dates'=>$alpa_dates,'foto'=>$p['foto']??null,'status_foto'=>$p['status_foto']??null];
    $stats['alpa'] += $alpa_count;
}
usort($pegawaiAlpaList, fn($a, $b) => $b['alpa'] <=> $a['alpa']);

// ── UI helpers ────────────────────────────────────────────────
$bulanList = [1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',9=>'September',10=>'Oktober',11=>'November',12=>'Desember'];
$exportParams = http_build_query(array_filter(['pegawai_id'=>$pegawai_id,'tahun'=>$tahun,'bulan'=>$bulan,'tgl_awal'=>$tgl_awal,'tgl_akhir'=>$tgl_akhir]));
$periodeLabel = ($tgl_awal && $tgl_akhir) ? date('d/m/Y',strtotime($tgl_awal)).' – '.date('d/m/Y',strtotime($tgl_akhir)) : ($bulan ? $bulanList[(int)$bulan].' '.($tahun??date('Y')) : 'Bulan '.$bulanList[(int)date('n')].' '.date('Y'));
$pegawaiMap  = array_column($pegawaiList, 'nama', 'id');
$pegawaiNama = $pegawai_id ? ($pegawaiMap[$pegawai_id] ?? '') : '';
$auto_filter_note = (empty($_GET['tgl_awal']) && empty($_GET['tgl_akhir']) && empty($_GET['bulan']) && empty($_GET['pegawai_id'])) ? '<br><small style="color:rgba(255,255,255,.6);font-size:.7rem"><i class="bi bi-lightning-fill me-1"></i>Otomatis: 1 bulan ini s/d hari ini</small>' : '';
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<div class="main-content rk-root">
<div class="rk-page">

<!-- HERO -->
<header class="rk-hero" id="tour-header">
    <div class="rk-hero-bg"></div>
    <div class="rk-hero-inner">
        <div class="rk-hero-left">
            <div class="rk-eyebrow"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>Laporan Kehadiran</div>
            <h1 class="rk-hero-title">Rekap <em>Absensi</em><?= $auto_filter_note ?></h1>
            <div class="rk-hero-meta">
                <span class="rk-meta-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg><?= $periodeLabel ?></span>
                <?php if ($pegawaiNama): ?><span class="rk-meta-chip rk-meta-chip-gold"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><?= htmlspecialchars($pegawaiNama) ?></span><?php endif; ?>
                <span class="rk-meta-chip"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg><?= count($rekap) ?> entri</span>
            </div>
        </div>
        <div class="rk-hero-right">
            <button class="rk-guide-btn" onclick="startTour()" id="tour-guide-btn"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>Panduan</button>
            <a href="export_rekap_pdf.php?<?= $exportParams ?>" target="_blank" class="rk-export-btn" id="tour-export"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>Export PDF</a>
        </div>
    </div>
</header>

<!-- FILTER -->
<section class="rk-panel rk-filter-panel" id="tour-filter">
    <div class="rk-panel-label"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"/></svg>Filter Data</div>
    <form method="GET" class="rk-filter-grid">
        <div class="rk-field">
            <label class="rk-label">Pegawai</label>
            <div class="rk-select-wrap"><svg class="rk-select-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <select name="pegawai_id" class="rk-select"><option value="">Semua Pegawai</option>
                <?php foreach($pegawaiList as $p): ?><option value="<?= $p['id'] ?>" <?= ($pegawai_id??'') == $p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['nama']) ?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="rk-field">
            <label class="rk-label">Tahun</label>
            <div class="rk-select-wrap"><svg class="rk-select-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <select name="tahun" class="rk-select" id="selTahun"><?php for($y=2024;$y<=date('Y');$y++): ?><option value="<?= $y ?>" <?= $tahun == $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?></select>
            </div>
        </div>
        <div class="rk-field">
            <label class="rk-label">Bulan</label>
            <div class="rk-select-wrap"><svg class="rk-select-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
                <select name="bulan" class="rk-select" id="selBulan">
                <?php foreach($bulanList as $m => $nama): ?><option value="<?= $m ?>" <?= $bulan == $m ? 'selected' : '' ?>><?= $nama ?></option><?php endforeach; ?></select>
            </div>
        </div>
        <div class="rk-field"><label class="rk-label">Dari Tanggal</label><input type="date" name="tgl_awal" id="inpTglAwal" class="rk-input" value="<?= htmlspecialchars($tgl_awal??'') ?>"></div>
        <div class="rk-field"><label class="rk-label">Sampai Tanggal</label><input type="date" name="tgl_akhir" id="inpTglAkhir" class="rk-input" value="<?= htmlspecialchars($tgl_akhir??'') ?>"></div>
        <div class="rk-field rk-field-action">
            <button type="submit" class="rk-btn-primary"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>Tampilkan</button>
            <a href="rekap.php" class="rk-btn-ghost" title="Reset filter"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="1 4 1 10 7 10"/><path d="M3.51 15a9 9 0 1 0 .49-4.5"/></svg></a>
        </div>
    </form>
</section>

<!-- PERIODE INFO -->
<div class="rk-periode-bar">
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#0E1E3D" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
    <div><strong>Periode:</strong> <?= date('d M Y', strtotime($range_awal)) ?> – <?= date('d M Y', strtotime($range_akhir)) ?> &nbsp;|&nbsp;
    <strong>Hari Kerja Efektif:</strong> <?= $total_hari_kerja_periode ?> hari <span style="color:#94a3b8;font-size:.7rem;margin-left:.3rem">(Senin–Jumat, dikurangi Libur Nasional)</span></div>
</div>

<!-- ALPA LIST -->
<?php if (!empty($pegawaiAlpaList)): ?>
<!-- ALPA SUMMARY BAR -->
<div class="rk-alpa-bar">
    <div class="rk-alpa-bar-left">
        <div class="rk-alpa-bar-icon">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        </div>
        <div>
            <div class="rk-alpa-bar-title">Pegawai dengan Alpa</div>
            <div class="rk-alpa-bar-sub">
                <span class="rk-alpa-bar-count"><?= count($pegawaiAlpaList) ?> pegawai</span>
                &nbsp;·&nbsp; total <strong><?= $stats['alpa'] ?> hari</strong> tidak hadir tanpa keterangan pada periode ini
            </div>
        </div>
    </div>
    <button class="rk-alpa-bar-btn" onclick="openAlpaModal()">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Lihat Detail
    </button>
</div>
<?php endif; ?>

<!-- MODAL DETAIL ALPA -->
<div id="modalAlpa" class="alpa-modal-backdrop" onclick="closeAlpaModal(event)">
    <div class="alpa-modal-box" role="dialog" aria-modal="true">
        <div class="alpa-modal-head">
            <div>
                <div class="alpa-modal-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                    Detail Pegawai Alpa
                </div>
                <div class="alpa-modal-sub">Data alpa periode: <strong><?= date('d M Y', strtotime($range_awal)) ?> – <?= date('d M Y', strtotime($range_akhir)) ?></strong></div>
            </div>
            <button class="alpa-modal-close" onclick="closeAlpaModal()" aria-label="Tutup">&times;</button>
        </div>

        <!-- Filter dalam modal -->
        <div class="alpa-modal-filter">
            <div class="alpa-modal-search-wrap">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <input type="text" id="alpaSearch" class="alpa-modal-search" placeholder="Cari nama pegawai..." oninput="filterAlpaTable()">
            </div>
        </div>

        <!-- Tabel -->
        <div class="alpa-modal-body">
            <table class="alpa-table" id="alpaTable">
                <thead>
                    <tr>
                        <th style="width:36px">#</th>
                        <th>Pegawai</th>
                        <th style="width:80px;text-align:center">Alpa</th>
                        <th>Tanggal Alpa</th>
                        <th style="width:100px;text-align:center">Rekap</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $bln = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'Mei',6=>'Jun',7=>'Jul',8=>'Agu',9=>'Sep',10=>'Okt',11=>'Nov',12=>'Des'];
                foreach ($pegawaiAlpaList as $idx => $pa): ?>
                <tr class="alpa-tr" data-nama="<?= strtolower(htmlspecialchars($pa['nama'])) ?>">
                    <td class="alpa-td-num"><?= $idx + 1 ?></td>
                    <td>
                        <div class="alpa-nama-cell">
                            <div class="alpa-avatar">
                                <?php if (!empty($pa['foto'])): ?>
                                <img src="<?= htmlspecialchars(foto_url(basename($pa['foto']))) ?>"
                                     alt="<?= htmlspecialchars($pa['nama']) ?>"
                                     style="width:100%;height:100%;object-fit:cover;border-radius:50%"
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                                <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center"><?= mb_substr($pa['nama'],0,1) ?></span>
                                <?php else: ?>
                                <?= mb_substr($pa['nama'],0,1) ?>
                                <?php endif; ?>
                            </div>
                            <span class="alpa-nama-text"><?= htmlspecialchars($pa['nama']) ?></span>
                        </div>
                    </td>
                    <td style="text-align:center">
                        <span class="alpa-count-badge"><?= $pa['alpa'] ?> hari</span>
                    </td>
                    <td>
                        <div class="alpa-chips-wrap">
                            <?php foreach ($pa['dates'] as $tgl):
                                $ts  = strtotime($tgl);
                                $hari_singkat = ['Mon'=>'Sen','Tue'=>'Sel','Wed'=>'Rab','Thu'=>'Kam','Fri'=>'Jum'];
                                $day = $hari_singkat[date('D', $ts)] ?? date('D', $ts);
                            ?>
                            <span class="alpa-date-chip">
                                <span class="alpa-chip-day"><?= $day ?></span>
                                <span class="alpa-chip-num"><?= date('j', $ts) ?></span>
                                <span class="alpa-chip-bln"><?= $bln[(int)date('n', $ts)] ?></span>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td style="text-align:center">
                        <a href="rekap.php?pegawai_id=<?= $pa['id'] ?>&<?= http_build_query(['tahun'=>$tahun,'bulan'=>$bulan,'tgl_awal'=>$tgl_awal,'tgl_akhir'=>$tgl_akhir],'','&') ?>"
                           class="alpa-rekap-btn" target="_blank">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                            Rekap
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div id="alpaNoResult" class="alpa-no-result" style="display:none">Tidak ada pegawai yang cocok.</div>
        </div>
    </div>
</div>


<!-- STATS -->
<section class="rk-stats-grid" id="tour-stats">
    <div class="rk-stat rk-stat--green"><div class="rk-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg></div><div class="rk-stat-body"><div class="rk-stat-num"><?= $totalHadir ?></div><div class="rk-stat-lbl">Hadir</div></div><div class="rk-stat-bar"></div></div>
    <div class="rk-stat rk-stat--amber"><div class="rk-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div><div class="rk-stat-body"><div class="rk-stat-num"><?= $totalIzin ?></div><div class="rk-stat-lbl">Izin</div></div><div class="rk-stat-bar"></div></div>
    <div class="rk-stat rk-stat--red"><div class="rk-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg></div><div class="rk-stat-body"><div class="rk-stat-num"><?= $totalSakit ?></div><div class="rk-stat-lbl">Sakit</div></div><div class="rk-stat-bar"></div></div>
    <div class="rk-stat rk-stat--indigo"><div class="rk-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg></div><div class="rk-stat-body"><div class="rk-stat-num"><?= $totalCuti ?></div><div class="rk-stat-lbl">Cuti</div></div><div class="rk-stat-bar"></div></div>
    <div class="rk-stat rk-stat--slate"><div class="rk-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div><div class="rk-stat-body"><div class="rk-stat-num"><?= $totalTerlambat ?></div><div class="rk-stat-lbl">Terlambat</div></div><div class="rk-stat-bar"></div></div>
    <div class="rk-stat rk-stat--navy"><div class="rk-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg></div><div class="rk-stat-body"><div class="rk-stat-num"><?= $totalWFO ?></div><div class="rk-stat-lbl">WFO</div></div><div class="rk-stat-bar"></div></div>
    <div class="rk-stat rk-stat--teal"><div class="rk-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></div><div class="rk-stat-body"><div class="rk-stat-num"><?= $totalWFH ?></div><div class="rk-stat-lbl">WFH</div></div><div class="rk-stat-bar"></div></div>
    <div class="rk-stat rk-stat--purple"><div class="rk-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></div><div class="rk-stat-body"><div class="rk-stat-num"><?= $totalWFA ?></div><div class="rk-stat-lbl">WFA</div></div><div class="rk-stat-bar"></div></div>
    <div class="rk-stat rk-stat--danger"><div class="rk-stat-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg></div><div class="rk-stat-body"><div class="rk-stat-num"><?= (int)$stats['alpa'] ?></div><div class="rk-stat-lbl">Alpa</div></div><div class="rk-stat-bar"></div></div>
</section>

<!-- TABLE -->
<section class="rk-table-section" id="tour-table">
    <div class="rk-table-header">
        <div class="rk-table-title"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>Hasil Rekap Absensi</div>
        <div class="rk-table-count"><?= count($rekap) ?> entri</div>
    </div>
    <?php if (empty($rekap)): ?>
    <div class="rk-empty"><div class="rk-empty-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div><div class="rk-empty-title">Tidak ada data</div><div class="rk-empty-sub">Coba ubah filter untuk menampilkan data lain</div></div>
    <?php else: ?>
    <div class="rk-table-scroll">
        <table class="rk-table">
            <thead>
                <tr>
                    <th style="width:90px">Tanggal</th>
                    <th style="min-width:160px">Pegawai</th>
                    <th style="width:80px">Jenis</th>
                    <th style="width:90px">Mode</th>
                    <th style="min-width:160px">Masuk</th>
                    <th style="min-width:160px">Pulang</th>
                    <th style="width:130px">Status</th>
                    <th style="width:60px">Detail</th>
                </tr>
            </thead>
            <tbody>
            <?php
            foreach ($rekap as $idx => $r):
                $dayName  = $r['tanggal'] ? date('D', strtotime($r['tanggal'])) : '';
                $isWeekend = in_array($dayName, ['Sat','Sun']);

                // Tentukan mode login masuk & pulang
                $via_masuk  = $r['mode_login']        ?? null;
                $via_pulang = $r['mode_login_pulang']  ?? null;
                $via_izin_bukti = $r['bukti'] ?? null;

                // Data untuk modal (JSON-encode untuk JS)
                $modalData = [
                    'nama'          => $r['nama'],
                    'jabatan'       => $r['jabatan'] ?? '',
                    'unit_kerja'    => $r['unit_kerja'] ?? '',
                    'tanggal'       => $r['tanggal'],
                    'tanggal_mulai' => $r['tanggal_mulai'] ?? null,
                    'tanggal_selesai'=> $r['tanggal_selesai'] ?? null,
                    'jenis'         => $r['jenis'],
                    'mode'          => $r['mode'] ?? null,
                    'alamat_wfh'    => $r['alamat_wfh'] ?? null,
                    'jam_masuk'     => $r['jam_masuk'] ?? null,
                    'jam_pulang'    => $r['jam_pulang'] ?? null,
                    'status_masuk'  => $r['status_masuk'] ?? null,
                    'status_pulang' => $r['status_pulang'] ?? null,
                    'foto_masuk'    => $r['foto_masuk'] ?? null,
                    'foto_pulang'   => $r['foto_pulang'] ?? null,
                    'mode_login'    => $via_masuk,
                    'mode_login_pulang' => $via_pulang,
                    'keterangan'    => $r['keterangan'] ?? null,
                    'catatan'       => $r['catatan'] ?? null,
                    'bukti'         => $via_izin_bukti,
                    'lat_masuk'     => $r['lat_masuk'] ?? null,
                    'lng_masuk'     => $r['lng_masuk'] ?? null,
                    'lat_pulang'    => $r['lat_pulang'] ?? null,
                    'lng_pulang'    => $r['lng_pulang'] ?? null,
                ];
            ?>
            <tr class="rk-tr <?= $isWeekend ? 'rk-tr--weekend' : '' ?>" data-row="<?= $idx ?>" style="animation-delay:<?= $idx * 15 ?>ms">

                <!-- TANGGAL -->
                <td>
                    <?php if ($r['tanggal']): ?>
                    <div class="rk-date-cell">
                        <div class="rk-date-day"><?= date('d', strtotime($r['tanggal'])) ?></div>
                        <div class="rk-date-month"><?= date('M Y', strtotime($r['tanggal'])) ?></div>
                        <div class="rk-date-dow <?= $isWeekend ? 'rk-date-dow--weekend' : '' ?>"><?= $dayName ?></div>
                    </div>
                    <?php elseif(isset($r['tanggal_mulai'])): ?>
                    <div class="rk-date-range">
                        <div class="rk-date-range-start"><?= date('d M', strtotime($r['tanggal_mulai'])) ?></div>
                        <div class="rk-date-range-sep">→</div>
                        <div class="rk-date-range-end"><?= date('d M Y', strtotime($r['tanggal_selesai'])) ?></div>
                    </div>
                    <?php endif; ?>
                </td>

                <!-- PEGAWAI -->
                <td>
                    <div class="rk-nama-cell">
                        <div class="rk-avatar">
                            <?php if (!empty($r['foto_profil'])): ?>
                            <img src="<?= htmlspecialchars(foto_url(basename($r['foto_profil']))) ?>"
                                 alt="<?= htmlspecialchars($r['nama']) ?>"
                                 style="width:100%;height:100%;object-fit:cover;border-radius:50%;"
                                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex';">
                            <span style="display:none;width:100%;height:100%;align-items:center;justify-content:center;"><?= strtoupper(substr($r['nama'],0,1)) ?></span>
                            <?php else: ?>
                            <?= strtoupper(substr($r['nama'],0,1)) ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <div class="rk-nama"><?= htmlspecialchars($r['nama']) ?></div>
                            <?php
                            $tgl_gabung = null;
                            foreach ($pegawaiList as $p) {
                                if ($p['id'] == $r['user_id']) { $tgl_gabung = $p['created_at']; break; }
                            }
                            if ($tgl_gabung):
                            ?>
                            <div class="rk-join-date">
                                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                Bergabung: <?= date('d M Y', strtotime($tgl_gabung)) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>

                <!-- JENIS -->
                <td>
                    <?php if ($r['jenis'] === 'hadir'): ?>
                        <span class="rk-badge rk-badge--green"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>Hadir</span>
                    <?php elseif ($r['jenis'] === 'izin'): ?>
                        <span class="rk-badge rk-badge--amber"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>Izin</span>
                    <?php elseif ($r['jenis'] === 'sakit'): ?>
                        <span class="rk-badge rk-badge--red"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>Sakit</span>
                    <?php elseif ($r['jenis'] === 'cuti'): ?>
                        <span class="rk-badge rk-badge--indigo"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>Cuti</span>
                    <?php endif; ?>
                </td>

                <!-- MODE -->
                <td>
                    <?php if ($r['jenis'] === 'hadir'): ?>
                        <?php if ($r['mode'] === 'wfh'): ?>
                            <span class="rk-mode rk-mode--wfh" title="<?= htmlspecialchars($r['alamat_wfh']??'') ?>"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>WFH</span>
                        <?php elseif ($r['mode'] === 'wfa'): ?>
                            <span class="rk-mode rk-mode--wfa"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>WFA</span>
                        <?php else: ?>
                            <span class="rk-mode rk-mode--wfo"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></svg>WFO</span>
                        <?php endif; ?>
                    <?php elseif ($r['keterangan']): ?>
                        <span class="rk-keterangan" title="<?= htmlspecialchars($r['keterangan']) ?>"><?= mb_strlen($r['keterangan'])>28 ? htmlspecialchars(mb_substr($r['keterangan'],0,25)).'…' : htmlspecialchars($r['keterangan']) ?></span>
                    <?php else: ?>
                        <span class="rk-dash">—</span>
                    <?php endif; ?>
                </td>

                <!-- JAM MASUK + FOTO / QR -->
                <td>
                    <?php if ($r['jenis'] === 'hadir' && $r['jam_masuk']): ?>
                    <div class="rk-jam-foto-cell">
                        <div class="rk-jam-row">
                            <span class="rk-time rk-time--in">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 8 14 12"/><line x1="18" y1="8" x2="18" y2="16"/><circle cx="9" cy="12" r="7"/></svg>
                                <?= date('H:i', strtotime($r['jam_masuk'])) ?>
                            </span>
                            <!-- Via QR atau Web -->
                            <?php if ($via_masuk === 'qr'): ?>
                                <span class="rk-via-badge rk-via-qr" title="Absen via QR Code">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="5" y="5" width="3" height="3"/><rect x="16" y="5" width="3" height="3"/><rect x="5" y="16" width="3" height="3"/><path d="M14 14h3v3h3v3M17 14v3M14 17h3"/></svg>QR
                                </span>
                            <?php elseif ($via_masuk === 'web'): ?>
                                <span class="rk-via-badge rk-via-web" title="Absen via Website">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>Web
                                </span>
                            <?php endif; ?>
                        </div>
                        <!-- Foto thumbnail masuk -->
                        <?php if ($r['foto_masuk']): ?>
                        <div class="rk-thumb-wrap" onclick='openModal(<?= json_encode($modalData, JSON_UNESCAPED_UNICODE) ?>, "masuk")' title="Klik untuk lihat foto masuk">
                            <img src="<?= htmlspecialchars(foto_url(basename($r['foto_masuk']))) ?>" class="rk-thumb" alt="Foto masuk" loading="lazy" onerror="this.parentElement.classList.add('rk-thumb-error');this.parentElement.innerHTML='<span class=\'rk-thumb-na\'>No Img</span>'">
                            <div class="rk-thumb-overlay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg></div>
                        </div>
                        <?php elseif ($via_masuk === 'qr'): ?>
                        <div class="rk-qr-badge-thumb" title="Absen via QR — tidak ada foto">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="5" y="5" width="3" height="3"/><rect x="16" y="5" width="3" height="3"/><rect x="5" y="16" width="3" height="3"/><path d="M14 14h3v3h3v3M17 14v3M14 17h3"/></svg>
                            <span>via QR</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php elseif (in_array($r['jenis'], ['izin','sakit','cuti'])): ?>
                        <span class="rk-dash">—</span>
                    <?php else: ?>
                        <span class="rk-dash">—</span>
                    <?php endif; ?>
                </td>

                <!-- JAM PULANG + FOTO / QR -->
                <td>
                    <?php if ($r['jenis'] === 'hadir' && $r['jam_pulang']): ?>
                    <div class="rk-jam-foto-cell">
                        <div class="rk-jam-row">
                            <span class="rk-time rk-time--out">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="2 12 6 16 10 12"/><line x1="6" y1="16" x2="6" y2="8"/><circle cx="15" cy="12" r="7"/></svg>
                                <?= date('H:i', strtotime($r['jam_pulang'])) ?>
                            </span>
                            <?php if ($via_pulang === 'qr'): ?>
                                <span class="rk-via-badge rk-via-qr" title="Absen pulang via QR Code">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="5" y="5" width="3" height="3"/><rect x="16" y="5" width="3" height="3"/><rect x="5" y="16" width="3" height="3"/><path d="M14 14h3v3h3v3M17 14v3M14 17h3"/></svg>QR
                                </span>
                            <?php elseif ($via_pulang === 'web'): ?>
                                <span class="rk-via-badge rk-via-web" title="Absen pulang via Website">
                                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>Web
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ($r['foto_pulang']): ?>
                        <div class="rk-thumb-wrap" onclick='openModal(<?= json_encode($modalData, JSON_UNESCAPED_UNICODE) ?>, "pulang")' title="Klik untuk lihat foto pulang">
                            <img src="<?= htmlspecialchars(foto_url(basename($r['foto_pulang']))) ?>" class="rk-thumb" alt="Foto pulang" loading="lazy" onerror="this.parentElement.classList.add('rk-thumb-error');this.parentElement.innerHTML='<span class=\'rk-thumb-na\'>No Img</span>'">
                            <div class="rk-thumb-overlay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg></div>
                        </div>
                        <?php elseif ($via_pulang === 'qr'): ?>
                        <div class="rk-qr-badge-thumb" title="Pulang via QR — tidak ada foto">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="5" y="5" width="3" height="3"/><rect x="16" y="5" width="3" height="3"/><rect x="5" y="16" width="3" height="3"/><path d="M14 14h3v3h3v3M17 14v3M14 17h3"/></svg>
                            <span>via QR</span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php elseif ($r['jenis'] === 'hadir' && $r['jam_masuk'] && !$r['jam_pulang']): ?>
                        <span class="rk-dash" style="font-size:.68rem;color:#94a3b8">Belum pulang</span>
                    <?php else: ?>
                        <?php if ($via_izin_bukti): ?>
                            <div class="rk-bukti-thumb" onclick='openModal(<?= json_encode($modalData, JSON_UNESCAPED_UNICODE) ?>, "bukti")' title="Lihat bukti <?= htmlspecialchars($r['jenis']) ?>">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                                <span>Lihat Bukti</span>
                            </div>
                        <?php else: ?>
                            <span class="rk-dash">—</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>

                <!-- STATUS -->
                <td>
                    <?php if ($r['jenis'] === 'hadir'): ?>
                        <?php if (($r['status_masuk']??'') === 'terlambat'): ?>
                            <span class="rk-status rk-status--late"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Terlambat</span>
                        <?php else: ?>
                            <span class="rk-status rk-status--ontime"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>Tepat Waktu</span>
                        <?php endif; ?>
                        <?php if (($r['status_pulang']??'') === 'pulang awal'): ?>
                            <span class="rk-status rk-status--early" style="margin-top:.22rem"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="2 12 6 16 10 12"/></svg>Pulang Awal</span>
                        <?php elseif (($r['status_pulang']??'') === 'pulang tepat'): ?>
                            <span class="rk-status rk-status--ontime" style="margin-top:.22rem"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>Pulang Tepat</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="rk-dash">—</span>
                    <?php endif; ?>
                </td>

                <!-- DETAIL -->
                <td>
                    <button class="rk-detail-btn" onclick='openModal(<?= json_encode($modalData, JSON_UNESCAPED_UNICODE) ?>, "all")' title="Lihat detail lengkap">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    </button>
                </td>

            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <!-- PAGINATION -->
    <div id="rkPagination" class="rk-pagination"></div>
    <?php endif; ?>
</section>

</div><!-- .rk-page -->
</div><!-- .rk-root -->

<!-- ═══════════════════ MODAL DETAIL ═══════════════════ -->
<div id="rkModal" class="rkm-backdrop" onclick="closeModal(event)">
    <div class="rkm-box" role="dialog" aria-modal="true">
        <button class="rkm-close" onclick="closeModal()" aria-label="Tutup">&times;</button>

        <!-- Header modal -->
        <div class="rkm-head">
            <div class="rkm-head-avatar" id="mAvatar"></div>
            <div class="rkm-head-info">
                <div class="rkm-head-nama" id="mNama"></div>
                <div class="rkm-head-sub" id="mSub"></div>
            </div>
            <div class="rkm-head-badge" id="mJenisBadge"></div>
        </div>

        <!-- Tanggal -->
        <div class="rkm-section rkm-tanggal" id="mTanggal"></div>

        <!-- Keterangan / Alasan (izin/cuti) -->
        <div class="rkm-section rkm-keterangan-wrap" id="mKetWrap" style="display:none">
            <div class="rkm-section-label">Keterangan</div>
            <div class="rkm-ket-text" id="mKetText"></div>
            <div class="rkm-catatan" id="mCatatan" style="display:none"></div>
        </div>

        <!-- Foto grid -->
        <div class="rkm-foto-grid" id="mFotoGrid"></div>

        <!-- Bukti (izin/cuti) -->
        <div id="mBuktiWrap" style="display:none">
            <div class="rkm-section-label" style="padding:0 1.5rem;margin-bottom:.65rem">Bukti Dokumen</div>
            <div class="rkm-bukti-area" id="mBuktiArea"></div>
        </div>

        <!-- Info baris -->
        <div class="rkm-info-grid" id="mInfoGrid"></div>
    </div>
</div>

<!-- ═══════════════ LIGHTBOX FULLSCREEN FOTO ═══════════════ -->
<div id="rkLightbox" class="rklb-backdrop" onclick="closeLightbox()">
    <button class="rklb-close" onclick="closeLightbox()">&times;</button>
    <img id="rkLbImg" src="" alt="" class="rklb-img" onclick="event.stopPropagation()">
    <div class="rklb-cap" id="rkLbCap"></div>
    <button class="rklb-nav rklb-prev" id="lbPrev" onclick="event.stopPropagation();lightboxNav(-1)">&#8249;</button>
    <button class="rklb-nav rklb-next" id="lbNext" onclick="event.stopPropagation();lightboxNav(1)">&#8250;</button>
</div>

<style>
/* ════════════════════════════════════════════════════════
   ROOT VARS & RESET
════════════════════════════════════════════════════════ */
:root{
  --gold:#C9A84C;--gold-lt:#FBF3DE;--gold-dk:#A8862E;
  --navy:#0E1E3D;--navy-2:#172B55;--navy-3:#1E3A6E;
  --slate:#64748B;--slate-lt:#F1F5F9;--slate-2:#E2E8F0;--slate-3:#CBD5E1;
  --white:#fff;--text:#1E293B;--text-2:#475569;--text-3:#94A3B8;
  --r-sm:6px;--r-md:10px;--r-lg:14px;--r-xl:18px;
  --sh-sm:0 1px 3px rgba(0,0,0,.06);--sh-md:0 4px 16px rgba(0,0,0,.08);--sh-lg:0 12px 40px rgba(0,0,0,.14);
  --font-head:'DM Serif Display',Georgia,serif;
  --font-body:'Geist','SF Pro Display',system-ui,sans-serif;
}
.rk-root *{box-sizing:border-box;margin:0;padding:0}
.rk-root{font-family:var(--font-body);color:var(--text);background:#F8FAFC;min-height:100vh}
.rk-page{max-width:1440px;margin:0 auto;padding:1.5rem 1.75rem 4rem;display:flex;flex-direction:column;gap:1.25rem}

/* HERO */
.rk-hero{position:relative;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-3) 60%,#1B3A72 100%);border-radius:var(--r-xl);overflow:hidden;box-shadow:0 8px 32px rgba(14,30,61,.28)}
.rk-hero-bg{position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse 60% 80% at 90% 50%,rgba(201,168,76,.12) 0%,transparent 70%),radial-gradient(ellipse 40% 60% at -10% 50%,rgba(201,168,76,.06) 0%,transparent 70%)}
.rk-hero::after{content:'';position:absolute;inset:0;pointer-events:none;background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);background-size:32px 32px}
.rk-hero-inner{position:relative;z-index:1;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:1.25rem;padding:2rem 2.25rem}
.rk-eyebrow{display:inline-flex;align-items:center;gap:.4rem;font-size:.6rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--gold);margin-bottom:.55rem}
.rk-hero-title{font-family:var(--font-head);font-size:2rem;font-weight:400;line-height:1.1;color:var(--white)}.rk-hero-title em{color:var(--gold);font-style:italic}
.rk-hero-meta{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.75rem}
.rk-meta-chip{display:inline-flex;align-items:center;gap:.35rem;font-size:.7rem;font-weight:500;color:rgba(255,255,255,.65);background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);padding:.28rem .7rem;border-radius:20px}
.rk-meta-chip-gold{color:var(--gold);background:rgba(201,168,76,.12);border-color:rgba(201,168,76,.3)}
.rk-hero-right{display:flex;gap:.65rem;align-items:center}
.rk-guide-btn{display:inline-flex;align-items:center;gap:.45rem;font-size:.72rem;font-weight:600;color:rgba(255,255,255,.7);background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.15);border-radius:var(--r-md);padding:.55rem 1rem;cursor:pointer;transition:all .2s}.rk-guide-btn:hover{background:rgba(255,255,255,.14);color:#fff}
.rk-export-btn{display:inline-flex;align-items:center;gap:.45rem;font-size:.72rem;font-weight:700;color:var(--navy);background:var(--gold);border:none;border-radius:var(--r-md);padding:.55rem 1.15rem;cursor:pointer;transition:all .2s;text-decoration:none;box-shadow:0 2px 12px rgba(201,168,76,.35)}.rk-export-btn:hover{background:var(--gold-dk);transform:translateY(-1px)}

/* PANEL */
.rk-panel{background:var(--white);border:1px solid var(--slate-2);border-radius:var(--r-lg);box-shadow:var(--sh-sm);padding:1.15rem 1.5rem}
.rk-panel-label{display:flex;align-items:center;gap:.45rem;font-size:.62rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:var(--slate);margin-bottom:.9rem}
.rk-filter-grid{display:grid;grid-template-columns:1.8fr 1fr 1.2fr 1.2fr 1.2fr auto;gap:.85rem;align-items:end}
.rk-field{display:flex;flex-direction:column}.rk-label{font-size:.6rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-2);margin-bottom:.32rem}
.rk-select-wrap{position:relative}.rk-select-ico{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);width:13px;height:13px;color:var(--text-3);pointer-events:none}
.rk-select{width:100%;appearance:none;padding:.46rem .85rem .46rem 2.1rem;border:1.5px solid var(--slate-2);border-radius:var(--r-sm);font-size:.78rem;color:var(--text);background:#fff;cursor:pointer;outline:none;transition:border-color .2s}.rk-select:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.12)}
.rk-input{padding:.46rem .85rem;border:1.5px solid var(--slate-2);border-radius:var(--r-sm);font-size:.78rem;color:var(--text);background:#fff;outline:none;transition:border-color .2s;width:100%}.rk-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.12)}
.rk-field-action{flex-direction:row;gap:.5rem}
.rk-btn-primary{display:inline-flex;align-items:center;gap:.4rem;font-size:.75rem;font-weight:700;color:#fff;background:var(--navy);border:none;border-radius:var(--r-sm);padding:.46rem 1.05rem;cursor:pointer;transition:all .2s}.rk-btn-primary:hover{background:var(--navy-3);transform:translateY(-1px)}
.rk-btn-ghost{display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:var(--r-sm);background:var(--slate-lt);border:1.5px solid var(--slate-2);color:var(--slate);text-decoration:none;transition:all .2s}.rk-btn-ghost:hover{border-color:var(--gold);color:var(--gold-dk)}

/* PERIODE BAR */
.rk-periode-bar{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:.75rem 1.1rem;display:flex;align-items:center;gap:.6rem;font-size:.78rem;color:#475569}

/* ALPA SUMMARY BAR */
.rk-alpa-bar{background:#fff;border:1px solid #fecaca;border-radius:var(--r-lg);padding:.9rem 1.2rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;box-shadow:var(--sh-sm)}
.rk-alpa-bar-left{display:flex;align-items:center;gap:.75rem}
.rk-alpa-bar-icon{width:36px;height:36px;background:#fef2f2;border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;color:#dc2626;flex-shrink:0}
.rk-alpa-bar-title{font-size:.82rem;font-weight:700;color:#991b1b}
.rk-alpa-bar-sub{font-size:.73rem;color:#64748b;margin-top:.15rem}
.rk-alpa-bar-count{display:inline-block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:99px;font-size:.65rem;font-weight:700;padding:.1rem .5rem}
.rk-alpa-bar-btn{display:inline-flex;align-items:center;gap:.4rem;font-size:.73rem;font-weight:700;color:#fff;background:#991b1b;border:none;border-radius:var(--r-sm);padding:.5rem 1rem;cursor:pointer;transition:all .2s;white-space:nowrap;flex-shrink:0}.rk-alpa-bar-btn:hover{background:#7f1d1d;transform:translateY(-1px)}

/* MODAL ALPA */
.alpa-modal-backdrop{position:fixed;inset:0;z-index:1100;background:rgba(14,30,61,.6);backdrop-filter:blur(4px);display:none;align-items:center;justify-content:center;padding:1rem}
.alpa-modal-backdrop.active{display:flex;animation:fadeBg .2s ease}
@keyframes fadeBg{from{opacity:0}to{opacity:1}}
.alpa-modal-box{background:#fff;border-radius:var(--r-xl);box-shadow:0 24px 64px rgba(14,30,61,.28);width:100%;max-width:820px;max-height:88vh;display:flex;flex-direction:column;animation:slideUp .25s cubic-bezier(.34,1.56,.64,1)}
@keyframes slideUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:none}}
.alpa-modal-head{display:flex;align-items:flex-start;justify-content:space-between;padding:1.4rem 1.5rem 1rem;border-bottom:1px solid #f1f5f9;flex-shrink:0}
.alpa-modal-title{display:flex;align-items:center;gap:.5rem;font-size:.95rem;font-weight:700;color:#991b1b}
.alpa-modal-sub{font-size:.72rem;color:#64748b;margin-top:.25rem}
.alpa-modal-close{width:30px;height:30px;display:flex;align-items:center;justify-content:center;background:#f1f5f9;border:none;border-radius:50%;font-size:1.1rem;color:#64748b;cursor:pointer;transition:all .15s;flex-shrink:0}.alpa-modal-close:hover{background:#fee2e2;color:#dc2626}
.alpa-modal-filter{padding:.85rem 1.5rem;border-bottom:1px solid #f1f5f9;flex-shrink:0}
.alpa-modal-search-wrap{position:relative;max-width:320px}
.alpa-modal-search-wrap svg{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none}
.alpa-modal-search{width:100%;padding:.42rem .85rem .42rem 2rem;border:1.5px solid #e2e8f0;border-radius:var(--r-sm);font-size:.78rem;color:#1e293b;outline:none;transition:border-color .2s}.alpa-modal-search:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.12)}
.alpa-modal-body{overflow-y:auto;padding:0 0 1rem}
.alpa-table{width:100%;border-collapse:collapse;font-size:.78rem}
.alpa-table thead tr{background:linear-gradient(to right,var(--navy),var(--navy-2))}
.alpa-table thead th{padding:.65rem 1rem;font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:rgba(255,255,255,.6);text-align:left;white-space:nowrap}
.alpa-table thead th:first-child{padding-left:1.5rem}
.alpa-table thead th:last-child{padding-right:1.5rem}
.alpa-tr{border-bottom:1px solid #f1f5f9;transition:background .12s}.alpa-tr:hover{background:#fafafa}
.alpa-table tbody td{padding:.7rem 1rem;vertical-align:top}
.alpa-table tbody td:first-child{padding-left:1.5rem}
.alpa-table tbody td:last-child{padding-right:1.5rem}
.alpa-td-num{color:#94a3b8;font-size:.72rem;font-weight:600;vertical-align:middle!important}
.alpa-nama-cell{display:flex;align-items:center;gap:.55rem}
.alpa-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,#172B55,#1E3A6E);color:#fff;font-size:.72rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
.alpa-nama-text{font-weight:600;color:var(--navy);font-size:.8rem}
.alpa-count-badge{display:inline-block;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:99px;font-size:.68rem;font-weight:700;padding:.18rem .6rem}
.alpa-chips-wrap{display:flex;flex-wrap:wrap;gap:.3rem}
.alpa-date-chip{display:inline-flex;align-items:center;gap:.25rem;background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:.2rem .45rem;white-space:nowrap}
.alpa-chip-day{font-size:.58rem;font-weight:700;color:#991b1b;text-transform:uppercase}
.alpa-chip-num{font-size:.75rem;font-weight:700;color:#1e293b}
.alpa-chip-bln{font-size:.62rem;color:#64748b}
.alpa-rekap-btn{display:inline-flex;align-items:center;gap:.3rem;font-size:.67rem;font-weight:600;color:var(--navy);background:rgba(14,30,61,.06);border:1px solid rgba(14,30,61,.15);border-radius:6px;padding:.25rem .6rem;text-decoration:none;white-space:nowrap;transition:all .15s}.alpa-rekap-btn:hover{background:rgba(14,30,61,.12)}
.alpa-no-result{padding:2rem;text-align:center;color:#94a3b8;font-size:.8rem}


/* STATS */
.rk-stats-grid{display:grid;grid-template-columns:repeat(9,minmax(0,1fr));gap:.85rem}
@media(max-width:1200px){.rk-stats-grid{grid-template-columns:repeat(5,minmax(0,1fr))}}
@media(max-width:760px){.rk-stats-grid{grid-template-columns:repeat(3,minmax(0,1fr))}}
.rk-stat{background:var(--white);border:1px solid var(--slate-2);border-radius:var(--r-lg);padding:1rem .75rem;display:flex;align-items:center;gap:.6rem;box-shadow:var(--sh-sm);position:relative;overflow:hidden;transition:all .22s cubic-bezier(.34,1.56,.64,1);min-width:0}.rk-stat:hover{transform:translateY(-3px);box-shadow:var(--sh-md)}
.rk-stat-bar{position:absolute;top:0;left:0;width:100%;height:3px;border-radius:var(--r-lg) var(--r-lg) 0 0}
.rk-stat--green  .rk-stat-bar{background:linear-gradient(90deg,#16a34a,#4ade80)}
.rk-stat--amber  .rk-stat-bar{background:linear-gradient(90deg,#d97706,#fbbf24)}
.rk-stat--red    .rk-stat-bar{background:linear-gradient(90deg,#dc2626,#f87171)}
.rk-stat--indigo .rk-stat-bar{background:linear-gradient(90deg,#4f46e5,#818cf8)}
.rk-stat--slate  .rk-stat-bar{background:linear-gradient(90deg,#475569,#94a3b8)}
.rk-stat--navy   .rk-stat-bar{background:linear-gradient(90deg,var(--navy),var(--navy-3))}
.rk-stat--teal   .rk-stat-bar{background:linear-gradient(90deg,#0d9488,#2dd4bf)}
.rk-stat--purple .rk-stat-bar{background:linear-gradient(90deg,#7c3aed,#c4b5fd)}
.rk-stat--danger .rk-stat-bar{background:linear-gradient(90deg,#dc2626,#f87171)}
.rk-stat-icon{width:34px;height:34px;border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.rk-stat-icon svg{width:16px;height:16px}
.rk-stat--green  .rk-stat-icon{background:#dcfce7;color:#16a34a}
.rk-stat--amber  .rk-stat-icon{background:#fef3c7;color:#d97706}
.rk-stat--red    .rk-stat-icon{background:#fee2e2;color:#dc2626}
.rk-stat--indigo .rk-stat-icon{background:#e0e7ff;color:#4f46e5}
.rk-stat--slate  .rk-stat-icon{background:var(--slate-lt);color:var(--slate)}
.rk-stat--navy   .rk-stat-icon{background:#dbeafe;color:var(--navy)}
.rk-stat--teal   .rk-stat-icon{background:#ccfbf1;color:#0d9488}
.rk-stat--purple .rk-stat-icon{background:#f5f3ff;color:#7c3aed}
.rk-stat--danger .rk-stat-icon{background:#fee2e2;color:#dc2626}
.rk-stat-num{font-size:clamp(1rem,1.4vw,1.55rem);font-weight:800;line-height:1;color:var(--text);letter-spacing:-.02em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rk-stat-lbl{font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-3);margin-top:.18rem;white-space:nowrap}

/* TABLE */
.rk-table-section{background:var(--white);border:1px solid var(--slate-2);border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh-sm)}
.rk-table-header{display:flex;justify-content:space-between;align-items:center;padding:1rem 1.5rem;border-bottom:2px solid var(--gold);background:linear-gradient(to right,#FAFBFC,var(--white))}
.rk-table-title{display:flex;align-items:center;gap:.5rem;font-size:.82rem;font-weight:700;color:var(--navy)}.rk-table-title svg{color:var(--gold)}
.rk-table-count{font-size:.68rem;font-weight:700;color:var(--navy);background:var(--gold-lt);border:1px solid rgba(201,168,76,.3);padding:.2rem .65rem;border-radius:20px}
.rk-table-scroll{overflow-x:auto}
.rk-table{width:100%;border-collapse:collapse;font-size:.785rem}
.rk-table thead tr{background:linear-gradient(to right,var(--navy) 0%,var(--navy-2) 100%)}
.rk-table thead th{padding:.75rem 1rem;font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.6);white-space:nowrap;border:none;text-align:left}
.rk-table thead th:first-child{padding-left:1.5rem}
.rk-table thead th:last-child{padding-right:1.5rem;text-align:center}
@keyframes rowIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
.rk-tr{border-bottom:1px solid var(--slate-2);transition:background .12s;animation:rowIn .28s ease both}
.rk-tr:last-child{border-bottom:none}
.rk-tr:hover{background:#f8fafc}
.rk-tr--weekend{background:rgba(248,250,252,.5)}
.rk-table tbody td{padding:.75rem 1rem;vertical-align:middle}
.rk-table tbody td:first-child{padding-left:1.5rem}
.rk-table tbody td:last-child{padding-right:1.5rem;text-align:center}

/* DATE CELL */
.rk-date-cell{display:flex;flex-direction:column;gap:.05rem}
.rk-date-day{font-size:1.1rem;font-weight:800;color:var(--navy);line-height:1}
.rk-date-month{font-size:.64rem;color:var(--text-3);font-weight:500}
.rk-date-dow{font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3)}
.rk-date-dow--weekend{color:#dc2626}
.rk-date-range{display:flex;flex-direction:column;gap:.1rem}
.rk-date-range-start,.rk-date-range-end{font-size:.72rem;font-weight:600;color:var(--navy)}
.rk-date-range-sep{color:var(--text-3);font-size:.7rem}

/* NAMA CELL */
.rk-nama-cell{display:flex;align-items:center;gap:.65rem}
.rk-avatar{width:30px;height:30px;border-radius:50%;background:linear-gradient(135deg,var(--navy-2),var(--navy-3));color:#fff;font-size:.72rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
.rk-nama{font-weight:600;color:var(--navy)}
.rk-join-date{font-size:.58rem;color:var(--text-3);margin-top:.1rem;display:flex;align-items:center;gap:.25rem;font-weight:500}

/* BADGES */
.rk-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .6rem;border-radius:20px;font-size:.67rem;font-weight:700;white-space:nowrap}
.rk-badge--green{background:#dcfce7;color:#15803d}
.rk-badge--amber{background:#fef3c7;color:#92400e}
.rk-badge--red  {background:#fee2e2;color:#991b1b}
.rk-badge--indigo{background:#e0e7ff;color:#3730a3}

/* MODE */
.rk-mode{display:inline-flex;align-items:center;gap:.32rem;padding:.2rem .55rem;border-radius:var(--r-sm);font-size:.67rem;font-weight:700}
.rk-mode--wfo{background:#dbeafe;color:#1e40af}
.rk-mode--wfh{background:#ccfbf1;color:#065f46}
.rk-mode--wfa{background:#f5f3ff;color:#6d28d9}
.rk-keterangan{font-size:.72rem;color:var(--text-2);cursor:help}

/* JAM + FOTO CELL */
.rk-jam-foto-cell{display:flex;flex-direction:column;gap:.35rem}
.rk-jam-row{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.rk-time{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .52rem;border-radius:var(--r-sm);font-size:.72rem;font-weight:600}
.rk-time--in {background:#f0fdf4;color:#166534}
.rk-time--out{background:#eff6ff;color:#1e40af}

/* VIA BADGE (QR / Web) */
.rk-via-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .45rem;border-radius:20px;font-size:.58rem;font-weight:700;letter-spacing:.02em}
.rk-via-qr {background:#fef9c3;color:#854d0e;border:1px solid #fde68a}
.rk-via-web{background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe}

/* FOTO THUMBNAIL */
.rk-thumb-wrap{position:relative;width:48px;height:48px;border-radius:var(--r-sm);overflow:hidden;cursor:zoom-in;flex-shrink:0;box-shadow:0 1px 4px rgba(0,0,0,.12);border:2px solid var(--slate-2);transition:border-color .2s}
.rk-thumb-wrap:hover{border-color:var(--gold)}
.rk-thumb{width:100%;height:100%;object-fit:cover;display:block}
.rk-thumb-overlay{position:absolute;inset:0;background:rgba(14,30,61,.45);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .18s}
.rk-thumb-wrap:hover .rk-thumb-overlay{opacity:1}
.rk-thumb-overlay svg{stroke:#fff}
.rk-thumb-error,.rk-thumb-na{display:flex;align-items:center;justify-content:center;width:48px;height:48px;background:#f1f5f9;color:var(--text-3);font-size:.55rem;border-radius:var(--r-sm)}

/* QR BADGE THUMB (no photo) */
.rk-qr-badge-thumb{display:flex;flex-direction:column;align-items:center;gap:.15rem;padding:.35rem .55rem;background:#fef9c3;border:1px dashed #fde68a;border-radius:var(--r-sm);color:#854d0e;font-size:.55rem;font-weight:700;cursor:default}
.rk-qr-badge-thumb svg{opacity:.8}

/* BUKTI BUTTON */
.rk-bukti-thumb{display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .65rem;background:#e0e7ff;border:1px solid #c7d2fe;border-radius:var(--r-sm);color:#4338ca;font-size:.67rem;font-weight:700;cursor:pointer;transition:all .2s}
.rk-bukti-thumb:hover{background:#c7d2fe;color:#3730a3}

/* STATUS */
.rk-status{display:inline-flex;align-items:center;gap:.28rem;padding:.19rem .5rem;border-radius:20px;font-size:.63rem;font-weight:700}
.rk-status--ontime{background:#f0fdf4;color:#166534}
.rk-status--late  {background:#fef3c7;color:#92400e}
.rk-status--early {background:#eff6ff;color:#1e40af}
td .rk-status{display:flex;width:fit-content} /* stack vertically */

/* DETAIL BUTTON */
.rk-detail-btn{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:var(--r-sm);background:var(--slate-lt);border:1.5px solid var(--slate-2);color:var(--slate);cursor:pointer;transition:all .2s}
.rk-detail-btn:hover{background:var(--gold-lt);border-color:var(--gold);color:var(--gold-dk)}
.rk-dash{color:var(--text-3);font-size:.75rem}

/* EMPTY */
.rk-empty{display:flex;flex-direction:column;align-items:center;padding:4rem 2rem;gap:.75rem}
.rk-empty-icon{width:64px;height:64px;border-radius:var(--r-xl);background:var(--slate-lt);display:flex;align-items:center;justify-content:center}
.rk-empty-icon svg{width:28px;height:28px;color:var(--text-3)}
.rk-empty-title{font-size:.9rem;font-weight:700;color:var(--text-2)}
.rk-empty-sub{font-size:.75rem;color:var(--text-3)}

/* ════════════════ MODAL ════════════════ */
.rkm-backdrop{position:fixed;inset:0;background:rgba(14,30,61,.72);backdrop-filter:blur(4px);z-index:9000;display:none;align-items:center;justify-content:center;padding:1rem}
.rkm-backdrop.active{display:flex;animation:fadeIn .18s ease}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.rkm-box{background:var(--white);border-radius:20px;width:100%;max-width:620px;max-height:90vh;overflow-y:auto;box-shadow:0 24px 80px rgba(14,30,61,.28);position:relative;animation:slideUp .22s cubic-bezier(.34,1.2,.64,1)}
@keyframes slideUp{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none}}
.rkm-close{position:absolute;top:1rem;right:1rem;width:32px;height:32px;border-radius:50%;background:rgba(0,0,0,.06);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:var(--text-2);z-index:2;transition:all .15s}.rkm-close:hover{background:rgba(0,0,0,.12);color:var(--text)}

/* Modal head */
.rkm-head{display:flex;align-items:center;gap:1rem;padding:1.5rem 1.5rem 1.1rem;border-bottom:1px solid var(--slate-2)}
.rkm-head-avatar{width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--navy-2),var(--navy-3));color:#fff;font-size:1.2rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.rkm-head-info{flex:1}
.rkm-head-nama{font-size:1rem;font-weight:700;color:var(--navy)}
.rkm-head-sub{font-size:.7rem;color:var(--text-3);margin-top:.15rem}
.rkm-head-badge{flex-shrink:0}

/* Modal section */
.rkm-section{padding:.85rem 1.5rem}
.rkm-section-label{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.1em;color:var(--text-3);margin-bottom:.5rem}
.rkm-tanggal{font-size:.9rem;font-weight:700;color:var(--navy);border-bottom:1px solid var(--slate-2)}
.rkm-keterangan-wrap{border-bottom:1px solid var(--slate-2)}
.rkm-ket-text{font-size:.82rem;color:var(--text);line-height:1.55}
.rkm-catatan{font-size:.72rem;color:var(--slate);margin-top:.5rem;padding:.5rem .75rem;background:#f8fafc;border-radius:var(--r-sm);border-left:3px solid var(--gold)}

/* Foto grid */
.rkm-foto-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;padding:1rem 1.5rem;border-bottom:1px solid var(--slate-2)}
.rkm-foto-grid:empty{display:none}
.rkm-foto-card{display:flex;flex-direction:column;gap:.5rem}
.rkm-foto-label{font-size:.6rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3);display:flex;align-items:center;gap:.35rem}
.rkm-foto-img-wrap{position:relative;border-radius:var(--r-md);overflow:hidden;aspect-ratio:3/4;background:var(--slate-lt);cursor:zoom-in;box-shadow:var(--sh-sm);border:2px solid var(--slate-2);transition:border-color .2s}
.rkm-foto-img-wrap:hover{border-color:var(--gold)}
.rkm-foto-img{width:100%;height:100%;object-fit:cover;display:block}
.rkm-foto-overlay{position:absolute;inset:0;background:rgba(14,30,61,.3);display:flex;align-items:center;justify-content:center;opacity:0;transition:opacity .18s}
.rkm-foto-img-wrap:hover .rkm-foto-overlay{opacity:1}
.rkm-foto-overlay svg{stroke:#fff;width:24px;height:24px}
.rkm-qr-placeholder{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;aspect-ratio:3/4;background:#fef9c3;border:2px dashed #fde68a;border-radius:var(--r-md);color:#854d0e}
.rkm-qr-placeholder svg{width:36px;height:36px;opacity:.7}
.rkm-qr-placeholder span{font-size:.68rem;font-weight:700;text-align:center}

/* Bukti */
.rkm-bukti-area{padding:0 1.5rem 1rem;display:flex;justify-content:center}
.rkm-bukti-img{max-width:100%;max-height:none;width:100%;height:auto;object-fit:contain;border-radius:var(--r-md);box-shadow:var(--sh-md);cursor:zoom-in;display:block}
.rkm-bukti-pdf{display:inline-flex;align-items:center;gap:.5rem;padding:.65rem 1.2rem;background:#e0e7ff;border:1px solid #c7d2fe;border-radius:var(--r-md);color:#4338ca;font-size:.78rem;font-weight:700;text-decoration:none}

/* Info grid */
.rkm-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:.5rem .75rem;padding:1rem 1.5rem 1.5rem}
.rkm-info-item{display:flex;flex-direction:column;gap:.18rem}
.rkm-info-label{font-size:.58rem;font-weight:800;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3)}
.rkm-info-val{font-size:.78rem;font-weight:600;color:var(--text)}

/* ════════════════ LIGHTBOX ════════════════ */
.rklb-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:9500;display:none;align-items:center;justify-content:center}
.rklb-backdrop.active{display:flex;animation:fadeIn .15s ease}
.rklb-img{max-width:90vw;max-height:88vh;object-fit:contain;border-radius:var(--r-md);box-shadow:0 0 80px rgba(0,0,0,.6)}
.rklb-close{position:fixed;top:1.25rem;right:1.25rem;width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.1);border:1.5px solid rgba(255,255,255,.2);color:#fff;font-size:1.4rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;z-index:2}.rklb-close:hover{background:rgba(255,255,255,.2)}
.rklb-cap{position:fixed;bottom:1.5rem;left:50%;transform:translateX(-50%);color:rgba(255,255,255,.7);font-size:.72rem;font-weight:500;white-space:nowrap}
.rklb-nav{position:fixed;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.1);border:1.5px solid rgba(255,255,255,.2);color:#fff;font-size:2rem;width:48px;height:56px;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .15s;z-index:2}.rklb-nav:hover{background:rgba(255,255,255,.25)}
.rklb-prev{left:1.25rem}.rklb-next{right:1.25rem}
.rklb-nav.hidden{display:none}

/* DRIVER.JS TOUR */
.rk-popover.driver-popover{background:var(--white)!important;border-radius:14px!important;box-shadow:0 24px 64px rgba(14,30,61,.22),0 0 0 1px rgba(201,168,76,.2)!important;padding:0!important;max-width:310px!important;overflow:hidden!important;font-family:var(--font-body)!important}
.rk-popover .driver-popover-title{font-size:.82rem!important;font-weight:700!important;color:var(--white)!important;background:linear-gradient(135deg,var(--navy),var(--navy-3))!important;padding:.85rem 1.1rem!important;border-bottom:2px solid var(--gold)!important;margin:0!important}
.rk-popover .driver-popover-description{font-size:.75rem!important;color:var(--text-2)!important;line-height:1.65!important;padding:.9rem 1.1rem .6rem!important}
.rk-popover .driver-popover-navigation-btns{display:flex!important;gap:.4rem!important;padding:.7rem 1.1rem .9rem!important;border-top:1px solid var(--slate-2)!important;margin-top:.5rem!important}
.rk-popover .driver-popover-next-btn,.rk-popover .driver-popover-done-btn{background:var(--gold)!important;border:none!important;color:var(--navy)!important;border-radius:var(--r-sm)!important;padding:.34rem .85rem!important;font-size:.7rem!important;font-weight:700!important;cursor:pointer!important;box-shadow:none!important;text-shadow:none!important;min-width:unset!important;width:auto!important;height:auto!important}
.rk-popover .driver-popover-prev-btn{background:var(--slate-lt)!important;border:1px solid var(--slate-2)!important;color:var(--slate)!important;border-radius:var(--r-sm)!important;padding:.34rem .85rem!important;font-size:.7rem!important;font-weight:600!important;cursor:pointer!important;box-shadow:none!important;text-shadow:none!important;min-width:unset!important;width:auto!important;height:auto!important}
.rk-popover .driver-popover-close-btn{color:rgba(255,255,255,.6)!important;background:none!important;border:none!important;cursor:pointer!important;font-size:1rem!important;position:absolute!important;top:.65rem!important;right:.85rem!important;width:auto!important;height:auto!important}

/* RESPONSIVE */
@media(max-width:1280px){.rk-stats-grid{grid-template-columns:repeat(5,1fr)}}
@media(max-width:1024px){.rk-filter-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:900px){.rk-stats-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:768px){.rk-page{padding:1rem 1rem 3rem}.rk-hero-inner{padding:1.5rem 1.25rem}.rk-hero-title{font-size:1.5rem}.rk-filter-grid{grid-template-columns:repeat(2,1fr)}.rkm-foto-grid{grid-template-columns:1fr}}
@media(max-width:580px){.rk-stats-grid{grid-template-columns:repeat(2,1fr)}.rk-filter-grid{grid-template-columns:1fr}}
/* ── Pagination ───────────────────────────────────────── */
.rk-pagination{display:flex;align-items:center;justify-content:center;gap:.35rem;padding:1.1rem 1.5rem;flex-wrap:wrap}
.rk-page-btn{min-width:34px;height:34px;padding:0 .55rem;border-radius:8px;border:1.5px solid var(--border);background:var(--surface);color:var(--text-2);font-size:.78rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;transition:all .15s;line-height:1}
.rk-page-btn:hover{border-color:var(--gold);color:var(--gold);background:#fdf8ee}
.rk-page-btn.active{background:var(--navy);border-color:var(--navy);color:#fff;pointer-events:none}
.rk-page-btn:disabled{opacity:.35;cursor:not-allowed;pointer-events:none}
.rk-page-ellipsis{color:var(--text-3);font-size:.8rem;padding:0 .2rem;line-height:34px}
.rk-page-info{font-size:.72rem;color:var(--text-3);margin-left:.5rem}
</style>

<script>
// ══════════════════════════════════════════
//  MODAL
// ══════════════════════════════════════════
const BULAN = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
const HARI  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];

// Helper: konversi nama file foto ke URL proxy aman
const _FOTO_BASE = '<?php
    $parts = explode('/', trim($_SERVER['SCRIPT_NAME'], '/'));
    echo '/' . $parts[0];
?>';
function fotoProxyUrl(filename) {
    if (!filename) return '';
    return _FOTO_BASE + '/foto.php?type=selfie&file=' + encodeURIComponent(filename.split('/').pop());
}
function buktiProxyUrl(filename) {
    if (!filename) return '';
    return _FOTO_BASE + '/foto.php?type=selfie&file=' + encodeURIComponent(filename.split('/').pop());
}

let lbImages = [], lbIdx = 0;

function formatTgl(str) {
    if (!str) return '—';
    const d = new Date(str);
    return HARI[d.getDay()] + ', ' + String(d.getDate()).padStart(2,'0') + ' ' + BULAN[d.getMonth()+1] + ' ' + d.getFullYear();
}

function jenisColor(j) {
    const map = {hadir:'#15803d',izin:'#92400e',sakit:'#991b1b',cuti:'#3730a3'};
    return map[j] || '#475569';
}
function jenisBg(j) {
    const map = {hadir:'#dcfce7',izin:'#fef3c7',sakit:'#fee2e2',cuti:'#e0e7ff'};
    return map[j] || '#f1f5f9';
}
function jenisLabel(j) {
    const map = {hadir:'✅ Hadir',izin:'📋 Izin',sakit:'🏥 Sakit',cuti:'📅 Cuti'};
    return map[j] || j;
}

function openModal(data, focus) {
    const modal = document.getElementById('rkModal');
    lbImages = [];

    // Head
    document.getElementById('mAvatar').textContent = (data.nama||'').charAt(0).toUpperCase();
    document.getElementById('mNama').textContent = data.nama || '—';
    document.getElementById('mSub').textContent = [data.jabatan, data.unit_kerja].filter(Boolean).join(' · ') || 'Pegawai';
    const badgeEl = document.getElementById('mJenisBadge');
    badgeEl.innerHTML = `<span style="display:inline-flex;align-items:center;gap:.3rem;padding:.28rem .75rem;border-radius:20px;font-size:.72rem;font-weight:700;background:${jenisBg(data.jenis)};color:${jenisColor(data.jenis)}">${jenisLabel(data.jenis)}</span>`;

    // Tanggal
    const tglEl = document.getElementById('mTanggal');
    if (data.jenis === 'cuti' && data.tanggal_mulai && data.tanggal_selesai) {
        tglEl.innerHTML = `<div class="rkm-section-label">Periode Cuti</div><div style="font-size:.88rem;font-weight:700;color:var(--navy)">${formatTgl(data.tanggal_mulai)} — ${formatTgl(data.tanggal_selesai)}</div>`;
    } else {
        tglEl.innerHTML = `<div class="rkm-section-label">Tanggal</div><div style="font-size:.88rem;font-weight:700;color:var(--navy)">${formatTgl(data.tanggal)}</div>`;
    }

    // Keterangan
    const ketWrap = document.getElementById('mKetWrap');
    if (data.keterangan) {
        ketWrap.style.display = '';
        document.getElementById('mKetText').textContent = data.keterangan;
        const catEl = document.getElementById('mCatatan');
        if (data.catatan) {
            catEl.style.display = '';
            catEl.textContent = '📌 Catatan admin: ' + data.catatan;
        } else catEl.style.display = 'none';
    } else ketWrap.style.display = 'none';

    // Foto grid
    const fotoGrid = document.getElementById('mFotoGrid');
    fotoGrid.innerHTML = '';

    if (data.jenis === 'hadir') {
        // Foto Masuk
        const cardMasuk = makeFotoCard(
            'Foto Masuk',
            data.foto_masuk,
            data.mode_login,
            data.jam_masuk,
            data.status_masuk,
            'in'
        );
        fotoGrid.appendChild(cardMasuk);

        // Foto Pulang
        const cardPulang = makeFotoCard(
            'Foto Pulang',
            data.foto_pulang,
            data.mode_login_pulang,
            data.jam_pulang,
            data.status_pulang,
            'out'
        );
        fotoGrid.appendChild(cardPulang);

        // Collect untuk lightbox
        if (data.foto_masuk) lbImages.push({src:fotoProxyUrl(data.foto_masuk), cap:'Foto Masuk — '+data.nama});
        if (data.foto_pulang) lbImages.push({src:fotoProxyUrl(data.foto_pulang), cap:'Foto Pulang — '+data.nama});

        // Focus ke foto yang diklik
        if (focus === 'masuk' && data.foto_masuk) {
            lbIdx = 0;
        } else if (focus === 'pulang' && data.foto_pulang) {
            lbIdx = data.foto_masuk ? 1 : 0;
        }
    }

    // Bukti izin/cuti
    const buktiWrap = document.getElementById('mBuktiWrap');
    const buktiArea = document.getElementById('mBuktiArea');
    if (data.bukti && data.jenis !== 'hadir') {
        buktiWrap.style.display = '';
        const ext = data.bukti.split('.').pop().toLowerCase();
        const isPdf = (ext === 'pdf');
        const buktiPath = buktiProxyUrl(data.bukti);

        if (isPdf) {
            buktiArea.innerHTML = `<a href="${buktiPath}" target="_blank" class="rkm-bukti-pdf"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>Buka Bukti PDF</a>`;
        } else {
            buktiArea.innerHTML = `<img src="${buktiPath}" class="rkm-bukti-img" alt="Bukti" onclick="openLightbox([{src:'${buktiPath}',cap:'Bukti ${data.jenis} — ${data.nama}'}], 0)">`;
        }
    } else buktiWrap.style.display = 'none';

    // Info grid
    const infoGrid = document.getElementById('mInfoGrid');
    const infos = [];
    if (data.jenis === 'hadir') {
        if (data.mode) infos.push(['Mode Kerja', data.mode.toUpperCase()]);
        if (data.alamat_wfh) infos.push(['Alamat WFH', data.alamat_wfh]);
        if (data.jam_masuk) infos.push(['Jam Masuk', data.jam_masuk.slice(0,5)]);
        if (data.jam_pulang) infos.push(['Jam Pulang', data.jam_pulang.slice(0,5)]);
        if (data.status_masuk) infos.push(['Status Masuk', data.status_masuk.charAt(0).toUpperCase()+data.status_masuk.slice(1)]);
        if (data.status_pulang) infos.push(['Status Pulang', data.status_pulang.charAt(0).toUpperCase()+data.status_pulang.slice(1)]);
        if (data.lat_masuk) infos.push(['GPS Masuk', parseFloat(data.lat_masuk).toFixed(6)+', '+parseFloat(data.lng_masuk).toFixed(6)]);
        if (data.lat_pulang) infos.push(['GPS Pulang', parseFloat(data.lat_pulang).toFixed(6)+', '+parseFloat(data.lng_pulang).toFixed(6)]);
    }
    infoGrid.innerHTML = infos.map(([l,v]) => `<div class="rkm-info-item"><div class="rkm-info-label">${l}</div><div class="rkm-info-val">${v}</div></div>`).join('');
    if (infos.length === 0) infoGrid.style.display = 'none';
    else infoGrid.style.display = '';

    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}

function makeFotoCard(label, foto, via, jam, status, dir) {
    const card = document.createElement('div');
    card.className = 'rkm-foto-card';

    const dirIcon = dir === 'in'
        ? '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="22 12 18 8 14 12"/><line x1="18" y1="8" x2="18" y2="16"/><circle cx="9" cy="12" r="7"/></svg>'
        : '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="2 12 6 16 10 12"/><line x1="6" y1="16" x2="6" y2="8"/><circle cx="15" cy="12" r="7"/></svg>';

    let labelHtml = `<div class="rkm-foto-label">${dirIcon} ${label}`;
    if (jam) labelHtml += ` <span style="font-size:.65rem;font-weight:500;color:var(--text-3);margin-left:.25rem">${jam.slice(0,5)}</span>`;
    if (via === 'qr') labelHtml += ` <span style="font-size:.55rem;font-weight:700;background:#fef9c3;color:#854d0e;border:1px solid #fde68a;border-radius:10px;padding:0 .4rem">QR</span>`;
    else if (via === 'web') labelHtml += ` <span style="font-size:.55rem;font-weight:700;background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;border-radius:10px;padding:0 .4rem">Web</span>`;
    labelHtml += '</div>';
    card.innerHTML = labelHtml;

    const fotoIdx = lbImages.length; // index SEBELUM push

    if (foto) {
        const wrap = document.createElement('div');
        wrap.className = 'rkm-foto-img-wrap';
        wrap.onclick = () => openLightbox(lbImages, fotoIdx);
        wrap.innerHTML = `<img src="${fotoProxyUrl(foto)}" class="rkm-foto-img" alt="${label}" loading="lazy"><div class="rkm-foto-overlay"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg></div>`;
        card.appendChild(wrap);
    } else if (via === 'qr') {
        card.innerHTML += `<div class="rkm-qr-placeholder"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="5" y="5" width="3" height="3"/><rect x="16" y="5" width="3" height="3"/><rect x="5" y="16" width="3" height="3"/><path d="M14 14h3v3h3v3M17 14v3M14 17h3"/></svg><span>Absen via QR<br>Tidak ada foto</span></div>`;
    } else if (!jam) {
        card.innerHTML += `<div class="rkm-qr-placeholder" style="background:#f8fafc;border-color:#e2e8f0;color:var(--text-3)"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M18 6L6 18M6 6l12 12"/></svg><span>Belum ada data</span></div>`;
    } else {
        card.innerHTML += `<div class="rkm-qr-placeholder" style="background:#f8fafc;border-color:#e2e8f0;color:var(--text-3)"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 3v18"/></svg><span>Foto tidak tersedia</span></div>`;
    }

    // Status pill di bawah foto
    if (status) {
        const colors = {
            'tepat waktu': '#166534,#f0fdf4',
            'terlambat':   '#92400e,#fef3c7',
            'pulang tepat':'#166534,#f0fdf4',
            'pulang awal': '#1e40af,#eff6ff',
        };
        const [col, bg] = (colors[status]||'#475569,#f1f5f9').split(',');
        card.innerHTML += `<div style="font-size:.62rem;font-weight:700;padding:.22rem .6rem;border-radius:20px;background:${bg};color:${col};width:fit-content;margin-top:.25rem">${status.charAt(0).toUpperCase()+status.slice(1)}</div>`;
    }

    return card;
}

function closeModal(e) {
    if (e && e.target !== document.getElementById('rkModal')) return;
    document.getElementById('rkModal').classList.remove('active');
    document.body.style.overflow = '';
}

// ══════════════════════════════════════════
//  LIGHTBOX
// ══════════════════════════════════════════
function openLightbox(images, idx) {
    lbImages = images;
    lbIdx    = idx;
    renderLightbox();
    document.getElementById('rkLightbox').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function renderLightbox() {
    const img = document.getElementById('rkLbImg');
    const cap = document.getElementById('rkLbCap');
    const prev = document.getElementById('lbPrev');
    const next = document.getElementById('lbNext');
    img.src = lbImages[lbIdx].src;
    cap.textContent = lbImages[lbIdx].cap + (lbImages.length > 1 ? ` (${lbIdx+1}/${lbImages.length})` : '');
    prev.classList.toggle('hidden', lbIdx === 0);
    next.classList.toggle('hidden', lbIdx === lbImages.length - 1);
}

function lightboxNav(dir) {
    lbIdx = Math.max(0, Math.min(lbImages.length-1, lbIdx+dir));
    renderLightbox();
}

function closeLightbox() {
    document.getElementById('rkLightbox').classList.remove('active');
}

// Keyboard
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        closeLightbox();
        document.getElementById('rkModal').classList.remove('active');
        document.body.style.overflow = '';
    }
    if (document.getElementById('rkLightbox').classList.contains('active')) {
        if (e.key === 'ArrowLeft')  lightboxNav(-1);
        if (e.key === 'ArrowRight') lightboxNav(1);
    }
});

// ══════════════════════════════════════════
//  TOUR
// ══════════════════════════════════════════
const TOUR_KEY = 'rekap_absensi_tour_v4';
function loadDriver(cb) {
    if (window.driver && window.driver.js) { cb(); return; }
    const link = document.createElement('link'); link.rel='stylesheet'; link.href='https://cdnjs.cloudflare.com/ajax/libs/driver.js/1.3.1/driver.css'; document.head.appendChild(link);
    const sc = document.createElement('script'); sc.src='https://cdnjs.cloudflare.com/ajax/libs/driver.js/1.3.1/driver.js.iife.js'; sc.onload=()=>setTimeout(cb,100); document.head.appendChild(sc);
}
function startTour() {
    loadDriver(() => {
        const drv = window.driver.js.driver({
            popoverClass:'rk-popover', showProgress:true, progressText:'{{current}} / {{total}}',
            nextBtnText:'Lanjut →', prevBtnText:'← Kembali', doneBtnText:'Selesai',
            allowClose:true, overlayColor:'rgba(14,30,61,.72)', smoothScroll:true, animate:true,
            onDestroyStarted:() => { localStorage.setItem(TOUR_KEY,'1'); drv.destroy(); },
            steps:[
                {popover:{title:'Rekap Absensi',description:'Dashboard laporan kehadiran lengkap — kini dilengkapi <strong>foto selfie</strong>, <strong>ikon QR</strong>, dan <strong>detail pop-up</strong> per entri.',side:'over',align:'center'}},
                {element:'#tour-header',popover:{title:'Header & Periode',description:'Periode aktif dan tombol Export PDF ada di sini.',side:'bottom',align:'start'}},
                {element:'#tour-filter',popover:{title:'Panel Filter',description:'Saring per pegawai, bulan, tahun, atau rentang tanggal bebas.',side:'bottom',align:'center'}},
                {element:'#tour-stats',popover:{title:'Statistik Ringkas',description:'9 kartu statistik termasuk Alpa yang dihitung otomatis.',side:'bottom',align:'center'}},
                {element:'#tour-table',popover:{title:'Tabel dengan Foto & QR',description:'Kolom Masuk & Pulang kini menampilkan <strong>foto thumbnail</strong> (klik = besar), atau <strong>ikon QR</strong> jika absen via scanner. Klik tombol 🔍 untuk detail lengkap.',side:'top',align:'center'}},
                {element:'#tour-export',popover:{title:'Export PDF',description:'Unduh laporan dalam format PDF.',side:'bottom',align:'end'}},
            ]
        });
        drv.drive();
    });
}
if (!localStorage.getItem(TOUR_KEY)) { window.addEventListener('load', () => setTimeout(startTour, 900)); }
</script>

<script>
// ══════════════════════════════════════════
//  PAGINATION — 10 baris per halaman
// ══════════════════════════════════════════
(function() {
    const PER_PAGE = 10;
    let currentPage = 1;

    const rows = Array.from(document.querySelectorAll('tr[data-row]'));
    const paginationEl = document.getElementById('rkPagination');
    if (!rows.length || !paginationEl) return;

    const totalPages = () => Math.ceil(rows.length / PER_PAGE);

    function showPage(page) {
        currentPage = Math.max(1, Math.min(page, totalPages()));
        const start = (currentPage - 1) * PER_PAGE;
        const end   = start + PER_PAGE;
        rows.forEach((tr, i) => {
            tr.style.display = (i >= start && i < end) ? '' : 'none';
        });
        renderPagination();
        // Scroll ke atas tabel
        document.querySelector('.rk-table-scroll')?.scrollIntoView({behavior:'smooth', block:'nearest'});
    }

    function renderPagination() {
        const total = totalPages();
        if (total <= 1) { paginationEl.innerHTML = ''; return; }

        let html = '';

        // Tombol Sebelumnya
        html += `<button class="rk-page-btn" onclick="rkGoPage(${currentPage-1})" ${currentPage===1?'disabled':''}>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="15 18 9 12 15 6"/></svg>
        </button>`;

        // Nomor halaman dengan ellipsis
        const pages = getPageNumbers(currentPage, total);
        pages.forEach(p => {
            if (p === '...') {
                html += `<span class="rk-page-ellipsis">···</span>`;
            } else {
                html += `<button class="rk-page-btn ${p===currentPage?'active':''}" onclick="rkGoPage(${p})">${p}</button>`;
            }
        });

        // Tombol Berikutnya
        html += `<button class="rk-page-btn" onclick="rkGoPage(${currentPage+1})" ${currentPage===total?'disabled':''}>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
        </button>`;

        // Info halaman
        const start = (currentPage-1)*PER_PAGE+1;
        const end   = Math.min(currentPage*PER_PAGE, rows.length);
        html += `<span class="rk-page-info">${start}–${end} dari ${rows.length} entri</span>`;

        paginationEl.innerHTML = html;
    }

    function getPageNumbers(current, total) {
        // Selalu tampilkan: 1, ..., current-1, current, current+1, ..., total
        const pages = [];
        if (total <= 7) {
            for (let i = 1; i <= total; i++) pages.push(i);
            return pages;
        }
        pages.push(1);
        if (current > 3) pages.push('...');
        for (let i = Math.max(2, current-1); i <= Math.min(total-1, current+1); i++) pages.push(i);
        if (current < total - 2) pages.push('...');
        pages.push(total);
        return pages;
    }

    // Expose ke global agar onclick bisa memanggil
    window.rkGoPage = function(page) { showPage(page); };

    // Inisialisasi halaman pertama
    showPage(1);
})();

/* ── Sync bulan/tahun → tgl_awal/tgl_akhir ── */
(function() {
    const selBulan  = document.getElementById('selBulan');
    const selTahun  = document.getElementById('selTahun');
    const inpAwal   = document.getElementById('inpTglAwal');
    const inpAkhir  = document.getElementById('inpTglAkhir');
    if (!selBulan || !selTahun || !inpAwal || !inpAkhir) return;

    function syncTanggal() {
        const bulan = parseInt(selBulan.value);
        const tahun = parseInt(selTahun.value);
        if (!bulan || !tahun) return;

        // Hitung hari pertama dan terakhir bulan
        const tglAwal  = new Date(tahun, bulan - 1, 1);
        const tglAkhir = new Date(tahun, bulan, 0); // hari-0 bulan berikutnya = hari terakhir bulan ini

        const pad = n => String(n).padStart(2, '0');
        const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;

        inpAwal.value  = fmt(tglAwal);
        inpAkhir.value = fmt(tglAkhir);
    }

    selBulan.addEventListener('change', syncTanggal);
    selTahun.addEventListener('change', syncTanggal);
})();

/* ── Modal Alpa ── */
function openAlpaModal() {
    document.getElementById('modalAlpa').classList.add('active');
    document.body.style.overflow = 'hidden';
    document.getElementById('alpaSearch').value = '';
    filterAlpaTable();
}
function closeAlpaModal(e) {
    if (e && e.target !== document.getElementById('modalAlpa')) return;
    document.getElementById('modalAlpa').classList.remove('active');
    document.body.style.overflow = '';
}
function filterAlpaTable() {
    const q = document.getElementById('alpaSearch').value.toLowerCase().trim();
    const rows = document.querySelectorAll('#alpaTable .alpa-tr');
    let found = 0;
    rows.forEach(tr => {
        const nama = tr.dataset.nama || '';
        const show = !q || nama.includes(q);
        tr.style.display = show ? '' : 'none';
        if (show) found++;
    });
    document.getElementById('alpaNoResult').style.display = found === 0 ? 'block' : 'none';
}
// Tutup modal alpa dengan Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.getElementById('modalAlpa')?.classList.remove('active');
        document.body.style.overflow = '';
    }
});

</script>

<?php include '../templates/footer.php'; ?> 