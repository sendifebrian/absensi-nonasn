<?php
/**
 * maintenance.php (config/maintenance.php)
 * ─────────────────────────────────────────
 * Middleware maintenance mode.
 * Di-require di config/init.php SETELAH database siap.
 *
 * Cara kerja:
 *  - Cek kolom maintenance_mode di tabel settings (value: 1 = aktif, 0 = nonaktif)
 *  - Kalau aktif → redirect semua ke /maintenance.php
 *  - Admin tetap bisa akses dashboard
 *  - Halaman maintenance.php sendiri TIDAK dicegat (infinite redirect)
 */

function check_maintenance_mode(PDO $pdo): void {
    // Halaman-halaman yang TIDAK boleh diblokir walaupun maintenance aktif
    $current_file = basename($_SERVER['SCRIPT_FILENAME'] ?? '');
    $bypass_files = [
        'maintenance.php',  // halaman maintenance itu sendiri
        'logout.php',       // biar bisa logout
    ];

    if (in_array($current_file, $bypass_files)) return;

    // Ambil status maintenance dari DB
    try {
        $stmt = $pdo->query("SELECT maintenance_mode FROM settings WHERE id = 1 LIMIT 1");
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
        $maintenance_on = !empty($row['maintenance_mode']);
    } catch (Throwable $e) {
        // Kolom belum ada → auto migrate, lalu skip (belum aktif)
        try {
            $pdo->exec("ALTER TABLE settings ADD COLUMN `maintenance_mode` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Maintenance mode: 1=aktif, 0=nonaktif'");
            $pdo->exec("ALTER TABLE settings ADD COLUMN `maintenance_pesan` varchar(255) NOT NULL DEFAULT '' COMMENT 'Pesan tambahan maintenance'");
        } catch (Throwable) { /* kolom sudah ada */ }
        return;
    }

    if (!$maintenance_on) return;

    // Admin yang sudah login → boleh lewat
    if (isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'admin') return;

    // Semua yang lain → redirect ke halaman maintenance
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $parts  = explode('/', trim($script, '/'));
    $base   = isset($parts[0]) && $parts[0] !== '' ? '/' . $parts[0] : '';

    header('Location: ' . $base . '/maintenance.php');
    exit();
}
