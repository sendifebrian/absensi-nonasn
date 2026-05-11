<?php
/**
 * api/cuti_realtime.php
 * ─────────────────────────────────────────────────────────────────────
 * Endpoint polling untuk halaman admin/cuti.php
 * Mengembalikan data terbaru pengajuan cuti + stats.
 * Hanya bisa diakses oleh admin yang sudah login.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

// Load init untuk session + auth
require_once __DIR__ . '/../config/init.php';
$is_qr_page = false;
restore_web_session($pdo);

// Pastikan hanya admin yang bisa akses
if (!isset($_SESSION['user_id']) || !is_admin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$status_filter = $_GET['status'] ?? 'menunggu';
if (!in_array($status_filter, ['menunggu','disetujui','ditolak','semua'])) {
    $status_filter = 'menunggu';
}

// Ambil timestamp terakhir yang diketahui client (untuk deteksi data baru)
$last_id = (int)($_GET['last_id'] ?? 0);

try {
    // ── Statistik ───────────────────────────────────────────────────
    $stmt = $pdo->query("SELECT status, COUNT(*) AS total FROM cuti GROUP BY status");
    $stats = ['menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0];
    while ($row = $stmt->fetch()) {
        $stats[$row['status']] = (int)$row['total'];
    }

    // ── Data cuti ───────────────────────────────────────────────────
    $where  = $status_filter === 'semua' ? '' : "WHERE c.status = ?";
    $params = $status_filter === 'semua' ? [] : [$status_filter];

    $stmt = $pdo->prepare("
        SELECT c.id, c.user_id, c.jenis_cuti, c.tanggal_mulai, c.tanggal_selesai,
               c.alasan, c.status, c.catatan, c.bukti, c.created_at,
               u.nama, u.unit_kerja, u.foto AS foto_pegawai
        FROM cuti c
        JOIN users u ON u.id = c.user_id
        $where
        ORDER BY c.created_at DESC
        LIMIT 50
    ");
    $stmt->execute($params);
    $cutiList = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Deteksi apakah ada data baru (ID lebih besar dari last_id) ──
    $max_id  = empty($cutiList) ? 0 : max(array_column($cutiList, 'id'));
    $has_new = ($last_id > 0 && $max_id > $last_id);

    // Format tanggal untuk response
    foreach ($cutiList as &$c) {
        $c['id'] = (int)$c['id'];
        $durasi = (strtotime($c['tanggal_selesai']) - strtotime($c['tanggal_mulai'])) / 86400 + 1;
        $c['durasi'] = (int)$durasi;
        $c['tgl_mulai_fmt']   = date('d M Y', strtotime($c['tanggal_mulai']));
        $c['tgl_selesai_fmt'] = date('d M Y', strtotime($c['tanggal_selesai']));
        $c['created_fmt']     = date('d M Y H:i', strtotime($c['created_at']));
        // Foto URL relatif
        $c['foto_url'] = $c['foto_pegawai']
            ? 'foto.php?type=selfie&file=' . urlencode(basename($c['foto_pegawai']))
            : null;
    }
    unset($c);

    echo json_encode([
        'ok'       => true,
        'stats'    => $stats,
        'list'     => $cutiList,
        'max_id'   => $max_id,
        'has_new'  => $has_new,
        'ts'       => time(),
    ]);

} catch (Throwable $e) {
    error_log('cuti_realtime error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'db_error']);
}
