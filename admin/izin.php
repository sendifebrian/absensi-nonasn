<?php
require_once __DIR__ . '/../config/init.php';
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_admin()) {
    header('Location: dashboard.php');
    exit();
}

if (isset($_GET['approve']) || isset($_GET['reject'])) {
    $id = (int)(isset($_GET['approve']) ? $_GET['approve'] : $_GET['reject']);
    $status = isset($_GET['approve']) ? 'disetujui' : 'ditolak';
    
    $stmt = $pdo->prepare("UPDATE izin SET status = ? WHERE id = ?");
    $stmt->execute([$status, $id]);
    header("Location: izin.php");
    exit();
}

$stmt = $pdo->query("
    SELECT i.*, u.nama 
    FROM izin i 
    JOIN users u ON i.user_id = u.id 
    ORDER BY i.created_at DESC
");
$izinList = $stmt->fetchAll();
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<div class="main-content">
    <div class="container mt-4">
        <h5 class="mb-4">Monitoring Permohonan Izin/Sakit</h5>

        <div class="card shadow-sm">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Pegawai</th>
                                <th>Tanggal</th>
                                <th>Jenis</th>
                                <th>Status</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($izinList)): ?>
                                <tr><td colspan="5" class="text-center py-3 text-muted">Tidak ada permohonan.</td></tr>
                            <?php else: ?>
                                <?php foreach ($izinList as $i): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($i['nama']) ?></td>
                                        <td><?= htmlspecialchars($i['tanggal']) ?></td>
                                        <td><?= ucfirst($i['jenis']) ?></td>
                                        <td>
                                            <?php if ($i['status'] === 'menunggu'): ?>
                                                <span class="badge bg-secondary">Menunggu</span>
                                            <?php elseif ($i['status'] === 'disetujui'): ?>
                                                <span class="badge bg-success">Disetujui</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Ditolak</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($i['status'] === 'menunggu'): ?>
                                                <a href="?approve=<?= $i['id'] ?>" class="btn btn-sm btn-success">Setujui</a>
                                                <a href="?reject=<?= $i['id'] ?>" class="btn btn-sm btn-danger">Tolak</a>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include '../templates/footer.php'; ?>