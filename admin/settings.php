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

// ── Auto-migrate: tambah kolom toleransi jika belum ada ──────────────
try {
    $pdo->query("SELECT toleransi_terlambat FROM settings LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN `toleransi_terlambat` int NOT NULL DEFAULT 30 COMMENT 'Menit toleransi keterlambatan'");
}

// ── Ambil / buat settings ─────────────────────────────────────────────
$stmt    = $pdo->query("SELECT * FROM settings LIMIT 1");
$setting = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$setting) {
    $pdo->exec("INSERT INTO settings (id, jam_masuk, jam_pulang, latitude, longitude, radius, toleransi_terlambat)
                VALUES (1, '08:00:00', '17:00:00', -6.20000000, 106.80000000, 100, 30)");
    $setting = ['jam_masuk'=>'08:00:00','jam_pulang'=>'17:00:00','latitude'=>-6.2,'longitude'=>106.8,'radius'=>100,'toleransi_terlambat'=>30];
}
if (!isset($setting['toleransi_terlambat'])) $setting['toleransi_terlambat'] = 30;

// ── Handle: simpan JAM KERJA ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_jam'])) {
    csrf_verify();
    $jam_masuk        = trim($_POST['jam_masuk']           ?? '');
    $jam_pulang       = trim($_POST['jam_pulang']          ?? '');
    $toleransi        = (int)($_POST['toleransi_terlambat'] ?? 30);

    if (!strtotime($jam_masuk) || !strtotime($jam_pulang)) {
        $error_jam = "Format jam tidak valid.";
    } elseif ($toleransi < 0 || $toleransi > 180) {
        $error_jam = "Toleransi harus antara 0–180 menit.";
    } else {
        $pdo->prepare("UPDATE settings SET jam_masuk=?, jam_pulang=?, toleransi_terlambat=? WHERE id=1")
            ->execute([$jam_masuk, $jam_pulang, $toleransi]);
        header("Location: settings.php?success=jam"); exit();
    }
}

// ── Handle: simpan LOKASI ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_lokasi'])) {
    csrf_verify();
    $lat    = trim($_POST['latitude']  ?? '');
    $lng    = trim($_POST['longitude'] ?? '');
    $radius = (int)($_POST['radius']   ?? 100);

    if (!is_numeric($lat) || !is_numeric($lng)) {
        $error_lokasi = "Koordinat harus berupa angka.";
    } elseif ($radius < 10 || $radius > 2000) {
        $error_lokasi = "Radius harus antara 10–2000 meter.";
    } else {
        $pdo->prepare("UPDATE settings SET latitude=?, longitude=?, radius=? WHERE id=1")
            ->execute([$lat, $lng, $radius]);
        header("Location: settings.php?success=lokasi"); exit();
    }
}

// Handle: toggle MAINTENANCE MODE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_maintenance'])) {
    csrf_verify();
    try { $pdo->query("SELECT maintenance_mode FROM settings LIMIT 1"); }
    catch (PDOException $e) {
        $pdo->exec("ALTER TABLE settings ADD COLUMN `maintenance_mode` tinyint(1) NOT NULL DEFAULT 0");
    }
    $mode = (int)($_POST['maintenance_mode'] ?? 0);
    $pdo->prepare("UPDATE settings SET maintenance_mode=? WHERE id=1")->execute([$mode]);
    header("Location: settings.php?success=maintenance"); exit();
}
try { $maint_mode = (int)($setting['maintenance_mode'] ?? 0); } catch (Throwable $e) { $maint_mode = 0; }

$lat_cur       = (float)$setting['latitude'];
$lng_cur       = (float)$setting['longitude'];
$rad_cur       = (int)$setting['radius'];
$toleransi_cur = (int)$setting['toleransi_terlambat'];

// Hitung jam batas terlambat untuk preview
$batas_dt = new DateTime($setting['jam_masuk']);
$batas_dt->modify('+' . $toleransi_cur . ' minutes');
$batas_terlambat_display = $batas_dt->format('H:i');
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=Geist:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">

<div class="main-content st-root">
<div class="st-page">

    <!-- ── HERO HEADER ─────────────────────────────────────────── -->
    <header class="st-hero" id="tour-header">
        <div class="st-hero-glow"></div>
        <div class="st-hero-grid"></div>
        <div class="st-hero-inner">
            <div>
                <div class="st-eyebrow">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 0 1 1.93 15.14M4.93 4.93A10 10 0 0 0 3 12a10 10 0 0 0 3.93 8.07"/><path d="M14.12 2.07a10 10 0 0 1 5.81 7.79M9.88 2.07A10 10 0 0 0 4.07 9.86"/><path d="M14.12 21.93a10 10 0 0 0 5.81-7.79M9.88 21.93A10 10 0 0 1 4.07 14.14"/></svg>
                    Konfigurasi Sistem
                </div>
                <h1 class="st-hero-title">Pengaturan <em>Absensi</em></h1>
                <p class="st-hero-sub">Konfigurasi jam kerja, toleransi keterlambatan, dan zona lokasi kantor</p>
            </div>
            <div class="st-hero-right">
                <button class="st-guide-btn" onclick="startTour()" id="tour-guide-btn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    Panduan
                </button>
            </div>
        </div>

        <!-- Config summary strip -->
        <div class="st-hero-strip">
            <div class="st-strip-item">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span>Masuk <strong><?= date('H:i', strtotime($setting['jam_masuk'])) ?></strong></span>
            </div>
            <div class="st-strip-sep"></div>
            <div class="st-strip-item">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                <span>Pulang <strong><?= date('H:i', strtotime($setting['jam_pulang'])) ?></strong></span>
            </div>
            <div class="st-strip-sep"></div>
            <div class="st-strip-item st-strip-item--warn">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span>Toleransi <strong><?= $toleransi_cur ?> menit</strong> → batas <?= $batas_terlambat_display ?></span>
            </div>
            <div class="st-strip-sep"></div>
            <div class="st-strip-item">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 6.9 8 11.7z"/></svg>
                <span>Radius <strong><?= $rad_cur ?>m</strong></span>
            </div>
        </div>
    </header>

    <!-- ── ALERT ──────────────────────────────────────────────── -->
    <?php if (isset($_GET['success'])): ?>
    <div class="st-alert st-alert--success" id="pageAlert">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?= $_GET['success'] === 'jam' ? 'Jam kerja &amp; toleransi keterlambatan berhasil disimpan.' : 'Lokasi kantor berhasil disimpan.' ?>
        <button class="st-alert-close" onclick="this.parentElement.remove()">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <?php endif; ?>

    <!-- ── 2-COL GRID ─────────────────────────────────────────── -->
    <div class="st-grid">

        <!-- ══ JAM KERJA + TOLERANSI ══ -->
        <div class="st-card" id="tour-jam">
            <div class="st-card-head">
                <div class="st-card-icon st-card-icon--navy">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div>
                    <div class="st-card-title">Jam Kerja</div>
                    <div class="st-card-sub">Waktu masuk, pulang &amp; toleransi keterlambatan</div>
                </div>
            </div>

            <!-- Current time display -->
            <div class="st-time-row">
                <div class="st-time-chip st-time-chip--in">
                    <div class="st-time-ico">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 1 0 10 10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <div class="st-time-lbl">Jam Masuk</div>
                        <div class="st-time-val"><?= date('H:i', strtotime($setting['jam_masuk'])) ?></div>
                    </div>
                </div>
                <div class="st-time-arrow">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                </div>
                <div class="st-time-chip st-time-chip--out">
                    <div class="st-time-ico">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div>
                        <div class="st-time-lbl">Jam Pulang</div>
                        <div class="st-time-val"><?= date('H:i', strtotime($setting['jam_pulang'])) ?></div>
                    </div>
                </div>
            </div>

            <!-- Toleransi indicator -->
            <div class="st-toleransi-display" id="toleransiDisplay">
                <div class="st-tol-left">
                    <div class="st-tol-icon">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                    </div>
                    <div>
                        <div class="st-tol-title">Batas Toleransi Keterlambatan</div>
                        <div class="st-tol-sub">Absen setelah waktu ini dinyatakan <strong>Terlambat</strong></div>
                    </div>
                </div>
                <div class="st-tol-time" id="tolDisplayTime"><?= $batas_terlambat_display ?></div>
            </div>

            <?php if (isset($error_jam)): ?>
            <div class="st-alert st-alert--danger mb">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($error_jam) ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <?php csrf_field(); ?>
                <!-- Jam Masuk & Pulang — Analog Clock Picker -->
                <div class="clk-pair-row">
                    <!-- JAM MASUK -->
                    <div class="clk-field">
                        <div class="clk-label">
                            <span class="clk-dot clk-dot--in"></span>
                            Jam Masuk <span class="st-req">*</span>
                        </div>
                        <div class="clk-wrap" id="clkWrapMasuk" onclick="openClock('masuk')">
                            <div class="clk-face" id="clkFaceMasuk">
                                <!-- ticks -->
                                <div class="clk-ticks" id="clkTicksMasuk"></div>
                                <!-- hands -->
                                <div class="clk-hand clk-hand--hour"  id="clkHourMasuk"></div>
                                <div class="clk-hand clk-hand--min"   id="clkMinMasuk"></div>
                                <div class="clk-center"></div>
                                <!-- digital overlay -->
                                <div class="clk-digital" id="clkDigMasuk">08:00</div>
                            </div>
                            <div class="clk-edit-hint">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Klik untuk ubah
                            </div>
                        </div>
                        <input type="hidden" name="jam_masuk" id="jamMasuk" value="<?= htmlspecialchars(date('H:i', strtotime($setting['jam_masuk']))) ?>" required>
                        <div class="st-hint">Pegawai harus absen sebelum batas toleransi</div>
                    </div>

                    <div class="clk-vs">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </div>

                    <!-- JAM PULANG -->
                    <div class="clk-field">
                        <div class="clk-label">
                            <span class="clk-dot clk-dot--out"></span>
                            Jam Pulang <span class="st-req">*</span>
                        </div>
                        <div class="clk-wrap" id="clkWrapPulang" onclick="openClock('pulang')">
                            <div class="clk-face" id="clkFacePulang">
                                <div class="clk-ticks" id="clkTicksPulang"></div>
                                <div class="clk-hand clk-hand--hour"  id="clkHourPulang"></div>
                                <div class="clk-hand clk-hand--min"   id="clkMinPulang"></div>
                                <div class="clk-center"></div>
                                <div class="clk-digital" id="clkDigPulang">17:00</div>
                            </div>
                            <div class="clk-edit-hint">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                                Klik untuk ubah
                            </div>
                        </div>
                        <input type="hidden" name="jam_pulang" id="jamPulang" value="<?= htmlspecialchars(date('H:i', strtotime($setting['jam_pulang']))) ?>" required>
                        <div class="st-hint">Absen pulang dibuka setelah jam ini</div>
                    </div>
                </div>

                <!-- ── CLOCK PICKER MODAL ───────────────────────────────── -->
                <div class="clk-modal-bg" id="clkModalBg" onclick="closeClock()"></div>
                <div class="clk-modal" id="clkModal">
                    <div class="clk-modal-head">
                        <div>
                            <div class="clk-modal-title" id="clkModalTitle">Jam Masuk</div>
                            <div class="clk-modal-sub">Pilih jam &amp; menit</div>
                        </div>
                        <button type="button" class="clk-modal-close" onclick="closeClock()">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        </button>
                    </div>

                    <!-- Big digital display -->
                    <div class="clk-modal-dig">
                        <span class="clk-dig-h" id="clkPickH">08</span>
                        <span class="clk-dig-sep">:</span>
                        <span class="clk-dig-m" id="clkPickM">00</span>
                    </div>

                    <!-- Interactive SVG clock -->
                    <div class="clk-picker-wrap">
                        <svg id="clkPickerSvg" class="clk-picker-svg" viewBox="0 0 220 220" xmlns="http://www.w3.org/2000/svg">
                            <!-- Background circle -->
                            <circle cx="110" cy="110" r="105" fill="#F8FAFC" stroke="#E2E8F0" stroke-width="1.5"/>
                            <!-- Hour markers -->
                            <g id="clkPickerMarkers"></g>
                            <!-- Selection arc -->
                            <circle id="clkSelArc" cx="110" cy="110" r="88" fill="none" stroke="var(--navy)" stroke-width="2" stroke-opacity="0.08" stroke-dasharray="0 553"/>
                            <!-- Hour hand -->
                            <line id="clkPickHourLine" x1="110" y1="110" x2="110" y2="45" stroke="var(--navy)" stroke-width="4" stroke-linecap="round"/>
                            <!-- Minute hand -->
                            <line id="clkPickMinLine" x1="110" y1="110" x2="110" y2="30" stroke="var(--gold)" stroke-width="2.5" stroke-linecap="round"/>
                            <!-- Center dot -->
                            <circle cx="110" cy="110" r="5" fill="var(--navy)"/>
                            <!-- Touch target rings (invisible, draggable) -->
                            <circle id="clkHourRing"  cx="110" cy="110" r="72" fill="none" stroke="transparent" stroke-width="28" style="cursor:pointer"/>
                            <circle id="clkMinRing"   cx="110" cy="110" r="30" fill="none" stroke="transparent" stroke-width="24" style="cursor:pointer"/>
                        </svg>
                        <div class="clk-picker-mode-row">
                            <button type="button" class="clk-mode-btn clk-mode-btn--active" id="btnModeH" onclick="setPickMode('h')">JAM</button>
                            <button type="button" class="clk-mode-btn" id="btnModeM" onclick="setPickMode('m')">MENIT</button>
                        </div>
                    </div>

                    <!-- Quick presets -->
                    <div class="clk-presets" id="clkPresets"></div>

                    <div class="clk-modal-foot">
                        <button type="button" class="clk-cancel-btn" onclick="closeClock()">Batal</button>
                        <button type="button" class="clk-ok-btn" onclick="confirmClock()">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            Terapkan
                        </button>
                    </div>
                </div>

                <!-- ─── TOLERANSI KETERLAMBATAN (FITUR BARU) ─────── -->
                <div class="st-field" id="tour-toleransi">
                    <label class="st-label">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
                        Toleransi Keterlambatan <span class="st-req">*</span>
                        <span class="st-badge-inline st-badge-inline--warn" id="tolBadge"><?= $toleransi_cur ?> menit</span>
                    </label>

                    <input type="range" id="tolSlider" name="toleransi_terlambat"
                           min="0" max="180" step="5"
                           value="<?= $toleransi_cur ?>"
                           class="st-range st-range--warn">
                    <div class="st-range-scale">
                        <span>0 mnt</span><span>30</span><span>60</span><span>90</span><span>120</span><span>180</span>
                    </div>

                    <!-- Visual timeline -->
                    <div class="st-timeline" id="tolTimeline">
                        <div class="st-tl-seg st-tl-seg--green">
                            <div class="st-tl-label">Tepat Waktu</div>
                            <div class="st-tl-range" id="tlRangeTepat">
                                <?= date('H:i', strtotime($setting['jam_masuk'])) ?>
                            </div>
                        </div>
                        <div class="st-tl-arrow">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </div>
                        <div class="st-tl-seg st-tl-seg--amber">
                            <div class="st-tl-label">Toleransi</div>
                            <div class="st-tl-range">
                                s/d <span id="tlBatas"><?= $batas_terlambat_display ?></span>
                            </div>
                        </div>
                        <div class="st-tl-arrow">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                        </div>
                        <div class="st-tl-seg st-tl-seg--red">
                            <div class="st-tl-label">Terlambat</div>
                            <div class="st-tl-range">Setelah <span id="tlTerlambat"><?= $batas_terlambat_display ?></span></div>
                        </div>
                    </div>

                    <div class="st-hint">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        Set <strong>0 menit</strong> untuk tidak ada toleransi — absen telat 1 menit langsung Terlambat
                    </div>
                </div>

                <!-- Durasi kerja preview -->
                <div class="st-durasi-box" id="durasiBox">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Durasi kerja: <strong id="durasiText">—</strong>
                </div>

                <button type="submit" name="simpan_jam" class="st-submit-btn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Simpan Jam Kerja &amp; Toleransi
                </button>
            </form>
        </div>

        <!-- ══ LOKASI KANTOR ══ -->
        <div class="st-card" id="tour-lokasi">
            <div class="st-card-head">
                <div class="st-card-icon st-card-icon--gold">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 6.9 8 11.7z"/></svg>
                </div>
                <div>
                    <div class="st-card-title">Lokasi Kantor</div>
                    <div class="st-card-sub">Koordinat GPS &amp; radius zona absensi</div>
                </div>
            </div>

            <!-- Current coords -->
            <div class="st-loc-strip">
                <div class="st-loc-pin">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 6.9 8 11.7z"/></svg>
                </div>
                <div>
                    <div class="st-loc-coords" id="locCoordsDisplay"><?= number_format($lat_cur,6) ?>, <?= number_format($lng_cur,6) ?></div>
                    <div class="st-loc-radius">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/></svg>
                        Radius <?= $rad_cur ?> meter dari titik ini
                    </div>
                </div>
            </div>

            <?php if (isset($error_lokasi)): ?>
            <div class="st-alert st-alert--danger mb">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                <?= htmlspecialchars($error_lokasi) ?>
            </div>
            <?php endif; ?>

            <form method="POST">
                <?php csrf_field(); ?>
                <div class="st-coord-row">
                    <div class="st-field">
                        <label class="st-label">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="2" x2="12" y2="22"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                            Latitude
                        </label>
                        <input type="text" name="latitude" id="latitude" class="st-input st-input-mono"
                               value="<?= htmlspecialchars($setting['latitude']) ?>" placeholder="-6.20000000" required>
                    </div>
                    <div class="st-field">
                        <label class="st-label">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10z"/></svg>
                            Longitude
                        </label>
                        <input type="text" name="longitude" id="longitude" class="st-input st-input-mono"
                               value="<?= htmlspecialchars($setting['longitude']) ?>" placeholder="106.80000000" required>
                    </div>
                </div>

                <!-- Radius slider -->
                <div class="st-field" id="tour-radius">
                    <label class="st-label">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="4"/></svg>
                        Radius Absensi
                        <span class="st-badge-inline st-badge-inline--navy" id="radiusBadge"><?= $rad_cur ?> m</span>
                    </label>
                    <input type="range" name="radius" id="radiusSlider" class="st-range st-range--navy"
                           value="<?= $rad_cur ?>" min="10" max="2000" step="10">
                    <div class="st-range-scale">
                        <span>10m</span><span>500m</span><span>1000m</span><span>2000m</span>
                    </div>
                    <div class="st-hint">
                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        Lingkaran biru di peta menyesuaikan secara langsung
                    </div>
                </div>

                <button type="button" class="st-btn-outline w-100 mb" id="btnGetLoc">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="22" y1="12" x2="18" y2="12"/><line x1="6" y1="12" x2="2" y2="12"/><line x1="12" y1="6" x2="12" y2="2"/><line x1="12" y1="22" x2="12" y2="18"/></svg>
                    Gunakan Lokasi Saya Saat Ini
                </button>
                <button type="submit" name="simpan_lokasi" class="st-submit-btn">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Simpan Lokasi Kantor
                </button>
            </form>
        </div>

    </div><!-- /st-grid -->

    <!-- ── MAP ────────────────────────────────────────────────── -->
    <div class="st-card st-card-map" id="tour-map">
        <div class="st-card-head">
            <div class="st-card-icon st-card-icon--teal">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="3 6 9 3 15 6 21 3 21 18 15 21 9 18 3 21"/><line x1="9" y1="3" x2="9" y2="18"/><line x1="15" y1="6" x2="15" y2="21"/></svg>
            </div>
            <div>
                <div class="st-card-title">Peta Lokasi Kantor</div>
                <div class="st-card-sub">Klik peta atau seret pin untuk memilih koordinat · powered by OpenStreetMap</div>
            </div>
            <div class="st-map-legend">
                <span class="st-legend-dot st-legend-dot--marker"></span><span>Kantor</span>
                <span class="st-legend-dot st-legend-dot--radius"></span><span>Zona absensi</span>
            </div>
        </div>
        <div id="leafletMap" class="st-map"></div>
        <div class="st-map-tip">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:var(--gold);flex-shrink:0"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
            Klik sembarang titik di peta untuk mengatur koordinat kantor. Lingkaran biru menunjukkan zona absensi sesuai radius.
        </div>
    </div>

    <!-- ── FEATURE CHIPS ──────────────────────────────────────── -->
    <div class="st-features" id="tour-features">
        <div class="st-feature">
            <div class="st-feat-icon st-feat-icon--blue">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div class="st-feat-title">Validasi Otomatis</div>
            <div class="st-feat-sub">Lokasi &amp; waktu divalidasi sistem secara real-time saat absen</div>
        </div>
        <div class="st-feature">
            <div class="st-feat-icon st-feat-icon--amber">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            </div>
            <div class="st-feat-title">Toleransi Fleksibel</div>
            <div class="st-feat-sub">Batas keterlambatan dikonfigurasi 0–180 menit sesuai kebijakan instansi</div>
        </div>
        <div class="st-feature">
            <div class="st-feat-icon st-feat-icon--green">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="st-feat-title">Status Real-Time</div>
            <div class="st-feat-sub">Tepat Waktu / Terlambat dihitung otomatis berdasarkan konfigurasi ini</div>
        </div>
        <div class="st-feature">
            <div class="st-feat-icon st-feat-icon--gold">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="10" r="3"/><path d="M12 21.7C17.3 17 20 13 20 10a8 8 0 1 0-16 0c0 3 2.7 6.9 8 11.7z"/></svg>
            </div>
            <div class="st-feat-title">Zona GPS</div>
            <div class="st-feat-sub">WFO hanya bisa absen dalam radius yang ditentukan dari koordinat kantor</div>
        </div>
    </div>

</div><!-- /st-page -->
</div><!-- /main-content -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>

<style>
:root{
    --gold:#C9A84C; --gold-lt:#FBF3DE; --gold-dk:#A8862E;
    --navy:#0E1E3D; --navy-2:#172B55; --navy-3:#1E3A6E;
    --slate:#64748B; --slate-lt:#F1F5F9; --slate-2:#E2E8F0;
    --white:#FFFFFF; --text:#1E293B; --text-2:#475569; --text-3:#94A3B8;
    --green:#16a34a; --green-lt:#dcfce7;
    --amber:#d97706; --amber-lt:#fef3c7;
    --red:#dc2626;   --red-lt:#fee2e2;
    --teal:#0d9488;  --teal-lt:#ccfbf1;
    --blue:#1d4ed8;  --blue-lt:#dbeafe;
    --r-sm:6px; --r-md:10px; --r-lg:14px; --r-xl:18px;
    --sh-sm:0 1px 3px rgba(0,0,0,.06);
    --font-head:'DM Serif Display',Georgia,serif;
    --font-body:'Geist','SF Pro Display',system-ui,sans-serif;
}
.st-root *{box-sizing:border-box;margin:0;padding:0}
.st-root{font-family:var(--font-body);color:var(--text);background:#F8FAFC;min-height:100vh}
.st-page{max-width:1440px;margin:0 auto;padding:1.5rem 1.75rem 4rem;display:flex;flex-direction:column;gap:1.15rem}

/* ── HERO ── */
.st-hero{
    position:relative;
    background:linear-gradient(135deg,var(--navy) 0%,var(--navy-3) 60%,#1B3A72 100%);
    border-radius:var(--r-xl);overflow:hidden;
    box-shadow:0 8px 32px rgba(14,30,61,.28);
}
.st-hero-glow{position:absolute;inset:0;pointer-events:none;background:radial-gradient(ellipse 60% 80% at 90% 50%,rgba(201,168,76,.13) 0%,transparent 70%)}
.st-hero-grid{
    position:absolute;inset:0;pointer-events:none;
    background-image:linear-gradient(rgba(255,255,255,.025) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.025) 1px,transparent 1px);
    background-size:32px 32px;
}
.st-hero-inner{
    position:relative;z-index:1;
    display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;
    gap:1rem;padding:2rem 2.25rem 1.25rem;
}
.st-eyebrow{display:inline-flex;align-items:center;gap:.4rem;font-size:.6rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--gold);margin-bottom:.5rem}
.st-hero-title{font-family:var(--font-head);font-size:1.9rem;font-weight:400;line-height:1.1;color:#fff;margin-bottom:.35rem}
.st-hero-title em{color:var(--gold);font-style:italic}
.st-hero-sub{font-size:.74rem;color:rgba(255,255,255,.5)}
.st-guide-btn{
    display:inline-flex;align-items:center;gap:.4rem;
    font-family:var(--font-body);font-size:.72rem;font-weight:600;
    color:rgba(255,255,255,.7);background:rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.15);border-radius:var(--r-md);
    padding:.52rem .95rem;cursor:pointer;transition:all .2s;
}
.st-guide-btn:hover{background:rgba(255,255,255,.14);color:#fff}

/* Hero strip */
.st-hero-strip{
    position:relative;z-index:1;
    display:flex;align-items:center;flex-wrap:wrap;gap:0;
    padding:.85rem 2.25rem;
    border-top:1px solid rgba(255,255,255,.08);
    background:rgba(0,0,0,.15);
}
.st-strip-item{display:flex;align-items:center;gap:.45rem;font-size:.73rem;color:rgba(255,255,255,.65);padding:.2rem 1.25rem .2rem 0}
.st-strip-item svg{flex-shrink:0}
.st-strip-item strong{color:#fff}
.st-strip-item--warn{color:#FCD34D}
.st-strip-item--warn strong{color:#FDE68A}
.st-strip-sep{width:1px;height:16px;background:rgba(255,255,255,.12);margin-right:1.25rem}

/* ── ALERT ── */
@keyframes slideDown{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.st-alert{display:flex;align-items:center;gap:.65rem;padding:.8rem 1rem;border-radius:var(--r-md);font-size:.78rem;font-weight:500;animation:slideDown .3s ease}
.st-alert--success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.st-alert--danger {background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.st-alert-close{margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.6;padding:.15rem}
.st-alert-close:hover{opacity:1}
.mb{margin-bottom:.85rem}

/* ── GRID ── */
.st-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.15rem}

/* ── CARD ── */
.st-card{background:var(--white);border:1px solid var(--slate-2);border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh-sm);padding:1.4rem}
.st-card-map{padding-bottom:0}
.st-card-head{display:flex;align-items:flex-start;gap:.85rem;margin-bottom:1.25rem}
.st-card-icon{width:42px;height:42px;border-radius:var(--r-md);flex-shrink:0;display:flex;align-items:center;justify-content:center}
.st-card-icon--navy {background:rgba(14,30,61,.08); color:var(--navy)}
.st-card-icon--gold {background:var(--gold-lt);     color:var(--gold-dk)}
.st-card-icon--teal {background:var(--teal-lt);     color:var(--teal)}
.st-card-title{font-size:.88rem;font-weight:700;color:var(--navy)}
.st-card-sub{font-size:.72rem;color:var(--text-3);margin-top:.15rem}

/* ── TIME DISPLAY ── */
.st-time-row{display:flex;align-items:center;gap:.75rem;background:var(--slate-lt);border:1px solid var(--slate-2);border-radius:var(--r-md);padding:.85rem 1rem;margin-bottom:1.1rem}
.st-time-chip{display:flex;align-items:center;gap:.65rem;flex:1}
.st-time-ico{width:36px;height:36px;border-radius:var(--r-sm);display:flex;align-items:center;justify-content:center}
.st-time-chip--in .st-time-ico{background:#fef3c7;color:#d97706}
.st-time-chip--out .st-time-ico{background:#dbeafe;color:#1e40af}
.st-time-lbl{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-3)}
.st-time-val{font-size:1.25rem;font-weight:800;color:var(--navy);letter-spacing:-.02em;line-height:1}
.st-time-arrow{color:var(--text-3)}

/* ── TOLERANSI DISPLAY ── */
.st-toleransi-display{
    display:flex;align-items:center;justify-content:space-between;gap:1rem;
    background:linear-gradient(135deg,#fef3c7,#fffbeb);
    border:1px solid #fcd34d;border-radius:var(--r-md);
    padding:.9rem 1.1rem;margin-bottom:1.15rem;
}
.st-tol-left{display:flex;align-items:center;gap:.75rem}
.st-tol-icon{width:36px;height:36px;border-radius:var(--r-sm);background:rgba(217,119,6,.12);color:var(--amber);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.st-tol-title{font-size:.78rem;font-weight:700;color:#92400e}
.st-tol-sub{font-size:.67rem;color:#a16207;margin-top:.1rem}
.st-tol-time{font-size:1.35rem;font-weight:800;color:var(--amber);letter-spacing:-.02em;white-space:nowrap}

/* ── FORM ── */
.st-field{margin-bottom:.95rem}
.st-label{display:flex;align-items:center;gap:.35rem;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-2);margin-bottom:.38rem}
.st-req{color:var(--red);font-weight:700}
.st-input{width:100%;padding:.52rem .9rem;border:1.5px solid var(--slate-2);border-radius:var(--r-md);font-family:var(--font-body);font-size:.8rem;color:var(--text);background:#fff;outline:none;transition:border-color .2s}
.st-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.12)}
.st-input-mono{font-family:monospace;font-size:.78rem}
.st-hint{display:flex;align-items:flex-start;gap:.35rem;font-size:.67rem;color:var(--text-3);margin-top:.35rem;line-height:1.5}
.st-coord-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}

/* Range sliders */
.st-range{width:100%;height:4px;appearance:none;border-radius:3px;cursor:pointer;outline:none;margin-top:.3rem}
.st-range--warn{background:linear-gradient(to right,var(--amber) 0%,var(--amber) <?= ($toleransi_cur/180*100) ?>%,var(--slate-2) <?= ($toleransi_cur/180*100) ?>%,var(--slate-2) 100%)}
.st-range--navy{background:linear-gradient(to right,var(--navy) 0%,var(--navy) <?= (($rad_cur-10)/1990*100) ?>%,var(--slate-2) <?= (($rad_cur-10)/1990*100) ?>%,var(--slate-2) 100%)}
.st-range::-webkit-slider-thumb{appearance:none;width:18px;height:18px;border-radius:50%;border:3px solid #fff;box-shadow:0 1px 6px rgba(0,0,0,.2);cursor:pointer}
.st-range--warn::-webkit-slider-thumb{background:var(--amber)}
.st-range--navy::-webkit-slider-thumb{background:var(--navy)}
.st-range::-moz-range-thumb{width:18px;height:18px;border-radius:50%;border:3px solid #fff;box-shadow:0 1px 6px rgba(0,0,0,.2);cursor:pointer}
.st-range--warn::-moz-range-thumb{background:var(--amber)}
.st-range--navy::-moz-range-thumb{background:var(--navy)}
.st-range-scale{display:flex;justify-content:space-between;font-size:.59rem;color:var(--text-3);margin-top:.28rem}

/* Inline badge */
.st-badge-inline{display:inline-flex;align-items:center;margin-left:auto;padding:.12rem .52rem;border-radius:20px;font-size:.65rem;font-weight:700;text-transform:none;letter-spacing:0}
.st-badge-inline--warn{background:var(--amber-lt);color:#92400e;border:1px solid #fcd34d}
.st-badge-inline--navy{background:rgba(14,30,61,.08);color:var(--navy);border:1px solid rgba(14,30,61,.15)}

/* Timeline visual */
.st-timeline{display:flex;align-items:stretch;gap:.4rem;margin:.65rem 0;border-radius:var(--r-md);overflow:hidden;border:1px solid var(--slate-2)}
.st-tl-seg{flex:1;padding:.55rem .65rem}
.st-tl-seg--green{background:var(--green-lt);border-right:1px solid var(--slate-2)}
.st-tl-seg--amber{background:var(--amber-lt);border-right:1px solid var(--slate-2)}
.st-tl-seg--red  {background:var(--red-lt)}
.st-tl-label{font-size:.58rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.18rem}
.st-tl-seg--green .st-tl-label{color:var(--green)}
.st-tl-seg--amber .st-tl-label{color:var(--amber)}
.st-tl-seg--red   .st-tl-label{color:var(--red)}
.st-tl-range{font-size:.7rem;font-weight:600;color:var(--text)}
.st-tl-arrow{display:flex;align-items:center;padding:.2rem;color:var(--text-3);background:var(--slate-lt)}

/* Durasi box */
.st-durasi-box{
    display:flex;align-items:center;gap:.5rem;
    background:rgba(201,168,76,.08);border:1px solid rgba(201,168,76,.25);
    border-radius:var(--r-md);padding:.6rem .9rem;
    font-size:.76rem;color:var(--gold-dk);margin-bottom:.95rem;
}
.st-durasi-box svg{color:var(--gold);flex-shrink:0}

/* Submit button */
.st-submit-btn{
    width:100%;display:flex;align-items:center;justify-content:center;gap:.5rem;
    font-family:var(--font-body);font-size:.8rem;font-weight:700;
    color:var(--navy);background:linear-gradient(135deg,var(--gold),#E2B95A);
    border:none;border-radius:var(--r-md);padding:.7rem;
    cursor:pointer;transition:all .22s;
    box-shadow:0 3px 12px rgba(201,168,76,.3);
}
.st-submit-btn:hover{background:linear-gradient(135deg,var(--gold-dk),var(--gold));transform:translateY(-1px);box-shadow:0 5px 18px rgba(201,168,76,.4)}

.st-btn-outline{
    display:flex;align-items:center;justify-content:center;gap:.5rem;
    font-family:var(--font-body);font-size:.78rem;font-weight:600;
    color:var(--text-2);background:#fff;border:1.5px solid var(--slate-2);
    border-radius:var(--r-md);padding:.65rem;cursor:pointer;transition:all .2s;
}
.st-btn-outline:hover{border-color:var(--navy);color:var(--navy);background:var(--slate-lt)}
.w-100{width:100%} .mb{margin-bottom:.65rem}

/* Location strip */
.st-loc-strip{display:flex;align-items:center;gap:.85rem;background:var(--slate-lt);border:1px solid var(--slate-2);border-radius:var(--r-md);padding:.85rem 1rem;margin-bottom:1.1rem}
.st-loc-pin{width:36px;height:36px;border-radius:var(--r-sm);background:var(--gold-lt);color:var(--gold-dk);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.st-loc-coords{font-family:monospace;font-size:.76rem;font-weight:700;color:var(--navy);margin-bottom:.18rem}
.st-loc-radius{display:flex;align-items:center;gap:.3rem;font-size:.68rem;color:var(--text-3)}

/* Map */
.st-map{width:100%;height:380px;background:var(--slate-lt)}
.st-map-legend{display:flex;align-items:center;gap:.55rem;font-size:.7rem;color:var(--text-2);margin-left:auto;flex-wrap:wrap}
.st-legend-dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.st-legend-dot--marker{background:#C9A84C}
.st-legend-dot--radius{background:rgba(37,99,235,.4);border:2px solid #2563eb}
.st-map-tip{display:flex;align-items:flex-start;gap:.55rem;padding:.75rem 1.1rem;background:var(--slate-lt);border-top:1px solid var(--slate-2);font-size:.73rem;color:var(--text-2);line-height:1.55}

/* Features */
.st-features{display:grid;grid-template-columns:repeat(4,1fr);gap:.9rem}
.st-feature{background:var(--white);border:1px solid var(--slate-2);border-radius:var(--r-lg);padding:1.15rem;box-shadow:var(--sh-sm);transition:all .2s;display:flex;flex-direction:column;gap:.5rem}
.st-feature:hover{border-color:var(--gold);transform:translateY(-2px);box-shadow:0 6px 20px rgba(0,0,0,.08)}
.st-feat-icon{width:40px;height:40px;border-radius:var(--r-md);display:flex;align-items:center;justify-content:center}
.st-feat-icon--blue {background:var(--blue-lt);color:var(--blue)}
.st-feat-icon--amber{background:var(--amber-lt);color:var(--amber)}
.st-feat-icon--green{background:var(--green-lt);color:var(--green)}
.st-feat-icon--gold {background:var(--gold-lt); color:var(--gold-dk)}
.st-feat-title{font-size:.8rem;font-weight:700;color:var(--navy)}
.st-feat-sub{font-size:.7rem;color:var(--text-3);line-height:1.55}

/* Driver.js popover */
.st-popover.driver-popover{
    background:var(--white) !important;border-radius:14px !important;
    box-shadow:0 24px 64px rgba(14,30,61,.22),0 0 0 1px rgba(201,168,76,.2) !important;
    padding:0 !important;max-width:310px !important;overflow:hidden !important;
    font-family:var(--font-body) !important;
}
.st-popover .driver-popover-title{
    font-family:var(--font-body) !important;font-size:.82rem !important;font-weight:700 !important;
    color:var(--white) !important;
    background:linear-gradient(135deg,var(--navy),var(--navy-3)) !important;
    padding:.88rem 1.1rem !important;border-bottom:2px solid var(--gold) !important;margin:0 !important;
}
.st-popover .driver-popover-description{font-size:.75rem !important;color:var(--text-2) !important;line-height:1.65 !important;padding:.9rem 1.1rem .55rem !important}
.st-popover .driver-popover-progress-text{font-size:.6rem !important;font-weight:700 !important;color:var(--gold-dk) !important;padding:0 1.1rem !important}
.st-popover .driver-popover-navigation-btns{display:flex !important;gap:.4rem !important;padding:.65rem 1.1rem .88rem !important;border-top:1px solid var(--slate-2) !important;margin-top:.5rem !important}
.st-popover .driver-popover-next-btn,
.st-popover .driver-popover-done-btn{
    display:inline-flex !important;align-items:center !important;
    background:var(--gold) !important;border:none !important;color:var(--navy) !important;
    border-radius:var(--r-sm) !important;padding:.34rem .85rem !important;
    font-family:var(--font-body) !important;font-size:.7rem !important;font-weight:700 !important;
    cursor:pointer !important;box-shadow:none !important;text-shadow:none !important;
    min-width:unset !important;width:auto !important;height:auto !important;
}
.st-popover .driver-popover-next-btn:hover,
.st-popover .driver-popover-done-btn:hover{background:var(--gold-dk) !important}
.st-popover .driver-popover-prev-btn{
    display:inline-flex !important;align-items:center !important;
    background:var(--slate-lt) !important;border:1px solid var(--slate-2) !important;
    color:var(--slate) !important;border-radius:var(--r-sm) !important;
    padding:.34rem .85rem !important;font-family:var(--font-body) !important;
    font-size:.7rem !important;font-weight:600 !important;cursor:pointer !important;
    box-shadow:none !important;text-shadow:none !important;
    min-width:unset !important;width:auto !important;height:auto !important;
}
.st-popover .driver-popover-prev-btn:hover{background:var(--slate-2) !important}
.st-popover .driver-popover-close-btn{
    color:rgba(255,255,255,.65) !important;background:none !important;border:none !important;
    padding:0 !important;cursor:pointer !important;font-size:.9rem !important;
    position:absolute !important;top:.65rem !important;right:.85rem !important;
    width:auto !important;height:auto !important;
}

@media(max-width:1200px){.st-features{grid-template-columns:repeat(2,1fr)}}
@media(max-width:1024px){.st-grid{grid-template-columns:1fr}}
@media(max-width:768px){
    .st-page{padding:1rem 1rem 3rem}
    .st-hero-inner{padding:1.5rem 1.25rem 1rem}
    .st-hero-title{font-size:1.5rem}
    .st-hero-strip{padding:.7rem 1.25rem}
    .st-map{height:280px}
    .st-coord-row{grid-template-columns:1fr}
    .st-features{grid-template-columns:1fr 1fr}
}
@media(max-width:480px){
    .st-features{grid-template-columns:1fr}
    .st-hero-strip{flex-direction:column;align-items:flex-start;gap:.45rem}
    .st-strip-sep{display:none}
}

/* ═══════════════════════════════════════════════════════════
   ANALOG CLOCK PICKER
═══════════════════════════════════════════════════════════ */
.clk-pair-row{display:grid;grid-template-columns:1fr auto 1fr;align-items:start;gap:.75rem;margin-bottom:1.1rem}
.clk-vs{display:flex;align-items:center;justify-content:center;padding-top:2.2rem;color:var(--text-3)}
.clk-field{display:flex;flex-direction:column;gap:.45rem}
.clk-label{font-size:.73rem;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:.4rem}
.clk-dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.clk-dot--in{background:#d97706}
.clk-dot--out{background:#1e40af}

/* Clock face (small preview) */
.clk-wrap{
    background:var(--slate-lt);border:1.5px solid var(--slate-2);border-radius:var(--r-md);
    padding:.85rem;cursor:pointer;transition:border-color .2s,box-shadow .2s;
    display:flex;flex-direction:column;align-items:center;gap:.55rem;
    position:relative;
}
.clk-wrap:hover{border-color:var(--navy);box-shadow:0 0 0 3px rgba(14,30,61,.06)}
.clk-face{
    width:88px;height:88px;border-radius:50%;
    background:#fff;border:2px solid #E2E8F0;
    position:relative;overflow:hidden;
    box-shadow:0 2px 8px rgba(0,0,0,.08);
}
.clk-ticks{position:absolute;inset:0}
.clk-hand{position:absolute;bottom:50%;left:50%;transform-origin:bottom center;border-radius:3px}
.clk-hand--hour{width:3px;height:26px;background:var(--navy);margin-left:-1.5px;transform:rotate(0deg)}
.clk-hand--min{width:2px;height:34px;background:var(--gold);margin-left:-1px;transform:rotate(0deg)}
.clk-center{position:absolute;top:50%;left:50%;width:7px;height:7px;background:var(--navy);border-radius:50%;transform:translate(-50%,-50%);z-index:2}
.clk-digital{
    position:absolute;bottom:10px;left:50%;transform:translateX(-50%);
    font-size:.65rem;font-weight:800;color:var(--navy);letter-spacing:.05em;
    background:rgba(248,250,252,.85);padding:.1rem .35rem;border-radius:4px;
    white-space:nowrap;
}
.clk-edit-hint{font-size:.63rem;color:var(--text-3);display:flex;align-items:center;gap:.3rem}

/* ── Modal ── */
.clk-modal-bg{
    display:none;position:fixed;inset:0;background:rgba(14,30,61,.4);
    backdrop-filter:blur(3px);z-index:9998;
}
.clk-modal{
    display:none;position:fixed;z-index:9999;
    top:50%;left:50%;transform:translate(-50%,-48%) scale(.96);
    width:320px;background:#fff;border-radius:20px;
    box-shadow:0 24px 60px rgba(14,30,61,.22);
    padding:1.5rem;
    animation:none;
}
.clk-modal.clk-modal--open{
    display:block;
    animation:clkIn .22s cubic-bezier(.34,1.56,.64,1) forwards;
}
.clk-modal-bg.clk-modal-bg--open{display:block}
@keyframes clkIn{
    from{opacity:0;transform:translate(-50%,-48%) scale(.93)}
    to  {opacity:1;transform:translate(-50%,-50%) scale(1)}
}
.clk-modal-head{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:.9rem}
.clk-modal-title{font-size:.9rem;font-weight:800;color:var(--navy)}
.clk-modal-sub{font-size:.68rem;color:var(--text-3);margin-top:.1rem}
.clk-modal-close{border:none;background:var(--slate-lt);border-radius:50%;width:28px;height:28px;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text-3);flex-shrink:0}
.clk-modal-close:hover{background:var(--slate-2);color:var(--navy)}

/* Digital big display */
.clk-modal-dig{
    text-align:center;font-size:2.8rem;font-weight:800;letter-spacing:.04em;
    color:var(--navy);margin-bottom:1rem;line-height:1;
    font-variant-numeric:tabular-nums;
}
.clk-dig-sep{color:var(--gold);animation:blink 1s step-end infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.3}}
.clk-dig-h,.clk-dig-m{display:inline-block;min-width:2ch;text-align:center}

/* SVG Clock picker */
.clk-picker-wrap{display:flex;flex-direction:column;align-items:center;gap:.75rem}
.clk-picker-svg{width:200px;height:200px;cursor:crosshair;user-select:none;touch-action:none}
.clk-picker-mode-row{display:flex;gap:.4rem}
.clk-mode-btn{
    font-size:.62rem;font-weight:800;letter-spacing:.1em;
    padding:.35rem .85rem;border-radius:999px;border:1.5px solid var(--slate-2);
    background:transparent;color:var(--text-3);cursor:pointer;transition:all .15s;
}
.clk-mode-btn--active{background:var(--navy);color:#fff;border-color:var(--navy)}

/* Presets */
.clk-presets{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.85rem;justify-content:center}
.clk-preset-btn{
    font-size:.65rem;font-weight:700;padding:.28rem .7rem;
    border-radius:999px;border:1.5px solid var(--slate-2);
    background:var(--slate-lt);color:var(--navy);cursor:pointer;transition:all .15s;
}
.clk-preset-btn:hover{background:var(--navy);color:#fff;border-color:var(--navy)}

/* Footer */
.clk-modal-foot{display:flex;gap:.6rem;margin-top:1rem}
.clk-cancel-btn{
    flex:1;padding:.55rem;border-radius:var(--r-md);border:1.5px solid var(--slate-2);
    background:transparent;color:var(--text-2);font-size:.78rem;font-weight:600;cursor:pointer;
}
.clk-cancel-btn:hover{background:var(--slate-lt)}
.clk-ok-btn{
    flex:2;padding:.55rem;border-radius:var(--r-md);border:none;
    background:var(--navy);color:#fff;font-size:.78rem;font-weight:700;
    cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.4rem;
    transition:opacity .15s;
}
.clk-ok-btn:hover{opacity:.88}

@media(max-width:500px){
    .clk-pair-row{grid-template-columns:1fr;gap:1rem}
    .clk-vs{display:none}
}
</style>

<script>
const jamMasukEl  = document.getElementById('jamMasuk');
const jamPulangEl = document.getElementById('jamPulang');
const tolSlider   = document.getElementById('tolSlider');

// ══════════════════════════════════════════════════════════════
//  ANALOG CLOCK PICKER ENGINE
// ══════════════════════════════════════════════════════════════
let clkTarget  = 'masuk'; // 'masuk' | 'pulang'
let clkPickH   = 8;
let clkPickMin = 0;
let clkMode    = 'h';     // 'h' | 'm'

const PRESETS = {
    masuk:  ['06:00','07:00','07:30','08:00','08:30','09:00'],
    pulang: ['15:00','16:00','16:30','17:00','17:30','18:00'],
};

/* ── Build hour tick marks on mini clock faces ── */
function buildTicks(containerId) {
    const el = document.getElementById(containerId);
    if (!el) return;
    let html = '';
    for (let i = 0; i < 12; i++) {
        const ang = i * 30;
        const r   = 40; // radius within 88px face (center=44)
        const x   = 44 + r * Math.sin(ang * Math.PI / 180);
        const y   = 44 - r * Math.cos(ang * Math.PI / 180);
        const isMajor = (i % 3 === 0);
        html += `<circle cx="${x.toFixed(1)}" cy="${y.toFixed(1)}" r="${isMajor ? 2.5 : 1.2}" fill="${isMajor ? 'var(--navy)' : '#CBD5E1'}" style="position:absolute"/>`;
    }
    // Use SVG inside the div
    el.innerHTML = `<svg viewBox="0 0 88 88" width="88" height="88" style="position:absolute;inset:0">${html}</svg>`;
}

/* ── Rotate hands on mini clock ── */
function updateMiniClock(which, h24, m) {
    const suffix = which === 'masuk' ? 'Masuk' : 'Pulang';
    const h12 = h24 % 12;
    const hourDeg = h12 * 30 + m * 0.5;
    const minDeg  = m * 6;

    const hourEl = document.getElementById('clkHour' + suffix);
    const minEl  = document.getElementById('clkMin'  + suffix);
    const digEl  = document.getElementById('clkDig'  + suffix);

    if (hourEl) hourEl.style.transform = `rotate(${hourDeg}deg)`;
    if (minEl)  minEl.style.transform  = `rotate(${minDeg}deg)`;
    if (digEl)  digEl.textContent = pad(h24) + ':' + pad(m);
}

/* ── Build picker SVG markers ── */
function buildPickerMarkers() {
    const g = document.getElementById('clkPickerMarkers');
    if (!g) return;
    let html = '';
    for (let i = 0; i < 12; i++) {
        const ang = i * 30;
        const rad = 88;
        const x   = 110 + rad * Math.sin(ang * Math.PI / 180);
        const y   = 110 - rad * Math.cos(ang * Math.PI / 180);
        const num = i === 0 ? 12 : i;
        html += `<text x="${x.toFixed(1)}" y="${y.toFixed(1)}"
            text-anchor="middle" dominant-baseline="central"
            font-size="12" font-weight="700" fill="var(--navy)"
            font-family="var(--font-body)">${num}</text>`;
    }
    // Minute minor marks
    for (let i = 0; i < 60; i++) {
        if (i % 5 === 0) continue; // skip hour positions
        const ang = i * 6;
        const r1 = 100, r2 = 105;
        const x1 = 110 + r1 * Math.sin(ang * Math.PI / 180);
        const y1 = 110 - r1 * Math.cos(ang * Math.PI / 180);
        const x2 = 110 + r2 * Math.sin(ang * Math.PI / 180);
        const y2 = 110 - r2 * Math.cos(ang * Math.PI / 180);
        html += `<line x1="${x1.toFixed(1)}" y1="${y1.toFixed(1)}" x2="${x2.toFixed(1)}" y2="${y2.toFixed(1)}" stroke="#CBD5E1" stroke-width="1"/>`;
    }
    g.innerHTML = html;
}

/* ── Update picker SVG hands ── */
function updatePickerHands() {
    const h12    = clkPickH % 12;
    const hourDeg = h12 * 30 + clkPickMin * 0.5;
    const minDeg  = clkPickMin * 6;

    // Hour hand endpoints
    const hLen = 65;
    const hx = 110 + hLen * Math.sin(hourDeg * Math.PI / 180);
    const hy = 110 - hLen * Math.cos(hourDeg * Math.PI / 180);
    const hourLine = document.getElementById('clkPickHourLine');
    if (hourLine) { hourLine.setAttribute('x2', hx.toFixed(1)); hourLine.setAttribute('y2', hy.toFixed(1)); }

    // Minute hand endpoints
    const mLen = 80;
    const mx = 110 + mLen * Math.sin(minDeg * Math.PI / 180);
    const my = 110 - mLen * Math.cos(minDeg * Math.PI / 180);
    const minLine = document.getElementById('clkPickMinLine');
    if (minLine) { minLine.setAttribute('x2', mx.toFixed(1)); minLine.setAttribute('y2', my.toFixed(1)); }

    // Digital display
    document.getElementById('clkPickH').textContent = pad(clkPickH);
    document.getElementById('clkPickM').textContent = pad(clkPickMin);
}

function pad(n) { return String(n).padStart(2,'0'); }

function setPickMode(mode) {
    clkMode = mode;
    document.getElementById('btnModeH').classList.toggle('clk-mode-btn--active', mode === 'h');
    document.getElementById('btnModeM').classList.toggle('clk-mode-btn--active', mode === 'm');
}

/* ── SVG drag/click to set time ── */
function svgAngleToTime(evt) {
    const svg  = document.getElementById('clkPickerSvg');
    const rect = svg.getBoundingClientRect();
    const cx   = rect.left + rect.width  / 2;
    const cy   = rect.top  + rect.height / 2;
    const clientX = evt.touches ? evt.touches[0].clientX : evt.clientX;
    const clientY = evt.touches ? evt.touches[0].clientY : evt.clientY;
    const dx   = clientX - cx;
    const dy   = clientY - cy;
    let angle  = Math.atan2(dx, -dy) * 180 / Math.PI;
    if (angle < 0) angle += 360;

    if (clkMode === 'h') {
        clkPickH = Math.round(angle / 30) % 12;
        // Preserve AM/PM based on original hour
        if (clkPickH === 0) clkPickH = 0;
        // If originally >= 12, keep PM
        if (clkPickH < 12 && _clkOrigPM) clkPickH += 12;
        if (clkPickH === 12 && !_clkOrigPM) clkPickH = 0;
    } else {
        clkPickMin = Math.round(angle / 6) % 60;
    }
    updatePickerHands();
}

let _clkOrigPM = false;
let _clkDragging = false;

document.getElementById('clkPickerSvg').addEventListener('mousedown',  e => { _clkDragging=true; svgAngleToTime(e); });
document.getElementById('clkPickerSvg').addEventListener('touchstart', e => { _clkDragging=true; svgAngleToTime(e); },{passive:true});
document.addEventListener('mousemove',  e => { if (_clkDragging) svgAngleToTime(e); });
document.addEventListener('touchmove',  e => { if (_clkDragging) svgAngleToTime(e); },{passive:true});
document.addEventListener('mouseup',   () => _clkDragging = false);
document.addEventListener('touchend',  () => _clkDragging = false);

/* ── Open/close modal ── */
function openClock(which) {
    clkTarget = which;
    const hidden = which === 'masuk' ? jamMasukEl : jamPulangEl;
    const val    = hidden.value || (which === 'masuk' ? '08:00' : '17:00');
    const [h, m] = val.split(':').map(Number);
    clkPickH   = h;
    clkPickMin = m;
    _clkOrigPM = h >= 12;

    document.getElementById('clkModalTitle').textContent = which === 'masuk' ? 'Atur Jam Masuk' : 'Atur Jam Pulang';

    // Build presets
    const presetsEl = document.getElementById('clkPresets');
    presetsEl.innerHTML = PRESETS[which].map(p =>
        `<button type="button" class="clk-preset-btn" onclick="applyPreset('${p}')">${p}</button>`
    ).join('');

    buildPickerMarkers();
    updatePickerHands();
    setPickMode('h');

    document.getElementById('clkModalBg').classList.add('clk-modal-bg--open');
    document.getElementById('clkModal').classList.add('clk-modal--open');
    document.body.style.overflow = 'hidden';
}

function closeClock() {
    document.getElementById('clkModalBg').classList.remove('clk-modal-bg--open');
    document.getElementById('clkModal').classList.remove('clk-modal--open');
    document.body.style.overflow = '';
}

function applyPreset(val) {
    const [h, m] = val.split(':').map(Number);
    clkPickH   = h;
    clkPickMin = m;
    _clkOrigPM = h >= 12;
    updatePickerHands();
}

function confirmClock() {
    const val = pad(clkPickH) + ':' + pad(clkPickMin);
    if (clkTarget === 'masuk') {
        jamMasukEl.value = val;
        updateMiniClock('masuk', clkPickH, clkPickMin);
    } else {
        jamPulangEl.value = val;
        updateMiniClock('pulang', clkPickH, clkPickMin);
    }
    closeClock();
    updateToleransi();
    hitungDurasi();
}

/* ── Init mini clocks from PHP values ── */
(function initMiniClocks() {
    buildTicks('clkTicksMasuk');
    buildTicks('clkTicksPulang');

    const mVal = jamMasukEl.value  || '08:00';
    const pVal = jamPulangEl.value || '17:00';
    const [mh, mm] = mVal.split(':').map(Number);
    const [ph, pm] = pVal.split(':').map(Number);
    updateMiniClock('masuk',  mh, mm);
    updateMiniClock('pulang', ph, pm);
})();

// ── Hitung jam batas terlambat ───────────────────────────────
function updateToleransi() {
    const masukVal = jamMasukEl.value;
    const tol      = parseInt(tolSlider.value);

    if (!masukVal) return;

    // Parse
    const [h, m] = masukVal.split(':').map(Number);
    const totalMnt = h * 60 + m + tol;
    const bH = Math.floor(totalMnt / 60) % 24;
    const bM = totalMnt % 60;
    const batas = String(bH).padStart(2,'0') + ':' + String(bM).padStart(2,'0');

    // Update badge slider
    document.getElementById('tolBadge').textContent = tol + ' menit';

    // Update display card
    document.getElementById('tolDisplayTime').textContent = batas;

    // Update timeline
    document.getElementById('tlRangeTepat').textContent = masukVal.substring(0,5);
    document.getElementById('tlBatas').textContent      = batas;
    document.getElementById('tlTerlambat').textContent  = batas;

    // Update hero strip
    document.getElementById('tolDisplayTime').textContent = batas;

    // Update range fill
    tolSlider.style.background =
        `linear-gradient(to right, var(--amber) 0%, var(--amber) ${tol/180*100}%, var(--slate-2) ${tol/180*100}%, var(--slate-2) 100%)`;
}

// ── Hitung durasi kerja ──────────────────────────────────────
function hitungDurasi() {
    if (!jamMasukEl.value || !jamPulangEl.value) return;
    const [hs, ms] = jamMasukEl.value.split(':').map(Number);
    const [he, me] = jamPulangEl.value.split(':').map(Number);
    let dur = (he * 60 + me) - (hs * 60 + ms);
    if (dur < 0) dur += 1440;
    const h = Math.floor(dur / 60), min = dur % 60;
    document.getElementById('durasiText').textContent = h + ' jam' + (min > 0 ? ' ' + min + ' menit' : '');
}

tolSlider.addEventListener('input', updateToleransi);
hitungDurasi();
updateToleransi();

// ── Radius slider ────────────────────────────────────────────
const radSlider = document.getElementById('radiusSlider');
radSlider.addEventListener('input', function() {
    document.getElementById('radiusBadge').textContent = this.value + ' m';
    const pct = (this.value - 10) / 1990 * 100;
    this.style.background = `linear-gradient(to right, var(--navy) 0%, var(--navy) ${pct}%, var(--slate-2) ${pct}%, var(--slate-2) 100%)`;
    if (window._circle) window._circle.setRadius(parseInt(this.value));
});

// ── Leaflet Map ──────────────────────────────────────────────
const initLat = <?= $lat_cur ?>;
const initLng = <?= $lng_cur ?>;
const initRad = <?= $rad_cur ?>;

const map = L.map('leafletMap').setView([initLat, initLng], 16);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
    maxZoom: 19
}).addTo(map);

const goldIcon = L.divIcon({
    className: '',
    html: `<div style="width:26px;height:26px;background:linear-gradient(135deg,#C9A84C,#E2B95A);border:3px solid #fff;border-radius:50% 50% 50% 0;transform:rotate(-45deg);box-shadow:0 2px 10px rgba(0,0,0,.3);"></div>`,
    iconSize:[26,26], iconAnchor:[13,26], popupAnchor:[0,-28]
});

const marker = L.marker([initLat, initLng], {icon: goldIcon, draggable: true}).addTo(map);
marker.bindPopup('<strong style="font-family:system-ui;font-size:.8rem">Lokasi Kantor</strong><br><small style="color:#64748b">Seret untuk pindahkan</small>').openPopup();

window._circle = L.circle([initLat, initLng], {
    radius: initRad, color: '#1d4ed8', fillColor: '#1d4ed8',
    fillOpacity: 0.1, weight: 2, dashArray: '6 4'
}).addTo(map);

function updateCoords(lat, lng) {
    document.getElementById('latitude').value  = lat.toFixed(8);
    document.getElementById('longitude').value = lng.toFixed(8);
    document.getElementById('locCoordsDisplay').textContent = lat.toFixed(6) + ', ' + lng.toFixed(6);
    window._circle.setLatLng([lat, lng]);
}

marker.on('dragend', e => { const p = e.target.getLatLng(); updateCoords(p.lat, p.lng); });
map.on('click', e => { marker.setLatLng(e.latlng); updateCoords(e.latlng.lat, e.latlng.lng); map.panTo(e.latlng); });

// ── Gunakan lokasi saat ini ──────────────────────────────────
document.getElementById('btnGetLoc').addEventListener('click', function () {
    const btn = this;
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="animation:spin .6s linear infinite"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg> Mengambil lokasi…';

    if (!('geolocation' in navigator)) {
        alert('Browser tidak mendukung geolocation.');
        btn.disabled = false; btn.innerHTML = orig; return;
    }
    navigator.geolocation.getCurrentPosition(pos => {
        const lat = pos.coords.latitude, lng = pos.coords.longitude;
        updateCoords(lat, lng);
        marker.setLatLng([lat, lng]);
        map.flyTo([lat, lng], 17, {duration: 1.2});
        btn.disabled = false;
        btn.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg> Lokasi berhasil diambil';
        setTimeout(() => { btn.innerHTML = orig; }, 3000);
    }, () => {
        alert('Gagal mengambil lokasi. Pastikan GPS aktif dan izinkan akses lokasi.');
        btn.disabled = false; btn.innerHTML = orig;
    }, {enableHighAccuracy: true, timeout: 10000, maximumAge: 0});
});

// ── Auto dismiss alert ───────────────────────────────────────
const al = document.getElementById('pageAlert');
if (al) setTimeout(() => { al.style.opacity='0'; al.style.transition='opacity .4s'; setTimeout(()=>al.remove(),400); }, 4000);

// ── Driver.js Tour ───────────────────────────────────────────
const TOUR_KEY = 'settings_tour_v2';
function loadDriver(cb) {
    if (window.driver && window.driver.js) { cb(); return; }
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = 'https://cdnjs.cloudflare.com/ajax/libs/driver.js/1.3.1/driver.css';
    document.head.appendChild(link);
    const sc = document.createElement('script');
    sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/driver.js/1.3.1/driver.js.iife.js';
    sc.onload = () => setTimeout(cb, 100);
    document.head.appendChild(sc);
}

function startTour() {
    loadDriver(() => {
        const drv = window.driver.js.driver({
            popoverClass: 'st-popover',
            showProgress: true,
            progressText: '{{current}} / {{total}}',
            nextBtnText: 'Lanjut →',
            prevBtnText: '← Kembali',
            doneBtnText: 'Selesai',
            allowClose: true,
            overlayColor: 'rgba(14,30,61,.72)',
            smoothScroll: true, animate: true,
            onDestroyStarted: () => { localStorage.setItem(TOUR_KEY,'1'); drv.destroy(); },
            steps: [
                {
                    popover: {
                        title: 'Pengaturan Sistem Absensi',
                        description: 'Halaman ini mengontrol semua parameter validasi absensi — jam kerja, <strong>toleransi keterlambatan</strong>, dan zona GPS lokasi kantor.',
                        side:'over', align:'center'
                    }
                },
                {
                    element: '#tour-header',
                    popover: {
                        title: 'Status Konfigurasi Aktif',
                        description: 'Strip di bawah hero menampilkan konfigurasi yang sedang aktif: jam masuk, pulang, toleransi (menit + jam batas), dan radius lokasi.',
                        side:'bottom', align:'start'
                    }
                },
                {
                    element: '#tour-jam',
                    popover: {
                        title: 'Jam Kerja',
                        description: 'Atur jam masuk dan pulang kantor. Perubahan langsung mempengaruhi validasi waktu absensi seluruh pegawai.',
                        side:'right', align:'start'
                    }
                },
                {
                    element: '#tour-toleransi',
                    popover: {
                        title: 'Toleransi Keterlambatan — Fitur Baru',
                        description: 'Slider ini menentukan berapa menit setelah jam masuk, absen masih dianggap <strong>Tepat Waktu</strong>.\n\nContoh: jam masuk 08:00 + toleransi 30 mnt → batas 08:30. Absen pukul 08:25 = Tepat Waktu. 08:31 = Terlambat.\n\nSet <strong>0 menit</strong> untuk tanpa toleransi.',
                        side:'top', align:'center'
                    }
                },
                {
                    element: '#tour-lokasi',
                    popover: {
                        title: 'Lokasi & Radius Absensi',
                        description: 'Atur koordinat GPS kantor dan radius zona absensi. Pegawai WFO hanya bisa absen jika berada dalam radius ini.',
                        side:'left', align:'start'
                    }
                },
                {
                    element: '#tour-radius',
                    popover: {
                        title: 'Slider Radius',
                        description: 'Geser untuk mengatur jangkauan zona. Lingkaran biru di peta langsung menyesuaikan.\n\n<strong>10–50m</strong>: sangat ketat\n<strong>100–300m</strong>: standar kantor\n<strong>500m+</strong>: longgar/gedung besar',
                        side:'top', align:'center'
                    }
                },
                {
                    element: '#tour-map',
                    popover: {
                        title: 'Peta Interaktif',
                        description: 'Tiga cara mengatur koordinat:<br>① <strong>Klik</strong> titik di peta<br>② <strong>Seret</strong> pin emas<br>③ Tombol <strong>GPS</strong> — gunakan posisi Anda saat ini',
                        side:'top', align:'center'
                    }
                },
                {
                    element: '#tour-features',
                    popover: {
                        title: 'Fitur Aktif',
                        description: 'Empat fitur validasi yang berjalan berdasarkan pengaturan ini: validasi otomatis, toleransi fleksibel, status real-time, dan zona GPS.',
                        side:'top', align:'center'
                    }
                },
                {
                    popover: {
                        title: 'Siap Dikonfigurasi',
                        description: 'Perubahan yang disimpan langsung berlaku untuk semua validasi absensi. Klik <strong>Panduan</strong> kapan saja untuk mengulang tur ini.',
                        side:'over', align:'center'
                    }
                }
            ]
        });
        drv.drive();
    });
}
if (!localStorage.getItem(TOUR_KEY)) {
    window.addEventListener('load', () => setTimeout(startTour, 800));
}
</script>
<style>@keyframes spin{to{transform:rotate(360deg)}}</style>


<!-- ── MAINTENANCE MODE ───────────────────────────────────── -->
<div class="st-card mm-card <?= $maint_mode ? 'mm-card--on' : '' ?>" id="tour-maintenance">
    <div class="st-card-head">
        <div class="st-card-icon <?= $maint_mode ? 'mm-icon--on' : 'mm-icon--off' ?>" id="mmCardIcon">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
            </svg>
        </div>
        <div>
            <div class="st-card-title">Maintenance Mode</div>
            <div class="st-card-sub">Blokir akses pegawai saat perbaikan sistem</div>
        </div>
        <!-- Status pill -->
        <div class="mm-status-pill <?= $maint_mode ? 'mm-pill--on' : 'mm-pill--off' ?>" id="mmStatusPill">
            <span class="mm-pill-dot" id="mmPillDot"></span>
            <span id="mmPillText"><?= $maint_mode ? 'AKTIF' : 'NONAKTIF' ?></span>
        </div>
    </div>

    <?php if(isset($_GET['success']) && $_GET['success']==='maintenance'): ?>
    <div class="st-alert st-alert--success mm-alert-anim" style="margin-bottom:1rem">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        Maintenance mode berhasil <?= $maint_mode ? 'diaktifkan' : 'dinonaktifkan' ?>.
        <button class="st-alert-close" onclick="this.parentElement.remove()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <?php endif; ?>

    <!-- Toggle row -->
    <div class="mm-toggle-row">
        <div class="mm-toggle-info">
            <div class="mm-toggle-label" id="mmToggleLabel">
                <?= $maint_mode ? 'Maintenance Aktif' : 'Sistem Berjalan Normal' ?>
            </div>
            <div class="mm-toggle-sub" id="mmToggleSub">
                <?= $maint_mode
                    ? 'Pegawai diarahkan ke halaman maintenance saat mengakses sistem'
                    : 'Pegawai dapat login dan melakukan absensi seperti biasa' ?>
            </div>
        </div>
        <!-- Toggle switch -->
        <form method="POST" id="mmForm" style="flex-shrink:0">
            <?php csrf_field(); ?>
            <input type="hidden" name="maintenance_mode" id="mmInput" value="<?= $maint_mode ? '0' : '1' ?>">
            <button type="button" class="mm-toggle <?= $maint_mode ? 'mm-toggle--on' : '' ?>"
                    id="mmToggleBtn" aria-label="Toggle maintenance mode" title="Klik untuk toggle maintenance">
                <span class="mm-toggle-thumb"></span>
            </button>
            <button type="submit" name="toggle_maintenance" id="mmSubmit" style="display:none"></button>
        </form>
    </div>

    <!-- Info cards -->
    <div class="mm-info-row">
        <div class="mm-info-box">
            <div class="mm-info-icon mm-info-icon--status <?= $maint_mode ? 'mm-info-icon--red' : 'mm-info-icon--green' ?>" id="mmInfoStatus">
                <?php if($maint_mode): ?>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                </svg>
                <?php else: ?>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <polyline points="20 6 9 17 4 12"/>
                </svg>
                <?php endif; ?>
            </div>
            <div>
                <div class="mm-info-label">Sistem</div>
                <div class="mm-info-val <?= $maint_mode ? 'mm-val--red' : 'mm-val--green' ?>" id="mmValSistem">
                    <?= $maint_mode ? 'Maintenance' : 'Online' ?>
                </div>
            </div>
        </div>
        <div class="mm-info-box">
            <div class="mm-info-icon" style="background:rgba(201,168,76,.1);color:var(--gold-dk)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                    <circle cx="9" cy="7" r="4"/>
                    <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                    <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                </svg>
            </div>
            <div>
                <div class="mm-info-label">Admin</div>
                <div class="mm-info-val" style="color:var(--gold-dk)">Tetap Akses</div>
            </div>
        </div>
        <div class="mm-info-box">
            <div class="mm-info-icon <?= $maint_mode ? 'mm-info-icon--red' : 'mm-info-icon--green' ?>" id="mmInfoPegawai">
                <?php if($maint_mode): ?>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/>
                </svg>
                <?php else: ?>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
                    <polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <?php endif; ?>
            </div>
            <div>
                <div class="mm-info-label">Pegawai</div>
                <div class="mm-info-val <?= $maint_mode ? 'mm-val--red' : 'mm-val--green' ?>" id="mmValPegawai">
                    <?= $maint_mode ? 'Diblokir' : 'Bisa Akses' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Confirm banner (hidden by default) -->
    <div class="mm-confirm" id="mmConfirm" style="display:none">
        <div class="mm-confirm-inner" id="mmConfirmInner">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <span id="mmConfirmText">Aktifkan maintenance mode? Pegawai tidak bisa absen.</span>
            <div class="mm-confirm-btns">
                <button type="button" class="mm-btn-cancel" onclick="mmCancelToggle()">Batal</button>
                <button type="button" class="mm-btn-confirm" id="mmBtnConfirm" onclick="mmDoSubmit()">Ya, Lanjutkan</button>
            </div>
        </div>
    </div>
</div>

<style>
/* ── MAINTENANCE MODE CARD ── */
.mm-card {
    border: 1.5px solid var(--slate-2);
    transition: border-color .35s, box-shadow .35s;
    position: relative; overflow: hidden;
}
.mm-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, transparent, transparent);
    transition: background .35s;
}
.mm-card--on { border-color: rgba(239,68,68,.3); box-shadow: 0 4px 24px rgba(239,68,68,.06); }
.mm-card--on::before { background: linear-gradient(90deg, transparent, rgba(239,68,68,.6), transparent); }

/* Icon */
.mm-icon--off { background: rgba(100,116,139,.1); color: var(--slate); }
.mm-icon--on  { background: rgba(239,68,68,.1); color: #EF4444; }

/* Status pill */
.mm-status-pill {
    display: inline-flex; align-items: center; gap: 5px;
    border-radius: 100px; padding: .28rem .8rem;
    font-size: .6rem; font-weight: 700; letter-spacing: .1em;
    border: 1px solid; margin-left: auto;
    transition: all .3s;
}
.mm-pill--off { background: rgba(34,197,94,.08); color: #16a34a; border-color: rgba(34,197,94,.25); }
.mm-pill--on  { background: rgba(239,68,68,.1);  color: #DC2626; border-color: rgba(239,68,68,.3); }
.mm-pill-dot  { width: 5px; height: 5px; border-radius: 50%; background: currentColor; }
.mm-pill--on .mm-pill-dot { animation: mm-blink 1.2s ease-in-out infinite; }
@keyframes mm-blink { 0%,100%{opacity:1} 50%{opacity:.2} }

/* Toggle row */
.mm-toggle-row {
    display: flex; align-items: center; gap: 1rem;
    background: var(--slate-lt); border: 1px solid var(--slate-2);
    border-radius: var(--r-md); padding: .95rem 1.1rem;
    margin-bottom: 1rem;
}
.mm-toggle-info { flex: 1; min-width: 0; }
.mm-toggle-label { font-size: .82rem; font-weight: 700; color: var(--navy); margin-bottom: .15rem; }
.mm-toggle-sub   { font-size: .7rem; color: var(--text-3); line-height: 1.5; }

/* The actual toggle switch */
.mm-toggle {
    width: 52px; height: 28px; border-radius: 100px;
    border: none; cursor: pointer; position: relative;
    background: var(--slate-2); transition: background .25s, box-shadow .25s;
    flex-shrink: 0; outline: none;
}
.mm-toggle:hover { box-shadow: 0 0 0 3px rgba(100,116,139,.15); }
.mm-toggle--on { background: #EF4444; }
.mm-toggle--on:hover { box-shadow: 0 0 0 3px rgba(239,68,68,.2); }
.mm-toggle-thumb {
    position: absolute; top: 3px; left: 3px;
    width: 22px; height: 22px; border-radius: 50%;
    background: #fff;
    box-shadow: 0 1px 6px rgba(0,0,0,.2);
    transition: transform .25s cubic-bezier(.34,1.56,.64,1);
}
.mm-toggle--on .mm-toggle-thumb { transform: translateX(24px); }

/* Info row */
.mm-info-row { display: grid; grid-template-columns: repeat(3,1fr); gap: .7rem; margin-bottom: 0; }
.mm-info-box {
    display: flex; align-items: center; gap: .65rem;
    background: #fff; border: 1px solid var(--slate-2);
    border-radius: var(--r-md); padding: .75rem .9rem;
}
.mm-info-icon {
    width: 34px; height: 34px; border-radius: var(--r-sm);
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.mm-info-icon--green { background: rgba(34,197,94,.1); color: #16a34a; }
.mm-info-icon--red   { background: rgba(239,68,68,.1); color: #DC2626; }
.mm-info-label { font-size: .6rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-3); margin-bottom: .1rem; }
.mm-info-val   { font-size: .78rem; font-weight: 700; color: var(--navy); }
.mm-val--green { color: #16a34a; }
.mm-val--red   { color: #DC2626; }

/* Confirm banner */
.mm-confirm {
    margin-top: 1rem; border-radius: var(--r-md); overflow: hidden;
    animation: mm-slideIn .2s ease;
}
@keyframes mm-slideIn { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:none} }
.mm-confirm-inner {
    display: flex; align-items: center; flex-wrap: wrap; gap: .65rem;
    padding: .85rem 1rem;
    border: 1px solid; border-radius: var(--r-md);
    font-size: .76rem; font-weight: 500;
}
.mm-confirm-inner--activate {
    background: rgba(239,68,68,.06); border-color: rgba(239,68,68,.25); color: #991b1b;
}
.mm-confirm-inner--deactivate {
    background: rgba(34,197,94,.06); border-color: rgba(34,197,94,.25); color: #166534;
}
.mm-confirm-inner svg { flex-shrink: 0; }
.mm-confirm-inner span { flex: 1; min-width: 160px; }
.mm-confirm-btns { display: flex; gap: .45rem; }
.mm-btn-cancel {
    padding: .35rem .85rem; border-radius: var(--r-sm);
    border: 1.5px solid var(--slate-2); background: #fff;
    font-size: .72rem; font-weight: 600; color: var(--text-2); cursor: pointer;
}
.mm-btn-cancel:hover { background: var(--slate-lt); }
.mm-btn-confirm {
    padding: .35rem .85rem; border-radius: var(--r-sm);
    border: none; font-size: .72rem; font-weight: 700; cursor: pointer;
    color: #fff; background: #EF4444;
}
.mm-btn-confirm--green { background: #16a34a; }
.mm-btn-confirm:hover { opacity: .88; }

.mm-alert-anim { animation: slideDown .3s ease; }
@media(max-width:600px){
    .mm-info-row{grid-template-columns:1fr 1fr}
    .mm-info-box:last-child{grid-column:span 2}
}
@media(max-width:420px){
    .mm-info-row{grid-template-columns:1fr}
    .mm-info-box:last-child{grid-column:span 1}
}
</style>

<script>
(function() {
    const btn        = document.getElementById('mmToggleBtn');
    const form       = document.getElementById('mmForm');
    const input      = document.getElementById('mmInput');
    const confirm    = document.getElementById('mmConfirm');
    const confirmInner = document.getElementById('mmConfirmInner');
    const confirmText  = document.getElementById('mmConfirmText');
    const btnConfirm   = document.getElementById('mmBtnConfirm');

    let pendingOn = null; // what value we want to set

    btn.addEventListener('click', function() {
        const currentlyOn = btn.classList.contains('mm-toggle--on');
        pendingOn = !currentlyOn;

        // Show confirm banner
        confirm.style.display = 'block';
        confirmInner.className = 'mm-confirm-inner ' +
            (pendingOn ? 'mm-confirm-inner--activate' : 'mm-confirm-inner--deactivate');
        confirmText.textContent = pendingOn
            ? 'Aktifkan maintenance mode? Pegawai tidak bisa absen.'
            : 'Matikan maintenance mode? Pegawai bisa kembali akses sistem.';
        btnConfirm.className = 'mm-btn-confirm' + (pendingOn ? '' : ' mm-btn-confirm--green');
    });

    window.mmCancelToggle = function() {
        pendingOn = null;
        confirm.style.display = 'none';
    };

    window.mmDoSubmit = function() {
        input.value = pendingOn ? '1' : '0';
        document.getElementById('mmSubmit').click();
    };
})();
</script>

<?php include '../templates/footer.php'; ?>