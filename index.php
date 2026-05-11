<?php
require_once __DIR__ . '/config/init.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'aktif'");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        session_unset();
        session_regenerate_id(true);
        $_SESSION['user_id']       = $user['id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['nama']          = $user['nama'];
        $_SESSION['role']          = $user['role'];
        $_SESSION['status']        = $user['status'];
        $_SESSION['login_via']     = 'web';
        $_SESSION['sess_context']  = 'web';
        $_SESSION['came_from_qr']  = false;
        $_SESSION['last_activity'] = time();

        header('Location: ' . ($user['role'] === 'admin' ? 'admin/dashboard.php' : 'pegawai/dashboard.php'));
        exit();
    } else {
        header('Location: login.php?error=Username atau password salah, atau akun tidak aktif.');
        exit();
    }
} else {
    header('Location: login.php');
}
?>