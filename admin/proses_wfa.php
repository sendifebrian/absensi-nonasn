<?php
require_once __DIR__ . '/../config/init.php';
$is_qr_page = false;
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_admin()) {
    header('Location: dashboard.php');
    exit();
}

$action = $_POST['action'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_verify();

// ── Validasi tanggal (shared) ──────────────────────────────
function validasi_tanggal(&$tanggal_mulai, &$tanggal_selesai, &$berlaku_untuk, &$keterangan): ?string {
    $tanggal_mulai   = $_POST['tanggal_mulai']   ?? null;
    $tanggal_selesai = $_POST['tanggal_selesai'] ?? null;
    $berlaku_untuk   = $_POST['berlaku_untuk']   ?? 'semua';
    $keterangan      = trim($_POST['keterangan'] ?? 'Work From Anywhere');

    if (!$tanggal_mulai || !$tanggal_selesai)
        return 'Tanggal mulai dan selesai wajib diisi.';
    if ($tanggal_selesai < $tanggal_mulai)
        return 'Tanggal selesai tidak boleh sebelum tanggal mulai.';

    return null;
}

// ── Cek kondisi pegawai pada rentang tanggal ───────────────
// Aturan:
//   - Izin/Sakit : status default sudah 'disetujui', langsung diblok
//   - Cuti       : hanya diblok jika status = 'disetujui' (menunggu = boleh)
function cek_konflik_pegawai(PDO $pdo, array $user_ids, string $tanggal_mulai, string $tanggal_selesai): array {
    $konflik = [];

    foreach ($user_ids as $uid) {
        $stmtNama = $pdo->prepare("SELECT nama FROM users WHERE id = ?");
        $stmtNama->execute([$uid]);
        $nama = $stmtNama->fetchColumn() ?: "Pegawai #$uid";

        // Cek izin/sakit (otomatis disetujui)
        $stmtIzin = $pdo->prepare("
            SELECT jenis FROM izin
            WHERE user_id = ?
              AND tanggal BETWEEN ? AND ?
              AND status = 'disetujui'
            LIMIT 1
        ");
        $stmtIzin->execute([$uid, $tanggal_mulai, $tanggal_selesai]);
        $izin = $stmtIzin->fetchColumn();

        if ($izin) {
            $konflik[$uid] = [
                'nama'   => $nama,
                'alasan' => $izin === 'sakit' ? 'Sakit' : 'Izin',
            ];
            continue;
        }

        // Cek cuti disetujui
        $stmtCuti = $pdo->prepare("
            SELECT id FROM cuti
            WHERE user_id = ?
              AND status = 'disetujui'
              AND tanggal_mulai <= ?
              AND tanggal_selesai >= ?
            LIMIT 1
        ");
        $stmtCuti->execute([$uid, $tanggal_selesai, $tanggal_mulai]);
        if ($stmtCuti->fetchColumn()) {
            $konflik[$uid] = [
                'nama'   => $nama,
                'alasan' => 'Cuti disetujui',
            ];
        }
    }

    return $konflik;
}

// ── TAMBAH ────────────────────────────────────────────────
if ($action === 'tambah') {
    $err = validasi_tanggal($tanggal_mulai, $tanggal_selesai, $berlaku_untuk, $keterangan);
    if ($err) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => $err];
        header('Location: wfa.php'); exit();
    }

    if ($berlaku_untuk === 'semua') {
        // Cek seluruh pegawai aktif untuk peringatan
        $stmtAll  = $pdo->query("SELECT id FROM users WHERE role = 'pegawai' AND status = 'aktif'");
        $semua_ids = array_column($stmtAll->fetchAll(), 'id');
        $konflik   = cek_konflik_pegawai($pdo, $semua_ids, $tanggal_mulai, $tanggal_selesai);

        $stmt = $pdo->prepare("
            INSERT INTO wfa_schedule (tanggal_mulai, tanggal_selesai, keterangan, berlaku_untuk, user_id, created_by)
            VALUES (?, ?, ?, 'semua', NULL, ?)
        ");
        $stmt->execute([$tanggal_mulai, $tanggal_selesai, $keterangan, $_SESSION['user_id']]);

        if (!empty($konflik)) {
            $skip = implode(', ', array_map(fn($k) => "{$k['nama']} ({$k['alasan']})", $konflik));
            $_SESSION['alert'] = [
                'type'    => 'warning',
                'message' => "Jadwal WFA untuk semua pegawai berhasil ditambahkan. "
                           . "Perhatian: pegawai berikut sedang cuti/izin/sakit pada rentang ini dan tidak bisa absen WFA — $skip.",
            ];
        } else {
            $_SESSION['alert'] = ['type' => 'success', 'message' => 'Jadwal WFA untuk semua pegawai berhasil ditambahkan.'];
        }

    } else {
        $user_ids = $_POST['user_id'] ?? [];
        if (!is_array($user_ids)) $user_ids = [];
        $user_ids = array_unique(array_filter(array_map('intval', $user_ids), fn($id) => $id > 0));

        if (empty($user_ids)) {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Pilih minimal satu pegawai.'];
            header('Location: wfa.php'); exit();
        }

        $konflik  = cek_konflik_pegawai($pdo, $user_ids, $tanggal_mulai, $tanggal_selesai);
        $bisa_ids = array_diff($user_ids, array_keys($konflik));

        if (empty($bisa_ids)) {
            $info = implode(', ', array_map(fn($k) => "{$k['nama']} ({$k['alasan']})", $konflik));
            $_SESSION['alert'] = [
                'type'    => 'danger',
                'message' => "Tidak ada pegawai yang bisa dijadwalkan WFA. Semua sedang cuti/izin/sakit: $info.",
            ];
            header('Location: wfa.php'); exit();
        }

        $stmt = $pdo->prepare("
            INSERT INTO wfa_schedule (tanggal_mulai, tanggal_selesai, keterangan, berlaku_untuk, user_id, created_by)
            VALUES (?, ?, ?, 'personal', ?, ?)
        ");
        foreach ($bisa_ids as $uid) {
            $stmt->execute([$tanggal_mulai, $tanggal_selesai, $keterangan, $uid, $_SESSION['user_id']]);
        }

        $jumlah = count($bisa_ids);
        if (!empty($konflik)) {
            $skip = implode(', ', array_map(fn($k) => "{$k['nama']} ({$k['alasan']})", $konflik));
            $_SESSION['alert'] = [
                'type'    => 'warning',
                'message' => "Jadwal WFA berhasil ditambahkan untuk $jumlah pegawai. "
                           . "Dilewati karena cuti/izin/sakit: $skip.",
            ];
        } else {
            $_SESSION['alert'] = ['type' => 'success', 'message' => "Jadwal WFA berhasil ditambahkan untuk $jumlah pegawai."];
        }
    }

    header('Location: wfa.php'); exit();
}

// ── EDIT ──────────────────────────────────────────────────
if ($action === 'edit') {
    $id = intval($_POST['id'] ?? 0);
    if (!$id) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => 'ID jadwal tidak valid.'];
        header('Location: wfa.php'); exit();
    }

    $err = validasi_tanggal($tanggal_mulai, $tanggal_selesai, $berlaku_untuk, $keterangan);
    if ($err) {
        $_SESSION['alert'] = ['type' => 'danger', 'message' => $err];
        header('Location: wfa.php'); exit();
    }

    if ($berlaku_untuk === 'semua') {
        $user_id = null;

        // Peringatkan jika ada pegawai yang konflik
        $stmtAll   = $pdo->query("SELECT id FROM users WHERE role = 'pegawai' AND status = 'aktif'");
        $semua_ids = array_column($stmtAll->fetchAll(), 'id');
        $konflik   = cek_konflik_pegawai($pdo, $semua_ids, $tanggal_mulai, $tanggal_selesai);
        if (!empty($konflik)) {
            $skip = implode(', ', array_map(fn($k) => "{$k['nama']} ({$k['alasan']})", $konflik));
            $_SESSION['alert'] = [
                'type'    => 'warning',
                'message' => "Jadwal WFA diperbarui. Perhatian: pegawai berikut sedang cuti/izin/sakit — $skip.",
            ];
        }
    } else {
        $raw_ids = $_POST['user_id'] ?? [];
        $user_id = is_array($raw_ids) ? (intval($raw_ids[0] ?? 0) ?: null) : (intval($raw_ids) ?: null);

        if (!$user_id) {
            $_SESSION['alert'] = ['type' => 'danger', 'message' => 'Pilih minimal satu pegawai.'];
            header('Location: wfa.php'); exit();
        }

        $konflik = cek_konflik_pegawai($pdo, [$user_id], $tanggal_mulai, $tanggal_selesai);
        if (!empty($konflik)) {
            $k = reset($konflik);
            $_SESSION['alert'] = [
                'type'    => 'warning',
                'message' => "Jadwal WFA disimpan. Perhatian: {$k['nama']} sedang {$k['alasan']} pada rentang tanggal ini.",
            ];
        }
    }

    $stmt = $pdo->prepare("
        UPDATE wfa_schedule
        SET tanggal_mulai = ?, tanggal_selesai = ?, keterangan = ?,
            berlaku_untuk = ?, user_id = ?
        WHERE id = ?
    ");
    $stmt->execute([$tanggal_mulai, $tanggal_selesai, $keterangan, $berlaku_untuk, $user_id, $id]);

    if (!isset($_SESSION['alert'])) {
        $_SESSION['alert'] = ['type' => 'success', 'message' => 'Jadwal WFA berhasil diperbarui.'];
    }
    header('Location: wfa.php'); exit();
}

// ── HAPUS ─────────────────────────────────────────────────
if ($action === 'hapus') {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare("DELETE FROM wfa_schedule WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['alert'] = ['type' => 'success', 'message' => 'Jadwal WFA berhasil dihapus.'];
    }
    header('Location: wfa.php'); exit();
}

// Fallback
header('Location: wfa.php'); exit();