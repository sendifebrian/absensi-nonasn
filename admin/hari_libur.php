<?php
require_once __DIR__ . '/../config/init.php';
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_admin()) { header('Location: dashboard.php'); exit(); }

/* ─── API & Fallback ─────────────────────────────────────────────────────── */
function getHariLiburDariAPI($tahun) {
    $url = "https://libur.deno.dev/api?year=" . intval($tahun);
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [CURLOPT_URL=>$url,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_USERAGENT=>'Mozilla/5.0 BBWS-AbsensiApp/1.0']);
        $raw=curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        if ($raw===false||$err||$code!==200) return null;
    } elseif (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http'=>['timeout'=>8,'ignore_errors'=>true]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw===false) return null;
    } else { return null; }
    $json = json_decode($raw, true);
    if (!is_array($json)||empty($json)) return null;
    $data = [];
    foreach ($json as $item) {
        if (isset($item['date']) && isset($item['name'])) {
            $isCuti = isset($item['is_cuti']) ? (bool)$item['is_cuti'] : (stripos($item['name'],'cuti')!==false);
            $data[] = ['date'=>$item['date'],'name'=>$item['name'],'is_cuti'=>$isCuti];
        }
    }
    usort($data, function($a,$b){ return strcmp($a['date'],$b['date']); });
    return count($data)>0 ? $data : null;
}

function getHariLiburFallback($tahun) {
    $fb = [
        2025=>[
            ['2025-01-01','Tahun Baru Masehi 2025',false],['2025-01-27','Isra Mikraj Nabi Muhammad SAW',false],
            ['2025-01-28','Cuti Bersama Isra Mikraj',true],['2025-01-29','Tahun Baru Imlek 2576',false],
            ['2025-03-29','Hari Suci Nyepi',false],['2025-03-31','Idulfitri 1446 H',false],
            ['2025-04-01','Idulfitri 1446 H (Hari Kedua)',false],['2025-04-02','Cuti Bersama Idulfitri',true],
            ['2025-04-03','Cuti Bersama Idulfitri',true],['2025-04-04','Cuti Bersama Idulfitri',true],
            ['2025-04-07','Cuti Bersama Idulfitri',true],['2025-04-18','Wafat Yesus Kristus',false],
            ['2025-05-01','Hari Buruh Internasional',false],['2025-05-12','Hari Raya Waisak 2569 BE',false],
            ['2025-05-29','Kenaikan Yesus Kristus',false],['2025-05-30','Cuti Bersama Kenaikan Yesus Kristus',true],
            ['2025-06-01','Hari Lahir Pancasila',false],['2025-06-06','Idul Adha 1446 H',false],
            ['2025-06-27','Tahun Baru Islam 1447 H',false],['2025-08-17','Hari Kemerdekaan Republik Indonesia',false],
            ['2025-09-05','Maulid Nabi Muhammad SAW',false],['2025-12-25','Hari Raya Natal',false],
            ['2025-12-26','Cuti Bersama Natal',true]
        ],
        2026=>[
            ['2026-01-01','Tahun Baru Masehi 2026',false],['2026-01-16','Isra Mikraj Nabi Muhammad SAW',false],
            ['2026-02-16','Cuti Bersama Tahun Baru Imlek',true],['2026-02-17','Tahun Baru Imlek 2577 Kongzili',false],
            ['2026-03-18','Cuti Bersama Hari Suci Nyepi',true],['2026-03-19','Hari Suci Nyepi',false],
            ['2026-03-20','Cuti Bersama Idulfitri',true],['2026-03-21','Idulfitri 1447 H',false],
            ['2026-03-22','Idulfitri 1447 H (Hari Kedua)',false],['2026-03-23','Cuti Bersama Idulfitri',true],
            ['2026-03-24','Cuti Bersama Idulfitri',true],['2026-04-03','Wafat Yesus Kristus',false],
            ['2026-04-05','Kebangkitan Yesus Kristus',false],['2026-05-01','Hari Buruh Internasional',false],
            ['2026-05-14','Kenaikan Yesus Kristus',false],['2026-05-15','Cuti Bersama Kenaikan Yesus Kristus',true],
            ['2026-05-27','Idul Adha 1447 H',false],['2026-05-28','Cuti Bersama Idul Adha',true],
            ['2026-05-31','Hari Raya Waisak 2570 BE',false],['2026-06-01','Hari Lahir Pancasila',false],
            ['2026-06-16','Tahun Baru Islam 1448 H',false],['2026-08-17','Hari Kemerdekaan Republik Indonesia',false],
            ['2026-08-25','Maulid Nabi Muhammad SAW',false],['2026-12-24','Cuti Bersama Natal',true],
            ['2026-12-25','Hari Raya Natal',false]
        ],
    ];
    if (isset($fb[$tahun])) {
        $data = [];
        foreach ($fb[$tahun] as $item) $data[] = ['date'=>$item[0],'name'=>$item[1],'is_cuti'=>$item[2]];
        usort($data, function($a,$b){ return strcmp($a['date'],$b['date']); });
        return $data;
    }
    return [];
}

function getHariLiburNasional($tahun) {
    $api = getHariLiburDariAPI($tahun);
    return $api !== null ? $api : getHariLiburFallback($tahun);
}

/* ─── Init vars ──────────────────────────────────────────────────────────── */
$tahunSekarang = (int)date('Y');
$successMsg = $errorMsg = '';
$namaBulan = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];

/* ─── POST: Tambah ───────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='tambah') {
    csrf_verify();
    $mode = $_POST['mode'] ?? 'single';
    $keterangan = trim($_POST['keterangan'] ?? '') ?: 'Hari Libur';
    $isCuti = isset($_POST['is_cuti']) ? 1 : 0;
    $tanggalList = [];
    if ($mode==='single') {
        $t = $_POST['tanggal'] ?? ''; if ($t) $tanggalList[] = $t;
    } elseif ($mode==='range') {
        $ta=$_POST['tgl_awal']??''; $tb=$_POST['tgl_akhir']??'';
        if ($ta && $tb) {
            if ($tb < $ta) { $errorMsg='Tanggal akhir harus sama atau setelah tanggal awal.'; }
            else { $cur=new DateTime($ta); $end=new DateTime($tb); $max=365; while($cur<=$end&&$max-->0){$tanggalList[]=$cur->format('Y-m-d');$cur->modify('+1 day');} }
        } else { $errorMsg='Tanggal awal dan akhir wajib diisi.'; }
    } elseif ($mode==='multi') {
        $raw=$_POST['tanggal_multi']??''; $dec=json_decode($raw,true);
        if (is_array($dec)) $tanggalList=array_filter($dec);
    }
    if (!$errorMsg) {
        $added=$skipped=0;
        foreach ($tanggalList as $tgl) {
            if (!$tgl) continue;
            $s=$pdo->prepare("SELECT id FROM hari_libur WHERE tanggal=?"); $s->execute([$tgl]);
            if ($s->fetch()){$skipped++;continue;}
            $pdo->prepare("INSERT INTO hari_libur(tanggal,keterangan,is_cuti)VALUES(?,?,?)")->execute([$tgl,$keterangan,$isCuti]);
            $added++;
        }
        $successMsg="$added tanggal berhasil ditambahkan".($skipped?", $skipped dilewati (sudah ada).":".");
    }
}

/* ─── POST: Import ───────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='import') {
    csrf_verify();
    $tahunImport=(int)($_POST['tahun_import']??$tahunSekarang);
    $apiData=getHariLiburDariAPI($tahunImport);
    if ($apiData!==null){$daftarImport=$apiData;$sumber="API libur.deno.dev";}
    else{$daftarImport=getHariLiburFallback($tahunImport);$sumber="data lokal";}
    $added=$skipped=0;
    foreach ($daftarImport as $item){
        $s=$pdo->prepare("SELECT id FROM hari_libur WHERE tanggal=?"); $s->execute([$item['date']]);
        if ($s->fetch()){$skipped++;continue;}
        $ic=$item['is_cuti']?1:0;
        $pdo->prepare("INSERT INTO hari_libur(tanggal,keterangan,is_cuti)VALUES(?,?,?)")->execute([$item['date'],$item['name'],$ic]);
        $added++;
    }
    $successMsg="Import $tahunImport: <strong>$added ditambahkan</strong>".($skipped?", $skipped sudah ada":"")." — $sumber.";
}

/* ─── GET: Hapus satu ────────────────────────────────────────────────────── */
if (isset($_GET['hapus'])) {
    $pdo->prepare("DELETE FROM hari_libur WHERE id=?")->execute([(int)$_GET['hapus']]);
    header("Location: hari_libur.php?msg=hapus"); exit();
}

/* ─── POST: Hapus bulan ──────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='hapus_bulan') {
    csrf_verify();
    $bln=(int)($_POST['hapus_bulan']??0); $thn=(int)($_POST['hapus_tahun']??0);
    if ($bln&&$thn) {
        $pdo->prepare("DELETE FROM hari_libur WHERE MONTH(tanggal)=? AND YEAR(tanggal)=?")->execute([$bln,$thn]);
        $successMsg="Semua hari libur ".$namaBulan[$bln]." $thn berhasil dihapus.";
    }
}
if (isset($_GET['msg'])&&$_GET['msg']==='hapus') $successMsg="Hari libur berhasil dihapus.";

/* ─── Query data ─────────────────────────────────────────────────────────── */
$allLibur=$pdo->query("SELECT * FROM hari_libur ORDER BY tanggal ASC")->fetchAll(PDO::FETCH_ASSOC);

$tahunList=[];
foreach ($allLibur as $l){$y=(int)date('Y',strtotime($l['tanggal']));if(!in_array($y,$tahunList))$tahunList[]=$y;}
sort($tahunList);
if(!in_array($tahunSekarang,$tahunList))$tahunList[]=$tahunSekarang;
if(!in_array($tahunSekarang+1,$tahunList))$tahunList[]=$tahunSekarang+1;
sort($tahunList);

$jumlahTahunIni=0;
foreach ($allLibur as $l){if(date('Y',strtotime($l['tanggal']))==$tahunSekarang)$jumlahTahunIni++;}

/* Build holidays JSON */
$holidaysJs=[];
foreach ($allLibur as $l){
    $ic=isset($l['is_cuti'])?(bool)$l['is_cuti']:(stripos($l['keterangan'],'cuti')!==false);
    $holidaysJs[]=['id'=>(int)$l['id'],'date'=>$l['tanggal'],'name'=>$l['keterangan'],'cuti'=>$ic];
}
$holidaysJson=json_encode($holidaysJs,JSON_UNESCAPED_UNICODE);

/* Preview fallback */
$previewData=[];
for($y=$tahunSekarang;$y<=$tahunSekarang+3;$y++) $previewData[$y]=getHariLiburFallback($y);
$previewJson=json_encode($previewData,JSON_UNESCAPED_UNICODE);

/* Select options */
$optImport='';
for($y=$tahunSekarang;$y<=$tahunSekarang+3;$y++){
    $fd=getHariLiburFallback($y);
    $lbl=!empty($fd)?"$y (".count($fd)." hari – lokal)":"$y (via API)";
    $optImport.="<option value=\"$y\">$lbl</option>";
}
$defaultKalBulan=(int)date('n'); $defaultKalTahun=$tahunSekarang;
$optKalBulan=''; for($m=1;$m<=12;$m++){$sel=($m===$defaultKalBulan)?' selected':'';$optKalBulan.="<option value=\"$m\"$sel>{$namaBulan[$m]}</option>";}
$optKalTahun=''; for($y=$tahunSekarang;$y<=$tahunSekarang+2;$y++){$sel=($y===$defaultKalTahun)?' selected':'';$optKalTahun.="<option value=\"$y\"$sel>$y</option>";}
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<div class="main-content">
<div class="hl-page">

  <!-- PAGE HEADER -->
  <div class="hl-header" id="tour-hl-header">
    <div class="hl-header-left">
      <h4 class="hl-title">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="9" y1="16" x2="10" y2="16"/><line x1="14" y1="16" x2="15" y2="16"/></svg>
        Kelola Hari Libur
      </h4>
      <p class="hl-sub">Atur tanggal merah, cuti bersama, dan hari libur khusus</p>
    </div>
    <div class="hl-header-right">
      <div class="stat-pill"><span class="stat-num"><?= count($allLibur) ?></span><span class="stat-lbl">Total</span></div>
      <div class="stat-pill"><span class="stat-num"><?= $jumlahTahunIni ?></span><span class="stat-lbl">Tahun <?= $tahunSekarang ?></span></div>
      <button class="hl-guide-btn" id="btnTourHl" onclick="mulaiTourHl()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
        <span>Panduan</span>
      </button>
    </div>
  </div>

  <!-- ALERTS -->
  <?php if($successMsg): ?>
  <div class="hl-alert hl-alert-success" id="mainAlert">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
    <?= $successMsg ?>
  </div>
  <?php endif; ?>
  <?php if($errorMsg): ?>
  <div class="hl-alert hl-alert-danger" id="mainAlert">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
    <?= $errorMsg ?>
  </div>
  <?php endif; ?>
  <div id="jsAlertArea"></div>

  <!-- MAIN LAYOUT -->
  <div class="hl-layout">

    <!-- LEFT -->
    <div class="hl-left">

      <!-- Import -->
      <div class="hl-card" id="tour-hl-import">
        <div class="hl-card-head">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
          Import Hari Libur Nasional
        </div>
        <div class="hl-card-body">
          <p class="hl-hint">Import dari <strong>libur.deno.dev</strong>. Gagal API → fallback data lokal.</p>
          <div class="api-badge">
            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><circle cx="12" cy="20" r="1"/></svg>
            API aktif · Fallback tersedia
          </div>
          <form method="POST" style="margin-top:.65rem">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="import">
            <div class="import-row">
              <select name="tahun_import" id="tahunImportSel" class="hl-select" onchange="syncPreview()"><?= $optImport ?></select>
              <button type="submit" class="btn-gold-sm" onclick="return confirm('Import hari libur untuk tahun yang dipilih?')">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/></svg>
                Import
              </button>
            </div>
          </form>
          <div class="preview-toggle" onclick="togglePreview()">
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
            <span id="pvToggleText">Lihat preview data lokal</span>
          </div>
          <div id="pvList" style="display:none" class="preview-list"></div>
        </div>
      </div>

      <!-- Tambah Manual -->
      <div class="hl-card" id="tour-hl-tambah">
        <div class="hl-card-head">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="12" y1="14" x2="12" y2="18"/><line x1="10" y1="16" x2="14" y2="16"/></svg>
          Tambah Manual
        </div>
        <div class="hl-card-body">
          <div class="mode-tabs" id="tour-hl-mode">
            <button type="button" class="mode-tab active" data-mode="single">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              <span>Satu Tanggal</span>
            </button>
            <button type="button" class="mode-tab" data-mode="range">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><polyline points="8 14 10 16 14 12"/></svg>
              <span>Rentang</span>
            </button>
            <button type="button" class="mode-tab" data-mode="multi">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="14" x2="8.01" y2="14"/><line x1="12" y1="14" x2="12.01" y2="14"/><line x1="16" y1="14" x2="16.01" y2="14"/></svg>
              <span>Pilih Bebas</span>
            </button>
          </div>
          <form method="POST" id="formTambah">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="tambah">
            <input type="hidden" name="mode" id="modeInput" value="single">

            <!-- Single -->
            <div id="panelSingle" class="mode-panel">
              <div class="hl-field">
                <label class="hl-label">Tanggal *</label>
                <input type="date" name="tanggal" id="singleDate" class="hl-input">
              </div>
            </div>

            <!-- Range -->
            <div id="panelRange" class="mode-panel" style="display:none">
              <div class="row-2">
                <div class="hl-field"><label class="hl-label">Dari *</label><input type="date" name="tgl_awal" id="rangeAwal" class="hl-input"></div>
                <div class="hl-field"><label class="hl-label">Sampai *</label><input type="date" name="tgl_akhir" id="rangeAkhir" class="hl-input"></div>
              </div>
              <div class="info-box" id="rangeInfo" style="display:none">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                <span id="rangeInfoText"></span>
              </div>
            </div>

            <!-- Multi -->
            <div id="panelMulti" class="mode-panel" style="display:none">
              <div class="mini-cal">
                <div class="mini-nav">
                  <button type="button" id="prevKalBtn" class="mini-arr">&#8249;</button>
                  <div class="mini-selects">
                    <select id="kalBulan" class="hl-select-sm"><?= $optKalBulan ?></select>
                    <select id="kalTahun" class="hl-select-sm"><?= $optKalTahun ?></select>
                  </div>
                  <button type="button" id="nextKalBtn" class="mini-arr">&#8250;</button>
                </div>
                <div id="kalGrid" class="mini-grid"></div>
                <div class="mini-footer">
                  <small class="hl-hint-sm">Klik untuk pilih / batal</small>
                  <button type="button" class="btn-clear" onclick="clearMulti()">Hapus Pilihan</button>
                </div>
              </div>
              <div class="info-box" id="multiSummary" style="display:none">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
                <span id="multiSummaryText"></span>
              </div>
              <input type="hidden" name="tanggal_multi" id="multiDates" value="[]">
            </div>

            <div class="hl-field" style="margin-top:.85rem">
              <label class="hl-label">Keterangan</label>
              <input type="text" name="keterangan" class="hl-input" placeholder="Cuti Bersama, Libur Lokal…">
            </div>
            <div class="hl-check-row">
              <input type="checkbox" name="is_cuti" id="isCutiChk" value="1">
              <label for="isCutiChk" class="hl-check-label">Tandai sebagai Cuti Bersama</label>
            </div>
            <button type="submit" class="btn-gold-block">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
              Tambah Hari Libur
            </button>
          </form>
        </div>
      </div>

    </div><!-- /hl-left -->

    <!-- RIGHT -->
    <div class="hl-right">
      <div class="hl-card hl-right-card">

        <div class="hl-card-head hl-right-head" id="tour-hl-daftar">
          <div class="right-title-row">
            <span class="right-title">
              <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              Daftar Hari Libur
            </span>
            <span class="badge-count" id="badgeCount"><?= count($allLibur) ?> hari</span>
          </div>
          <div class="view-toggle" id="tour-hl-filter">
            <button class="vt-btn active" id="vtCal" onclick="switchView('cal')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
              Kalender
            </button>
            <button class="vt-btn" id="vtList" onclick="switchView('list')">
              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
              Daftar
            </button>
          </div>
        </div>

        <!-- Legend -->
        <div class="hl-legend" id="legendArea">
          <div class="legend-item"><div class="legend-dot ld-red"></div>Libur Nasional</div>
          <div class="legend-item"><div class="legend-dot ld-purple"></div>Cuti Bersama</div>
          <div class="legend-item"><div class="legend-dot ld-yellow"></div>Akhir Pekan</div>
        </div>

        <!-- Filter bar (list) -->
        <div class="hl-filter-bar" id="filterBar" style="display:none">
          <div class="filter-group">
            <label class="hl-label">Bulan</label>
            <select id="fBulan" class="hl-select" onchange="renderList()">
              <option value="">Semua Bulan</option>
              <?php for($m=1;$m<=12;$m++) echo "<option value=\"$m\">{$namaBulan[$m]}</option>"; ?>
            </select>
          </div>
          <div class="filter-group">
            <label class="hl-label">Tahun</label>
            <select id="fTahun" class="hl-select" onchange="renderList()">
              <?php foreach($tahunList as $ty) echo "<option value=\"$ty\"".($ty==$tahunSekarang?' selected':'').">$ty</option>"; ?>
            </select>
          </div>
          <button class="btn-reset" onclick="resetFilter()" title="Reset filter">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          </button>
          <div id="hapusBulanArea" style="margin-left:auto;display:flex;align-items:flex-end"></div>
        </div>

        <!-- CALENDAR VIEW -->
        <div id="viewCal">
          <div class="big-cal">
            <div class="bcal-top-nav">
              <button class="bcal-arr" onclick="prevBcal()">&#8249;</button>
              <div class="bcal-title-wrap">
                <span class="bcal-title" id="bcalTitle"></span>
                <div class="bcal-yr-row">
                  <select id="bcalBulan" class="hl-select-sm" onchange="syncBcalSelects('bulan')"></select>
                  <select id="bcalTahun" class="hl-select-sm" onchange="syncBcalSelects('tahun')"></select>
                </div>
              </div>
              <button class="bcal-arr" onclick="nextBcal()">&#8250;</button>
            </div>
            <div class="bcal-grid" id="bcalGrid"></div>
            <div id="dayDetail" class="day-detail" style="display:none">
              <div class="day-detail-head">
                <span id="dayDetailTitle"></span>
                <button onclick="closeDayDetail()" class="btn-close-dd">&times;</button>
              </div>
              <div id="dayDetailBody"></div>
            </div>
          </div>
        </div>

        <!-- LIST VIEW -->
        <div id="viewList" style="display:none">
          <div id="listContent" class="list-view"></div>
        </div>

      </div>
    </div><!-- /hl-right -->

  </div><!-- /hl-layout -->
</div><!-- /hl-page -->
</div><!-- /main-content -->

<style>
/* ─── Variables ── */
:root{
  --hn:#1a2744;--hn2:#243352;--hg:#c9983a;--hgl:#fdf5e0;--hgb:#e8d08a;
  --hr:#dc2626;--hrb:#fef2f2;--hrbd:#fecaca;
  --hp:#7c3aed;--hpb:#f5f3ff;--hpbd:#ddd6fe;
  --hgr:#16a34a;--hs:#fff;--hbg:#f5f6f8;
  --hbd:#e5e7eb;--hbd2:#d1d5db;--ht:#111827;--ht2:#6b7280;--ht3:#9ca3af;
  --rr:10px;--rr2:8px;--rr3:6px;
}
/* ─── Page ── */
.hl-page{padding:1.5rem 1.75rem;max-width:1200px;font-family:'Inter','Segoe UI',system-ui,sans-serif;font-size:14px;color:var(--ht);line-height:1.5}
/* ─── Header ── */
.hl-header{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.25rem}
.hl-title{font-size:1.2rem;font-weight:700;color:var(--hn);display:flex;align-items:center;gap:.45rem;margin:0}
.hl-title svg{opacity:.7}
.hl-sub{font-size:.75rem;color:var(--ht2);margin:.2rem 0 0}
.hl-header-right{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}
/* ─── Stat pills ── */
.stat-pill{background:var(--hs);border:1px solid var(--hbd);border-radius:var(--rr2);padding:.4rem .85rem;text-align:center;min-width:64px}
.stat-num{display:block;font-size:1.3rem;font-weight:700;color:var(--hn);line-height:1}
.stat-lbl{font-size:.62rem;color:var(--ht2);margin-top:2px}
/* ─── Guide btn ── */
.hl-guide-btn{display:inline-flex;align-items:center;gap:.3rem;padding:.37rem .72rem;border-radius:var(--rr2);font-size:.72rem;font-weight:500;color:var(--ht2);background:var(--hs);border:1px solid var(--hbd);cursor:pointer;transition:.15s;white-space:nowrap}
.hl-guide-btn:hover{border-color:var(--hg);color:var(--hg)}
/* ─── Alerts ── */
.hl-alert{display:flex;align-items:flex-start;gap:.5rem;padding:.65rem .9rem;border-radius:var(--rr2);font-size:.8rem;margin-bottom:1rem;line-height:1.55}
.hl-alert svg{margin-top:1px;flex-shrink:0}
.hl-alert-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}
.hl-alert-danger{background:var(--hrb);border:1px solid var(--hrbd);color:var(--hr)}
/* ─── Layout ── */
.hl-layout{display:grid;grid-template-columns:320px 1fr;gap:1.1rem;align-items:start}
/* ─── Cards ── */
.hl-card{background:var(--hs);border:1px solid var(--hbd);border-radius:var(--rr);overflow:hidden;margin-bottom:1rem}
.hl-card:last-child,.hl-right-card{margin-bottom:0}
.hl-card-head{display:flex;align-items:center;gap:.45rem;padding:.72rem 1rem;font-size:.8rem;font-weight:600;color:var(--hn);background:linear-gradient(135deg,#f8f9fa,#fff);border-bottom:2px solid var(--hg)}
.hl-card-head svg{opacity:.6;flex-shrink:0}
.hl-right-head{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:.6rem}
.right-title-row{display:flex;align-items:center;gap:.5rem}
.right-title{display:flex;align-items:center;gap:.4rem;font-size:.8rem;font-weight:600;color:var(--hn)}
.badge-count{background:var(--hgl);color:#7a5c10;font-size:.68rem;font-weight:700;padding:.18rem .5rem;border-radius:20px;border:1px solid var(--hgb)}
.hl-card-body{padding:1rem}
/* ─── Hints ── */
.hl-hint{font-size:.75rem;color:var(--ht2);margin-bottom:.6rem}
.hl-hint-sm{font-size:.68rem;color:var(--ht3)}
/* ─── API badge ── */
.api-badge{display:inline-flex;align-items:center;gap:.4rem;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--rr2);padding:.28rem .65rem;font-size:.7rem;color:var(--hgr)}
/* ─── Import ── */
.import-row{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;margin-top:.6rem}
.preview-toggle{display:inline-flex;align-items:center;gap:.35rem;font-size:.73rem;color:var(--ht2);cursor:pointer;margin-top:.7rem;transition:color .15s}
.preview-toggle:hover{color:var(--hn)}
.preview-list{margin-top:.45rem;max-height:190px;overflow-y:auto;padding-right:2px}
.preview-item{display:flex;align-items:center;gap:.5rem;padding:.3rem 0;border-bottom:1px solid #f1f5f9;font-size:.72rem}
.preview-item:last-child{border-bottom:none}
.pv-date{background:var(--hn);color:#fff;font-size:.62rem;padding:.1rem .38rem;border-radius:4px;font-weight:600;white-space:nowrap;flex-shrink:0}
.pv-cuti{background:var(--hp)}
/* ─── Mode tabs ── */
.mode-tabs{display:flex;background:#f1f5f9;border-radius:var(--rr2);padding:3px;gap:2px;margin-bottom:1rem}
.mode-tab{flex:1;display:flex;align-items:center;justify-content:center;gap:.3rem;padding:.38rem .3rem;border:none;background:transparent;border-radius:var(--rr3);font-size:.72rem;font-weight:500;color:var(--ht2);cursor:pointer;transition:.15s;white-space:nowrap;font-family:inherit}
.mode-tab svg{opacity:.7;flex-shrink:0}
.mode-tab.active{background:var(--hs);color:var(--hn);font-weight:600;box-shadow:0 1px 4px rgba(0,0,0,.1)}
/* ─── Form ── */
.hl-field{margin-bottom:.8rem}
.hl-label{display:block;font-size:.72rem;font-weight:600;color:var(--hn);margin-bottom:.28rem}
.hl-input{width:100%;padding:.45rem .65rem;border:1.5px solid var(--hbd2);border-radius:var(--rr2);font-size:.8rem;font-family:inherit;color:var(--ht);background:var(--hs);outline:none;transition:border-color .15s}
.hl-input:focus{border-color:var(--hg);box-shadow:0 0 0 3px rgba(201,152,58,.1)}
.hl-select{padding:.4rem .65rem;border:1.5px solid var(--hbd2);border-radius:var(--rr2);font-size:.8rem;font-family:inherit;background:var(--hs);color:var(--ht);outline:none;transition:border-color .15s}
.hl-select:focus{border-color:var(--hg);box-shadow:0 0 0 3px rgba(201,152,58,.1)}
.hl-select-sm{padding:.3rem .5rem;border:1px solid var(--hbd2);border-radius:var(--rr3);font-size:.75rem;font-family:inherit;background:var(--hs);color:var(--ht);outline:none}
.row-2{display:grid;grid-template-columns:1fr 1fr;gap:.6rem}
.hl-check-row{display:flex;align-items:center;gap:.45rem;margin-bottom:.85rem}
.hl-check-label{font-size:.75rem;color:var(--ht2);cursor:pointer}
.info-box{display:flex;align-items:flex-start;gap:.4rem;background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--rr2);padding:.48rem .7rem;font-size:.75rem;color:#1e40af;margin-top:.5rem}
.info-box svg{margin-top:1px;flex-shrink:0}
/* ─── Buttons ── */
.btn-gold-sm{display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .82rem;background:var(--hg);border:none;color:#fff;font-weight:600;border-radius:var(--rr2);cursor:pointer;font-size:.77rem;font-family:inherit;transition:background .15s;white-space:nowrap}
.btn-gold-sm:hover{background:#b8852e}
.btn-gold-block{display:flex;align-items:center;justify-content:center;gap:.4rem;width:100%;padding:.55rem 1rem;background:var(--hg);border:none;color:#fff;font-weight:600;border-radius:var(--rr2);cursor:pointer;font-size:.82rem;font-family:inherit;transition:background .15s;margin-top:.65rem}
.btn-gold-block:hover{background:#b8852e}
.btn-clear{background:none;border:1px solid var(--hbd);color:var(--ht2);font-size:.7rem;padding:.25rem .5rem;border-radius:var(--rr3);cursor:pointer;font-family:inherit;transition:.15s}
.btn-clear:hover{border-color:var(--hn);color:var(--hn)}
.btn-reset{display:flex;align-items:center;justify-content:center;background:var(--hs);border:1.5px solid var(--hbd2);color:var(--ht2);padding:.4rem .5rem;border-radius:var(--rr2);cursor:pointer;transition:.15s;align-self:flex-end}
.btn-reset:hover{border-color:var(--hn);color:var(--hn)}
.btn-del{background:none;border:none;color:var(--ht3);cursor:pointer;padding:.28rem .42rem;border-radius:var(--rr3);font-size:.82rem;line-height:1;transition:.12s;flex-shrink:0}
.btn-del:hover{background:var(--hrb);color:var(--hr)}
.btn-danger-sm{display:flex;align-items:center;gap:.4rem;padding:.4rem .75rem;background:var(--hrb);border:1.5px solid var(--hrbd);color:var(--hr);font-weight:600;border-radius:var(--rr2);cursor:pointer;font-size:.74rem;font-family:inherit;transition:.15s;white-space:nowrap}
.btn-danger-sm:hover{background:#fee2e2}
.btn-close-dd{background:none;border:none;font-size:1.1rem;line-height:1;cursor:pointer;color:var(--ht2);padding:0 2px}
/* ─── Mini calendar ── */
.mini-nav{display:flex;justify-content:space-between;align-items:center;gap:.45rem;margin-bottom:.45rem}
.mini-selects{display:flex;gap:.35rem}
.mini-arr{background:#f1f5f9;border:1px solid var(--hbd);border-radius:var(--rr3);padding:.28rem .5rem;cursor:pointer;color:var(--hn);font-size:.8rem;transition:.15s}
.mini-arr:hover{background:var(--hn);color:#fff}
.mini-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.mini-footer{display:flex;justify-content:space-between;align-items:center;margin-top:.4rem}
.kd-h{text-align:center;font-size:.62rem;font-weight:600;color:var(--ht3);padding:.18rem 0}
.kd{text-align:center;font-size:.71rem;padding:.3rem .1rem;border-radius:5px;cursor:pointer;border:1.5px solid transparent;line-height:1.2;transition:.1s;user-select:none}
.kd:not(.kd-empty):hover{background:#f1f5f9}
.kd-empty{cursor:default}
.kd-sel{background:var(--hn)!important;color:#fff!important;font-weight:600}
.kd-exist{background:#dcfce7!important;color:#166534!important;border-color:#86efac!important;cursor:not-allowed;font-size:.63rem}
.kd-wknd{color:var(--hr)}
.kd-today{border-color:var(--hg)!important;font-weight:700}
/* ─── View toggle ── */
.view-toggle{display:flex;background:#f1f5f9;border-radius:var(--rr3);padding:2px;gap:2px}
.vt-btn{display:flex;align-items:center;gap:.3rem;padding:.3rem .65rem;border:none;background:transparent;border-radius:4px;font-size:.72rem;cursor:pointer;font-family:inherit;color:var(--ht2);transition:.12s;font-weight:500}
.vt-btn.active{background:var(--hs);color:var(--hn);font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,.08)}
/* ─── Legend ── */
.hl-legend{display:flex;gap:1rem;flex-wrap:wrap;padding:.48rem 1rem;border-bottom:1px solid var(--hbd);background:#fafafa}
.legend-item{display:flex;align-items:center;gap:.35rem;font-size:.68rem;color:var(--ht2)}
.legend-dot{width:11px;height:11px;border-radius:3px}
.ld-red{background:var(--hrb);border:1px solid var(--hrbd)}
.ld-purple{background:var(--hpb);border:1px solid var(--hpbd)}
.ld-yellow{background:#fffbeb;border:1px solid #fde68a}
/* ─── Filter bar ── */
.hl-filter-bar{display:flex;gap:.65rem;align-items:flex-end;flex-wrap:wrap;padding:.75rem 1rem;border-bottom:1px solid var(--hbd);background:#fafafa}
.filter-group{display:flex;flex-direction:column;gap:.25rem}
/* ─── Big Calendar ── */
.big-cal{padding:1rem}
.bcal-top-nav{display:flex;justify-content:space-between;align-items:center;margin-bottom:.85rem;gap:.5rem}
.bcal-title-wrap{text-align:center}
.bcal-title{font-size:.95rem;font-weight:700;color:var(--hn);display:block}
.bcal-yr-row{display:flex;gap:.35rem;justify-content:center;margin-top:.3rem}
.bcal-arr{background:var(--hs);border:1px solid var(--hbd);border-radius:var(--rr2);padding:.35rem .65rem;cursor:pointer;color:var(--hn);font-size:.9rem;transition:.15s;line-height:1}
.bcal-arr:hover{background:var(--hn);color:#fff}
.bcal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:1px;background:var(--hbd);border:1px solid var(--hbd);border-radius:var(--rr2);overflow:hidden}
.bcal-dh{background:var(--hn);text-align:center;font-size:.63rem;font-weight:600;color:rgba(255,255,255,.65);padding:.48rem 0;text-transform:uppercase;letter-spacing:.05em}
.bcal-dh:first-child,.bcal-dh:last-child{color:rgba(255,255,255,.45)}
.bcal-cell{background:var(--hs);min-height:72px;padding:.4rem .45rem;cursor:pointer;transition:background .1s;overflow:hidden}
.bcal-cell:hover{background:#f9fafb}
.bcal-cell.other-m{background:#fafafa}
.bcal-cell.other-m .bcal-num{color:var(--ht3)}
.bcal-cell.is-wknd .bcal-num{color:rgba(220,38,38,.6)}
.bcal-cell.is-holiday{background:var(--hrb)}
.bcal-cell.is-holiday .bcal-num{color:var(--hr);font-weight:700}
.bcal-cell.is-cuti{background:var(--hpb)}
.bcal-cell.is-cuti .bcal-num{color:var(--hp);font-weight:700}
.bcal-num{font-size:.75rem;font-weight:500;line-height:1;margin-bottom:.22rem}
.bcal-today-dot{background:var(--hn);color:#fff;border-radius:50%;width:20px;height:20px;display:inline-flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700}
.bcal-chip{font-size:.58rem;border-radius:3px;padding:.12rem .3rem;line-height:1.3;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:.15rem;background:var(--hr);color:#fff}
.bcal-chip.c-cuti{background:var(--hp)}
/* Day detail */
.day-detail{background:var(--hs);border:1px solid var(--hbd);border-radius:var(--rr2);padding:.75rem 1rem;margin-top:.75rem;animation:fadein .15s ease}
@keyframes fadein{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.day-detail-head{display:flex;justify-content:space-between;align-items:center;font-weight:600;font-size:.82rem;color:var(--hn);margin-bottom:.5rem;padding-bottom:.45rem;border-bottom:1px solid var(--hbd)}
.detail-row{display:flex;align-items:flex-start;gap:.55rem;padding:.38rem 0;border-bottom:1px solid #f3f4f6;font-size:.78rem}
.detail-row:last-of-type{border-bottom:none}
.detail-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;margin-top:4px}
.d-dot-red{background:var(--hr)}.d-dot-purple{background:var(--hp)}
/* ─── List view ── */
.month-group{}
.month-label{display:flex;align-items:center;gap:.45rem;padding:.48rem .9rem;background:linear-gradient(90deg,var(--hn),var(--hn2));color:#fff;font-size:.73rem;font-weight:600;letter-spacing:.03em}
.hl-row{display:grid;grid-template-columns:115px 1fr auto;align-items:center;gap:.75rem;padding:.62rem .9rem;border-bottom:1px solid var(--hbd);transition:background .1s}
.hl-row:last-child{border-bottom:none}
.hl-row:hover{background:#fafafa}
.hl-row.row-holiday{background:var(--hrb)}.hl-row.row-holiday:hover{background:#fee2e2}
.hl-row.row-cuti{background:var(--hpb)}.hl-row.row-cuti:hover{background:#ede9fe}
.hl-row.row-wknd{background:#fffbeb}
.hl-date{font-size:.79rem;font-weight:600;color:var(--hn)}
.hl-date.h-red{color:var(--hr)}.hl-date.h-purple{color:var(--hp)}
.hl-day-badge{font-size:.65rem;padding:.15rem .42rem;border-radius:4px;font-weight:600;margin-top:2px;display:inline-block}
.d-wknd{background:#fef3c7;color:#92400e}.d-wkday{background:#f1f5f9;color:#475569}
.hl-info{font-size:.79rem;color:var(--ht)}
.tag-cuti{display:inline-block;font-size:.67rem;margin-top:3px;padding:.1rem .38rem;border-radius:3px;background:var(--hpb);color:var(--hp)}
.empty-state{text-align:center;padding:2.5rem 1rem;color:var(--ht3)}
.empty-state svg{margin-bottom:.65rem;opacity:.35;display:block;margin-left:auto;margin-right:auto}
.empty-state p{font-weight:600;font-size:.85rem;margin-bottom:.25rem}
.empty-state small{font-size:.75rem}
/* ─── Responsive ── */
@media(max-width:1024px){.hl-layout{grid-template-columns:290px 1fr}}
@media(max-width:860px){.hl-layout{grid-template-columns:1fr}.hl-right{order:-1}.big-cal{padding:.75rem}.bcal-cell{min-height:60px}.bcal-chip{font-size:.55rem}}
@media(max-width:640px){
  .hl-page{padding:1rem}
  .hl-header{flex-direction:column;gap:.7rem}
  .hl-header-right{width:100%;justify-content:space-between}
  .mode-tab span{display:none}
  .row-2{grid-template-columns:1fr}
  .bcal-cell{min-height:48px;padding:.28rem .25rem}
  .bcal-num{font-size:.68rem}
  .bcal-chip{display:none}
  .bcal-cell.is-holiday::after,.bcal-cell.is-cuti::after{content:'';display:block;width:5px;height:5px;border-radius:50%;margin:2px auto 0}
  .bcal-cell.is-holiday::after{background:var(--hr)}
  .bcal-cell.is-cuti::after{background:var(--hp)}
  .hl-row{grid-template-columns:100px 1fr auto;gap:.5rem;padding:.55rem .75rem}
  .hl-filter-bar{gap:.45rem}
  .hl-legend{gap:.65rem}
  .bcal-top-nav{gap:.3rem}
  .stat-pill{min-width:56px;padding:.35rem .65rem}
  .hl-title{font-size:1rem}
}
@media(max-width:420px){
  .hl-layout{gap:.75rem}
  .bcal-dh{font-size:.58rem}
  .hl-row{grid-template-columns:90px 1fr auto}
  .bcal-yr-row{flex-wrap:wrap}
}
/* ─── Tour ── */
.hl-tour .driver-popover{background:#fff!important;border-radius:var(--rr)!important;box-shadow:0 20px 60px rgba(0,0,0,.15),0 0 0 1px rgba(201,152,58,.2)!important;padding:1rem 1.15rem!important;max-width:290px!important}
.hl-tour .driver-popover-title{font-size:.83rem!important;font-weight:700!important;color:#111!important;padding-bottom:.35rem!important;border-bottom:2px solid var(--hg)!important;margin-bottom:.55rem!important}
.hl-tour .driver-popover-description{font-size:.73rem!important;color:#374151!important;line-height:1.65!important}
.hl-tour .driver-popover-progress-text{color:var(--hg)!important;font-size:.63rem!important;font-weight:700!important}
.hl-tour .driver-popover-navigation-btns{display:flex!important;gap:.25rem!important;margin-top:.7rem!important}
.hl-tour .driver-popover-next-btn,.hl-tour .driver-popover-done-btn{background:var(--hg)!important;border:none!important;color:#fff!important;border-radius:var(--rr3)!important;padding:.3rem .75rem!important;font-size:.71rem!important;font-weight:700!important;cursor:pointer!important;line-height:1.4!important;min-width:unset!important}
.hl-tour .driver-popover-next-btn:hover,.hl-tour .driver-popover-done-btn:hover{background:#b8852e!important}
.hl-tour .driver-popover-prev-btn{background:#f3f4f6!important;border:1px solid #e5e7eb!important;color:#4b5563!important;border-radius:var(--rr3)!important;padding:.3rem .75rem!important;font-size:.71rem!important;cursor:pointer!important;line-height:1.4!important}
.hl-tour .driver-popover-close-btn{color:#9ca3af!important;background:none!important;border:none!important}
@media(max-width:576px){.hl-tour .driver-popover{max-width:calc(100vw - 2rem)!important}}
</style>

<script>
/* ─── Data from PHP ── */
var HOLIDAYS = <?= $holidaysJson ?>;
var PREVIEW  = <?= $previewJson ?>;
var TODAY    = '<?= date('Y-m-d') ?>';

/* ─── Constants ── */
var BLN = ['','Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
var HARI = ['Minggu','Senin','Selasa','Rabu','Kamis','Jumat','Sabtu'];
var SH   = ['Min','Sen','Sel','Rab','Kam','Jum','Sab'];

/* ─── State ── */
var selDates={}, curMode='single';
var kalM=<?= $defaultKalBulan ?>, kalY=<?= $defaultKalTahun ?>;
var bcalM=<?= $defaultKalBulan ?>, bcalY=<?= $defaultKalTahun ?>;
var curView='cal', pvOpen=false;

/* ─── Utils ── */
function pad(n){return n<10?'0'+n:''+n}
function toKey(y,m,d){return y+'-'+pad(m)+'-'+pad(d)}
function isWknd(y,m,d){var w=new Date(y,m-1,d).getDay();return w===0||w===6}
function buildHmap(){var m={};HOLIDAYS.forEach(function(h){m[h.date]=h;});return m}

/* ─── Alert ── */
function jsAlert(msg,type){
    var el=document.getElementById('jsAlertArea');
    var ic=type==='success'
        ?'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>'
        :'<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/></svg>';
    el.innerHTML='<div class="hl-alert hl-alert-'+type+'">'+ic+msg+'</div>';
    setTimeout(function(){el.innerHTML=''},4000);
}

/* ─── Mode tabs ── */
document.querySelectorAll('.mode-tab').forEach(function(btn){
    btn.addEventListener('click',function(){
        document.querySelectorAll('.mode-tab').forEach(function(b){b.classList.remove('active')});
        document.querySelectorAll('.mode-panel').forEach(function(p){p.style.display='none'});
        this.classList.add('active');
        curMode=this.dataset.mode;
        document.getElementById('modeInput').value=curMode;
        document.getElementById('panel'+curMode.charAt(0).toUpperCase()+curMode.slice(1)).style.display='block';
        if(curMode==='multi') renderKal();
    });
});

/* ─── Range info ── */
['rangeAwal','rangeAkhir'].forEach(function(id){document.getElementById(id).addEventListener('input',updateRange)});
function updateRange(){
    var a=document.getElementById('rangeAwal').value, b=document.getElementById('rangeAkhir').value;
    var box=document.getElementById('rangeInfo');
    if(a&&b&&b>=a){var d=Math.round((new Date(b)-new Date(a))/86400000)+1;document.getElementById('rangeInfoText').textContent=d+' hari akan ditambahkan';box.style.display='flex';}
    else box.style.display='none';
}

/* ─── Mini calendar ── */
document.getElementById('prevKalBtn').onclick=function(){kalM--;if(kalM<1){kalM=12;kalY--;}document.getElementById('kalBulan').value=kalM;document.getElementById('kalTahun').value=kalY;renderKal();};
document.getElementById('nextKalBtn').onclick=function(){kalM++;if(kalM>12){kalM=1;kalY++;}document.getElementById('kalBulan').value=kalM;document.getElementById('kalTahun').value=kalY;renderKal();};
document.getElementById('kalBulan').onchange=function(){kalM=parseInt(this.value);renderKal();};
document.getElementById('kalTahun').onchange=function(){kalY=parseInt(this.value);renderKal();};

function renderKal(){
    var hmap=buildHmap(), html='';
    SH.forEach(function(h){html+='<div class="kd-h">'+h+'</div>';});
    var fd=new Date(kalY,kalM-1,1).getDay(), dim=new Date(kalY,kalM,0).getDate();
    for(var i=0;i<fd;i++) html+='<div class="kd kd-empty"></div>';
    for(var d=1;d<=dim;d++){
        var ds=toKey(kalY,kalM,d), iw=isWknd(kalY,kalM,d), ie=!!hmap[ds], isel=!!selDates[ds], it=(ds===TODAY);
        var cls='kd'+(iw?' kd-wknd':'')+(ie?' kd-exist':'')+(isel?' kd-sel':'')+(it?' kd-today':'');
        var lbl=ie?d+'<br><small>✓</small>':d;
        html+='<div class="'+cls+'" onclick="toggleKalDate(\''+ds+'\','+ie+')">'+lbl+'</div>';
    }
    document.getElementById('kalGrid').innerHTML=html;
    updateMultiInfo();
}
function toggleKalDate(ds,ie){if(ie)return;if(selDates[ds])delete selDates[ds];else selDates[ds]=true;renderKal();}
function updateMultiInfo(){
    var arr=Object.keys(selDates).sort();
    document.getElementById('multiDates').value=JSON.stringify(arr);
    var box=document.getElementById('multiSummary');
    if(arr.length>0){
        var bn=['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
        var lb=arr.slice(0,4).map(function(d){var dt=new Date(d+'T00:00:00');return dt.getDate()+' '+bn[dt.getMonth()];});
        if(arr.length>4) lb.push('+'+(arr.length-4)+' lagi');
        document.getElementById('multiSummaryText').textContent=arr.length+' tanggal: '+lb.join(', ');
        box.style.display='flex';
    } else box.style.display='none';
}
function clearMulti(){selDates={};updateMultiInfo();renderKal();}

/* ─── Form validation ── */
document.getElementById('formTambah').addEventListener('submit',function(e){
    if(curMode==='multi'){var arr=JSON.parse(document.getElementById('multiDates').value||'[]');if(!arr.length){e.preventDefault();jsAlert('Pilih minimal satu tanggal.','danger');}}
    if(curMode==='single'&&!document.getElementById('singleDate').value){e.preventDefault();jsAlert('Pilih tanggal terlebih dahulu.','danger');}
});

/* ─── Preview ── */
function togglePreview(){
    pvOpen=!pvOpen;
    document.getElementById('pvList').style.display=pvOpen?'block':'none';
    document.getElementById('pvToggleText').textContent=pvOpen?'Sembunyikan preview':'Lihat preview data lokal';
    if(pvOpen) syncPreview();
}
function syncPreview(){
    if(!pvOpen) return;
    var yr=parseInt(document.getElementById('tahunImportSel').value);
    var data=(PREVIEW[yr]||[]);
    var html='';
    if(!data.length){html='<p class="hl-hint" style="padding:.35rem 0">Data tahun '+yr+' via API saat import.</p>';}
    else data.forEach(function(item){
        var dt=new Date(item.date+'T00:00:00'), lbl=dt.getDate()+'&nbsp;'+BLN[dt.getMonth()+1].substring(0,3);
        html+='<div class="preview-item"><span class="pv-date'+(item.is_cuti?' pv-cuti':'')+'">'+lbl+'</span><span>'+item.name+'</span></div>';
    });
    document.getElementById('pvList').innerHTML=html;
}

/* ─── View switch ── */
function switchView(v){
    curView=v;
    document.getElementById('vtCal').classList.toggle('active',v==='cal');
    document.getElementById('vtList').classList.toggle('active',v==='list');
    document.getElementById('viewCal').style.display=v==='cal'?'block':'none';
    document.getElementById('viewList').style.display=v==='list'?'block':'none';
    document.getElementById('filterBar').style.display=v==='list'?'flex':'none';
    document.getElementById('legendArea').style.display=v==='cal'?'flex':'none';
    if(v==='list') renderList();
    if(v==='cal')  renderBcal();
}

/* ─── Big Calendar ── */
function populateBcalSelects(){
    var bs=document.getElementById('bcalBulan'), ys=document.getElementById('bcalTahun');
    bs.innerHTML='';
    for(var m=1;m<=12;m++){var o=document.createElement('option');o.value=m;o.textContent=BLN[m];if(m===bcalM)o.selected=true;bs.appendChild(o);}
    ys.innerHTML='';
    var curYr=new Date().getFullYear();
    for(var y=curYr-1;y<=curYr+3;y++){var o2=document.createElement('option');o2.value=y;o2.textContent=y;if(y===bcalY)o2.selected=true;ys.appendChild(o2);}
}
function syncBcalSelects(which){
    if(which==='bulan') bcalM=parseInt(document.getElementById('bcalBulan').value);
    else bcalY=parseInt(document.getElementById('bcalTahun').value);
    renderBcal();
}
function prevBcal(){bcalM--;if(bcalM<1){bcalM=12;bcalY--;}populateBcalSelects();renderBcal();}
function nextBcal(){bcalM++;if(bcalM>12){bcalM=1;bcalY++;}populateBcalSelects();renderBcal();}

function renderBcal(){
    var hmap=buildHmap();
    document.getElementById('bcalTitle').textContent=BLN[bcalM]+' '+bcalY;
    document.getElementById('bcalBulan').value=bcalM;
    document.getElementById('bcalTahun').value=bcalY;

    var html='';
    SH.forEach(function(h){html+='<div class="bcal-dh">'+h+'</div>';});

    var fd=new Date(bcalY,bcalM-1,1).getDay(), dim=new Date(bcalY,bcalM,0).getDate();
    var prevDim=new Date(bcalY,bcalM-1,0).getDate();

    // Prev month
    for(var i=fd-1;i>=0;i--){
        var pd=prevDim-i, pm=bcalM-1, py=bcalY; if(pm<1){pm=12;py--;}
        var pds=toKey(py,pm,pd), ph=hmap[pds];
        var cls='bcal-cell other-m'+(isWknd(py,pm,pd)?' is-wknd':'')+(ph?(ph.cuti?' is-cuti':' is-holiday'):'');
        html+='<div class="'+cls+'"><div class="bcal-num">'+pd+'</div>'+(ph?'<span class="bcal-chip'+(ph.cuti?' c-cuti':'')+'" title="'+ph.name+'">'+ph.name+'</span>':'')+'</div>';
    }
    // Current month
    for(var d=1;d<=dim;d++){
        var ds=toKey(bcalY,bcalM,d), h=hmap[ds], iw=isWknd(bcalY,bcalM,d), it=(ds===TODAY);
        var cls='bcal-cell'+(iw?' is-wknd':'')+(h?(h.cuti?' is-cuti':' is-holiday'):'');
        var numHtml=it?'<div class="bcal-num"><span class="bcal-today-dot">'+d+'</span></div>':'<div class="bcal-num">'+d+'</div>';
        html+='<div class="'+cls+'" onclick="showDayDetail(\''+ds+'\')">'+ numHtml+(h?'<span class="bcal-chip'+(h.cuti?' c-cuti':'')+'" title="'+h.name+'">'+h.name+'</span>':'')+'</div>';
    }
    // Next month
    var cells=fd+dim, remain=cells%7?7-(cells%7):0;
    for(var d=1;d<=remain;d++){
        var nd=d, nm=bcalM+1, ny=bcalY; if(nm>12){nm=1;ny++;}
        var nds=toKey(ny,nm,nd), nh=hmap[nds];
        var cls='bcal-cell other-m'+(isWknd(ny,nm,nd)?' is-wknd':'')+(nh?(nh.cuti?' is-cuti':' is-holiday'):'');
        html+='<div class="'+cls+'"><div class="bcal-num">'+nd+'</div>'+(nh?'<span class="bcal-chip'+(nh.cuti?' c-cuti':'')+'" title="'+nh.name+'">'+nh.name+'</span>':'')+'</div>';
    }
    document.getElementById('bcalGrid').innerHTML=html;
    closeDayDetail();
}

/* ─── Day detail popup ── */
function showDayDetail(ds){
    var hmap=buildHmap(), h=hmap[ds];
    var dt=new Date(ds+'T00:00:00'), dow=dt.getDay();
    var dstr=dt.getDate()+' '+BLN[dt.getMonth()+1]+' '+dt.getFullYear()+' · '+HARI[dow];
    document.getElementById('dayDetailTitle').textContent=dstr;
    var body='';
    if(h){
        var dcls=h.cuti?'d-dot-purple':'d-dot-red';
        body+='<div class="detail-row"><div class="detail-dot '+dcls+'"></div><div><strong>'+h.name+'</strong><br><span style="font-size:.72rem;color:var(--ht2)">'+(h.cuti?'Cuti Bersama':'Hari Libur Nasional')+'</span></div></div>';
        body+='<div style="margin-top:.6rem">';
        body+='<a href="?hapus='+h.id+'" onclick="return confirm(\'Hapus hari libur ini?\')" style="font-size:.73rem;color:var(--hr);text-decoration:none;display:inline-flex;align-items:center;gap:.3rem;padding:.32rem .65rem;border:1px solid var(--hrbd);border-radius:var(--rr3);background:var(--hrb)">'
            +'<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>Hapus</a>';
        body+='</div>';
    } else {
        body='<p style="font-size:.78rem;color:var(--ht2);padding:.3rem 0">Tidak ada hari libur pada tanggal ini.</p>';
    }
    document.getElementById('dayDetailBody').innerHTML=body;
    var panel=document.getElementById('dayDetail');
    panel.style.display='block';
    panel.scrollIntoView({behavior:'smooth',block:'nearest'});
}
function closeDayDetail(){document.getElementById('dayDetail').style.display='none';}

/* ─── List view ── */
function resetFilter(){document.getElementById('fBulan').value='';renderList();}
function renderList(){
    var fB=parseInt(document.getElementById('fBulan').value)||0;
    var fT=parseInt(document.getElementById('fTahun').value)||0;
    var filtered=HOLIDAYS.filter(function(h){
        var pts=h.date.split('-'), y=parseInt(pts[0]), m=parseInt(pts[1]);
        if(fT&&y!==fT) return false;
        if(fB&&m!==fB) return false;
        return true;
    });
    document.getElementById('badgeCount').textContent=filtered.length+' hari';

    // Hapus bulan btn
    var hba=document.getElementById('hapusBulanArea');
    if(fB&&fT&&filtered.length>0){
        var nm=BLN[fB];
        var csrfToken='<?php echo htmlspecialchars(csrf_generate(), ENT_QUOTES, "UTF-8"); ?>';
        hba.innerHTML='<form method="POST"><input type="hidden" name="csrf_token" value="'+csrfToken+'"><input type="hidden" name="action" value="hapus_bulan"><input type="hidden" name="hapus_bulan" value="'+fB+'"><input type="hidden" name="hapus_tahun" value="'+fT+'">'
            +'<button type="submit" class="btn-danger-sm" onclick="return confirm(\'Hapus semua '+filtered.length+' hari libur di '+nm+' '+fT+'?\')">'
            +'<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>'
            +'Hapus '+nm+' '+fT+' ('+filtered.length+')</button></form>';
    } else hba.innerHTML='';

    if(!filtered.length){
        document.getElementById('listContent').innerHTML='<div class="empty-state"><svg width="38" height="38" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg><p>Tidak ada hari libur</p><small>Coba ubah filter atau tambah data</small></div>';
        return;
    }
    var groups={};
    filtered.forEach(function(h){var mk=h.date.substring(0,7);if(!groups[mk])groups[mk]=[];groups[mk].push(h);});
    var html='';
    Object.keys(groups).sort().forEach(function(mk){
        var pts=mk.split('-'), mn=BLN[parseInt(pts[1])]+' '+pts[0];
        html+='<div class="month-group"><div class="month-label"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>'+mn+'</div>';
        groups[mk].forEach(function(h){
            var dt=new Date(h.date+'T00:00:00'), dow=dt.getDay(), iw=dow===0||dow===6;
            var rc='hl-row'+(h.cuti?' row-cuti':' row-holiday');
            var dc='hl-date'+(h.cuti?' h-purple':' h-red');
            var bc=iw?'d-wknd':'d-wkday';
            var dstr=dt.getDate()+' '+BLN[dt.getMonth()+1].substring(0,3)+' '+dt.getFullYear();
            html+='<div class="'+rc+'"><div><div class="'+dc+'">'+dstr+'</div><span class="hl-day-badge '+bc+'">'+HARI[dow]+'</span></div>'
                +'<div class="hl-info">'+h.name+(h.cuti?'<span class="tag-cuti">Cuti Bersama</span>':'')+'</div>'
                +'<a href="?hapus='+h.id+'" class="btn-del" onclick="return confirm(\'Hapus hari libur ini?\')" title="Hapus">'
                +'<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>'
                +'</a></div>';
        });
        html+='</div>';
    });
    document.getElementById('listContent').innerHTML=html;
}

/* ─── Tour ── */
var HL_TOUR_KEY='absensi_harlibur_tour_v3';
function muatDriverJs(cb){
    if(window.driver&&window.driver.js){cb();return;}
    var sc=document.createElement('script');
    sc.src='https://cdnjs.cloudflare.com/ajax/libs/driver.js/1.3.1/driver.js.iife.js';
    sc.onload=function(){setTimeout(cb,150);};
    document.head.appendChild(sc);
}
function buatTourHl(){
    var drv=window.driver.js.driver({
        popoverClass:'hl-tour',showProgress:true,progressText:'{{current}} dari {{total}}',
        nextBtnText:'Berikutnya →',prevBtnText:'← Kembali',doneBtnText:'✓ Selesai',
        allowClose:true,overlayColor:'rgba(17,24,39,.75)',smoothScroll:true,animate:true,
        onDestroyStarted:function(){localStorage.setItem(HL_TOUR_KEY,'1');drv.destroy();},
        steps:[
            {popover:{title:'📅 Kelola Hari Libur',description:'Halaman ini untuk mengatur semua hari libur sistem — import otomatis, tambah manual, atau hapus massal.',side:'over',align:'center'}},
            {element:'#tour-hl-header',popover:{title:'📊 Statistik',description:'Total hari libur tersimpan dan jumlah khusus untuk tahun ini.',side:'bottom',align:'start'}},
            {element:'#tour-hl-import',popover:{title:'☁️ Import Otomatis',description:'Cara tercepat! Pilih tahun lalu klik <strong>Import</strong>. Gagal API? Otomatis pakai data lokal.',side:'right',align:'start'}},
            {element:'#tour-hl-mode',popover:{title:'✏️ Tiga Mode Input',description:'<strong>Satu Tanggal</strong> — satu hari.<br><strong>Rentang</strong> — dari A ke B.<br><strong>Pilih Bebas</strong> — klik kalender mini.',side:'bottom',align:'center'}},
            {element:'#tour-hl-daftar',popover:{title:'📅 Kalender & Daftar',description:'Kalender: lihat visual bulan, klik tanggal untuk detail dan hapus.<br>Daftar: tabel filter per bulan/tahun.',side:'top',align:'center'}},
            {element:'#btnTourHl',popover:{title:'❓ Panduan',description:'Klik kapan saja untuk membuka panduan ini kembali.',side:'bottom',align:'end'}},
            {popover:{title:'🎉 Siap!',description:'Tanggal merah = libur nasional · Ungu = cuti bersama.<br>Import data dulu sebelum sistem digunakan.',side:'over',align:'center'}}
        ]
    });
    drv.drive();
}
function mulaiTourHl(){muatDriverJs(buatTourHl);}
if(!localStorage.getItem(HL_TOUR_KEY)){
    window.addEventListener('load',function(){setTimeout(mulaiTourHl,800);});
}

/* ─── Auto dismiss main alert ── */
var ma=document.getElementById('mainAlert');
if(ma) setTimeout(function(){ma.style.cssText+='opacity:0;transition:opacity .4s';setTimeout(function(){if(ma&&ma.parentNode)ma.parentNode.removeChild(ma);},400);},4000);

/* ─── Init ── */
populateBcalSelects();
renderBcal();
renderKal();
</script>

<?php include '../templates/footer.php'; ?>