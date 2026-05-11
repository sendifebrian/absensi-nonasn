<?php
require_once 'config/init.php';

// ── Base URL (harus SEBELUM dipakai) ─────────────────────────
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
            str_contains($_SERVER['HTTP_HOST'] ?? '', 'ngrok');

$base_path = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$base_url  = ($is_https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $base_path;

// ── Helper normalisasi URL ────────────────────────────────────
function normalize_return_url(?string $url, string $base): ?string {
    if (!$url) return null;

    // Sudah URL absolut (http/https) — kembalikan apa adanya
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url;
    }

    $scheme_host = parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST);
    $base_path   = rtrim(parse_url($base, PHP_URL_PATH) ?? '', '/');

    // Path absolut dimulai '/' — jangan tambahkan base_path lagi
    // Contoh: $url='/absensi-nonasn/qr.php', $base_path='/absensi-nonasn'
    // → kembalikan scheme_host + url saja (tidak dobel)
    if (str_starts_with($url, '/')) {
        return $scheme_host . $url;
    }

    // Path relatif (misal: 'qr.php') — gabung dengan base lengkap
    return $scheme_host . $base_path . '/' . $url;
}

// ── Ambil parameter GET ───────────────────────────────────────
$return_url = normalize_return_url($_GET['return'] ?? null, $base_url);
$auto_lat   = $_GET['lat'] ?? null;
$auto_lng   = $_GET['lng'] ?? null;

// ── Cek session aktif — bedakan konteks QR vs Web ────────────
if (isset($_SESSION['user_id'])) {
    $is_qr_context = !empty($_GET['return']) && (
        str_contains($_GET['return'], 'qr.php') ||
        str_contains($_GET['return'], 'scan.php')
    );

    if ($is_qr_context) {
        // Akses dari konteks QR
        if (($_SESSION['login_via'] ?? '') === 'qr' && ($_SESSION['sess_context'] ?? '') === 'qr') {
            // Sudah login QR → langsung ke tujuan
            $target = normalize_return_url($_GET['return'], $base_url) ?? ($base_url . '/qr.php');
            header('Location: ' . $target);
            exit();
        }
        // login_via = 'web' atau tidak ada sess_context qr → tampilkan form login QR
    } else {
        // Akses dari konteks Web — HANYA auto-redirect jika sess_context='web'
        // Jika sess_context='qr' atau tidak ada → WAJIB tampilkan form login web
        if (($_SESSION['login_via'] ?? '') === 'web' && ($_SESSION['sess_context'] ?? '') === 'web') {
            $target = $_SESSION['role'] === 'admin'
                ? $base_url . '/admin/dashboard.php'
                : $base_url . '/pegawai/dashboard.php';
            header('Location: ' . $target);
            exit();
        }

        // Session ada tapi bukan web context → bersihkan, jangan auto-redirect
        // Cek web remember cookie saja (bukan dari session QR yang bocor)
        if (empty($_SESSION['login_via']) || $_SESSION['login_via'] !== 'web') {
            // Hancurkan session yang tidak relevan ini
            session_unset(); session_destroy(); session_start();
        }

        // Coba restore dari web remember cookie
        if (!isset($_SESSION['user_id']) && !empty($_COOKIE['web_remember']) && !empty($_COOKIE['web_token'])) {
            if (restore_web_session($pdo)) {
                $target = $_SESSION['role'] === 'admin'
                    ? $base_url . '/admin/dashboard.php'
                    : $base_url . '/pegawai/dashboard.php';
                header('Location: ' . $target);
                exit();
            }
        }
        // Tidak ada web session valid → tampilkan form login
    }
}

// ── Proses POST ───────────────────────────────────────────────
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username   = trim($_POST['username'] ?? '');
    $password   = $_POST['password']      ?? '';
    $remember   = isset($_POST['remember']);
    $return_url = normalize_return_url($_POST['return_url'] ?? $_GET['return'] ?? null, $base_url);
    $auto_lat   = $_POST['auto_lat'] ?? $_GET['lat'] ?? null;
    $auto_lng   = $_POST['auto_lng'] ?? $_GET['lng'] ?? null;

    // QR login = return_url mengarah ke qr.php atau scan.php
    $is_qr_login = !empty($return_url) && (
        str_contains($return_url, 'qr.php') ||
        str_contains($return_url, 'scan.php')
    );

    if ($username && $password) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'aktif'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {

            // Blokir admin akses QR
            if ($user['role'] === 'admin' && $is_qr_login) {
                session_unset();
                session_destroy();
                header('Location: ' . $base_url . '/login.php?error=admin_qr');
                exit();
            }

            // Bersihkan session lama sepenuhnya sebelum set data baru
            // Ini penting agar came_from_qr / data user lama tidak bocor
            session_unset();
            session_regenerate_id(true);

            // Set session
            $_SESSION['user_id']       = $user['id'];
            $_SESSION['username']      = $user['username'];
            $_SESSION['nama']          = $user['nama'];
            $_SESSION['role']          = $user['role'];
            $_SESSION['device_hash']   = md5($_SERVER['HTTP_USER_AGENT'] ?? '');
            $_SESSION['login_via']     = $is_qr_login ? 'qr' : 'web';
            $_SESSION['sess_context']  = $is_qr_login ? 'qr' : 'web';
            $_SESSION['came_from_qr']  = false;
            $_SESSION['last_activity'] = time();

            $cookieOpts = [
                'path'     => '/',
                'secure'   => $is_https,
                'httponly' => true,
                'samesite' => $is_https ? 'None' : 'Lax',
                'expires'  => time() + 2592000,
            ];

            if ($is_qr_login) {
                // Set flag one-time untuk qr.php
                $_SESSION['qr_just_logged_in'] = true;
                $_SESSION['qr_fresh_request']  = true;

                // Set QR remember cookie otomatis (persisten di HP)
                $tok     = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                $pdo->prepare("UPDATE users SET remember_token = ?, remember_expires = ? WHERE id = ?")
                    ->execute([$tok, $expires, $user['id']]);
                setcookie('qr_remember', '1',  $cookieOpts);
                setcookie('qr_token',    $tok, $cookieOpts);

                // Redirect ke qr.php dengan flag audio siap
                $safe_url = htmlspecialchars($return_url, ENT_QUOTES);
                while (ob_get_level()) ob_end_clean();
                echo "<!DOCTYPE html><html><head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width,initial-scale=1'>
                </head><body>
                <script>
                    try { sessionStorage.setItem('audio_ctx_resumed','1'); } catch(e){}
                    window.location.replace('{$safe_url}');
                </script>
                </body></html>";
                exit();

            } else {
                // Web login — handle remember me
                $_SESSION['remember_me'] = $remember;
                if ($remember) {
                    $token   = bin2hex(random_bytes(32));
                    $expires = date('Y-m-d H:i:s', strtotime('+30 days'));
                    $pdo->prepare("UPDATE users SET web_remember_token = ?, web_remember_expires = ? WHERE id = ?")
                        ->execute([$token, $expires, $user['id']]);
                    setcookie('web_remember', '1',    $cookieOpts);
                    setcookie('web_token',    $token, $cookieOpts);
                } else {
                    // Hapus web cookie kalau tidak centang ingat saya
                    $del = array_merge($cookieOpts, ['expires' => time() - 3600]);
                    setcookie('web_remember', '', $del);
                    setcookie('web_token',    '', $del);
                }
                $target = $user['role'] === 'admin'
                    ? $base_url . '/admin/dashboard.php'
                    : $base_url . '/pegawai/dashboard.php';
                header('Location: ' . $target);
                exit();
            }

        } else {
            $error = 'Username atau password salah, atau akun tidak aktif.';
        }
    } else {
        $error = 'Username dan password wajib diisi.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <title>Masuk — Sistem Absensi Non-ASN BBWS Citanduy</title>
    <meta name="description" content="Login Sistem Absensi Non-ASN BBWS Citanduy. Masuk untuk mencatat kehadiran pegawai Non-ASN Balai Besar Wilayah Sungai Citanduy.">
    <meta name="keywords" content="login absensi BBWS, absen BBWS Citanduy, sistem absensi non ASN BBWS">
    <meta name="robots" content="index, follow">
    <meta name="author" content="BBWS Citanduy">
    <link rel="icon" type="image/png" href="assets/img/logo-instansi.png">
    <?php
    $manifest_data = json_encode([
        'name'             => 'Absensi Non-ASN BBWS Citanduy',
        'short_name'       => 'Absen BBWS',
        'description'      => 'Sistem Absensi Non-ASN Balai Besar Wilayah Sungai Citanduy',
        'start_url'        => $base_path . '/login.php',
        'scope'            => $base_path . '/',
        'display'          => 'standalone',
        'orientation'      => 'portrait',
        'background_color' => '#1e3a5f',
        'theme_color'      => '#1e3a5f',
        'icons'            => [
            ['src' => $base_url . '/assets/img/logo-instansi.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => $base_url . '/assets/img/logo-instansi.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $manifest_uri_login = 'data:application/manifest+json,' . rawurlencode($manifest_data);
    ?>
    <link rel="manifest" href="<?= $manifest_uri_login ?>">
    <meta name="theme-color" content="#1e3a5f">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Absen BBWS">
    <link rel="apple-touch-icon" href="assets/img/logo-instansi.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            --navy:     #152B52;
            --navy-mid: #1A3560;
            --navy-lit: #224070;
            --navy-xl:  #2A4D82;
            --gold:     #C9A84C;
            --gold-lit: #E8C97A;
            --gold-pale:#F5E9C8;
            --silver:   #8A9BBE;
            --ash:      #CBD5E8;
            --white:    #FAFBFF;
            --surface:  #F2F5FB;
            --txt:      #0A1628;
            --muted:    #5A6A8A;
            --border:   #DDE4F0;
            --ok:       #0E7A5F;
            --err:      #B52A2A;
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html, body { height: 100%; font-family: 'DM Sans', sans-serif; background: var(--navy); color: var(--txt); -webkit-font-smoothing: antialiased; }
        .layout { display: flex; min-height: 100vh; }

        /* LEFT PANEL */
        .panel-left { flex: 1; position: relative; display: none; overflow: hidden; }
        @media (min-width: 900px) { .panel-left { display: block; } }
        .panel-left .bg { position: absolute; inset: 0; background: linear-gradient(160deg, #0D1F3E 0%, #152B52 40%, #1A3560 70%, #1E3D6E 100%); }
        .panel-left .geo { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
        .panel-left .geo::before { content: ''; position: absolute; top: -20%; left: 55%; width: 1px; height: 140%; background: linear-gradient(to bottom, transparent 0%, rgba(201,168,76,.18) 30%, rgba(201,168,76,.08) 70%, transparent 100%); transform: rotate(12deg); }
        .panel-left .geo::after  { content: ''; position: absolute; top: -20%; left: 72%; width: 1px; height: 140%; background: linear-gradient(to bottom, transparent 0%, rgba(201,168,76,.08) 40%, transparent 100%); transform: rotate(12deg); }
        .panel-left .dots    { position: absolute; top: 60px; right: 50px; width: 110px; height: 110px; background-image: radial-gradient(circle, rgba(201,168,76,.65) 1.5px, transparent 1.5px); background-size: 18px 18px; opacity: .55; }
        .panel-left .dots-bl { position: absolute; bottom: 100px; left: 50px; width: 80px; height: 80px; background-image: radial-gradient(circle, rgba(201,168,76,.5) 1.5px, transparent 1.5px); background-size: 14px 14px; opacity: .45; }
        .panel-left .ring    { position: absolute; top: -120px; left: -120px; width: 500px; height: 500px; border-radius: 50%; border: 1px solid rgba(201,168,76,.14); }
        .panel-left .ring-2  { position: absolute; bottom: -80px; right: -80px; width: 320px; height: 320px; border-radius: 50%; border: 1px solid rgba(201,168,76,.11); }
        .panel-left .glow    { position: absolute; top: 30%; left: 10%; width: 300px; height: 300px; border-radius: 50%; background: radial-gradient(circle, rgba(201,168,76,.10) 0%, transparent 70%); }
        .panel-left .bg-img  { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; opacity: .32; mix-blend-mode: overlay; animation: slowzoom 25s ease-in-out infinite alternate; }
        @keyframes slowzoom { from{transform:scale(1.0)} to{transform:scale(1.08)} }
        .panel-left .content { position: relative; z-index: 2; height: 100%; display: flex; flex-direction: column; justify-content: space-between; padding: 52px 52px 52px 56px; }
        .panel-left .logo-wrap { display: flex; align-items: center; gap: 14px; animation: fadeUp .8s ease both; }
        .panel-left .logo-wrap img { width: 52px; height: 52px; object-fit: contain; filter: drop-shadow(0 4px 16px rgba(0,0,0,.5)); }
        .panel-left .logo-wrap .org { font-size: .72rem; font-weight: 600; color: var(--ash); letter-spacing: .14em; text-transform: uppercase; line-height: 1.5; }
        .panel-left .logo-wrap .org span { display: block; font-weight: 400; color: var(--silver); letter-spacing: .04em; text-transform: none; font-size: .68rem; }
        .panel-left .main-copy { animation: fadeUp .8s .1s ease both; }
        .panel-left .eyebrow { font-size: .7rem; font-weight: 600; letter-spacing: .2em; text-transform: uppercase; color: var(--gold); margin-bottom: 1rem; }
        .panel-left h1 { font-family: 'Cormorant Garamond', serif; font-size: clamp(2.2rem, 3.2vw, 3rem); font-weight: 700; color: var(--white); line-height: 1.15; margin-bottom: 1.1rem; }
        .panel-left h1 em { font-style: italic; color: var(--gold-lit); }
        .panel-left .gold-bar { width: 48px; height: 2px; background: linear-gradient(90deg, var(--gold), var(--gold-lit)); border-radius: 2px; margin-bottom: 1.25rem; }
        .panel-left p { font-size: .88rem; color: rgba(255,255,255,.62); line-height: 1.75; max-width: 320px; }
        .features { display: flex; flex-direction: column; gap: 14px; animation: fadeUp .8s .25s ease both; }
        .feat-item { display: flex; align-items: center; gap: 14px; }
        .feat-ico { width: 36px; height: 36px; border-radius: 10px; background: rgba(201,168,76,.1); border: 1px solid rgba(201,168,76,.2); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .feat-ico svg { width: 16px; height: 16px; }
        .feat-txt { font-size: .82rem; color: rgba(255,255,255,.72); line-height: 1.4; }
        .feat-txt strong { display: block; font-weight: 600; color: rgba(255,255,255,.88); margin-bottom: 1px; }
        .panel-left .credit { font-size: .68rem; color: rgba(255,255,255,.25); letter-spacing: .06em; animation: fadeUp .8s .35s ease both; }

        /* RIGHT PANEL */
        .panel-right { width: 100%; max-width: 500px; background: var(--white); display: flex; flex-direction: column; position: relative; overflow: hidden; animation: slideIn .65s cubic-bezier(.22,1,.36,1) both; }
        @media (min-width: 900px) { .panel-right { box-shadow: -24px 0 80px rgba(0,0,0,.35); } }
        @media (max-width: 899px) { .panel-right { max-width: 100%; background: transparent; } }
        @keyframes slideIn { from{opacity:0;transform:translateX(32px)} to{opacity:1;transform:translateX(0)} }
        @keyframes fadeUp  { from{opacity:0;transform:translateY(18px)} to{opacity:1;transform:translateY(0)} }
        .panel-right::before { content: ''; position: absolute; top: 0; right: 0; width: 200px; height: 200px; background: radial-gradient(circle at top right, rgba(201,168,76,.06) 0%, transparent 70%); pointer-events: none; }

        /* MOBILE HERO */
        .mobile-hero { display: none; background: linear-gradient(145deg, #152B52 0%, #1A3560 55%, #224070 100%); padding: 3rem 1.75rem 3.5rem; position: relative; overflow: hidden; flex-shrink: 0; }
        @media (max-width: 899px) { .mobile-hero { display: block; } }
        .mh-lines { position: absolute; inset: 0; overflow: hidden; pointer-events: none; }
        .mh-lines::before { content: ''; position: absolute; top: -40%; left: 60%; width: 1px; height: 180%; background: linear-gradient(to bottom, transparent, rgba(201,168,76,.2) 40%, rgba(201,168,76,.08) 70%, transparent); transform: rotate(15deg); }
        .mh-grid  { position: absolute; top: 16px; right: 16px; width: 100px; height: 100px; background-image: radial-gradient(circle, rgba(201,168,76,.5) 1.5px, transparent 1.5px); background-size: 16px 16px; opacity: .3; }
        .mh-ring  { position: absolute; top: -60px; right: -60px; width: 240px; height: 240px; border-radius: 50%; border: 1px solid rgba(201,168,76,.12); }
        .mh-ring-2{ position: absolute; bottom: -40px; left: -40px; width: 160px; height: 160px; border-radius: 50%; border: 1px solid rgba(201,168,76,.08); }
        .mh-inner { position: relative; z-index: 2; }
        .mh-logo-row { display: flex; align-items: center; gap: 12px; margin-bottom: 1.4rem; }
        .mh-logo-row img { width: 48px; height: 48px; object-fit: contain; filter: drop-shadow(0 4px 12px rgba(0,0,0,.4)); }
        .mh-logo-row .org-label { font-size: .68rem; font-weight: 600; color: var(--ash); letter-spacing: .15em; text-transform: uppercase; line-height: 1.5; }
        .mh-logo-row .org-label span { display: block; font-weight: 400; color: var(--silver); text-transform: none; letter-spacing: .04em; font-size: .65rem; }
        .mh-divider { width: 40px; height: 2px; background: linear-gradient(90deg, var(--gold), var(--gold-lit)); border-radius: 2px; margin-bottom: 1rem; }
        .mh-title { font-family: 'Cormorant Garamond', serif; font-size: 1.75rem; font-weight: 700; color: var(--white); line-height: 1.2; margin-bottom: .5rem; }
        .mh-title em { font-style: italic; color: var(--gold-lit); }
        .mh-sub { font-size: .78rem; color: rgba(255,255,255,.55); margin-bottom: 1.5rem; line-height: 1.6; }
        .mh-badges { display: flex; gap: 8px; flex-wrap: wrap; }
        .mh-badge { display: inline-flex; align-items: center; gap: 5px; background: rgba(255,255,255,.07); border: 1px solid rgba(201,168,76,.25); border-radius: 20px; padding: 5px 12px; font-size: .73rem; color: rgba(255,255,255,.82); }
        .mh-badge svg { width: 11px; height: 11px; fill: var(--gold-lit); flex-shrink: 0; }

        /* FORM AREA */
        .form-area { flex: 1; display: flex; flex-direction: column; justify-content: center; padding: 3rem 3.25rem; }
        @media (max-width: 899px) { .form-area { background: var(--white); border-radius: 28px 28px 0 0; margin-top: -28px; position: relative; z-index: 2; padding: 2.25rem 1.5rem 2.5rem; box-shadow: 0 -12px 48px rgba(0,0,0,.25); flex: 1; } }
        @media (max-width: 400px) { .form-area { padding: 2rem 1.25rem 2.25rem; } }
        .desk-topbar { display: none; align-items: center; gap: 12px; margin-bottom: 2.5rem; }
        @media (min-width: 900px) { .desk-topbar { display: flex; } }
        .desk-topbar img { width: 40px; height: 40px; object-fit: contain; }
        .desk-topbar .org { font-size: .72rem; font-weight: 600; color: var(--navy); letter-spacing: .1em; text-transform: uppercase; line-height: 1.5; }
        .desk-topbar .org span { display: block; font-weight: 400; color: var(--muted); text-transform: none; letter-spacing: 0; font-size: .68rem; }
        .form-heading { margin-bottom: 1.75rem; }
        .form-eyebrow { font-size: .7rem; font-weight: 600; letter-spacing: .18em; text-transform: uppercase; color: var(--gold); margin-bottom: .65rem; }
        .form-title { font-family: 'Cormorant Garamond', serif; font-size: 1.8rem; font-weight: 700; color: var(--navy); line-height: 1.15; margin-bottom: .4rem; }
        .form-sub { font-size: .82rem; color: var(--muted); line-height: 1.6; }
        .gold-rule { width: 44px; height: 2px; background: linear-gradient(90deg, var(--gold), var(--gold-lit)); border-radius: 2px; margin-bottom: 1.5rem; }

        /* QR mode notice */
        .qr-notice { display:flex; align-items:center; gap:10px; background:#F0F7FF; border:1px solid #BDD8F5; border-radius:10px; padding:11px 14px; margin-bottom:1.25rem; font-size:.8rem; color:#1A3A6B; }
        .qr-notice svg { width:16px; height:16px; fill:#3B82F6; flex-shrink:0; }

        .alert-err { display: flex; align-items: flex-start; gap: 10px; background: #FDF2F2; border: 1px solid #F8CACA; border-left: 3px solid var(--err); border-radius: 10px; padding: 12px 14px; margin-bottom: 1.25rem; font-size: .82rem; color: var(--err); animation: shake .4s ease; }
        .alert-err svg { width: 16px; height: 16px; fill: var(--err); flex-shrink: 0; margin-top: 1px; }
        @keyframes shake { 0%,100%{transform:translateX(0)} 20%,60%{transform:translateX(-5px)} 40%,80%{transform:translateX(5px)} }

        .field { margin-bottom: 1.1rem; }
        .field label { display: block; font-size: .8rem; font-weight: 600; color: var(--navy); margin-bottom: .55rem; letter-spacing: .02em; }
        .input-wrap { position: relative; }
        .input-wrap input { width: 100%; padding: .85rem 1rem .85rem 2.85rem; background: var(--surface); border: 1.5px solid var(--border); border-radius: 12px; font-family: 'DM Sans', sans-serif; font-size: .9rem; color: var(--txt); outline: none; transition: border-color .2s, box-shadow .2s, background .2s; -webkit-appearance: none; }
        .input-wrap input::placeholder { color: var(--ash); }
        .input-wrap input:hover { border-color: var(--silver); background: #EEF2FA; }
        .input-wrap input:focus { border-color: var(--gold); background: var(--white); box-shadow: 0 0 0 4px rgba(201,168,76,.1); }
        .input-wrap input.is-err { border-color: var(--err); box-shadow: 0 0 0 4px rgba(181,42,42,.08); }
        .input-ico { position: absolute; left: .9rem; top: 50%; transform: translateY(-50%); pointer-events: none; }
        .input-ico svg { width: 17px; height: 17px; }
        .toggle-eye { position: absolute; right: .75rem; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--silver); padding: 4px; border-radius: 6px; transition: color .2s, background .15s; display: flex; align-items: center; justify-content: center; }
        .toggle-eye:hover { color: var(--navy); background: rgba(0,0,0,.05); }
        .toggle-eye svg { width: 17px; height: 17px; }

        .remember-row { display: flex; align-items: center; justify-content: space-between; margin: .75rem 0 1.4rem; }
        .remember-check { display: flex; align-items: center; gap: 8px; cursor: pointer; }
        .remember-check input[type=checkbox] { width: 17px; height: 17px; border-radius: 5px; accent-color: var(--gold); cursor: pointer; flex-shrink: 0; }
        .remember-check span { font-size: .8rem; color: var(--muted); user-select: none; }

        .btn-submit { width: 100%; padding: .95rem 1.5rem; background: linear-gradient(135deg, var(--navy) 0%, var(--navy-lit) 100%); color: var(--white); border: none; border-radius: 12px; font-family: 'DM Sans', sans-serif; font-size: .9rem; font-weight: 600; letter-spacing: .04em; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px; box-shadow: 0 6px 24px rgba(10,22,40,.28); transition: all .25s ease; position: relative; overflow: hidden; }
        .btn-submit::before { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, transparent, rgba(201,168,76,.12), transparent); transform: translateX(-100%); transition: transform .55s ease; }
        .btn-submit:hover::before { transform: translateX(100%); }
        .btn-submit:hover { background: linear-gradient(135deg, var(--navy-mid) 0%, var(--navy-xl) 100%); box-shadow: 0 10px 32px rgba(10,22,40,.38); transform: translateY(-2px); }
        .btn-submit:active { transform: translateY(0); box-shadow: 0 4px 16px rgba(10,22,40,.2); }
        .btn-submit:disabled { background: var(--ash); box-shadow: none; cursor: not-allowed; transform: none; }
        .btn-submit svg { width: 18px; height: 18px; }
        .btn-submit .spin-ico { width: 18px; height: 18px; border: 2px solid rgba(255,255,255,.3); border-top-color: #fff; border-radius: 50%; animation: rotating .7s linear infinite; display: none; flex-shrink: 0; }
        @keyframes rotating { to { transform: rotate(360deg); } }

        .sec-note { display: flex; align-items: center; justify-content: center; gap: 6px; margin-top: 1.25rem; font-size: .72rem; color: var(--silver); }
        .sec-note svg { width: 12px; height: 12px; fill: var(--gold); }
        .form-footer { margin-top: 2rem; padding-top: 1.25rem; border-top: 1px solid var(--border); text-align: center; font-size: .72rem; color: var(--silver); line-height: 1.8; }
        .form-footer strong { color: var(--muted); font-weight: 500; }
        .forgot-link { font-size: .78rem; font-weight: 500; color: var(--gold); text-decoration: none; transition: color .2s, opacity .2s; opacity: .85; }
        .forgot-link:hover { color: var(--gold-lit); opacity: 1; }
    </style>
</head>
<body>
<div class="layout">

    <!-- LEFT PANEL -->
    <div class="panel-left">
        <div class="bg"></div>
        <div class="geo"></div>
        <div class="dots"></div>
        <div class="dots-bl"></div>
        <div class="ring"></div>
        <div class="ring-2"></div>
        <div class="glow"></div>
        <img src="assets/img/bg-instansi.jpg" alt="" class="bg-img" onerror="this.style.display='none'">
        <div class="content">
            <div class="logo-wrap">
                <img src="assets/img/logo-instansi.png" alt="Logo BBWS Citanduy" onerror="this.style.display='none'">
                <div class="org">BBWS Citanduy<span>Kementerian Pekerjaan Umum</span></div>
            </div>
            <div class="main-copy">
                <div class="eyebrow">Portal Kehadiran Digital</div>
                <h1>Absensi<br><em>Non-ASN</em><br>BBWS Citanduy</h1>
                <div class="gold-bar"></div>
                <p>Pencatatan kehadiran berbasis GPS secara real-time, akurat, dan terkelola untuk seluruh pegawai Non-ASN.</p>
            </div>
            <div class="features">
                <div class="feat-item">
                    <div class="feat-ico"><svg viewBox="0 0 24 24" fill="none"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z" fill="rgba(201,168,76,.9)"/></svg></div>
                    <div class="feat-txt"><strong>Validasi Lokasi GPS</strong>Hanya bisa absen dari area kantor</div>
                </div>
                <div class="feat-item">
                    <div class="feat-ico"><svg viewBox="0 0 24 24" fill="none"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16zm.5-13H11v6l5.2 3.1.8-1.3-4.5-2.7V7z" fill="rgba(201,168,76,.9)"/></svg></div>
                    <div class="feat-txt"><strong>Rekap Real-Time</strong>Data kehadiran langsung tercatat</div>
                </div>
                <div class="feat-item">
                    <div class="feat-ico"><svg viewBox="0 0 24 24" fill="none"><path d="M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11" stroke="rgba(201,168,76,.9)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></div>
                    <div class="feat-txt"><strong>Laporan & Export</strong>Rekap bulanan format PDF & Excel</div>
                </div>
                <div class="feat-item">
                    <div class="feat-ico"><svg viewBox="0 0 24 24" fill="none"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5L12 1z" fill="rgba(201,168,76,.9)"/></svg></div>
                    <div class="feat-txt"><strong>Aman & Terenkripsi</strong>Data dikelola admin instansi</div>
                </div>
            </div>
            <div class="credit">© <?= date('Y') ?> Balai Besar Wilayah Sungai Citanduy — Ditjen SDA</div>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="panel-right">
        <div class="mobile-hero">
            <div class="mh-lines"></div>
            <div class="mh-grid"></div>
            <div class="mh-ring"></div>
            <div class="mh-ring-2"></div>
            <div class="mh-inner">
                <div class="mh-logo-row">
                    <img src="assets/img/logo-instansi.png" alt="Logo" onerror="this.style.display='none'">
                    <div class="org-label">BBWS Citanduy<span>Kementerian Pekerjaan Umum</span></div>
                </div>
                <div class="mh-divider"></div>
                <div class="mh-title">Absensi <em>Non-ASN</em><br>BBWS Citanduy</div>
                <div class="mh-sub">Direktorat Jenderal Sumber Daya Air</div>
                <div class="mh-badges">
                    <span class="mh-badge"><svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6a2.5 2.5 0 0 1 0 5.5z"/></svg>GPS</span>
                    <span class="mh-badge"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm.5 5H11v6l5.2 3.1.8-1.3-4.5-2.7V7z"/></svg>Real-Time</span>
                    <span class="mh-badge"><svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5L12 1z"/></svg>Terenkripsi</span>
                </div>
            </div>
        </div>

        <div class="form-area">
            <div class="desk-topbar">
                <img src="assets/img/logo-instansi.png" alt="Logo" onerror="this.style.display='none'">
                <div class="org">BBWS Citanduy<span>Kementerian Pekerjaan Umum</span></div>
            </div>

            <div class="form-heading">
                <div class="form-eyebrow">Portal Pegawai</div>
                <div class="form-title">Selamat Datang</div>
                <div class="form-sub">Masuk untuk mencatat kehadiran Anda hari ini</div>
            </div>
            <div class="gold-rule"></div>

            <?php if (!empty($return_url) && (str_contains($return_url, 'qr.php') || str_contains($return_url, 'scan.php'))): ?>
            <div class="qr-notice">
                <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                <span>Login via QR — sesi akan tersimpan di perangkat ini secara otomatis.</span>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['timeout'])): ?>
            <div style="display:flex;align-items:flex-start;gap:10px;background:#FFFBEB;border:1px solid #FDE68A;border-left:3px solid #D97706;border-radius:10px;padding:12px 14px;margin-bottom:1.25rem;font-size:.82rem;color:#92400E;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="#D97706" style="flex-shrink:0;margin-top:1px;"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16zm.5-13H11v6l5.2 3.1.8-1.3-4.5-2.7V7z"/></svg>
                <span>Sesi Anda telah berakhir karena tidak aktif. Silakan masuk kembali.</span>
            </div>
            <?php endif; ?>

            <?php if (isset($_GET['error']) && $_GET['error'] === 'admin_qr'): ?>
            <div style="display:flex;align-items:flex-start;gap:10px;background:#FEF2F2;border:1px solid #FECACA;border-left:3px solid #DC2626;border-radius:10px;padding:12px 14px;margin-bottom:1.25rem;font-size:.82rem;color:#991B1B;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="#DC2626" style="flex-shrink:0;margin-top:1px;"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5L12 1z"/></svg>
                <div>
                    <strong style="display:block;margin-bottom:3px;">Akses QR Ditolak</strong>
                    Akun Admin tidak dapat login melalui QR absensi. Silakan login melalui link website.
                </div>
            </div>
            <?php endif; ?>

            <?php if ($error): ?>
            <div class="alert-err">
                <svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 0 20A10 10 0 0 0 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
            <?php endif; ?>

            <form method="POST" id="loginForm" novalidate>
                <?php csrf_field(); ?>
                <?php if ($return_url): ?>
                <input type="hidden" name="return_url" value="<?= htmlspecialchars($return_url) ?>">
                <?php endif; ?>
                <?php if ($auto_lat): ?>
                <input type="hidden" name="auto_lat" value="<?= htmlspecialchars($auto_lat) ?>">
                <?php endif; ?>
                <?php if ($auto_lng): ?>
                <input type="hidden" name="auto_lng" value="<?= htmlspecialchars($auto_lng) ?>">
                <?php endif; ?>

                <div class="field">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <span class="input-ico"><svg viewBox="0 0 24 24" fill="none"><circle cx="12" cy="8" r="4" stroke="#8A9BBE" stroke-width="1.8"/><path d="M4 20c0-4 3.58-7 8-7s8 3 8 7" stroke="#8A9BBE" stroke-width="1.8" stroke-linecap="round"/></svg></span>
                        <input type="text" id="username" name="username" placeholder="Masukkan username" autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <span class="input-ico"><svg viewBox="0 0 24 24" fill="none"><rect x="3" y="11" width="18" height="11" rx="2" stroke="#8A9BBE" stroke-width="1.8"/><path d="M7 11V7a5 5 0 0 1 10 0v4" stroke="#8A9BBE" stroke-width="1.8"/><circle cx="12" cy="16" r="1.5" fill="#8A9BBE"/></svg></span>
                        <input type="password" id="password" name="password" placeholder="Masukkan password" autocomplete="current-password" required>
                        <button type="button" class="toggle-eye" id="toggleEye" aria-label="Tampilkan password">
                            <svg id="eyeShow" viewBox="0 0 24 24" fill="none"><path d="M1 12C1 12 5 4 12 4s11 8 11 8-4 8-11 8S1 12 1 12z" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="1.8"/></svg>
                            <svg id="eyeHide" viewBox="0 0 24 24" fill="none" style="display:none"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24M1 1l22 22" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
                        </button>
                    </div>
                </div>

                <?php
                // Sembunyikan "ingat saya" jika login via QR (sudah otomatis ingat)
                $is_qr_form = !empty($return_url) && (
                    str_contains($return_url, 'qr.php') || str_contains($return_url, 'scan.php')
                );
                ?>
                <?php if (!$is_qr_form): ?>
                <div class="remember-row">
                    <label class="remember-check">
                        <input type="checkbox" id="remember" name="remember" value="1">
                        <span>Ingat saya 30 hari</span>
                    </label>
                    <a href="lupa_password.php" class="forgot-link">Lupa kata sandi?</a>
                </div>
                <?php endif; ?>

                <button type="submit" class="btn-submit" id="submitBtn">
                    <svg viewBox="0 0 24 24" fill="none" id="btnIco"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    <span id="btnLabel">Masuk</span>
                    <span class="spin-ico" id="spinIco"></span>
                </button>
            </form>

            <div class="sec-note">
                <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5L12 1z"/></svg>
                Koneksi terenkripsi & aman
            </div>
            <div class="form-footer">
                © <?= date('Y') ?> <strong>Balai Besar Wilayah Sungai Citanduy</strong><br>
                Direktorat Jenderal Sumber Daya Air — Kementerian PU
            </div>
        </div>
    </div>
</div>

<script>
const toggleEye = document.getElementById('toggleEye');
const passInput = document.getElementById('password');
toggleEye.addEventListener('click', () => {
    const isPass = passInput.type === 'password';
    passInput.type = isPass ? 'text' : 'password';
    document.getElementById('eyeShow').style.display = isPass ? 'none' : '';
    document.getElementById('eyeHide').style.display = isPass ? '' : 'none';
});

document.getElementById('loginForm').addEventListener('submit', function(e) {
    const u = document.getElementById('username');
    const p = document.getElementById('password');
    let ok = true;
    [u, p].forEach(el => el.classList.remove('is-err'));
    if (!u.value.trim()) { u.classList.add('is-err'); u.focus(); ok = false; }
    if (!p.value) { p.classList.add('is-err'); if (ok) p.focus(); ok = false; }
    if (!ok) { e.preventDefault(); return; }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    document.getElementById('btnLabel').textContent = 'Memproses...';
    document.getElementById('btnIco').style.display = 'none';
    document.getElementById('spinIco').style.display = 'block';
});

['username', 'password'].forEach(id => {
    document.getElementById(id)?.addEventListener('input', function() {
        this.classList.remove('is-err');
    });
});

document.getElementById('username').addEventListener('input', function() {
    const p = this.selectionStart;
    this.value = this.value.toLowerCase();
    this.setSelectionRange(p, p);
});

window.addEventListener('load', () => {
    const u = document.getElementById('username');
    if (!u.value) u.focus(); else document.getElementById('password').focus();
});

(function() {
    const triggers = [
        document.querySelector('button[type="submit"]'),
        document.querySelector('input[type="submit"]'),
        document.querySelector('form'),
    ].filter(Boolean);

    function markAudioReady() {
        try {
            sessionStorage.setItem('audio_ctx_resumed', '1');
        } catch(e) {}
    }

    triggers.forEach(el => {
        const evt = el.tagName === 'FORM' ? 'submit' : 'click';
        el.addEventListener(evt, markAudioReady, { once: true });
    });
})();
</script>
</body>
</html>