<?php
require_once 'config/init.php';

// Redirect jika sudah login
if (isset($_SESSION['user_id'])) {
    $target = $_SESSION['role'] === 'admin' ? 'admin/dashboard.php' : 'pegawai/dashboard.php';
    header('Location: ' . $target);
    exit();
}

// Ambil nomor WA admin dari settings
$settingsStmt = $pdo->query("SELECT wa_admin FROM settings WHERE id = 1");
$settings     = $settingsStmt->fetch();
$wa_admin     = $settings['wa_admin'] ?? '6282130919861';

$step       = 'form';   // form | kirim
$found_user = null;
$wa_url     = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $nama_input     = trim($_POST['nama']     ?? '');
    $username_input = trim($_POST['username'] ?? '');

    if (!$nama_input || !$username_input) {
        $error = 'Nama lengkap dan username wajib diisi.';
    } else {
        $stmt = $pdo->prepare("SELECT id, nama, username, jabatan, unit_kerja FROM users WHERE username = ? AND role = 'pegawai' AND status = 'aktif'");
        $stmt->execute([$username_input]);
        $found_user = $stmt->fetch();

        if (!$found_user) {
            $error = 'Username tidak ditemukan atau akun tidak aktif. Periksa kembali username Anda.';
        } else {
            // Cek kesesuaian nama (case-insensitive, toleransi spasi)
            $nama_db    = strtolower(trim($found_user['nama']));
            $nama_input_lower = strtolower($nama_input);
            if (strpos($nama_db, $nama_input_lower) === false && strpos($nama_input_lower, $nama_db) === false) {
                $error = 'Nama lengkap tidak sesuai dengan data akun. Pastikan nama yang Anda masukkan benar.';
                $found_user = null;
            } else {
                $step = 'kirim';
                $waktu = date('d/m/Y H:i');
                $pesan = "Halo Admin,%0A%0ASaya ingin meminta *reset password* akun saya.%0A%0A*Detail Akun:*%0A• Nama%09: {$found_user['nama']}%0A• Username%09: {$found_user['username']}%0A• Jabatan%09: {$found_user['jabatan']}%0A• Unit Kerja%09: {$found_user['unit_kerja']}%0A• Waktu%09: {$waktu}%0A%0AMohon bantuannya untuk mereset password saya. Terima kasih 🙏";
                $wa_url = "https://wa.me/{$wa_admin}?text={$pesan}";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>Lupa Kata Sandi — Absensi Non-ASN BBWS Citanduy</title>
    <link rel="icon" type="image/png" href="assets/img/logo-instansi.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400;1,600&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<style>
:root {
    --navy:     #0A1628;
    --navy-mid: #0F2241;
    --navy-lit: #1A3560;
    --navy-xl:  #243970;
    --gold:     #C9A84C;
    --gold-lit: #E8C97A;
    --gold-pale:#F5E9C8;
    --gold-dim: rgba(201,168,76,.12);
    --silver:   #8A9BBE;
    --ash:      #CBD5E8;
    --white:    #FAFBFF;
    --surface:  #F2F5FB;
    --txt:      #0A1628;
    --muted:    #5A6A8A;
    --border:   #DDE4F0;
    --err:      #B52A2A;
    --ok:       #0E7A5F;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    height: 100%;
    font-family: 'DM Sans', sans-serif;
    background: var(--navy);
    color: var(--txt);
    -webkit-font-smoothing: antialiased;
}

/* ── PAGE WRAPPER ── */
.page {
    min-height: 100vh;
    display: grid;
    grid-template-rows: auto 1fr auto;
    position: relative;
    overflow: hidden;
}

/* ── BACKGROUND LAYER ── */
.bg-layer {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 0;
}
.bg-layer .grad {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 70% 60% at 80% 10%, rgba(201,168,76,.055) 0%, transparent 60%),
        radial-gradient(ellipse 50% 40% at 10% 80%, rgba(26,53,96,.6) 0%, transparent 60%),
        linear-gradient(160deg, #060E1D 0%, #0A1628 45%, #0D1E3C 100%);
}
.bg-layer .line-v {
    position: absolute;
    top: 0; bottom: 0;
    width: 1px;
    background: linear-gradient(to bottom, transparent, rgba(201,168,76,.15) 30%, rgba(201,168,76,.06) 70%, transparent);
}
.bg-layer .line-v:nth-child(2) { left: 30%; transform: rotate(3deg); }
.bg-layer .line-v:nth-child(3) { left: 62%; transform: rotate(-2deg); }
.bg-layer .dots-grid {
    position: absolute;
    top: 40px; right: 40px;
    width: 130px; height: 130px;
    background-image: radial-gradient(circle, rgba(201,168,76,.4) 1.5px, transparent 1.5px);
    background-size: 18px 18px;
    opacity: .25;
}
.bg-layer .dots-grid-2 {
    position: absolute;
    bottom: 60px; left: 30px;
    width: 90px; height: 90px;
    background-image: radial-gradient(circle, rgba(201,168,76,.3) 1.5px, transparent 1.5px);
    background-size: 14px 14px;
    opacity: .2;
}
.bg-layer .ring {
    position: absolute;
    border-radius: 50%;
    border: 1px solid rgba(201,168,76,.07);
}
.bg-layer .ring-1 { width: 600px; height: 600px; top: -200px; left: -200px; }
.bg-layer .ring-2 { width: 400px; height: 400px; bottom: -120px; right: -120px; }

/* ── HEADER ── */
.site-header {
    position: relative;
    z-index: 10;
    padding: 1.5rem 2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    border-bottom: 1px solid rgba(201,168,76,.1);
    backdrop-filter: blur(6px);
}
.header-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
}
.header-brand img {
    width: 40px; height: 40px;
    object-fit: contain;
    filter: drop-shadow(0 2px 8px rgba(0,0,0,.4));
}
.header-brand .brand-text {
    font-size: .72rem;
    font-weight: 600;
    color: var(--ash);
    letter-spacing: .12em;
    text-transform: uppercase;
    line-height: 1.5;
}
.header-brand .brand-text span {
    display: block;
    font-weight: 400;
    color: var(--silver);
    text-transform: none;
    letter-spacing: .03em;
    font-size: .67rem;
}
.back-link {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    font-size: .78rem;
    font-weight: 500;
    color: var(--silver);
    text-decoration: none;
    padding: .42rem .85rem;
    border-radius: 8px;
    border: 1px solid rgba(201,168,76,.2);
    transition: all .2s;
}
.back-link svg { width: 15px; height: 15px; }
.back-link:hover {
    color: var(--gold-lit);
    border-color: rgba(201,168,76,.45);
    background: rgba(201,168,76,.06);
}

/* ── MAIN ── */
.main {
    position: relative;
    z-index: 10;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 2.5rem 1.25rem;
}

/* ── CARD ── */
.card-wrap {
    width: 100%;
    max-width: 500px;
    animation: riseIn .65s cubic-bezier(.22,1,.36,1) both;
}
@keyframes riseIn {
    from { opacity: 0; transform: translateY(28px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* Gold accent bar top */
.card-accent {
    height: 3px;
    background: linear-gradient(90deg, var(--navy-lit), var(--gold) 40%, var(--gold-lit) 70%, var(--navy-lit));
    border-radius: 3px 3px 0 0;
}

.card {
    background: rgba(250,251,255,.97);
    border: 1px solid rgba(201,168,76,.18);
    border-top: none;
    border-radius: 0 0 20px 20px;
    padding: 2.5rem 2.75rem 2.75rem;
    box-shadow:
        0 32px 80px rgba(0,0,0,.45),
        0 0 0 1px rgba(201,168,76,.08) inset;
}
@media (max-width: 560px) {
    .card { padding: 2rem 1.5rem 2.25rem; }
}

/* ── ICON TOP ── */
.card-icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.5rem;
}
.card-icon {
    width: 64px; height: 64px;
    border-radius: 18px;
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-lit) 100%);
    border: 1px solid rgba(201,168,76,.3);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 28px rgba(10,22,40,.3);
    position: relative;
}
.card-icon svg { width: 28px; height: 28px; }
.card-icon .pulse-ring {
    position: absolute;
    inset: -6px;
    border-radius: 22px;
    border: 1.5px solid rgba(201,168,76,.25);
    animation: pulseRing 2.5s ease-in-out infinite;
}
@keyframes pulseRing {
    0%, 100% { opacity: .25; transform: scale(1); }
    50%       { opacity: .6;  transform: scale(1.04); }
}

/* ── HEADING ── */
.card-eyebrow {
    text-align: center;
    font-size: .68rem;
    font-weight: 600;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: var(--gold);
    margin-bottom: .55rem;
}
.card-title {
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.85rem;
    font-weight: 700;
    color: var(--navy);
    text-align: center;
    line-height: 1.15;
    margin-bottom: .5rem;
}
.card-sub {
    font-size: .82rem;
    color: var(--muted);
    text-align: center;
    line-height: 1.65;
    margin-bottom: 1.75rem;
}

/* Gold divider */
.gold-divider {
    display: flex;
    align-items: center;
    gap: .75rem;
    margin-bottom: 1.75rem;
}
.gold-divider::before,
.gold-divider::after {
    content: '';
    flex: 1;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(201,168,76,.3));
}
.gold-divider::after {
    background: linear-gradient(90deg, rgba(201,168,76,.3), transparent);
}
.gold-divider-dot {
    width: 6px; height: 6px;
    border-radius: 50%;
    background: var(--gold);
    opacity: .6;
}

/* ── ALERT ── */
.alert {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    padding: .85rem 1rem;
    border-radius: 12px;
    font-size: .81rem;
    line-height: 1.55;
    margin-bottom: 1.25rem;
}
.alert svg { width: 16px; height: 16px; flex-shrink: 0; margin-top: 1px; }
.alert--err {
    background: #FDF2F2;
    border: 1px solid #F8CACA;
    border-left: 3px solid var(--err);
    color: var(--err);
}
.alert--err svg { fill: var(--err); }
.alert--info {
    background: #F0F7FF;
    border: 1px solid #BDD8F5;
    border-left: 3px solid #3B82F6;
    color: #1A3A6B;
}
.alert--info svg { fill: #3B82F6; }

/* ── FORM ── */
.field { margin-bottom: 1.1rem; }
.field label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: .78rem;
    font-weight: 600;
    color: var(--navy);
    margin-bottom: .5rem;
    letter-spacing: .015em;
}
.field label svg { width: 14px; height: 14px; opacity: .5; }
.field label .req { color: var(--err); margin-left: 1px; }

.input-wrap { position: relative; }
.input-wrap input {
    width: 100%;
    padding: .82rem 1rem .82rem 2.8rem;
    background: var(--surface);
    border: 1.5px solid var(--border);
    border-radius: 12px;
    font-family: 'DM Sans', sans-serif;
    font-size: .88rem;
    color: var(--txt);
    outline: none;
    transition: border-color .2s, box-shadow .2s, background .2s;
    -webkit-appearance: none;
}
.input-wrap input::placeholder { color: var(--ash); }
.input-wrap input:hover { border-color: var(--silver); background: #EEF2FA; }
.input-wrap input:focus {
    border-color: var(--gold);
    background: var(--white);
    box-shadow: 0 0 0 4px rgba(201,168,76,.1);
}
.input-wrap input.is-err {
    border-color: var(--err);
    box-shadow: 0 0 0 4px rgba(181,42,42,.08);
}
.input-ico {
    position: absolute;
    left: .85rem;
    top: 50%;
    transform: translateY(-50%);
    pointer-events: none;
}
.input-ico svg { width: 16px; height: 16px; }
.field-hint {
    font-size: .7rem;
    color: var(--silver);
    margin-top: .35rem;
    padding-left: .15rem;
}

/* ── SUBMIT BTN ── */
.btn-submit {
    width: 100%;
    padding: .95rem 1.5rem;
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy-lit) 100%);
    color: var(--white);
    border: none;
    border-radius: 12px;
    font-family: 'DM Sans', sans-serif;
    font-size: .9rem;
    font-weight: 600;
    letter-spacing: .04em;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    box-shadow: 0 6px 24px rgba(10,22,40,.28);
    transition: all .25s ease;
    position: relative;
    overflow: hidden;
    margin-top: 1.5rem;
}
.btn-submit::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, transparent, rgba(201,168,76,.14), transparent);
    transform: translateX(-100%);
    transition: transform .55s ease;
}
.btn-submit:hover::before { transform: translateX(100%); }
.btn-submit:hover {
    background: linear-gradient(135deg, var(--navy-mid) 0%, var(--navy-xl) 100%);
    box-shadow: 0 10px 32px rgba(10,22,40,.38);
    transform: translateY(-2px);
}
.btn-submit:active { transform: translateY(0); }
.btn-submit svg { width: 18px; height: 18px; flex-shrink: 0; }
.btn-submit .spin {
    width: 18px; height: 18px;
    border: 2px solid rgba(255,255,255,.3);
    border-top-color: #fff;
    border-radius: 50%;
    animation: rotating .7s linear infinite;
    display: none;
    flex-shrink: 0;
}
@keyframes rotating { to { transform: rotate(360deg); } }

/* ── STEP: KIRIM ── */
.success-wrap {
    animation: riseIn .5s cubic-bezier(.22,1,.36,1) both;
}

.success-icon-wrap {
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 1.4rem;
}
.success-icon {
    width: 72px; height: 72px;
    border-radius: 50%;
    background: linear-gradient(135deg, #0E7A5F, #10a37f);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 8px 28px rgba(14,122,95,.35);
    animation: popIn .5s cubic-bezier(.34,1.56,.64,1) both;
}
@keyframes popIn {
    from { opacity: 0; transform: scale(.4); }
    to   { opacity: 1; transform: scale(1); }
}
.success-icon svg { width: 32px; height: 32px; }

.user-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 1rem 1.2rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    gap: .85rem;
}
.user-avatar {
    width: 46px; height: 46px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--navy), var(--navy-lit));
    border: 2px solid rgba(201,168,76,.3);
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: 'Cormorant Garamond', serif;
    font-size: 1.2rem;
    font-weight: 700;
    color: var(--gold-lit);
    flex-shrink: 0;
}
.user-info-name {
    font-weight: 600;
    font-size: .88rem;
    color: var(--navy);
    margin-bottom: .12rem;
}
.user-info-meta {
    font-size: .72rem;
    color: var(--muted);
}

/* Steps indicator */
.steps {
    display: flex;
    gap: 0;
    margin-bottom: 1.5rem;
    border-radius: 10px;
    overflow: hidden;
    border: 1px solid var(--border);
}
.step-item {
    flex: 1;
    display: flex;
    align-items: center;
    gap: 8px;
    padding: .7rem .9rem;
    font-size: .75rem;
    font-weight: 500;
    color: var(--silver);
    background: var(--surface);
    position: relative;
}
.step-item:not(:last-child) {
    border-right: 1px solid var(--border);
}
.step-item.done {
    background: #F0FDF8;
    color: var(--ok);
}
.step-item.active {
    background: var(--gold-pale);
    color: var(--navy);
    font-weight: 600;
}
.step-num {
    width: 22px; height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .68rem;
    font-weight: 700;
    flex-shrink: 0;
    background: var(--border);
    color: var(--muted);
}
.step-item.done .step-num {
    background: var(--ok);
    color: #fff;
}
.step-item.active .step-num {
    background: var(--gold);
    color: #fff;
}

/* WA button */
.btn-wa {
    width: 100%;
    padding: 1rem 1.5rem;
    background: linear-gradient(135deg, #075E54 0%, #128C7E 100%);
    color: #fff;
    border: none;
    border-radius: 14px;
    font-family: 'DM Sans', sans-serif;
    font-size: .92rem;
    font-weight: 600;
    letter-spacing: .02em;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    box-shadow: 0 6px 24px rgba(7,94,84,.35);
    transition: all .25s;
    text-decoration: none;
    position: relative;
    overflow: hidden;
}
.btn-wa::before {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.1), transparent);
    transform: translateX(-100%);
    transition: transform .5s;
}
.btn-wa:hover::before { transform: translateX(100%); }
.btn-wa:hover {
    background: linear-gradient(135deg, #064a42 0%, #0d7a6e 100%);
    box-shadow: 0 10px 32px rgba(7,94,84,.45);
    transform: translateY(-2px);
    color: #fff;
}
.btn-wa svg { width: 22px; height: 22px; flex-shrink: 0; }

.wa-note {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    background: #F0FDF8;
    border: 1px solid #A7F3D0;
    border-radius: 10px;
    padding: .8rem 1rem;
    margin-top: 1rem;
    font-size: .76rem;
    color: #065F46;
    line-height: 1.55;
}
.wa-note svg { width: 14px; height: 14px; fill: #059669; flex-shrink: 0; margin-top: 2px; }

.try-again {
    display: block;
    text-align: center;
    margin-top: 1.1rem;
    font-size: .78rem;
    color: var(--silver);
    text-decoration: none;
    transition: color .2s;
}
.try-again:hover { color: var(--gold); }

/* ── FOOTER ── */
.site-footer {
    position: relative;
    z-index: 10;
    text-align: center;
    padding: 1.25rem;
    font-size: .68rem;
    color: rgba(255,255,255,.22);
    border-top: 1px solid rgba(201,168,76,.08);
}

/* ── RESPONSIVE ── */
@media (max-width: 560px) {
    .site-header { padding: 1rem 1.25rem; }
    .header-brand .brand-text { display: none; }
    .steps { flex-direction: column; }
    .step-item:not(:last-child) { border-right: none; border-bottom: 1px solid var(--border); }
}
</style>
</head>
<body>
<div class="page">

    <!-- BG -->
    <div class="bg-layer">
        <div class="grad"></div>
        <div class="line-v"></div>
        <div class="line-v"></div>
        <div class="dots-grid"></div>
        <div class="dots-grid-2"></div>
        <div class="ring ring-1"></div>
        <div class="ring ring-2"></div>
    </div>

    <!-- HEADER -->
    <header class="site-header">
        <a href="login.php" class="header-brand">
            <img src="assets/img/logo-instansi.png" alt="Logo BBWS Citanduy" onerror="this.style.display='none'">
            <div class="brand-text">BBWS Citanduy<span>Kementerian Pekerjaan Umum</span></div>
        </a>
        <a href="login.php" class="back-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M19 12H5M12 19l-7-7 7-7"/>
            </svg>
            Kembali Login
        </a>
    </header>

    <!-- MAIN -->
    <main class="main">
        <div class="card-wrap">
            <div class="card-accent"></div>
            <div class="card">

            <?php if ($step === 'form'): ?>
            <!-- ══ STEP 1: FORM ══ -->

                <div class="card-icon-wrap">
                    <div class="card-icon">
                        <div class="pulse-ring"></div>
                        <svg viewBox="0 0 24 24" fill="none">
                            <rect x="3" y="11" width="18" height="11" rx="2" stroke="var(--gold-lit)" stroke-width="1.8"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="var(--gold-lit)" stroke-width="1.8"/>
                            <circle cx="12" cy="16" r="1.5" fill="var(--gold-lit)"/>
                        </svg>
                    </div>
                </div>

                <div class="card-eyebrow">Pemulihan Akses</div>
                <div class="card-title">Lupa Kata Sandi?</div>
                <div class="card-sub">Masukkan data akun Anda. Kami akan menghubungkan Anda langsung ke admin untuk proses reset.</div>

                <div class="gold-divider"><div class="gold-divider-dot"></div></div>

                <?php if (isset($error)): ?>
                <div class="alert alert--err">
                    <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
                <?php endif; ?>

                <div class="alert alert--info">
                    <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                    <span>Data Anda digunakan untuk memverifikasi identitas sebelum pesan dikirim ke admin.</span>
                </div>

                <form method="POST" id="lupaForm" novalidate>
                    <?php csrf_field(); ?>
                    <div class="field">
                        <label for="nama">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.58-7 8-7s8 3 8 7"/></svg>
                            Nama Lengkap <span class="req">*</span>
                        </label>
                        <div class="input-wrap">
                            <span class="input-ico">
                                <svg viewBox="0 0 24 24" fill="none">
                                    <circle cx="12" cy="8" r="4" stroke="#8A9BBE" stroke-width="1.8"/>
                                    <path d="M4 20c0-4 3.58-7 8-7s8 3 8 7" stroke="#8A9BBE" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                            </span>
                            <input type="text" id="nama" name="nama"
                                   placeholder="Masukkan nama lengkap Anda"
                                   value="<?= htmlspecialchars($_POST['nama'] ?? '') ?>"
                                   autocomplete="name" required>
                        </div>
                        <div class="field-hint">Sesuaikan dengan nama yang terdaftar di sistem</div>
                    </div>

                    <div class="field">
                        <label for="username">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M8 12h8M8 8h4"/></svg>
                            Username <span class="req">*</span>
                        </label>
                        <div class="input-wrap">
                            <span class="input-ico">
                                <svg viewBox="0 0 24 24" fill="none">
                                    <rect x="2" y="4" width="20" height="16" rx="2" stroke="#8A9BBE" stroke-width="1.8"/>
                                    <path d="M8 10h8M8 14h5" stroke="#8A9BBE" stroke-width="1.8" stroke-linecap="round"/>
                                </svg>
                            </span>
                            <input type="text" id="username" name="username"
                                   placeholder="Masukkan username login Anda"
                                   value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                                   autocomplete="username" required>
                        </div>
                        <div class="field-hint">Username yang Anda gunakan saat login (biasanya email)</div>
                    </div>

                    <button type="submit" class="btn-submit" id="submitBtn">
                        <svg viewBox="0 0 24 24" fill="none" id="btnIco">
                            <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6 6l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.73 16l.19.92z" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                        <span id="btnLabel">Verifikasi & Hubungi Admin</span>
                        <span class="spin" id="spinIco"></span>
                    </button>
                </form>

            <?php else: ?>
            <!-- ══ STEP 2: KIRIM WA ══ -->

                <div class="success-wrap">

                    <!-- Steps -->
                    <div class="steps">
                        <div class="step-item done">
                            <div class="step-num">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none">
                                    <path d="M20 6L9 17l-5-5" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                            <span>Verifikasi Akun</span>
                        </div>
                        <div class="step-item active">
                            <div class="step-num">2</div>
                            <span>Kirim ke Admin</span>
                        </div>
                    </div>

                    <div class="success-icon-wrap">
                        <div class="success-icon">
                            <svg viewBox="0 0 24 24" fill="none">
                                <path d="M20 6L9 17l-5-5" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </div>
                    </div>

                    <div class="card-eyebrow">Akun Ditemukan</div>
                    <div class="card-title" style="margin-bottom:.4rem">Satu Langkah Lagi</div>
                    <div class="card-sub" style="margin-bottom:1.25rem">Klik tombol di bawah untuk membuka WhatsApp dan mengirim permintaan reset ke admin secara langsung.</div>

                    <!-- User card -->
                    <div class="user-card">
                        <div class="user-avatar">
                            <?= strtoupper(substr($found_user['nama'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="user-info-name"><?= htmlspecialchars($found_user['nama']) ?></div>
                            <div class="user-info-meta">
                                <?= htmlspecialchars($found_user['jabatan']) ?> &middot; <?= htmlspecialchars($found_user['unit_kerja']) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Tombol WhatsApp -->
                    <a href="<?= $wa_url ?>" target="_blank" class="btn-wa" id="btnWa">
                        <svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg">
                            <path fill="#fff" d="M16.004 2.667C8.64 2.667 2.667 8.64 2.667 16c0 2.347.64 4.613 1.84 6.587L2.667 29.333l6.987-1.787A13.22 13.22 0 0 0 16.004 29.333c7.36 0 13.329-5.973 13.329-13.333S23.364 2.667 16.004 2.667zm0 2.4c6.027 0 10.933 4.907 10.933 10.933S22.031 26.933 16.004 26.933c-2.08 0-4.027-.587-5.68-1.6l-.4-.24-4.16 1.067 1.093-3.973-.267-.427A10.893 10.893 0 0 1 5.071 16c0-6.027 4.907-10.933 10.933-10.933zm-3.36 5.706c-.187 0-.48.067-.733.347-.24.267-.933.907-.933 2.213 0 1.307.96 2.56 1.093 2.747.133.187 1.867 2.933 4.587 4c.64.267 1.147.427 1.52.547.64.2 1.227.173 1.68.107.52-.08 1.587-.64 1.813-1.267.227-.627.227-1.147.16-1.267-.067-.107-.24-.16-.52-.293-.267-.133-1.6-.787-1.84-.88-.24-.093-.413-.133-.587.133-.173.267-.667.88-.813 1.053-.147.173-.293.2-.547.067-.267-.133-1.12-.413-2.133-1.307-.787-.693-1.32-1.56-1.48-1.827-.147-.267-.013-.413.12-.547.12-.12.267-.307.4-.467.133-.16.173-.267.267-.44.093-.173.04-.333-.013-.467-.053-.133-.587-1.44-.8-1.96-.213-.507-.44-.44-.6-.44l-.507-.013z"/>
                        </svg>
                        Buka WhatsApp & Kirim Pesan
                    </a>

                    <div class="wa-note">
                        <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                        <span>Pesan sudah disiapkan otomatis, Anda tinggal menekan <strong>Kirim</strong> di WhatsApp. Admin akan memproses dan mereset password Anda sesegera mungkin.</span>
                    </div>

                    <a href="lupa_password.php" class="try-again">← Kembali ke form verifikasi</a>

                </div>

            <?php endif; ?>

            </div><!-- .card -->
        </div><!-- .card-wrap -->
    </main>

    <!-- FOOTER -->
    <footer class="site-footer">
        © <?= date('Y') ?> Balai Besar Wilayah Sungai Citanduy — Direktorat Jenderal Sumber Daya Air, Kementerian PU
    </footer>

</div><!-- .page -->

<script>
<?php if ($step === 'form'): ?>
// Form validation & loading state
document.getElementById('lupaForm').addEventListener('submit', function(e) {
    const nama     = document.getElementById('nama');
    const username = document.getElementById('username');
    let ok = true;

    [nama, username].forEach(el => el.classList.remove('is-err'));

    if (!nama.value.trim())     { nama.classList.add('is-err');     nama.focus();     ok = false; }
    if (!username.value.trim()) { username.classList.add('is-err'); if(ok) username.focus(); ok = false; }

    if (!ok) { e.preventDefault(); return; }

    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    document.getElementById('btnLabel').textContent = 'Memverifikasi...';
    document.getElementById('btnIco').style.display = 'none';
    document.getElementById('spinIco').style.display = 'block';
});

['nama','username'].forEach(function(id) {
    document.getElementById(id).addEventListener('input', function() {
        this.classList.remove('is-err');
    });
});

document.getElementById('username').addEventListener('input', function() {
    const p = this.selectionStart;
    this.value = this.value.toLowerCase();
    this.setSelectionRange(p, p);
});

// Focus otomatis
window.addEventListener('load', function() {
    document.getElementById('nama').focus();
});
<?php endif; ?>

<?php if ($step === 'kirim'): ?>
// Auto buka WA setelah 800ms (UX: beri waktu user baca dulu)
window.addEventListener('load', function() {
    setTimeout(function() {
        // Tampilkan hint animasi pada tombol WA
        const btn = document.getElementById('btnWa');
        if (btn) {
            btn.style.animation = 'none';
            btn.offsetHeight; // reflow
            btn.style.boxShadow = '0 0 0 0 rgba(7,94,84,0)';
            btn.animate([
                { boxShadow: '0 0 0 0 rgba(7,94,84,.5)' },
                { boxShadow: '0 0 0 14px rgba(7,94,84,0)' }
            ], { duration: 900, iterations: 2 });
        }
    }, 600);
});
<?php endif; ?>
</script>
</body>
</html>