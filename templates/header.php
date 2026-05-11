<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Sistem Absensi Non-ASN BBWS Citanduy — Pencatatan kehadiran pegawai Non-ASN Balai Besar Wilayah Sungai Citanduy berbasis QR Code dan GPS.">
    <meta name="keywords" content="absensi BBWS Citanduy, absensi non ASN, absen pegawai BBWS, sistem absensi BBWS Citanduy, kehadiran pegawai non ASN">
    <meta name="robots" content="index, follow">
    <meta name="author" content="BBWS Citanduy">
    <title><?= isset($page_title) ? $page_title . ' - ' : '' ?>Sistem Absensi Non ASN</title>

    <?php
    /**
     * Deteksi HTTPS yang benar — mencakup:
     * - HTTPS langsung (Apache/Nginx dengan SSL)
     * - Proxy/tunnel seperti ngrok, Cloudflare, reverse proxy
     *   yang meneruskan header X-Forwarded-Proto
     */
    if (!defined('BASE_URL')) {
        $is_https = false;

        // 1. HTTPS langsung dari server
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $is_https = true;
        }
        // 2. Dari proxy/tunnel (ngrok, Cloudflare, dll)
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
                && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            $is_https = true;
        }
        // 3. Port HTTPS standar
        elseif (!empty($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
            $is_https = true;
        }
        // 4. Deteksi dari Host header (ngrok selalu https)
        elseif (!empty($_SERVER['HTTP_HOST'])
                && str_contains($_SERVER['HTTP_HOST'], 'ngrok')) {
            $is_https = true;
        }

        $proto    = $is_https ? 'https' : 'http';
        $host     = $_SERVER['HTTP_HOST'];
        $base_dir = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/\\');

        // Kalau ada di root (base_dir = '' atau '/'), jangan tambah slash ganda
        if ($base_dir === '/' || $base_dir === '\\') {
            $base_dir = '';
        }

        define('BASE_URL', $proto . '://' . $host . $base_dir);
    }
    ?>

    <!-- PWA Manifest (inline data URI - kompatibel InfinityFree) -->
    <?php
    $manifest_data = json_encode([
        'name'             => 'Absensi Non-ASN BBWS Citanduy',
        'short_name'       => 'Absen BBWS',
        'description'      => 'Sistem Absensi Non-ASN Balai Besar Wilayah Sungai Citanduy',
        'start_url'        => rtrim($base_dir, '/') . '/login.php',
        'scope'            => rtrim($base_dir, '/') . '/',
        'display'          => 'standalone',
        'orientation'      => 'portrait',
        'background_color' => '#1e3a5f',
        'theme_color'      => '#1e3a5f',
        'icons'            => [
            ['src' => BASE_URL . '/assets/img/logo-instansi.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'],
            ['src' => BASE_URL . '/assets/img/logo-instansi.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'],
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $manifest_uri = 'data:application/manifest+json,' . rawurlencode($manifest_data);
    ?>
    <link rel="manifest" href="<?= $manifest_uri ?>">
    <meta name="theme-color" content="#1e3a5f">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Absen BBWS">
    <link rel="apple-touch-icon" href="<?= BASE_URL ?>/assets/img/logo-instansi.png">

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo-instansi.png">
    <link rel="shortcut icon" type="image/png" href="<?= BASE_URL ?>/assets/img/logo-instansi.png">

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"
          integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">

    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

    <!-- AOS Animation Library -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.css" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>

    <!-- Driver.js -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/driver.js/1.3.1/driver.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/driver.js/1.3.1/driver.js.iife.js"></script>

    <!-- Custom CSS — pakai BASE_URL yang sudah fix HTTPS -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="role-<?= $_SESSION['role'] ?? 'guest' ?>">