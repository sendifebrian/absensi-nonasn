<?php
require_once __DIR__ . '/../config/init.php';
require_once __DIR__ . '/../config/helper.php'; // ← Fungsi alpa tersedia dari sini
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_pegawai()) {
    header('Location: dashboard.php');
    exit();
}

$userId = $_SESSION['user_id'];

// Ambil data user
$stmt = $pdo->prepare("SELECT nama, jabatan, unit_kerja, nik, created_at FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();

// Filter bulan/tahun
$filterBulan = $_GET['bulan'] ?? date('m');
$filterTahun = $_GET['tahun'] ?? date('Y');

// Tentukan rentang hitung alpa (sama seperti dashboard & rekap)
$hari_ini = date('Y-m-d');
$range_awal = "{$filterTahun}-{$filterBulan}-01";
$range_akhir = min(date('Y-m-t', strtotime($range_awal)), $hari_ini);
$tgl_daftar = date('Y-m-d', strtotime($user['created_at']));
$start_hitung = max($range_awal, $tgl_daftar);

// Hitung alpa menggunakan fungsi dari helper.php
$totalAlpa = 0;
if ($start_hitung <= $range_akhir) {
    $res = hitung_alpa_pegawai($pdo, $userId, $start_hitung, $range_akhir);
    $totalAlpa = $res['alpa'];
}

// ── Rentang tanggal untuk bulan yang dipilih ─────────────────
$range_awal_display  = "{$filterTahun}-{$filterBulan}-01";
$range_akhir_display = date('Y-m-t', strtotime($range_awal_display));

// Ambil riwayat attendance (hadir) — FILTER langsung di SQL per bulan/tahun
// BUG FIX: sebelumnya tidak ada filter tanggal sehingga semua bulan ikut masuk
$stmt = $pdo->prepare("
    SELECT tanggal, jam_masuk, jam_pulang, status_masuk, status_pulang, 'hadir' as jenis,
           NULL as keterangan, NULL as bukti
    FROM attendance 
    WHERE user_id = ? AND jam_masuk IS NOT NULL
      AND tanggal BETWEEN ? AND ?
    ORDER BY tanggal DESC
");
$stmt->execute([$userId, $range_awal_display, $range_akhir_display]);
$hadirList = $stmt->fetchAll();

// Ambil riwayat izin/sakit — FILTER langsung di SQL per bulan/tahun
$stmt = $pdo->prepare("
    SELECT tanggal, NULL as jam_masuk, NULL as jam_pulang, 
           NULL as status_masuk, NULL as status_pulang, jenis, keterangan, bukti
    FROM izin 
    WHERE user_id = ? AND status = 'disetujui'
      AND tanggal BETWEEN ? AND ?
    ORDER BY tanggal DESC
");
$stmt->execute([$userId, $range_awal_display, $range_akhir_display]);
$izinList = $stmt->fetchAll();

// Gabungkan dan urutkan berdasarkan tanggal DESC
// BUG FIX: $filtered sekarang = $allData langsung (sudah difilter di SQL, tidak perlu filter PHP lagi)
$allData = array_merge($hadirList, $izinList);
usort($allData, fn($a, $b) => strcmp($b['tanggal'], $a['tanggal']));
$filtered = $allData; // semua data sudah sesuai bulan/tahun

// Tahun tersedia
$tahunList = range(date('Y'), date('Y') - 3);
$bulanList = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret',
    '04' => 'April',   '05' => 'Mei',       '06' => 'Juni',
    '07' => 'Juli',    '08' => 'Agustus',   '09' => 'September',
    '10' => 'Oktober', '11' => 'November',  '12' => 'Desember',
];

// Hitung ringkasan bulan ini — dari $filtered yang sekarang sudah sinkron dengan hitung_alpa_pegawai()
$totalHadir = 0; $totalIzin = 0; $totalSakit = 0; $totalTerlambat = 0;
foreach ($filtered as $row) {
    if ($row['jenis'] === 'hadir')       $totalHadir++;
    elseif ($row['jenis'] === 'izin')    $totalIzin++;
    elseif ($row['jenis'] === 'sakit')   $totalSakit++;
    if (isset($row['status_masuk']) && $row['status_masuk'] === 'terlambat') $totalTerlambat++;
}
?>

<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<div class="main-content">
    <div class="container-fluid">
        
        <!-- Page Header -->
        <div class="page-header" data-aos="fade-down">
            <div>
                <h2 class="mb-1">Riwayat Absensi</h2>
                <p class="text-muted mb-0">
                    <i class="bi bi-person-badge me-1"></i>
                    <?= htmlspecialchars($user['nama']) ?>
                    <?= $user['jabatan'] ? ' • ' . htmlspecialchars($user['jabatan']) : '' ?>
                </p>
            </div>
            <a href="export_pdf.php?bulan=<?= $filterBulan ?>&tahun=<?= $filterTahun ?>"
               target="_blank"
               class="btn btn-gold"
               data-aos="fade-left">
                <i class="bi bi-file-earmark-pdf-fill me-2"></i>Export PDF
            </a>
        </div>

        <!-- Filter Card -->
        <div class="card filter-card mb-3 mb-md-4" data-aos="fade-up">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label-custom">
                            <i class="bi bi-calendar-month me-1"></i>Bulan
                        </label>
                        <select name="bulan" class="form-select form-control-custom">
                            <?php foreach ($bulanList as $k => $v): ?>
                                <option value="<?= $k ?>" <?= $filterBulan === $k ? 'selected' : '' ?>><?= $v ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-sm-6 col-md-4">
                        <label class="form-label-custom">
                            <i class="bi bi-calendar3 me-1"></i>Tahun
                        </label>
                        <select name="tahun" class="form-select form-control-custom">
                            <?php foreach ($tahunList as $t): ?>
                                <option value="<?= $t ?>" <?= $filterTahun == $t ? 'selected' : '' ?>><?= $t ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <button type="submit" class="btn btn-navy w-100">
                            <i class="bi bi-funnel-fill me-2"></i>Tampilkan Data
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistik Cards (DITAMBAHKAN CARD ALPA) -->
        <div class="row g-3 g-md-4 mb-3 mb-md-4">
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="0">
                <div class="stats-card stats-success">
                    <div class="stats-icon"><i class="bi bi-check-circle-fill"></i></div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $totalHadir ?></div>
                        <div class="stats-label">Hadir</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="100">
                <div class="stats-card stats-warning">
                    <div class="stats-icon"><i class="bi bi-file-earmark-text-fill"></i></div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $totalIzin ?></div>
                        <div class="stats-label">Izin</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="200">
                <div class="stats-card stats-danger">
                    <div class="stats-icon"><i class="bi bi-virus"></i></div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $totalSakit ?></div>
                        <div class="stats-label">Sakit</div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="300">
                <div class="stats-card stats-secondary">
                    <div class="stats-icon"><i class="bi bi-clock-history"></i></div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $totalTerlambat ?></div>
                        <div class="stats-label">Terlambat</div>
                    </div>
                </div>
            </div>
            <!-- CARD ALPA BARU -->
            <div class="col-6 col-lg-3" data-aos="fade-up" data-aos-delay="400">
                <div class="stats-card stats-alpa">
                    <div class="stats-icon"><i class="bi bi-x-circle-fill"></i></div>
                    <div class="stats-content">
                        <div class="stats-value"><?= $totalAlpa ?></div>
                        <div class="stats-label">Alpa</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabel Riwayat -->
        <div class="card table-card" data-aos="fade-up">
            <div class="card-header-custom">
                <div>
                    <h6 class="mb-0">
                        <i class="bi bi-calendar-range me-2 text-gold"></i>
                        <?= $bulanList[$filterBulan] ?> <?= $filterTahun ?>
                    </h6>
                    <small class="text-muted"><?= count($filtered) ?> data ditemukan</small>
                </div>
            </div>
            <div class="card-body p-0">
                <!-- Mobile: Alert Scroll -->
                <div class="alert alert-info d-md-none mx-3 mt-3 mb-0">
                    <small>
                        <i class="bi bi-info-circle me-1"></i>
                        Geser tabel ke samping untuk melihat seluruh data
                    </small>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th width="15%">Tanggal</th>
                                <th width="12%">Jenis</th>
                                <th width="12%">Jam Masuk</th>
                                <th width="12%">Jam Pulang</th>
                                <th width="18%">Status</th>
                                <th width="31%">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($filtered)): ?>
                                <tr>
                                    <td colspan="6" class="text-center py-5">
                                        <div class="empty-state">
                                            <i class="bi bi-inbox fs-1 text-muted mb-3 d-block"></i>
                                            <p class="text-muted mb-0">Tidak ada data riwayat absensi pada bulan ini</p>
                                        </div>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($filtered as $row): ?>
                                    <?php
                                    $tgl = new DateTime($row['tanggal']);
                                    $hariNama = ['Sun'=>'Minggu','Mon'=>'Senin','Tue'=>'Selasa','Wed'=>'Rabu','Thu'=>'Kamis','Fri'=>'Jumat','Sat'=>'Sabtu'];
                                    $namaHari = $hariNama[$tgl->format('D')];
                                    ?>
                                    <tr>
                                        <td>
                                            <div class="date-cell">
                                                <strong class="text-navy d-block"><?= $tgl->format('d/m/Y') ?></strong>
                                                <small class="text-muted"><?= $namaHari ?></small>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($row['jenis'] === 'hadir'): ?>
                                                <span class="badge badge-success"><i class="bi bi-check-circle me-1"></i>Hadir</span>
                                            <?php elseif ($row['jenis'] === 'izin'): ?>
                                                <span class="badge badge-warning"><i class="bi bi-file-text me-1"></i>Izin</span>
                                            <?php elseif ($row['jenis'] === 'sakit'): ?>
                                                <span class="badge badge-danger"><i class="bi bi-virus me-1"></i>Sakit</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['jam_masuk']): ?>
                                                <span class="time-badge"><i class="bi bi-clock me-1"></i><?= date('H:i', strtotime($row['jam_masuk'])) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['jam_pulang']): ?>
                                                <span class="time-badge"><i class="bi bi-clock me-1"></i><?= date('H:i', strtotime($row['jam_pulang'])) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($row['jenis'] === 'hadir'): ?>
                                                <?php if (isset($row['status_masuk']) && $row['status_masuk'] === 'terlambat'): ?>
                                                    <span class="badge badge-warning-outline"><i class="bi bi-clock-history me-1"></i>Terlambat</span>
                                                <?php else: ?>
                                                    <span class="badge badge-success-outline"><i class="bi bi-check2 me-1"></i>Tepat Waktu</span>
                                                <?php endif; ?>
                                                <?php if (isset($row['status_pulang']) && $row['status_pulang'] === 'pulang awal'): ?>
                                                    <span class="badge badge-info-outline ms-1"><i class="bi bi-arrow-left-circle me-1"></i>Pulang Awal</span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (isset($row['keterangan']) && $row['keterangan']): ?>
                                                <div class="keterangan-cell"><?= htmlspecialchars(mb_substr($row['keterangan'], 0, 50)) ?><?= mb_strlen($row['keterangan']) > 50 ? '...' : '' ?></div>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Custom CSS (DITAMBAHKAN STYLE UNTUK CARD ALPA) -->
<style>
/* ========================================
   RIWAYAT PAGE CUSTOM STYLES
   ======================================== */

/* Filter Card */
.filter-card {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
}
.filter-card:hover { box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1); }

/* Stats Card */
.stats-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    padding: 1.25rem;
    display: flex;
    align-items: center;
    gap: 1rem;
    transition: all 0.3s ease;
    position: relative;
    overflow: hidden;
}
.stats-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
    transition: width 0.3s ease;
}
.stats-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
.stats-card:hover::before { width: 100%; opacity: 0.05; }

/* Stats Variants */
.stats-success::before { background-color: #10b981; }
.stats-warning::before { background-color: #f59e0b; }
.stats-danger::before { background-color: #ef4444; }
.stats-secondary::before { background-color: #6b7280; }
.stats-alpa::before { background-color: #dc2626; } /* ← BARU */

.stats-icon {
    width: 50px; height: 50px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.5rem; flex-shrink: 0;
}
.stats-success .stats-icon { background-color: #e8f5e9; color: #10b981; }
.stats-warning .stats-icon { background-color: #fef3e2; color: #f59e0b; }
.stats-danger .stats-icon { background-color: #fef2f2; color: #ef4444; }
.stats-secondary .stats-icon { background-color: #f3f4f6; color: #6b7280; }
.stats-alpa .stats-icon { background-color: #fee2e2; color: #dc2626; } /* ← BARU */

.stats-content { flex: 1; }
.stats-value {
    font-size: 1.75rem; font-weight: 700;
    color: #1e3a5f; line-height: 1; margin-bottom: 0.25rem;
}
.stats-label {
    font-size: 0.8125rem; font-weight: 600;
    color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em;
}

/* Table Card */
.table-card {
    border: 1px solid #e5e7eb;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
    overflow: hidden;
}
.card-header-custom {
    background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
    border-bottom: 2px solid #d4af37;
    padding: 1.25rem 1.5rem;
}
.card-header-custom h6 {
    color: #1e3a5f; font-weight: 600; font-size: 1rem;
}

/* Table Styling */
.table { margin-bottom: 0; }
.table thead { background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%); }
.table thead th {
    color: #1e3a5f; font-weight: 600; font-size: 0.8125rem;
    text-transform: uppercase; letter-spacing: 0.05em;
    padding: 1rem; border-bottom: 2px solid #e5e7eb;
}
.table tbody td {
    padding: 1rem; vertical-align: middle;
    color: #374151; font-size: 0.9375rem;
}
.table tbody tr { transition: background-color 0.2s ease; }
.table tbody tr:hover { background-color: #f9fafb; }

/* Date Cell */
.date-cell strong { font-size: 0.9375rem; }
.date-cell small { font-size: 0.75rem; }

/* Badge Styles */
.badge {
    padding: 0.375rem 0.75rem; border-radius: 6px;
    font-weight: 600; font-size: 0.8125rem;
    display: inline-flex; align-items: center;
}
.badge-success { background-color: #10b981; color: #ffffff; }
.badge-warning { background-color: #f59e0b; color: #ffffff; }
.badge-danger { background-color: #ef4444; color: #ffffff; }

/* Outline Badges */
.badge-success-outline { background-color: #e8f5e9; color: #065f46; border: 1px solid #10b981; }
.badge-warning-outline { background-color: #fef3e2; color: #92400e; border: 1px solid #f59e0b; }
.badge-info-outline { background-color: #eff6ff; color: #1e40af; border: 1px solid #3b82f6; }

/* Time Badge */
.time-badge {
    background-color: #f3f4f6; color: #1e3a5f;
    padding: 0.375rem 0.75rem; border-radius: 6px;
    font-size: 0.875rem; font-weight: 600;
    display: inline-flex; align-items: center;
}

/* Keterangan Cell */
.keterangan-cell { font-size: 0.875rem; color: #6b7280; line-height: 1.4; }

/* Empty State */
.empty-state { padding: 2rem 1rem; }

/* ========================================
   RESPONSIVE DESIGN
   ======================================== */
@media (min-width: 992px) {
    .stats-icon { width: 60px; height: 60px; font-size: 1.75rem; }
    .stats-value { font-size: 2rem; }
}
@media (min-width: 768px) and (max-width: 991.98px) {
    .stats-icon { width: 55px; height: 55px; }
    .stats-value { font-size: 1.875rem; }
}
@media (max-width: 767.98px) {
    .page-header { flex-direction: column; align-items: stretch; }
    .page-header .btn-gold { width: 100%; }
    .card-header-custom { padding: 1rem 1.25rem; }
    .stats-card { padding: 1rem; }
    .stats-icon { width: 45px; height: 45px; font-size: 1.25rem; }
    .stats-value { font-size: 1.5rem; }
    .stats-label { font-size: 0.75rem; }
    .table thead th { padding: 0.75rem; font-size: 0.75rem; }
    .table tbody td { padding: 0.75rem; font-size: 0.875rem; }
    .badge { font-size: 0.75rem; padding: 0.25rem 0.5rem; }
    .time-badge { font-size: 0.8125rem; padding: 0.25rem 0.5rem; }
}
@media (max-width: 575.98px) {
    .page-header h2 { font-size: 1.5rem; }
    .card-header-custom h6 { font-size: 0.9375rem; }
    .stats-card { padding: 0.875rem; }
    .stats-icon { width: 40px; height: 40px; font-size: 1.125rem; }
    .stats-value { font-size: 1.25rem; }
    .date-cell strong { font-size: 0.875rem; }
    .date-cell small { font-size: 0.7rem; }
}
@media (max-width: 374px) {
    .page-header h2 { font-size: 1.25rem; }
}
.table-responsive { border-radius: 0 0 12px 12px; }
@media (max-width: 991.98px) {
    .table-responsive { -webkit-overflow-scrolling: touch; }
}
</style>

<!-- AOS Animation -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
<script>
AOS.init({ duration: 600, once: true, offset: 50 });
</script>

<?php include '../templates/footer.php'; ?>