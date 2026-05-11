// assets/js/gps.js
function getCurrentLocation(callback) {
    if (!navigator.geolocation) {
        callback(null, "Geolocation tidak didukung browser.");
        return;
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            const lat = position.coords.latitude;
            const lng = position.coords.longitude;
            callback({ lat, lng }, null);
        },
        (error) => {
            let msg = "Tidak bisa mengambil lokasi.";
            if (error.code === 1) msg = "Izinkan akses lokasi untuk absensi.";
            callback(null, msg);
        },
        { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
}