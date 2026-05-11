<?php
require_once __DIR__ . '/config/init.php';
require_once __DIR__ . '/config/helper.php';

// Auth guard: harus login QR. Jika belum, redirect ke login QR
if (!validate_qr_session($pdo)) {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    header('Location: ' . $base . '/login.php?return=' . urlencode($base . '/qr.php'));
    exit();
}

// ── Generate one-time token untuk handoff ke dashboard web ────────────
function generate_qr_handoff_token(): string {
    global $pdo;
    $token   = bin2hex(random_bytes(24));
    $user_id = $_SESSION['user_id'] ?? 0;
    // Simpan di QRSES
    $_SESSION['qr_dashboard_token']   = $token;
    $_SESSION['qr_dashboard_user_id'] = $user_id;
    $_SESSION['qr_dashboard_expires'] = time() + 300;
    // Simpan ke kolom khusus di DB (TIDAK pakai remember_token agar cookie QR tidak bentrok)
    if ($user_id && isset($pdo)) {
        try {
            $expires = date('Y-m-d H:i:s', time() + 300);
            $pdo->prepare("UPDATE users SET qr_handoff_token = ?, qr_handoff_expires = ? WHERE id = ?")
                ->execute([$token, $expires, $user_id]);
        } catch (Exception $e) { /* silent */ }
    }
    return $token;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Verifikasi Kehadiran</title>
    <link rel="icon" type="image/png" href="assets/img/logo-instansi.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root {
            --navy:      #0A1628;
            --navy-mid:  #0F2241;
            --navy-lit:  #1A3560;
            --gold:      #C9A84C;
            --gold-lit:  #E8C97A;
            --silver:    #8A9BBE;
            --ash:       #CBD5E8;
            --white:     #FAFBFF;
            --surface:   rgba(255,255,255,0.97);
            --txt:       #0A1628;
            --muted:     #5A6A8A;
            --ok:        #0E7A5F;
            --err:       #B52A2A;
            --warn:      #9A6200;
        }
        *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
        body {
            font-family:'DM Sans',sans-serif;
            background:var(--navy);
            min-height:100vh;
            display:flex; align-items:center; justify-content:center;
            padding:1.25rem; overflow:hidden; position:relative;
        }
        body::before {
            content:''; position:fixed; inset:0;
            background:
                radial-gradient(ellipse 80% 60% at 20% 10%, rgba(201,168,76,.08) 0%, transparent 60%),
                radial-gradient(ellipse 60% 80% at 80% 90%, rgba(26,53,96,.6) 0%, transparent 60%),
                linear-gradient(160deg,#0A1628 0%,#0F2241 50%,#081220 100%);
            z-index:0;
        }
        body::after {
            content:''; position:fixed; inset:0;
            background-image:
                repeating-linear-gradient(0deg,transparent,transparent 39px,rgba(201,168,76,.025) 39px,rgba(201,168,76,.025) 40px),
                repeating-linear-gradient(90deg,transparent,transparent 39px,rgba(201,168,76,.025) 39px,rgba(201,168,76,.025) 40px);
            z-index:0;
        }
        .orb { position:fixed; border-radius:50%; filter:blur(80px); opacity:.10; animation:drift 12s ease-in-out infinite alternate; pointer-events:none; z-index:0; }
        .orb-1 { width:400px; height:400px; background:var(--gold); top:-100px; left:-100px; }
        .orb-2 { width:300px; height:300px; background:var(--navy-lit); bottom:-80px; right:-80px; animation-delay:-5s; }
        @keyframes drift { from{transform:translate(0,0) scale(1)} to{transform:translate(30px,20px) scale(1.08)} }
        .card {
            position:relative; z-index:1;
            background:var(--surface);
            border-radius:28px; width:100%; max-width:390px;
            overflow:hidden;
            box-shadow:0 40px 80px rgba(0,0,0,.55), 0 0 0 1px rgba(201,168,76,.18), inset 0 1px 0 rgba(255,255,255,.8);
            animation:cardIn .7s cubic-bezier(.22,1,.36,1) both;
        }
        @keyframes cardIn { from{opacity:0;transform:translateY(32px) scale(.97)} to{opacity:1;transform:translateY(0) scale(1)} }
        .hd {
            background:linear-gradient(135deg,var(--navy) 0%,var(--navy-mid) 60%,var(--navy-lit) 100%);
            padding:1.5rem 2rem 1.4rem; position:relative; overflow:hidden;
        }
        .hd::after { content:''; position:absolute; bottom:0; left:0; right:0; height:2px; background:linear-gradient(90deg,transparent,var(--gold) 40%,var(--gold-lit) 60%,transparent); }
        .hd::before { content:''; position:absolute; top:-36px; right:-36px; width:130px; height:130px; border:1px solid rgba(201,168,76,.12); border-radius:50%; }
        .brand { display:flex; align-items:center; gap:.6rem; margin-bottom:1.1rem; }
        .brand-ico { width:30px; height:30px; background:linear-gradient(135deg,var(--gold),var(--gold-lit)); border-radius:7px; display:flex; align-items:center; justify-content:center; box-shadow:0 4px 12px rgba(201,168,76,.35); }
        .brand-ico svg { width:16px; height:16px; fill:var(--navy); }
        .brand-lbl { font-family:'Cormorant Garamond',serif; color:var(--ash); font-size:.7rem; letter-spacing:.18em; text-transform:uppercase; font-weight:600; }
        .clock-row { display:flex; align-items:center; gap:1.1rem; }
        #analogClock { flex-shrink:0; filter:drop-shadow(0 4px 12px rgba(201,168,76,.22)); }
        .clock-digital { font-family:'Cormorant Garamond',serif; font-size:2.5rem; font-weight:700; color:var(--white); letter-spacing:.05em; line-height:1; font-variant-numeric:tabular-nums; text-shadow:0 2px 16px rgba(201,168,76,.3); }
        .clock-digital .colon { color:var(--gold); animation:blink 1s step-end infinite; }
        @keyframes blink { 50%{opacity:.25} }
        .date-str { font-size:.72rem; color:var(--silver); letter-spacing:.07em; margin-top:.3rem; font-weight:400; }
        .bd { padding:1.75rem 2rem 2rem; }
        .status-zone { display:flex; flex-direction:column; align-items:center; text-align:center; gap:.65rem; min-height:200px; justify-content:center; }
        .icon-ring { width:80px; height:80px; border-radius:50%; background:linear-gradient(145deg,#EEF2FF,#E0E7FF); border:1.5px solid var(--ash); display:flex; align-items:center; justify-content:center; position:relative; transition:all .5s cubic-bezier(.22,1,.36,1); flex-shrink:0; }
        .icon-ring svg.st-ico { width:34px; height:34px; transition:all .4s ease; }
        .icon-ring.s-success { background:linear-gradient(145deg,#D1FAE5,#A7F3D0); border-color:#6EE7B7; box-shadow:0 0 0 8px rgba(16,185,129,.09); }
        .icon-ring.s-error   { background:linear-gradient(145deg,#FEE2E2,#FECACA); border-color:#FCA5A5; box-shadow:0 0 0 8px rgba(239,68,68,.09); }
        .icon-ring.s-warning { background:linear-gradient(145deg,#FEF3C7,#FDE68A); border-color:#FCD34D; box-shadow:0 0 0 8px rgba(245,158,11,.09); }
        .icon-ring.s-gold    { background:linear-gradient(145deg,#FEF3C7,var(--gold-lit)); border-color:var(--gold); box-shadow:0 0 0 8px rgba(201,168,76,.12); }
        .avatar-wrap { display:none; flex-direction:column; align-items:center; animation:avatarIn .6s cubic-bezier(.22,1,.36,1) both; position:relative; }
        .avatar-wrap.show { display:flex; }
        .avatar-wrap img { width:110px; height:110px; object-fit:contain; object-position:center top; filter:drop-shadow(0 8px 24px rgba(10,22,40,.3)); }
        .avatar-ring   { position:absolute; width:120px; height:120px; border-radius:50%; border:2px solid rgba(201,168,76,.25); animation:ringPulse 2s ease-in-out infinite; top:50%; left:50%; transform:translate(-50%,-50%); }
        .avatar-ring-2 { position:absolute; width:140px; height:140px; border-radius:50%; border:1px solid rgba(201,168,76,.12); animation:ringPulse 2s .4s ease-in-out infinite; top:50%; left:50%; transform:translate(-50%,-50%); }
        @keyframes avatarIn  { from{opacity:0;transform:scale(.7) translateY(10px)} to{opacity:1;transform:scale(1) translateY(0)} }
        @keyframes ringPulse { 0%,100%{transform:translate(-50%,-50%) scale(1);opacity:.6} 50%{transform:translate(-50%,-50%) scale(1.08);opacity:.2} }
        .icon-ring.hidden { display:none; }
        .orbit { position:absolute; inset:-7px; border-radius:50%; border:2px solid transparent; border-top-color:var(--gold); border-right-color:rgba(201,168,76,.25); animation:spin 1.1s linear infinite; }
        .orbit.off { display:none; }
        @keyframes spin { to{transform:rotate(360deg)} }
        .st-title { font-family:'Cormorant Garamond',serif; font-size:1.35rem; font-weight:700; color:var(--txt); line-height:1.2; }
        .st-sub   { font-size:.83rem; color:var(--muted); line-height:1.65; max-width:270px; }
        .info-box { display:none; width:100%; background:linear-gradient(135deg,#F8FAFF,#EEF2FF); border:1px solid var(--ash); border-left:3px solid var(--gold); border-radius:0 12px 12px 0; padding:.85rem 1rem; text-align:left; margin-top:.25rem; }
        .info-box.show { display:block; }
        .info-box .ib-label { font-size:.72rem; color:var(--muted); margin-bottom:.2rem; display:flex; align-items:center; gap:.35rem; }
        .info-box .ib-label svg { width:12px; height:12px; }
        .info-box .ib-val { font-family:'Cormorant Garamond',serif; font-size:1.1rem; font-weight:700; color:var(--navy); }
        .info-box .ib-sub { font-size:.72rem; color:var(--silver); margin-top:.2rem; }
        .done-box { display:none; width:100%; background:linear-gradient(135deg,#D1FAE5,#A7F3D0); border:1px solid #6EE7B7; border-radius:14px; padding:1rem; text-align:left; margin-top:.25rem; }
        .done-box.show { display:block; }
        .done-box .db-row  { display:flex; justify-content:space-between; gap:.75rem; }
        .done-box .db-col  { flex:1; }
        .done-box .db-label{ font-size:.68rem; color:#0E7A5F; font-weight:600; letter-spacing:.06em; text-transform:uppercase; margin-bottom:.2rem; }
        .done-box .db-val  { font-family:'Cormorant Garamond',serif; font-size:1.1rem; font-weight:700; color:var(--navy); }
        .done-box .db-div  { width:1px; background:rgba(14,122,95,.2); flex-shrink:0; }
        .done-box .db-note { font-size:.74rem; color:#0E7A5F; margin-top:.65rem; padding-top:.5rem; border-top:1px solid rgba(14,122,95,.2); }
        .prog-track { width:100%; height:3px; background:#EEF2FF; border-radius:99px; overflow:hidden; margin-top:1rem; opacity:0; transition:opacity .3s; }
        .prog-track.on { opacity:1; }
        .prog-fill  { height:100%; background:linear-gradient(90deg,var(--navy-mid),var(--gold)); border-radius:99px; width:0%; transition:width .5s cubic-bezier(.4,0,.2,1); }
        .pill { display:none; align-items:center; gap:6px; padding:5px 16px; border-radius:99px; font-size:.76rem; font-weight:600; letter-spacing:.04em; margin-top:.2rem; }
        .pill.on { display:inline-flex; }
        .pill-dot { width:6px; height:6px; border-radius:50%; flex-shrink:0; }
        .pill.ok  { background:#D1FAE5; color:var(--ok);  } .pill.ok  .pill-dot { background:var(--ok); }
        .pill.err { background:#FEE2E2; color:var(--err); } .pill.err .pill-dot { background:var(--err); }
        .pill.wrn { background:#FEF3C7; color:var(--warn);} .pill.wrn .pill-dot { background:var(--warn);}
        .pill.gold{ background:#FEF9EC; color:#7A5E00;   } .pill.gold .pill-dot { background:var(--gold);}
        .sep { width:100%; height:1px; background:linear-gradient(90deg,transparent,var(--ash),transparent); margin:1.25rem 0; display:none; }
        .btn-dash { display:none; width:100%; padding:.85rem 1.5rem; background:linear-gradient(135deg,var(--navy-mid),var(--navy-lit)); color:var(--white); border:none; border-radius:12px; font-family:'DM Sans',sans-serif; font-weight:600; font-size:.88rem; letter-spacing:.04em; cursor:pointer; text-decoration:none; text-align:center; transition:all .2s ease; box-shadow:0 4px 16px rgba(15,34,65,.3); position:relative; overflow:hidden; }
        .btn-dash::before { content:''; position:absolute; top:0; left:-100%; width:100%; height:100%; background:linear-gradient(90deg,transparent,rgba(201,168,76,.15),transparent); transition:left .5s; }
        .btn-dash:hover::before { left:100%; }
        .btn-dash:hover  { box-shadow:0 8px 24px rgba(15,34,65,.4); transform:translateY(-1px); }
        .btn-dash:active { transform:translateY(0); }
        .btn-dash.on { display:block; }
        .ft { padding:.75rem 2rem; background:linear-gradient(90deg,#F8FAFF,#F5F7FF); border-top:1px solid var(--ash); display:flex; align-items:center; justify-content:space-between; }
        .ft-lbl { font-size:.67rem; color:var(--silver); letter-spacing:.1em; text-transform:uppercase; font-weight:500; }
        .ft-sec { display:flex; align-items:center; gap:4px; font-size:.67rem; color:var(--silver); font-weight:500; }
        .ft-sec svg { width:11px; height:11px; fill:var(--gold); }
        #toastContainer { position:fixed; top:0; left:0; right:0; display:flex; flex-direction:column; align-items:center; gap:10px; padding:12px 16px 0; z-index:9999; pointer-events:none; }
        .toast { width:100%; max-width:380px; background:rgba(18,18,22,.88); backdrop-filter:blur(24px) saturate(180%); -webkit-backdrop-filter:blur(24px) saturate(180%); border:1px solid rgba(255,255,255,.1); border-radius:18px; padding:13px 15px; display:flex; align-items:center; gap:12px; pointer-events:all; cursor:pointer; box-shadow:0 4px 6px rgba(0,0,0,.12),0 12px 40px rgba(0,0,0,.35),0 0 0 .5px rgba(255,255,255,.06) inset; transform:translateY(-110%) scale(.92); opacity:0; transition:transform .48s cubic-bezier(.22,1,.36,1),opacity .35s ease; will-change:transform,opacity; overflow:hidden; position:relative; }
        .toast.show { transform:translateY(0) scale(1); opacity:1; }
        .toast.hide { transform:translateY(-110%) scale(.9); opacity:0; transition:transform .32s cubic-bezier(.4,0,1,1),opacity .22s ease; }
        .toast-progress { position:absolute; bottom:0; left:0; height:2.5px; border-radius:0 0 18px 18px; width:100%; transform-origin:left; animation:toastProgress linear forwards; }
        @keyframes toastProgress { from{transform:scaleX(1)} to{transform:scaleX(0)} }
        .toast-icon { width:40px; height:40px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
        .toast-icon svg { width:20px; height:20px; }
        .toast.t-success .toast-icon { background:linear-gradient(135deg,#059669,#0D9488); }
        .toast.t-success .toast-progress { background:linear-gradient(90deg,#059669,#0D9488); }
        .toast.t-error   .toast-icon { background:linear-gradient(135deg,#DC2626,#B91C1C); }
        .toast.t-error   .toast-progress { background:linear-gradient(90deg,#DC2626,#EF4444); }
        .toast.t-warning .toast-icon { background:linear-gradient(135deg,#D97706,#B45309); }
        .toast.t-warning .toast-progress { background:linear-gradient(90deg,#D97706,#FBBF24); }
        .toast.t-info    .toast-icon { background:linear-gradient(135deg,#0F2241,#1A3560); }
        .toast.t-info    .toast-progress { background:linear-gradient(90deg,#C9A84C,#E8C97A); }
        .toast-body  { flex:1; min-width:0; }
        .toast-title { font-size:.82rem; font-weight:600; color:rgba(255,255,255,.95); line-height:1.3; margin-bottom:2px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .toast-msg   { font-size:.74rem; color:rgba(255,255,255,.58); line-height:1.45; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
        .toast-app   { display:flex; flex-direction:column; align-items:flex-end; gap:2px; flex-shrink:0; }
        .toast-app-name { font-size:.62rem; color:rgba(255,255,255,.35); letter-spacing:.04em; text-transform:uppercase; font-weight:500; }
        .toast-time     { font-size:.65rem; color:rgba(255,255,255,.3); font-variant-numeric:tabular-nums; }
        .toast-handle   { position:absolute; top:5px; left:50%; transform:translateX(-50%); width:32px; height:3px; background:rgba(255,255,255,.15); border-radius:99px; }
    </style>
</head>
<body>
<div id="toastContainer"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="card">
    <div class="hd">
        <div class="brand">
            <div class="brand-ico">
                <svg viewBox="0 0 20 20"><path d="M3 2a1 1 0 011-1h12a1 1 0 011 1v16a1 1 0 01-1 1H4a1 1 0 01-1-1V2zm2 1v14h10V3H5zm2 2h2v2H7V5zm4 0h2v2h-2V5zM7 9h2v2H7V9zm4 0h2v2h-2V9zm-4 4h2v2H7v-2zm4 0h2v2h-2v-2z"/></svg>
            </div>
            <span class="brand-lbl">Sistem Kehadiran</span>
        </div>
        <div class="clock-row">
            <svg id="analogClock" width="68" height="68" viewBox="0 0 68 68">
                <defs><linearGradient id="faceGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#1A3560"/><stop offset="100%" stop-color="#0A1628"/></linearGradient></defs>
                <circle cx="34" cy="34" r="33" fill="none" stroke="rgba(201,168,76,0.4)" stroke-width="1.5"/>
                <circle cx="34" cy="34" r="31" fill="url(#faceGrad)"/>
                <circle cx="34" cy="34" r="29" fill="none" stroke="rgba(201,168,76,0.18)" stroke-width="0.8"/>
                <g id="ticks"></g>
                <line id="hourHand" x1="34" y1="34" x2="34" y2="19" stroke="#FAFBFF" stroke-width="3" stroke-linecap="round"/>
                <line id="minHand"  x1="34" y1="34" x2="34" y2="13" stroke="#FAFBFF" stroke-width="2" stroke-linecap="round"/>
                <line id="secHand"  x1="34" y1="39" x2="34" y2="10" stroke="#C9A84C" stroke-width="1.2" stroke-linecap="round"/>
                <circle cx="34" cy="34" r="3.2" fill="#C9A84C"/>
                <circle cx="34" cy="34" r="1.4" fill="#081220"/>
            </svg>
            <div>
                <div class="clock-digital">
                    <span id="ch">--</span><span class="colon">:</span><span id="cm">--</span><span class="colon">:</span><span id="cs">--</span>
                </div>
                <div class="date-str" id="dateStr">Memuat...</div>
            </div>
        </div>
    </div>

    <div class="bd">
        <div class="status-zone">
            <div class="avatar-wrap" id="avatarWrap">
                <div class="avatar-ring"></div>
                <div class="avatar-ring-2"></div>
                <img src="assets/img/karakter-bbws2.png" alt="Karakter BBWS">
            </div>
            <div class="icon-ring" id="iconRing">
                <svg class="st-ico" id="stIco" viewBox="0 0 24 24" fill="none">
                    <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z" fill="#5A6A8A"/>
                </svg>
                <span class="orbit" id="orbit"></span>
            </div>
            <div>
                <div class="st-title" id="stTitle">Verifikasi Kehadiran</div>
                <p class="st-sub" id="stSub">Mengaktifkan GPS...</p>
            </div>
            <div class="pill" id="pill"><span class="pill-dot"></span><span id="pillTxt"></span></div>
            <div class="info-box" id="infoBox">
                <div class="ib-label">
                    <svg viewBox="0 0 24 24" fill="none"><path d="M12 6v6l4 2" stroke="#8A9BBE" stroke-width="1.8" stroke-linecap="round"/><circle cx="12" cy="12" r="10" stroke="#8A9BBE" stroke-width="1.8" fill="none"/></svg>
                    Jam Pulang
                </div>
                <div class="ib-val" id="infoJamPulang">--:--</div>
                <div class="ib-sub" id="infoSisa">Silakan kembali saat waktunya</div>
            </div>
            <div class="done-box" id="doneBox">
                <div class="db-row">
                    <div class="db-col"><div class="db-label">Masuk</div><div class="db-val" id="doneJamMasuk">--:--</div></div>
                    <div class="db-div"></div>
                    <div class="db-col"><div class="db-label">Pulang</div><div class="db-val" id="doneJamPulang">--:--</div></div>
                </div>
                <div class="db-note">Kehadiran hari ini sudah tercatat lengkap. Sampai jumpa besok!</div>
            </div>
        </div>
        <div class="prog-track" id="progTrack"><div class="prog-fill" id="progFill"></div></div>
        <div class="sep" id="sep"></div>
        <!-- Tombol kembali ke halaman scan QR -->
        <a href="#" class="btn-dash" id="dashBtn">← Kembali ke Dashboard</a>
    </div>

    <div class="ft">
        <span class="ft-lbl">GPS Verified</span>
        <span class="ft-sec">
            <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5L12 1z"/></svg>
            Terenkripsi
        </span>
    </div>
</div>

<script>
/* ══════════════════════════════════════════════════════════
   BASE URL — dihitung dari posisi qr.php di filesystem
   qr.php ada di root project: /absensi-nonasn/qr.php
   Jadi BASE = /absensi-nonasn
══════════════════════════════════════════════════════════ */
const BASE = (function() {
    const parts = window.location.pathname.split('/');
    parts.pop(); // buang 'qr.php'
    return parts.join('/').replace(/\/$/, ''); // '/absensi-nonasn'
})();

// Buat URL absolut dari path relatif terhadap root project
function baseUrl(path) {
    return window.location.origin + BASE + '/' + path.replace(/^\//, '');
}

// ── Redirect ke dashboard dengan one-time QR token ───────────────────
// Token sudah di-generate saat PHP render halaman ini (60 detik valid)
const _QR_DASHBOARD_URL = baseUrl('pegawai/dashboard.php') + '?qr_from=<?php echo generate_qr_handoff_token(); ?>';
async function redirectToDashboard() {
    window.location.href = _QR_DASHBOARD_URL;
}

/* ══════════════════════════════════════════════════════════
   CLOCK — TICKS
══════════════════════════════════════════════════════════ */
(function(){
    const g=document.getElementById('ticks'), CX=34, CY=34;
    for(let i=0;i<60;i++){
        const isH=i%5===0;
        const a=(i/60)*2*Math.PI-Math.PI/2;
        const ln=document.createElementNS('http://www.w3.org/2000/svg','line');
        ln.setAttribute('x1',(CX+(isH?23:26)*Math.cos(a)).toFixed(2));
        ln.setAttribute('y1',(CY+(isH?23:26)*Math.sin(a)).toFixed(2));
        ln.setAttribute('x2',(CX+29*Math.cos(a)).toFixed(2));
        ln.setAttribute('y2',(CY+29*Math.sin(a)).toFixed(2));
        ln.setAttribute('stroke',isH?'rgba(201,168,76,0.75)':'rgba(255,255,255,0.15)');
        ln.setAttribute('stroke-width',isH?'1.8':'0.7');
        ln.setAttribute('stroke-linecap','round');
        g.appendChild(ln);
    }
})();

const HARI  = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
const BULAN = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

function rotateHand(el,deg,len,tail){
    const r=(deg-90)*Math.PI/180, CX=34, CY=34;
    el.setAttribute('x2',(CX+len*Math.cos(r)).toFixed(2));
    el.setAttribute('y2',(CY+len*Math.sin(r)).toFixed(2));
    if(tail){ const rT=(deg+90)*Math.PI/180; el.setAttribute('x1',(CX+tail*Math.cos(rT)).toFixed(2)); el.setAttribute('y1',(CY+tail*Math.sin(rT)).toFixed(2)); }
}

const hH=document.getElementById('hourHand'), mH=document.getElementById('minHand'), sH=document.getElementById('secHand');
function tick(){
    const n=new Date(), h=n.getHours(), m=n.getMinutes(), s=n.getSeconds(), ms=n.getMilliseconds();
    document.getElementById('ch').textContent=String(h).padStart(2,'0');
    document.getElementById('cm').textContent=String(m).padStart(2,'0');
    document.getElementById('cs').textContent=String(s).padStart(2,'0');
    document.getElementById('dateStr').textContent=`${HARI[n.getDay()]}, ${n.getDate()} ${BULAN[n.getMonth()]} ${n.getFullYear()}`;
    rotateHand(sH,(s+ms/1000)*6,23,5);
    rotateHand(mH,m*6+s*0.1,20);
    rotateHand(hH,(h%12)*30+m*0.5,14);
}
tick(); setInterval(tick,50);

/* ══════════════════════════════════════════════════════════
   ICON LIBRARY
══════════════════════════════════════════════════════════ */
const ICONS = {
    pin:     `<path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z" fill="#5A6A8A"/>`,
    pin_err: `<path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z" fill="#B52A2A"/>`,
    ok:      `<path fill-rule="evenodd" d="M20.3 6.3a1 1 0 0 1 0 1.4l-9 9a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L10.6 14.6l8.3-8.3a1 1 0 0 1 1.4 0z" fill="#0E7A5F"/>`,
    x:       `<path d="M18 6 6 18M6 6l12 12" stroke="#B52A2A" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>`,
    warn:    `<path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" stroke="#9A6200" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>`,
    lock:    `<rect x="3" y="11" width="18" height="11" rx="2" stroke="#B52A2A" stroke-width="2" fill="none"/><path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="#B52A2A" stroke-width="2" fill="none"/><circle cx="12" cy="16" r="1.5" fill="#B52A2A"/>`,
    time:    `<circle cx="12" cy="12" r="10" stroke="#9A6200" stroke-width="2" fill="none"/><path d="M12 6v6l4 2" stroke="#9A6200" stroke-width="2" stroke-linecap="round" fill="none"/>`,
    wifi:    `<path d="M1 9l2 2a9.97 9.97 0 0 1 14.1-.1L19 9A13 13 0 0 0 1 9zm4 4l2 2a5 5 0 0 1 7.05-.05L16 13a7 7 0 0 0-11 0zm5 5a2 2 0 1 1 4 0 2 2 0 0 1-4 0z" fill="#B52A2A"/>`,
    star:    `<path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" fill="#C9A84C"/>`,
};
function setIcon(k){ document.getElementById('stIco').innerHTML = ICONS[k] || ICONS.pin; }

/* ══════════════════════════════════════════════════════════
   DOM REFS
══════════════════════════════════════════════════════════ */
const iconRing   = document.getElementById('iconRing');
const avatarWrap = document.getElementById('avatarWrap');
const orbit      = document.getElementById('orbit');
const stTitle    = document.getElementById('stTitle');
const stSub      = document.getElementById('stSub');
const pill       = document.getElementById('pill');
const pillTxt    = document.getElementById('pillTxt');
const dashBtn    = document.getElementById('dashBtn');
const progTrack  = document.getElementById('progTrack');
const progFill   = document.getElementById('progFill');
const sep        = document.getElementById('sep');
const infoBox    = document.getElementById('infoBox');
const doneBox    = document.getElementById('doneBox');

/* ══════════════════════════════════════════════════════════
   UI HELPERS
══════════════════════════════════════════════════════════ */
function setProgress(p){ progTrack.classList.add('on'); progFill.style.width = p + '%'; }
function hideProgress(){ progTrack.classList.remove('on'); }
function stopOrbit()   { orbit.classList.add('off'); }
function setState(s)   { iconRing.className = `icon-ring ${s}`; stopOrbit(); }
function showPill(t,tx){ pill.className = `pill ${t} on`; pillTxt.textContent = tx; }
function showDash()    { sep.style.display = 'block'; dashBtn.classList.add('on'); }
function showAvatar()  { iconRing.classList.add('hidden'); avatarWrap.classList.add('show'); }

/* ══════════════════════════════════════════════════════════
   WEB AUDIO ENGINE
══════════════════════════════════════════════════════════ */
const _AC = new (window.AudioContext || window.webkitAudioContext)();
const _audioBuffers = {};

// Coba resume segera (berhasil jika sudah ada gesture dari scan.php)
_AC.resume().catch(() => {});

// Jika masih suspended, unlock saat ada interaksi apapun di halaman ini
function _unlockAC() {
    if (_AC.state !== 'running') {
        _AC.resume().catch(() => {});
    }
    document.removeEventListener('touchstart', _unlockAC);
    document.removeEventListener('click', _unlockAC);
}
document.addEventListener('touchstart', _unlockAC, { once: true });
document.addEventListener('click',      _unlockAC, { once: true });

async function _loadBuffer(name) {
    if (_audioBuffers[name]) return _audioBuffers[name];
    try {
        const r = await fetch(baseUrl(`assets/sounds/${name}.mp3`));
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        const buf = await _AC.decodeAudioData(await r.arrayBuffer());
        _audioBuffers[name] = buf;
        return buf;
    } catch(e) { console.warn(`⚠️ Gagal load audio "${name}":`, e.message); return null; }
}
// Pre-load semua suara segera agar buffer siap saat dibutuhkan
['success', 'warning', 'eror'].forEach(_loadBuffer);
['success', 'warning', 'eror'].forEach(_loadBuffer);

async function playSound(name, volume = 1.0) {
    try {
        // Pastikan AudioContext running sebelum play
        if (_AC.state === 'suspended') {
            await Promise.race([
                _AC.resume(),
                new Promise(r => setTimeout(r, 500)) // max tunggu 500ms
            ]);
        }
        if (_AC.state !== 'running') return; // tetap tidak bisa, skip
        const buffer = await _loadBuffer(name);
        if (!buffer) return;
        const src = _AC.createBufferSource();
        const gain = _AC.createGain();
        src.buffer = buffer;
        gain.gain.value = Math.min(1, Math.max(0, volume));
        src.connect(gain);
        gain.connect(_AC.destination);
        src.start(0);
    } catch(e) { console.error(`❌ playSound [${name}]:`, e); }
}
const playOk      = () => playSound('success');
const playErr     = () => playSound('eror');
const playWarning = () => playSound('warning');
const playInfo    = () => playSound('warning', 0.75);
function vibe(p){ try { navigator.vibrate && navigator.vibrate(p); } catch(_){} }

/* ══════════════════════════════════════════════════════════
   TOAST ENGINE
══════════════════════════════════════════════════════════ */
const TC = document.getElementById('toastContainer');
const TOAST_ICONS = {
    success: `<svg viewBox="0 0 24 24" fill="none"><path fill-rule="evenodd" d="M20.3 6.3a1 1 0 0 1 0 1.4l-9 9a1 1 0 0 1-1.4 0l-4-4a1 1 0 1 1 1.4-1.4L10.6 14.6l8.3-8.3a1 1 0 0 1 1.4 0z" fill="#fff"/></svg>`,
    error:   `<svg viewBox="0 0 24 24" fill="none"><path d="M18 6 6 18M6 6l12 12" stroke="#fff" stroke-width="2.5" stroke-linecap="round"/></svg>`,
    warning: `<svg viewBox="0 0 24 24" fill="none"><path d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>`,
    info:    `<svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#C9A84C" stroke-width="2"/><path d="M12 8v4m0 4h.01" stroke="#C9A84C" stroke-width="2" stroke-linecap="round"/></svg>`,
};
let _toastId = 0;
function showToast({ type='info', title, msg, duration=5000 }) {
    const id = ++_toastId;
    const now = new Date();
    const ts  = `${String(now.getHours()).padStart(2,'0')}:${String(now.getMinutes()).padStart(2,'0')}`;
    const el  = document.createElement('div');
    el.className  = `toast t-${type}`;
    el.dataset.id = id;
    el.innerHTML  = `
        <div class="toast-handle"></div>
        <div class="toast-icon">${TOAST_ICONS[type]||TOAST_ICONS.info}</div>
        <div class="toast-body">
            <div class="toast-title">${title}</div>
            ${msg ? `<div class="toast-msg">${msg}</div>` : ''}
        </div>
        <div class="toast-app">
            <span class="toast-app-name">Absensi</span>
            <span class="toast-time">${ts}</span>
        </div>
        <div class="toast-progress" style="animation-duration:${duration}ms"></div>
    `;
    el.addEventListener('click', () => dismissToast(el));
    let startY = 0;
    el.addEventListener('touchstart', e => { startY = e.touches[0].clientY; }, {passive:true});
    el.addEventListener('touchmove', e => {
        const dy = e.touches[0].clientY - startY;
        if(dy < -10){ el.style.transform=`translateY(${dy}px) scale(${Math.max(.92,1+dy/400)})`; el.style.opacity=String(Math.max(0,1+dy/100)); }
    }, {passive:true});
    el.addEventListener('touchend', e => {
        const dy = e.changedTouches[0].clientY - startY;
        if(dy < -40) dismissToast(el); else { el.style.transform=''; el.style.opacity=''; }
    });
    TC.appendChild(el);
    requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('show')));
    el._timer = setTimeout(() => dismissToast(el), duration);
    return el;
}
function dismissToast(el) {
    if (!el || el._dismissed) return;
    el._dismissed = true;
    clearTimeout(el._timer);
    el.classList.remove('show');
    el.classList.add('hide');
    setTimeout(() => el.remove(), 400);
}

/* ══════════════════════════════════════════════════════════
   SWEETALERT THEME
══════════════════════════════════════════════════════════ */
const _sw = document.createElement('style');
_sw.textContent = `
.swal2-container{backdrop-filter:blur(6px)!important}
.sp{border-radius:24px!important;border:1px solid rgba(201,168,76,.2)!important;box-shadow:0 40px 80px rgba(0,0,0,.45)!important;padding:2rem!important;font-family:'DM Sans',sans-serif!important;max-width:390px!important}
.st_{font-family:'Cormorant Garamond',serif!important;font-size:1.45rem!important;font-weight:700!important;color:#0A1628!important}
.sh_{font-size:.86rem!important;color:#5A6A8A!important}
.sc_{border-radius:10px!important;font-family:'DM Sans',sans-serif!important;font-weight:600!important;font-size:.88rem!important;padding:.75rem 2rem!important;letter-spacing:.04em!important;border:none!important}
.sc_:hover{transform:translateY(-1px)!important}
.swal2-icon.swal2-success{border-color:#0E7A5F!important}
.swal2-icon.swal2-success [class^=swal2-success-line]{background-color:#0E7A5F!important}
.swal2-icon.swal2-success .swal2-success-ring{border-color:rgba(14,122,95,.3)!important}
`;
document.head.appendChild(_sw);
const SW = {customClass:{popup:'sp',title:'st_',htmlContainer:'sh_',confirmButton:'sc_'}};

/* ══════════════════════════════════════════════════════════
   POPUP HELPERS
══════════════════════════════════════════════════════════ */
async function popupBerhasil(data) {
    const isPulang = data.type_absen === 'pulang';
    await Swal.fire({...SW,
        icon:'success',
        title: isPulang ? 'Absen Pulang Berhasil' : 'Absen Masuk Berhasil',
        background:'#FAFBFF', allowOutsideClick:false, allowEscapeKey:false,
        confirmButtonText:'Ke Dashboard', confirmButtonColor:'#0F2241',
        willOpen: () => { playOk(); vibe([80,60,120]); },
        html: isPulang
        ? `<div style="text-align:left;font-size:.86rem;color:#334155;line-height:1.75">
                <div style="background:#D1FAE5;border-radius:12px;padding:.85rem 1rem;margin-bottom:1rem;display:flex;align-items:center;gap:.6rem">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="#0E7A5F" stroke-width="2" stroke-linecap="round"/><path d="M22 4 12 14.01l-3-3" stroke="#0E7A5F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <div><strong style="color:#0A1628;font-size:.95rem">Kehadiran Hari Ini Lengkap!</strong><p style="margin:.2rem 0 0;font-size:.78rem;color:#065F46">Absen masuk & pulang sudah tercatat.</p></div>
                </div>
                <div style="display:flex;gap:.75rem">
                    <div style="flex:1;background:#F8FAFF;border:1px solid #CBD5E8;border-radius:12px;padding:.75rem;text-align:center"><div style="font-size:.68rem;color:#8A9BBE;font-weight:600;letter-spacing:.06em;text-transform:uppercase;margin-bottom:.2rem">Masuk</div><div style="font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:700;color:#0A1628">${data.jam_masuk??'--:--'}</div></div>
                    <div style="flex:1;background:#D1FAE5;border:1px solid #6EE7B7;border-radius:12px;padding:.75rem;text-align:center"><div style="font-size:.68rem;color:#0E7A5F;font-weight:600;letter-spacing:.06em;text-transform:uppercase;margin-bottom:.2rem">Pulang</div><div style="font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:700;color:#0A1628">${data.jam??'--:--'}</div></div>
                </div>
                <p style="margin:1rem 0 0;font-size:.76rem;color:#8A9BBE;text-align:center">Sampai jumpa besok!</p>
           </div>`
        : `<div style="text-align:left;font-size:.86rem;color:#334155;line-height:1.75">
                <p style="margin:0 0 1rem">Absen masuk pukul <strong style="color:#0A1628;font-size:.98rem">${data.jam??'--:--'}</strong> berhasil dicatat.</p>
                <div style="background:linear-gradient(135deg,#F8FAFF,#F0F4FF);border:1px solid #CBD5E8;border-left:3px solid #C9A84C;border-radius:0 12px 12px 0;padding:.85rem 1rem">
                    <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.25rem"><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#0F2241" stroke-width="2"/><path d="M12 6v6l4 2" stroke="#0F2241" stroke-width="2" stroke-linecap="round"/></svg><strong style="color:#0A1628;font-size:.84rem">Jam Pulang</strong></div>
                    <span style="font-size:1.1rem;font-weight:700;color:#0F2241;font-family:'Cormorant Garamond',serif">${data.jam_pulang_setting??'17:00'}</span>
                    <p style="margin:.3rem 0 0;font-size:.74rem;color:#8A9BBE">Scan QR kembali setelah jam tersebut untuk absen pulang.</p>
                </div>
           </div>`
    });
}

async function popupMenungguPulang(data) {
    const sisa    = data.sisa_menit ?? 0;
    const sisaStr = sisa >= 60 ? `${Math.floor(sisa/60)} jam ${sisa%60} menit` : `${sisa} menit`;
    await Swal.fire({...SW,
        icon:'info', title:'Belum Waktunya Pulang', background:'#F8FAFF',
        confirmButtonText:'Mengerti', confirmButtonColor:'#0F2241',
        html:`<div style="text-align:left;font-size:.86rem;color:#334155;line-height:1.75">
            <div style="background:linear-gradient(135deg,#EEF2FF,#E0E7FF);border:1px solid #CBD5E8;border-radius:12px;padding:.85rem 1rem;margin-bottom:1rem;display:flex;align-items:center;gap:.7rem">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" stroke="#0E7A5F" stroke-width="2" stroke-linecap="round"/><path d="M22 4 12 14.01l-3-3" stroke="#0E7A5F" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <div><strong style="color:#0A1628">Absen masuk sudah tercatat</strong><p style="margin:.2rem 0 0;font-size:.78rem;color:#5A6A8A">Pukul ${data.jam_masuk??'--:--'}</p></div>
            </div>
            <div style="background:#FEF9EC;border:1px solid #FCD34D;border-left:3px solid #F59E0B;border-radius:0 12px 12px 0;padding:.85rem 1rem">
                <div style="display:flex;align-items:center;gap:.4rem;margin-bottom:.25rem"><svg width="13" height="13" viewBox="0 0 24 24" fill="none"><circle cx="12" cy="12" r="10" stroke="#9A6200" stroke-width="2"/><path d="M12 6v6l4 2" stroke="#9A6200" stroke-width="2" stroke-linecap="round"/></svg><strong style="color:#92400E;font-size:.84rem">Jam Pulang</strong></div>
                <span style="font-size:1.1rem;font-weight:700;color:#7A5200;font-family:'Cormorant Garamond',serif">${data.jam_pulang_setting??'17:00'}</span>
                <p style="margin:.3rem 0 0;font-size:.74rem;color:#92400E">Masih ${sisaStr} lagi. Scan QR kembali setelah jam pulang.</p>
            </div>
        </div>`
    });
}

async function popupSudahLengkap(data) {
    await Swal.fire({...SW,
        title:'Kehadiran Sudah Lengkap', background:'#F0FDF4',
        confirmButtonText:'Ke Dashboard', confirmButtonColor:'#0E7A5F',
        allowOutsideClick:false, allowEscapeKey:false,
        html:`<div style="text-align:center;font-size:.86rem;color:#334155;line-height:1.75">
            <img src="${baseUrl('assets/img/karakter-bbws2.png')}" style="width:100px;margin:0 auto .5rem;display:block;filter:drop-shadow(0 4px 12px rgba(0,0,0,.2))">
            <p style="margin:0 0 1rem;color:#0A1628;font-weight:600">Terima kasih! Kehadiran hari ini sudah tercatat lengkap.</p>
            <div style="display:flex;gap:.75rem;margin-bottom:.5rem">
                <div style="flex:1;background:#F8FAFF;border:1px solid #CBD5E8;border-radius:12px;padding:.75rem;text-align:center"><div style="font-size:.68rem;color:#8A9BBE;font-weight:600;letter-spacing:.06em;text-transform:uppercase;margin-bottom:.2rem">Masuk</div><div style="font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:700;color:#0A1628">${data.jam_masuk??'--:--'}</div></div>
                <div style="flex:1;background:#D1FAE5;border:1px solid #6EE7B7;border-radius:12px;padding:.75rem;text-align:center"><div style="font-size:.68rem;color:#0E7A5F;font-weight:600;letter-spacing:.06em;text-transform:uppercase;margin-bottom:.2rem">Pulang</div><div style="font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:700;color:#0A1628">${data.jam_pulang??'--:--'}</div></div>
            </div>
            <p style="font-size:.76rem;color:#8A9BBE">Sampai jumpa besok!</p>
        </div>`
    });
}

/* ══════════════════════════════════════════════════════════
   AUTO-START
══════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function() {
    // Tombol dashboard sudah pakai _QR_DASHBOARD_URL yang sama
    dashBtn.href = _QR_DASHBOARD_URL;

    const cameFromScan  = sessionStorage.getItem('audio_ctx_resumed') === '1';
    const cameFromLogin = <?php echo (isset($_SESSION['qr_just_logged_in']) && $_SESSION['qr_just_logged_in']) ? 'true' : 'false'; ?>;
    <?php unset($_SESSION['qr_just_logged_in']); ?>

    if (!cameFromScan && !cameFromLogin) {
        // ✅ baseUrl() bukan path relatif
        window.location.replace(baseUrl('scan.php'));
        return;
    }

    sessionStorage.setItem('audio_ctx_resumed', '1');
    startAbsenFlow();
});

/* ══════════════════════════════════════════════════════════
   MAIN FLOW
══════════════════════════════════════════════════════════ */
function startAbsenFlow() {
    if (!navigator.geolocation) {
        setState('s-error'); setIcon('wifi');
        stTitle.textContent = 'GPS Tidak Tersedia';
        stSub.textContent   = 'Browser tidak mendukung geolokasi.';
        showPill('err','Tidak Didukung'); showDash();
        vibe([500]); playErr();
        return;
    }

    setProgress(20); stSub.textContent = 'Mendeteksi posisi GPS Anda...';

    navigator.geolocation.getCurrentPosition(
        async pos => {
            setProgress(70);
            const lat = pos.coords.latitude.toFixed(8);
            const lng = pos.coords.longitude.toFixed(8);
            stTitle.textContent = 'Lokasi Ditemukan';
            stSub.textContent   = 'Memverifikasi kehadiran...';
            await tryAbsen(lat, lng);
        },
        err => {
            setState('s-error'); hideProgress();
            vibe([500]); playErr();
            if (err.code === 1) {
                setIcon('lock');
                stTitle.textContent = 'Akses Ditolak';
                stSub.textContent   = 'Izin lokasi ditolak. Buka pengaturan browser dan izinkan akses lokasi.';
                showToast({ type:'error', title:'Akses Lokasi Ditolak', msg:'Buka pengaturan browser → izinkan akses lokasi.', duration:7000 });
            } else if (err.code === 3) {
                setIcon('time');
                stTitle.textContent = 'Waktu Habis';
                stSub.textContent   = 'GPS timeout. Pastikan sinyal aktif lalu coba lagi.';
                showToast({ type:'error', title:'GPS Timeout', msg:'Pastikan sinyal GPS aktif.', duration:7000 });
            } else {
                setIcon('wifi');
                stTitle.textContent = 'Lokasi Gagal';
                stSub.textContent   = 'Gagal mengambil lokasi GPS.';
                showToast({ type:'error', title:'Lokasi Gagal', msg:'Tidak dapat mendeteksi posisi GPS Anda.', duration:7000 });
            }
            showPill('err','Gagal'); showDash();
        },
        { enableHighAccuracy:true, timeout:15000, maximumAge:0 }
    );
}

/* ══════════════════════════════════════════════════════════
   FUNGSI ABSEN UTAMA
══════════════════════════════════════════════════════════ */
async function tryAbsen(lat, lng) {
    try {
        setProgress(88);
        const res = await fetch(baseUrl('api/proses_qr_absen.php'), {
            method : 'POST',
            headers: { 'Content-Type':'application/json' },
            body   : JSON.stringify({ latitude: lat, longitude: lng }),
        });

        if (res.status === 429) {
            setState('s-warning'); setIcon('time');
            stTitle.textContent = 'Mohon Tunggu';
            stSub.textContent   = 'Terlalu cepat, mencoba ulang dalam 3 detik...';
            showToast({ type:'warning', title:'Sedang diproses', msg:'Scan terlalu cepat, mencoba ulang...', duration:3500 });
            setTimeout(() => tryAbsen(lat, lng), 3200);
            return;
        }

        if (!res.ok) throw new Error(`Server Error ${res.status}. Hubungi admin.`);
        const ct = res.headers.get('content-type');
        if (!ct || !ct.includes('application/json')) {
            console.error(await res.text());
            throw new Error('Respon server tidak valid.');
        }

        const data = await res.json();
        setProgress(100); hideProgress(); stopOrbit();

        /* ── Login required ── */
        if (data.type === 'login_required') {
            try { sessionStorage.setItem('audio_ctx_resumed', '1'); } catch(e) {}
            // ✅ Pakai baseUrl() untuk kedua URL
            window.location.href = data.redirect
                || (baseUrl('login.php') + '?return=' + encodeURIComponent(baseUrl('qr.php')));
            return;
        }

        /* ── Sudah absen LENGKAP ── */
        if (data.type === 'done' || data.state === 'done') {
            showAvatar();
            stTitle.textContent = 'Sudah Absen Lengkap';
            stSub.textContent   = 'Kehadiran hari ini sudah tercatat sempurna.';
            showPill('ok','Lengkap Hari Ini');
            document.getElementById('doneJamMasuk').textContent  = data.jam_masuk  ?? '--:--';
            document.getElementById('doneJamPulang').textContent = data.jam_pulang ?? '--:--';
            doneBox.classList.add('show');
            showDash();
            playInfo(); vibe([60,40,60]);
            showToast({ type:'info', title:'Kehadiran Hari Ini Sudah Lengkap', msg:`Masuk ${data.jam_masuk??'--:--'} · Pulang ${data.jam_pulang??'--:--'}`, duration:4500 });
            await popupSudahLengkap(data);
            await redirectToDashboard();
            return;
        }

        /* ── Sudah masuk, belum waktunya pulang ── */
        if (data.type === 'waiting_pulang' || data.state === 'waiting_pulang') {
            setState('s-warning'); setIcon('time');
            stTitle.textContent = 'Sudah Absen Masuk';
            stSub.textContent   = 'Belum waktunya absen pulang.';
            showPill('wrn','Menunggu Jam Pulang');
            document.getElementById('infoJamPulang').textContent = data.jam_pulang_setting ?? '--:--';
            const sisa    = data.sisa_menit ?? 0;
            const sisaStr = sisa >= 60 ? `${Math.floor(sisa/60)} jam ${sisa%60} menit` : `${sisa} menit`;
            document.getElementById('infoSisa').textContent = `Scan kembali dalam ${sisaStr} lagi`;
            infoBox.classList.add('show');
            showDash();
            playWarning(); vibe([60,40,60]);
            showToast({ type:'warning', title:`Jam pulang ${data.jam_pulang_setting??'--:--'} · ${sisaStr} lagi`, msg:`Absen masuk ${data.jam_masuk??'--:--'} sudah tercatat. Scan QR kembali nanti.`, duration:6000 });
            await popupMenungguPulang(data);
            return;
        }

        /* ── Berhasil absen (masuk / pulang baru) ── */
        if (data.success) {
            const isPulang = data.state === 'done_pulang' || data.type_absen === 'pulang';
            if (isPulang) {
                showAvatar();
                stTitle.textContent = 'Absen Pulang Berhasil';
                stSub.textContent   = data.message;
                showPill('ok',`Pulang ${data.jam??'--:--'}`);
                document.getElementById('doneJamMasuk').textContent  = data.jam_masuk ?? '--:--';
                document.getElementById('doneJamPulang').textContent = data.jam ?? '--:--';
                doneBox.classList.add('show');
            } else {
                setState('s-success'); setIcon('ok');
                stTitle.textContent = 'Absen Masuk Berhasil';
                stSub.textContent   = data.message;
                showPill('ok',`Masuk ${data.jam??'--:--'}`);
                document.getElementById('infoJamPulang').textContent = data.jam_pulang_setting ?? '--:--';
                document.getElementById('infoSisa').textContent = 'Scan QR kembali saat jam pulang';
                infoBox.classList.add('show');
            }
            showToast({ type:'success', title: isPulang ? `Absen Pulang Tercatat — ${data.jam??'--:--'}` : `Absen Masuk Tercatat — ${data.jam??'--:--'}`, msg: isPulang ? 'Kehadiran hari ini sudah lengkap. Sampai jumpa besok!' : `Jam pulang: ${data.jam_pulang_setting??'17:00'}`, duration:5000 });
            data.jam_masuk = data.jam_masuk ?? data.jam;
            await popupBerhasil(data); // ← playOk() + vibe() dipanggil di dalam willOpen
            await redirectToDashboard();
            return;
        }

        /* ── Ditolak / Error ── */
        const msg_lc   = (data.message||'').toLowerCase();
        const isRadius = msg_lc.includes('radius') || msg_lc.includes('luar') || msg_lc.includes('jarak') || data.distance;
        const isLibur  = msg_lc.includes('libur');
        const isLokasi = msg_lc.includes('lokasi') || msg_lc.includes('koordinat');
        let errIcon = 'x', errTitle = 'Absen Ditolak', errPill = 'Ditolak';
        let toastTitle = 'Absen Ditolak', swalTitle = 'Absen Ditolak';
        if (isRadius) { errIcon='pin_err'; errTitle='Di Luar Area Kantor'; errPill='Lokasi Salah'; toastTitle='Di Luar Radius Kantor'; swalTitle='Di Luar Area Kantor'; }
        else if (isLibur) { errIcon='star'; errTitle='Hari Libur'; errPill='Libur'; toastTitle='Hari Ini Libur Nasional'; swalTitle='Hari Libur Nasional'; }
        else if (isLokasi) { errIcon='wifi'; errTitle='Lokasi Tidak Valid'; errPill='GPS Error'; toastTitle='Lokasi GPS Tidak Valid'; swalTitle='GPS Tidak Valid'; }
        setState('s-error'); setIcon(errIcon);
        stTitle.textContent = errTitle;
        stSub.textContent   = data.message || 'Gagal memproses absensi.';
        showPill('err', errPill); showDash();
        playErr(); vibe([500]);
        showToast({ type:'error', title:toastTitle, msg: isRadius && data.distance ? `Jarak Anda ${data.distance}m dari kantor.` : data.message||'Silakan hubungi admin.', duration:7000 });
        const swalHtml = isRadius
            ? `<div style="text-align:left;font-size:.86rem;color:#334155;line-height:1.75"><div style="background:#FEF2F2;border:1px solid #FECACA;border-radius:12px;padding:.85rem 1rem;margin-bottom:1rem;display:flex;align-items:center;gap:.75rem"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" style="flex-shrink:0"><circle cx="12" cy="12" r="10" stroke="#EF4444" stroke-width="1.5" stroke-dasharray="4 2"/><path d="M12 7c-2.21 0-4 1.79-4 4 0 3 4 7 4 7s4-4 4-7c0-2.21-1.79-4-4-4zm0 5.5A1.5 1.5 0 1 1 12 9a1.5 1.5 0 0 1 0 3z" fill="#EF4444"/></svg><div><strong style="color:#991B1B">Anda berada di luar area kantor</strong>${data.distance?`<p style="margin:.25rem 0 0;font-size:.78rem;color:#B91C1C">Jarak: <strong>${data.distance} meter</strong></p>`:''}</div></div><div style="background:#FEF2F2;border:1px solid #FECACA;border-left:3px solid #EF4444;border-radius:0 12px 12px 0;padding:.85rem 1rem"><p style="margin:0;font-size:.78rem;color:#991B1B">Absen hanya dapat dilakukan di dalam radius area kantor.</p></div></div>`
            : `<div style="text-align:left;font-size:.86rem;color:#334155;line-height:1.75"><p style="margin:0 0 1rem">${data.message}</p><div style="background:#FEF2F2;border:1px solid #FECACA;border-left:3px solid #EF4444;border-radius:0 12px 12px 0;padding:.85rem 1rem"><p style="margin:0;font-size:.76rem;color:#991B1B">Jika Anda merasa ini keliru, silakan hubungi administrator sistem.</p></div></div>`;
        await Swal.fire({...SW, icon:'error', title:swalTitle, background:'#FFFAFA', confirmButtonText:'Tutup', confirmButtonColor:'#B52A2A', html:swalHtml});

    } catch(err) {
        setState('s-error'); setIcon('wifi');
        stTitle.textContent = 'Koneksi Gagal';
        stSub.textContent   = err.message || 'Tidak dapat terhubung ke server.';
        hideProgress(); showPill('err','Error'); showDash();
        playErr(); vibe([500]);
        console.error(err);
        showToast({ type:'error', title:'Koneksi Bermasalah', msg:err.message||'Pastikan internet aktif lalu coba lagi.', duration:8000 });
        await Swal.fire({...SW, icon:'error', title:'Koneksi Bermasalah', background:'#FFFAFA', confirmButtonText:'Tutup',
            html:`<div style="text-align:left;font-size:.86rem;color:#334155;line-height:1.75"><p style="margin:0 0 1rem">${err.message||'Tidak dapat terhubung ke server.'}</p><div style="background:#FEF2F2;border:1px solid #FECACA;border-left:3px solid #EF4444;border-radius:0 12px 12px 0;padding:.85rem 1rem"><p style="margin:0;font-size:.76rem;color:#991B1B">Pastikan koneksi internet stabil.</p></div></div>`
        });
    }
}
</script>
</body>
</html>