<?php
require_once __DIR__ . '/../config/init.php';
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_admin()) {
    header('Location: dashboard.php');
    exit();
}

$stmt = $pdo->query("SELECT * FROM users WHERE role = 'pegawai' ORDER BY nama ASC");
$pegawai = $stmt->fetchAll();

$totalPegawai = count($pegawai);
$aktif    = count(array_filter($pegawai, fn($p) => $p['status'] === 'aktif'));
$nonaktif = $totalPegawai - $aktif;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Data Pegawai Non-ASN - BBWS Citanduy</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { font-family: Arial, sans-serif; font-size:11px; color:#000; background:#e5e5e5; }

        /* ── A4 ── */
        .a4-page { width:210mm; min-height:297mm; margin:20px auto; background:#fff; box-shadow:0 0 10px rgba(0,0,0,0.15); padding:15mm 15mm 20mm; }
        @page { size: A4 portrait; margin:0; }
        @media print {
            html, body { background:#fff; }
            .no-print { display:none; }
            .a4-page { margin:0; box-shadow:none; padding:15mm 15mm 20mm; }
            body { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        }

        /* ── TOOLBAR ── */
        .no-print { text-align:center; padding:14px; background:#f3f4f6; border-bottom:1px solid #ddd; }
        .no-print button { background:#1a2744; color:white; border:none; padding:8px 24px; border-radius:5px; cursor:pointer; font-size:13px; margin:0 5px; }
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

        /* ── INFO REKAP ── */
        .rekap-info { font-size:11px; margin-bottom:10px; }
        .rekap-info span { margin-right:20px; }
        .rekap-info strong { color:#1a2744; }

        /* ── TABEL ── */
        table { width:100%; border-collapse:collapse; }
        thead th { background:#1a2744; color:#fff; padding:6px 8px; text-align:center; font-size:10.5px; border:1px solid #ccc; }
        tbody td { padding:5px 8px; border:1px solid #ddd; font-size:10px; vertical-align:middle; }
        tbody tr:nth-child(even) { background:#f9f9f9; }
        .center { text-align:center; }
        .badge-aktif    { background:#d1fae5; color:#065f46; padding:2px 6px; border-radius:4px; font-size:9.5px; }
        .badge-nonaktif { background:#f3f4f6; color:#374151; padding:2px 6px; border-radius:4px; font-size:9.5px; }

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
    <a href="pegawai.php">
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
        <h2>Daftar Pegawai Non-ASN</h2>
        <p>Dicetak pada: <?= date('d F Y, H:i') ?> WIB</p>
    </div>

    <!-- INFO REKAP -->
    <div class="rekap-info">
        <span>Total Pegawai: <strong><?= $totalPegawai ?></strong></span>
        <span>Aktif: <strong><?= $aktif ?></strong></span>
        <span>Nonaktif: <strong><?= $nonaktif ?></strong></span>
    </div>

    <!-- TABEL -->
    <table>
        <thead>
            <tr>
                <th style="width:25px;">No</th>
                <th>Nama Lengkap</th>
                <th>NIK</th>
                <th>Jabatan</th>
                <th>Unit Kerja</th>
                <th>No HP</th>
                <th>Terdaftar</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($pegawai)): ?>
                <tr>
                    <td colspan="8" class="center" style="padding:16px;color:#888;">Belum ada data pegawai.</td>
                </tr>
            <?php else: ?>
                <?php $no = 1; foreach ($pegawai as $p): ?>
                    <tr>
                        <td class="center"><?= $no++ ?></td>
                        <td>
                            <strong><?= htmlspecialchars($p['nama']) ?></strong>
                            <?php if ($p['email']): ?>
                                <br><span style="color:#6b7280;font-size:9px;"><?= htmlspecialchars($p['email']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="center"><?= $p['nik'] ?: '-' ?></td>
                        <td><?= htmlspecialchars($p['jabatan'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($p['unit_kerja'] ?: '-') ?></td>
                        <td class="center"><?= $p['no_hp'] ?: '-' ?></td>
                        <td class="center"><?= $p['created_at'] ? date('d/m/Y', strtotime($p['created_at'])) : '-' ?></td>
                        <td class="center">
                            <?php if ($p['status'] === 'aktif'): ?>
                                <span class="badge-aktif">Aktif</span>
                            <?php else: ?>
                                <span class="badge-nonaktif">Nonaktif</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

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