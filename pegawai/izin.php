<?php
require_once __DIR__ . '/../config/init.php';
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_pegawai()) {
    header('Location: dashboard.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $tanggal = $_POST['tanggal'] ?? '';
    $jenis = $_POST['jenis'] ?? '';
    $keterangan = trim($_POST['keterangan'] ?? '');
    
    if (!in_array($jenis, ['izin', 'sakit']) || !$tanggal) {
        $error = "Data tidak lengkap.";
    } else {
        $buktiPath = null;
        if (!empty($_FILES['bukti']['name'])) {
            $allowed = ['jpg', 'jpeg', 'png', 'pdf'];
            $ext = strtolower(pathinfo($_FILES['bukti']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowed) && $_FILES['bukti']['size'] <= 2097152) {
                $filename = 'bukti_' . $_SESSION['user_id'] . '_' . time() . '.' . $ext;
                $target = '../uploads/bukti/' . $filename;
                if (move_uploaded_file($_FILES['bukti']['tmp_name'], $target)) {
                    $buktiPath = $filename;
                } else {
                    $error = "Gagal mengupload bukti.";
                }
            } else {
                $error = "File harus JPG/PNG/PDF maks 2MB.";
            }
        }

        if (!isset($error)) {
            $stmt = $pdo->prepare("INSERT INTO izin (user_id, tanggal, jenis, keterangan, bukti) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$_SESSION['user_id'], $tanggal, $jenis, $keterangan, $buktiPath])) {
                $success = "Permohonan izin berhasil diajukan.";
            } else {
                $error = "Gagal menyimpan data.";
            }
        }
    }
}
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<div class="main-content">
    <div class="container mt-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0">Ajukan Izin / Sakit</h5>
            </div>
            <div class="card-body">
                <?php if (isset($success)): ?>
                    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
                    <meta http-equiv="refresh" content="2;url=riwayat.php">
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
                    <?php csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label">Tanggal</label>
                        <input type="text" name="tanggal" class="form-control" id="flatpickr" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Jenis</label>
                        <select name="jenis" class="form-select" required>
                            <option value="">Pilih</option>
                            <option value="izin">Izin</option>
                            <option value="sakit">Sakit</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Keterangan</label>
                        <textarea name="keterangan" class="form-control" rows="3" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Upload Bukti (Opsional)</label>
                        <input type="file" name="bukti" class="form-control" accept=".jpg,.jpeg,.png,.pdf">
                        <div class="form-text">Format: JPG, PNG, PDF (maks 2MB)</div>
                    </div>
                    <button type="submit" class="btn w-100" style="background:#d4a832; color:white;">Ajukan</button>
                </form>
            </div>
        </div>
    </div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
flatpickr("#flatpickr", {
    dateFormat: "Y-m-d",
    minDate: "today"
});
</script>
<?php include '../templates/footer.php'; ?>