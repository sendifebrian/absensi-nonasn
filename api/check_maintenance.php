<?php
/**
 * api/check_maintenance.php
 * ─────────────────────────
 * Endpoint polling ringan untuk maintenance.php
 * Dipanggil JS setiap beberapa detik — tidak butuh session admin.
 * Hanya mengembalikan apakah maintenance masih aktif atau tidak.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Load database saja, tanpa auth/session overhead
$cfg_path = __DIR__ . '/../config/database.php';
if (!file_exists($cfg_path)) {
    echo json_encode(['maintenance' => true, 'error' => 'cfg_missing']);
    exit;
}

try {
    require_once $cfg_path;   // membuat $pdo
    $stmt = $pdo->query("SELECT maintenance_mode FROM settings WHERE id = 1 LIMIT 1");
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    $on   = !empty($row['maintenance_mode']);
    echo json_encode(['maintenance' => $on, 'ts' => time()]);
} catch (Throwable $e) {
    // Jika DB error, anggap masih maintenance (aman)
    echo json_encode(['maintenance' => true, 'error' => 'db_error']);
}
