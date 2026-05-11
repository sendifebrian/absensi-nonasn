<?php
// admin/qr_generator.php — Generate QR Code Absensi (khusus Admin)
// Menggantikan generate_qr.php dan buat_qr.php yang dihapus dari root

require_once __DIR__ . '/../config/init.php';
$is_qr_page = false;
restore_web_session($pdo);
require_login();
if (!is_admin()) {
    header('Location: dashboard.php');
    exit();
}

// ── Auto-migrate: tambah kolom base_url di settings jika belum ada ──
try {
    $pdo->query("SELECT base_url FROM settings LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("ALTER TABLE settings ADD COLUMN `base_url` varchar(255) NOT NULL DEFAULT '' COMMENT 'URL publik/ngrok untuk QR Code absensi'");
}

// ── Ambil settings ──────────────────────────────────────────────────
$setting = $pdo->query("SELECT * FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$base_url = rtrim($setting['base_url'] ?? '', '/');

// ── Deteksi base_url otomatis jika belum diisi ──────────────────────
if (!$base_url) {
    $scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    // Deteksi ngrok dari header x-forwarded-proto
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $scheme = strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']);
    }
    $base_url = $scheme . '://' . $_SERVER['HTTP_HOST'];
    // Ambil subfolder dari SCRIPT_NAME (misal /absensi-nonasn)
    $dir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');
    if ($dir && $dir !== '/') $base_url .= $dir;
}

// ── Handle: simpan base_url baru ────────────────────────────────────
$success_msg = '';
$error_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_url'])) {
    csrf_verify();
    $input_url = trim($_POST['base_url'] ?? '');
    $input_url = rtrim($input_url, '/');

    if (!$input_url) {
        $error_msg = 'URL tidak boleh kosong.';
    } elseif (!filter_var($input_url, FILTER_VALIDATE_URL)) {
        $error_msg = 'Format URL tidak valid. Contoh: https://nama.ngrok-free.app/absensi-nonasn';
    } elseif (!preg_match('/^https?:\/\//i', $input_url)) {
        $error_msg = 'URL harus diawali dengan https:// atau http://';
    } else {
        $pdo->prepare("UPDATE settings SET base_url = ? WHERE id = 1")->execute([$input_url]);
        $base_url    = $input_url;
        $success_msg = 'URL berhasil disimpan.';
    }
}

// ── Handle: generate & simpan QR ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_qr'])) {
    csrf_verify();
    $input_url = trim($_POST['base_url'] ?? '');
    $input_url = rtrim($input_url, '/');
    if ($input_url && filter_var($input_url, FILTER_VALIDATE_URL)) {
        $pdo->prepare("UPDATE settings SET base_url = ? WHERE id = 1")->execute([$input_url]);
        $base_url = $input_url;
    }
}

// ── Susun URL scan ──────────────────────────────────────────────────
// Tambahkan ngrok-skip-browser-warning jika pakai ngrok
$scan_url = $base_url . '/scan.php';
if (str_contains($base_url, 'ngrok')) {
    $scan_url .= '?ngrok-skip-browser-warning=1';
}

// ── Build QR API URL ────────────────────────────────────────────────
$qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=600x600&data="
            . urlencode($scan_url) . "&ecc=H&margin=2";

// ── Simpan file QR jika diminta ─────────────────────────────────────
$qr_saved    = false;
$qr_save_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_qr']) && $base_url) {
    $qr_dir = __DIR__ . '/../assets/qr/';
    if (!is_dir($qr_dir)) mkdir($qr_dir, 0755, true);
    $qr_img = @file_get_contents($qr_api_url);
    if ($qr_img) {
        file_put_contents($qr_dir . 'qr_absen.png', $qr_img);
        $qr_saved    = true;
        $success_msg = 'QR Code berhasil digenerate dan disimpan.';
    } else {
        $error_msg = 'Gagal mengambil QR dari server. Periksa koneksi internet server.';
    }
}

// ── Cek apakah file QR sudah ada ────────────────────────────────────
$qr_file_exists = file_exists(__DIR__ . '/../assets/qr/qr_absen.png');
$qr_file_url    = '../assets/qr/qr_absen.png';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>QR Generator — Admin BBWS Citanduy</title>
<?php include '../templates/header.php'; ?>
<style>
.qrg-wrap{max-width:700px;margin:0 auto;padding:24px 0 40px}
.qrg-card{background:#fff;border:1px solid #e5e9f0;border-radius:14px;padding:28px 28px 24px;margin-bottom:20px;box-shadow:0 1px 4px rgba(10,22,40,.05)}
.qrg-eyebrow{font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#C9A84C;margin-bottom:6px}
.qrg-title{font-size:18px;font-weight:600;color:#0A1628;margin:0 0 4px}
.qrg-sub{font-size:13px;color:#64748b;margin:0 0 20px}
.qrg-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#334155;margin-bottom:6px;display:block}
.qrg-input{width:100%;padding:10px 14px;border:1.5px solid #cbd5e1;border-radius:8px;font-size:13px;color:#0f172a;outline:none;transition:border-color .2s;box-sizing:border-box;font-family:monospace}
.qrg-input:focus{border-color:#C9A84C;box-shadow:0 0 0 3px rgba(201,168,76,.12)}
.qrg-hint{font-size:11px;color:#94a3b8;margin-top:5px}
.qrg-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;border:none;transition:all .18s}
.qrg-btn-primary{background:linear-gradient(135deg,#0A1628,#1A3560);color:#C9A84C}
.qrg-btn-primary:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(10,22,40,.25)}
.qrg-btn-gold{background:linear-gradient(135deg,#b8922a,#C9A84C);color:#fff}
.qrg-btn-gold:hover{transform:translateY(-1px);box-shadow:0 4px 14px rgba(201,168,76,.4)}
.qrg-btn-outline{background:#fff;border:1.5px solid #e2e8f0;color:#334155}
.qrg-btn-outline:hover{border-color:#C9A84C;color:#b8922a}
.qrg-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.qrg-preview{display:flex;flex-direction:column;align-items:center;gap:16px;padding:24px;background:#f8fafc;border-radius:12px;border:1px dashed #cbd5e1}
.qrg-qr-img{width:220px;height:220px;border-radius:10px;border:2px solid #e5e9f0;background:#fff;padding:8px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.qrg-url-pill{font-size:11px;font-family:monospace;background:#0A1628;color:#C9A84C;padding:4px 12px;border-radius:20px;word-break:break-all;text-align:center}
.qrg-alert{padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.qrg-alert-ok{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.qrg-alert-err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.qrg-info-box{background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:14px 16px;font-size:12.5px;color:#0369a1;line-height:1.7}
.qrg-info-box strong{color:#0c4a6e}
.qrg-divider{border:none;border-top:1px solid #f1f5f9;margin:20px 0}
.qrg-step{display:flex;gap:12px;margin-bottom:12px}
.qrg-step-num{width:22px;height:22px;border-radius:50%;background:#0A1628;color:#C9A84C;font-size:11px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px}
.qrg-step-text{font-size:13px;color:#334155;line-height:1.6}
.qrg-step-text code{background:#f1f5f9;padding:1px 6px;border-radius:4px;font-size:12px;color:#0f172a}
@media print{.qrg-card:not(.print-target){display:none!important}.print-target{border:none!important;box-shadow:none!important}.no-print{display:none!important}}
</style>
</head>
<body>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<div class="main-content">
<div class="qrg-wrap">

    <!-- Header -->
    <div style="margin-bottom:20px">
        <div class="qrg-eyebrow">Sistem</div>
        <h1 class="qrg-title">Generator QR Code Absensi</h1>
        <p class="qrg-sub">Buat QR Code untuk ditempel di pintu kantor. Pegawai scan QR ini dengan HP untuk absen.</p>
    </div>

    <?php if ($success_msg): ?>
    <div class="qrg-alert qrg-alert-ok">✅ <?= htmlspecialchars($success_msg) ?></div>
    <?php endif; ?>
    <?php if ($error_msg): ?>
    <div class="qrg-alert qrg-alert-err">❌ <?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <!-- Kartu: URL & Generate -->
    <div class="qrg-card">
        <div class="qrg-eyebrow">Langkah 1</div>
        <div class="qrg-title" style="font-size:15px;margin-bottom:4px">Set URL Publik Aplikasi</div>
        <div class="qrg-sub" style="margin-bottom:16px">URL ini adalah alamat yang bisa diakses HP pegawai. Jika pakai ngrok/server VPS, isi sesuai URL tersebut.</div>

        <form method="POST">
            <?php csrf_field(); ?>
            <label class="qrg-label" for="base_url">URL Aplikasi (tanpa garis miring di akhir)</label>
            <input type="url" name="base_url" id="base_url" class="qrg-input"
                   value="<?= htmlspecialchars($base_url) ?>"
                   placeholder="https://nama.ngrok-free.app/absensi-nonasn"
                   required>
            <div class="qrg-hint">
                Contoh ngrok: <code>https://abc123.ngrok-free.app/absensi-nonasn</code><br>
                Contoh VPS: <code>https://absensi.bbwscitanduy.go.id</code><br>
                Contoh lokal: <code>http://localhost/absensi-nonasn</code>
            </div>
            <div class="qrg-row">
                <button type="submit" name="simpan_url" class="qrg-btn qrg-btn-primary">
                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M4 13v3h3l8-8-3-3-8 8z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                    Simpan URL
                </button>
                <button type="submit" name="generate_qr" class="qrg-btn qrg-btn-gold">
                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><rect x="2" y="2" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="11" y="2" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="2" y="11" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="13" y="13" width="3" height="3" fill="currentColor"/></svg>
                    Simpan & Generate QR
                </button>
            </div>
        </form>
    </div>

    <!-- Kartu: Preview QR -->
    <div class="qrg-card print-target">
        <div class="qrg-eyebrow">Langkah 2</div>
        <div class="qrg-title" style="font-size:15px;margin-bottom:4px">Preview & Cetak QR Code</div>
        <div class="qrg-sub" style="margin-bottom:16px">QR di bawah akan membuka halaman absensi di HP pegawai saat di-scan.</div>

        <?php if ($base_url): ?>
        <div class="qrg-preview">
            <?php if ($qr_file_exists && !$qr_saved): ?>
                <!-- Tampilkan file yang sudah disimpan -->
                <img src="<?= $qr_file_url ?>?v=<?= filemtime(__DIR__.'/../assets/qr/qr_absen.png') ?>"
                     alt="QR Code Absensi" class="qrg-qr-img">
                <div class="qrg-url-pill"><?= htmlspecialchars($scan_url) ?></div>
                <div style="font-size:12px;color:#94a3b8">QR Code tersimpan — klik "Simpan & Generate QR" untuk memperbarui</div>
            <?php elseif ($qr_saved): ?>
                <!-- Tampilkan QR yang baru digenerate -->
                <img src="<?= $qr_file_url ?>?v=<?= time() ?>"
                     alt="QR Code Absensi" class="qrg-qr-img">
                <div class="qrg-url-pill"><?= htmlspecialchars($scan_url) ?></div>
            <?php else: ?>
                <!-- Live preview dari API (belum disimpan) -->
                <img src="<?= htmlspecialchars($qr_api_url) ?>"
                     alt="QR Code Preview" class="qrg-qr-img">
                <div class="qrg-url-pill"><?= htmlspecialchars($scan_url) ?></div>
                <div style="font-size:12px;color:#f59e0b">Preview saja — klik "Simpan & Generate QR" untuk menyimpan ke server</div>
            <?php endif; ?>

            <div class="no-print" style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center">
                <button onclick="window.print()" class="qrg-btn qrg-btn-outline">
                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><rect x="4" y="2" width="12" height="6" rx="1" stroke="currentColor" stroke-width="1.4"/><path d="M4 8H2a1 1 0 0 0-1 1v6a1 1 0 0 0 1 1h2v-4h12v4h2a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1h-2" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><rect x="4" y="13" width="12" height="5" rx="1" stroke="currentColor" stroke-width="1.4"/></svg>
                    Cetak QR
                </button>
                <?php if ($qr_file_exists || $qr_saved): ?>
                <a href="<?= $qr_file_url ?>" download="qr_absen_bbws.png" class="qrg-btn qrg-btn-outline">
                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M10 3v10M6 9l4 4 4-4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 15h14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                    Download PNG
                </a>
                <?php endif; ?>
                <a href="<?= htmlspecialchars($scan_url) ?>" target="_blank" class="qrg-btn qrg-btn-outline">
                    <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M11 3h6v6M17 3l-8 8M8 5H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2v-3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Test URL
                </a>
            </div>
        </div>

        <?php else: ?>
        <div class="qrg-preview" style="color:#94a3b8;font-size:13px;gap:8px">
            <svg width="40" height="40" viewBox="0 0 20 20" fill="none" style="opacity:.3"><rect x="2" y="2" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.2"/><rect x="11" y="2" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.2"/><rect x="2" y="11" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.2"/></svg>
            Isi URL di atas terlebih dahulu, lalu klik "Simpan & Generate QR"
        </div>
        <?php endif; ?>
    </div>

    <!-- Kartu: Panduan -->
    <div class="qrg-card no-print">
        <div class="qrg-eyebrow">Panduan</div>
        <div class="qrg-title" style="font-size:15px;margin-bottom:14px">Cara Menggunakan QR Code</div>

        <div class="qrg-step">
            <div class="qrg-step-num">1</div>
            <div class="qrg-step-text">Jalankan tunnel (ngrok/cloudflare) atau pastikan server VPS sudah aktif dan bisa diakses dari HP pegawai</div>
        </div>
        <div class="qrg-step">
            <div class="qrg-step-num">2</div>
            <div class="qrg-step-text">Salin URL tunnel ke kolom di atas (tanpa <code>/</code> di akhir). Contoh: <code>https://abc.ngrok-free.app/absensi-nonasn</code></div>
        </div>
        <div class="qrg-step">
            <div class="qrg-step-num">3</div>
            <div class="qrg-step-text">Klik <strong>Simpan & Generate QR</strong> — QR Code akan tersimpan otomatis ke server</div>
        </div>
        <div class="qrg-step">
            <div class="qrg-step-num">4</div>
            <div class="qrg-step-text">Klik <strong>Cetak QR</strong> atau <strong>Download PNG</strong>, lalu tempel di pintu kantor</div>
        </div>
        <div class="qrg-step">
            <div class="qrg-step-num">5</div>
            <div class="qrg-step-text">Jika URL ngrok berubah (gratis = berubah tiap restart), ulangi langkah 1–4. Jika pakai domain tetap, cukup sekali saja.</div>
        </div>

        <hr class="qrg-divider">

        <div class="qrg-info-box">
            <strong>💡 Tips profesional:</strong> Gunakan ngrok dengan akun gratis untuk testing, atau Cloudflare Tunnel (gratis permanen) untuk produksi. Dengan Cloudflare Tunnel, URL tidak berubah sehingga QR Code hanya perlu digenerate sekali.
        </div>
    </div>

</div>
</div>

<?php include '../templates/footer.php'; ?>
</body>
</html>
