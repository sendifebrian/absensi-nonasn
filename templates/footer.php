<!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" 
            integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" 
            crossorigin="anonymous"></script>

    <!-- AOS Animation -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/aos/2.3.4/aos.js"></script>
    <script>AOS.init({ duration: 600, once: true });</script>

    <!-- Auto Logout Client-Side -->
    <?php
    $al_via      = $_SESSION['login_via']   ?? 'web';
    $al_remember = $_SESSION['remember_me'] ?? false;
    $al_do_timer = ($al_via !== 'qr' && !$al_remember);
    // Hitung base URL untuk redirect login
    $al_script   = $_SERVER['SCRIPT_NAME'] ?? '';
    $al_parts    = explode('/', trim($al_script, '/'));
    $al_base     = isset($al_parts[0]) && $al_parts[0] !== '' ? '/' . $al_parts[0] : '';
    $al_login    = $al_base . '/login.php?timeout=1';
    ?>
    <script>
    (function () {
        const DO_TIMER  = <?= $al_do_timer ? 'true' : 'false' ?>;
        const LOGIN_URL = <?= json_encode($al_login) ?>;
        const TIMEOUT   = 30 * 60 * 1000; // 30 menit (harus sama dengan PHP)
        const STORE_KEY = 'absensi_last_activity';

        if (!DO_TIMER) return; // QR atau remember me → tidak timeout

        function stampActivity() {
            try { localStorage.setItem(STORE_KEY, Date.now()); } catch(e) {}
        }

        function checkTimeout() {
            try {
                const last = parseInt(localStorage.getItem(STORE_KEY) || '0', 10);
                if (last && (Date.now() - last) >= TIMEOUT) {
                    localStorage.removeItem(STORE_KEY);
                    window.location.href = LOGIN_URL;
                }
            } catch(e) {}
        }

        // Cek langsung saat halaman dibuka (ini yang mengatasi masalah utama)
        checkTimeout();

        // Update timestamp setiap ada aktivitas user
        ['click', 'keydown', 'mousemove', 'touchstart', 'scroll'].forEach(function(ev) {
            document.addEventListener(ev, stampActivity, { passive: true });
        });

        // Stamp saat pertama load
        stampActivity();

        // Cek setiap 1 menit
        setInterval(checkTimeout, 60 * 1000);

        // Cek saat tab/browser dibuka kembali (Page Visibility API)
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                checkTimeout();
            }
        });
    })();
    </script>

</body>
</html>