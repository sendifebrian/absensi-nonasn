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

// ── Proses approval ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    csrf_verify();
    $id      = (int)($_POST['id']      ?? 0);
    $status  = $_POST['status']        ?? '';
    $catatan = trim($_POST['catatan']  ?? '');

    if ($id && in_array($status, ['disetujui', 'ditolak'])) {
        $stmt = $pdo->prepare("UPDATE cuti SET status=?, catatan=?, updated_at=NOW() WHERE id=?");
        $stmt->execute([$status, $catatan, $id]);

        $_SESSION['alert'] = [
            'type'    => $status === 'disetujui' ? 'success' : 'danger',
            'message' => 'Cuti berhasil ' . ($status === 'disetujui' ? 'disetujui' : 'ditolak') . '.'
        ];
        header('Location: cuti.php');
        exit();
    }
}

// ── Filter ────────────────────────────────────────────────────────────
$status_filter = $_GET['status'] ?? 'menunggu';
if (!in_array($status_filter, ['menunggu','disetujui','ditolak','semua'])) {
    $status_filter = 'menunggu';
}

$where  = $status_filter === 'semua' ? '' : "WHERE c.status = ?";
$params = $status_filter === 'semua' ? [] : [$status_filter];

$stmt = $pdo->prepare("
    SELECT c.*, u.nama, u.unit_kerja, u.foto AS foto_pegawai
    FROM cuti c
    JOIN users u ON u.id = c.user_id
    $where
    ORDER BY c.created_at DESC
    LIMIT 50
");
$stmt->execute($params);
$cutiList = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Statistik ─────────────────────────────────────────────────────────
$stmt = $pdo->query("SELECT status, COUNT(*) AS total FROM cuti GROUP BY status");
$stats = ['menunggu' => 0, 'disetujui' => 0, 'ditolak' => 0];
while ($row = $stmt->fetch()) { $stats[$row['status']] = (int)$row['total']; }
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=Geist:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

<div class="main-content ct-root">
<div class="ct-page">

    <!-- ── HERO HEADER ─────────────────────────────────────────── -->
    <header class="ct-hero">
        <div class="ct-hero-glow"></div>
        <div class="ct-hero-grid"></div>
        <div class="ct-hero-inner">
            <div class="ct-hero-left">
                <div class="ct-eyebrow">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><path d="M8 14h.01M12 14h.01M16 14h.01"/></svg>
                    Manajemen Cuti
                </div>
                <h1 class="ct-hero-title">Kelola <em>Pengajuan Cuti</em></h1>
                <p class="ct-hero-sub">Tinjau dan proses pengajuan cuti tahunan &amp; khusus pegawai</p>
            </div>
            <div class="ct-hero-stats">
                <div class="ct-hstat ct-hstat--warn">
                    <div class="ct-hstat-num"><?= $stats['menunggu'] ?></div>
                    <div class="ct-hstat-lbl">Menunggu</div>
                </div>
                <div class="ct-hstat ct-hstat--success">
                    <div class="ct-hstat-num"><?= $stats['disetujui'] ?></div>
                    <div class="ct-hstat-lbl">Disetujui</div>
                </div>
                <div class="ct-hstat ct-hstat--danger">
                    <div class="ct-hstat-num"><?= $stats['ditolak'] ?></div>
                    <div class="ct-hstat-lbl">Ditolak</div>
                </div>
            </div>
        </div>
    </header>

    <!-- ── ALERT ───────────────────────────────────────────────── -->
    <?php if (isset($_SESSION['alert'])): ?>
    <div class="ct-alert ct-alert--<?= $_SESSION['alert']['type'] ?>" id="pageAlert">
        <?php if ($_SESSION['alert']['type'] === 'success'): ?>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
        <?php else: ?>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
        <?php endif; ?>
        <?= htmlspecialchars($_SESSION['alert']['message']) ?>
        <button class="ct-alert-close" onclick="this.parentElement.remove()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
    </div>
    <?php unset($_SESSION['alert']); endif; ?>

    <!-- ── FILTER & SEARCH BAR ────────────────────────────────── -->
    <div class="ct-toolbar">
        <!-- Tab filter status -->
        <div class="ct-tabs">
            <?php foreach(['menunggu'=>'Menunggu','disetujui'=>'Disetujui','ditolak'=>'Ditolak','semua'=>'Semua'] as $val=>$lbl): ?>
            <a href="?status=<?= $val ?>"
               class="ct-tab <?= $status_filter === $val ? 'ct-tab--active' : '' ?>">
                <?= $lbl ?>
                <?php if ($val !== 'semua'): ?>
                <span class="ct-tab-count"><?= $stats[$val] ?? 0 ?></span>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
        <!-- Search -->
        <div class="ct-search-wrap">
            <svg class="ct-search-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="searchInput" class="ct-search" placeholder="Cari nama atau unit kerja…">
        </div>
    </div>

    <!-- ── TABLE CARD ─────────────────────────────────────────── -->
    <div class="ct-card">
        <div class="ct-card-head">
            <div class="ct-card-title">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/></svg>
                Daftar Pengajuan
                <span class="ct-card-count"><?= count($cutiList) ?> data</span>
            </div>
            <div class="ct-card-period"><?= date('F Y') ?></div>
        </div>

        <?php if (empty($cutiList)): ?>
        <div class="ct-empty">
            <div class="ct-empty-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/><line x1="12" y1="14" x2="12" y2="18"/><line x1="10" y1="16" x2="14" y2="16"/></svg>
            </div>
            <div class="ct-empty-title">Tidak ada pengajuan cuti</div>
            <div class="ct-empty-sub">
                <?= $status_filter === 'menunggu' ? 'Semua pengajuan telah diproses atau belum ada yang masuk.' : 'Tidak ada data dengan status "'.$status_filter.'".' ?>
            </div>
        </div>
        <?php else: ?>
        <div class="ct-table-scroll">
            <table class="ct-table">
                <thead>
                    <tr>
                        <th style="width:44px">#</th>
                        <th>Pegawai</th>
                        <th>Periode Cuti</th>
                        <th>Alasan</th>
                        <th class="text-center" style="width:70px">Bukti</th>
                        <th class="text-center" style="width:110px">Status</th>
                        <th class="text-right" style="width:110px">Aksi</th>
                    </tr>
                </thead>
                <tbody id="cutiTableBody">
                    <?php foreach ($cutiList as $i => $c):
                        $durasi = (strtotime($c['tanggal_selesai']) - strtotime($c['tanggal_mulai'])) / 86400 + 1;
                    ?>
                    <tr class="ct-tr ct-tr--<?= $c['status'] ?>"
                        data-nama="<?= strtolower($c['nama']) ?>"
                        data-unit="<?= strtolower($c['unit_kerja'] ?? '') ?>"
                        style="animation-delay:<?= $i * 22 ?>ms">
                        <!-- No -->
                        <td><span class="ct-no"><?= $i + 1 ?></span></td>

                        <!-- Pegawai -->
                        <td>
                            <div class="ct-pegawai">
                                <?php if ($c['foto_pegawai']): ?>
                                <img src="<?= htmlspecialchars(foto_url($c['foto_pegawai'])) ?>"
                                     class="ct-avatar-img"
                                     onerror="this.style.display='none';this.nextElementSibling.style.display='flex'"
                                     alt="">
                                <div class="ct-avatar" style="display:none"><?= strtoupper(substr($c['nama'],0,1)) ?></div>
                                <?php else: ?>
                                <div class="ct-avatar"><?= strtoupper(substr($c['nama'],0,1)) ?></div>
                                <?php endif; ?>
                                <div>
                                    <div class="ct-peg-nama"><?= htmlspecialchars($c['nama']) ?></div>
                                    <div class="ct-peg-unit"><?= htmlspecialchars($c['unit_kerja'] ?? '—') ?></div>
                                </div>
                            </div>
                        </td>

                        <!-- Periode -->
                        <td>
                            <div class="ct-periode">
                                <div class="ct-tgl-from"><?= date('d M Y', strtotime($c['tanggal_mulai'])) ?></div>
                                <div class="ct-tgl-arrow">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                                    <?= date('d M Y', strtotime($c['tanggal_selesai'])) ?>
                                </div>
                                <div class="ct-durasi">
                                    <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <?= $durasi ?> hari
                                </div>
                            </div>
                        </td>

                        <!-- Alasan -->
                        <td>
                            <span class="ct-alasan" title="<?= htmlspecialchars($c['alasan']) ?>">
                                <?= strlen($c['alasan']) > 52 ? htmlspecialchars(substr($c['alasan'],0,49)).'…' : htmlspecialchars($c['alasan']) ?>
                            </span>
                            <?php if ($c['catatan']): ?>
                            <div class="ct-note" title="Catatan: <?= htmlspecialchars($c['catatan']) ?>">
                                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                <?= htmlspecialchars($c['catatan']) ?>
                            </div>
                            <?php endif; ?>
                        </td>

                        <!-- Bukti -->
                        <td class="text-center">
                            <?php if ($c['bukti']): ?>
                            <button class="ct-icon-btn ct-icon-btn--gold" title="Lihat Bukti"
                                    onclick="lihatBukti('<?= htmlspecialchars($c['bukti']) ?>')">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                            </button>
                            <?php else: ?>
                            <span class="ct-dash">—</span>
                            <?php endif; ?>
                        </td>

                        <!-- Status -->
                        <td class="text-center">
                            <?php if ($c['status'] === 'menunggu'): ?>
                            <span class="ct-badge ct-badge--warn">
                                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                Menunggu
                            </span>
                            <?php elseif ($c['status'] === 'disetujui'): ?>
                            <span class="ct-badge ct-badge--success">
                                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                Disetujui
                            </span>
                            <?php else: ?>
                            <span class="ct-badge ct-badge--danger">
                                <svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                Ditolak
                            </span>
                            <?php endif; ?>
                        </td>

                        <!-- Aksi -->
                        <td class="text-right">
                            <?php if ($c['status'] === 'menunggu'): ?>
                            <div class="ct-actions">
                                <button class="ct-act-btn ct-act-btn--approve"
                                        data-id="<?= $c['id'] ?>"
                                        data-status="disetujui"
                                        data-nama="<?= htmlspecialchars($c['nama'], ENT_QUOTES) ?>"
                                        data-tgl="<?= date('d M Y', strtotime($c['tanggal_mulai'])) ?> – <?= date('d M Y', strtotime($c['tanggal_selesai'])) ?>"
                                        data-durasi="<?= $durasi ?>"
                                        title="Setujui">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                                </button>
                                <button class="ct-act-btn ct-act-btn--reject"
                                        data-id="<?= $c['id'] ?>"
                                        data-status="ditolak"
                                        data-nama="<?= htmlspecialchars($c['nama'], ENT_QUOTES) ?>"
                                        data-tgl="<?= date('d M Y', strtotime($c['tanggal_mulai'])) ?> – <?= date('d M Y', strtotime($c['tanggal_selesai'])) ?>"
                                        data-durasi="<?= $durasi ?>"
                                        title="Tolak">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                                </button>
                            </div>
                            <?php else: ?>
                            <button class="ct-icon-btn"
                                    onclick="showDetail('<?= htmlspecialchars($c['nama'],ENT_QUOTES) ?>','<?= date('d M Y',strtotime($c['tanggal_mulai'])) ?> – <?= date('d M Y',strtotime($c['tanggal_selesai'])) ?>','<?= ucfirst($c['status']) ?>','<?= htmlspecialchars($c['catatan']??'—',ENT_QUOTES) ?>')"
                                    title="Detail">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

</div><!-- /ct-page -->
</div><!-- /main-content -->

<!-- ══════════════════════════════
     MODAL: APPROVAL
     ══════════════════════════════ -->
<div class="modal fade" id="modalApproval" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content ct-modal">
            <form method="POST" action="cuti.php">
                <?php csrf_field(); ?>
                <input type="hidden" name="action"  value="approve">
                <input type="hidden" name="status"  id="mStatus">
                <input type="hidden" name="id"      id="mId">

                <div class="ct-modal-head" id="mHead">
                    <div class="ct-modal-icon" id="mIcon"></div>
                    <div>
                        <div class="ct-modal-title" id="mTitle">Konfirmasi</div>
                        <div class="ct-modal-sub" id="mSub"></div>
                    </div>
                    <button type="button" class="ct-modal-close" data-bs-dismiss="modal">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>

                <!-- Info strip -->
                <div class="ct-modal-info-strip" id="mStrip">
                    <div class="ct-modal-info-item">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        <span id="mNama">—</span>
                    </div>
                    <div class="ct-modal-info-item">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                        <span id="mTgl">—</span>
                    </div>
                    <div class="ct-modal-info-item">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <span id="mDurasi">—</span>
                    </div>
                </div>

                <div class="ct-modal-body">
                    <label class="ct-modal-label">Catatan untuk Pegawai <span class="ct-optional">(opsional)</span></label>
                    <textarea name="catatan" class="ct-modal-textarea" rows="3"
                              placeholder="Contoh: Cuti disetujui, harap koordinasi sebelum berangkat…"></textarea>

                    <div class="ct-modal-notice" id="mNotice"></div>
                </div>

                <div class="ct-modal-foot">
                    <button type="button" class="ct-modal-cancel" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="ct-modal-submit" id="mSubmit">Setujui</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ══════════════════════════════
     MODAL: BUKTI
     ══════════════════════════════ -->
<div class="modal fade" id="modalBukti" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content ct-modal">
            <div class="ct-modal-head ct-modal-head--gold">
                <div class="ct-modal-icon ct-modal-icon--gold">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/></svg>
                </div>
                <div class="ct-modal-title">Bukti Pengajuan Cuti</div>
                <button type="button" class="ct-modal-close" data-bs-dismiss="modal">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="ct-modal-body text-center ct-bukti-modal-body">
                <img id="buktiImg" src="" class="ct-bukti-img" alt="Bukti">
                <div id="buktiCaption" class="ct-bukti-caption"></div>
            </div>
            <div class="ct-modal-foot">
                <button type="button" class="ct-modal-submit" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════
     MODAL: DETAIL
     ══════════════════════════════ -->
<div class="modal fade" id="modalDetail" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content ct-modal">
            <div class="ct-modal-head ct-modal-head--slate">
                <div class="ct-modal-icon ct-modal-icon--slate">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                </div>
                <div class="ct-modal-title">Detail Cuti</div>
                <button type="button" class="ct-modal-close" data-bs-dismiss="modal">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>
            <div class="ct-modal-body" id="detailBody"></div>
            <div class="ct-modal-foot">
                <button type="button" class="ct-modal-submit" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     STYLES
     ══════════════════════════════════════════════════════ -->
<style>
:root{
    --gold:#C9A84C; --gold-lt:#FBF3DE; --gold-dk:#A8862E;
    --navy:#0E1E3D; --navy-2:#172B55; --navy-3:#1E3A6E;
    --slate:#64748B; --slate-lt:#F1F5F9; --slate-2:#E2E8F0; --slate-3:#CBD5E1;
    --white:#FFFFFF; --text:#1E293B; --text-2:#475569; --text-3:#94A3B8;
    --green:#16a34a; --green-lt:#dcfce7;
    --amber:#d97706; --amber-lt:#fef3c7;
    --red:#dc2626;   --red-lt:#fee2e2;
    --r-sm:6px; --r-md:10px; --r-lg:14px; --r-xl:18px;
    --sh-sm:0 1px 3px rgba(0,0,0,.06); --sh-md:0 4px 16px rgba(0,0,0,.08);
    --font-head:'DM Serif Display',Georgia,serif;
    --font-body:'Geist','SF Pro Display',system-ui,sans-serif;
}
.ct-root *{box-sizing:border-box;margin:0;padding:0}
.ct-root{font-family:var(--font-body);color:var(--text);background:#F8FAFC;min-height:100vh}
.ct-page{max-width:1440px;margin:0 auto;padding:1.5rem 1.75rem 4rem;display:flex;flex-direction:column;gap:1.15rem}
.text-center{text-align:center} .text-right{text-align:right}

/* ── HERO ── */
.ct-hero{
    position:relative;
    background:linear-gradient(135deg,var(--navy) 0%,var(--navy-3) 60%,#1B3A72 100%);
    border-radius:var(--r-xl);overflow:hidden;
    box-shadow:0 8px 32px rgba(14,30,61,.28);
}
.ct-hero-glow{
    position:absolute;inset:0;pointer-events:none;
    background:radial-gradient(ellipse 60% 80% at 90% 50%,rgba(201,168,76,.14) 0%,transparent 70%);
}
.ct-hero-grid{
    position:absolute;inset:0;pointer-events:none;z-index:0;
    background-image:linear-gradient(rgba(255,255,255,.028) 1px,transparent 1px),
                     linear-gradient(90deg,rgba(255,255,255,.028) 1px,transparent 1px);
    background-size:32px 32px;
}
.ct-hero-inner{
    position:relative;z-index:1;
    display:flex;justify-content:space-between;align-items:center;
    flex-wrap:wrap;gap:1.5rem;padding:2rem 2.25rem;
}
.ct-eyebrow{
    display:inline-flex;align-items:center;gap:.4rem;
    font-size:.6rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;
    color:var(--gold);margin-bottom:.55rem;
}
.ct-hero-title{
    font-family:var(--font-head);
    font-size:1.9rem;font-weight:400;line-height:1.1;color:#fff;margin-bottom:.4rem;
}
.ct-hero-title em{color:var(--gold);font-style:italic}
.ct-hero-sub{font-size:.75rem;color:rgba(255,255,255,.5)}

/* Hero stats */
.ct-hero-stats{display:flex;gap:.75rem}
.ct-hstat{
    background:rgba(255,255,255,.07);
    border:1px solid rgba(255,255,255,.12);
    border-radius:var(--r-lg);padding:1rem 1.25rem;
    text-align:center;min-width:88px;
    transition:all .2s;
}
.ct-hstat:hover{background:rgba(255,255,255,.11);transform:translateY(-2px)}
.ct-hstat-num{font-size:1.75rem;font-weight:800;line-height:1;margin-bottom:.18rem}
.ct-hstat-lbl{font-size:.6rem;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.5)}
.ct-hstat--warn   .ct-hstat-num{color:#FCD34D}
.ct-hstat--success.ct-hstat-num{color:#6EE7B7}
.ct-hstat--danger .ct-hstat-num{color:#FCA5A5}

/* ── ALERT ── */
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
.ct-alert{
    display:flex;align-items:center;gap:.75rem;padding:.85rem 1.1rem;
    border-radius:var(--r-md);font-size:.78rem;font-weight:500;
    animation:slideDown .3s ease;
}
.ct-alert--success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.ct-alert--danger {background:#fef2f2;border:1px solid #fca5a5;color:#991b1b}
.ct-alert-close{
    margin-left:auto;background:none;border:none;cursor:pointer;
    color:inherit;opacity:.6;padding:.2rem;border-radius:4px;
}
.ct-alert-close:hover{opacity:1}

/* ── TOOLBAR ── */
.ct-toolbar{display:flex;align-items:center;gap:.85rem;flex-wrap:wrap}
.ct-tabs{display:flex;background:var(--white);border:1px solid var(--slate-2);border-radius:var(--r-md);overflow:hidden;flex-shrink:0}
.ct-tab{
    display:inline-flex;align-items:center;gap:.4rem;
    padding:.55rem 1rem;font-size:.75rem;font-weight:600;
    color:var(--text-2);text-decoration:none;transition:all .15s;
    border-right:1px solid var(--slate-2);white-space:nowrap;
}
.ct-tab:last-child{border-right:none}
.ct-tab:hover{background:var(--slate-lt);color:var(--navy)}
.ct-tab--active{background:var(--navy);color:var(--white) !important}
.ct-tab-count{
    font-size:.6rem;font-weight:700;padding:.1rem .42rem;
    border-radius:20px;background:rgba(255,255,255,.18);
}
.ct-tab--active .ct-tab-count{background:rgba(255,255,255,.2);color:#fff}
.ct-tab:not(.ct-tab--active) .ct-tab-count{background:var(--slate-lt);color:var(--slate)}

.ct-search-wrap{position:relative;flex:1;max-width:320px}
.ct-search-ico{
    position:absolute;left:.75rem;top:50%;transform:translateY(-50%);
    width:14px;height:14px;color:var(--text-3);pointer-events:none;
}
.ct-search{
    width:100%;padding:.55rem .85rem .55rem 2.2rem;
    border:1.5px solid var(--slate-2);border-radius:var(--r-md);
    font-family:var(--font-body);font-size:.78rem;color:var(--text);
    background:#fff;outline:none;transition:border-color .2s;
}
.ct-search:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.12)}

/* ── TABLE CARD ── */
.ct-card{
    background:var(--white);border:1px solid var(--slate-2);
    border-radius:var(--r-lg);overflow:hidden;box-shadow:var(--sh-sm);
}
.ct-card-head{
    display:flex;justify-content:space-between;align-items:center;
    padding:.9rem 1.5rem;
    background:linear-gradient(to right,#FAFBFC,var(--white));
    border-bottom:2px solid var(--gold);
}
.ct-card-title{
    display:flex;align-items:center;gap:.5rem;
    font-size:.82rem;font-weight:700;color:var(--navy);
}
.ct-card-title svg{color:var(--gold)}
.ct-card-count{
    font-size:.65rem;font-weight:700;color:var(--navy);
    background:var(--gold-lt);border:1px solid rgba(201,168,76,.3);
    padding:.17rem .6rem;border-radius:20px;margin-left:.25rem;
}
.ct-card-period{font-size:.72rem;color:var(--text-3);font-weight:500}

/* Table */
.ct-table-scroll{overflow-x:auto}
.ct-table{width:100%;border-collapse:collapse;font-size:.8rem}
.ct-table thead tr{background:linear-gradient(to right,var(--navy),var(--navy-2))}
.ct-table thead th{
    padding:.72rem 1rem;
    font-size:.58rem;font-weight:700;text-transform:uppercase;
    letter-spacing:.1em;color:rgba(255,255,255,.55);
    border:none;white-space:nowrap;
}
.ct-table thead th:first-child{padding-left:1.5rem}
.ct-table thead th:last-child{padding-right:1.5rem}

@keyframes rowIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
.ct-tr{border-bottom:1px solid var(--slate-2);transition:background .12s;animation:rowIn .28s ease both}
.ct-tr:last-child{border-bottom:none}
.ct-tr:hover{background:var(--slate-lt)}
.ct-tr--disetujui{background:rgba(22,163,74,.03)}
.ct-tr--ditolak  {background:rgba(220,38,38,.03)}
.ct-table tbody td{padding:.85rem 1rem;vertical-align:middle;border:none}
.ct-table tbody td:first-child{padding-left:1.5rem}
.ct-table tbody td:last-child{padding-right:1.5rem}

/* Cell components */
.ct-no{font-size:.72rem;font-weight:700;color:var(--gold)}
.ct-pegawai{display:flex;align-items:center;gap:.65rem}
.ct-avatar{
    width:32px;height:32px;border-radius:50%;flex-shrink:0;
    background:linear-gradient(135deg,var(--navy-2),var(--navy-3));
    color:#fff;font-size:.7rem;font-weight:700;
    display:flex;align-items:center;justify-content:center;
}
.ct-avatar-img{
    width:32px;height:32px;border-radius:50%;object-fit:cover;flex-shrink:0;
    border:2px solid var(--slate-2);
}
.ct-peg-nama{font-weight:600;color:var(--navy);font-size:.82rem}
.ct-peg-unit{font-size:.68rem;color:var(--text-3);margin-top:.1rem}

.ct-periode{display:flex;flex-direction:column;gap:.1rem}
.ct-tgl-from{font-weight:700;color:var(--navy);font-size:.8rem}
.ct-tgl-arrow{
    display:flex;align-items:center;gap:.35rem;
    font-size:.72rem;color:var(--text-2);
}
.ct-durasi{
    display:inline-flex;align-items:center;gap:.28rem;
    font-size:.65rem;color:var(--text-3);margin-top:.08rem;
}

.ct-alasan{font-size:.75rem;color:var(--text-2);cursor:default}
.ct-note{
    display:inline-flex;align-items:center;gap:.28rem;
    font-size:.63rem;color:var(--slate);margin-top:.22rem;cursor:help;
}

/* Badges */
.ct-badge{
    display:inline-flex;align-items:center;gap:.3rem;
    padding:.22rem .62rem;border-radius:20px;
    font-size:.66rem;font-weight:700;white-space:nowrap;
}
.ct-badge--warn   {background:var(--amber-lt);color:#92400e}
.ct-badge--success{background:var(--green-lt);color:#15803d}
.ct-badge--danger {background:var(--red-lt);  color:#991b1b}

/* Action buttons */
.ct-actions{display:flex;justify-content:flex-end;gap:.45rem}
.ct-act-btn{
    width:38px;height:38px;border-radius:var(--r-md);
    display:flex;align-items:center;justify-content:center;
    border:none;cursor:pointer;transition:all .22s cubic-bezier(.34,1.56,.64,1);
    color:#fff;flex-shrink:0;
}
.ct-act-btn--approve{background:linear-gradient(135deg,#16a34a,#15803d);box-shadow:0 2px 8px rgba(22,163,74,.3)}
.ct-act-btn--approve:hover{transform:scale(1.12);box-shadow:0 4px 14px rgba(22,163,74,.45)}
.ct-act-btn--reject {background:linear-gradient(135deg,#dc2626,#b91c1c);box-shadow:0 2px 8px rgba(220,38,38,.3)}
.ct-act-btn--reject:hover {transform:scale(1.12);box-shadow:0 4px 14px rgba(220,38,38,.45)}

.ct-icon-btn{
    width:32px;height:32px;border-radius:var(--r-sm);
    display:inline-flex;align-items:center;justify-content:center;
    background:var(--slate-lt);border:1.5px solid var(--slate-2);
    color:var(--slate);cursor:pointer;transition:all .2s;
}
.ct-icon-btn:hover{border-color:var(--navy);color:var(--navy);background:#fff}
.ct-icon-btn--gold:hover{border-color:var(--gold);color:var(--gold-dk);background:var(--gold-lt)}
.ct-dash{color:var(--text-3);font-size:.75rem}

/* Empty */
.ct-empty{display:flex;flex-direction:column;align-items:center;padding:4rem 2rem;gap:.75rem}
.ct-empty-icon{
    width:64px;height:64px;border-radius:var(--r-xl);
    background:var(--slate-lt);display:flex;align-items:center;justify-content:center;
}
.ct-empty-icon svg{width:26px;height:26px;color:var(--text-3)}
.ct-empty-title{font-size:.9rem;font-weight:700;color:var(--text-2)}
.ct-empty-sub{font-size:.75rem;color:var(--text-3);text-align:center}

/* ── MODAL ── */
.ct-modal{border:none;border-radius:var(--r-xl);overflow:hidden;box-shadow:0 24px 64px rgba(14,30,61,.2),0 0 0 1px rgba(201,168,76,.15)}
.ct-modal-head{
    display:flex;align-items:center;gap:.85rem;
    padding:1.1rem 1.35rem;
    background:linear-gradient(135deg,var(--navy),var(--navy-3));
    border-bottom:2px solid var(--gold);position:relative;
}
.ct-modal-head--gold{background:linear-gradient(135deg,var(--gold-dk),var(--gold))}
.ct-modal-head--slate{background:linear-gradient(135deg,#334155,var(--slate))}
.ct-modal-icon{
    width:42px;height:42px;border-radius:var(--r-md);
    background:rgba(255,255,255,.12);
    display:flex;align-items:center;justify-content:center;flex-shrink:0;
    color:#fff;
}
.ct-modal-icon--gold{background:rgba(255,255,255,.15)}
.ct-modal-icon--slate{background:rgba(255,255,255,.12)}
.ct-modal-title{font-size:.9rem;font-weight:700;color:#fff}
.ct-modal-sub{font-size:.72rem;color:rgba(255,255,255,.55);margin-top:.1rem}
.ct-modal-close{
    margin-left:auto;background:rgba(255,255,255,.1);border:none;
    width:30px;height:30px;border-radius:var(--r-sm);
    display:flex;align-items:center;justify-content:center;
    color:rgba(255,255,255,.7);cursor:pointer;transition:all .2s;flex-shrink:0;
}
.ct-modal-close:hover{background:rgba(255,255,255,.2);color:#fff}

.ct-modal-info-strip{
    display:flex;flex-direction:column;gap:.5rem;
    padding:.9rem 1.35rem;
    background:var(--slate-lt);border-bottom:1px solid var(--slate-2);
}
.ct-modal-info-item{
    display:flex;align-items:center;gap:.55rem;
    font-size:.76rem;color:var(--text-2);
}
.ct-modal-info-item svg{color:var(--gold);flex-shrink:0}

.ct-modal-body{padding:1.15rem 1.35rem}
.ct-modal-label{font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-2);display:block;margin-bottom:.4rem}
.ct-optional{font-weight:400;text-transform:none;letter-spacing:0;color:var(--text-3)}
.ct-modal-textarea{
    width:100%;padding:.65rem .9rem;
    border:1.5px solid var(--slate-2);border-radius:var(--r-md);
    font-family:var(--font-body);font-size:.8rem;color:var(--text);
    resize:vertical;outline:none;transition:border-color .2s;
}
.ct-modal-textarea:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,.12)}
.ct-modal-notice{
    margin-top:.85rem;padding:.65rem .85rem;border-radius:var(--r-sm);
    font-size:.73rem;line-height:1.55;
}
.ct-modal-notice--success{background:var(--green-lt);color:#166534;border-left:3px solid var(--green)}
.ct-modal-notice--danger {background:var(--red-lt);  color:#991b1b;border-left:3px solid var(--red)}

.ct-modal-foot{
    display:flex;justify-content:flex-end;gap:.6rem;
    padding:.9rem 1.35rem;
    background:var(--slate-lt);border-top:1px solid var(--slate-2);
}
.ct-modal-cancel{
    font-family:var(--font-body);font-size:.76rem;font-weight:600;
    color:var(--text-2);background:#fff;border:1.5px solid var(--slate-2);
    border-radius:var(--r-sm);padding:.48rem 1.1rem;cursor:pointer;transition:all .2s;
}
.ct-modal-cancel:hover{border-color:var(--slate-3);color:var(--text)}
.ct-modal-submit{
    font-family:var(--font-body);font-size:.76rem;font-weight:700;
    color:#fff;background:var(--navy);border:none;
    border-radius:var(--r-sm);padding:.48rem 1.25rem;cursor:pointer;transition:all .2s;
}
.ct-modal-submit:hover{background:var(--navy-3);transform:translateY(-1px)}
.ct-modal-submit--success{background:linear-gradient(135deg,#16a34a,#15803d) !important}
.ct-modal-submit--danger {background:linear-gradient(135deg,#dc2626,#b91c1c) !important}

.ct-bukti-modal-body{padding:1rem 1.35rem;overflow-y:auto;max-height:80vh}
.ct-bukti-img{max-width:100%;max-height:none;width:100%;height:auto;object-fit:contain;border-radius:var(--r-md);box-shadow:var(--sh-md);display:block}
.ct-bukti-pdf-link{display:inline-flex;align-items:center;gap:.5rem;padding:.65rem 1.2rem;background:#e0e7ff;border:1px solid #c7d2fe;border-radius:var(--r-md);color:#4338ca;font-size:.85rem;font-weight:700;text-decoration:none;margin-top:.5rem}
.ct-bukti-pdf-link:hover{background:#c7d2fe;color:#3730a3}
.ct-bukti-caption{font-size:.72rem;color:var(--text-3);margin-top:.65rem}

/* Detail body */
.ct-detail-grid{display:flex;flex-direction:column;gap:.6rem}
.ct-detail-row{display:flex;gap:.65rem}
.ct-detail-key{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-3);min-width:90px;padding-top:.1rem}
.ct-detail-val{font-size:.8rem;color:var(--text);font-weight:500}

/* Responsive */
@media(max-width:900px){
    .ct-page{padding:1rem 1rem 3rem}
    .ct-hero-inner{padding:1.5rem 1.25rem}
    .ct-hero-title{font-size:1.5rem}
    .ct-table thead th:nth-child(4),
    .ct-table tbody td:nth-child(4){display:none}
}
@media(max-width:640px){
    .ct-toolbar{gap:.6rem}
    .ct-hero-stats{gap:.45rem}
    .ct-hstat{min-width:72px;padding:.75rem .9rem}
    .ct-hstat-num{font-size:1.4rem}
    .ct-table thead th:nth-child(5),
    .ct-table tbody td:nth-child(5){display:none}
}
</style>

<!-- ══════════════════════════════
     JAVASCRIPT
     ══════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function () {

    // ── Search ──────────────────────────────────────────────────
    document.getElementById('searchInput').addEventListener('keyup', function () {
        const q = this.value.toLowerCase().trim();
        document.querySelectorAll('#cutiTableBody .ct-tr').forEach(row => {
            const match = (row.dataset.nama||'').includes(q) || (row.dataset.unit||'').includes(q);
            row.style.display = match ? '' : 'none';
        });
    });

    // ── Approval buttons ────────────────────────────────────────
    document.querySelectorAll('.ct-act-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const id     = this.dataset.id;
            const status = this.dataset.status;
            const nama   = this.dataset.nama;
            const tgl    = this.dataset.tgl;
            const durasi = this.dataset.durasi;
            const isApprove = status === 'disetujui';

            document.getElementById('mId').value     = id;
            document.getElementById('mStatus').value = status;
            document.getElementById('mNama').textContent   = nama;
            document.getElementById('mTgl').textContent    = tgl;
            document.getElementById('mDurasi').textContent = durasi + ' hari';

            const iconEl   = document.getElementById('mIcon');
            const titleEl  = document.getElementById('mTitle');
            const subEl    = document.getElementById('mSub');
            const noticeEl = document.getElementById('mNotice');
            const submitEl = document.getElementById('mSubmit');
            const headEl   = document.getElementById('mHead');

            if (isApprove) {
                iconEl.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>';
                titleEl.textContent = 'Setujui Pengajuan Cuti';
                subEl.textContent   = 'Tindakan ini akan mengonfirmasi cuti pegawai';
                noticeEl.className  = 'ct-modal-notice ct-modal-notice--success';
                noticeEl.textContent= 'Setelah disetujui, catatan cuti akan tersimpan dan status berubah menjadi Disetujui.';
                submitEl.className  = 'ct-modal-submit ct-modal-submit--success';
                submitEl.textContent= 'Setujui Cuti';
            } else {
                iconEl.innerHTML = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
                titleEl.textContent = 'Tolak Pengajuan Cuti';
                subEl.textContent   = 'Tindakan ini akan menolak permintaan cuti pegawai';
                noticeEl.className  = 'ct-modal-notice ct-modal-notice--danger';
                noticeEl.textContent= 'Pegawai akan melihat status Ditolak beserta catatan yang Anda tulis.';
                submitEl.className  = 'ct-modal-submit ct-modal-submit--danger';
                submitEl.textContent= 'Tolak Cuti';
            }

            new bootstrap.Modal(document.getElementById('modalApproval')).show();
        });
    });

    // ── Lihat Bukti ───────────────────────────────────────────
    window.lihatBukti = function (filename) {
        const img = document.getElementById('buktiImg');
        const cap = document.getElementById('buktiCaption');
        const ext = filename.split('.').pop().toLowerCase();
        // Hitung base path dinamis — bukan hardcode /absensi-nonasn
        const basePath = '/' + location.pathname.replace(/^\//, '').split('/')[0];
        // Bukti cuti disimpan di uploads/selfie/ — pakai type=selfie
        const url = basePath + '/foto.php?type=selfie&file=' + encodeURIComponent(filename.split('/').pop());

        // Reset state sebelum tampil
        img.style.display = '';
        img.src = '';
        cap.innerHTML = '';

        if (['jpg','jpeg','png','gif','webp'].includes(ext)) {
            img.src = url;
            cap.textContent = 'Bukti dalam format gambar';
        } else if (ext === 'pdf') {
            img.style.display = 'none';
            cap.innerHTML = '<a href="' + url + '" target="_blank" class="ct-bukti-pdf-link">'
                + '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>'
                + ' Buka Bukti PDF</a>';
        } else {
            img.style.display = 'none';
            cap.textContent = 'File: ' + filename;
        }
        new bootstrap.Modal(document.getElementById('modalBukti')).show();
    };

    // ── Detail cuti (non-pending) ────────────────────────────────
    window.showDetail = function (nama, tgl, status, catatan) {
        const statusColors = {Disetujui:'#166534',Ditolak:'#991b1b',Menunggu:'#92400e'};
        document.getElementById('detailBody').innerHTML = `
            <div class="ct-detail-grid">
                <div class="ct-detail-row">
                    <div class="ct-detail-key">Pegawai</div>
                    <div class="ct-detail-val">${nama}</div>
                </div>
                <div class="ct-detail-row">
                    <div class="ct-detail-key">Periode</div>
                    <div class="ct-detail-val">${tgl}</div>
                </div>
                <div class="ct-detail-row">
                    <div class="ct-detail-key">Status</div>
                    <div class="ct-detail-val"><strong style="color:${statusColors[status]||'#333'}">${status}</strong></div>
                </div>
                <div class="ct-detail-row">
                    <div class="ct-detail-key">Catatan</div>
                    <div class="ct-detail-val">${catatan}</div>
                </div>
            </div>`;
        new bootstrap.Modal(document.getElementById('modalDetail')).show();
    };

    // ── Auto dismiss alert ───────────────────────────────────────
    const alert = document.getElementById('pageAlert');
    if (alert) setTimeout(() => alert.remove(), 5000);
});
</script>

<?php include '../templates/footer.php'; ?>