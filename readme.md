# Sistem Absensi Non-ASN — BBWS Citanduy

Aplikasi web absensi untuk pegawai Non-ASN di lingkungan BBWS Citanduy, dikembangkan saat PKL di Unit Hidrologi dan Kualitas Air.

## Fitur

- Login pegawai & admin
- Absensi masuk & pulang via **QR Code** atau **Web (deteksi wajah)**
- Validasi lokasi GPS otomatis
- Mode kerja: WFO / WFH / WFA
- Pengajuan cuti & izin (dengan approval admin)
- Jadwal WFH & WFA per pegawai / unit kerja
- Rekap absensi bulanan
- Export PDF (rekap & data pegawai)
- Dashboard admin lengkap
- Halaman maintenance

## Teknologi

- PHP 8.1
- MySQL / MariaDB
- PDO (prepared statement)
- face-api.js (deteksi wajah & liveness)
- Bootstrap 5
- Leaflet.js (peta OpenStreetMap)

## Cara Menjalankan di Localhost

### Prasyarat
- [XAMPP](https://www.apachefriends.org/) (PHP 8.1+, MySQL, Apache)
- Browser modern (Chrome / Edge disarankan)

### Langkah Instalasi

**1. Clone atau download repo ini**
```
git clone https://github.com/sihka/absensi-nonasn.git
```
Atau download ZIP → ekstrak ke folder `C:\laragon\www\` atau `C:\xampp\htdocs\`

**2. Import database**

- Buka `http://localhost/phpmyadmin`
- Buat database baru, contoh: `absensi_nonasn`
- Klik **Import** → pilih file `database.sql` dari folder ini
- Klik **Go**

**3. Buat file konfigurasi database**

Buat file baru: `config/database.php`

```php
<?php
$host    = 'localhost';
$db      = 'absensi_nonasn';
$user    = 'root';
$pass    = '';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    error_log('DB Connection failed: ' . $e->getMessage());
    http_response_code(503);
    die('Koneksi database gagal.');
}
?>
```

> Sesuaikan `$user` dan `$pass` dengan pengaturan MySQL kamu. Default XAMPP biasanya user `root` dan password kosong.

**4. Buat folder uploads**

Pastikan folder berikut ada dan bisa ditulis:
```
uploads/selfie/
uploads/bukti/
sessions/qr/
sessions/web/
```

**5. Update Base URL di database**

Setelah import database, jalankan query ini di phpMyAdmin agar QR code mengarah ke URL yang benar:

- Buka `http://localhost/phpmyadmin`
- Pilih database `absensi_nonasn`
- Klik tab **SQL**
- Jalankan query berikut:

```sql
UPDATE settings SET base_url = 'http://localhost/absensi-nonasn' WHERE id = 1;
```

> Jika menggunakan ngrok, ganti nilainya dengan link ngrok kamu:
> ```sql
> UPDATE settings SET base_url = 'https://xxxx.ngrok-free.app/absensi-nonasn' WHERE id = 1;
> ```
> Tanpa langkah ini, QR code yang ter-generate tidak bisa discan dari HP karena masih mengarah ke URL lama.

**6. Akses aplikasi**

Buka browser dan akses:
```
http://localhost/absensi-nonasn/
```

> **Catatan:** Fitur kamera (absensi web & deteksi wajah) membutuhkan **HTTPS**. Di localhost, gunakan browser Chrome dan izinkan akses kamera saat diminta.

---

## Testing Fitur QR Code di Localhost (via ngrok)

Fitur absensi QR tidak bisa langsung ditest di localhost biasa, karena:
- HP pegawai tidak bisa akses `http://localhost/...` dari jaringan lain
- Browser HP memblokir kamera & GPS jika tidak ada HTTPS

Solusinya pakai **ngrok** — tool gratis yang membuat localhost kamu bisa diakses dari HP manapun via link HTTPS sementara.

### Langkah-langkah ngrok

**1. Download & install ngrok**

Buka [ngrok.com](https://ngrok.com) → Sign Up gratis → download ngrok untuk Windows → ekstrak file `ngrok.exe` ke folder manapun (misal `C:\ngrok\`)

**2. Daftarkan authtoken**

Setelah login di ngrok.com, buka menu **Your Authtoken** → copy token-nya.

Buka Command Prompt, jalankan:
```
ngrok config add-authtoken TOKEN_KAMU_DISINI
```

Cukup dilakukan sekali saja.

**3. Pastikan XAMPP / Laragon sudah jalan**

Apache dan MySQL harus aktif, dan aplikasi bisa diakses di `http://localhost/absensi-nonasn/`

**4. Jalankan ngrok**

Buka Command Prompt di folder ngrok, jalankan:
```
ngrok http 80
```

Atau kalau Laragon pakai port lain (misal 8080):
```
ngrok http 8080
```

**5. Salin link HTTPS dari ngrok**

Di terminal ngrok akan muncul seperti ini:
```
Forwarding  https://a1b2c3d4.ngrok-free.app -> http://localhost:80
```

Salin link `https://a1b2-....ngrok-free.app` — ini link sementara yang bisa diakses dari HP manapun.

**6. Akses aplikasi via link ngrok & ganti URL di pengaturan**

Di browser laptop, buka:
```
https://a1b2c3d4.ngrok-free.app/absensi-nonasn/
```

Login sebagai **admin** → buka menu **Pengaturan** → cari bagian **URL / Base URL** → ganti isinya dengan link ngrok:
```
https://a1b2c3d4.ngrok-free.app/absensi-nonasn/
```
Simpan pengaturan. Setelah itu buka halaman **QR Generator** — QR yang tampil sudah menggunakan link ngrok dan bisa discan dari HP.

> Langkah ini penting! Tanpa mengganti URL, QR yang ter-generate masih mengarah ke `localhost` dan tidak bisa dibuka dari HP.

**7. Test scan dari HP**

Scan QR dari HP menggunakan kamera — HP akan membuka halaman `scan.php` via HTTPS. Kamera dan GPS sudah bisa berjalan normal.

> **Catatan penting:**
> - Link ngrok berubah setiap kali ngrok dijalankan ulang (versi gratis)
> - Jangan tutup terminal ngrok selama testing
> - Versi gratis ngrok ada batas koneksi, cukup untuk keperluan testing

### Akun Default

Setelah import database, login menggunakan akun admin yang sudah ada di data contoh, atau buat akun baru via halaman registrasi.

## Struktur Folder

```
absensi-nonasn/
├── admin/          # Halaman admin (rekap, pegawai, cuti, dll)
├── api/            # Endpoint API (QR scan, maintenance)
├── assets/         # CSS, JS, gambar, suara
├── config/         # Konfigurasi database & helper (database.php tidak diupload)
├── partials/       # Komponen kecil (alert, dll)
├── pegawai/        # Halaman pegawai (absensi, profil, riwayat, dll)
├── sessions/       # Penyimpanan session (tidak diupload)
├── templates/      # Header, footer, navbar, sidebar
├── uploads/        # Foto selfie & bukti (tidak diupload)
├── index.php       # Halaman utama / redirect
├── login.php       # Halaman login
├── qr.php          # Halaman QR absensi (tampil di layar kantor)
├── scan.php        # Halaman scan QR (dari HP pegawai)
└── database.sql    # File database
```

## Catatan

- File `config/database.php` tidak disertakan di repo karena berisi kredensial database. Buat manual sesuai langkah di atas.
- Folder `uploads/` dan `sessions/` tidak disertakan karena berisi data pribadi pegawai.
- Dikembangkan untuk keperluan internal BBWS Citanduy — Unit Hidrologi dan Kualitas Air.

---

Dibuat oleh **Sendi Febriansyah** — PKL BBWS Citanduy 2026
