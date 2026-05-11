<!-- /templates/sidebar.php -->
<?php
$current_page = basename($_SERVER['PHP_SELF']);
$is_pegawai   = isset($_SESSION['role']) && $_SESSION['role'] === 'pegawai';
$is_admin     = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$today        = date('Y-m-d');
$_sb_via      = $_SESSION['login_via'] ?? 'web';
$_sb_is_qr    = ($_sb_via === 'qr') || !empty($_SESSION['came_from_qr']);

// Ambil foto profil user dari database
$_sb_foto_url = null;
if (!empty($_SESSION['user_id']) && isset($pdo)) {
    try {
        $__sfstmt = $pdo->prepare("SELECT foto FROM users WHERE id = ? LIMIT 1");
        $__sfstmt->execute([(int)$_SESSION['user_id']]);
        $__sffoto = $__sfstmt->fetchColumn();
        if ($__sffoto) {
            $_sb_foto_url = foto_url($__sffoto);
        }
    } catch (Exception $e) { /* silent */ }
}

// Pakai BASE_URL dari header.php jika sudah ada, atau hitung sendiri
if (defined('BASE_URL')) {
    $_sb_baseurl = BASE_URL;
} else {
    $_sb_https   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
                || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443
                || str_contains($_SERVER['HTTP_HOST'] ?? '', 'ngrok');
    $_sb_dir     = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');
    if ($_sb_dir === '/' || $_sb_dir === '\\') $_sb_dir = '';
    $_sb_baseurl = ($_sb_https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_sb_dir;
}

function is_active_group(array $pages): bool {
    return in_array(basename($_SERVER['PHP_SELF']), $pages, true);
}

$wfh_active    = false;
$wfa_active    = false;
$cuti_menunggu = 0;

if ($is_admin) {
    $s = $pdo->prepare("SELECT COUNT(*) FROM wfh_schedule WHERE tanggal_mulai<=? AND tanggal_selesai>=? AND berlaku_untuk='semua'");
    $s->execute([$today,$today]); $wfh_active = (bool)$s->fetchColumn();

    $s = $pdo->prepare("SELECT COUNT(*) FROM wfa_schedule WHERE tanggal_mulai<=? AND tanggal_selesai>=? AND berlaku_untuk='semua'");
    $s->execute([$today,$today]); $wfa_active = (bool)$s->fetchColumn();

    $s = $pdo->query("SELECT COUNT(*) FROM cuti WHERE status='menunggu'");
    $cuti_menunggu = (int)$s->fetchColumn();
}

$absensi_active  = is_active_group(['absensi.php','riwayat.php']);
$jadwal_active   = is_active_group(['wfh.php','wfa.php']);
$cuti_adm_active = is_active_group(['cuti.php']);
?>

<style>
.sb-root {
    position: fixed; top:0; left:0;
    width: 256px; height: 100vh;
    background: #0B1F45;
    border-right: 1px solid rgba(255,255,255,.06);
    display: flex; flex-direction: column;
    z-index: 1040;
    transition: transform .28s cubic-bezier(.4,0,.2,1);
    overflow: hidden;
}
.sb-logo {
    height: 60px; padding: 0 16px;
    display: flex; align-items: center; gap: 10px;
    border-bottom: 1px solid rgba(255,255,255,.06); flex-shrink:0;
}
.sb-logo-mark {
    width:32px; height:32px;
    background:rgba(212,175,55,.1); border:1.5px solid rgba(212,175,55,.28);
    border-radius:7px; display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.sb-logo-main { color:#fff; font-size:12px; font-weight:700; line-height:1.25; }
.sb-logo-sub  { color:rgba(255,255,255,.32); font-size:9px; letter-spacing:.07em; line-height:1.2; }

.sb-chip {
    margin: 12px 14px 4px;
    display:inline-flex; align-items:center; gap:5px;
    background:rgba(212,175,55,.08); border:1px solid rgba(212,175,55,.18);
    border-radius:5px; padding:3px 9px;
    font-size:9.5px; font-weight:700; color:rgba(212,175,55,.8); letter-spacing:.1em;
    flex-shrink:0;
}
.sb-chip-dot { width:5px; height:5px; border-radius:50%; background:#C9A84C; flex-shrink:0; }

/* QR chip — beda warna */
.sb-chip-qr {
    margin: 12px 14px 4px;
    display:inline-flex; align-items:center; gap:6px;
    background:rgba(212,175,55,.12); border:1px solid rgba(212,175,55,.3);
    border-radius:5px; padding:4px 10px;
    font-size:9.5px; font-weight:700; color:#D4AF37; letter-spacing:.08em;
    flex-shrink:0;
}

.sb-nav {
    flex:1; overflow-y:auto; padding:4px 10px 12px;
    scrollbar-width:none;
}
.sb-nav::-webkit-scrollbar { display:none; }

.sb-sec {
    font-size:9px; font-weight:700; letter-spacing:.12em;
    color:rgba(255,255,255,.2); padding:14px 8px 4px;
    text-transform:uppercase;
}
.sb-item {
    display:flex; align-items:center; gap:9px;
    padding:8px 10px; border-radius:7px;
    color:rgba(255,255,255,.55); font-size:12.5px; font-weight:500;
    text-decoration:none; cursor:pointer; border:none; background:transparent;
    width:100%; text-align:left; transition:background .15s, color .15s;
    position:relative; margin-bottom:1px; box-sizing:border-box;
}
.sb-item:hover { background:rgba(255,255,255,.06); color:rgba(255,255,255,.88); }
.sb-item.on {
    background:rgba(212,175,55,.13); color:#D4AF37;
}
.sb-item.on::before {
    content:''; position:absolute; left:0; top:24%; bottom:24%;
    width:3px; background:#D4AF37; border-radius:0 2px 2px 0;
}
.sb-ico { width:16px; height:16px; flex-shrink:0; opacity:.65; display:flex; align-items:center; justify-content:center; }
.sb-item.on .sb-ico, .sb-item:hover .sb-ico { opacity:1; }
.sb-chev { margin-left:auto; flex-shrink:0; transition:transform .2s; }
.sb-chev.open { transform:rotate(180deg); }
.sb-badge {
    margin-left:auto; background:#D4AF37; color:#0B1F45;
    font-size:9px; font-weight:700; min-width:16px; height:16px;
    padding:0 4px; border-radius:8px;
    display:flex; align-items:center; justify-content:center; flex-shrink:0;
}
.sb-badge.g { background:#10B981; color:#fff; }
.sb-badge.p { background:#8B5CF6; color:#fff; }
.sb-sub { overflow:hidden; max-height:0; transition:max-height .22s cubic-bezier(.4,0,.2,1); }
.sb-sub.open { max-height:280px; }
.sb-sub-a {
    display:flex; align-items:center; gap:0;
    padding:6px 10px 6px 28px; border-radius:6px;
    color:rgba(255,255,255,.4); font-size:12px; text-decoration:none;
    transition:background .14s, color .14s; position:relative; margin-bottom:1px;
}
.sb-sub-a::before {
    content:''; position:absolute; left:16px; top:50%; transform:translateY(-50%);
    width:3px; height:3px; border-radius:50%; background:rgba(255,255,255,.2);
}
.sb-sub-a:hover { background:rgba(255,255,255,.05); color:rgba(255,255,255,.78); }
.sb-sub-a:hover::before { background:rgba(255,255,255,.5); }
.sb-sub-a.on { color:#D4AF37; }
.sb-sub-a.on::before { background:#D4AF37; }
.sb-div { height:1px; background:rgba(255,255,255,.06); margin:6px 0; }

/* Footer */
.sb-foot { padding:12px 14px; border-top:1px solid rgba(255,255,255,.06); flex-shrink:0; }
.sb-foot-inner { display:flex; align-items:center; gap:9px; }
.sb-foot-av {
    width:30px; height:30px; border-radius:50%;
    background:#C9A84C; display:flex; align-items:center; justify-content:center;
    font-size:10px; font-weight:700; color:#0B1F45; flex-shrink:0;
}
.sb-foot-name { font-size:12px; font-weight:600; color:rgba(255,255,255,.82); line-height:1.3; }
.sb-foot-role { font-size:10px; color:rgba(255,255,255,.32); display:flex; align-items:center; gap:4px; }

/* QR indicator di footer */
.sb-qr-indicator {
    display:inline-flex; align-items:center; gap:4px;
    background:rgba(212,175,55,.12); border:1px solid rgba(212,175,55,.25);
    border-radius:4px; padding:2px 6px;
    font-size:9px; font-weight:700; color:#D4AF37;
}

/* Overlay */
#sbOverlay {
    display:none; position:fixed; inset:0;
    background:rgba(0,0,0,.5); z-index:1039;
}
#sbOverlay.show { display:block; }

@media (max-width: 991.98px) {
    .sb-root { transform:translateX(-100%); }
    .sb-root.show { transform:translateX(0); box-shadow:8px 0 32px rgba(0,0,0,.3); }
}
@media (min-width: 992px) {
    .main-content { margin-left:256px; }
}
</style>

<div class="sb-root" id="sidebarMenu">

    <!-- Logo header -->
    <div class="sb-logo">
        <div class="sb-logo-mark">
            <img src="<?= $_sb_baseurl ?>/assets/img/logo-bbws-white.png"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='block'"
                 style="width:22px;height:22px;object-fit:contain;" alt="">
            <svg style="display:none;width:16px;height:16px;" viewBox="0 0 20 20" fill="none">
                <path d="M2 17L10 4L18 17H2Z" stroke="#D4AF37" stroke-width="1.5" stroke-linejoin="round"/>
                <path d="M5 17v-4h10v4" stroke="#D4AF37" stroke-width="1.5"/>
            </svg>
        </div>
        <div>
            <div class="sb-logo-main">BBWS Citanduy</div>
            <div class="sb-logo-sub">SISTEM ABSENSI NON ASN</div>
        </div>
    </div>

    <!-- Role chip -->
    <?php if ($_sb_is_qr): ?>
    <div class="sb-chip-qr">
        <svg width="11" height="11" viewBox="0 0 16 16" fill="none">
            <rect x="1" y="1" width="6" height="6" rx=".8" stroke="#D4AF37" stroke-width="1.2"/>
            <rect x="9" y="1" width="6" height="6" rx=".8" stroke="#D4AF37" stroke-width="1.2"/>
            <rect x="1" y="9" width="6" height="6" rx=".8" stroke="#D4AF37" stroke-width="1.2"/>
            <rect x="2.5" y="2.5" width="3" height="3" fill="#D4AF37"/>
            <rect x="10.5" y="2.5" width="3" height="3" fill="#D4AF37"/>
            <rect x="2.5" y="10.5" width="3" height="3" fill="#D4AF37"/>
            <rect x="10" y="9" width="1.5" height="1.5" fill="#D4AF37"/>
            <rect x="12" y="9" width="1.5" height="1.5" fill="#D4AF37"/>
            <rect x="10" y="11" width="1.5" height="1.5" fill="#D4AF37"/>
            <rect x="12" y="11" width="1.5" height="1.5" fill="#D4AF37"/>
        </svg>
        LOGIN VIA QR SCAN
    </div>
    <?php else: ?>
    <div class="sb-chip-qr" style="background:rgba(99,102,241,.1);border-color:rgba(99,102,241,.25);color:#A5B4FC;">
        <svg width="11" height="11" viewBox="0 0 16 16" fill="none">
            <circle cx="8" cy="8" r="6.5" stroke="#A5B4FC" stroke-width="1.2"/>
            <path d="M8 1.5c-1.5 2-2.5 4-2.5 6.5s1 4.5 2.5 6.5" stroke="#A5B4FC" stroke-width="1.2" stroke-linecap="round"/>
            <path d="M8 1.5c1.5 2 2.5 4 2.5 6.5s-1 4.5-2.5 6.5" stroke="#A5B4FC" stroke-width="1.2" stroke-linecap="round"/>
            <path d="M1.5 8h13M2 5.5h12M2 10.5h12" stroke="#A5B4FC" stroke-width="1.2" stroke-linecap="round"/>
        </svg>
        LOGIN VIA WEBSITE
    </div>
    <?php endif; ?>

    <!-- Nav -->
    <nav class="sb-nav">

        <?php if ($is_pegawai): ?>
        <div class="sb-sec">Utama</div>
        <a href="dashboard.php" class="sb-item <?= $current_page==='dashboard.php'?'on':'' ?>">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="2" y="2" width="7" height="7" rx="1.5" fill="currentColor"/><rect x="11" y="2" width="7" height="7" rx="1.5" fill="currentColor" opacity=".45"/><rect x="2" y="11" width="7" height="7" rx="1.5" fill="currentColor" opacity=".45"/><rect x="11" y="11" width="7" height="7" rx="1.5" fill="currentColor"/></svg></span>
            Dashboard
        </a>

        <div class="sb-sec">Kehadiran</div>

        <button class="sb-item <?= $absensi_active?'on':'' ?>" onclick="sbToggle('sb-absensi',this)">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.4"/><path d="M10 6v4l2.5 2.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            Absensi
            <svg class="sb-chev <?= $absensi_active?'open':'' ?>" width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M3 5L6.5 8.5L10 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <div class="sb-sub <?= $absensi_active?'open':'' ?>" id="sb-absensi">
            <a href="absensi.php" class="sb-sub-a <?= $current_page==='absensi.php'?'on':'' ?>">Portal Absensi</a>
            <a href="riwayat.php" class="sb-sub-a <?= $current_page==='riwayat.php'?'on':'' ?>">Riwayat Absensi</a>
        </div>

        <a href="cuti.php" class="sb-item <?= $current_page==='cuti.php'?'on':'' ?>">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="13" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M7 2v4M13 2v4M3 8h14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            Ajukan Cuti
        </a>

        <div class="sb-div"></div>
        <div class="sb-sec">Akun</div>
        <a href="profil.php" class="sb-item <?= $current_page==='profil.php'?'on':'' ?>">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="7" r="3.5" stroke="currentColor" stroke-width="1.4"/><path d="M4 17c0-3.314 2.686-5 6-5s6 1.686 6 5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            Profil Saya
        </a>

        <?php elseif ($is_admin): ?>
        <div class="sb-sec">Utama</div>
        <a href="dashboard.php" class="sb-item <?= $current_page==='dashboard.php'?'on':'' ?>">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="2" y="2" width="7" height="7" rx="1.5" fill="currentColor"/><rect x="11" y="2" width="7" height="7" rx="1.5" fill="currentColor" opacity=".45"/><rect x="2" y="11" width="7" height="7" rx="1.5" fill="currentColor" opacity=".45"/><rect x="11" y="11" width="7" height="7" rx="1.5" fill="currentColor"/></svg></span>
            Dashboard
        </a>
        <a href="pegawai.php" class="sb-item <?= $current_page==='pegawai.php'?'on':'' ?>">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="7" cy="7" r="3" stroke="currentColor" stroke-width="1.4"/><path d="M2 17c0-2.761 2.239-4.5 5-4.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><circle cx="14" cy="10" r="2.5" stroke="currentColor" stroke-width="1.4"/><path d="M10.5 17.5c0-2 1.567-3.5 3.5-3.5s3.5 1.5 3.5 3.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            Kelola Pegawai
        </a>

        <div class="sb-sec">Jadwal & Kehadiran</div>
        <a href="rekap.php" class="sb-item <?= $current_page==='rekap.php'?'on':'' ?>">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M3 15V5M7 15V9M11 15V7M15 15V3" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            Rekap Absensi
        </a>

        <button class="sb-item <?= $jadwal_active?'on':'' ?>" onclick="sbToggle('sb-jadwal',this)">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="13" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M7 2v4M13 2v4M3 8h14M7 12h6M7 15h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            Jadwal Kerja
            <?php if ($wfh_active||$wfa_active): ?><span class="sb-badge g" style="margin-left:auto;">ON</span><?php endif; ?>
            <svg class="sb-chev <?= $jadwal_active?'open':'' ?>" width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M3 5L6.5 8.5L10 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <div class="sb-sub <?= $jadwal_active?'open':'' ?>" id="sb-jadwal">
            <a href="wfh.php" class="sb-sub-a <?= $current_page==='wfh.php'?'on':'' ?>">Kelola WFH<?php if($wfh_active): ?> <span class="sb-badge g" style="margin-left:auto;font-size:8px;padding:0 4px;min-width:14px;height:14px;">ON</span><?php endif; ?></a>
            <a href="wfa.php" class="sb-sub-a <?= $current_page==='wfa.php'?'on':'' ?>">Kelola WFA<?php if($wfa_active): ?> <span class="sb-badge p" style="margin-left:auto;font-size:8px;padding:0 4px;min-width:14px;height:14px;">ON</span><?php endif; ?></a>
        </div>

        <button class="sb-item <?= $cuti_adm_active?'on':'' ?>" onclick="sbToggle('sb-cuti',this)">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M9 11l2 2 4-4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/><rect x="3" y="4" width="14" height="13" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M7 2v4M13 2v4M3 8h14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            Kelola Cuti
            <?php if($cuti_menunggu>0): ?><span class="sb-badge"><?= $cuti_menunggu ?></span><?php endif; ?>
            <svg class="sb-chev <?= $cuti_adm_active?'open':'' ?>" width="13" height="13" viewBox="0 0 13 13" fill="none"><path d="M3 5L6.5 8.5L10 5" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <div class="sb-sub <?= $cuti_adm_active?'open':'' ?>" id="sb-cuti">
            <a href="cuti.php" class="sb-sub-a <?= ($current_page==='cuti.php'&&!isset($_GET['status']))?'on':'' ?>">Persetujuan<?php if($cuti_menunggu>0): ?> <span class="sb-badge" style="margin-left:auto;font-size:8px;padding:0 4px;min-width:14px;height:14px;"><?= $cuti_menunggu ?></span><?php endif; ?></a>
            <a href="cuti.php?status=disetujui" class="sb-sub-a <?= ($current_page==='cuti.php'&&($_GET['status']??'')==='disetujui')?'on':'' ?>">Disetujui</a>
            <a href="cuti.php?status=ditolak"   class="sb-sub-a <?= ($current_page==='cuti.php'&&($_GET['status']??'')==='ditolak')?'on':'' ?>">Ditolak</a>
        </div>

        <a href="hari_libur.php" class="sb-item <?= $current_page==='hari_libur.php'?'on':'' ?>">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="3" y="4" width="14" height="13" rx="2" stroke="currentColor" stroke-width="1.4"/><path d="M7 2v4M13 2v4M3 8h14" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/><path d="M7 12l2 2 4-4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            Hari Libur
        </a>

        <div class="sb-div"></div>
        <div class="sb-sec">Sistem</div>
        <a href="settings.php" class="sb-item <?= $current_page==='settings.php'?'on':'' ?>">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><circle cx="10" cy="10" r="2.5" stroke="currentColor" stroke-width="1.4"/><path d="M10 2v2M10 16v2M2 10h2M16 10h2M4.22 4.22l1.41 1.41M14.37 14.37l1.41 1.41M4.22 15.78l1.41-1.41M14.37 5.63l1.41-1.41" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            Pengaturan
        </a>
        <a href="qr_generator.php" class="sb-item <?= $current_page==='qr_generator.php'?'on':'' ?>">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><rect x="2" y="2" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="11" y="2" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="2" y="11" width="7" height="7" rx="1" stroke="currentColor" stroke-width="1.4"/><rect x="13" y="13" width="3" height="3" fill="currentColor"/><path d="M13 11h1.5M17 11v1.5M17 16h-1.5M11 16v-1.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg></span>
            Generator QR
        </a>

        <?php else: ?>
        <a href="../login.php" class="sb-item">
            <span class="sb-ico"><svg width="16" height="16" viewBox="0 0 20 20" fill="none"><path d="M7 3H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h3M13 15l4-5-4-5M17 10H7" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
            Login
        </a>
        <?php endif; ?>

    </nav>

    <!-- Footer -->
    <?php if (isset($_SESSION['nama'])): ?>
    <div class="sb-foot">
        <div class="sb-foot-inner">
            <?php if ($_sb_foto_url): ?>
            <img src="<?= htmlspecialchars($_sb_foto_url) ?>" class="sb-foot-av" style="object-fit:cover;padding:0;" alt="<?= mb_strtoupper(mb_substr($_SESSION['nama'],0,2)) ?>" onerror="this.outerHTML='<div class=\'sb-foot-av\'><?= mb_strtoupper(mb_substr($_SESSION['nama'],0,2)) ?></div>'">
            <?php else: ?>
            <div class="sb-foot-av"><?= mb_strtoupper(mb_substr($_SESSION['nama'],0,2)) ?></div>
            <?php endif; ?>
            <div style="flex:1;min-width:0;">
                <div class="sb-foot-name"><?= htmlspecialchars(mb_substr($_SESSION['nama'],0,16)) ?></div>
                <div class="sb-foot-role">
                    <?= ucfirst($_SESSION['role']??'') ?>
                    <?php if ($_sb_is_qr): ?>
                    <span class="sb-qr-indicator">
                        <svg width="9" height="9" viewBox="0 0 12 12" fill="none"><rect x=".5" y=".5" width="4" height="4" rx=".5" stroke="#D4AF37" stroke-width="1"/><rect x="7.5" y=".5" width="4" height="4" rx=".5" stroke="#D4AF37" stroke-width="1"/><rect x=".5" y="7.5" width="4" height="4" rx=".5" stroke="#D4AF37" stroke-width="1"/><rect x="1.5" y="1.5" width="2" height="2" fill="#D4AF37"/><rect x="8.5" y="1.5" width="2" height="2" fill="#D4AF37"/><rect x="1.5" y="8.5" width="2" height="2" fill="#D4AF37"/></svg>
                        QR
                    </span>
                    <?php else: ?>
                    <span class="sb-qr-indicator" style="background:rgba(99,102,241,.12);border-color:rgba(99,102,241,.25);color:#A5B4FC;">
                        <svg width="9" height="9" viewBox="0 0 12 12" fill="none">
                            <circle cx="6" cy="6" r="5" stroke="#A5B4FC" stroke-width="1"/>
                            <path d="M6 1c-1 1.5-1.8 3-1.8 5s.8 3.5 1.8 5" stroke="#A5B4FC" stroke-width="1" stroke-linecap="round"/>
                            <path d="M6 1c1 1.5 1.8 3 1.8 5S7 9.5 6 11" stroke="#A5B4FC" stroke-width="1" stroke-linecap="round"/>
                            <path d="M1 6h10" stroke="#A5B4FC" stroke-width="1" stroke-linecap="round"/>
                        </svg>
                        WEB
                    </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if (!$_sb_is_qr): ?>
        <a href="logout.php" style="
            display:flex;align-items:center;gap:7px;
            margin-top:9px;padding:7px 10px;
            background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.16);
            border-radius:7px;color:#F87171;font-size:12px;font-weight:600;
            text-decoration:none;width:100%;box-sizing:border-box;
            transition:background .15s;
        " onmouseover="this.style.background='rgba(220,38,38,.14)'" onmouseout="this.style.background='rgba(220,38,38,.07)'">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M7 3H4a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h3M13 15l4-5-4-5M17 10H7" stroke="#F87171" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Keluar dari Akun
        </a>
        <?php else: ?>
        <?php
        // Tombol kembali ke halaman absen QR (hanya untuk mode QR dashboard)
        $_sb_qr_url = $_sb_baseurl . '/qr.php';
        ?>
        <a href="<?= $_sb_qr_url ?>" style="
            display:flex;align-items:center;gap:7px;
            margin-top:9px;padding:7px 10px;
            background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.2);
            border-radius:7px;color:#D4AF37;font-size:12px;font-weight:600;
            text-decoration:none;width:100%;box-sizing:border-box;
            transition:background .15s;
        " onmouseover="this.style.background='rgba(212,175,55,.15)'" onmouseout="this.style.background='rgba(212,175,55,.08)'">
            <svg width="13" height="13" viewBox="0 0 20 20" fill="none"><path d="M2 10h16M2 10l6-6M2 10l6 6" stroke="#D4AF37" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            Kembali ke Halaman Absen
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<div id="sbOverlay" onclick="toggleSidebar()"></div>

<script>
function toggleSidebar() {
    var sb = document.getElementById('sidebarMenu');
    var ov = document.getElementById('sbOverlay');
    sb.classList.toggle('show');
    ov.classList.toggle('show');
    document.body.style.overflow = sb.classList.contains('show') ? 'hidden' : '';
}
function sbToggle(id, btn) {
    var sub  = document.getElementById(id);
    var chev = btn.querySelector('.sb-chev');
    var open = sub.classList.contains('open');
    document.querySelectorAll('.sb-sub.open').forEach(function(el) {
        if (el.id !== id) {
            el.classList.remove('open');
            var pb = el.previousElementSibling;
            if (pb) { var c = pb.querySelector('.sb-chev'); if(c) c.classList.remove('open'); }
        }
    });
    sub.classList.toggle('open', !open);
    if (chev) chev.classList.toggle('open', !open);
}
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        var sb = document.getElementById('sidebarMenu');
        if (sb && sb.classList.contains('show')) toggleSidebar();
    }
});
</script>