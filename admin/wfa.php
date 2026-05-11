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

$today = date('Y-m-d');

// Ambil semua jadwal WFA
$stmt = $pdo->query("
    SELECT w.*, u.nama AS nama_pegawai, u.unit_kerja AS unit_pegawai
    FROM wfa_schedule w
    LEFT JOIN users u ON w.user_id = u.id
    ORDER BY w.tanggal_mulai DESC
");
$jadwal_list = $stmt->fetchAll();

// Ambil pegawai aktif
$stmt = $pdo->query("SELECT id, nama, unit_kerja FROM users WHERE role = 'pegawai' AND status = 'aktif' ORDER BY nama");
$pegawai_list = $stmt->fetchAll();

// Cek WFA aktif hari ini (semua pegawai)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM wfa_schedule WHERE tanggal_mulai <= ? AND tanggal_selesai >= ? AND berlaku_untuk = 'semua'");
$stmt->execute([$today, $today]);
$wfa_aktif_semua = $stmt->fetchColumn();

// Stats
$stmt = $pdo->prepare("SELECT COUNT(*) FROM wfa_schedule WHERE tanggal_selesai >= ?");
$stmt->execute([$today]);
$total_aktif = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT COUNT(*) FROM wfa_schedule WHERE tanggal_selesai < ?");
$stmt->execute([$today]);
$total_selesai = $stmt->fetchColumn();

// Hitung pegawai yang sedang WFA hari ini
$stmt = $pdo->prepare("
    SELECT COUNT(DISTINCT w.user_id)
    FROM wfa_schedule w
    WHERE w.tanggal_mulai <= ? AND w.tanggal_selesai >= ?
    AND (w.berlaku_untuk = 'semua' OR w.berlaku_untuk = 'personal')
");
$stmt->execute([$today, $today]);
$pegawai_wfa_hari_ini = $stmt->fetchColumn();

$bulan = ['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
function fmt_tgl($tgl, $bulan) {
    $dt = new DateTime($tgl);
    return $dt->format('d') . ' ' . $bulan[(int)$dt->format('n')] . ' ' . $dt->format('Y');
}

// Check tour
$stmt = $pdo->prepare("SELECT has_seen_tour FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();
$showTour = !$user['has_seen_tour'];

if (isset($_GET['tour_completed'])) {
    $pdo->prepare("UPDATE users SET has_seen_tour = 1 WHERE id = ?")
        ->execute([$_SESSION['user_id']]);
    header("Location: wfa.php");
    exit();
}
?>

<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<div class="main-content">
<div class="container-fluid px-4 py-4">

    <!-- Page Header -->
    <div class="page-header-wfa mb-4" id="tour-header">
        <div class="header-content">
            <div class="header-icon-wrap">
                <svg class="header-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                    <polyline points="9 22 9 12 15 12 15 22"></polyline>
                </svg>
            </div>
            <div>
                <h4 class="page-title">Kelola Work From Anywhere</h4>
                <p class="page-subtitle">Atur jadwal kerja fleksibel untuk pegawai</p>
            </div>
        </div>
        <button class="btn-primary-wfa" data-bs-toggle="modal" data-bs-target="#modalWFA" onclick="resetModal()">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <line x1="12" y1="5" x2="12" y2="19"></line>
                <line x1="5" y1="12" x2="19" y2="12"></line>
            </svg>
            <span>Tambah Jadwal</span>
        </button>
    </div>

    <!-- Flash Alert -->
    <?php if (isset($_SESSION['alert'])): ?>
    <div class="alert alert-<?= $_SESSION['alert']['type'] ?> alert-dismissible fade show modern-alert mb-4">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <?php if ($_SESSION['alert']['type'] === 'success'): ?>
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
            <polyline points="22 4 12 14.01 9 11.01"></polyline>
            <?php else: ?>
            <circle cx="12" cy="12" r="10"></circle>
            <line x1="15" y1="9" x2="9" y2="15"></line>
            <line x1="9" y1="9" x2="15" y2="15"></line>
            <?php endif; ?>
        </svg>
        <?= htmlspecialchars($_SESSION['alert']['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['alert']); endif; ?>

    <!-- Banner WFA aktif hari ini -->
    <?php if ($wfa_aktif_semua): ?>
    <div class="wfa-active-banner mb-4" id="tour-banner">
        <div class="banner-icon">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <path d="M12 6v6l4 2"></path>
            </svg>
        </div>
        <div class="banner-content">
            <h6>WFA Berlaku Hari Ini</h6>
            <p>Semua pegawai dapat bekerja dari lokasi manapun tanpa validasi GPS</p>
        </div>
        <div class="live-indicator">
            <span class="live-dot"></span>
            <span class="live-text">Aktif</span>
        </div>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row g-3 mb-4" id="tour-stats">
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon blue">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </div>
                <div class="stat-info">
                    <div class="stat-value"><?= $total_aktif ?></div>
                    <div class="stat-label">Jadwal Aktif</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon gray">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M9 11l3 3L22 4"></path>
                        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
                    </svg>
                </div>
                <div class="stat-info">
                    <div class="stat-value"><?= $total_selesai ?></div>
                    <div class="stat-label">Selesai</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon gold">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                        <circle cx="9" cy="7" r="4"></circle>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                    </svg>
                </div>
                <div class="stat-info">
                    <div class="stat-value"><?= $pegawai_wfa_hari_ini ?></div>
                    <div class="stat-label">WFA Hari Ini</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-icon navy">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                </div>
                <div class="stat-info">
                    <div class="stat-value"><?= count($pegawai_list) ?></div>
                    <div class="stat-label">Total Pegawai</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabel -->
    <div class="table-card" id="tour-table">
        <div class="table-header">
            <div class="table-title">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="8" y1="6" x2="21" y2="6"></line>
                    <line x1="8" y1="12" x2="21" y2="12"></line>
                    <line x1="8" y1="18" x2="21" y2="18"></line>
                    <line x1="3" y1="6" x2="3.01" y2="6"></line>
                    <line x1="3" y1="12" x2="3.01" y2="12"></line>
                    <line x1="3" y1="18" x2="3.01" y2="18"></line>
                </svg>
                <span>Daftar Jadwal WFA</span>
            </div>
            <span class="count-badge"><?= count($jadwal_list) ?></span>
        </div>

        <?php if (empty($jadwal_list)): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                    <line x1="12" y1="14" x2="12" y2="18"></line>
                    <line x1="10" y1="16" x2="14" y2="16"></line>
                </svg>
            </div>
            <h6>Belum ada jadwal WFA</h6>
            <p>Klik tombol "Tambah Jadwal" untuk membuat jadwal WFA pertama</p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table-modern">
                <thead>
                    <tr>
                        <th style="width:50px">#</th>
                        <th>Pegawai / Cakupan</th>
                        <th>Periode</th>
                        <th class="d-none d-lg-table-cell">Keterangan</th>
                        <th>Status</th>
                        <th class="text-end" style="width:100px">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($jadwal_list as $i => $j):
                    $todayDt = new DateTime($today);
                    $mulai   = new DateTime($j['tanggal_mulai']);
                    $selesai = new DateTime($j['tanggal_selesai']);

                    if ($todayDt >= $mulai && $todayDt <= $selesai) {
                        $statusLabel = 'Berlangsung';
                        $statusClass = 'status-active';
                        $statusIcon  = '<circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path>';
                    } elseif ($todayDt < $mulai) {
                        $statusLabel = 'Akan Datang';
                        $statusClass = 'status-upcoming';
                        $statusIcon  = '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line>';
                    } else {
                        $statusLabel = 'Selesai';
                        $statusClass = 'status-completed';
                        $statusIcon  = '<polyline points="20 6 9 17 4 12"></polyline>';
                    }

                    $is_semua = $j['berlaku_untuk'] === 'semua';
                ?>
                <tr>
                    <td class="text-muted"><?= $i + 1 ?></td>
                    <td>
                        <div class="employee-cell">
                            <?php if ($is_semua): ?>
                            <div class="avatar avatar-purple">
                                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                    <circle cx="9" cy="7" r="4"></circle>
                                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                    <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                </svg>
                            </div>
                            <div class="employee-info">
                                <div class="employee-name">Semua Pegawai</div>
                                <div class="employee-unit">Berlaku untuk semua unit</div>
                            </div>
                            <?php else: ?>
                            <div class="avatar avatar-navy">
                                <?= strtoupper(substr($j['nama_pegawai'] ?? 'P', 0, 1)) ?>
                            </div>
                            <div class="employee-info">
                                <div class="employee-name"><?= htmlspecialchars($j['nama_pegawai'] ?? '-') ?></div>
                                <div class="employee-unit"><?= htmlspecialchars($j['unit_pegawai'] ?? '-') ?></div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </td>
                    <td>
                        <div class="date-primary"><?= fmt_tgl($j['tanggal_mulai'], $bulan) ?></div>
                        <?php if ($j['tanggal_mulai'] !== $j['tanggal_selesai']): ?>
                        <div class="date-secondary">s/d <?= fmt_tgl($j['tanggal_selesai'], $bulan) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-lg-table-cell">
                        <span class="text-muted"><?= htmlspecialchars($j['keterangan']) ?></span>
                    </td>
                    <td>
                        <span class="status-badge <?= $statusClass ?>">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <?= $statusIcon ?>
                            </svg>
                            <?= $statusLabel ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <div class="action-buttons">
                            <button class="action-btn edit" title="Edit"
                                onclick="bukaEdit(
                                    <?= $j['id'] ?>,
                                    '<?= $j['berlaku_untuk'] ?>',
                                    '<?= $j['user_id'] ?? '' ?>',
                                    '<?= $j['tanggal_mulai'] ?>',
                                    '<?= $j['tanggal_selesai'] ?>',
                                    '<?= htmlspecialchars($j['keterangan'], ENT_QUOTES) ?>'
                                )">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                    <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                </svg>
                            </button>
                            <button class="action-btn delete" title="Hapus" onclick="hapus(<?= $j['id'] ?>)">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="3 6 5 6 21 6"></polyline>
                                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                </svg>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Help Button -->
    <button class="btn-help-float" id="btnHelp" title="Panduan WFA">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"></circle>
            <path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"></path>
            <line x1="12" y1="17" x2="12.01" y2="17"></line>
        </svg>
    </button>

</div>
</div>

<!-- ════ MODAL WFA ════ -->
<div class="modal fade" id="modalWFA" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content modal-modern">
            <div class="modal-header-modern">
                <div class="modal-icon">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                </div>
                <div>
                    <h5 id="modalTitle">Tambah Jadwal WFA</h5>
                    <p id="modalSubtitle">Isi detail jadwal Work From Anywhere</p>
                </div>
                <button type="button" class="btn-close-custom" data-bs-dismiss="modal">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="18" y1="6" x2="6" y2="18"></line>
                        <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                </button>
            </div>

            <form id="formWFA" action="proses_wfa.php" method="POST">
                <?php csrf_field(); ?>
                <input type="hidden" name="action" id="fAction" value="tambah">
                <input type="hidden" name="id" id="fId" value="">

                <div class="modal-body-modern">

                    <!-- Berlaku Untuk -->
                    <div class="form-group-modern">
                        <label class="form-label-modern">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="8.5" cy="7" r="4"></circle>
                                <polyline points="17 11 19 13 23 9"></polyline>
                            </svg>
                            Berlaku Untuk
                        </label>
                        <div class="radio-group-modern">
                            <label class="radio-card">
                                <input type="radio" name="berlaku_untuk" value="semua" id="rSemua" checked>
                                <div class="radio-content">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="9" cy="7" r="4"></circle>
                                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                                        <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
                                    </svg>
                                    <span>Semua Pegawai</span>
                                </div>
                            </label>
                            <label class="radio-card">
                                <input type="radio" name="berlaku_untuk" value="personal" id="rPersonal">
                                <div class="radio-content">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                        <circle cx="12" cy="7" r="4"></circle>
                                    </svg>
                                    <span>Pegawai Tertentu</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- Multi-select Pegawai -->
                    <div class="form-group-modern" id="wrapPgw" style="display:none;">
                        <label class="form-label-modern">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            Pilih Pegawai
                            <span id="isEditNote" style="font-size:.8rem;font-weight:400;color:#6b7280;display:none"> (saat edit: pilih satu pegawai)</span>
                        </label>

                        <!-- Search -->
                        <div style="position:relative;margin-bottom:.6rem">
                            <svg style="position:absolute;left:.75rem;top:50%;transform:translateY(-50%);width:16px;height:16px;color:#9ca3af;pointer-events:none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                            <input type="text" id="pgwSearch" class="form-control-modern" style="padding-left:2.5rem"
                                   placeholder="Cari nama pegawai..." oninput="filterDaftarPgw(this.value)">
                        </div>

                        <!-- Pills -->
                        <div id="pgwPills" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:.5rem;min-height:4px"></div>

                        <!-- Daftar checkbox -->
                        <div id="pgwList" style="max-height:220px;overflow-y:auto;border:2px solid var(--gray-lighter);border-radius:10px;background:#fff">

                            <!-- Select all row -->
                            <div id="pgwSelectAllRow" style="display:flex;align-items:center;gap:.6rem;padding:.55rem 1rem;border-bottom:2px solid var(--gray-lighter);background:#f9fafb;cursor:pointer">
                                <input type="checkbox" id="pgwSelectAll" style="width:16px;height:16px;accent-color:#1e3a5f;cursor:pointer" onchange="toggleSelectAll(this.checked)">
                                <label for="pgwSelectAll" style="font-size:.82rem;font-weight:600;color:#1e3a5f;cursor:pointer;margin:0">Pilih semua pegawai</label>
                            </div>

                            <?php foreach ($pegawai_list as $p): ?>
                            <label class="pgw-check-item-wfa"
                                   data-nama="<?= strtolower(htmlspecialchars($p['nama'])) ?>"
                                   data-uid="<?= $p['id'] ?>">
                                <input type="checkbox"
                                       name="user_id[]"
                                       value="<?= $p['id'] ?>"
                                       data-nama="<?= htmlspecialchars($p['nama'],ENT_QUOTES) ?>"
                                       onchange="onCheckPgw(this)">
                                <div class="pgw-item-av"><?= strtoupper(substr($p['nama'],0,1)) ?></div>
                                <div>
                                    <span class="pgw-item-nm"><?= htmlspecialchars($p['nama']) ?></span>
                                    <?php if ($p['unit_kerja']): ?>
                                    <span class="pgw-item-ut"><?= htmlspecialchars($p['unit_kerja']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </label>
                            <?php endforeach; ?>

                            <div id="pgwNoResult" style="display:none;padding:1.25rem;text-align:center;font-size:.82rem;color:#9ca3af">
                                Tidak ada pegawai yang cocok
                            </div>
                        </div>

                        <div id="pgwCountInfo" style="font-size:.75rem;color:#6b7280;margin-top:.4rem;text-align:right">
                            <strong style="color:#1e3a5f">0</strong> pegawai dipilih
                        </div>
                    </div>

                    <!-- Periode -->
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-group-modern">
                                <label class="form-label-modern">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                    </svg>
                                    Tanggal Mulai
                                </label>
                                <input type="date" name="tanggal_mulai" id="iMulai" class="form-control-modern" value="<?= $today ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group-modern">
                                <label class="form-label-modern">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                        <line x1="16" y1="2" x2="16" y2="6"></line>
                                        <line x1="8" y1="2" x2="8" y2="6"></line>
                                        <line x1="3" y1="10" x2="21" y2="10"></line>
                                    </svg>
                                    Tanggal Selesai
                                </label>
                                <input type="date" name="tanggal_selesai" id="iSelesai" class="form-control-modern" value="<?= $today ?>" required>
                            </div>
                        </div>
                    </div>

                    <!-- Keterangan -->
                    <div class="form-group-modern">
                        <label class="form-label-modern">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
                            </svg>
                            Keterangan
                        </label>
                        <input type="text" name="keterangan" id="iKet" class="form-control-modern"
                               placeholder="Contoh: Dinas luar kota" value="Work From Anywhere">
                    </div>

                    <!-- Info Box -->
                    <div class="info-box-modern">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="12" cy="12" r="10"></circle>
                            <line x1="12" y1="16" x2="12" y2="12"></line>
                            <line x1="12" y1="8" x2="12.01" y2="8"></line>
                        </svg>
                        <div>
                            <strong>Tentang WFA</strong>
                            <p>Pegawai dapat bekerja dari lokasi manapun tanpa validasi GPS. Foto selfie tetap diperlukan saat absensi.</p>
                        </div>
                    </div>
                </div>

                <div class="modal-footer-modern">
                    <button type="button" class="btn-secondary-modern" data-bs-dismiss="modal">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="18" y1="6" x2="6" y2="18"></line>
                            <line x1="6" y1="6" x2="18" y2="18"></line>
                        </svg>
                        Batal
                    </button>
                    <button type="submit" class="btn-primary-modern" id="btnSimpan">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path>
                            <polyline points="17 21 17 13 7 13 7 21"></polyline>
                            <polyline points="7 3 7 8 15 8"></polyline>
                        </svg>
                        <span id="btnLabel">Simpan</span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Welcome Tour Modal -->
<?php if ($showTour): ?>
<div class="modal fade" id="tourModal" tabindex="-1" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content tour-modal-content">
            <div class="modal-body text-center p-5">
                <div class="tour-icon-wrap mb-4">
                    <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M12 2L2 7l10 5 10-5-10-5z"></path>
                        <path d="M2 17l10 5 10-5M2 12l10 5 10-5"></path>
                    </svg>
                </div>
                <h3 class="mb-3">Selamat Datang!</h3>
                <p class="text-muted mb-4">
                    Ingin tour singkat untuk mengenal fitur kelola WFA?
                    <br><small class="text-muted">(Hanya 2 menit)</small>
                </p>
                <div class="d-grid gap-2">
                    <button class="btn-tour-start" id="btnStartTour">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <polygon points="5 3 19 12 5 21 5 3"></polygon>
                        </svg>
                        Mulai Tour
                    </button>
                    <button class="btn-tour-skip" id="btnSkipTour">Lewati</button>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
:root {
    --navy: #1e3a5f;
    --navy-light: #2d5a8f;
    --gold: #d4af37;
    --gold-light: #f0c860;
    --gray: #6b7280;
    --gray-light: #9ca3af;
    --gray-lighter: #e5e7eb;
    --gray-lightest: #f3f4f6;
    --white: #ffffff;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.06);
    --shadow: 0 2px 8px rgba(0,0,0,0.08);
    --shadow-lg: 0 4px 16px rgba(0,0,0,0.1);
    --radius: 12px;
    --radius-lg: 16px;
}

.page-header-wfa {
    display: flex; justify-content: space-between; align-items: center;
    flex-wrap: wrap; gap: 1.5rem; padding: 1.5rem;
    background: var(--white); border: 1px solid var(--gray-lighter);
    border-radius: var(--radius-lg); box-shadow: var(--shadow);
}
.header-content { display: flex; align-items: center; gap: 1.25rem; }
.header-icon-wrap {
    width: 56px; height: 56px;
    background: linear-gradient(135deg, var(--navy), var(--navy-light));
    border-radius: var(--radius); display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.header-icon { width: 28px; height: 28px; color: var(--white); }
.page-title { color: var(--navy); font-size: 1.5rem; font-weight: 700; margin: 0 0 .25rem; }
.page-subtitle { color: var(--gray); font-size: .9375rem; margin: 0; }
.btn-primary-wfa {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .875rem 1.5rem;
    background: linear-gradient(135deg, var(--gold), var(--gold-light));
    color: var(--navy); border: none; border-radius: 10px;
    font-weight: 600; font-size: .9375rem;
    box-shadow: 0 2px 8px rgba(212,175,55,.25); cursor: pointer;
    transition: all .3s ease;
}
.btn-primary-wfa:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(212,175,55,.35); }

.modern-alert {
    display: flex; align-items: center; gap: .75rem;
    padding: 1rem 1.25rem; border-radius: var(--radius); border: none; box-shadow: var(--shadow-sm);
}
.alert-success { background: linear-gradient(135deg,#ecfdf5,#d1fae5); color: #065f46; }
.alert-danger  { background: linear-gradient(135deg,#fef2f2,#fecaca); color: #991b1b; }

.wfa-active-banner {
    display: flex; align-items: center; gap: 1.25rem;
    padding: 1.25rem 1.5rem;
    background: linear-gradient(135deg,#ecfdf5,#d1fae5);
    border: 2px solid #10b981; border-radius: var(--radius-lg);
}
.banner-icon {
    width: 48px; height: 48px; background: var(--white);
    border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.banner-icon svg { color: #10b981; }
.banner-content { flex: 1; }
.banner-content h6 { color: #065f46; font-weight: 700; font-size: 1rem; margin: 0 0 .25rem; }
.banner-content p  { color: #047857; font-size: .875rem; margin: 0; }
.live-indicator {
    display: flex; align-items: center; gap: .5rem;
    padding: .5rem 1rem; background: var(--white); border-radius: 20px; flex-shrink: 0;
}
.live-dot { width: 8px; height: 8px; background: #10b981; border-radius: 50%; animation: pulse-dot 2s infinite; }
@keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:.5} }
.live-text { color: #10b981; font-weight: 600; font-size: .8125rem; }

.stat-card {
    display: flex; align-items: center; gap: 1rem; padding: 1.25rem;
    background: var(--white); border: 1px solid var(--gray-lighter);
    border-radius: var(--radius); box-shadow: var(--shadow-sm);
    transition: all .3s ease;
}
.stat-card:hover { transform: translateY(-2px); box-shadow: var(--shadow); }
.stat-icon {
    width: 48px; height: 48px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.stat-icon.blue   { background: linear-gradient(135deg,#dbeafe,#bfdbfe); color: #1e40af; }
.stat-icon.gray   { background: linear-gradient(135deg,var(--gray-lightest),var(--gray-lighter)); color: var(--gray); }
.stat-icon.gold   { background: linear-gradient(135deg,#fef3c7,#fde68a); color: #b45309; }
.stat-icon.navy   { background: linear-gradient(135deg,#e0e7ff,#c7d2fe); color: var(--navy); }
.stat-value { font-size: 1.75rem; font-weight: 700; color: var(--navy); line-height: 1; margin-bottom: .25rem; }
.stat-label { font-size: .8125rem; color: var(--gray); font-weight: 500; }

.table-card {
    background: var(--white); border: 1px solid var(--gray-lighter);
    border-radius: var(--radius-lg); box-shadow: var(--shadow); overflow: hidden;
}
.table-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 1.25rem 1.5rem; border-bottom: 1px solid var(--gray-lighter);
    background: var(--gray-lightest);
}
.table-title { display: flex; align-items: center; gap: .75rem; color: var(--navy); font-weight: 600; font-size: 1rem; }
.table-title svg { color: var(--gold); }
.count-badge {
    padding: .375rem .875rem; background: var(--white); color: var(--gray);
    font-size: .8125rem; font-weight: 600; border-radius: 20px; border: 1px solid var(--gray-lighter);
}
.table-modern { width: 100%; border-collapse: collapse; }
.table-modern thead th {
    padding: 1rem 1.25rem; text-align: left; font-size: .75rem; font-weight: 700;
    color: var(--gray); text-transform: uppercase; letter-spacing: .05em;
    background: var(--gray-lightest); border-bottom: 1px solid var(--gray-lighter);
}
.table-modern tbody tr { border-bottom: 1px solid var(--gray-lightest); transition: background .2s; }
.table-modern tbody tr:last-child { border-bottom: none; }
.table-modern tbody tr:hover { background: #fafbfc; }
.table-modern tbody td { padding: 1.125rem 1.25rem; vertical-align: middle; }

.employee-cell { display: flex; align-items: center; gap: .875rem; }
.avatar {
    width: 40px; height: 40px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: .875rem; font-weight: 700; flex-shrink: 0;
}
.avatar-purple { background: linear-gradient(135deg,#f3e8ff,#e9d5ff); color: #7c3aed; }
.avatar-navy   { background: linear-gradient(135deg,#dbeafe,#bfdbfe); color: var(--navy); }
.employee-name { font-weight: 600; color: var(--navy); font-size: .9375rem; margin-bottom: .125rem; }
.employee-unit { font-size: .8125rem; color: var(--gray); }
.date-primary   { font-weight: 600; color: var(--navy); font-size: .9375rem; margin-bottom: .125rem; }
.date-secondary { font-size: .8125rem; color: var(--gray); }

.status-badge {
    display: inline-flex; align-items: center; gap: .5rem;
    padding: .4375rem .875rem; border-radius: 20px;
    font-size: .8125rem; font-weight: 600; white-space: nowrap;
}
.status-active    { background: linear-gradient(135deg,#f0fdf4,#dcfce7); color: #166534; border: 1px solid #86efac; }
.status-upcoming  { background: linear-gradient(135deg,#eff6ff,#dbeafe); color: #1e40af; border: 1px solid #93c5fd; }
.status-completed { background: linear-gradient(135deg,var(--gray-lightest),var(--gray-lighter)); color: var(--gray); border: 1px solid var(--gray-lighter); }

.action-buttons { display: flex; gap: .5rem; justify-content: flex-end; }
.action-btn {
    width: 36px; height: 36px; border: 1px solid var(--gray-lighter);
    border-radius: 8px; background: var(--white); color: var(--gray);
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all .2s ease;
}
.action-btn:hover { background: var(--gray-lightest); border-color: var(--gray-light); }
.action-btn.edit:hover   { background: #eff6ff; border-color: #3b82f6; color: #3b82f6; }
.action-btn.delete:hover { background: #fef2f2; border-color: #ef4444; color: #ef4444; }

.empty-state { padding: 4rem 2rem; text-align: center; }
.empty-icon { margin-bottom: 1.5rem; }
.empty-icon svg { color: var(--gray-lighter); }
.empty-state h6 { color: var(--navy); font-weight: 600; font-size: 1.125rem; margin-bottom: .5rem; }
.empty-state p  { color: var(--gray); font-size: .9375rem; margin: 0; }

.btn-help-float {
    position: fixed; bottom: 2rem; right: 2rem;
    width: 56px; height: 56px; border-radius: 50%;
    background: linear-gradient(135deg, var(--gold), var(--gold-light));
    border: none; color: var(--navy);
    box-shadow: 0 4px 16px rgba(212,175,55,.3);
    cursor: pointer; transition: all .3s ease; z-index: 1000;
    display: flex; align-items: center; justify-content: center;
}
.btn-help-float:hover { transform: translateY(-4px) scale(1.05); box-shadow: 0 6px 20px rgba(212,175,55,.4); }

/* ── Modal ── */
.modal-modern { border: none; border-radius: var(--radius-lg); box-shadow: 0 20px 60px rgba(0,0,0,.15); }
.modal-header-modern {
    display: flex; align-items: flex-start; gap: 1.25rem;
    padding: 2rem; border-bottom: 1px solid var(--gray-lighter);
}
.modal-icon {
    width: 48px; height: 48px;
    background: linear-gradient(135deg, var(--gold), var(--gold-light));
    border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.modal-icon svg { color: var(--navy); }
.modal-header-modern h5 { color: var(--navy); font-size: 1.25rem; font-weight: 700; margin: 0 0 .25rem; }
.modal-header-modern p  { color: var(--gray); font-size: .875rem; margin: 0; }
.btn-close-custom {
    width: 32px; height: 32px; border: 1px solid var(--gray-lighter);
    border-radius: 8px; background: var(--white); color: var(--gray);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; margin-left: auto; transition: all .2s ease;
}
.btn-close-custom:hover { background: var(--gray-lightest); border-color: var(--gray); }
.modal-body-modern { padding: 2rem; }
.form-group-modern { margin-bottom: 1.5rem; }
.form-label-modern {
    display: flex; align-items: center; gap: .5rem;
    color: var(--navy); font-size: .9375rem; font-weight: 600; margin-bottom: .75rem;
}
.form-label-modern svg { color: var(--gold); }
.form-control-modern {
    width: 100%; padding: .875rem 1.125rem;
    border: 2px solid var(--gray-lighter); border-radius: 10px;
    font-size: .9375rem; transition: all .3s ease; background: var(--white);
    font-family: inherit;
}
.form-control-modern:focus { outline: none; border-color: var(--gold); box-shadow: 0 0 0 4px rgba(212,175,55,.1); }

.radio-group-modern { display: grid; grid-template-columns: repeat(auto-fit,minmax(180px,1fr)); gap: .75rem; }
.radio-card { position: relative; cursor: pointer; }
.radio-card input { position: absolute; opacity: 0; pointer-events: none; }
.radio-content {
    display: flex; align-items: center; gap: .75rem; padding: 1rem 1.25rem;
    border: 2px solid var(--gray-lighter); border-radius: 10px;
    background: var(--white); transition: all .3s ease;
}
.radio-content svg { color: var(--gray); flex-shrink: 0; }
.radio-content span { color: var(--navy); font-weight: 500; font-size: .9375rem; }
.radio-card input:checked + .radio-content { background: linear-gradient(135deg,#fef3c7,#fde68a); border-color: var(--gold); }
.radio-card input:checked + .radio-content svg,
.radio-card input:checked + .radio-content span { color: #92400e; }

/* Multi-select dalam modal WFA */
.pgw-check-item-wfa {
    display: flex; align-items: center; gap: .65rem;
    padding: .6rem 1rem; border-bottom: 1px solid var(--gray-lightest);
    cursor: pointer; transition: background .1s;
}
.pgw-check-item-wfa:last-child { border-bottom: none; }
.pgw-check-item-wfa:hover { background: var(--gray-lightest); }
.pgw-check-item-wfa input[type=checkbox] { width: 16px; height: 16px; accent-color: var(--navy); cursor: pointer; flex-shrink: 0; }
.pgw-check-item-wfa.is-checked { background: #fef3c7; }
.pgw-check-item-wfa.is-checked:hover { background: #fde68a; }
.pgw-item-av {
    width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
    background: linear-gradient(135deg,#fef3c7,#fde68a);
    color: #b45309; display: flex; align-items: center; justify-content: center;
    font-size: .75rem; font-weight: 700;
}
.pgw-item-nm { display: block; font-size: .84rem; font-weight: 600; color: var(--navy); }
.pgw-item-ut { display: block; font-size: .72rem; color: var(--gray); margin-top: 1px; }

/* Selected pill */
.selected-pill-wfa {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 8px 3px 10px;
    background: var(--navy); color: #fff;
    border-radius: 99px; font-size: .72rem; font-weight: 600;
}
.selected-pill-wfa .pill-x {
    background: none; border: none; color: rgba(255,255,255,.65);
    cursor: pointer; padding: 0; line-height: 1; font-size: 1rem;
    display: flex; align-items: center; transition: color .1s;
}
.selected-pill-wfa .pill-x:hover { color: #fff; }

.info-box-modern {
    display: flex; gap: 1rem; padding: 1.25rem;
    background: linear-gradient(135deg,#eff6ff,#dbeafe);
    border: 1px solid #93c5fd; border-radius: var(--radius);
}
.info-box-modern svg { color: #3b82f6; flex-shrink: 0; }
.info-box-modern strong { display: block; color: #1e40af; font-size: .9375rem; margin-bottom: .25rem; }
.info-box-modern p { color: #1e40af; font-size: .875rem; margin: 0; line-height: 1.5; }

.modal-footer-modern {
    display: flex; justify-content: flex-end; gap: .75rem;
    padding: 1.5rem 2rem; border-top: 1px solid var(--gray-lighter);
}
.btn-secondary-modern {
    display: inline-flex; align-items: center; gap: .5rem; padding: .75rem 1.5rem;
    background: var(--white); color: var(--gray); border: 2px solid var(--gray-lighter);
    border-radius: 10px; font-weight: 600; font-size: .9375rem; cursor: pointer;
    transition: all .3s ease; font-family: inherit;
}
.btn-secondary-modern:hover { background: var(--gray-lightest); border-color: var(--gray); }
.btn-primary-modern {
    display: inline-flex; align-items: center; gap: .5rem; padding: .75rem 1.5rem;
    background: linear-gradient(135deg, var(--navy), var(--navy-light));
    color: var(--white); border: none; border-radius: 10px;
    font-weight: 600; font-size: .9375rem; cursor: pointer;
    transition: all .3s ease; box-shadow: 0 2px 8px rgba(30,58,95,.25); font-family: inherit;
}
.btn-primary-modern:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(30,58,95,.35); }

.tour-modal-content { border: none; border-radius: var(--radius-lg); box-shadow: 0 20px 60px rgba(0,0,0,.2); }
.tour-icon-wrap {
    width: 100px; height: 100px;
    background: linear-gradient(135deg, var(--gold), var(--gold-light));
    border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
}
.tour-icon-wrap svg { color: var(--navy); }
.tour-modal-content h3 { color: var(--navy); font-weight: 700; }
.btn-tour-start {
    display: inline-flex; align-items: center; justify-content: center; gap: .625rem;
    padding: 1rem 2rem;
    background: linear-gradient(135deg, var(--gold), var(--gold-light));
    color: var(--navy); border: none; border-radius: 12px;
    font-weight: 700; font-size: 1.0625rem; cursor: pointer; transition: all .3s ease;
    font-family: inherit;
}
.btn-tour-start:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(212,175,55,.3); }
.btn-tour-skip {
    padding: .875rem 2rem; background: transparent; color: var(--gray);
    border: 2px solid var(--gray-lighter); border-radius: 12px;
    font-weight: 600; cursor: pointer; transition: all .3s ease; font-family: inherit;
}
.btn-tour-skip:hover { background: var(--gray-lightest); border-color: var(--gray); }

@media (max-width: 991.98px) {
    .page-header-wfa { flex-direction: column; align-items: stretch; }
    .btn-primary-wfa { width: 100%; justify-content: center; }
    .stat-value { font-size: 1.5rem; }
    .btn-help-float { bottom: 1rem; right: 1rem; width: 48px; height: 48px; }
}
@media (max-width: 767.98px) {
    .header-content { width: 100%; }
    .wfa-active-banner { flex-direction: column; text-align: center; }
    .live-indicator { width: 100%; justify-content: center; }
    .action-buttons { flex-direction: column; }
}
</style>

<!-- Driver.js + SweetAlert2 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css"/>
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
let isEditMode = false;

/* ═══════════════════════════════
   MULTI-SELECT PEGAWAI
═══════════════════════════════ */

// Toggle panel pegawai
document.querySelectorAll('input[name="berlaku_untuk"]').forEach(r => {
    r.addEventListener('change', function() {
        const show = this.value === 'personal';
        document.getElementById('wrapPgw').style.display = show ? 'block' : 'none';
        if (!show) resetMultiSelect();
    });
});

// Filter daftar
function filterDaftarPgw(q) {
    q = q.toLowerCase().trim();
    let adaHasil = false;
    document.querySelectorAll('#pgwList .pgw-check-item-wfa').forEach(el => {
        const cocok = el.dataset.nama.includes(q);
        el.style.display = cocok ? '' : 'none';
        if (cocok) adaHasil = true;
    });
    document.getElementById('pgwNoResult').style.display = adaHasil ? 'none' : 'block';
}

// Handler checkbox
function onCheckPgw(cb) {
    cb.closest('.pgw-check-item-wfa').classList.toggle('is-checked', cb.checked);
    renderPills();
    syncSelectAll();
}

// Select all / deselect all
function toggleSelectAll(checked) {
    document.querySelectorAll('#pgwList .pgw-check-item-wfa').forEach(el => {
        if (el.style.display !== 'none') {
            const cb = el.querySelector('input[type=checkbox]');
            cb.checked = checked;
            el.classList.toggle('is-checked', checked);
        }
    });
    renderPills();
}

function syncSelectAll() {
    const all     = document.querySelectorAll('#pgwList .pgw-check-item-wfa:not([style*="none"]) input[type=checkbox]');
    const checked = document.querySelectorAll('#pgwList .pgw-check-item-wfa:not([style*="none"]) input[type=checkbox]:checked');
    const saEl    = document.getElementById('pgwSelectAll');
    if (!saEl) return;
    saEl.indeterminate = checked.length > 0 && checked.length < all.length;
    saEl.checked       = all.length > 0 && checked.length === all.length;
}

// Render pills
function renderPills() {
    const checked = document.querySelectorAll('#pgwList input[type=checkbox]:checked');
    const wrap    = document.getElementById('pgwPills');
    const count   = document.getElementById('pgwCountInfo');
    wrap.innerHTML = '';
    checked.forEach(cb => {
        const pill = document.createElement('span');
        pill.className = 'selected-pill-wfa';
        pill.innerHTML = `${escHtml(cb.dataset.nama)}<button type="button" class="pill-x" onclick="uncheckPgw('${cb.value}')" title="Hapus">×</button>`;
        wrap.appendChild(pill);
    });
    count.innerHTML = `<strong style="color:#1e3a5f">${checked.length}</strong> pegawai dipilih`;
}

function uncheckPgw(val) {
    const cb = document.querySelector(`#pgwList input[value="${val}"]`);
    if (cb) { cb.checked = false; cb.closest('.pgw-check-item-wfa').classList.remove('is-checked'); }
    renderPills(); syncSelectAll();
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function resetMultiSelect() {
    document.querySelectorAll('#pgwList input[type=checkbox]').forEach(cb => {
        cb.checked = false;
        cb.closest('.pgw-check-item-wfa').classList.remove('is-checked');
        cb.closest('.pgw-check-item-wfa').style.display = '';
    });
    document.getElementById('pgwPills').innerHTML = '';
    document.getElementById('pgwCountInfo').innerHTML = '<strong style="color:#1e3a5f">0</strong> pegawai dipilih';
    document.getElementById('pgwSearch').value = '';
    document.getElementById('pgwNoResult').style.display = 'none';
    const saEl = document.getElementById('pgwSelectAll');
    if (saEl) { saEl.checked = false; saEl.indeterminate = false; }
}

/* ── Modal helpers ── */
function resetModal() {
    isEditMode = false;
    document.getElementById('modalTitle').textContent    = 'Tambah Jadwal WFA';
    document.getElementById('modalSubtitle').textContent = 'Isi detail jadwal Work From Anywhere';
    document.getElementById('fAction').value = 'tambah';
    document.getElementById('fId').value     = '';
    document.getElementById('btnLabel').textContent      = 'Simpan';
    document.getElementById('rSemua').checked            = true;
    document.getElementById('wrapPgw').style.display     = 'none';
    document.getElementById('isEditNote').style.display  = 'none';
    document.getElementById('pgwSelectAllRow').style.display = '';
    resetMultiSelect();
    const t = new Date().toISOString().split('T')[0];
    document.getElementById('iMulai').value  = t;
    document.getElementById('iSelesai').value = t;
    document.getElementById('iKet').value    = 'Work From Anywhere';
}

function bukaEdit(id, berlaku, userId, mulai, selesai, ket) {
    isEditMode = true;
    document.getElementById('modalTitle').textContent    = 'Edit Jadwal WFA';
    document.getElementById('modalSubtitle').textContent = 'Perbarui detail jadwal';
    document.getElementById('fAction').value = 'edit';
    document.getElementById('fId').value     = id;
    document.getElementById('btnLabel').textContent = 'Perbarui';

    if (berlaku === 'semua') {
        document.getElementById('rSemua').checked        = true;
        document.getElementById('wrapPgw').style.display = 'none';
    } else {
        document.getElementById('rPersonal').checked     = true;
        document.getElementById('wrapPgw').style.display = 'block';
        document.getElementById('isEditNote').style.display = 'inline';
        document.getElementById('pgwSelectAllRow').style.display = 'none';
        resetMultiSelect();
        if (userId) {
            const cb = document.querySelector(`#pgwList input[value="${userId}"]`);
            if (cb) { cb.checked = true; cb.closest('.pgw-check-item-wfa').classList.add('is-checked'); renderPills(); }
        }
    }

    document.getElementById('iMulai').value   = mulai;
    document.getElementById('iSelesai').value = selesai;
    document.getElementById('iKet').value     = ket;
    new bootstrap.Modal(document.getElementById('modalWFA')).show();
}

/* ── Form validation ── */
document.getElementById('formWFA').addEventListener('submit', function(e) {
    const m = new Date(document.getElementById('iMulai').value);
    const s = new Date(document.getElementById('iSelesai').value);
    if (s < m) {
        e.preventDefault();
        Swal.fire({ icon:'error', title:'Tanggal Tidak Valid', text:'Tanggal selesai tidak boleh sebelum tanggal mulai.', confirmButtonColor:'#1e3a5f' });
        return;
    }
    const berlaku = document.querySelector('input[name="berlaku_untuk"]:checked').value;
    if (berlaku === 'personal') {
        const checked = document.querySelectorAll('#pgwList input[type=checkbox]:checked');
        if (checked.length === 0) {
            e.preventDefault();
            Swal.fire({ icon:'warning', title:'Pilih Pegawai', text:'Pilih minimal satu pegawai terlebih dahulu.', confirmButtonColor:'#1e3a5f' });
        }
    }
});

/* ── Hapus ── */
function hapus(id) {
    Swal.fire({
        icon: 'warning', title: 'Hapus Jadwal?',
        text: 'Jadwal WFA ini akan dihapus permanen.',
        showCancelButton: true,
        confirmButtonText: 'Hapus', cancelButtonText: 'Batal',
        confirmButtonColor: '#ef4444', cancelButtonColor: '#6b7280'
    }).then(r => {
        if (r.isConfirmed) {
            const f = document.createElement('form');
            f.method = 'POST'; f.action = 'proses_wfa.php';
            f.innerHTML = `<input name="csrf_token" value="<?php echo htmlspecialchars(csrf_generate(), ENT_QUOTES, 'UTF-8'); ?>"><input name="action" value="hapus"><input name="id" value="${id}">`;
            document.body.appendChild(f); f.submit();
        }
    });
}

/* ── Driver.js Tour ── */
const driverObj = window.driver.js.driver({
    showProgress: true,
    steps: [
        {
            element: '#tour-header',
            popover: {
                title: '📍 Header Kelola WFA',
                description: 'Klik "Tambah Jadwal" untuk jadwal baru. Bisa untuk semua pegawai atau <strong>beberapa pegawai sekaligus</strong>.',
                side: 'bottom', align: 'start'
            }
        },
        <?php if ($wfa_aktif_semua): ?>
        {
            element: '#tour-banner',
            popover: {
                title: '🟢 Status WFA Aktif',
                description: 'Banner ini muncul saat ada jadwal WFA yang berlaku hari ini.',
                side: 'bottom', align: 'center'
            }
        },
        <?php endif; ?>
        {
            element: '#tour-stats',
            popover: {
                title: '📊 Statistik WFA',
                description: 'Jadwal aktif, selesai, pegawai WFA hari ini, dan total pegawai.',
                side: 'top', align: 'center'
            }
        },
        {
            element: '#tour-table',
            popover: {
                title: '📋 Daftar Jadwal',
                description: 'Edit atau hapus jadwal dengan tombol aksi di kolom kanan.',
                side: 'top', align: 'start'
            }
        },
        {
            element: '.btn-help-float',
            popover: {
                title: '❓ Tombol Bantuan',
                description: 'Klik kapan saja untuk melihat panduan lagi.',
                side: 'left', align: 'end'
            }
        }
    ],
    nextBtnText: 'Lanjut →',
    prevBtnText: '← Kembali',
    doneBtnText: '✓ Selesai',
    onDestroyStarted: () => {
        <?php if ($showTour): ?>
        window.location.href = '?tour_completed=1';
        <?php endif; ?>
        driverObj.destroy();
    }
});

<?php if ($showTour): ?>
const tourModal = new bootstrap.Modal(document.getElementById('tourModal'));
tourModal.show();
document.getElementById('btnStartTour').addEventListener('click', () => {
    tourModal.hide();
    setTimeout(() => driverObj.drive(), 500);
});
document.getElementById('btnSkipTour').addEventListener('click', () => {
    if (confirm('Yakin ingin melewati tour? Anda bisa melihatnya lagi dengan klik tombol (?) di pojok kanan bawah.')) {
        window.location.href = '?tour_completed=1';
    }
});
<?php endif; ?>

document.getElementById('btnHelp')?.addEventListener('click', () => driverObj.drive());
</script>

<?php include '../templates/footer.php'; ?>