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

// Cek apakah foto sudah disetujui
$stmt = $pdo->prepare("SELECT foto, status_foto FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch();

if ($user['status_foto'] === 'disetujui') {
    // Jika sudah disetujui, arahkan ke dashboard
    header('Location: dashboard.php');
    exit();
}
?>

<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<div class="main-content">
    <div class="container mt-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <h5 class="mb-0">Upload Foto Profil</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-1"></i>
                    <strong>Wajib upload foto profil</strong> untuk bisa absen.
                    Admin akan meninjau foto Anda.
                </div>

                <div class="text-center mb-4">
                    <video id="video" width="240" height="180" autoplay playsinline style="border-radius:8px; background:#f8f9fa;"></video>
                    <canvas id="canvas" style="display:none;"></canvas>
                    <br><br>
                    <button id="captureBtn" class="btn" style="background:#d4a832; color:white;">Ambil Foto</button>
                    <img id="photoPreview" class="mt-3" style="max-width:240px; display:none; border-radius:8px; border:2px solid #d4a832;">
                </div>

                <div class="form-text text-center mb-3">
                    Gunakan foto wajah asli, jelas, sopan, dan terbaru. Foto tidak sesuai akan ditolak.
                </div>

                <form id="uploadForm">
                    <input type="hidden" name="foto" id="fotoInput">
                    <button type="submit" class="btn w-100" style="background:#1a2744; color:white;" id="submitBtn" disabled>
                        Kirim untuk Review
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
// Akses kamera
let stream;
navigator.mediaDevices.getUserMedia({ video: true })
    .then(s => {
        stream = s;
        document.getElementById('video').srcObject = s;
    })
    .catch(err => {
        Swal.fire('Error', 'Izinkan akses kamera untuk upload foto.', 'error');
    });

// Ambil foto
document.getElementById('captureBtn').addEventListener('click', () => {
    const canvas = document.getElementById('canvas');
    const ctx = canvas.getContext('2d');
    const video = document.getElementById('video');
    
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
    
    const imgData = canvas.toDataURL('image/jpeg');
    document.getElementById('photoPreview').src = imgData;
    document.getElementById('photoPreview').style.display = 'block';
    document.getElementById('fotoInput').value = imgData;
    document.getElementById('submitBtn').disabled = false;
});

// Kirim foto
document.getElementById('uploadForm').addEventListener('submit', e => {
    e.preventDefault();
    const foto = document.getElementById('fotoInput').value;
    if (!foto) return;

    fetch('proses_upload_foto.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ foto })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            Swal.fire('Berhasil', 'Foto dikirim untuk review. Tunggu approval dari admin.', 'success')
                .then(() => window.location = 'dashboard.php');
        } else {
            Swal.fire('Gagal', data.message, 'error');
        }
    });
});
</script>
<?php include '../templates/footer.php'; ?>