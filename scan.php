<?php
/**
 * scan.php — Halaman Perantara QR Scan
 * 
 * QR code di kantor mengarah ke halaman INI, bukan ke qr.php langsung.
 * 
 * Fungsi:
 * 1. Tampilkan UI "Tap untuk Absen" yang menarik
 * 2. Saat tap → unlock AudioContext (user gesture) + set sessionStorage
 * 3. Redirect otomatis ke qr.php (yang akan auto-absen + suara)
 * 
 * Jika user sudah pernah tap sebelumnya (sessionStorage ada),
 * langsung redirect ke qr.php tanpa tampilkan apapun.
 */
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Absensi — Tap untuk Mulai</title>
    <link rel="icon" type="image/png" href="assets/img/logo-instansi.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600;700&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:     #0A1628;
            --navy-mid: #0F2241;
            --navy-lit: #1A3560;
            --gold:     #C9A84C;
            --gold-lit: #E8C97A;
            --gold-dim: rgba(201,168,76,0.15);
            --silver:   #8A9BBE;
            --white:    #FAFBFF;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            width: 100%; height: 100%;
            font-family: 'DM Sans', sans-serif;
            background: var(--navy);
            overflow: hidden;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
        }

        /* ── BACKGROUND LAYERS ── */
        .bg-grid {
            position: fixed; inset: 0; z-index: 0;
            background-image:
                repeating-linear-gradient(0deg, transparent, transparent 39px, rgba(201,168,76,.03) 40px),
                repeating-linear-gradient(90deg, transparent, transparent 39px, rgba(201,168,76,.03) 40px);
        }

        .bg-glow {
            position: fixed; inset: 0; z-index: 0;
            background:
                radial-gradient(ellipse 70% 50% at 50% 0%, rgba(201,168,76,.12) 0%, transparent 60%),
                radial-gradient(ellipse 50% 60% at 50% 100%, rgba(26,53,96,.5) 0%, transparent 60%);
        }

        .orb {
            position: fixed; border-radius: 50%;
            filter: blur(90px); pointer-events: none; z-index: 0;
        }
        .orb-1 { width: 350px; height: 350px; background: var(--gold); opacity: .06; top: -80px; left: -80px; animation: drift 14s ease-in-out infinite alternate; }
        .orb-2 { width: 280px; height: 280px; background: #1A3560; opacity: .25; bottom: -60px; right: -60px; animation: drift 10s ease-in-out infinite alternate-reverse; }

        @keyframes drift { from { transform: translate(0,0) scale(1); } to { transform: translate(25px, 18px) scale(1.07); } }

        /* ── MAIN WRAPPER ── */
        .scene {
            position: relative; z-index: 1;
            width: 100%; height: 100%;
            display: flex; flex-direction: column;
            align-items: center; justify-content: center;
            padding: 2rem 1.5rem;
        }

        /* ── LOGO AREA ── */
        .logo-area {
            display: flex; flex-direction: column; align-items: center;
            gap: .75rem; margin-bottom: 3rem;
            animation: fadeUp .8s cubic-bezier(.22,1,.36,1) both;
        }

        .logo-shield {
            width: 72px; height: 72px; border-radius: 20px;
            background: linear-gradient(145deg, var(--navy-lit), var(--navy-mid));
            border: 1px solid rgba(201,168,76,.25);
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 8px 32px rgba(0,0,0,.4), 0 0 0 1px rgba(201,168,76,.08) inset;
            position: relative;
        }
        .logo-shield::after {
            content: '';
            position: absolute; inset: -1px;
            border-radius: 21px;
            background: linear-gradient(135deg, rgba(201,168,76,.3), transparent 50%);
            pointer-events: none;
        }
        .logo-shield svg { width: 34px; height: 34px; }

        .logo-name {
            font-family: 'Cormorant Garamond', serif;
            font-size: .75rem; font-weight: 600;
            color: var(--silver); letter-spacing: .2em;
            text-transform: uppercase;
        }

        /* ── TAP BUTTON — ELEMEN UTAMA ── */
        .tap-wrap {
            display: flex; flex-direction: column; align-items: center;
            gap: 2rem;
            animation: fadeUp .8s .1s cubic-bezier(.22,1,.36,1) both;
        }

        .tap-btn {
            position: relative;
            width: 200px; height: 200px;
            border-radius: 50%;
            background: none; border: none;
            cursor: pointer; outline: none;
            -webkit-tap-highlight-color: transparent;
        }

        /* Ring animasi luar */
        .ring-outer {
            position: absolute; inset: -24px;
            border-radius: 50%;
            border: 1.5px solid rgba(201,168,76,.18);
            animation: ringPulse 2.8s ease-in-out infinite;
        }
        .ring-mid {
            position: absolute; inset: -12px;
            border-radius: 50%;
            border: 1px solid rgba(201,168,76,.28);
            animation: ringPulse 2.8s .3s ease-in-out infinite;
        }

        @keyframes ringPulse {
            0%, 100% { transform: scale(1); opacity: .6; }
            50%       { transform: scale(1.05); opacity: .2; }
        }

        /* Lingkaran utama */
        .tap-circle {
            position: absolute; inset: 0;
            border-radius: 50%;
            background: linear-gradient(145deg, var(--navy-lit) 0%, var(--navy-mid) 100%);
            border: 1.5px solid rgba(201,168,76,.35);
            box-shadow:
                0 0 0 0 rgba(201,168,76,.4),
                0 24px 64px rgba(0,0,0,.5),
                inset 0 1px 0 rgba(201,168,76,.15);
            display: flex; align-items: center; justify-content: center;
            transition: transform .18s ease, box-shadow .18s ease;
            animation: breathe 3.5s ease-in-out infinite;
        }

        @keyframes breathe {
            0%, 100% { box-shadow: 0 0 0 0 rgba(201,168,76,.3), 0 24px 64px rgba(0,0,0,.5), inset 0 1px 0 rgba(201,168,76,.15); }
            50%       { box-shadow: 0 0 0 14px rgba(201,168,76,.0), 0 24px 64px rgba(0,0,0,.5), inset 0 1px 0 rgba(201,168,76,.15); }
        }

        .tap-btn:active .tap-circle {
            transform: scale(.93);
            box-shadow: 0 0 0 20px rgba(201,168,76,.0), 0 8px 32px rgba(0,0,0,.6), inset 0 1px 0 rgba(201,168,76,.15);
            animation: none;
        }

        /* Ikon di dalam lingkaran */
        .tap-inner-icon {
            display: flex; flex-direction: column; align-items: center; gap: .6rem;
        }
        .tap-finger {
            width: 52px; height: 52px;
            background: linear-gradient(145deg, var(--gold), var(--gold-lit));
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            box-shadow: 0 4px 16px rgba(201,168,76,.4);
            animation: iconBob 2.5s ease-in-out infinite;
        }
        @keyframes iconBob {
            0%, 100% { transform: translateY(0); }
            50%       { transform: translateY(-4px); }
        }
        .tap-finger svg { width: 26px; height: 26px; }

        .tap-label-inside {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1rem; font-weight: 700;
            color: var(--white); letter-spacing: .08em;
            text-shadow: 0 2px 8px rgba(0,0,0,.4);
        }

        /* Teks di bawah tombol */
        .tap-hint {
            text-align: center;
        }
        .tap-hint-main {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.5rem; font-weight: 700;
            color: var(--white);
            margin-bottom: .4rem;
            text-shadow: 0 2px 16px rgba(201,168,76,.15);
        }
        .tap-hint-sub {
            font-size: .8rem; color: var(--silver);
            line-height: 1.65; letter-spacing: .02em;
        }

        /* ── PROGRESS STATE (muncul setelah tap) ── */
        .progress-state {
            display: none;
            flex-direction: column; align-items: center;
            gap: 1.25rem; text-align: center;
            animation: fadeUp .5s cubic-bezier(.22,1,.36,1) both;
        }
        .progress-state.show { display: flex; }
        .tap-wrap.hide { display: none; }

        .spinner {
            width: 60px; height: 60px; border-radius: 50%;
            border: 3px solid rgba(201,168,76,.2);
            border-top-color: var(--gold);
            animation: spin .9s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        .progress-title {
            font-family: 'Cormorant Garamond', serif;
            font-size: 1.3rem; font-weight: 700; color: var(--white);
        }
        .progress-sub {
            font-size: .8rem; color: var(--silver); line-height: 1.6;
        }

        /* ── RIPPLE ── */
        .ripple {
            position: fixed; border-radius: 50%;
            background: rgba(201,168,76,.2);
            transform: scale(0); pointer-events: none;
            animation: rippleOut .7s ease-out forwards;
            z-index: 10;
        }
        @keyframes rippleOut { to { transform: scale(12); opacity: 0; } }

        /* ── FOOTER ── */
        .footer {
            position: fixed; bottom: 1.5rem;
            display: flex; align-items: center; gap: .4rem;
            font-size: .65rem; color: rgba(138,155,190,.5);
            letter-spacing: .12em; text-transform: uppercase;
            animation: fadeUp .8s .3s cubic-bezier(.22,1,.36,1) both;
        }
        .footer svg { width: 10px; height: 10px; fill: var(--gold); opacity: .6; }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body>

<div class="bg-grid"></div>
<div class="bg-glow"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="scene">

    <!-- Logo -->
    <div class="logo-area">
        <div class="logo-shield">
            <svg viewBox="0 0 24 24" fill="none">
                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5L12 1z" fill="var(--gold)" opacity=".9"/>
            </svg>
        </div>
        <div class="logo-name">Sistem Kehadiran</div>
    </div>

    <!-- TAP BUTTON — UI utama yang user lihat -->
    <div class="tap-wrap" id="tapWrap">
        <button class="tap-btn" id="tapBtn" aria-label="Tap untuk absen">
            <div class="ring-outer"></div>
            <div class="ring-mid"></div>
            <div class="tap-circle">
                <div class="tap-inner-icon">
                    <div class="tap-finger">
                        <!-- Ikon jari / touch -->
                        <svg viewBox="0 0 24 24" fill="none">
                            <path d="M9 11V6a2 2 0 0 1 4 0v5" stroke="var(--navy)" stroke-width="2" stroke-linecap="round"/>
                            <path d="M13 11V8a2 2 0 0 1 4 0v5l.01 1A5 5 0 0 1 12 19H9a5 5 0 0 1-5-5v-2a2 2 0 0 1 2-2h.5" stroke="var(--navy)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                        </svg>
                    </div>
                    <div class="tap-label-inside">TAP</div>
                </div>
            </div>
        </button>

        <div class="tap-hint">
            <div class="tap-hint-main">Tap untuk Absen</div>
            <div class="tap-hint-sub">
                Sentuh lingkaran di atas<br>untuk memulai verifikasi kehadiran
            </div>
        </div>
    </div>

    <!-- PROGRESS STATE — muncul setelah tap -->
    <div class="progress-state" id="progressState">
        <div class="spinner"></div>
        <div class="progress-title" id="progressTitle">Memproses...</div>
        <div class="progress-sub" id="progressSub">Menyiapkan sistem absensi</div>
    </div>

</div>

<div class="footer">
    <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5L12 1z"/></svg>
    GPS Terenkripsi
</div>

<script>
/* ══════════════════════════════════════════════════════════
   SCAN.PHP — SCRIPT UTAMA

   ALUR:
   1. Cek sessionStorage — jika audio sudah pernah unlock
      di sesi ini, langsung redirect ke qr.php tanpa tampil UI
   2. Jika belum → tampilkan UI "Tap untuk Absen"
   3. Saat user tap:
      a. Buat AudioContext + resume() ← ini user gesture yang sah
      b. Play suara klik kecil (konfirmasi ke user)
      c. Set sessionStorage flag
      d. Tampilkan animasi loading
      e. Redirect ke qr.php setelah 400ms
══════════════════════════════════════════════════════════ */

const DEST = 'qr.php'; // tujuan redirect setelah tap

/* ── Cek apakah perlu tampil UI atau langsung redirect ── */
const alreadyUnlocked = sessionStorage.getItem('audio_ctx_resumed') === '1';

if (alreadyUnlocked) {
    /* Sudah pernah tap sebelumnya di sesi ini → langsung ke qr.php */
    window.location.replace(DEST);
} else {
    /* Tampilkan UI tap — tunggu gesture user */
    initTapUI();
}

function initTapUI() {
    const tapBtn        = document.getElementById('tapBtn');
    const tapWrap       = document.getElementById('tapWrap');
    const progressState = document.getElementById('progressState');
    const progressTitle = document.getElementById('progressTitle');
    const progressSub   = document.getElementById('progressSub');

    let _fired = false;

    /* Handler tap — dipanggil sekali */
    async function onTap(e) {
        if (_fired) return;
        _fired = true;

        /* ── Ripple visual di posisi tap ── */
        const touch = e.changedTouches ? e.changedTouches[0] : e;
        const cx    = touch.clientX ?? window.innerWidth / 2;
        const cy    = touch.clientY ?? window.innerHeight / 2;
        const size  = Math.max(window.innerWidth, window.innerHeight) * .5;
        const r     = document.createElement('div');
        r.className = 'ripple';
        r.style.cssText = `width:${size}px;height:${size}px;left:${cx - size/2}px;top:${cy - size/2}px`;
        document.body.appendChild(r);
        setTimeout(() => r.remove(), 800);

        /* ── KUNCI UTAMA: Buat & resume AudioContext dalam gesture ini ── */
        try {
            const ac = new (window.AudioContext || window.webkitAudioContext)();
            await ac.resume();

            /*
             * Play suara klik kecil sebagai konfirmasi tap
             * Dibuat dengan Web Audio API (tidak perlu file .mp3)
             * sehingga tidak ada delay loading
             */
            const osc  = ac.createOscillator();
            const gain = ac.createGain();
            osc.connect(gain);
            gain.connect(ac.destination);
            osc.frequency.setValueAtTime(880, ac.currentTime);
            osc.frequency.exponentialRampToValueAtTime(440, ac.currentTime + .08);
            gain.gain.setValueAtTime(.25, ac.currentTime);
            gain.gain.exponentialRampToValueAtTime(.001, ac.currentTime + .12);
            osc.start(ac.currentTime);
            osc.stop(ac.currentTime + .12);

            /* ★ Simpan flag — qr.php akan baca ini ★ */
            sessionStorage.setItem('audio_ctx_resumed', '1');
            console.log('✅ AudioContext unlocked via tap on scan.php');

        } catch(err) {
            console.warn('AudioContext error:', err);
            /* Tetap lanjut meski audio gagal */
            sessionStorage.setItem('audio_ctx_resumed', '1');
        }

        /* ── Vibrate konfirmasi ── */
        try { navigator.vibrate && navigator.vibrate([40, 20, 40]); } catch(_) {}

        /* ── Transisi UI ke loading state ── */
        tapWrap.classList.add('hide');
        progressState.classList.add('show');
        progressTitle.textContent = 'Siap!';
        progressSub.textContent   = 'Membuka sistem absensi...';

        /* ── Redirect setelah animasi singkat ── */
        setTimeout(() => {
            window.location.replace(DEST);
        }, 400);
    }

    /* Daftarkan event — touchend untuk mobile, click untuk desktop */
    tapBtn.addEventListener('touchend', function(e) {
        e.preventDefault();
        onTap(e);
    }, { once: true, passive: false });

    tapBtn.addEventListener('click', function(e) {
        onTap(e);
    }, { once: true });

    /* Fallback: tap di mana saja di halaman */
    document.addEventListener('touchend', function(e) {
        if (!_fired) onTap(e);
    }, { once: true, passive: true });
}
</script>
</body>
</html>