<!-- /templates/navbar.php -->
<?php
$_nav_inisial  = mb_strtoupper(mb_substr($_SESSION['nama'] ?? 'U', 0, 2));
$_nav_nama     = htmlspecialchars($_SESSION['nama'] ?? '');
$_nav_role     = ucfirst($_SESSION['role'] ?? 'Pegawai');
$_nav_via      = $_SESSION['login_via'] ?? 'web';
$_nav_is_qr    = ($_nav_via === 'qr') || !empty($_SESSION['came_from_qr']);
$_nav_is_pegawai = (($_SESSION['role'] ?? '') === 'pegawai');

// Ambil foto profil user dari database
$_nav_foto_url = null;
if (!empty($_SESSION['user_id']) && isset($pdo)) {
    try {
        $__fstmt = $pdo->prepare("SELECT foto FROM users WHERE id = ? LIMIT 1");
        $__fstmt->execute([(int)$_SESSION['user_id']]);
        $__ffoto = $__fstmt->fetchColumn();
        if ($__ffoto) {
            $_nav_foto_url = foto_url($__ffoto);
        }
    } catch (Exception $e) { /* silent */ }
}

// Pakai BASE_URL dari header.php jika sudah ada, atau hitung sendiri
if (defined('BASE_URL')) {
    $_nav_baseurl = BASE_URL;
} else {
    $_nav_https   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                 || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
                 || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
                 || str_contains($_SERVER['HTTP_HOST'] ?? '', 'ngrok');
    $_nav_dir     = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    if ($_nav_dir === '/' || $_nav_dir === '\\') $_nav_dir = '';
    $_nav_baseurl = ($_nav_https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_nav_dir;
}

// Ambil jam kerja dari settings untuk dikirim ke JS (hanya untuk pegawai)
$_nav_jam_masuk  = '07:30';
$_nav_jam_pulang = '16:00';
$_nav_toleransi  = 30;
$_nav_sudah_masuk  = false;
$_nav_sudah_pulang = false;

if ($_nav_is_pegawai && isset($pdo)) {
    try {
        $__s = $pdo->query("SELECT jam_masuk, jam_pulang, toleransi_terlambat FROM settings LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($__s) {
            $_nav_jam_masuk  = substr($__s['jam_masuk'],  0, 5);
            $_nav_jam_pulang = substr($__s['jam_pulang'], 0, 5);
            $_nav_toleransi  = (int)($__s['toleransi_terlambat'] ?? 30);
        }
        $__today = date('Y-m-d');
        $__uid   = (int)($_SESSION['user_id'] ?? 0);
        if ($__uid) {
            $__rec = $pdo->prepare("SELECT jam_masuk, jam_pulang FROM attendance WHERE user_id=? AND tanggal=? LIMIT 1");
            $__rec->execute([$__uid, $__today]);
            $__r = $__rec->fetch(PDO::FETCH_ASSOC);
            if ($__r) {
                $_nav_sudah_masuk  = !empty($__r['jam_masuk']);
                $_nav_sudah_pulang = !empty($__r['jam_pulang']);
            }
        }
    } catch (Exception $e) { /* silent */ }
}
?>
<style>
#mnav {
    position: fixed; top:0; left:0; right:0;
    height: 60px;
    background: #0B1F45;
    border-bottom: 1px solid rgba(255,255,255,.07);
    display: flex; align-items: center;
    padding: 0 16px; gap: 8px;
    z-index: 1050; box-sizing: border-box;
}
#mnav-spacer { flex:1; min-width:0; }
.mnav-wordmark { flex-shrink:0; line-height:1; }
.mnav-sep { width:1px; height:22px; background:rgba(255,255,255,.1); flex-shrink:0; }

.mnav-qr-badge {
    display: flex; align-items: center; gap:5px;
    background: rgba(212,175,55,.13);
    border: 1px solid rgba(212,175,55,.3);
    border-radius: 20px;
    padding: 3px 9px 3px 6px;
    font-size: 10px; font-weight: 700;
    color: #D4AF37; letter-spacing: .04em;
    flex-shrink: 0; white-space: nowrap;
}

#mnav-user-btn {
    all: unset;
    box-sizing: border-box;
    display: flex; align-items: center; gap: 7px;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.12);
    border-radius: 8px;
    height: 36px; padding: 0 10px;
    cursor: pointer; flex-shrink: 0;
    transition: background .2s;
}
#mnav-user-btn:hover { background: rgba(255,255,255,.13); }

.mnav-av {
    width: 26px; height: 26px; border-radius: 50%;
    background: #C9A84C;
    display: flex; align-items: center; justify-content: center;
    font-size: 10px; font-weight: 700; color: #0B1F45;
    flex-shrink: 0; letter-spacing: 0; line-height: 1;
    min-width: 26px; min-height: 26px;
}
.mnav-uname {
    font-size: 13px; font-weight: 500;
    color: rgba(255,255,255,.85);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    max-width: 120px;
}
.mnav-chev { flex-shrink:0; transition: transform .2s; }

#mnav-ham {
    all: unset; box-sizing: border-box;
    display: none; align-items: center; justify-content: center;
    width: 36px; height: 36px; border-radius: 8px;
    background: rgba(255,255,255,.07);
    border: 1px solid rgba(255,255,255,.12);
    cursor: pointer; flex-shrink: 0;
}

#mnav-dd {
    display: none; position: absolute;
    top: calc(100% + 6px); right: 0;
    min-width: 214px; background: #fff;
    border: 1px solid #E5E7EB; border-radius: 10px;
    box-shadow: 0 8px 28px rgba(0,0,0,.14);
    overflow: hidden; z-index: 2000;
}
.mnav-dd-item {
    display: flex; align-items: center; gap: 9px;
    padding: 9px 11px; border-radius: 7px;
    color: #374151; font-size: 13px; text-decoration: none;
}
.mnav-dd-item:hover { background: #F3F4F6; }
.mnav-dd-danger {
    display: flex; align-items: center; gap: 9px;
    padding: 9px 11px; border-radius: 7px;
    color: #DC2626; font-size: 13px; text-decoration: none;
}
.mnav-dd-danger:hover { background: #FEF2F2; }

/* ── BELL BUTTON ── */
#mnav-bell {
    all: unset; box-sizing: border-box;
    display: flex; align-items: center; justify-content: center;
    width: 36px; height: 36px; border-radius: 8px;
    background: rgba(255,255,255,.06);
    border: 1px solid rgba(255,255,255,.1);
    cursor: pointer; flex-shrink: 0;
    position: relative;
    transition: background .2s, border-color .2s;
}
#mnav-bell:hover { background: rgba(255,255,255,.13); border-color: rgba(255,255,255,.2); }
#mnav-bell.notif-granted { border-color: rgba(212,175,55,.5); background: rgba(212,175,55,.1); }
#mnav-bell.notif-denied  { border-color: rgba(220,38,38,.4); background: rgba(220,38,38,.08); }
#mnav-bell.notif-ringing svg { animation: bellRing .5s ease-in-out 2; }
@keyframes bellRing {
    0%,100% { transform: rotate(0deg); }
    20%      { transform: rotate(-18deg); }
    60%      { transform: rotate(18deg); }
}

/* Badge merah di atas bell */
#bell-badge {
    position: absolute; top: 4px; right: 4px;
    width: 8px; height: 8px; border-radius: 50%;
    background: #EF4444;
    border: 1.5px solid #0B1F45;
    display: none;
    animation: badgePulse 2s ease-in-out infinite;
}
@keyframes badgePulse { 0%,100%{transform:scale(1);opacity:1} 50%{transform:scale(1.25);opacity:.8} }

/* Tooltip bell */
#bell-tooltip {
    position: absolute; top: calc(100% + 8px); right: 0;
    background: #1F2937; color: #fff;
    font-size: 11px; font-weight: 500;
    padding: 6px 10px; border-radius: 7px;
    white-space: nowrap; pointer-events: none;
    opacity: 0; transform: translateY(-4px);
    transition: opacity .2s, transform .2s;
    z-index: 3000; min-width: 180px; text-align: center;
    box-shadow: 0 4px 14px rgba(0,0,0,.25);
}
#bell-tooltip::before {
    content: '';
    position: absolute; top: -5px; right: 12px;
    border-left: 5px solid transparent;
    border-right: 5px solid transparent;
    border-bottom: 5px solid #1F2937;
}
#mnav-bell:hover #bell-tooltip { opacity: 1; transform: translateY(0); }

/* Panel popup notifikasi */
#notif-panel {
    display: none; position: absolute;
    top: calc(100% + 8px); right: 0;
    width: 300px; background: #fff;
    border: 1px solid #E5E7EB; border-radius: 12px;
    box-shadow: 0 8px 32px rgba(0,0,0,.16);
    z-index: 2000; overflow: hidden;
}
.notif-panel-hd {
    padding: 12px 14px;
    background: #0B1F45;
    display: flex; align-items: center; gap: 8px;
}
.notif-panel-hd-title { color: #fff; font-size: 13px; font-weight: 700; flex: 1; }
.notif-panel-hd-close {
    all: unset; cursor: pointer;
    color: rgba(255,255,255,.5); font-size: 16px; line-height: 1;
    padding: 2px 5px; border-radius: 4px;
}
.notif-panel-hd-close:hover { color: #fff; background: rgba(255,255,255,.1); }
.notif-panel-body { padding: 12px 14px; }
.notif-status-row {
    display: flex; align-items: center; gap: 8px;
    padding: 8px 10px; border-radius: 8px;
    margin-bottom: 8px; font-size: 12px; font-weight: 600;
}
.notif-status-row.ok   { background: #F0FDF4; color: #166534; border: 1px solid #BBF7D0; }
.notif-status-row.warn { background: #FFFBEB; color: #92400E; border: 1px solid #FDE68A; }
.notif-status-row.err  { background: #FEF2F2; color: #991B1B; border: 1px solid #FECACA; }
.notif-status-row.info { background: #EFF6FF; color: #1E40AF; border: 1px solid #BFDBFE; }
.notif-btn-grant {
    width: 100%; padding: 9px; border: none; border-radius: 8px;
    background: #0B1F45; color: #D4AF37;
    font-size: 12px; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: 6px;
    transition: background .2s;
}
.notif-btn-grant:hover { background: #172B55; }
.notif-divider { height: 1px; background: #F3F4F6; margin: 10px 0; }
.notif-schedule-title { font-size: 10px; font-weight: 700; color: #9CA3AF; letter-spacing: .07em; text-transform: uppercase; margin-bottom: 6px; }
.notif-schedule-item {
    display: flex; align-items: center; gap: 6px;
    font-size: 11.5px; color: #374151; margin-bottom: 4px;
}
.notif-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }

/* ── MOBILE ── */
@media (max-width: 991.98px) { #mnav-ham { display: flex !important; } }
@media (max-width: 600px) {
    #mnav { padding: 0 12px; gap: 6px; }
    .mnav-wordmark { display: none; }
    .mnav-sep { display: none; }
    .mnav-uname { display: none; }
    .mnav-chev { display: none; }
    .mnav-qr-badge .mnav-qr-label { display: none; }
    #mnav-user-btn {
        padding: 0 !important;
        width: 36px !important; min-width: 36px !important;
        justify-content: center; border-radius: 50% !important;
    }
    #notif-panel { width: calc(100vw - 24px); right: -8px; }
}
</style>


<nav id="mnav">
    <!-- Hamburger -->
    <button id="mnav-ham" onclick="toggleSidebar()" type="button" aria-label="Menu">
        <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
            <rect y="2.5"  width="16" height="1.5" rx=".75" fill="white"/>
            <rect y="7.25" width="16" height="1.5" rx=".75" fill="white"/>
            <rect y="12"   width="16" height="1.5" rx=".75" fill="white"/>
        </svg>
    </button>

    <!-- Logo -->
    <a href="dashboard.php" style="display:flex;align-items:center;gap:7px;text-decoration:none;flex-shrink:0;">
        <div style="width:32px;height:32px;background:rgba(212,175,55,.12);border:1.5px solid rgba(212,175,55,.3);border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <img src="<?= $_nav_baseurl ?>/assets/img/logo-bbws-white.png"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='block'"
                 style="width:22px;height:22px;object-fit:contain;" alt="BBWS">
            <svg style="display:none;width:16px;height:16px;" viewBox="0 0 20 20" fill="none">
                <path d="M2 17L10 4L18 17H2Z" stroke="#D4AF37" stroke-width="1.5" stroke-linejoin="round"/>
                <path d="M5 17v-4h10v4" stroke="#D4AF37" stroke-width="1.5"/>
            </svg>
        </div>
        <div class="mnav-wordmark">
            <div style="color:#fff;font-size:12.5px;font-weight:700;line-height:1.3;">BBWS Citanduy</div>
            <div style="color:rgba(255,255,255,.38);font-size:9.5px;letter-spacing:.06em;line-height:1.2;">SISTEM ABSENSI NON ASN</div>
        </div>
    </a>

    <div class="mnav-sep"></div>
    <div id="mnav-spacer"></div>

    <?php if (isset($_SESSION['nama'])): ?>

    <?php if ($_nav_is_qr): ?>
    <div class="mnav-qr-badge">
        <svg width="13" height="13" viewBox="0 0 20 20" fill="none">
            <rect x="2" y="2" width="7" height="7" rx="1" stroke="#D4AF37" stroke-width="1.5"/>
            <rect x="11" y="2" width="7" height="7" rx="1" stroke="#D4AF37" stroke-width="1.5"/>
            <rect x="2" y="11" width="7" height="7" rx="1" stroke="#D4AF37" stroke-width="1.5"/>
            <rect x="4" y="4" width="3" height="3" fill="#D4AF37"/>
            <rect x="13" y="4" width="3" height="3" fill="#D4AF37"/>
            <rect x="4" y="13" width="3" height="3" fill="#D4AF37"/>
            <rect x="13" y="11" width="1.5" height="1.5" fill="#D4AF37"/>
            <rect x="15.5" y="11" width="1.5" height="1.5" fill="#D4AF37"/>
            <rect x="13" y="13.5" width="1.5" height="1.5" fill="#D4AF37"/>
            <rect x="15.5" y="13.5" width="1.5" height="1.5" fill="#D4AF37"/>
        </svg>
        <span class="mnav-qr-label">QR</span>
    </div>
    <?php else: ?>
    <div class="mnav-qr-badge" style="background:rgba(99,102,241,.13);border-color:rgba(99,102,241,.3);color:#A5B4FC;">
        <svg width="13" height="13" viewBox="0 0 20 20" fill="none">
            <circle cx="10" cy="10" r="8" stroke="#A5B4FC" stroke-width="1.4"/>
            <path d="M10 2c-2 2.5-3 5-3 8s1 5.5 3 8" stroke="#A5B4FC" stroke-width="1.4" stroke-linecap="round"/>
            <path d="M10 2c2 2.5 3 5 3 8s-1 5.5-3 8" stroke="#A5B4FC" stroke-width="1.4" stroke-linecap="round"/>
            <path d="M2 10h16" stroke="#A5B4FC" stroke-width="1.4" stroke-linecap="round"/>
            <path d="M3 6.5h14M3 13.5h14" stroke="#A5B4FC" stroke-width="1.4" stroke-linecap="round"/>
        </svg>
        <span class="mnav-qr-label">WEB</span>
    </div>
    <?php endif; ?>

    <!-- Bell: HANYA PEGAWAI -->
    <?php if ($_nav_is_pegawai): ?>

    <!-- Tombol Install PWA -->
    <div style="position:relative;flex-shrink:0;" id="pwaWrap">
        <button id="btnInstallPWA" type="button" aria-label="Pasang di layar utama HP"
            style="display:flex;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);
                   border-radius:8px;width:36px;height:36px;cursor:pointer;align-items:center;
                   justify-content:center;flex-shrink:0;transition:all .2s;"
            onmouseover="this.style.background='rgba(255,255,255,.15)';document.getElementById('pwa-tooltip').style.opacity='1';document.getElementById('pwa-tooltip').style.transform='translateX(-50%) translateY(0)'"
            onmouseout="this.style.background='rgba(255,255,255,.07)';document.getElementById('pwa-tooltip').style.opacity='0';document.getElementById('pwa-tooltip').style.transform='translateX(-50%) translateY(-4px)'"
            onclick="handlePWAClick()">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none">
                <path d="M12 16l-5-5 1.41-1.41L11 13.17V4h2v9.17l2.59-2.58L17 11l-5 5z" fill="rgba(255,255,255,.75)"/>
                <path d="M5 20h14v-2H5v2z" fill="rgba(255,255,255,.75)"/>
            </svg>
        </button>
        <div id="pwa-tooltip" style="position:absolute;top:calc(100% + 8px);left:50%;
                    transform:translateX(-50%) translateY(-4px);
                    background:#1F2937;color:#fff;font-size:11px;font-weight:500;
                    padding:5px 10px;border-radius:6px;white-space:nowrap;pointer-events:none;
                    opacity:0;transition:opacity .2s,transform .2s;z-index:9999;
                    box-shadow:0 4px 14px rgba(0,0,0,.25);">
            Pasang di HP
        </div>
    </div>

    <div style="position:relative;flex-shrink:0;">
        <button id="mnav-bell" type="button" onclick="bellClick(event)" aria-label="Notifikasi absensi">
            <svg id="bell-icon" width="15" height="15" viewBox="0 0 20 20" fill="none">
                <path d="M10 2a6 6 0 0 0-6 6v3.586l-.707.707A1 1 0 0 0 4 14h12a1 1 0 0 0 .707-1.707L16 11.586V8a6 6 0 0 0-6-6zM10 18a2 2 0 0 0 2-2H8a2 2 0 0 0 2 2z" fill="rgba(255,255,255,.55)"/>
            </svg>
            <span id="bell-badge"></span>
            <div id="bell-tooltip">Klik untuk aktifkan pengingat</div>
        </button>

        <!-- Panel popup -->
        <div id="notif-panel">
            <div class="notif-panel-hd">
                <svg width="14" height="14" viewBox="0 0 20 20" fill="none">
                    <path d="M10 2a6 6 0 0 0-6 6v3.586l-.707.707A1 1 0 0 0 4 14h12a1 1 0 0 0 .707-1.707L16 11.586V8a6 6 0 0 0-6-6zM10 18a2 2 0 0 0 2-2H8a2 2 0 0 0 2 2z" fill="#D4AF37"/>
                </svg>
                <span class="notif-panel-hd-title">Pengingat Absensi</span>
                <button class="notif-panel-hd-close" onclick="closeBellPanel()">&#x2715;</button>
            </div>
            <div class="notif-panel-body" id="notif-panel-body">
                <!-- diisi JS -->
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- User dropdown -->
    <div style="position:relative;flex-shrink:0;">
        <button id="mnav-user-btn" onclick="mnavToggle()" type="button" aria-label="Menu akun">
            <?php if ($_nav_foto_url): ?>
            <img src="<?= htmlspecialchars($_nav_foto_url) ?>" class="mnav-av" style="object-fit:cover;padding:0;" alt="<?= $_nav_inisial ?>" onerror="this.outerHTML='<div class=\'mnav-av\'><?= $_nav_inisial ?></div>'">
            <?php else: ?>
            <div class="mnav-av"><?= $_nav_inisial ?></div>
            <?php endif; ?>
            <span class="mnav-uname"><?= $_nav_nama ?></span>
            <svg class="mnav-chev" id="mnav-chev" width="11" height="11" viewBox="0 0 11 11" fill="none">
                <path d="M2.5 4L5.5 7L8.5 4" stroke="rgba(255,255,255,.4)" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>

        <div id="mnav-dd">
            <div style="padding:11px 13px;background:#F9FAFB;border-bottom:1px solid #F0F0F0;">
                <div style="display:flex;align-items:center;gap:9px;">
                    <?php if ($_nav_foto_url): ?>
                    <img src="<?= htmlspecialchars($_nav_foto_url) ?>" style="width:34px;height:34px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid #C9A84C;" alt="<?= $_nav_inisial ?>" onerror="this.outerHTML='<div style=\'width:34px;height:34px;border-radius:50%;background:#C9A84C;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#0B1F45;flex-shrink:0;\'><?= $_nav_inisial ?></div>'">
                    <?php else: ?>
                    <div style="width:34px;height:34px;border-radius:50%;background:#C9A84C;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;color:#0B1F45;flex-shrink:0;"><?= $_nav_inisial ?></div>
                    <?php endif; ?>
                    <div>
                        <div style="font-size:13px;font-weight:600;color:#111827;line-height:1.3;"><?= $_nav_nama ?></div>
                        <div style="font-size:11px;color:#6B7280;line-height:1.3;display:flex;align-items:center;gap:4px;">
                            <?= $_nav_role ?>
                            <?php if ($_nav_is_qr): ?>
                            <span style="background:#FEF3C7;color:#92400E;font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;letter-spacing:.04em;">QR</span>
                            <?php else: ?>
                            <span style="background:#EEF2FF;color:#3730A3;font-size:9px;font-weight:700;padding:1px 5px;border-radius:3px;letter-spacing:.04em;">WEB</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div style="padding:5px;">
                <a href="profil.php" class="mnav-dd-item">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3.5" stroke="#9CA3AF" stroke-width="1.5"/><path d="M4 17c0-3.314 2.686-5 6-5s6 1.686 6 5" stroke="#9CA3AF" stroke-width="1.5" stroke-linecap="round"/></svg>
                    Profil Saya
                </a>
                <?php if (!$_nav_is_qr): ?>
                <div style="height:1px;background:#F3F4F6;margin:3px 0;"></div>
                <a href="logout.php" class="mnav-dd-danger">
                    <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M7 3H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h3M13 15l4-5-4-5M17 10H7" stroke="#DC2626" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    Keluar
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php endif; ?>
</nav>

<div style="height:60px;"></div>

<?php if ($_nav_is_pegawai): ?>
<script>
// ── Konfigurasi dari PHP ──────────────────────────────────────────────
const NOTIF_CFG = {
    jamMasuk    : '<?= $_nav_jam_masuk ?>',
    jamPulang   : '<?= $_nav_jam_pulang ?>',
    toleransi   : <?= $_nav_toleransi ?>,            // menit
    sudahMasuk  : <?= $_nav_sudah_masuk  ? 'true' : 'false' ?>,
    sudahPulang : <?= $_nav_sudah_pulang ? 'true' : 'false' ?>,
    soundUrl    : '<?= $_nav_baseurl ?>/assets/sounds/warning.mp3',
    // Jam malam mulai mengingatkan pulang (19:00)
    jamMalamMulai: 19,
    jamMalamAkhir: 22,
};

// ── State ─────────────────────────────────────────────────────────────
let _bellPanelOpen   = false;
let _notifPermission = Notification.permission; // 'default' | 'granted' | 'denied'
let _reminderTimers  = [];
let _audioCtx        = null;
const LS_KEY         = 'absen_notif_v1';

// ── Audio ─────────────────────────────────────────────────────────────
function playWarningSound() {
    try {
        const audio = new Audio(NOTIF_CFG.soundUrl);
        audio.volume = 0.8;
        audio.play().catch(() => {
            // Fallback: Web Audio API beep jika file gagal load
            try {
                const ctx = new (window.AudioContext || window.webkitAudioContext)();
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain); gain.connect(ctx.destination);
                osc.type = 'sine'; osc.frequency.value = 880;
                gain.gain.setValueAtTime(0.4, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.8);
                osc.start(); osc.stop(ctx.currentTime + 0.8);
            } catch(e) {}
        });
    } catch(e) {}
}

// ── Kirim notifikasi browser ──────────────────────────────────────────
function sendNotif(title, body, tag) {
    if (Notification.permission !== 'granted') return;
    const n = new Notification(title, {
        body,
        tag,
        icon  : '<?= $_nav_baseurl ?>/assets/img/logo-bbws-white.png',
        badge : '<?= $_nav_baseurl ?>/assets/img/logo-bbws-white.png',
        requireInteraction: true,
        silent: true, // suara dihandle manual
    });
    n.onclick = () => { window.focus(); n.close(); };
    playWarningSound();
    // Animasi bell berdering
    const bell = document.getElementById('mnav-bell');
    if (bell) { bell.classList.add('notif-ringing'); setTimeout(() => bell.classList.remove('notif-ringing'), 1200); }
}

// ── Parse jam "HH:MM" → menit sejak tengah malam ─────────────────────
function toMenit(hhmm) {
    const [h, m] = hhmm.split(':').map(Number);
    return h * 60 + m;
}
function menitSekarang() {
    const n = new Date();
    return n.getHours() * 60 + n.getMinutes();
}

// ── Jadwalkan pengingat ───────────────────────────────────────────────
function jadwalkanPengingat() {
    // Bersihkan timer lama
    _reminderTimers.forEach(clearTimeout);
    _reminderTimers = [];

    if (Notification.permission !== 'granted') return;
    if (NOTIF_CFG.sudahMasuk && NOTIF_CFG.sudahPulang) return; // selesai semua

    const sekarang = menitSekarang();
    const batasMasuk = toMenit(NOTIF_CFG.jamMasuk) + NOTIF_CFG.toleransi;

    // ── Pengingat 1: Belum absen masuk setelah batas toleransi ──────
    if (!NOTIF_CFG.sudahMasuk) {
        const selisihMs = (batasMasuk - sekarang) * 60 * 1000;

        if (sekarang < batasMasuk && selisihMs > 0) {
            // Belum lewat batas — jadwalkan tepat saat batas
            const t1 = setTimeout(() => {
                sendNotif(
                    '⚠️ Pengingat Absen Masuk',
                    'Anda belum absen masuk! Segera lakukan absensi sebelum terlambat.',
                    'absen-masuk'
                );
                showBadge(true);
            }, selisihMs);
            _reminderTimers.push(t1);
        } else if (sekarang >= batasMasuk) {
            // Sudah lewat batas — ingatkan sekarang (delay 2 detik agar UI siap)
            const t1b = setTimeout(() => {
                sendNotif(
                    '🚨 Belum Absen Masuk!',
                    'Anda sudah terlambat absen masuk. Segera lakukan absensi sekarang.',
                    'absen-masuk-late'
                );
                showBadge(true);
            }, 2000);
            _reminderTimers.push(t1b);

            // Ulangi tiap 30 menit jika masih belum absen
            for (let r = 1; r <= 4; r++) {
                const tr = setTimeout(() => {
                    if (!NOTIF_CFG.sudahMasuk) {
                        sendNotif(
                            '🚨 Masih Belum Absen Masuk!',
                            'Pengingat ke-' + r + ': Anda belum absen masuk hari ini.',
                            'absen-masuk-repeat-' + r
                        );
                    }
                }, 2000 + r * 30 * 60 * 1000);
                _reminderTimers.push(tr);
            }
        }
    }

    // ── Pengingat 2: Belum absen pulang saat malam ───────────────────
    if (NOTIF_CFG.sudahMasuk && !NOTIF_CFG.sudahPulang) {
        const mulaiMalam = NOTIF_CFG.jamMalamMulai * 60; // 19:00
        const akhirMalam = NOTIF_CFG.jamMalamAkhir * 60; // 22:00

        if (sekarang < mulaiMalam) {
            // Jadwalkan pukul 19:00
            const delayMs = (mulaiMalam - sekarang) * 60 * 1000;
            const t2 = setTimeout(() => {
                if (!NOTIF_CFG.sudahPulang) {
                    sendNotif(
                        '🌙 Jangan Lupa Absen Pulang!',
                        'Sudah malam — Anda masuk tapi belum absen pulang. Segera lakukan absensi pulang.',
                        'absen-pulang-malam'
                    );
                    showBadge(true);
                }
            }, delayMs);
            _reminderTimers.push(t2);

            // Ulangi pukul 20:00 jika masih belum pulang
            const delay2 = ((mulaiMalam + 60) - sekarang) * 60 * 1000;
            if (delay2 > 0) {
                const t3 = setTimeout(() => {
                    if (!NOTIF_CFG.sudahPulang) {
                        sendNotif(
                            '⚠️ Absen Pulang Terlewat!',
                            'Sudah pukul 20:00 dan Anda belum absen pulang. Harap segera absen.',
                            'absen-pulang-20'
                        );
                    }
                }, delay2);
                _reminderTimers.push(t3);
            }
        } else if (sekarang >= mulaiMalam && sekarang <= akhirMalam) {
            // Sudah malam, langsung ingatkan
            setTimeout(() => {
                if (!NOTIF_CFG.sudahPulang) {
                    sendNotif(
                        '🌙 Jangan Lupa Absen Pulang!',
                        'Sudah malam — Anda belum absen pulang. Segera lakukan absensi.',
                        'absen-pulang-now'
                    );
                    showBadge(true);
                }
            }, 3000);
        }
    }
}

// ── Badge merah di atas bell ──────────────────────────────────────────
function showBadge(show) {
    const b = document.getElementById('bell-badge');
    if (b) b.style.display = show ? 'block' : 'none';
}

// ── Update warna bell sesuai permission ──────────────────────────────
function updateBellStyle() {
    const bell = document.getElementById('mnav-bell');
    const tt   = document.getElementById('bell-tooltip');
    if (!bell) return;
    bell.classList.remove('notif-granted', 'notif-denied');
    if (_notifPermission === 'granted') {
        bell.classList.add('notif-granted');
        if (tt) tt.textContent = 'Pengingat aktif — klik untuk detail';
    } else if (_notifPermission === 'denied') {
        bell.classList.add('notif-denied');
        if (tt) tt.textContent = 'Notifikasi diblokir browser';
    } else {
        if (tt) tt.textContent = 'Klik untuk aktifkan pengingat';
    }
}

// ── Render isi panel ──────────────────────────────────────────────────
function renderPanel() {
    const body = document.getElementById('notif-panel-body');
    if (!body) return;

    const batasMasuk = toMenit(NOTIF_CFG.jamMasuk) + NOTIF_CFG.toleransi;
    const sekarang   = menitSekarang();
    const fmt = m => String(Math.floor(m/60)).padStart(2,'0') + ':' + String(m%60).padStart(2,'0');

    let html = '';

    // Status permission
    if (_notifPermission === 'default') {
        html += `
        <div class="notif-status-row info">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none"><path d="M10 2a8 8 0 1 0 0 16A8 8 0 0 0 10 2zm0 4a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm0 4a1 1 0 0 1 1 1v3a1 1 0 0 1-2 0v-3a1 1 0 0 1 1-1z" fill="#1E40AF"/></svg>
            Izinkan notifikasi untuk mendapat pengingat absensi otomatis
        </div>
        <button class="notif-btn-grant" onclick="mintaIzinNotif()">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M10 2a6 6 0 0 0-6 6v3.586l-.707.707A1 1 0 0 0 4 14h12a1 1 0 0 0 .707-1.707L16 11.586V8a6 6 0 0 0-6-6zM10 18a2 2 0 0 0 2-2H8a2 2 0 0 0 2 2z" fill="#D4AF37"/></svg>
            Izinkan Notifikasi Browser
        </button>`;
    } else if (_notifPermission === 'granted') {
        // Status absen hari ini
        const stMasuk  = NOTIF_CFG.sudahMasuk  ? 'ok'   : (sekarang > batasMasuk ? 'err' : 'warn');
        const stPulang = NOTIF_CFG.sudahPulang ? 'ok'   : (NOTIF_CFG.sudahMasuk ? 'warn' : 'info');
        html += `
        <div class="notif-status-row ok">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M16.707 5.293a1 1 0 0 1 0 1.414l-8 8a1 1 0 0 1-1.414 0l-4-4a1 1 0 1 1 1.414-1.414L8 12.586l7.293-7.293a1 1 0 0 1 1.414 0z" fill="#166534"/></svg>
            Pengingat aktif
        </div>
        <div class="notif-status-row ${stMasuk}">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M10 2a8 8 0 1 0 0 16A8 8 0 0 0 10 2zm.75 4a.75.75 0 0 0-1.5 0v4.5l3 1.5a.75.75 0 0 0 .67-1.34L10.75 9.5V6z" fill="currentColor"/></svg>
            Absen masuk: ${NOTIF_CFG.sudahMasuk ? '<strong>Sudah ✓</strong>' : '<strong>Belum</strong> (batas ' + fmt(batasMasuk) + ')'}
        </div>
        <div class="notif-status-row ${stPulang}">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M10 2a8 8 0 1 0 0 16A8 8 0 0 0 10 2zm.75 4a.75.75 0 0 0-1.5 0v4.5l3 1.5a.75.75 0 0 0 .67-1.34L10.75 9.5V6z" fill="currentColor"/></svg>
            Absen pulang: ${NOTIF_CFG.sudahPulang ? '<strong>Sudah ✓</strong>' : '<strong>Belum</strong>'}
        </div>`;
    } else {
        html += `
        <div class="notif-status-row err">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M10 2a8 8 0 1 0 0 16A8 8 0 0 0 10 2zm0 4a1 1 0 1 1 0 2 1 1 0 0 1 0-2zm0 4a1 1 0 0 1 1 1v3a1 1 0 0 1-2 0v-3a1 1 0 0 1 1-1z" fill="#991B1B"/></svg>
            Notifikasi diblokir. Aktifkan manual di pengaturan browser (ikon kunci/info di address bar).
        </div>`;
    }

    // Jadwal pengingat
    html += `
    <div class="notif-divider"></div>
    <div class="notif-schedule-title">Jadwal Pengingat Otomatis</div>
    <div class="notif-schedule-item">
        <span class="notif-dot" style="background:#F59E0B;"></span>
        Pukul ${fmt(batasMasuk)} — jika belum absen masuk
    </div>
    <div class="notif-schedule-item">
        <span class="notif-dot" style="background:#6366F1;"></span>
        Pukul 19:00 &amp; 20:00 — jika belum absen pulang
    </div>
    <div class="notif-schedule-item">
        <span class="notif-dot" style="background:#EF4444;"></span>
        Setiap 30 menit jika masih terlambat masuk
    </div>`;

    body.innerHTML = html;

    // Pasang onclick ke tombol izin setelah render
    const btnGrant = body.querySelector('.notif-btn-grant');
    if (btnGrant) btnGrant.onclick = mintaIzinNotif;
}

// ── Minta izin ────────────────────────────────────────────────────────
function mintaIzinNotif() {
    Notification.requestPermission().then(perm => {
        _notifPermission = perm;
        updateBellStyle();
        renderPanel();
        if (perm === 'granted') {
            jadwalkanPengingat();
            // Notif selamat datang
            setTimeout(() => {
                sendNotif(
                    '✅ Pengingat Absensi Aktif',
                    'Anda akan mendapat notifikasi pengingat absen masuk & pulang secara otomatis.',
                    'welcome'
                );
            }, 500);
        }
    });
}

// ── Toggle panel ──────────────────────────────────────────────────────
function bellClick(e) {
    e.stopPropagation();
    // Tutup dropdown user jika terbuka
    const dd = document.getElementById('mnav-dd');
    if (dd) dd.style.display = 'none';

    _bellPanelOpen = !_bellPanelOpen;
    const panel = document.getElementById('notif-panel');
    if (!panel) return;

    if (_bellPanelOpen) {
        renderPanel();
        panel.style.display = 'block';
        showBadge(false); // hapus badge saat panel dibuka
        // Jika belum pernah izin, langsung minta
        if (_notifPermission === 'default') {
            mintaIzinNotif();
        }
    } else {
        panel.style.display = 'none';
    }
}

function closeBellPanel() {
    _bellPanelOpen = false;
    const panel = document.getElementById('notif-panel');
    if (panel) panel.style.display = 'none';
}

// ── Tutup panel saat klik di luar ─────────────────────────────────────
document.addEventListener('click', function(e) {
    const bell  = document.getElementById('mnav-bell');
    const panel = document.getElementById('notif-panel');
    if (!panel || !bell) return;
    if (!bell.contains(e.target) && !panel.contains(e.target)) {
        panel.style.display = 'none';
        _bellPanelOpen = false;
    }
    // dropdown user
    const btn = document.getElementById('mnav-user-btn');
    const dd  = document.getElementById('mnav-dd');
    if (!btn || !dd) return;
    if (!btn.contains(e.target) && !dd.contains(e.target)) {
        dd.style.display = 'none';
        const chev = document.getElementById('mnav-chev');
        if (chev) chev.style.transform = '';
    }
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeBellPanel();
        const dd = document.getElementById('mnav-dd');
        if (dd) dd.style.display = 'none';
    }
});

// ── Init saat halaman load ────────────────────────────────────────────
(function init() {
    updateBellStyle();

    // Tampilkan badge jika ada masalah absen
    const sekarang   = menitSekarang();
    const batasMasuk = toMenit(NOTIF_CFG.jamMasuk) + NOTIF_CFG.toleransi;
    const sudahMalam = sekarang >= NOTIF_CFG.jamMalamMulai * 60;

    const adaMasalah = (!NOTIF_CFG.sudahMasuk && sekarang > batasMasuk)
                    || (NOTIF_CFG.sudahMasuk && !NOTIF_CFG.sudahPulang && sudahMalam);

    if (adaMasalah && _notifPermission === 'granted') showBadge(true);

    // Jadwalkan jika sudah granted
    if (_notifPermission === 'granted') jadwalkanPengingat();
})();
</script>
<?php else: ?>
<script>
// Untuk non-pegawai: hanya logika dropdown user biasa
document.addEventListener('click', function(e) {
    var btn = document.getElementById('mnav-user-btn');
    var dd  = document.getElementById('mnav-dd');
    if (!btn || !dd) return;
    if (!btn.contains(e.target) && !dd.contains(e.target)) {
        dd.style.display = 'none';
        var chev = document.getElementById('mnav-chev');
        if (chev) chev.style.transform = '';
    }
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var dd = document.getElementById('mnav-dd');
        if (dd) dd.style.display = 'none';
    }
});
</script>
<?php endif; ?>

<script>
function mnavToggle() {
    var dd   = document.getElementById('mnav-dd');
    var chev = document.getElementById('mnav-chev');
    var open = dd.style.display === 'block';
    dd.style.display = open ? 'none' : 'block';
    if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
    // Tutup panel notif jika terbuka
    var panel = document.getElementById('notif-panel');
    if (panel && !open) { panel.style.display = 'none'; _bellPanelOpen = false; }
}
</script>

<script>
/* ── PWA Install ─────────────────────────────────────────── */
var _pwaPrompt = null;

if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('/absensi-nonasn/sw.js').catch(function(){});
}

window.addEventListener('beforeinstallprompt', function(e) {
    e.preventDefault();
    _pwaPrompt = e;
});

window.addEventListener('appinstalled', function() {
    _pwaPrompt = null;
    var w = document.getElementById('pwaWrap');
    if (w) w.style.display = 'none';
});

function handlePWAClick() {
    if (_pwaPrompt) {
        _pwaPrompt.prompt();
        _pwaPrompt.userChoice.then(function(c) {
            if (c.outcome === 'accepted') {
                var w = document.getElementById('pwaWrap');
                if (w) w.style.display = 'none';
            }
            _pwaPrompt = null;
        });
    } else {
        showPwaGuide();
    }
}

function showPwaGuide() {
    var existing = document.getElementById('pwaGuideModal');
    if (existing) { existing.style.display = 'flex'; return; }

    var modal = document.createElement('div');
    modal.id = 'pwaGuideModal';
    modal.style.cssText = 'position:fixed;inset:0;z-index:99999;background:rgba(0,0,0,.6);display:flex;align-items:flex-end;justify-content:center;';

    var inner = document.createElement('div');
    inner.style.cssText = 'background:#fff;border-radius:20px 20px 0 0;padding:24px 20px 32px;width:100%;max-width:480px;';
    inner.innerHTML =
        '<div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;">' +
            '<div style="width:40px;height:40px;background:#0B1F45;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">' +
                '<svg width="22" height="22" viewBox="0 0 24 24" fill="none">' +
                    '<path d="M12 16l-5-5 1.41-1.41L11 13.17V4h2v9.17l2.59-2.58L17 11l-5 5z" fill="#D4AF37"/>' +
                    '<path d="M5 20h14v-2H5v2z" fill="#D4AF37"/>' +
                '</svg>' +
            '</div>' +
            '<div>' +
                '<div style="font-weight:700;font-size:15px;color:#0B1F45;">Pasang Absen BBWS di HP</div>' +
                '<div style="font-size:12px;color:#6B7280;">Akses cepat seperti aplikasi</div>' +
            '</div>' +
            '<button id="pwaCloseBtn" style="margin-left:auto;background:none;border:none;font-size:22px;cursor:pointer;color:#9CA3AF;line-height:1;padding:0 4px;">&times;</button>' +
        '</div>' +
        '<div style="background:#F0FDF4;border:1px solid #BBF7D0;border-radius:10px;padding:12px 14px;margin-bottom:10px;">' +
            '<div style="font-size:12px;font-weight:700;color:#166534;margin-bottom:6px;">Android (Chrome)</div>' +
            '<div style="font-size:12px;color:#374151;line-height:1.7;">' +
                '1. Tap menu <b>&#8942;</b> di pojok kanan atas<br>' +
                '2. Pilih <b>Tambahkan ke layar utama</b><br>' +
                '3. Tap <b>Tambahkan</b>' +
            '</div>' +
        '</div>' +
        '<div style="background:#EFF6FF;border:1px solid #BFDBFE;border-radius:10px;padding:12px 14px;">' +
            '<div style="font-size:12px;font-weight:700;color:#1E40AF;margin-bottom:6px;">iPhone (Safari)</div>' +
            '<div style="font-size:12px;color:#374151;line-height:1.7;">' +
                '1. Tap tombol <b>Share</b> (kotak &amp; panah atas)<br>' +
                '2. Pilih <b>Tambahkan ke Layar Utama</b><br>' +
                '3. Tap <b>Tambahkan</b>' +
            '</div>' +
        '</div>';

    modal.appendChild(inner);
    modal.addEventListener('click', function(e) {
        if (e.target === modal) modal.style.display = 'none';
    });
    document.body.appendChild(modal);
    var closeBtn = document.getElementById('pwaCloseBtn');
    if (closeBtn) closeBtn.onclick = function() { modal.style.display = 'none'; };
}
</script>