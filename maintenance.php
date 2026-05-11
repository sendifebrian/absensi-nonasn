<?php
// Halaman ini bisa diakses siapa saja — tidak butuh session
// Digunakan saat maintenance mode aktif
$base_url = (function() {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $parts  = explode('/', trim($script, '/'));
    $proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $proto . '://' . $host . (isset($parts[0]) && $parts[0] !== '' ? '/' . $parts[0] : '');
})();

// Simpan halaman asal dari referer (untuk redirect balik setelah maintenance selesai)
// Jika tidak ada referer, default ke index
$redirect_to = $_GET['from'] ?? '';
// Whitelist: hanya path internal (bukan URL eksternal)
if (!empty($redirect_to) && !preg_match('/^\//', $redirect_to)) {
    $redirect_to = '';
}
$safe_redirect = !empty($redirect_to) ? htmlspecialchars($redirect_to) : ($base_url . '/index.php');
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Maintenance — Absensi Non-ASN BBWS Citanduy</title>
    <link rel="icon" type="image/png" href="<?= $base_url ?>/assets/img/logo-instansi.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:     #0A1628;
            --navy-mid: #0F2241;
            --navy-lit: #1A3560;
            --gold:     #C9A84C;
            --gold-lit: #E8C97A;
            --silver:   #8A9BBE;
            --white:    #FAFBFF;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body {
            width: 100%; height: 100%;
            font-family: 'DM Sans', sans-serif;
            background: var(--navy); overflow: hidden;
            -webkit-tap-highlight-color: transparent; user-select: none;
        }
        .bg-grid {
            position: fixed; inset: 0; z-index: 0;
            background-image:
                repeating-linear-gradient(0deg, transparent, transparent 39px, rgba(201,168,76,.03) 40px),
                repeating-linear-gradient(90deg, transparent, transparent 39px, rgba(201,168,76,.03) 40px);
        }
        .bg-glow {
            position: fixed; inset: 0; z-index: 0;
            background:
                radial-gradient(ellipse 60% 40% at 50% 0%, rgba(239,68,68,.07) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 50% 110%, rgba(26,53,96,.6) 0%, transparent 60%);
        }
        .orb { position: fixed; border-radius: 50%; filter: blur(90px); pointer-events: none; z-index: 0; }
        .orb-1 { width: 320px; height: 320px; background: #EF4444; opacity: .04; top: -80px; right: -60px; animation: drift 16s ease-in-out infinite alternate; }
        .orb-2 { width: 260px; height: 260px; background: var(--gold); opacity: .05; bottom: -60px; left: -60px; animation: drift 12s ease-in-out infinite alternate-reverse; }
        @keyframes drift { from { transform: translate(0,0) scale(1); } to { transform: translate(20px,15px) scale(1.08); } }

        .scene {
            position: relative; z-index: 1; width: 100%; height: 100%;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 2rem 1.5rem;
        }
        .logo-area {
            display: flex; flex-direction: column; align-items: center;
            gap: .65rem; margin-bottom: 2.5rem;
            animation: fadeUp .7s cubic-bezier(.22,1,.36,1) both;
        }
        .logo-shield {
            width: 64px; height: 64px; border-radius: 18px;
            background: linear-gradient(145deg, var(--navy-lit), var(--navy-mid));
            border: 1px solid rgba(201,168,76,.2);
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 8px 32px rgba(0,0,0,.4); position: relative;
        }
        .logo-shield::after {
            content: ''; position: absolute; inset: -1px; border-radius: 19px;
            background: linear-gradient(135deg, rgba(201,168,76,.25), transparent 50%); pointer-events: none;
        }
        .logo-shield svg { width: 30px; height: 30px; }
        .logo-name {
            font-family: 'Cormorant Garamond', serif;
            font-size: .72rem; font-weight: 600; color: var(--silver);
            letter-spacing: .2em; text-transform: uppercase;
        }

        /* Main block */
        .maint-wrap {
            display: flex; flex-direction: column; align-items: center; gap: 2rem;
            animation: fadeUp .7s .1s cubic-bezier(.22,1,.36,1) both;
        }
        .maint-icon-ring { position: relative; width: 160px; height: 160px; }
        .ring-outer { position: absolute; inset: -20px; border-radius: 50%; border: 1px solid rgba(239,68,68,.15); animation: ringPulse 3s ease-in-out infinite; }
        .ring-mid   { position: absolute; inset: -10px; border-radius: 50%; border: 1px solid rgba(239,68,68,.22); animation: ringPulse 3s .35s ease-in-out infinite; }
        @keyframes ringPulse { 0%,100%{transform:scale(1);opacity:.7} 50%{transform:scale(1.06);opacity:.2} }

        .maint-circle {
            position: absolute; inset: 0; border-radius: 50%;
            background: linear-gradient(145deg, #1C1428, var(--navy-mid));
            border: 1.5px solid rgba(239,68,68,.3);
            display: flex; align-items: center; justify-content: center;
            animation: maintBreath 4s ease-in-out infinite;
        }
        @keyframes maintBreath {
            0%,100%{box-shadow:0 0 0 0 rgba(239,68,68,.25),0 20px 60px rgba(0,0,0,.5)}
            50%{box-shadow:0 0 0 14px rgba(239,68,68,0),0 20px 60px rgba(0,0,0,.5)}
        }
        .gear-svg-bg { position: absolute; inset: 0; opacity: .07; animation: gearSpin 25s linear infinite; }
        @keyframes gearSpin { to { transform: rotate(360deg); } }

        .maint-icon-bg {
            width: 60px; height: 60px; border-radius: 50%;
            background: linear-gradient(145deg, rgba(239,68,68,.18), rgba(239,68,68,.06));
            border: 1px solid rgba(239,68,68,.25);
            display: flex; align-items: center; justify-content: center;
            animation: iconBob 3s ease-in-out infinite; z-index: 1;
        }
        @keyframes iconBob { 0%,100%{transform:translateY(0)} 50%{transform:translateY(-5px)} }
        .maint-icon-bg svg { width: 28px; height: 28px; color: #f87171; }

        .maint-text { text-align: center; }
        .maint-badge {
            display: inline-flex; align-items: center; gap: 6px;
            background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.28);
            color: #F87171; font-size: .6rem; font-weight: 700;
            letter-spacing: .14em; text-transform: uppercase;
            padding: .3rem .85rem; border-radius: 100px; margin-bottom: .85rem;
        }
        .badge-pulse { width: 5px; height: 5px; border-radius: 50%; background: #EF4444; animation: blink 1.3s ease-in-out infinite; }
        @keyframes blink { 0%,100%{opacity:1} 50%{opacity:.15} }
        .maint-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 2rem; font-weight: 700; line-height: 1.15;
            color: var(--white); margin-bottom: .55rem;
        }
        .maint-title em { color: var(--gold); font-style: italic; }
        .maint-desc {
            font-size: .8rem; color: var(--silver); line-height: 1.75;
            max-width: 300px; margin: 0 auto 1.5rem;
        }
        .progress-wrap {
            width: 260px; height: 3px; background: rgba(255,255,255,.07);
            border-radius: 100px; overflow: hidden; margin: 0 auto .85rem;
        }
        .progress-fill {
            height: 100%; background: linear-gradient(90deg, var(--gold), var(--gold-lit));
            border-radius: 100px;
            animation: progressAnim 3.5s ease-in-out infinite alternate;
        }
        @keyframes progressAnim { from{width:15%} to{width:80%} }

        .info-chips { display: flex; gap: .55rem; flex-wrap: wrap; justify-content: center; }
        .chip {
            display: flex; align-items: center; gap: .45rem;
            background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08);
            border-radius: 10px; padding: .5rem .8rem;
        }
        .chip svg { width: 14px; height: 14px; flex-shrink: 0; }
        .chip-label { font-size: .58rem; color: var(--silver); font-weight: 600; letter-spacing: .06em; text-transform: uppercase; display: block; margin-bottom: .08rem; }
        .chip-value { font-size: .73rem; color: var(--white); font-weight: 700; }
        .chip--amber svg { color: #FBBF24; }
        .chip--blue  svg { color: #60A5FA; }
        .chip--green svg { color: #34D399; }

        /* ── POLLING STATUS BAR ── */
        .poll-bar {
            position: fixed; bottom: 0; left: 0; right: 0; z-index: 10;
            display: flex; align-items: center; justify-content: center; gap: .5rem;
            padding: .6rem 1.2rem;
            background: rgba(10,22,40,.85); backdrop-filter: blur(12px);
            border-top: 1px solid rgba(255,255,255,.06);
            font-size: .62rem; color: var(--silver); letter-spacing: .06em;
            animation: fadeUp .8s .4s cubic-bezier(.22,1,.36,1) both;
        }
        .poll-dot {
            width: 6px; height: 6px; border-radius: 50%;
            background: #34D399; flex-shrink: 0;
            animation: pollPulse 2s ease-in-out infinite;
        }
        .poll-dot--checking { background: #FBBF24; animation: none; }
        .poll-dot--error    { background: #EF4444; animation: none; }
        @keyframes pollPulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.4;transform:scale(.7)} }

        /* ── RESUME OVERLAY ── */
        .resume-overlay {
            display: none;
            position: fixed; inset: 0; z-index: 20;
            background: rgba(10,22,40,.92); backdrop-filter: blur(20px);
            flex-direction: column; align-items: center; justify-content: center;
            gap: 1.5rem; text-align: center;
            animation: fadeIn .5s ease;
        }
        .resume-overlay.show { display: flex; }
        @keyframes fadeIn { from{opacity:0} to{opacity:1} }
        .resume-icon {
            width: 72px; height: 72px; border-radius: 50%;
            background: linear-gradient(145deg, rgba(34,197,94,.2), rgba(34,197,94,.08));
            border: 1.5px solid rgba(34,197,94,.4);
            display: flex; align-items: center; justify-content: center;
            animation: resumePop .5s .1s cubic-bezier(.34,1.56,.64,1) both;
        }
        @keyframes resumePop { from{opacity:0;transform:scale(.5)} to{opacity:1;transform:scale(1)} }
        .resume-icon svg { width: 34px; height: 34px; color: #34D399; }
        .resume-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.8rem; font-weight: 700; color: var(--white);
        }
        .resume-title em { color: #34D399; font-style: italic; }
        .resume-sub { font-size: .8rem; color: var(--silver); line-height: 1.7; }
        .resume-spinner {
            width: 36px; height: 36px; border-radius: 50%;
            border: 3px solid rgba(52,211,153,.2);
            border-top-color: #34D399;
            animation: spin .8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .resume-countdown {
            display: inline-flex; align-items: center; gap: .5rem;
            background: rgba(52,211,153,.1); border: 1px solid rgba(52,211,153,.25);
            border-radius: 100px; padding: .35rem 1rem;
            font-size: .72rem; color: #34D399; font-weight: 600;
        }

        .footer {
            position: fixed; bottom: 2.4rem;
            display: flex; align-items: center; gap: .4rem;
            font-size: .6rem; color: rgba(138,155,190,.3);
            letter-spacing: .12em; text-transform: uppercase;
            animation: fadeUp .8s .3s cubic-bezier(.22,1,.36,1) both;
        }
        @keyframes fadeUp { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

        @media (max-width: 400px) {
            .maint-title { font-size: 1.65rem; }
            .maint-icon-ring { width: 130px; height: 130px; }
        }
    </style>
</head>
<body>
<div class="bg-grid"></div>
<div class="bg-glow"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="scene">
    <div class="logo-area">
        <div class="logo-shield">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5L12 1z" fill="var(--gold)" opacity=".9"/>
            </svg>
        </div>
        <div class="logo-name">BBWS Citanduy</div>
    </div>

    <div class="maint-wrap">
        <div class="maint-icon-ring">
            <svg class="gear-svg-bg" viewBox="0 0 160 160" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="80" cy="80" r="70" stroke="white" stroke-width="1"/>
                <circle cx="80" cy="80" r="50" stroke="white" stroke-width="1"/>
                <circle cx="80" cy="80" r="28" stroke="white" stroke-width="1"/>
            </svg>
            <div class="ring-outer"></div>
            <div class="ring-mid"></div>
            <div class="maint-circle">
                <div class="maint-icon-bg">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>
                    </svg>
                </div>
            </div>
        </div>

        <div class="maint-text">
            <div class="maint-badge">
                <span class="badge-pulse"></span>
                Sedang Maintenance
            </div>
            <h1 class="maint-title">Sistem Sedang<br><em>Diperbaiki</em></h1>
            <p class="maint-desc">
                Sistem absensi Non-ASN BBWS Citanduy sedang dalam proses pemeliharaan.
                Mohon bersabar, kami akan segera kembali.
            </p>
            <div class="progress-wrap"><div class="progress-fill"></div></div>
            <div class="info-chips">
                <div class="chip chip--amber">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.07 4.93a10 10 0 0 1 1.93 15.14M4.93 4.93A10 10 0 0 0 3 12a10 10 0 0 0 3.93 8.07"/>
                        <path d="M14.12 2.07a10 10 0 0 1 5.81 7.79M9.88 2.07A10 10 0 0 0 4.07 9.86"/>
                    </svg>
                    <div>
                        <span class="chip-label">Status</span>
                        <span class="chip-value">On Progress</span>
                    </div>
                </div>
                <div class="chip chip--blue">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                    <div>
                        <span class="chip-label">Unit</span>
                        <span class="chip-value">BBWS Citanduy</span>
                    </div>
                </div>
                <div class="chip chip--green">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12 19.79 19.79 0 0 1 1.63 3.49 2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 6 6l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21.73 16.92z"/>
                    </svg>
                    <div>
                        <span class="chip-label">Hubungi</span>
                        <span class="chip-value">Admin IT</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Resume overlay — muncul saat maintenance sudah dimatikan -->
<div class="resume-overlay" id="resumeOverlay">
    <div class="resume-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/>
            <polyline points="22 4 12 14.01 9 11.01"/>
        </svg>
    </div>
    <div>
        <div class="resume-title">Sistem Kembali <em>Online</em></div>
    </div>
    <p class="resume-sub">Maintenance selesai. Anda akan diarahkan kembali<br>ke halaman semula secara otomatis.</p>
    <div class="resume-countdown" id="resumeCountdown">
        <div class="resume-spinner"></div>
        Mengalihkan dalam <strong id="cdNum">3</strong> detik…
    </div>
</div>

<!-- Polling status bar (bawah layar) -->
<div class="poll-bar" id="pollBar">
    <span class="poll-dot" id="pollDot"></span>
    <span id="pollText">Memantau status sistem…</span>
</div>

<div class="footer">
    <svg width="9" height="9" viewBox="0 0 24 24" fill="rgba(201,168,76,.5)">
        <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5L12 1z"/>
    </svg>
    Sistem Absensi Non-ASN &mdash; Kementerian PUPR
</div>

<script>
/* ═══════════════════════════════════════════════════════
   REAL-TIME MAINTENANCE POLLING
   ─ Cek API setiap 5 detik
   ─ Jika maintenance = false → tampil overlay → redirect
═══════════════════════════════════════════════════════ */

const API_URL     = '<?= $base_url ?>/api/check_maintenance.php';
const REDIRECT_TO = '<?= $safe_redirect ?>';
const POLL_MS     = 5000;   // interval cek: 5 detik

const pollDot  = document.getElementById('pollDot');
const pollText = document.getElementById('pollText');
let   pollTimer = null;
let   failCount = 0;
let   done      = false;

function setBar(state, text) {
    pollDot.className = 'poll-dot' + (state === 'checking' ? ' poll-dot--checking' : state === 'error' ? ' poll-dot--error' : '');
    pollText.textContent = text;
}

async function checkStatus() {
    if (done) return;

    setBar('checking', 'Memeriksa status sistem…');

    try {
        const res  = await fetch(API_URL + '?t=' + Date.now(), { cache: 'no-store' });
        const data = await res.json();
        failCount  = 0;

        if (!data.maintenance) {
            // Maintenance sudah dimatikan!
            done = true;
            clearInterval(pollTimer);
            showResumeOverlay();
        } else {
            // Masih maintenance
            const now = new Date();
            setBar('ok', 'Masih maintenance — dicek lagi pukul ' + now.toLocaleTimeString('id-ID', {hour:'2-digit',minute:'2-digit',second:'2-digit'}));
        }
    } catch (err) {
        failCount++;
        if (failCount >= 3) {
            setBar('error', 'Gagal terhubung ke server (' + failCount + 'x) — mencoba kembali…');
        } else {
            setBar('error', 'Koneksi terputus — mencoba kembali…');
        }
    }
}

function showResumeOverlay() {
    const overlay  = document.getElementById('resumeOverlay');
    const cdNum    = document.getElementById('cdNum');
    overlay.classList.add('show');

    let count = 3;
    const tick = setInterval(() => {
        count--;
        cdNum.textContent = count;
        if (count <= 0) {
            clearInterval(tick);
            window.location.replace(REDIRECT_TO);
        }
    }, 1000);
}

// Mulai polling
checkStatus(); // langsung cek sekali saat load
pollTimer = setInterval(checkStatus, POLL_MS);

// Visibility API: cek segera saat tab kembali aktif
document.addEventListener('visibilitychange', () => {
    if (!document.hidden && !done) {
        clearInterval(pollTimer);
        checkStatus();
        pollTimer = setInterval(checkStatus, POLL_MS);
    }
});
</script>
</body>
</html>
