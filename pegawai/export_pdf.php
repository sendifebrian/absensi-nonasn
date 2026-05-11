<?php
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/helper.php'; // ← Fungsi alpa tersedia dari sini
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_pegawai()) {
    header('Location: ../pegawai/dashboard.php');
    exit();
}

$userId = $_SESSION['user_id'];

// Filter bulan/tahun
$filterBulan = $_GET['bulan'] ?? date('m');
$filterTahun = $_GET['tahun'] ?? date('Y');

$bulanList = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
    '04' => 'April',   '05' => 'Mei',       '06' => 'Juni',
    '07' => 'Juli',    '08' => 'Agustus',   '09' => 'September',
    '10' => 'Oktober', '11' => 'November',  '12' => 'Desember',
];

// Data user
$stmt = $pdo->prepare("SELECT nama, jabatan, unit_kerja, nik, created_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// ── Hitung Alpa (MENGGUNAKAN FUNGSI DARI HELPER.PHP) ──
$hari_ini = date('Y-m-d');
$range_awal = "{$filterTahun}-{$filterBulan}-01";
$range_akhir = min(date('Y-m-t', strtotime($range_awal)), $hari_ini);
$tgl_daftar = date('Y-m-d', strtotime($user['created_at']));
$start_hitung = max($range_awal, $tgl_daftar);

$totalAlpa = 0;
if ($start_hitung <= $range_akhir) {
    $res = hitung_alpa_pegawai($pdo, $userId, $start_hitung, $range_akhir);
    $totalAlpa = $res['alpa'];
}

// Data attendance (hadir)
$stmt = $pdo->prepare("
    SELECT tanggal, jam_masuk, jam_pulang, status_masuk, status_pulang, 'hadir' as jenis, NULL as keterangan
    FROM attendance 
    WHERE user_id = ? AND jam_masuk IS NOT NULL
      AND MONTH(tanggal) = ? AND YEAR(tanggal) = ?
    ORDER BY tanggal ASC
");
$stmt->execute([$userId, (int)$filterBulan, (int)$filterTahun]);
$hadirList = $stmt->fetchAll();

// Data izin/sakit
$stmt = $pdo->prepare("
    SELECT tanggal, NULL as jam_masuk, NULL as jam_pulang,
           NULL as status_masuk, NULL as status_pulang, jenis, keterangan
    FROM izin
    WHERE user_id = ? AND status = 'disetujui'
      AND MONTH(tanggal) = ? AND YEAR(tanggal) = ?
    ORDER BY tanggal ASC
");
$stmt->execute([$userId, (int)$filterBulan, (int)$filterTahun]);
$izinList = $stmt->fetchAll();

// Gabung & urutkan
$allData = array_merge($hadirList, $izinList);
usort($allData, fn($a, $b) => strcmp($a['tanggal'], $b['tanggal']));

// Ringkasan
$totalHadir = 0; $totalIzin = 0; $totalSakit = 0; $totalTerlambat = 0;
foreach ($allData as $row) {
    if ($row['jenis'] === 'hadir')            $totalHadir++;
    elseif ($row['jenis'] === 'izin')         $totalIzin++;
    elseif ($row['jenis'] === 'sakit')        $totalSakit++;
    if (($row['status_masuk'] ?? '') === 'terlambat') $totalTerlambat++;
}

$namaHari = ['Sun'=>'Minggu','Mon'=>'Senin','Tue'=>'Selasa','Wed'=>'Rabu','Thu'=>'Kamis','Fri'=>'Jumat','Sat'=>'Sabtu'];
$periodLabel = $bulanList[$filterBulan] . ' ' . $filterTahun;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Rekap Absensi - <?= htmlspecialchars($user['nama']) ?> - <?= $periodLabel ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 11px; color: #000; background: #e5e5e5; }
        .a4-page { width: 210mm; min-height: 297mm; margin: 20px auto; background: #fff; box-shadow: 0 0 10px rgba(0,0,0,0.15); padding: 15mm 15mm 20mm; }
        @page { size: A4 portrait; margin: 0; }
        @media print {
            html, body { background: #fff; }
            .no-print { display: none; }
            .a4-page { margin: 0; box-shadow: none; width: 210mm; padding: 15mm 15mm 20mm; }
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
        .kop { display: flex; align-items: center; padding-bottom: 8px; border-bottom: 3px solid #1a2744; }
        .kop img { width: 60px; height: 60px; margin-right: 14px; }
        .kop-text { text-align: center; flex: 1; }
        .kop-text .dept    { font-size: 12px; font-weight: bold; text-transform: uppercase; }
        .kop-text .instansi{ font-size: 14px; font-weight: bold; text-transform: uppercase; }
        .kop-text .satker  { font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: .5px; }
        .kop-text .alamat  { font-size: 9.5px; margin-top: 3px; color: #333; }
        .kop-text .garis   { border-top: 1px solid #000; margin-top: 4px; }
        .doc-title { text-align: center; margin: 14px 0 10px; }
        .doc-title h2 { font-size: 13px; font-weight: bold; text-transform: uppercase; text-decoration: underline; }
        .doc-title p  { font-size: 11px; margin-top: 2px; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 2px 30px; margin-bottom: 12px; }
        .info-grid .row { display: flex; font-size: 11px; }
        .info-grid .label { width: 90px; color: #555; }
        .info-grid .label::after { content: ':'; }
        .info-grid .value { font-weight: bold; }
        table { width: 100%; border-collapse: collapse; }
        thead th { background: #1a2744; color: #fff; padding: 6px 8px; text-align: center; font-size: 11px; border: 1px solid #ccc; }
        tbody td { padding: 5px 8px; border: 1px solid #ddd; font-size: 10.5px; vertical-align: middle; }
        tbody tr:nth-child(even) { background: #f9f9f9; }
        .center { text-align: center; }
        .badge-hadir  { background: #d1fae5; color: #065f46; padding: 2px 6px; border-radius: 4px; font-size: 10px; }
        .badge-izin   { background: #fef3c7; color: #92400e; padding: 2px 6px; border-radius: 4px; font-size: 10px; }
        .badge-sakit  { background: #fee2e2; color: #991b1b; padding: 2px 6px; border-radius: 4px; font-size: 10px; }
        .badge-lambat { background: #fef3c7; color: #92400e; padding: 2px 6px; border-radius: 4px; font-size: 10px; }
        .badge-tepat  { background: #d1fae5; color: #065f46; padding: 2px 6px; border-radius: 4px; font-size: 10px; }
        .badge-pawal  { background: #dbeafe; color: #1e40af; padding: 2px 6px; border-radius: 4px; font-size: 10px; }
        .badge-alpa   { background: #fee2e2; color: #dc2626; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: bold; } /* ← BARU */
        .ttd { display: flex; justify-content: flex-end; margin-top: 20px; }
        .ttd-box { text-align: center; font-size: 11px; }
        .ttd-box .kota  { margin-bottom: 4px; }
        .ttd-box .space { height: 55px; }
        .ttd-box .nama  { font-weight: bold; text-decoration: underline; }
        .no-print { text-align: center; padding: 14px; background: #f3f4f6; border-bottom: 1px solid #ddd; }
        .no-print button { background: #1a2744; color: white; border: none; padding: 8px 24px; border-radius: 5px; cursor: pointer; font-size: 13px; margin: 0 5px; }
        .no-print a { text-decoration: none; }
        .no-print .btn-back { background: #6b7280; color: white; border: none; padding: 8px 18px; border-radius: 5px; cursor: pointer; font-size: 13px; }
        /* Ringkasan box */
        .summary-box { display: flex; gap: 8px; margin: 10px 0 15px; flex-wrap: wrap; }
        .summary-item { flex: 1; min-width: 80px; border: 1px solid #ddd; border-radius: 4px; padding: 6px 4px; text-align: center; }
        .summary-item .num { font-size: 16px; font-weight: bold; display: block; }
        .summary-item .lbl { font-size: 9px; color: #555; }
        .s-hadir .num { color: #16a34a; }
        .s-izin .num { color: #d97706; }
        .s-sakit .num { color: #ef4444; }
        .s-lambat .num { color: #d97706; }
        .s-alpa .num { color: #dc2626; font-weight: 800; } /* ← BARU */
    </style>
</head>
<body>

<!-- TOOLBAR -->
<div class="no-print">
    <a href="riwayat.php?bulan=<?= $filterBulan ?>&tahun=<?= $filterTahun ?>">
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

<!-- INFO PEGAWAI -->
<div class="info-grid">
    <div class="row"><span class="label">Nama</span><span class="value"><?= htmlspecialchars($user['nama']) ?></span></div>
    <div class="row"><span class="label">NIK</span><span class="value"><?= $user['nik'] ?: '-' ?></span></div>
    <div class="row"><span class="label">Jabatan</span><span class="value"><?= htmlspecialchars($user['jabatan'] ?: '-') ?></span></div>
    <div class="row"><span class="label">Unit Kerja</span><span class="value"><?= htmlspecialchars($user['unit_kerja'] ?: '-') ?></span></div>
</div>

<!-- RINGKASAN (DITAMBAHKAN BOX ALPA) -->
<div class="summary-box">
    <div class="summary-item s-hadir"><span class="num"><?= $totalHadir ?></span><span class="lbl">Hadir</span></div>
    <div class="summary-item s-izin"><span class="num"><?= $totalIzin ?></span><span class="lbl">Izin</span></div>
    <div class="summary-item s-sakit"><span class="num"><?= $totalSakit ?></span><span class="lbl">Sakit</span></div>
    <div class="summary-item s-lambat"><span class="num"><?= $totalTerlambat ?></span><span class="lbl">Terlambat</span></div>
    <div class="summary-item s-alpa"><span class="num"><?= $totalAlpa ?></span><span class="lbl">Alpa</span></div> <!-- ← BARU -->
</div>

<!-- TABEL DATA -->
<table>
    <thead>
        <tr>
            <th style="width:30px;">No</th><th>Hari</th><th>Tanggal</th><th>Jenis</th><th>Jam Masuk</th><th>Jam Pulang</th><th>Status Masuk</th><th>Status Pulang</th><th>Keterangan</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($allData)): ?>
            <tr><td colspan="9" style="text-align:center;padding:16px;color:#888;">Tidak ada data absensi pada periode ini.</td></tr>
        <?php else: ?>
            <?php $no = 1; foreach ($allData as $row): ?>
                <?php $tgl = new DateTime($row['tanggal']); $hari = $namaHari[$tgl->format('D')]; ?>
                <tr>
                    <td class="center"><?= $no++ ?></td>
                    <td><?= $hari ?></td>
                    <td class="center"><?= $tgl->format('d/m/Y') ?></td>
                    <td class="center">
                        <?php if ($row['jenis'] === 'hadir'): ?><span class="badge-hadir">Hadir</span>
                        <?php elseif ($row['jenis'] === 'izin'): ?><span class="badge-izin">Izin</span>
                        <?php elseif ($row['jenis'] === 'sakit'): ?><span class="badge-sakit">Sakit</span><?php endif; ?>
                    </td>
                    <td class="center"><?= $row['jam_masuk'] ? date('H:i', strtotime($row['jam_masuk'])) : '-' ?></td>
                    <td class="center"><?= $row['jam_pulang'] ? date('H:i', strtotime($row['jam_pulang'])) : '-' ?></td>
                    <td class="center">
                        <?php if ($row['jenis'] === 'hadir'): ?>
                            <?php if ($row['status_masuk'] === 'terlambat'): ?><span class="badge-lambat">Terlambat</span>
                            <?php elseif ($row['status_masuk']): ?><span class="badge-tepat">Tepat Waktu</span>
                            <?php else: ?>-<?php endif; ?>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td class="center">
                        <?php if ($row['jenis'] === 'hadir' && $row['status_pulang']): ?>
                            <?php if ($row['status_pulang'] === 'pulang awal'): ?><span class="badge-pawal">Pulang Awal</span>
                            <?php else: ?><span class="badge-tepat">Pulang Tepat</span><?php endif; ?>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                    <td><?= isset($row['keterangan']) && $row['keterangan'] ? htmlspecialchars(mb_substr($row['keterangan'], 0, 35)) : '-' ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>

<!-- FOOTER NOTE (DITAMBAHKAN PENJELASAN ALPA) -->
<div style="margin-top:15px;font-size:9px;color:#666;padding:8px;background:#f9f9f9;border-radius:4px;">
    <strong>Catatan:</strong><br>
    • Alpa = Hari kerja tanpa kehadiran & tanpa keterangan sah (izin/sakit/cuti/WFH/WFA)<br>
    • Perhitungan alpa hanya mencakup hari Senin–Jumat, dikurangi libur nasional
</div>

<!-- TANDA TANGAN -->
<div class="ttd">
    <div class="ttd-box">
        <div class="kota">Banjar, <?= date('d ') . $bulanList[date('m')] . ' ' . date('Y') ?></div>
        <div>Pegawai yang bersangkutan,</div>
        <div class="space"></div>
        <div class="nama"><?= htmlspecialchars($user['nama']) ?></div>
    </div>
</div>

</div>
</body>
</html>