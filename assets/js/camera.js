// assets/js/camera.js
let videoStream = null;

function startCamera(videoElement) {
    if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
        navigator.mediaDevices.getUserMedia({ video: true })
            .then(stream => {
                videoElement.srcObject = stream;
                videoStream = stream;
            })
            .catch(err => {
                console.error("Error kamera:", err);
                Swal.fire('Kamera Error', 'Pastikan izin kamera diaktifkan.', 'error');
            });
    } else {
        alert("Browser tidak mendukung kamera.");
    }
}

function stopCamera() {
    if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
        videoStream = null;
    }
}