<?php
// Hapus semua output buffer sebelumnya (mencegah BOM / whitespace)
while (ob_get_level()) ob_end_clean();
ob_start();

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Content-Type-Options: nosniff');

$manifest = [
    "name" => "Absensi Non-ASN BBWS Citanduy",
    "short_name" => "Absen BBWS",
    "description" => "Sistem Absensi Non-ASN Balai Besar Wilayah Sungai Citanduy",
    "start_url" => "/absensi-nonasn/login.php",
    "scope" => "/absensi-nonasn/",
    "display" => "standalone",
    "orientation" => "portrait",
    "background_color" => "#1e3a5f",
    "theme_color" => "#1e3a5f",
    "icons" => [
        [
            "src" => "/absensi-nonasn/assets/img/logo-instansi.png",
            "sizes" => "192x192",
            "type" => "image/png",
            "purpose" => "any maskable"
        ],
        [
            "src" => "/absensi-nonasn/assets/img/logo-instansi.png",
            "sizes" => "512x512",
            "type" => "image/png",
            "purpose" => "any maskable"
        ]
    ]
];

$json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
ob_end_clean();
echo $json;
exit;
