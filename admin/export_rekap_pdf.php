<?php
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/helper.php'; // ← Pastikan helper.php di-include
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_admin()) {
    header('Location: dashboard.php');
    exit();
}

// Filter
$pegawai_id = $_GET['pegawai_id'] ?? null;
$tahun      = $_GET['tahun']      ?? date('Y');
$bulan      = $_GET['bulan']      ?? null;
$tgl_awal   = $_GET['tgl_awal']   ?? null;
$tgl_akhir  = $_GET['tgl_akhir']  ?? null;

$bulanList = [
    1=>'Januari',2=>'Februari',3=>'Maret',4=>'April',
    5=>'Mei',6=>'Juni',7=>'Juli',8=>'Agustus',
    9=>'September',10=>'Oktober',11=>'November',12=>'Desember'
];

// Nama pegawai jika filter per orang
$namaPegawai = 'Semua Pegawai';
if ($pegawai_id) {
    $stmt = $pdo->prepare("SELECT nama FROM users WHERE id = ?");
    $stmt->execute([$pegawai_id]);
    $namaPegawai = $stmt->fetchColumn() ?: 'Semua Pegawai';
}

// Label periode
if ($tgl_awal && $tgl_akhir) {
    $periodLabel = date('d/m/Y', strtotime($tgl_awal)) . ' – ' . date('d/m/Y', strtotime($tgl_akhir));
} elseif ($bulan) {
    $periodLabel = $bulanList[(int)$bulan] . ' ' . $tahun;
} else {
    $periodLabel = 'Tahun ' . $tahun;
}

// ── Tentukan rentang hitung (sama seperti rekap.php) ──
$hari_ini = date('Y-m-d');
if ($tgl_awal && $tgl_akhir) {
    $range_awal  = $tgl_awal;
    $range_akhir = min($tgl_akhir, $hari_ini);
} elseif ($bulan) {
    $range_awal  = "{$tahun}-{$bulan}-01";
    $range_akhir = min(date('Y-m-t', strtotime($range_awal)), $hari_ini);
} else {
    $range_awal  = "{$tahun}-01-01";
    $range_akhir = min("{$tahun}-12-31", $hari_ini);
}
if ($range_awal > $range_akhir) {
    $range_awal = $range_akhir = $hari_ini;
}

// ── Query attendance ──
$conditions = []; $params = [];
if ($pegawai_id) { $conditions[] = "a.user_id = ?"; $params[] = $pegawai_id; }
if ($tgl_awal && $tgl_akhir) {
    $conditions[] = "a.tanggal BETWEEN ? AND ?";
    $params[] = $tgl_awal; $params[] = $tgl_akhir;
} elseif ($bulan) {
    $conditions[] = "YEAR(a.tanggal) = ? AND MONTH(a.tanggal) = ?";
    $params[] = $tahun; $params[] = $bulan;
} else {
    $conditions[] = "YEAR(a.tanggal) = ?";
    $params[] = $tahun;
}
$where = !empty($conditions) ? "WHERE " . implode(" AND ", $conditions) : "";

$stmt = $pdo->prepare("
    SELECT u.nama, a.tanggal, a.jam_masuk, a.jam_pulang, a.status_masuk, a.status_pulang, 'hadir' as jenis,
           a.mode, a.alamat_wfh
    FROM attendance a
    JOIN users u ON u.id = a.user_id
    $where
    ORDER BY a.tanggal ASC, u.nama
");
$stmt->execute($params);
$hadirData = $stmt->fetchAll();

// ── Query izin ──
$condIzin = []; $paramsIzin = [];
if ($pegawai_id) { $condIzin[] = "i.user_id = ?"; $paramsIzin[] = $pegawai_id; }
if ($tgl_awal && $tgl_akhir) {
    $condIzin[] = "i.tanggal BETWEEN ? AND ?";
    $paramsIzin[] = $tgl_awal; $paramsIzin[] = $tgl_akhir;
} elseif ($bulan) {
    $condIzin[] = "YEAR(i.tanggal) = ? AND MONTH(i.tanggal) = ?";
    $paramsIzin[] = $tahun; $paramsIzin[] = $bulan;
} else {
    $condIzin[] = "YEAR(i.tanggal) = ?";
    $paramsIzin[] = $tahun;
}
$whereIzin = !empty($condIzin) ? "WHERE " . implode(" AND ", $condIzin) . " AND i.status = 'disetujui'" : "WHERE i.status = 'disetujui'";

$stmt = $pdo->prepare("
    SELECT u.nama, i.tanggal, NULL as jam_masuk, NULL as jam_pulang,
           NULL as status_masuk, NULL as status_pulang, i.jenis,
           NULL as mode, NULL as alamat_wfh
    FROM izin i
    JOIN users u ON u.id = i.user_id
    $whereIzin
    ORDER BY i.tanggal ASC, u.nama
");
$stmt->execute($paramsIzin);
$izinData = $stmt->fetchAll();

// ── Query cuti (untuk perhitungan alpa) ──
$condCuti = []; $paramsCuti = [];
if ($pegawai_id) { $condCuti[] = "c.user_id = ?"; $paramsCuti[] = $pegawai_id; }
if ($tgl_awal && $tgl_akhir) {
    $condCuti[] = "c.tanggal_mulai <= ? AND c.tanggal_selesai >= ?";
    $paramsCuti[] = $tgl_akhir; $paramsCuti[] = $tgl_awal;
} elseif ($bulan) {
    $condCuti[] = "(YEAR(c.tanggal_mulai) = ? AND MONTH(c.tanggal_mulai) = ?)
                   OR (YEAR(c.tanggal_selesai) = ? AND MONTH(c.tanggal_selesai) = ?)";
    $paramsCuti[] = $tahun; $paramsCuti[] = $bulan;
    $paramsCuti[] = $tahun; $paramsCuti[] = $bulan;
} else {
    $condCuti[] = "YEAR(c.tanggal_mulai) = ? OR YEAR(c.tanggal_selesai) = ?";
    $paramsCuti[] = $tahun; $paramsCuti[] = $tahun;
}
$whereCuti = "WHERE c.status = 'disetujui'" . (!empty($condCuti) ? " AND " . implode(" AND ", $condCuti) : "");

$stmt = $pdo->prepare("
    SELECT c.tanggal_mulai, c.tanggal_selesai FROM cuti c
    $whereCuti
");
$stmt->execute($paramsCuti);
$cutiData = $stmt->fetchAll();

// Gabung & urutkan
$rekap = array_merge($hadirData, $izinData);
usort($rekap, fn($a, $b) => strcmp($a['tanggal'], $b['tanggal']));

// Ringkasan dasar
$totalHadir = 0; $totalIzin = 0; $totalSakit = 0; $totalTerlambat = 0; $totalWFH = 0;
foreach ($rekap as $r) {
    if ($r['jenis'] === 'hadir')     $totalHadir++;
    elseif ($r['jenis'] === 'izin')  $totalIzin++;
    elseif ($r['jenis'] === 'sakit') $totalSakit++;
    if (($r['status_masuk'] ?? '') === 'terlambat') $totalTerlambat++;
    if ($r['mode'] === 'wfh') $totalWFH++;
}

// ════════════════════════════════════════════════════════════════════════
// HITUNG ALPA (MENGGUNAKAN FUNGSI DARI HELPER.PHP)
// ════════════════════════════════════════════════════════════════════════
$totalAlpa = 0;
if ($pegawai_id) {
    // Ambil tanggal pegawai terdaftar
    $stmt = $pdo->prepare("SELECT DATE(created_at) FROM users WHERE id = ?");
    $stmt->execute([$pegawai_id]);
    $tgl_daftar = $stmt->fetchColumn() ?? $range_awal;
    $start_hitung = max($range_awal, $tgl_daftar);
    
    if ($start_hitung <= $range_akhir) {
        $res = hitung_alpa_pegawai($pdo, (int)$pegawai_id, $start_hitung, $range_akhir);
        $totalAlpa = $res['alpa'];
    }
}
// Jika tidak ada filter pegawai, alpa = 0 (karena rekap ini per-individu)

$hariMap = ['Sun'=>'Min','Mon'=>'Sen','Tue'=>'Sel','Wed'=>'Rab','Thu'=>'Kam','Fri'=>'Jum','Sat'=>'Sab'];

// Buat back URL
$backParams = http_build_query(array_filter([
    'pegawai_id' => $pegawai_id,
    'tahun'      => $tahun,
    'bulan'      => $bulan,
    'tgl_awal'   => $tgl_awal,
    'tgl_akhir'  => $tgl_akhir,
]));
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rekap Absensi - <?= $periodLabel ?></title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family:Arial, sans-serif; font-size:11px; color:#000; background:#e5e5e5; }

        /* ── A4 ── */
        .a4-page { width:210mm; min-height:297mm; margin:20px auto; background:#fff; box-shadow:0 0 10px rgba(0,0,0,.15); padding:15mm 15mm 20mm; }
        @page { size:A4 portrait; margin:0; }
        @media print {
            html, body { background:#fff; }
            .no-print { display:none; }
            .a4-page { margin:0; box-shadow:none; padding:15mm 15mm 20mm; }
            body { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        }

        /* ── TOOLBAR ── */
        .no-print { text-align:center; padding:14px; background:#f3f4f6; border-bottom:1px solid #ddd; }
        .no-print button { background:#1a2744; color:#fff; border:none; padding:8px 24px; border-radius:5px; cursor:pointer; font-size:13px; margin:0 5px; }
        .no-print .btn-back { background:#6b7280; }

        /* ── KOP ── */
        .kop { display:flex; align-items:center; padding-bottom:8px; border-bottom:3px solid #1a2744; }
        .kop img { width:60px; height:60px; margin-right:14px; }
        .kop-text { text-align:center; flex:1; }
        .kop-text .dept     { font-size:12px; font-weight:bold; text-transform:uppercase; }
        .kop-text .instansi { font-size:14px; font-weight:bold; text-transform:uppercase; }
        .kop-text .satker   { font-size:15px; font-weight:bold; text-transform:uppercase; letter-spacing:.5px; }
        .kop-text .alamat   { font-size:9.5px; margin-top:3px; color:#333; }
        .kop-text .garis    { border-top:1px solid #000; margin-top:4px; }

        /* ── JUDUL ── */
        .doc-title { text-align:center; margin:14px 0 10px; }
        .doc-title h2 { font-size:13px; font-weight:bold; text-transform:uppercase; text-decoration:underline; }
        .doc-title p  { font-size:11px; margin-top:2px; }

        /* ── INFO ── */
        .doc-info { display:flex; justify-content:space-between; font-size:11px; margin-bottom:10px; }
        .doc-info .item span { color:#555; }
        .doc-info .item strong { color:#1a2744; }

        /* ── RINGKASAN ── */
        .rekap-bar { display:flex; gap:8px; margin-bottom:10px; }
        .rekap-bar .box { flex:1; border:1px solid #ddd; text-align:center; padding:5px 4px; border-radius:4px; }
        .rekap-bar .box .num { font-size:15px; font-weight:bold; }
        .rekap-bar .box .lbl { font-size:9px; color:#555; }
        .r-hadir  { border-top:2px solid #10b981; } .r-hadir .num  { color:#10b981; }
        .r-izin   { border-top:2px solid #f59e0b; } .r-izin .num   { color:#f59e0b; }
        .r-sakit  { border-top:2px solid #ef4444; } .r-sakit .num  { color:#ef4444; }
        .r-lambat { border-top:2px solid #6b7280; } .r-lambat .num { color:#6b7280; }
        .r-wfh    { border-top:2px solid #8b5cf6; } .r-wfh .num    { color:#8b5cf6; }
        .r-alpa   { border-top:2px solid #dc2626; } .r-alpa .num   { color:#dc2626; font-weight:800; }

        /* ── TABEL ── */
        table { width:100%; border-collapse:collapse; }
        thead th { background:#1a2744; color:#fff; padding:6px 8px; text-align:center; font-size:10.5px; border:1px solid #ccc; }
        tbody td { padding:5px 8px; border:1px solid #ddd; font-size:10px; vertical-align:middle; }
        tbody tr:nth-child(even) { background:#f9f9f9; }
        .center { text-align:center; }
        .b-hadir  { background:#d1fae5; color:#065f46; padding:2px 5px; border-radius:3px; font-size:9.5px; }
        .b-izin   { background:#fef3c7; color:#92400e; padding:2px 5px; border-radius:3px; font-size:9.5px; }
        .b-sakit  { background:#fee2e2; color:#991b1b; padding:2px 5px; border-radius:3px; font-size:9.5px; }
        .b-tepat  { background:#d1fae5; color:#065f46; padding:2px 5px; border-radius:3px; font-size:9.5px; }
        .b-lambat { background:#fef3c7; color:#92400e; padding:2px 5px; border-radius:3px; font-size:9.5px; }
        .b-pawal  { background:#dbeafe; color:#1e40af; padding:2px 5px; border-radius:3px; font-size:9.5px; }
        .b-wfo    { background:#dbeafe; color:#1e40af; padding:2px 5px; border-radius:3px; font-size:9.5px; }
        .b-wfh    { background:#f0e7ff; color:#6d28d9; padding:2px 5px; border-radius:3px; font-size:9.5px; font-weight:bold; }

        /* ── TTD ── */
        .ttd { display:flex; justify-content:flex-end; margin-top:20px; }
        .ttd-box { text-align:center; font-size:11px; }
        .ttd-box .space { height:55px; }
        .ttd-box .nama  { font-weight:bold; text-decoration:underline; }
    </style>
</head>
<body>

<!-- TOOLBAR -->
<div class="no-print">
    <a href="rekap.php?<?= $backParams ?>">
        <button class="btn-back">← Kembali</button>
    </a>
    <button onclick="window.print()">🖨️ Cetak / Simpan PDF</button>
</div>

<div class="a4-page">

    <!-- KOP SURAT -->
    <div class="kop">
        <img src="../assets/img/logo-instansi.png" alt="Logo" onerror="this.style.display='none'">
        <div class="kop-text">
            <div class="dept">Kementerian Pekerjaan Umum</div>
            <div class="instansi">Direktorat Jenderal Sumber Daya Air</div>
            <div class="satker">Satker Balai Besar Wilayah Sungai Citanduy</div>
            <div class="alamat">Jl. Prof. Dr. Ir. H. Sutami No. 1 Kota Banjar Jawa Barat 46332 &nbsp;|&nbsp; Telp. (0265) 741686 &nbsp;|&nbsp; bbwscitanduy@pu.go.id</div>
            <div class="garis"></div>
        </div>
    </div>

    <!-- JUDUL -->
    <div class="doc-title">
        <h2>Rekap Absensi Pegawai Non-ASN</h2>
        <p>Periode: <?= $periodLabel ?></p>
    </div>

    <!-- INFO -->
    <div class="doc-info">
        <div class="item">Pegawai: <strong><?= htmlspecialchars($namaPegawai) ?></strong></div>
        <div class="item">Dicetak: <strong><?= date('d F Y, H:i') ?> WIB</strong></div>
    </div>

    <!-- RINGKASAN (DITAMBAHKAN BOX ALPA) -->
    <div class="rekap-bar">
        <div class="box r-hadir">
            <div class="num"><?= $totalHadir ?></div>
            <div class="lbl">HADIR</div>
        </div>
        <div class="box r-izin">
            <div class="num"><?= $totalIzin ?></div>
            <div class="lbl">IZIN</div>
        </div>
        <div class="box r-sakit">
            <div class="num"><?= $totalSakit ?></div>
            <div class="lbl">SAKIT</div>
        </div>
        <div class="box r-lambat">
            <div class="num"><?= $totalTerlambat ?></div>
            <div class="lbl">TERLAMBAT</div>
        </div>
        <div class="box r-wfh">
            <div class="num"><?= $totalWFH ?></div>
            <div class="lbl">WFH</div>
        </div>
        <div class="box r-alpa">
            <div class="num"><?= $totalAlpa ?></div>
            <div class="lbl">ALPA</div>
        </div>
    </div>

    <!-- TABEL -->
    <table>
        <thead>
            <tr>
                <th style="width:25px;">No</th>
                <th>Hari</th>
                <th>Tanggal</th>
                <th>Nama Pegawai</th>
                <th>Jenis</th>
                <th>Mode</th>
                <th>Jam Masuk</th>
                <th>Jam Pulang</th>
                <th>Status Masuk</th>
                <th>Status Pulang</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rekap)): ?>
                <tr>
                    <td colspan="10" class="center" style="padding:16px;color:#888;">Tidak ada data absensi pada periode ini.</td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($rekap as $r): ?>
                    <?php $tgl = new DateTime($r['tanggal']); ?>
                    <tr>
                        <td class="center"><?= $no++ ?></td>
                        <td class="center"><?= $hariMap[$tgl->format('D')] ?></td>
                        <td class="center"><?= $tgl->format('d/m/Y') ?></td>
                        <td><?= htmlspecialchars($r['nama']) ?></td>
                        <td class="center">
                            <?php if ($r['jenis'] === 'hadir'): ?>
                                <span class="b-hadir">Hadir</span>
                            <?php elseif ($r['jenis'] === 'izin'): ?>
                                <span class="b-izin">Izin</span>
                            <?php elseif ($r['jenis'] === 'sakit'): ?>
                                <span class="b-sakit">Sakit</span>
                            <?php endif; ?>
                        </td>
                        <td class="center">
                            <?php if ($r['jenis'] === 'hadir'): ?>
                                <?php if ($r['mode'] === 'wfh'): ?>
                                    <span class="b-wfh" title="<?= htmlspecialchars($r['alamat_wfh'] ?? '-') ?>">WFH</span>
                                <?php else: ?>
                                    <span class="b-wfo">WFO</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span>-</span>
                            <?php endif; ?>
                        </td>
                        <td class="center"><?= $r['jam_masuk'] ? date('H:i', strtotime($r['jam_masuk'])) : '-' ?></td>
                        <td class="center"><?= $r['jam_pulang'] ? date('H:i', strtotime($r['jam_pulang'])) : '-' ?></td>
                        <td class="center">
                            <?php if ($r['jenis'] === 'hadir'): ?>
                                <?php if ($r['status_masuk'] === 'terlambat'): ?>
                                    <span class="b-lambat">Terlambat</span>
                                <?php elseif ($r['status_masuk']): ?>
                                    <span class="b-tepat">Tepat Waktu</span>
                                <?php else: ?>-<?php endif; ?>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                        <td class="center">
                            <?php if ($r['jenis'] === 'hadir' && $r['status_pulang']): ?>
                                <?php if ($r['status_pulang'] === 'pulang awal'): ?>
                                    <span class="b-pawal">Pulang Awal</span>
                                <?php else: ?>
                                    <span class="b-tepat">Pulang Tepat</span>
                                <?php endif; ?>
                            <?php else: ?>-<?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- FOOTER NOTE -->
    <div style="margin-top:15px; font-size:9px; color:#666; padding:8px; background:#f9f9f9; border-radius:4px;">
        <strong>Catatan:</strong><br>
        • WFO = Work From Office (Absen dari kantor dengan validasi lokasi)<br>
        • WFH = Work From Home (Absen dari lokasi bebas, tanpa validasi radius)<br>
        • Alpa = Hari kerja tanpa kehadiran & tanpa keterangan sah (izin/sakit/cuti/WFH)<br>
        • Alamat WFH dapat dilihat pada sistem digital untuk keperluan audit internal
    </div>

    <!-- TANDA TANGAN -->
    <div class="ttd">
        <div class="ttd-box">
            <div>Banjar, <?= date('d F Y') ?></div>
            <div>Pejabat yang berwenang,</div>
            <div class="space"></div>
            <div class="nama">(_______________________)</div>
        </div>
    </div>

</div><!-- end .a4-page -->
</body>
</html>