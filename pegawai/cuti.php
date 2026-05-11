<?php
require_once __DIR__ . '/../config/init.php';
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_pegawai()) { header('Location: dashboard.php'); exit(); }

$successMsg = $errorMsg = '';

// ── BATALKAN PENGAJUAN CUTI ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'batalkan') {
    csrf_verify();
    $cutiId = (int)($_POST['cuti_id'] ?? 0);
    if ($cutiId > 0) {
        // Pastikan cuti milik user ini dan statusnya masih 'menunggu'
        $stmtCek = $pdo->prepare("SELECT id, tanggal_mulai, tanggal_selesai FROM cuti WHERE id=? AND user_id=? AND status='menunggu'");
        $stmtCek->execute([$cutiId, $_SESSION['user_id']]);
        $cutiData = $stmtCek->fetch(PDO::FETCH_ASSOC);
        if ($cutiData) {
            $pdo->prepare("DELETE FROM cuti WHERE id=? AND user_id=? AND status='menunggu'")
                ->execute([$cutiId, $_SESSION['user_id']]);
            $successMsg = 'Pengajuan cuti <strong>'.date('d M Y',strtotime($cutiData['tanggal_mulai'])).' – '.date('d M Y',strtotime($cutiData['tanggal_selesai'])).'</strong> berhasil dibatalkan.';
        } else {
            $errorMsg = 'Gagal membatalkan. Pengajuan tidak ditemukan atau statusnya sudah bukan <em>Menunggu</em>.';
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajukan') {
    csrf_verify();
    $tglMulai = trim($_POST['tanggal_mulai'] ?? '');
    $tglAkhir = trim($_POST['tanggal_selesai'] ?? '');
    $alasan   = trim($_POST['alasan'] ?? '');

    // Validasi format tanggal
    $tglMulaiFmt = DateTime::createFromFormat('Y-m-d', $tglMulai);
    $tglAkhirFmt = DateTime::createFromFormat('Y-m-d', $tglAkhir);

    if (!$tglMulai || !$tglAkhir || !$tglMulaiFmt || !$tglAkhirFmt) {
        $errorMsg = 'Tanggal mulai dan selesai wajib diisi.';
    } elseif ($tglAkhir < $tglMulai) {
        $errorMsg = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
    } elseif (!$alasan) {
        $errorMsg = 'Alasan cuti wajib diisi.';
    } else {
        // Cek: jika tanggal mulai cuti adalah hari ini dan pegawai sudah absen masuk
        $today = date('Y-m-d');
        if ($tglMulai === $today) {
            $stmtAbsen = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE user_id = ? AND tanggal = ? AND jam_masuk IS NOT NULL");
            $stmtAbsen->execute([$_SESSION['user_id'], $today]);
            if ($stmtAbsen->fetchColumn() > 0) {
                $errorMsg = 'Tidak dapat mengajukan cuti untuk hari ini karena Anda sudah tercatat absen masuk.';
            }
        }

        if ($errorMsg === '') {
            $overlapQ = "((tanggal_mulai<=? AND tanggal_selesai>=?) OR (tanggal_mulai<=? AND tanggal_selesai>=?) OR (tanggal_mulai>=? AND tanggal_selesai<=?))";
            $overlapParams = [$_SESSION['user_id'],$tglMulai,$tglMulai,$tglAkhir,$tglAkhir,$tglMulai,$tglAkhir];

            // Cek tumpang tindih dengan cuti menunggu atau disetujui
            $stmtCek = $pdo->prepare("SELECT tanggal_mulai, tanggal_selesai, status FROM cuti WHERE user_id=? AND status IN ('menunggu','disetujui') AND $overlapQ LIMIT 1");
            $stmtCek->execute($overlapParams);
            $konflikAktif = $stmtCek->fetch(PDO::FETCH_ASSOC);

            if ($konflikAktif) {
                $stA = $konflikAktif['status'] === 'menunggu' ? 'sedang menunggu persetujuan' : 'sudah disetujui';
                $tA  = date('d M Y', strtotime($konflikAktif['tanggal_mulai']));
                $tB  = date('d M Y', strtotime($konflikAktif['tanggal_selesai']));
                $errorMsg = "Anda sudah memiliki pengajuan cuti yang <strong>{$stA}</strong> pada periode <strong>{$tA} – {$tB}</strong>. Tidak dapat mengajukan cuti pada tanggal yang sama.";
            } else {
                // Cuti ditolak → boleh ajukan ulang. Proses simpan.
                $bukti = null;
                if (!empty($_FILES['bukti']['name'])) {
                    $allowed = ['jpg','jpeg','png','pdf'];
                    $ext = strtolower(pathinfo($_FILES['bukti']['name'], PATHINFO_EXTENSION));
                    if (in_array($ext,$allowed) && $_FILES['bukti']['size'] <= 2097152) {
                        $filename = 'cuti_'.$_SESSION['user_id'].'_'.time().'.'.$ext;
                        if (move_uploaded_file($_FILES['bukti']['tmp_name'], '../uploads/selfie/'.$filename)) $bukti = $filename;
                    }
                }
                $pdo->prepare("INSERT INTO cuti (user_id,tanggal_mulai,tanggal_selesai,alasan,bukti) VALUES (?,?,?,?,?)")
                    ->execute([$_SESSION['user_id'],$tglMulai,$tglAkhir,$alasan,$bukti]);
                $durasi = (strtotime($tglAkhir)-strtotime($tglMulai))/86400+1;
                $successMsg = "Pengajuan cuti <strong>".date('d M Y',strtotime($tglMulai))." – ".date('d M Y',strtotime($tglAkhir))."</strong> ({$durasi} hari) berhasil dikirim.";
            }
        }
    }
}

$stmt = $pdo->prepare("SELECT * FROM cuti WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$stmt->execute([$_SESSION['user_id']]);
$cutiList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cek apakah hari ini sudah absen masuk (untuk peringatan di form)
$stmtHariIni = $pdo->prepare("SELECT jam_masuk FROM attendance WHERE user_id = ? AND tanggal = ? AND jam_masuk IS NOT NULL LIMIT 1");
$stmtHariIni->execute([$_SESSION['user_id'], date('Y-m-d')]);
$absenHariIni = $stmtHariIni->fetch(PDO::FETCH_ASSOC);

$totalCuti      = count($cutiList);
$totalDisetujui = count(array_filter($cutiList, fn($c) => $c['status']==='disetujui'));
$totalMenunggu  = count(array_filter($cutiList, fn($c) => $c['status']==='menunggu'));
$totalDitolak   = count(array_filter($cutiList, fn($c) => $c['status']==='ditolak'));

// Ambil hari libur dari DB untuk 2 tahun (tahun ini & tahun depan)
$stmtLibur = $pdo->prepare("SELECT tanggal, keterangan FROM hari_libur WHERE tahun IN (?,?) ORDER BY tanggal");
$stmtLibur->execute([date('Y'), date('Y')+1]);
$hariLiburRows = $stmtLibur->fetchAll(PDO::FETCH_ASSOC);
$hariLiburJS   = json_encode(array_map(fn($r) => [
    'date' => $r['tanggal'],
    'ket'  => $r['keterangan']
], $hariLiburRows));

function fmtTgl($tgl) {
    $bln=['','Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
    $ts=strtotime($tgl);
    return date('j',$ts).' '.$bln[(int)date('n',$ts)].' '.date('Y',$ts);
}
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<style>
:root{
    --gold:#C9A84C;--gold-lt:#FBF3DE;--gold-dk:#A8862E;--gold-border:rgba(201,168,76,.25);
    --navy:#0E1E3D;--navy-2:#172B55;
    --green:#16a34a;--green-lt:#f0fdf4;--green-border:#bbf7d0;
    --amber:#d97706;--amber-lt:#fffbeb;--amber-border:#fde68a;
    --red:#dc2626;--red-lt:#fef2f2;--red-border:#fecaca;
    --gray-50:#F8FAFC;--gray-100:#F1F5F9;--gray-200:#E2E8F0;
    --gray-400:#94A3B8;--gray-600:#475569;--gray-800:#1E293B;
    --radius:10px;--radius-lg:14px;--radius-xl:18px;
    --shadow:0 1px 4px rgba(0,0,0,.06),0 1px 2px rgba(0,0,0,.04);
    --shadow-md:0 4px 16px rgba(0,0,0,.09);
}
*,*::before,*::after{box-sizing:border-box}

.ct-wrap{
    max-width:1100px;margin:0 auto;
    padding:1.5rem 1.25rem 3rem;
    display:flex;flex-direction:column;gap:1.25rem;
}

/* ── PAGE HEADER ─────────────────────────────── */
.ct-ph{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.ct-ph-title{font-size:1.35rem;font-weight:800;color:var(--navy);letter-spacing:-.025em;line-height:1.2}
.ct-ph-sub{font-size:.78rem;color:var(--gray-400);margin-top:.2rem}
.ct-ph-eyebrow{
    display:inline-flex;align-items:center;gap:.35rem;
    font-size:.6rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;
    color:var(--gold-dk);margin-bottom:.3rem;
}
.ct-guide-btn{
    display:inline-flex;align-items:center;gap:.4rem;white-space:nowrap;
    font-size:.75rem;font-weight:600;color:var(--gray-600);
    background:#fff;border:1.5px solid var(--gray-200);
    border-radius:var(--radius);padding:.45rem .9rem;
    cursor:pointer;transition:all .18s;flex-shrink:0;
}
.ct-guide-btn:hover{border-color:var(--gold);color:var(--gold-dk);background:var(--gold-lt)}

/* ── STATS ROW ───────────────────────────────── */
.ct-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem}
.ct-stat{
    background:#fff;border:1px solid var(--gray-200);
    border-radius:var(--radius-lg);padding:1rem;
    display:flex;align-items:center;gap:.85rem;
    box-shadow:var(--shadow);transition:transform .18s,box-shadow .18s;
}
.ct-stat:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.ct-stat-ico{
    width:42px;height:42px;border-radius:10px;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
}
.ct-stat-ico svg{width:20px;height:20px}
.ct-stat-ico.total {background:#EFF6FF;color:#1D4ED8}
.ct-stat-ico.ok    {background:var(--green-lt);color:var(--green)}
.ct-stat-ico.wait  {background:var(--amber-lt);color:var(--amber)}
.ct-stat-ico.no    {background:var(--red-lt);color:var(--red)}
.ct-stat-num{font-size:1.5rem;font-weight:800;color:var(--navy);line-height:1}
.ct-stat-lbl{font-size:.67rem;font-weight:600;color:var(--gray-400);text-transform:uppercase;letter-spacing:.07em;margin-top:.15rem}

/* ── ALERT ───────────────────────────────────── */
.ct-alert{
    display:flex;align-items:flex-start;gap:.65rem;
    padding:.875rem 1.1rem;border-radius:var(--radius);
    font-size:.8rem;font-weight:500;line-height:1.55;
    animation:alertIn .28s ease;
}
@keyframes alertIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.ct-alert.ok {background:var(--green-lt);border:1px solid var(--green-border);color:#166534}
.ct-alert.err{background:var(--red-lt);border:1px solid var(--red-border);color:#991B1B}
.ct-alert svg{flex-shrink:0;margin-top:.1rem}
.ct-alert-x{margin-left:auto;background:none;border:none;cursor:pointer;color:inherit;opacity:.55;padding:.15rem;border-radius:4px;line-height:1}
.ct-alert-x:hover{opacity:1}

/* ── 2-COL LAYOUT ────────────────────────────── */
.ct-cols{
    display:grid;
    grid-template-columns:minmax(0,1fr) minmax(0,1fr);
    gap:1.25rem;
    align-items:start;
}

/* ── CARD ─────────────────────────────────────── */
.ct-card{background:#fff;border:1px solid var(--gray-200);border-radius:var(--radius-xl);box-shadow:var(--shadow);overflow:hidden}
.ct-card-hd{
    display:flex;align-items:center;justify-content:space-between;
    padding:.875rem 1.25rem;border-bottom:1px solid var(--gray-100);
}
.ct-card-hd-left{display:flex;align-items:center;gap:.55rem}
.ct-card-hd-icon{
    width:32px;height:32px;border-radius:8px;
    background:var(--gold-lt);border:1px solid var(--gold-border);
    display:flex;align-items:center;justify-content:center;color:var(--gold-dk);
}
.ct-card-hd-icon svg{width:15px;height:15px}
.ct-card-hd-title{font-size:.85rem;font-weight:700;color:var(--navy)}
.ct-card-hd-sub{font-size:.7rem;color:var(--gray-400);margin-top:.05rem}
.ct-card-body{padding:1.25rem}

.ct-count{
    font-size:.65rem;font-weight:700;color:var(--gold-dk);
    background:var(--gold-lt);border:1px solid var(--gold-border);
    padding:.15rem .55rem;border-radius:20px;
}

/* ── FORM ─────────────────────────────────────── */
.ct-field{margin-bottom:1rem}
.ct-field:last-child{margin-bottom:0}
.ct-label{
    display:flex;align-items:center;gap:.3rem;
    font-size:.65rem;font-weight:700;text-transform:uppercase;
    letter-spacing:.08em;color:var(--gray-600);margin-bottom:.42rem;
}
.ct-req{color:var(--red)}
.ct-opt{font-weight:400;text-transform:none;letter-spacing:0;color:var(--gray-400)}
.ct-input,.ct-textarea{
    width:100%;padding:.6rem .85rem;
    border:1.5px solid var(--gray-200);border-radius:var(--radius);
    font-size:.83rem;color:var(--gray-800);background:#fff;
    outline:none;transition:border-color .18s,box-shadow .18s;
    font-family:inherit;
}
.ct-textarea{resize:vertical;min-height:100px}
.ct-input:focus,.ct-textarea:focus{
    border-color:var(--gold);
    box-shadow:0 0 0 3px rgba(201,168,76,.12);
}
.ct-row2{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}

.ct-durasi{
    display:inline-flex;align-items:center;gap:.35rem;
    background:var(--gold-lt);border:1px solid var(--gold-border);
    color:var(--gold-dk);border-radius:20px;
    font-size:.7rem;font-weight:700;padding:.28rem .8rem;
    margin-bottom:.9rem;
}

/* Upload zone */
.ct-upload{
    position:relative;border:2px dashed var(--gray-200);border-radius:var(--radius);
    background:var(--gray-50);transition:all .18s;overflow:hidden;cursor:pointer;
}
.ct-upload:hover,.ct-upload.over{border-color:var(--gold);background:var(--gold-lt)}
.ct-upload input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%}
.ct-upload-inner{
    display:flex;flex-direction:column;align-items:center;
    padding:1.25rem 1rem;gap:.3rem;pointer-events:none;
}
.ct-upload-ico{
    width:38px;height:38px;border-radius:9px;
    background:var(--gray-200);display:flex;align-items:center;justify-content:center;
    color:var(--gray-400);margin-bottom:.15rem;
}
.ct-upload-ico svg{width:18px;height:18px}
.ct-upload-lbl{font-size:.78rem;font-weight:600;color:var(--gray-600)}
.ct-upload-sub{font-size:.67rem;color:var(--gray-400)}
.ct-file-ok{
    display:none;align-items:center;gap:.5rem;
    padding:.5rem .85rem;background:var(--green-lt);
    border:1px solid var(--green-border);border-radius:var(--radius);
    margin-top:.4rem;font-size:.75rem;color:#166534;font-weight:600;
}
.ct-file-ok svg{flex-shrink:0;width:13px;height:13px}
.ct-file-rm{margin-left:auto;background:none;border:none;color:#166534;opacity:.6;cursor:pointer;padding:.1rem;border-radius:3px}
.ct-file-rm:hover{opacity:1}

/* Info box */
.ct-info{
    display:flex;align-items:flex-start;gap:.6rem;
    background:var(--gray-50);border:1px solid var(--gray-200);
    border-radius:var(--radius);padding:.75rem .9rem;
    font-size:.73rem;color:var(--gray-600);line-height:1.6;
    margin-bottom:1rem;
}
.ct-info svg{flex-shrink:0;margin-top:.1rem;color:var(--gold-dk)}
.ct-badge-sm{
    display:inline-flex;align-items:center;
    padding:.08rem .45rem;border-radius:20px;
    font-size:.65rem;font-weight:700;vertical-align:middle;
    background:var(--amber-lt);color:#92400e;border:1px solid var(--amber-border);
}

/* Submit */
.ct-submit{
    width:100%;display:flex;align-items:center;justify-content:center;gap:.55rem;
    font-size:.85rem;font-weight:700;color:var(--navy);
    background:var(--gold);border:none;border-radius:var(--radius);
    padding:.78rem 1.25rem;cursor:pointer;transition:all .18s;
    font-family:inherit;
}
.ct-submit:hover{background:var(--gold-dk);transform:translateY(-1px);box-shadow:0 4px 14px rgba(201,168,76,.35)}
.ct-submit:active{transform:none}
.ct-submit svg{width:15px;height:15px;flex-shrink:0}

/* ── RIWAYAT ─────────────────────────────────── */
.ct-riwayat{max-height:520px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--gray-200) transparent}
.ct-riwayat::-webkit-scrollbar{width:4px}
.ct-riwayat::-webkit-scrollbar-track{background:transparent}
.ct-riwayat::-webkit-scrollbar-thumb{background:var(--gray-200);border-radius:4px}

.ct-ritem{
    display:flex;align-items:flex-start;justify-content:space-between;gap:.85rem;
    padding:1rem 1.25rem;border-bottom:1px solid var(--gray-100);
    transition:background .12s;position:relative;
    animation:rowIn .25s ease both;
}
@keyframes rowIn{from{opacity:0;transform:translateX(6px)}to{opacity:1;transform:none}}
.ct-ritem:last-child{border-bottom:none}
.ct-ritem:hover{background:var(--gray-50)}
.ct-ritem-bar{position:absolute;left:0;top:0;bottom:0;width:3px;border-radius:0}
.ct-ritem-bar.ok  {background:var(--green)}
.ct-ritem-bar.wait{background:var(--amber)}
.ct-ritem-bar.no  {background:var(--red)}

.ct-ritem-ico{
    width:30px;height:30px;border-radius:50%;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;
}
.ct-ritem-ico svg{width:13px;height:13px}
.ct-ritem-ico.ok  {background:var(--green-lt);color:var(--green)}
.ct-ritem-ico.wait{background:var(--amber-lt);color:var(--amber)}
.ct-ritem-ico.no  {background:var(--red-lt);color:var(--red)}

.ct-ritem-body{flex:1;min-width:0}
.ct-ritem-tgl{
    font-size:.8rem;font-weight:700;color:var(--navy);
    display:flex;align-items:center;gap:.35rem;flex-wrap:wrap;
    margin-bottom:.2rem;
}
.ct-ritem-tgl svg{width:9px;height:9px;color:var(--gray-400);flex-shrink:0}
.ct-ritem-dur{font-size:.68rem;font-weight:700;color:var(--gold-dk)}
.ct-ritem-alasan{
    font-size:.7rem;color:var(--gray-400);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    max-width:220px;margin-top:.18rem;
}
.ct-ritem-catatan{
    display:flex;align-items:flex-start;gap:.25rem;
    font-size:.66rem;color:var(--gray-400);margin-top:.28rem;line-height:1.5;
}
.ct-ritem-catatan svg{flex-shrink:0;margin-top:.12rem;width:9px;height:9px}

.ct-badge{
    display:inline-flex;align-items:center;gap:.25rem;
    padding:.22rem .62rem;border-radius:20px;
    font-size:.65rem;font-weight:700;white-space:nowrap;flex-shrink:0;
}
.ct-badge.ok  {background:var(--green-lt);color:#15803d;border:1px solid var(--green-border)}
.ct-badge.wait{background:var(--amber-lt);color:#92400e;border:1px solid var(--amber-border)}
.ct-badge.no  {background:var(--red-lt);color:#991b1b;border:1px solid var(--red-border)}
.ct-badge svg{width:8px;height:8px}

/* Empty state */
.ct-empty{
    display:flex;flex-direction:column;align-items:center;
    padding:3rem 1.5rem;gap:.65rem;text-align:center;
}
.ct-empty-ico{
    width:52px;height:52px;border-radius:var(--radius-lg);
    background:var(--gray-100);display:flex;align-items:center;justify-content:center;
}
.ct-empty-ico svg{width:22px;height:22px;color:var(--gray-400)}
.ct-empty-t{font-size:.85rem;font-weight:700;color:var(--gray-600)}
.ct-empty-s{font-size:.73rem;color:var(--gray-400)}

/* ── DRIVER.JS ───────────────────────────────── */
.ct-drv.driver-popover{
    background:#fff !important;border-radius:var(--radius-lg) !important;
    box-shadow:0 20px 60px rgba(14,30,61,.2),0 0 0 1px rgba(201,168,76,.2) !important;
    padding:0 !important;max-width:300px !important;overflow:hidden !important;font-family:inherit !important;
}
.ct-drv .driver-popover-title{
    font-size:.82rem !important;font-weight:700 !important;color:#fff !important;
    background:linear-gradient(135deg,var(--navy),var(--navy-2)) !important;
    padding:.85rem 1.1rem !important;border-bottom:2px solid var(--gold) !important;margin:0 !important;
}
.ct-drv .driver-popover-description{font-size:.74rem !important;color:var(--gray-600) !important;line-height:1.65 !important;padding:.85rem 1.1rem .5rem !important}
.ct-drv .driver-popover-progress-text{font-size:.6rem !important;font-weight:700 !important;color:var(--gold-dk) !important;padding:0 1.1rem !important}
.ct-drv .driver-popover-navigation-btns{display:flex !important;gap:.35rem !important;padding:.6rem 1.1rem .85rem !important;border-top:1px solid var(--gray-100) !important;margin-top:.4rem !important}
.ct-drv .driver-popover-next-btn,
.ct-drv .driver-popover-done-btn{
    background:var(--gold) !important;border:none !important;color:var(--navy) !important;
    border-radius:6px !important;padding:.32rem .8rem !important;
    font-size:.7rem !important;font-weight:700 !important;cursor:pointer !important;
    min-width:unset !important;width:auto !important;height:auto !important;
    box-shadow:none !important;text-shadow:none !important;
    display:inline-flex !important;align-items:center !important;
}
.ct-drv .driver-popover-next-btn:hover,.ct-drv .driver-popover-done-btn:hover{background:var(--gold-dk) !important}
.ct-drv .driver-popover-prev-btn{
    background:var(--gray-100) !important;border:1px solid var(--gray-200) !important;
    color:var(--gray-600) !important;border-radius:6px !important;
    padding:.32rem .8rem !important;font-size:.7rem !important;font-weight:500 !important;
    cursor:pointer !important;min-width:unset !important;width:auto !important;height:auto !important;
    box-shadow:none !important;text-shadow:none !important;
    display:inline-flex !important;align-items:center !important;
}
.ct-drv .driver-popover-prev-btn:hover{background:var(--gray-200) !important}
.ct-drv .driver-popover-close-btn{
    color:rgba(255,255,255,.6) !important;background:none !important;border:none !important;
    padding:0 !important;cursor:pointer !important;
    position:absolute !important;top:.65rem !important;right:.85rem !important;
    width:auto !important;height:auto !important;
}

/* ── RESPONSIVE ──────────────────────────────── */
@media(max-width:900px){
    .ct-cols{grid-template-columns:minmax(0,1fr)}
    .ct-stats{grid-template-columns:repeat(2,1fr)}
    .ct-riwayat{max-height:none}
}
@media(max-width:600px){
    .ct-wrap{padding:1rem .875rem 2.5rem;gap:1rem}
    .ct-stats{grid-template-columns:repeat(2,1fr);gap:.55rem}
    .ct-stat{padding:.75rem;gap:.65rem}
    .ct-stat-ico{width:36px;height:36px}
    .ct-stat-num{font-size:1.25rem}
    .ct-row2{grid-template-columns:1fr}
    .ct-ph-title{font-size:1.15rem}
    .ct-card-body{padding:1rem}
    .ct-ritem-alasan{max-width:100%}
    .ct-drv.driver-popover{max-width:calc(100vw - 2rem) !important}
}
@media(max-width:380px){
    .ct-stats{grid-template-columns:repeat(2,1fr)}
    .ct-stat-lbl{font-size:.6rem}
}

/* ── TOMBOL BATALKAN ─────────────────────────── */
.ct-batal-btn{
    display:inline-flex;align-items:center;gap:.28rem;
    font-size:.63rem;font-weight:700;
    color:#991b1b;background:#fef2f2;
    border:1.5px solid #fecaca;border-radius:6px;
    padding:.22rem .6rem;cursor:pointer;
    transition:all .18s;font-family:inherit;white-space:nowrap;
}
.ct-batal-btn:hover{background:#dc2626;color:#fff;border-color:#dc2626}

/* ── MODAL KONFIRMASI ────────────────────────── */
.ct-modal-overlay{
    display:none;position:fixed;inset:0;z-index:9999;
    background:rgba(14,30,61,.55);backdrop-filter:blur(3px);
    align-items:center;justify-content:center;padding:1.5rem;
}
.ct-modal-overlay.active{display:flex;animation:fadeIn .2s ease}
@keyframes fadeIn{from{opacity:0}to{opacity:1}}
.ct-modal{
    background:#fff;border-radius:var(--radius-xl);
    box-shadow:0 20px 60px rgba(14,30,61,.25),0 0 0 1px rgba(220,38,38,.15);
    max-width:420px;width:100%;overflow:hidden;
    animation:slideUp .22s ease;
}
@keyframes slideUp{from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:none}}
.ct-modal-hd{
    display:flex;align-items:center;gap:.65rem;
    padding:.9rem 1.25rem;
    background:linear-gradient(135deg,#fef2f2,#fff);
    border-bottom:1px solid #fecaca;
}
.ct-modal-hd-ico{
    width:38px;height:38px;border-radius:50%;
    background:#fef2f2;border:2px solid #fecaca;
    display:flex;align-items:center;justify-content:center;
    color:#dc2626;flex-shrink:0;
}
.ct-modal-hd-ico svg{width:17px;height:17px}
.ct-modal-title{font-size:.92rem;font-weight:800;color:#991b1b}
.ct-modal-body{padding:1.1rem 1.25rem}
.ct-modal-desc{font-size:.8rem;color:var(--gray-600);line-height:1.65;margin-bottom:.85rem}
.ct-modal-period{
    display:flex;align-items:center;gap:.5rem;
    background:var(--gray-50);border:1px solid var(--gray-200);
    border-radius:var(--radius);padding:.65rem .9rem;
    font-size:.78rem;font-weight:600;color:var(--navy);
    margin-bottom:1rem;
}
.ct-modal-period svg{color:var(--gray-400);flex-shrink:0;width:13px;height:13px}
.ct-modal-actions{display:flex;gap:.65rem}
.ct-modal-cancel{
    flex:1;display:flex;align-items:center;justify-content:center;gap:.4rem;
    font-size:.8rem;font-weight:600;color:var(--gray-600);
    background:#fff;border:1.5px solid var(--gray-200);
    border-radius:var(--radius);padding:.65rem 1rem;
    cursor:pointer;transition:all .18s;font-family:inherit;
}
.ct-modal-cancel:hover{background:var(--gray-100);border-color:var(--gray-400)}
.ct-modal-confirm{
    flex:1;display:flex;align-items:center;justify-content:center;gap:.4rem;
    font-size:.8rem;font-weight:700;color:#fff;
    background:#dc2626;border:none;
    border-radius:var(--radius);padding:.65rem 1rem;
    cursor:pointer;transition:all .18s;font-family:inherit;
}
.ct-modal-confirm:hover{background:#b91c1c;transform:translateY(-1px);box-shadow:0 4px 14px rgba(220,38,38,.3)}
.ct-modal-confirm:active{transform:none}
.ct-modal-confirm svg,.ct-modal-cancel svg{width:13px;height:13px}
</style>

<div class="main-content">
<div class="ct-wrap">

    <!-- PAGE HEADER -->
    <div class="ct-ph" id="tour-hero">
        <div>
            <div class="ct-ph-eyebrow">
                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                Pengajuan Cuti
            </div>
            <div class="ct-ph-title">Ajukan Cuti Anda</div>
            <div class="ct-ph-sub">Cuti tahunan &amp; khusus yang memerlukan persetujuan atasan</div>
        </div>
        <button class="ct-guide-btn" onclick="startTour()" id="tour-guide-btn">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
            Panduan
        </button>
    </div>

    <!-- STATS -->
    <div class="ct-stats" id="tour-stats">
        <div class="ct-stat">
            <div class="ct-stat-ico total">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
            </div>
            <div><div class="ct-stat-num"><?= $totalCuti ?></div><div class="ct-stat-lbl">Total</div></div>
        </div>
        <div class="ct-stat">
            <div class="ct-stat-ico ok">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            </div>
            <div><div class="ct-stat-num"><?= $totalDisetujui ?></div><div class="ct-stat-lbl">Disetujui</div></div>
        </div>
        <div class="ct-stat">
            <div class="ct-stat-ico wait">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div><div class="ct-stat-num"><?= $totalMenunggu ?></div><div class="ct-stat-lbl">Menunggu</div></div>
        </div>
        <div class="ct-stat">
            <div class="ct-stat-ico no">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
            </div>
            <div><div class="ct-stat-num"><?= $totalDitolak ?></div><div class="ct-stat-lbl">Ditolak</div></div>
        </div>
    </div>

    <!-- ALERTS -->
    <?php if ($successMsg): ?>
    <div class="ct-alert ok" id="pageAlert">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <span><?= $successMsg ?></span>
        <button class="ct-alert-x" onclick="this.parentElement.remove()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
    <div class="ct-alert err" id="pageAlert">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <span><?= $errorMsg ?></span>
        <button class="ct-alert-x" onclick="this.parentElement.remove()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <?php endif; ?>

    <!-- 2-COL LAYOUT -->
    <div class="ct-cols">

        <!-- FORM -->
        <div class="ct-card" id="tour-form">
            <div class="ct-card-hd">
                <div class="ct-card-hd-left">
                    <div class="ct-card-hd-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                    </div>
                    <div>
                        <div class="ct-card-hd-title">Formulir Pengajuan</div>
                        <div class="ct-card-hd-sub">Isi semua field bertanda *</div>
                    </div>
                </div>
            </div>
            <div class="ct-card-body">
                <form method="POST" enctype="multipart/form-data" id="formCuti">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="ajukan">

                    <!-- Tanggal -->
                    <div class="ct-row2" id="tour-tanggal">
                        <div class="ct-field">
                            <label class="ct-label">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                Mulai <span class="ct-req">*</span>
                            </label>
                            <input type="text" name="tanggal_mulai" id="tglMulai" class="ct-input" placeholder="Pilih tanggal mulai" readonly required>
                        </div>
                        <div class="ct-field">
                            <label class="ct-label">
                                <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                Selesai <span class="ct-req">*</span>
                            </label>
                            <input type="text" name="tanggal_selesai" id="tglSelesai" class="ct-input" placeholder="Pilih tanggal selesai" readonly required>
                            <div id="errorTglSelesai" style="display:none;margin-top:5px;font-size:.73rem;color:#dc2626;display:flex;align-items:center;gap:4px;">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                Tanggal selesai tidak boleh sebelum tanggal mulai
                            </div>
                        </div>
                    </div>

                    <!-- Durasi indicator -->
                    <div class="ct-durasi" id="durasiStrip">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span id="durasiText">1 hari</span>
                    </div>

                    <!-- Alasan -->
                    <div class="ct-field" id="tour-alasan">
                        <label class="ct-label">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            Alasan <span class="ct-req">*</span>
                        </label>
                        <textarea name="alasan" class="ct-textarea" rows="4"
                            placeholder="Contoh: Cuti tahunan, keperluan keluarga, pernikahan, dll." required></textarea>
                    </div>

                    <!-- Upload -->
                    <div class="ct-field" id="tour-bukti">
                        <label class="ct-label">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                            Bukti Pendukung <span class="ct-opt">(opsional)</span>
                        </label>
                        <div class="ct-upload" id="uploadZone">
                            <input type="file" name="bukti" id="fileBukti" accept=".jpg,.jpeg,.png,.pdf">
                            <div class="ct-upload-inner" id="uploadInner">
                                <div class="ct-upload-ico">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                                </div>
                                <div class="ct-upload-lbl">Klik atau seret file ke sini</div>
                                <div class="ct-upload-sub">JPG, PNG, PDF · Maks. 2 MB</div>
                            </div>
                        </div>
                        <div class="ct-file-ok" id="filePreview">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                            <span id="fileName">—</span>
                            <button type="button" class="ct-file-rm" id="fileRemove">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            </button>
                        </div>
                    </div>

                    <!-- Info -->
                    <div class="ct-info">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        <span>Setelah dikirim, status menjadi <span class="ct-badge-sm">Menunggu</span>. Anda tetap dapat absen selama cuti belum disetujui.</span>
                    </div>

                    <?php if ($absenHariIni): ?>
                    <!-- Peringatan sudah absen hari ini -->
                    <div id="warningAbsenHariIni" style="display:flex;align-items:flex-start;gap:.65rem;background:#fef2f2;border:1.5px solid #fecaca;border-radius:10px;padding:.85rem 1rem;margin-bottom:1rem">
                        <svg style="flex-shrink:0;margin-top:1px" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        <div>
                            <div style="font-size:.8rem;font-weight:700;color:#dc2626;margin-bottom:.2rem">Tidak Dapat Mengajukan Cuti Hari Ini</div>
                            <div style="font-size:.75rem;color:#991b1b;line-height:1.5">Anda sudah tercatat absen masuk hari ini pukul <strong><?= date('H:i', strtotime($absenHariIni['jam_masuk'])) ?></strong>. Pilih tanggal mulai <strong>besok atau setelahnya</strong> untuk melanjutkan.</div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <button type="submit" id="btnSubmitCuti" class="ct-submit" <?= $absenHariIni ? 'disabled style="opacity:.45;cursor:not-allowed"' : '' ?>>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        Kirim Pengajuan Cuti
                    </button>
                    <?php if ($absenHariIni): ?>
                    <script>
                    // Kalau tanggal mulai diubah ke selain hari ini → aktifkan tombol
                    (function() {
                        const today    = '<?= date('Y-m-d') ?>';
                        const tglInput = document.getElementById('tglMulai');
                        const btnSubmit = document.getElementById('btnSubmitCuti');
                        const warning  = document.getElementById('warningAbsenHariIni');
                        function checkTgl() {
                            const isToday = tglInput.value === today;
                            btnSubmit.disabled = isToday;
                            btnSubmit.style.opacity  = isToday ? '.45' : '';
                            btnSubmit.style.cursor   = isToday ? 'not-allowed' : '';
                            warning.style.display    = isToday ? 'flex' : 'none';
                        }
                        tglInput.addEventListener('change', checkTgl);
                        checkTgl(); // jalankan saat pertama load
                    })();
                    </script>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- RIWAYAT -->
        <div class="ct-card" id="tour-riwayat">
            <div class="ct-card-hd">
                <div class="ct-card-hd-left">
                    <div class="ct-card-hd-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 3h18v18H3zM3 9h18M3 15h18M9 3v18"/></svg>
                    </div>
                    <div>
                        <div class="ct-card-hd-title">Riwayat Pengajuan</div>
                        <div class="ct-card-hd-sub">20 data terbaru</div>
                    </div>
                </div>
                <span class="ct-count"><?= $totalCuti ?></span>
            </div>

            <?php if (empty($cutiList)): ?>
            <div class="ct-empty">
                <div class="ct-empty-ico">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                </div>
                <div class="ct-empty-t">Belum ada riwayat cuti</div>
                <div class="ct-empty-s">Ajukan cuti menggunakan formulir di sebelah</div>
            </div>
            <?php else: ?>
            <div class="ct-riwayat">
                <?php foreach ($cutiList as $idx => $c):
                    $dur = (strtotime($c['tanggal_selesai'])-strtotime($c['tanggal_mulai']))/86400+1;
                    $st  = $c['status']==='disetujui'?'ok':($c['status']==='menunggu'?'wait':'no');
                ?>
                <div class="ct-ritem" style="animation-delay:<?= $idx*25 ?>ms">
                    <div class="ct-ritem-bar <?= $st ?>"></div>
                    <div class="ct-ritem-ico <?= $st ?>">
                        <?php if ($c['status']==='disetujui'): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        <?php elseif ($c['status']==='ditolak'): ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                        <?php else: ?>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?php endif; ?>
                    </div>
                    <div class="ct-ritem-body">
                        <div class="ct-ritem-tgl">
                            <?= fmtTgl($c['tanggal_mulai']) ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                            <?= fmtTgl($c['tanggal_selesai']) ?>
                        </div>
                        <div class="ct-ritem-dur"><?= $dur ?> hari</div>
                        <?php if ($c['alasan']): ?>
                        <div class="ct-ritem-alasan" title="<?= htmlspecialchars($c['alasan']) ?>">
                            <?= htmlspecialchars(mb_substr($c['alasan'],0,50)).(mb_strlen($c['alasan'])>50?'…':'') ?>
                        </div>
                        <?php endif; ?>
                        <?php if ($c['catatan']): ?>
                        <div class="ct-ritem-catatan">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                            <?= htmlspecialchars($c['catatan']) ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div style="display:flex;flex-direction:column;align-items:flex-end;gap:.4rem;flex-shrink:0;">
                        <span class="ct-badge <?= $st ?>">
                            <?php if ($c['status']==='disetujui'): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>Disetujui
                            <?php elseif ($c['status']==='menunggu'): ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>Menunggu
                            <?php else: ?>
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>Ditolak
                            <?php endif; ?>
                        </span>
                        <?php if ($c['status']==='menunggu'): ?>
                        <button type="button" class="ct-batal-btn"
                            onclick="konfirmBatal(<?= $c['id'] ?>, '<?= fmtTgl($c['tanggal_mulai']) ?>', '<?= fmtTgl($c['tanggal_selesai']) ?>')"
                            title="Batalkan pengajuan ini">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                            Batalkan
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /ct-cols -->
</div><!-- /ct-wrap -->
</div><!-- /main-content -->

<!-- MODAL KONFIRMASI BATALKAN -->
<div class="ct-modal-overlay" id="batalModal" role="dialog" aria-modal="true">
    <div class="ct-modal">
        <div class="ct-modal-hd">
            <div class="ct-modal-hd-ico">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <div>
                <div class="ct-modal-title">Batalkan Pengajuan Cuti?</div>
            </div>
        </div>
        <div class="ct-modal-body">
            <p class="ct-modal-desc">Anda akan membatalkan pengajuan cuti berikut. Tindakan ini tidak dapat diurungkan.</p>
            <div class="ct-modal-period">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                <span id="batalPeriodText">—</span>
            </div>
            <div class="ct-modal-actions">
                <button type="button" class="ct-modal-cancel" onclick="tutupModal()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    Tidak, Kembali
                </button>
                <form method="POST" id="formBatal" style="flex:1;margin:0">
                    <?php csrf_field(); ?>
                    <input type="hidden" name="action" value="batalkan">
                    <input type="hidden" name="cuti_id" id="batalCutiId" value="">
                    <button type="submit" class="ct-modal-confirm" style="width:100%">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                        Ya, Batalkan
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// ── MODAL BATALKAN ──────────────────────────────────────────────────────────
function konfirmBatal(cutiId, tglMulai, tglSelesai) {
    document.getElementById('batalCutiId').value = cutiId;
    document.getElementById('batalPeriodText').textContent = tglMulai + ' – ' + tglSelesai;
    document.getElementById('batalModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function tutupModal() {
    document.getElementById('batalModal').classList.remove('active');
    document.body.style.overflow = '';
}
// Tutup modal klik di luar
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('batalModal').addEventListener('click', function(e) {
        if (e.target === this) tutupModal();
    });
});
// Tutup dengan Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') tutupModal();
});

document.addEventListener('DOMContentLoaded', function () {
    const tglM  = document.getElementById('tglMulai');
    const tglS  = document.getElementById('tglSelesai');
    const strip = document.getElementById('durasiStrip');
    const durTx = document.getElementById('durasiText');
    const errTgl = document.getElementById('errorTglSelesai');
    const btnSubmitCuti = document.getElementById('btnSubmitCuti');

    function hitungDurasi() {
        const a = new Date(tglM.value), b = new Date(tglS.value);
        const tglError = tglM.value && tglS.value && b < a;

        // Tampilkan error jika tanggal selesai < mulai
        if (errTgl) errTgl.style.display = tglError ? 'flex' : 'none';
        if (tglS) tglS.style.borderColor = tglError ? '#dc2626' : '';

        // Disable tombol submit jika ada error tanggal
        if (btnSubmitCuti && !btnSubmitCuti.dataset.absenLock) {
            btnSubmitCuti.disabled = tglError;
            btnSubmitCuti.style.opacity = tglError ? '.45' : '';
            btnSubmitCuti.style.cursor  = tglError ? 'not-allowed' : '';
        }

        if (a && b && b >= a) {
            durTx.textContent = (Math.round((b-a)/86400000)+1) + ' hari';
            strip.style.display = 'inline-flex';
        } else { strip.style.display = 'none'; }
    }
    // Tandai tombol supaya validasi absen-lock tidak konflik
    if (btnSubmitCuti && btnSubmitCuti.disabled) btnSubmitCuti.dataset.absenLock = '1';

    tglM.addEventListener('change', () => {
        // Pastikan tanggal selesai min ikut tanggal mulai
        tglS.min = tglM.value;
        // Kalau tanggal selesai sudah lebih kecil dari mulai, reset ke tanggal mulai
        if (tglS.value && tglS.value < tglM.value) {
            tglS.value = tglM.value;
        }
        hitungDurasi();
    });
    tglS.addEventListener('change', hitungDurasi);
    hitungDurasi();

    const zone    = document.getElementById('uploadZone');
    const inp     = document.getElementById('fileBukti');
    const preview = document.getElementById('filePreview');
    const nameEl  = document.getElementById('fileName');
    const rmBtn   = document.getElementById('fileRemove');
    const inner   = document.getElementById('uploadInner');

    function showFile(name) {
        nameEl.textContent = name;
        preview.style.display = 'flex';
        inner.style.opacity = '.4';
    }
    function clearFile() {
        inp.value = '';
        preview.style.display = 'none';
        inner.style.opacity = '1';
    }
    inp.addEventListener('change', function () {
        if (this.files.length) showFile(this.files[0].name);
    });
    rmBtn.addEventListener('click', clearFile);
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('over'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('over'));
    zone.addEventListener('drop', e => {
        e.preventDefault(); zone.classList.remove('over');
        const f = e.dataTransfer.files[0];
        if (f) {
            const dt = new DataTransfer(); dt.items.add(f);
            inp.files = dt.files; showFile(f.name);
        }
    });

    const al = document.getElementById('pageAlert');
    if (al) setTimeout(() => { al.style.opacity='0'; al.style.transition='opacity .4s'; setTimeout(()=>al.remove(),400); }, 5500);
});

const TOUR_KEY = 'cuti_tour_v2';
function loadDriver(cb) {
    if (window.driver && window.driver.js) { cb(); return; }
    const sc = document.createElement('script');
    sc.src = 'https://cdnjs.cloudflare.com/ajax/libs/driver.js/1.3.1/driver.js.iife.js';
    sc.onload = () => setTimeout(cb, 100);
    document.head.appendChild(sc);
}
function startTour() {
    loadDriver(() => {
        const drv = window.driver.js.driver({
            popoverClass:'ct-drv', showProgress:true,
            progressText:'{{current}} / {{total}}',
            nextBtnText:'Lanjut →', prevBtnText:'← Kembali', doneBtnText:'Selesai',
            allowClose:true, smoothScroll:true, animate:true,
            overlayColor:'rgba(14,30,61,.7)',
            onDestroyStarted: () => { localStorage.setItem(TOUR_KEY,'1'); drv.destroy(); },
            steps: [
                { popover:{ title:'Pengajuan Cuti', description:'Halaman ini untuk mengajukan cuti resmi yang memerlukan persetujuan atasan, dan memantau riwayat pengajuan Anda.', side:'over', align:'center' } },
                { element:'#tour-stats', popover:{ title:'Statistik Cuti', description:'Ringkasan total, disetujui, menunggu, dan ditolak secara sekilas.', side:'bottom', align:'start' } },
                { element:'#tour-tanggal', popover:{ title:'Periode Cuti', description:'Pilih tanggal mulai dan selesai. Durasi dihitung otomatis.', side:'bottom', align:'center' } },
                { element:'#tour-alasan', popover:{ title:'Alasan Cuti', description:'Tuliskan alasan dengan jelas agar mudah diproses atasan.', side:'top', align:'center' } },
                { element:'#tour-bukti', popover:{ title:'Bukti Pendukung', description:'Opsional — sertakan dokumen (JPG, PNG, PDF, maks. 2 MB). Bisa diseret langsung ke area upload.', side:'top', align:'center' } },
                { element:'#tour-riwayat', popover:{ title:'Riwayat Pengajuan', description:'Pantau semua pengajuan Anda. Riwayat bisa di-scroll jika lebih dari 5 entri.', side:'left', align:'center' } },
                { element:'#tour-guide-btn', popover:{ title:'Buka Panduan Lagi', description:'Klik kapan saja untuk mengulangi panduan.', side:'bottom', align:'end' } },
            ]
        });
        drv.drive();
    });
}
if (!localStorage.getItem(TOUR_KEY)) {
    window.addEventListener('load', () => setTimeout(startTour, 800));
}
</script>

<!-- Flatpickr -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/id.js"></script>
<style>
/* ── Flatpickr custom theme sesuai palette BBWS ── */
.flatpickr-calendar {
    font-family: 'DM Sans', sans-serif;
    border-radius: 12px;
    border: 1px solid rgba(201,168,76,.25);
    box-shadow: 0 8px 32px rgba(10,22,40,.12);
    overflow: hidden;
}
.flatpickr-months { background: #1A3560; padding: 6px 0; }
.flatpickr-month  { color: #fff; }
.flatpickr-current-month .flatpickr-monthDropdown-months,
.flatpickr-current-month input.cur-year { color: #fff; font-weight: 600; font-size: .88rem; }
.flatpickr-prev-month svg, .flatpickr-next-month svg { fill: rgba(255,255,255,.75); }
.flatpickr-prev-month:hover svg, .flatpickr-next-month:hover svg { fill: #C9A84C; }
.flatpickr-weekdays { background: #1A3560; }
span.flatpickr-weekday { color: rgba(201,168,76,.85); font-size: .72rem; font-weight: 600; letter-spacing: .04em; }
/* Sabtu (6) & Minggu (0) merah */
.flatpickr-day.flatpickr-weekend-sat,
.flatpickr-day.flatpickr-weekend-sun { color: #e05252 !important; }
/* Hari libur nasional */
.flatpickr-day.flatpickr-libur {
    color: #e05252 !important;
    text-decoration: underline dotted;
    position: relative;
}
.flatpickr-day.flatpickr-libur::after {
    content: attr(data-ket);
    position: absolute;
    bottom: 110%;
    left: 50%;
    transform: translateX(-50%);
    background: #1A3560;
    color: #E8C97A;
    font-size: .6rem;
    white-space: nowrap;
    padding: 3px 7px;
    border-radius: 5px;
    pointer-events: none;
    opacity: 0;
    transition: opacity .15s;
    z-index: 999;
}
.flatpickr-day.flatpickr-libur:hover::after { opacity: 1; }
/* Hari disabled (weekend/libur) */
.flatpickr-day.flatpickr-disabled,
.flatpickr-day.flatpickr-disabled:hover {
    color: #e05252 !important;
    background: rgba(224,82,82,.06) !important;
    cursor: not-allowed;
    text-decoration: line-through;
}
/* Selected */
.flatpickr-day.selected, .flatpickr-day.selected:hover {
    background: #C9A84C; border-color: #C9A84C; color: #fff;
}
.flatpickr-day.inRange {
    background: rgba(201,168,76,.15); border-color: transparent;
    box-shadow: -5px 0 0 rgba(201,168,76,.15), 5px 0 0 rgba(201,168,76,.15);
}
.flatpickr-day:hover:not(.flatpickr-disabled):not(.selected) {
    background: rgba(201,168,76,.12); border-color: rgba(201,168,76,.3);
}
.flatpickr-day.today:not(.selected) { border-color: #C9A84C; color: #C9A84C; }
</style>
<script>
(function() {
    const hariLibur = <?= $hariLiburJS ?>;
    const today     = new Date(); today.setHours(0,0,0,0);

    // Set tanggal disable: weekend + hari libur DB
    const liburDates = hariLibur.map(h => h.date);
    const liburMap   = {};
    hariLibur.forEach(h => { liburMap[h.date] = h.ket; });

    function isWeekend(date) { return date.getDay() === 0 || date.getDay() === 6; }
    function isLibur(date)   {
        const str = date.toISOString().split('T')[0];
        return liburDates.includes(str);
    }
    function getLiburKet(date) {
        const str = date.toISOString().split('T')[0];
        return liburMap[str] || null;
    }

    const commonOpts = {
        locale: 'id',
        dateFormat: 'Y-m-d',
        altInput: true,
        altFormat: 'j F Y',
        disableMobile: true,
        minDate: 'today',
        disable: [
            // weekend
            function(date) { return isWeekend(date) || isLibur(date); }
        ],
        onDayCreate: function(dObj, dStr, fp, dayElem) {
            const d = dayElem.dateObj;
            if (!d) return;
            // Sabtu
            if (d.getDay() === 6) dayElem.classList.add('flatpickr-weekend-sat');
            // Minggu
            if (d.getDay() === 0) dayElem.classList.add('flatpickr-weekend-sun');
            // Libur nasional
            const ket = getLiburKet(d);
            if (ket) {
                dayElem.classList.add('flatpickr-libur');
                dayElem.setAttribute('data-ket', ket);
                dayElem.title = ket;
            }
        }
    };

    const fpMulai = flatpickr('#tglMulai', {
        ...commonOpts,
        onChange: function(selectedDates) {
            if (selectedDates[0]) {
                fpSelesai.set('minDate', selectedDates[0]);
                // Kalau tgl selesai lebih kecil dari tgl mulai, reset
                const sd = fpSelesai.selectedDates[0];
                if (sd && sd < selectedDates[0]) fpSelesai.clear();
            }
        }
    });

    const fpSelesai = flatpickr('#tglSelesai', {
        ...commonOpts,
        onChange: function(selectedDates) {
            if (selectedDates[0] && fpMulai.selectedDates[0]) {
                if (selectedDates[0] < fpMulai.selectedDates[0]) {
                    fpSelesai.clear();
                }
            }
        }
    });
})();
</script>

<?php include '../templates/footer.php'; ?>