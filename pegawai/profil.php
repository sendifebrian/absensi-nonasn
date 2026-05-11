<?php
require_once __DIR__ . '/../config/init.php';
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_pegawai()) { header('Location: dashboard.php'); exit(); }

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$setup_wfh = isset($_GET['setup_wfh']) && $_GET['setup_wfh'] == 1;

// ── Simpan koordinat ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['simpan_koordinat'])) {
    csrf_verify();
    $lat = trim($_POST['lat_rumah'] ?? '');
    $lng = trim($_POST['lng_rumah'] ?? '');
    if ($lat && $lng) {
        if (!preg_match('/^-?\d{1,2}(\.\d+)?$/', $lat) || !preg_match('/^-?\d{1,3}(\.\d+)?$/', $lng)) {
            $flash = ['type'=>'err','msg'=>'Format koordinat tidak valid.'];
        } elseif ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            $flash = ['type'=>'err','msg'=>'Koordinat di luar batas valid.'];
        } else {
            $pdo->prepare("UPDATE users SET lat_rumah=?, lng_rumah=? WHERE id=?")->execute([$lat, $lng, $_SESSION['user_id']]);
            if (isset($_SESSION['pending_absen_type'])) {
                $type = $_SESSION['pending_absen_type'];
                unset($_SESSION['pending_absen_type']);
                header("Location: absensi.php?type=$type&success=koordinat_diset"); exit();
            }
            header("Location: profil.php?success=koordinat"); exit();
        }
    } else {
        $flash = ['type'=>'err','msg'=>'Koordinat tidak boleh kosong.'];
    }
}

// ── Upload foto (base64 dari crop) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['foto_cropped'])) {
    csrf_verify();
    $dataUrl = $_POST['foto_cropped'];
    if (preg_match('/^data:image\/(jpeg|png|jpg);base64,/', $dataUrl, $m)) {
        $imgData = base64_decode(preg_replace('/^data:image\/\w+;base64,/', '', $dataUrl));
        $ext      = $m[1] === 'png' ? 'png' : 'jpg';
        $filename = 'profil_'.$_SESSION['user_id'].'_'.time().'.'.$ext;
        $target   = '../uploads/selfie/'.$filename;
        if (file_put_contents($target, $imgData)) {
            $pdo->prepare("UPDATE users SET foto=? WHERE id=?")->execute([$filename, $_SESSION['user_id']]);
            header("Location: profil.php?success=foto"); exit();
        }
        $flash = ['type'=>'err','msg'=>'Gagal menyimpan foto.'];
    }
}

// ── Edit data diri ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_profil'])) {
    csrf_verify();
    $nik          = trim($_POST['nik'] ?? '');
    $no_hp        = trim($_POST['no_hp'] ?? '');
    $tempat_lahir = trim($_POST['tempat_lahir'] ?? '');
    $tgl_lahir    = $_POST['tgl_lahir'] ?? '';
    $unit_kerja   = trim($_POST['unit_kerja'] ?? '');

    $pdo->prepare("UPDATE users SET nik=?, no_hp=?, tempat_lahir=?, tgl_lahir=?, unit_kerja=? WHERE id=?")
        ->execute([$nik ?: null, $no_hp ?: null, $tempat_lahir ?: null, $tgl_lahir ?: null, $unit_kerja ?: null, $_SESSION['user_id']]);
    header("Location: profil.php?success=profil"); exit();
}

// ── Ganti password ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ganti_password'])) {
    csrf_verify();
    $new     = $_POST['new'] ?? '';
    $confirm = $_POST['confirm'] ?? '';
    if (strlen($new) < 6) {
        $flash = ['type'=>'err','msg'=>'Password minimal 6 karakter.'];
    } elseif ($new !== $confirm) {
        $flash = ['type'=>'err','msg'=>'Konfirmasi password tidak cocok.'];
    } else {
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        header("Location: profil.php?success=password"); exit();
    }
}

// ── Re-fetch user setelah edit ──
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

$successMap = [
    'foto'      => 'Foto profil berhasil diperbarui!',
    'password'  => 'Password berhasil diubah!',
    'koordinat' => 'Koordinat rumah berhasil disimpan!',
    'profil'    => 'Data diri berhasil diperbarui!',
    'koordinat_diset' => 'Koordinat rumah diset! Silakan lanjutkan absensi.',
];
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>

<style>
:root {
    --navy:      #0A1628;
    --navy-mid:  #0F2241;
    --navy-lit:  #1A3560;
    --gold:      #C9A84C;
    --gold-lit:  #E8C97A;
    --gold-pale: #F5E9C8;
    --gold-bg:   #FEFAEF;
    --silver:    #8A9BBE;
    --ash:       #CBD5E8;
    --white:     #FFFFFF;
    --surface:   #F6F8FE;
    --surf2:     #EEF2FA;
    --txt:       #0A1628;
    --muted:     #64748B;
    --border:    #E2E8F4;
    --ok:        #059669;
    --ok-bg:     #ECFDF5;
    --err:       #DC2626;
    --err-bg:    #FEF2F2;
    --warn:      #D97706;
    --warn-bg:   #FFFBEB;
    --r:         14px;
    --r-sm:      8px;
    --sh:        0 1px 3px rgba(10,22,40,.05);
    --sh2:       0 4px 16px rgba(10,22,40,.08);
    --sh3:       0 12px 40px rgba(10,22,40,.12);
}
body { font-family:'DM Sans',sans-serif; background:var(--surface); color:var(--txt); }
.dpg { padding:1.5rem 1.5rem 3rem; max-width:1100px; margin:0 auto; }

/* ── Hero header ── */
.hero {
    background:linear-gradient(135deg,var(--navy) 0%,var(--navy-mid) 55%,var(--navy-lit) 100%);
    border-radius:22px; padding:1.75rem 2rem;
    margin-bottom:1.25rem; position:relative; overflow:hidden;
    box-shadow:0 16px 48px rgba(10,22,40,.22);
}
.hero::before { content:''; position:absolute; top:-80px; right:-80px; width:260px; height:260px; border-radius:50%; border:1px solid rgba(201,168,76,.1); pointer-events:none; }
.hero::after  { content:''; position:absolute; bottom:0; left:0; right:0; height:2px; background:linear-gradient(90deg,transparent,var(--gold) 35%,var(--gold-lit) 65%,transparent); }
.hero-dots { position:absolute; top:18px; right:120px; width:90px; height:70px; background-image:radial-gradient(circle,rgba(201,168,76,.38) 1.5px,transparent 1.5px); background-size:15px 15px; opacity:.25; pointer-events:none; }
.hero-eyebrow { font-size:.63rem; font-weight:700; letter-spacing:.2em; text-transform:uppercase; color:var(--gold); margin-bottom:.3rem; }
.hero-title { font-family:'Cormorant Garamond',serif; font-size:clamp(1.5rem,4vw,2rem); font-weight:700; color:var(--white); margin-bottom:.3rem; }
.hero-sub { font-size:.76rem; color:var(--silver); }

/* ── Panels ── */
.panel { background:var(--white); border:1px solid var(--border); border-radius:var(--r); box-shadow:var(--sh); overflow:hidden; margin-bottom:1.25rem; }
.panel-head {
    display:flex; align-items:center; justify-content:space-between;
    padding:.9rem 1.35rem; border-bottom:1px solid var(--border);
    background:linear-gradient(90deg,#FAFCFF,var(--white));
}
.panel-title { display:flex; align-items:center; gap:.5rem; font-size:.84rem; font-weight:700; color:var(--navy); }
.panel-title-ico { width:26px; height:26px; border-radius:7px; background:var(--gold-bg); display:flex; align-items:center; justify-content:center; }
.panel-title-ico svg { width:13px; height:13px; fill:var(--gold); }
.panel-body { padding:1.35rem; }

/* ── Avatar section ── */
.avatar-zone { display:flex; flex-direction:column; align-items:center; padding:1.5rem 1.35rem 1.25rem; }
.avatar-ring {
    width:110px; height:110px; border-radius:50%;
    border:3px solid var(--gold);
    box-shadow:0 0 0 4px rgba(201,168,76,.15), var(--sh2);
    overflow:hidden; position:relative;
    background:var(--surf2); cursor:pointer;
    transition:all .25s;
    flex-shrink:0;
}
.avatar-ring:hover { box-shadow:0 0 0 6px rgba(201,168,76,.2), var(--sh3); }
.avatar-ring img { width:100%; height:100%; object-fit:cover; }
.avatar-ini {
    width:100%; height:100%;
    display:flex; align-items:center; justify-content:center;
    font-family:'Cormorant Garamond',serif;
    font-size:2.5rem; font-weight:700;
    background:linear-gradient(135deg,var(--navy),var(--navy-lit));
    color:var(--gold);
}
.avatar-edit-badge {
    position:absolute; bottom:6px; right:6px;
    width:28px; height:28px; border-radius:50%;
    background:var(--gold); border:2px solid var(--white);
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 2px 8px rgba(0,0,0,.2);
}
.avatar-edit-badge svg { width:13px; height:13px; fill:var(--navy); }
.avatar-name { font-family:'Cormorant Garamond',serif; font-size:1.25rem; font-weight:700; color:var(--navy); margin-top:.85rem; text-align:center; }
.avatar-role { font-size:.76rem; color:var(--muted); margin-top:.2rem; text-align:center; }
.avatar-status { margin-top:.65rem; }
.status-dot-ok  { display:inline-flex; align-items:center; gap:.35rem; font-size:.72rem; font-weight:600; color:var(--ok); background:var(--ok-bg); border:1px solid rgba(5,150,105,.2); padding:.25rem .7rem; border-radius:99px; }
.status-dot-ok::before { content:''; width:6px; height:6px; border-radius:50%; background:var(--ok); }

/* ── Upload trigger ── */
.upload-trigger {
    width:100%; margin-top:.85rem;
    display:flex; align-items:center; justify-content:center; gap:.5rem;
    padding:.6rem 1rem;
    border:1.5px dashed rgba(201,168,76,.4);
    border-radius:var(--r-sm);
    background:var(--gold-bg); color:var(--navy);
    font-size:.78rem; font-weight:600; cursor:pointer;
    transition:all .2s;
}
.upload-trigger svg { width:15px; height:15px; fill:var(--gold); }
.upload-trigger:hover { border-color:var(--gold); background:var(--gold-pale); }

/* ── Info list ── */
.info-list { display:flex; flex-direction:column; gap:.5rem; }
.info-row {
    display:flex; align-items:flex-start; gap:.75rem;
    padding:.7rem .85rem; background:var(--surface);
    border-radius:var(--r-sm); border-left:2.5px solid var(--ash);
    transition:border-color .15s;
}
.info-row:hover { border-left-color:var(--gold); }
.info-ico { width:30px; height:30px; border-radius:8px; background:var(--surf2); display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.info-ico svg { width:14px; height:14px; fill:var(--silver); }
.info-lbl { font-size:.65rem; font-weight:600; text-transform:uppercase; letter-spacing:.09em; color:var(--silver); margin-bottom:.15rem; }
.info-val { font-size:.84rem; font-weight:600; color:var(--navy); word-break:break-word; }
.info-val.empty { color:var(--ash); font-weight:400; font-style:italic; }

/* ── Edit button ── */
.btn-edit-row {
    display:inline-flex; align-items:center; gap:.35rem;
    padding:.5rem 1rem; font-size:.78rem; font-weight:600;
    background:var(--gold-bg); color:var(--navy);
    border:1px solid rgba(201,168,76,.3); border-radius:var(--r-sm);
    cursor:pointer; transition:all .2s; text-decoration:none;
}
.btn-edit-row svg { width:13px; height:13px; fill:var(--gold); }
.btn-edit-row:hover { background:var(--gold-pale); border-color:var(--gold); color:var(--navy); }

/* ── Form fields ── */
.field-group { margin-bottom:1rem; }
.field-label { display:block; font-size:.76rem; font-weight:600; color:var(--navy); margin-bottom:.4rem; letter-spacing:.02em; }
.field-input {
    width:100%; padding:.65rem .85rem;
    border:1.5px solid var(--border); border-radius:var(--r-sm);
    font-family:'DM Sans',sans-serif; font-size:.87rem; color:var(--txt);
    background:var(--surface); outline:none;
    transition:border-color .2s, background .2s, box-shadow .2s;
}
.field-input:focus { border-color:var(--gold); background:var(--white); box-shadow:0 0 0 3px rgba(201,168,76,.1); }
.field-row { display:grid; grid-template-columns:1fr 1fr; gap:.75rem; }

/* ── Buttons ── */
.btn-navy-solid {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.7rem 1.35rem;
    background:linear-gradient(135deg,var(--navy),var(--navy-lit));
    color:var(--white); border:none; border-radius:var(--r-sm);
    font-family:'DM Sans',sans-serif; font-size:.84rem; font-weight:600;
    cursor:pointer; transition:all .2s; box-shadow:0 4px 12px rgba(10,22,40,.2);
}
.btn-navy-solid svg { width:15px; height:15px; fill:var(--white); }
.btn-navy-solid:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(10,22,40,.28); }
.btn-gold-solid {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.7rem 1.35rem;
    background:linear-gradient(135deg,var(--gold),var(--gold-lit));
    color:var(--navy); border:none; border-radius:var(--r-sm);
    font-family:'DM Sans',sans-serif; font-size:.84rem; font-weight:700;
    cursor:pointer; transition:all .2s; box-shadow:0 4px 12px rgba(201,168,76,.3);
}
.btn-gold-solid svg { width:15px; height:15px; fill:var(--navy); }
.btn-gold-solid:hover { transform:translateY(-1px); box-shadow:0 6px 20px rgba(201,168,76,.4); }
.btn-ghost {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.65rem 1rem;
    background:transparent; color:var(--muted);
    border:1px solid var(--border); border-radius:var(--r-sm);
    font-family:'DM Sans',sans-serif; font-size:.82rem; font-weight:500;
    cursor:pointer; transition:all .2s;
}
.btn-ghost:hover { background:var(--surf2); color:var(--navy); }

/* ── Flash alerts ── */
.flash {
    display:flex; align-items:center; gap:.75rem;
    padding:.85rem 1.1rem; border-radius:var(--r-sm);
    font-size:.83rem; margin-bottom:1rem; animation:fadeUp .4s ease;
}
.flash.ok  { background:var(--ok-bg);   color:#065F46; border:1px solid rgba(5,150,105,.25); border-left:3px solid var(--ok); }
.flash.err { background:var(--err-bg);  color:#991B1B; border:1px solid rgba(220,38,38,.2);  border-left:3px solid var(--err); }
.flash.wrn { background:var(--warn-bg); color:#92400E; border:1px solid rgba(217,119,6,.2);  border-left:3px solid var(--warn); }
.flash svg { width:16px; height:16px; fill:currentColor; flex-shrink:0; }
.flash-close { margin-left:auto; background:none; border:none; cursor:pointer; color:currentColor; opacity:.6; padding:0; }

/* ── Password strength ── */
.pw-match { font-size:.74rem; font-weight:600; margin-top:.35rem; }
.pw-match.ok  { color:var(--ok); }
.pw-match.err { color:var(--err); }

/* ── Map ── */
#mapKoord { height:220px; border-radius:var(--r-sm); border:1px solid var(--border); }
.coord-inputs { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; margin-top:.65rem; }
.coord-note { display:flex; align-items:flex-start; gap:.4rem; font-size:.74rem; color:var(--muted); margin-top:.75rem; line-height:1.5; }
.coord-note svg { width:13px; height:13px; fill:var(--silver); flex-shrink:0; margin-top:.1rem; }

/* ── Grid layout ── */
.pg-grid { display:grid; grid-template-columns:320px 1fr; gap:1.25rem; align-items:start; }
@media(max-width:900px) { .pg-grid { grid-template-columns:1fr; } }

/* ── Photo crop modal ── */
.crop-overlay {
    position:fixed; inset:0; z-index:9999;
    background:rgba(10,22,40,.88); backdrop-filter:blur(8px);
    display:flex; align-items:center; justify-content:center;
    padding:1rem;
    opacity:0; pointer-events:none; transition:opacity .3s;
}
.crop-overlay.open { opacity:1; pointer-events:all; }
.crop-modal {
    background:var(--white); border-radius:22px;
    width:100%; max-width:440px;
    box-shadow:0 32px 80px rgba(0,0,0,.45), 0 0 0 1px rgba(201,168,76,.15);
    overflow:hidden;
    transform:scale(.94) translateY(16px); transition:transform .35s cubic-bezier(.22,1,.36,1);
}
.crop-overlay.open .crop-modal { transform:scale(1) translateY(0); }
.crop-modal-head {
    display:flex; align-items:center; justify-content:space-between;
    padding:1.1rem 1.35rem; border-bottom:1px solid var(--border);
}
.crop-modal-title { font-family:'Cormorant Garamond',serif; font-size:1.15rem; font-weight:700; color:var(--navy); }
.crop-close { background:none; border:none; cursor:pointer; color:var(--muted); padding:4px; border-radius:6px; transition:background .15s; }
.crop-close:hover { background:var(--surf2); }
.crop-close svg { width:18px; height:18px; fill:currentColor; }
.crop-canvas-wrap {
    position:relative; overflow:hidden;
    background:#111; height:320px;
    display:flex; align-items:center; justify-content:center;
}
.crop-canvas-wrap canvas { max-width:100%; max-height:100%; }
/* Circular mask overlay */
.crop-canvas-wrap::after {
    content:'';
    position:absolute; inset:0;
    background:
        radial-gradient(circle 130px at 50% 50%, transparent 130px, rgba(0,0,0,.58) 130px);
    pointer-events:none;
}
.crop-circle-guide {
    position:absolute; top:50%; left:50%;
    transform:translate(-50%,-50%);
    width:260px; height:260px; border-radius:50%;
    border:2px solid rgba(201,168,76,.8);
    pointer-events:none; z-index:2;
    box-shadow:0 0 0 9999px rgba(0,0,0,.55);
}
.crop-controls {
    padding:.9rem 1.35rem; border-top:1px solid var(--border);
    background:var(--surface);
}
.crop-hint { font-size:.74rem; color:var(--muted); text-align:center; margin-bottom:.65rem; }
.crop-zoom { width:100%; accent-color:var(--gold); cursor:pointer; }
.crop-footer {
    display:flex; gap:.5rem; padding:1rem 1.35rem;
    border-top:1px solid var(--border);
    justify-content:flex-end;
}

/* ── Animations ── */
@keyframes fadeUp { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:translateY(0)} }
.f1 { animation:fadeUp .42s ease both; }
.f2 { animation:fadeUp .42s .07s ease both; }
.f3 { animation:fadeUp .42s .14s ease both; }

/* ── Responsive ── */
@media(max-width:768px) {
    .dpg { padding:1rem .9rem 2.5rem; }
    .hero { padding:1.35rem 1.25rem; border-radius:16px; }
    .panel-body { padding:1rem; }
    .field-row { grid-template-columns:1fr; }
    .crop-canvas-wrap { height:260px; }
    .crop-circle-guide { width:200px; height:200px; }
    .crop-canvas-wrap::after { background:radial-gradient(circle 100px at 50% 50%,transparent 100px,rgba(0,0,0,.58) 100px); }
}
@media(max-width:480px) {
    .field-row { grid-template-columns:1fr; }
    .crop-modal { border-radius:16px; }
    .crop-canvas-wrap { height:220px; }
    .crop-circle-guide { width:170px; height:170px; }
    .crop-canvas-wrap::after { background:radial-gradient(circle 85px at 50% 50%,transparent 85px,rgba(0,0,0,.58) 85px); }
}
</style>

<div class="main-content">
<div class="dpg">

    <!-- Hero -->
    <div class="hero f1">
        <div class="hero-dots"></div>
        <div class="hero-eyebrow">Akun Pegawai</div>
        <h1 class="hero-title">Profil Saya</h1>
        <p class="hero-sub">Kelola informasi pribadi, foto, koordinat WFH, dan keamanan akun</p>
    </div>

    <!-- Flash messages -->
    <?php if (isset($_GET['success']) && isset($successMap[$_GET['success']])): ?>
    <div class="flash ok f1">
        <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
        <?= $successMap[$_GET['success']] ?>
        <button class="flash-close" onclick="this.parentElement.remove()">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <?php endif; ?>

    <?php if (isset($flash)): ?>
    <div class="flash <?= $flash['type'] ?> f1">
        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
        <?= htmlspecialchars($flash['msg']) ?>
        <button class="flash-close" onclick="this.parentElement.remove()">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <?php endif; ?>

    <?php if ($setup_wfh): ?>
    <div class="flash wrn f1">
        <svg viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>
        <div><strong>Koordinat Rumah Belum Diatur.</strong> Atur lokasi rumah Anda untuk absensi WFH.</div>
        <button class="flash-close" onclick="this.parentElement.remove()">
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <?php endif; ?>

    <!-- Main grid -->
    <div class="pg-grid">

        <!-- LEFT COLUMN -->
        <div>

            <!-- Avatar & Photo -->
            <div class="panel f2">
                <div class="avatar-zone">
                    <div class="avatar-ring" onclick="document.getElementById('fotoFileInput').click()" title="Klik untuk ganti foto">
                        <?php if (!empty($user['foto'])): ?>
                        <img src="<?= htmlspecialchars(foto_url($user['foto'])) ?>" alt="Foto Profil" id="currentAvatar">
                        <?php else: ?>
                        <div class="avatar-ini" id="currentAvatarIni"><?= strtoupper(substr($user['nama'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <div class="avatar-edit-badge">
                            <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                        </div>
                    </div>
                    <div class="avatar-name"><?= htmlspecialchars($user['nama']) ?></div>
                    <div class="avatar-role"><?= htmlspecialchars($user['jabatan'] ?? 'Pegawai') ?></div>
                    <div class="avatar-status">
                        <span class="status-dot-ok">Akun Aktif</span>
                    </div>
                    <label class="upload-trigger" for="fotoFileInput">
                        <svg viewBox="0 0 24 24"><path d="M9 16h6v-6h4l-7-7-7 7h4zm-4 2h14v2H5z"/></svg>
                        Ganti Foto Profil
                    </label>
                    <input type="file" id="fotoFileInput" accept="image/*" style="display:none">
                </div>
            </div>

            <!-- Koordinat WFH -->
            <div class="panel f2" id="sectionKoordinat">
                <div class="panel-head">
                    <div class="panel-title">
                        <div class="panel-title-ico">
                            <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z"/></svg>
                        </div>
                        Koordinat Rumah (WFH)
                    </div>
                    <?php if ($user['lat_rumah'] && $user['lng_rumah']): ?>
                    <span style="font-size:.68rem;font-weight:600;color:var(--ok);background:var(--ok-bg);border:1px solid rgba(5,150,105,.2);padding:3px 9px;border-radius:99px;">Sudah Set</span>
                    <?php else: ?>
                    <span style="font-size:.68rem;font-weight:600;color:var(--warn);background:var(--warn-bg);border:1px solid rgba(217,119,6,.2);padding:3px 9px;border-radius:99px;">Belum Set</span>
                    <?php endif; ?>
                </div>
                <div class="panel-body">
                    <div id="mapKoord"></div>
                    <div class="coord-inputs">
                        <input type="text" id="latInput" class="field-input" placeholder="Latitude" value="<?= htmlspecialchars($user['lat_rumah'] ?? '') ?>" oninput="updateMarkerFromInput()">
                        <input type="text" id="lngInput" class="field-input" placeholder="Longitude" value="<?= htmlspecialchars($user['lng_rumah'] ?? '') ?>" oninput="updateMarkerFromInput()">
                    </div>
                    <div style="display:flex;gap:.5rem;margin-top:.65rem;flex-wrap:wrap">
                        <button type="button" onclick="ambilGPS()" style="flex:1;min-width:120px" class="btn-ghost">
                            <svg viewBox="0 0 24 24" width="14" height="14" fill="#8A9BBE"><path d="M12 8c-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm8.94 3A8.994 8.994 0 0 0 13 3.06V1h-2v2.06A8.994 8.994 0 0 0 3.06 11H1v2h2.06A8.994 8.994 0 0 0 11 20.94V23h2v-2.06A8.994 8.994 0 0 0 20.94 13H23v-2h-2.06zM12 19c-3.87 0-7-3.13-7-7s3.13-7 7-7 7 3.13 7 7-3.13 7-7 7z"/></svg>
                            Ambil GPS
                        </button>
                        <button type="button" onclick="simpanKoordinat()" style="flex:1;min-width:120px" class="btn-gold-solid">
                            <svg viewBox="0 0 24 24"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg>
                            Simpan
                        </button>
                    </div>
                    <div class="coord-note">
                        <svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        Klik peta atau geser pin untuk memilih lokasi. Radius validasi WFH: <strong>100m</strong>.
                    </div>
                </div>
            </div>

        </div>

        <!-- RIGHT COLUMN -->
        <div>

            <!-- Data Diri — view mode -->
            <div class="panel f2" id="panelView">
                <div class="panel-head">
                    <div class="panel-title">
                        <div class="panel-title-ico">
                            <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
                        </div>
                        Data Diri
                    </div>
                    <button class="btn-edit-row" onclick="toggleEdit(true)">
                        <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                        Edit
                    </button>
                </div>
                <div class="panel-body">
                    <div class="info-list">
                        <?php
                        $infoFields = [
                            ['icon'=>'M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z', 'lbl'=>'Username', 'val'=>$user['username']],
                            ['icon'=>'M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z', 'lbl'=>'Email', 'val'=>$user['email']],
                            ['icon'=>'M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm-1 1.5L18.5 9H13V3.5zM6 20V4h5v7h7v9H6z', 'lbl'=>'NIK', 'val'=>$user['nik']],
                            ['icon'=>'M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z', 'lbl'=>'No. HP', 'val'=>$user['no_hp']],
                            ['icon'=>'M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z', 'lbl'=>'Tempat Lahir', 'val'=>$user['tempat_lahir']],
                            ['icon'=>'M19 4h-1V2h-2v2H8V2H6v2H5C3.9 4 3 4.9 3 6v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11zm-7-7h-2v-2h2v2zm0 4h-2v-2h2v2zm4-4h-2v-2h2v2zm0 4h-2v-2h2v2zm-8 0h-2v-2h2v2zm0-4h-2v-2h2v2z', 'lbl'=>'Tanggal Lahir', 'val'=>$user['tgl_lahir'] ? date('d F Y', strtotime($user['tgl_lahir'])) : null],
                            ['icon'=>'M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h2v-2h-2v-2h2v-2h-2V9h8v10zm-2-8h-2v2h2v-2zm0 4h-2v2h2v-2z', 'lbl'=>'Unit Kerja', 'val'=>$user['unit_kerja']],
                        ];
                        foreach($infoFields as $f):
                        ?>
                        <div class="info-row">
                            <div class="info-ico">
                                <svg viewBox="0 0 24 24"><path d="<?= $f['icon'] ?>"/></svg>
                            </div>
                            <div>
                                <div class="info-lbl"><?= $f['lbl'] ?></div>
                                <div class="info-val <?= empty($f['val']) ? 'empty' : '' ?>">
                                    <?= $f['val'] ? htmlspecialchars($f['val']) : 'Belum diisi' ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Data Diri — edit mode -->
            <div class="panel f2" id="panelEdit" style="display:none">
                <div class="panel-head">
                    <div class="panel-title">
                        <div class="panel-title-ico">
                            <svg viewBox="0 0 24 24"><path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"/></svg>
                        </div>
                        Edit Data Diri
                    </div>
                    <button class="btn-ghost" onclick="toggleEdit(false)" style="font-size:.76rem;padding:.4rem .75rem">
                        Batal
                    </button>
                </div>
                <div class="panel-body">
                    <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="edit_profil" value="1">
                        <div class="field-row">
                            <div class="field-group">
                                <label class="field-label">NIK</label>
                                <input type="text" name="nik" class="field-input" placeholder="Nomor Induk Karyawan" value="<?= htmlspecialchars($user['nik'] ?? '') ?>" maxlength="20">
                            </div>
                            <div class="field-group">
                                <label class="field-label">No. HP / WhatsApp</label>
                                <input type="tel" name="no_hp" class="field-input" placeholder="08xx-xxxx-xxxx" value="<?= htmlspecialchars($user['no_hp'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="field-row">
                            <div class="field-group">
                                <label class="field-label">Tempat Lahir</label>
                                <input type="text" name="tempat_lahir" class="field-input" placeholder="Kota/Kabupaten" value="<?= htmlspecialchars($user['tempat_lahir'] ?? '') ?>">
                            </div>
                            <div class="field-group">
                                <label class="field-label">Tanggal Lahir</label>
                                <input type="date" name="tgl_lahir" class="field-input" value="<?= htmlspecialchars($user['tgl_lahir'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="field-group">
                            <label class="field-label">Unit Kerja</label>
                            <input type="text" name="unit_kerja" class="field-input" placeholder="Bagian/Seksi/Sub-bidang" value="<?= htmlspecialchars($user['unit_kerja'] ?? '') ?>">
                        </div>
                        <div style="display:flex;gap:.6rem;justify-content:flex-end;margin-top:.25rem">
                            <button type="button" class="btn-ghost" onclick="toggleEdit(false)">Batal</button>
                            <button type="submit" class="btn-navy-solid">
                                <svg viewBox="0 0 24 24"><path d="M17 3H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V7l-4-4zm-5 16c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm3-10H5V5h10v4z"/></svg>
                                Simpan Perubahan
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Ganti Password -->
            <div class="panel f3">
                <div class="panel-head">
                    <div class="panel-title">
                        <div class="panel-title-ico">
                            <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                        </div>
                        Keamanan Akun
                    </div>
                </div>
                <div class="panel-body">
                    <div style="display:flex;align-items:center;gap:.5rem;background:var(--info-bg,#EFF6FF);border:1px solid #BFDBFE;border-radius:var(--r-sm);padding:.7rem .9rem;font-size:.78rem;color:#1E40AF;margin-bottom:1rem">
                        <svg viewBox="0 0 24 24" width="14" height="14" fill="#2563EB"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        Ganti password tanpa perlu memasukkan password lama
                    </div>
                    <form method="POST">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="ganti_password" value="1">
                        <div class="field-group">
                            <label class="field-label">Password Baru</label>
                            <div style="position:relative">
                                <input type="password" name="new" id="pwNew" class="field-input" placeholder="Minimal 6 karakter" required minlength="6" style="padding-right:2.5rem">
                                <button type="button" onclick="togglePw('pwNew',this)" style="position:absolute;right:.6rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--silver);padding:2px">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" id="eyeNew"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                </button>
                            </div>
                        </div>
                        <div class="field-group">
                            <label class="field-label">Konfirmasi Password</label>
                            <div style="position:relative">
                                <input type="password" name="confirm" id="pwConf" class="field-input" placeholder="Ketik ulang password" required style="padding-right:2.5rem" oninput="cekPw()">
                                <button type="button" onclick="togglePw('pwConf',this)" style="position:absolute;right:.6rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--silver);padding:2px">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" id="eyeConf"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                                </button>
                            </div>
                            <div class="pw-match" id="pwMatch"></div>
                        </div>
                        <button type="submit" class="btn-navy-solid" style="width:100%">
                            <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                            Simpan Password Baru
                        </button>
                    </form>
                </div>
            </div>

        </div>
    </div>
</div>
</div>

<!-- ═══ PHOTO CROP MODAL ═══ -->
<div class="crop-overlay" id="cropOverlay">
    <div class="crop-modal">
        <div class="crop-modal-head">
            <div class="crop-modal-title">Atur Foto Profil</div>
            <button class="crop-close" onclick="closeCrop()">
                <svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>
            </button>
        </div>
        <div class="crop-canvas-wrap">
            <canvas id="cropCanvas"></canvas>
            <div class="crop-circle-guide"></div>
        </div>
        <div class="crop-controls">
            <div class="crop-hint">Geser gambar · Cubit/scroll untuk zoom · Lingkaran = area foto profil</div>
            <input type="range" class="crop-zoom" id="zoomRange" min="0.5" max="3" step="0.01" value="1">
        </div>
        <div class="crop-footer">
            <button class="btn-ghost" onclick="closeCrop()">Batal</button>
            <button class="btn-navy-solid" onclick="saveCrop()">
                <svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>
                Gunakan Foto Ini
            </button>
        </div>
    </div>
</div>

<!-- Hidden form for photo upload -->
<form id="photoForm" method="POST" style="display:none">
    <?php csrf_field(); ?>
    <input type="hidden" name="foto_cropped" id="fotoCroppedInput">
</form>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
/* ═══════════════════════════════════
   EDIT TOGGLE
═══════════════════════════════════ */
function toggleEdit(show) {
    document.getElementById('panelView').style.display = show ? 'none' : 'block';
    document.getElementById('panelEdit').style.display = show ? 'block' : 'none';
    if (show) document.getElementById('panelEdit').scrollIntoView({behavior:'smooth',block:'nearest'});
}

/* ═══════════════════════════════════
   PASSWORD
═══════════════════════════════════ */
const eyeOpen  = `<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>`;
const eyeClose = `<path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46A11.804 11.804 0 0 0 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>`;

function togglePw(id, btn) {
    const inp = document.getElementById(id);
    const isPass = inp.type === 'password';
    inp.type = isPass ? 'text' : 'password';
    btn.querySelector('svg').innerHTML = isPass ? eyeClose : eyeOpen;
}

function cekPw() {
    const n = document.getElementById('pwNew').value;
    const c = document.getElementById('pwConf').value;
    const el = document.getElementById('pwMatch');
    if (!c) { el.textContent=''; return; }
    if (n === c) { el.textContent='✓ Password cocok'; el.className='pw-match ok'; }
    else         { el.textContent='✗ Password tidak cocok'; el.className='pw-match err'; }
}

document.getElementById('pwNew')?.addEventListener('input', cekPw);

/* ═══════════════════════════════════
   PHOTO CROP ENGINE
═══════════════════════════════════ */
let cropImg = null, canvas, ctx;
let scale = 1, minScale = 0.5;
let ox = 0, oy = 0;          // image origin (top-left of image on canvas)
let dragging = false, lastX, lastY;
let CROP_R;                   // circle radius in canvas px

function openCrop(src) {
    const overlay = document.getElementById('cropOverlay');
    canvas = document.getElementById('cropCanvas');
    ctx    = canvas.getContext('2d');
    overlay.classList.add('open');

    cropImg = new Image();
    cropImg.onload = () => {
        // Canvas size = crop wrap inner size
        const wrap = canvas.parentElement;
        canvas.width  = wrap.clientWidth  || 400;
        canvas.height = wrap.clientHeight || 320;
        CROP_R = Math.min(canvas.width, canvas.height) * 0.44;

        // Fit image to fill circle at 1x
        const imgAspect = cropImg.width / cropImg.height;
        const fitW = CROP_R * 2 * 1.05;
        const fitH = fitW / imgAspect;
        scale = fitW / cropImg.width;
        minScale = scale * 0.6;
        document.getElementById('zoomRange').value = scale;
        document.getElementById('zoomRange').min   = minScale;
        document.getElementById('zoomRange').max   = scale * 4;

        // Center image
        ox = canvas.width/2  - (cropImg.width * scale)/2;
        oy = canvas.height/2 - (cropImg.height * scale)/2;
        drawCrop();
    };
    cropImg.src = src;
}

function drawCrop() {
    if (!ctx || !cropImg) return;
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    ctx.drawImage(cropImg, ox, oy, cropImg.width * scale, cropImg.height * scale);
}

function closeCrop() { document.getElementById('cropOverlay').classList.remove('open'); }

function saveCrop() {
    // Render only the circle area to a square output canvas
    const out  = document.createElement('canvas');
    const size = 400;
    out.width  = out.height = size;
    const oc   = out.getContext('2d');
    // Clip to circle
    oc.beginPath();
    oc.arc(size/2, size/2, size/2, 0, Math.PI*2);
    oc.clip();
    // Draw source — map circle center on source canvas to center of output
    const cx = canvas.width/2, cy = canvas.height/2;
    const sx = cx - CROP_R, sy = cy - CROP_R;
    oc.drawImage(canvas, sx, sy, CROP_R*2, CROP_R*2, 0, 0, size, size);

    const dataUrl = out.toDataURL('image/jpeg', 0.88);
    document.getElementById('fotoCroppedInput').value = dataUrl;

    // Preview avatar immediately
    const av = document.getElementById('currentAvatar');
    const avI = document.getElementById('currentAvatarIni');
    if (av) { av.src = dataUrl; }
    else if (avI) {
        const img = document.createElement('img');
        img.src = dataUrl; img.id = 'currentAvatar';
        img.style.cssText = 'width:100%;height:100%;object-fit:cover';
        avI.parentElement.replaceChild(img, avI);
    }

    closeCrop();
    document.getElementById('photoForm').submit();
}

// Canvas mouse/touch events
document.addEventListener('DOMContentLoaded', () => {
    canvas = document.getElementById('cropCanvas');

    canvas.addEventListener('mousedown', e => { dragging=true; lastX=e.offsetX; lastY=e.offsetY; });
    canvas.addEventListener('mousemove', e => {
        if (!dragging) return;
        ox += e.offsetX - lastX; oy += e.offsetY - lastY;
        lastX=e.offsetX; lastY=e.offsetY; drawCrop();
    });
    canvas.addEventListener('mouseup', () => dragging=false);
    canvas.addEventListener('mouseleave', () => dragging=false);

    // Touch
    canvas.addEventListener('touchstart', e => {
        e.preventDefault();
        if (e.touches.length===1) {
            dragging=true;
            lastX=e.touches[0].clientX; lastY=e.touches[0].clientY;
        }
    }, {passive:false});
    canvas.addEventListener('touchmove', e => {
        e.preventDefault();
        if (dragging && e.touches.length===1) {
            ox += e.touches[0].clientX - lastX;
            oy += e.touches[0].clientY - lastY;
            lastX=e.touches[0].clientX; lastY=e.touches[0].clientY;
            drawCrop();
        }
        // Pinch zoom
        if (e.touches.length===2) {
            const d = Math.hypot(e.touches[0].clientX-e.touches[1].clientX, e.touches[0].clientY-e.touches[1].clientY);
            if (!canvas._lastPinch) { canvas._lastPinch=d; return; }
            const factor = d / canvas._lastPinch;
            canvas._lastPinch = d;
            const newScale = Math.max(minScale, Math.min(4, scale * factor));
            const ratio = newScale/scale;
            ox = canvas.width/2 - ratio*(canvas.width/2 - ox);
            oy = canvas.height/2 - ratio*(canvas.height/2 - oy);
            scale = newScale;
            document.getElementById('zoomRange').value = scale;
            drawCrop();
        }
    }, {passive:false});
    canvas.addEventListener('touchend', e => { dragging=false; canvas._lastPinch=null; });

    // Scroll zoom (desktop)
    canvas.addEventListener('wheel', e => {
        e.preventDefault();
        const factor = e.deltaY < 0 ? 1.08 : 0.93;
        const newScale = Math.max(minScale, Math.min(4, scale*factor));
        const rect = canvas.getBoundingClientRect();
        const mx = e.clientX - rect.left, my = e.clientY - rect.top;
        const ratio = newScale/scale;
        ox = mx - ratio*(mx-ox); oy = my - ratio*(my-oy);
        scale = newScale;
        document.getElementById('zoomRange').value = scale;
        drawCrop();
    }, {passive:false});
});

// Zoom slider
document.getElementById('zoomRange')?.addEventListener('input', function() {
    const newScale = parseFloat(this.value);
    const ratio = newScale/scale;
    if (!canvas) return;
    const cx=canvas.width/2, cy=canvas.height/2;
    ox = cx - ratio*(cx-ox); oy = cy - ratio*(cy-oy);
    scale = newScale; drawCrop();
});

// File input → open crop
document.getElementById('fotoFileInput')?.addEventListener('change', function() {
    if (!this.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => openCrop(e.target.result);
    reader.readAsDataURL(this.files[0]);
    this.value = ''; // reset so same file can be re-selected
});

/* ═══════════════════════════════════
   LEAFLET MAP
═══════════════════════════════════ */
let map, marker, circle;

function initMap(lat, lng) {
    map = L.map('mapKoord').setView([lat, lng], 16);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom:19, attribution:'&copy; OpenStreetMap'
    }).addTo(map);

    marker = L.marker([lat, lng], {draggable:true}).addTo(map);
    marker.on('dragend', e => {
        const p = marker.getLatLng();
        document.getElementById('latInput').value = p.lat.toFixed(6);
        document.getElementById('lngInput').value = p.lng.toFixed(6);
        circle.setLatLng(p);
    });

    circle = L.circle([lat, lng], {
        color:'#C9A84C', fillColor:'#FEFAEF', fillOpacity:.28, radius:100, weight:1.5
    }).addTo(map);

    map.on('click', e => {
        marker.setLatLng(e.latlng);
        circle.setLatLng(e.latlng);
        document.getElementById('latInput').value = e.latlng.lat.toFixed(6);
        document.getElementById('lngInput').value = e.latlng.lng.toFixed(6);
    });
}

function updateMarkerFromInput() {
    const lat = parseFloat(document.getElementById('latInput').value);
    const lng = parseFloat(document.getElementById('lngInput').value);
    if (!isNaN(lat) && !isNaN(lng) && lat>=-90 && lat<=90 && lng>=-180 && lng<=180) {
        marker?.setLatLng([lat,lng]);
        circle?.setLatLng([lat,lng]);
        map?.setView([lat,lng], 16);
    }
}

function ambilGPS() {
    if (!navigator.geolocation) { alert('GPS tidak didukung.'); return; }
    navigator.geolocation.getCurrentPosition(pos => {
        const lat = pos.coords.latitude, lng = pos.coords.longitude;
        document.getElementById('latInput').value = lat.toFixed(6);
        document.getElementById('lngInput').value = lng.toFixed(6);
        marker?.setLatLng([lat,lng]);
        circle?.setLatLng([lat,lng]);
        map?.setView([lat,lng], 16);
    }, () => alert('Gagal mengambil GPS. Pastikan izin lokasi diaktifkan.'), {enableHighAccuracy:true,timeout:10000});
}

function simpanKoordinat() {
    const lat = document.getElementById('latInput').value.trim();
    const lng = document.getElementById('lngInput').value.trim();
    if (!lat || !lng) { alert('Koordinat tidak boleh kosong.'); return; }
    if (isNaN(lat)||isNaN(lng)||lat<-90||lat>90||lng<-180||lng>180) { alert('Format koordinat tidak valid.'); return; }
    const f = document.createElement('form');
    f.method='POST';
    f.innerHTML=`<input name="csrf_token" value="<?php echo htmlspecialchars(csrf_generate(), ENT_QUOTES, 'UTF-8'); ?>"><input name="simpan_koordinat" value="1"><input name="lat_rumah" value="${lat}"><input name="lng_rumah" value="${lng}">`;
    document.body.appendChild(f); f.submit();
}

document.addEventListener('DOMContentLoaded', () => {
    const lat = <?= $user['lat_rumah'] ? floatval($user['lat_rumah']) : -7.365355 ?>;
    const lng = <?= $user['lng_rumah'] ? floatval($user['lng_rumah']) : 108.560654 ?>;
    initMap(lat, lng);

    <?php if ($setup_wfh): ?>
    setTimeout(() => {
        document.getElementById('sectionKoordinat')?.scrollIntoView({behavior:'smooth',block:'start'});
    }, 600);
    <?php endif; ?>
});
</script>

<?php include '../templates/footer.php'; ?>