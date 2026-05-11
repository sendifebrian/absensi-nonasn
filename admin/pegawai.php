<?php
require_once __DIR__ . '/../config/init.php';
$is_qr_page = false; // dashboard bukan halaman QR, jadi selalu false di sini
if (!$is_qr_page) {
    restore_web_session($pdo);
}
require_login();
if (!is_admin()) { header('Location: dashboard.php'); exit(); }

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_pegawai'])) {
    csrf_verify();
    $id=(int)$_POST['id']; $nama=trim($_POST['nama']); $username=trim($_POST['username']);
    $email=trim($_POST['email']); $jabatan=trim($_POST['jabatan']); $unit_kerja=trim($_POST['unit_kerja']);
    $nik=trim($_POST['nik']??''); $tempat_lahir=trim($_POST['tempat_lahir']??'');
    $tgl_lahir=!empty($_POST['tgl_lahir'])?$_POST['tgl_lahir']:null; $no_hp=trim($_POST['no_hp']??'');
    if (!$nama||!$username||!$email||!$jabatan||!$unit_kerja) { $error="Semua field bertanda * wajib diisi."; }
    else {
        $cek=$pdo->prepare("SELECT id FROM users WHERE username=? AND id!=?"); $cek->execute([$username,$id]);
        if ($cek->fetch()) { $error="Username sudah digunakan oleh pegawai lain."; }
        else {
            $pdo->prepare("UPDATE users SET nama=?,username=?,email=?,jabatan=?,unit_kerja=?,nik=?,tempat_lahir=?,tgl_lahir=?,no_hp=? WHERE id=? AND role='pegawai'")->execute([$nama,$username,$email,$jabatan,$unit_kerja,$nik,$tempat_lahir,$tgl_lahir,$no_hp,$id]);
            header("Location: pegawai.php?success=edit"); exit();
        }
    }
}
if (isset($_GET['reset_pass'])) {
    $id=(int)$_GET['reset_pass'];
    $s=$pdo->prepare("SELECT email FROM users WHERE id=? AND role='pegawai'"); $s->execute([$id]);
    $em=$s->fetchColumn();
    if ($em) $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($em,PASSWORD_DEFAULT),$id]);
    header("Location: pegawai.php?success=reset"); exit();
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['set_password'])) {
    csrf_verify();
    $id=(int)$_POST['id']; $new_pass=trim($_POST['new_password']); $confirm_pass=trim($_POST['confirm_password']);
    if (!$new_pass || strlen($new_pass)<6) { $error_pass="Password minimal 6 karakter."; $setpass_open_id=$id; }
    elseif ($new_pass!==$confirm_pass) { $error_pass="Konfirmasi password tidak cocok."; $setpass_open_id=$id; }
    else { $pdo->prepare("UPDATE users SET password=? WHERE id=? AND role='pegawai'")->execute([password_hash($new_pass,PASSWORD_DEFAULT),$id]); header("Location: pegawai.php?success=setpass"); exit(); }
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['tambah_pegawai'])) {
    csrf_verify();
    $nama=trim($_POST['nama']); $username=trim($_POST['username']); $email=trim($_POST['email']);
    $jabatan=trim($_POST['jabatan']); $unit_kerja=trim($_POST['unit_kerja']); $password=$_POST['password'];
    $nik=trim($_POST['nik']??''); $tempat_lahir=trim($_POST['tempat_lahir']??'');
    $tgl_lahir=!empty($_POST['tgl_lahir'])?$_POST['tgl_lahir']:null; $no_hp=trim($_POST['no_hp']??'');
    if (!$nama||!$username||!$email||!$jabatan||!$unit_kerja||!$password) { $error="Semua field wajib harus diisi."; }
    else {
        $cek=$pdo->prepare("SELECT id FROM users WHERE username=?"); $cek->execute([$username]);
        if ($cek->fetch()) { $error="Username sudah digunakan."; }
        else {
            $pdo->prepare("INSERT INTO users(nama,username,email,jabatan,unit_kerja,password,role,nik,tempat_lahir,tgl_lahir,no_hp)VALUES(?,?,?,?,?,?,'pegawai',?,?,?,?)")->execute([$nama,$username,$email,$jabatan,$unit_kerja,password_hash($password,PASSWORD_DEFAULT),$nik,$tempat_lahir,$tgl_lahir,$no_hp]);
            header("Location: pegawai.php?success=tambah"); exit();
        }
    }
}
if (isset($_GET['toggle_status'])) {
    $id=(int)$_GET['toggle_status'];
    $s=$pdo->prepare("SELECT status FROM users WHERE id=? AND role='pegawai'"); $s->execute([$id]); $u=$s->fetch();
    if ($u) $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$u['status']==='aktif'?'nonaktif':'aktif',$id]);
    header("Location: pegawai.php?success=status"); exit();
}
if (isset($_GET['hapus'])) {
    $pdo->prepare("DELETE FROM users WHERE id=? AND role='pegawai'")->execute([(int)$_GET['hapus']]);
    header("Location: pegawai.php?success=hapus"); exit();
}
$pegawai=$pdo->query("SELECT * FROM users WHERE role='pegawai' ORDER BY created_at DESC,nama ASC")->fetchAll(PDO::FETCH_ASSOC);
$tahunList=[];
foreach($pegawai as $p){if($p['created_at']){$t=date('Y',strtotime($p['created_at']));if(!in_array($t,$tahunList))$tahunList[]=$t;}}
rsort($tahunList);
$totalPegawai=count($pegawai); $aktif=$nonaktif=0;
foreach($pegawai as $p){$p['status']==='aktif'?$aktif++:$nonaktif++;}
?>
<?php include '../templates/header.php'; ?>
<?php include '../templates/navbar.php'; ?>
<?php include '../templates/sidebar.php'; ?>

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
    --am:#C8962A;--am-lt:#F0C85A;--am-dim:#FDF6E3;--am-bd:#EDD98A;
    --nv:#0F1E3C;--nv-md:#1A3260;
    --s50:#F8FAFC;--s100:#F1F5F9;--s200:#E2E8F0;--s300:#CBD5E1;
    --s400:#94A3B8;--s500:#64748B;--s600:#475569;--s700:#334155;--s800:#1E293B;--s900:#0F172A;
    --ok:#059669;--ok-bg:#ECFDF5;--ok-bd:#A7F3D0;
    --er:#DC2626;--er-bg:#FEF2F2;--er-bd:#FECACA;
    --r8:8px;--r12:12px;--r16:16px;--r20:20px;--r24:24px;
    --sh-sm:0 1px 3px rgba(15,30,60,.06),0 1px 2px rgba(15,30,60,.04);
    --sh-md:0 4px 12px rgba(15,30,60,.08),0 1px 3px rgba(15,30,60,.05);
    --sh-lg:0 10px 30px rgba(15,30,60,.10),0 2px 6px rgba(15,30,60,.06);
    --sh-xl:0 20px 50px rgba(15,30,60,.13),0 4px 12px rgba(15,30,60,.07);
    --font:'Plus Jakarta Sans',-apple-system,sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

.pgw{padding:1.5rem 1.5rem 3rem;max-width:1440px;margin:0 auto;font-family:var(--font);animation:pgIn .4s ease both}
@keyframes pgIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

/* Header */
.pgw-hd{display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1.5rem;padding-bottom:1.25rem;border-bottom:1px solid var(--s200)}
.pgw-ey{display:inline-flex;align-items:center;gap:.35rem;font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.14em;color:var(--am);margin-bottom:.28rem}
.pgw-t1{font-size:1.35rem;font-weight:800;color:var(--s900);letter-spacing:-.02em;margin-bottom:.15rem;line-height:1.2}
.pgw-t2{font-size:.75rem;color:var(--s400)}
.pgw-hd-r{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}

/* Buttons */
.pbtn{display:inline-flex;align-items:center;gap:.38rem;padding:.5rem .95rem;border-radius:var(--r12);font-size:.775rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all .2s cubic-bezier(.22,1,.36,1);white-space:nowrap;font-family:var(--font);line-height:1.4}
.pbtn i{font-size:.85rem;flex-shrink:0}
.pbtn-primary{background:var(--am);color:#fff;box-shadow:0 2px 8px rgba(200,150,42,.3)}
.pbtn-primary:hover{background:#B8841C;transform:translateY(-1px);box-shadow:0 6px 18px rgba(200,150,42,.38);color:#fff}
.pbtn-dark{background:var(--nv);color:#fff;box-shadow:0 2px 8px rgba(15,30,60,.2)}
.pbtn-dark:hover{background:var(--nv-md);transform:translateY(-1px);color:#fff}
.pbtn-ghost{background:#fff;color:var(--s600);border:1.5px solid var(--s200)}
.pbtn-ghost:hover{border-color:var(--am);color:var(--am);background:var(--am-dim)}
.pbtn-sec{background:var(--s100);color:var(--s600);border:1.5px solid var(--s200)}
.pbtn-sec:hover{background:var(--s200)}

/* Alert */
.pgw-al{display:flex;align-items:center;gap:.65rem;padding:.8rem 1.1rem;border-radius:var(--r12);font-size:.78rem;font-weight:500;margin-bottom:1.25rem;animation:slideD .3s ease both}
@keyframes slideD{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
.pgw-al-ok{background:var(--ok-bg);border:1px solid var(--ok-bd);color:#065F46}
.pgw-al-er{background:var(--er-bg);border:1px solid var(--er-bd);color:#991B1B}
.pgw-al-close{margin-left:auto;background:none;border:none;font-size:1.1rem;line-height:1;cursor:pointer;color:inherit;opacity:.6;padding:0;transition:opacity .15s}
.pgw-al-close:hover{opacity:1}

/* Stats */
.pgw-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-bottom:1.25rem}
.pgw-stat{background:#fff;border:1px solid var(--s200);border-radius:var(--r20);padding:1.1rem 1.25rem;display:flex;align-items:center;gap:1rem;box-shadow:var(--sh-sm);transition:all .22s cubic-bezier(.22,1,.36,1);position:relative;overflow:hidden}
.pgw-stat::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;border-radius:var(--r20) var(--r20) 0 0;opacity:0;transition:opacity .2s}
.pgw-stat:hover{transform:translateY(-3px);box-shadow:var(--sh-lg)}
.pgw-stat:hover::before{opacity:1}
.st-total::before{background:linear-gradient(90deg,var(--am),var(--am-lt))}
.st-aktif::before{background:linear-gradient(90deg,#059669,#34D399)}
.st-off::before{background:linear-gradient(90deg,var(--s400),var(--s300))}
.pgw-stat-ico{width:48px;height:48px;border-radius:var(--r12);display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.st-total .pgw-stat-ico{background:var(--am-dim);color:var(--am)}
.st-aktif .pgw-stat-ico{background:var(--ok-bg);color:var(--ok)}
.st-off   .pgw-stat-ico{background:var(--s100);color:var(--s400)}
.pgw-stat-lbl{font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;color:var(--s400);margin-bottom:.22rem}
.pgw-stat-val{font-size:1.75rem;font-weight:800;color:var(--s900);line-height:1;margin-bottom:.1rem;letter-spacing:-.03em}
.pgw-stat-sub{font-size:.66rem;color:var(--s400)}

/* Filter */
.pgw-fc{background:#fff;border:1px solid var(--s200);border-radius:var(--r20);padding:1.1rem 1.25rem;box-shadow:var(--sh-sm);margin-bottom:1rem}
.pgw-fr{display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap}
.pgw-fi{flex:1;min-width:140px}
.pgw-fi-lg{flex:2.5;min-width:200px}
.pgw-lbl{display:block;font-size:.67rem;font-weight:600;color:var(--s600);margin-bottom:.3rem;letter-spacing:.02em}
.pgw-lbl .opt{font-weight:400;color:var(--s400);font-size:.62rem;font-style:italic}
.pgw-inp{width:100%;padding:.52rem .85rem;border:1.5px solid var(--s200);border-radius:var(--r12);font-size:.8rem;color:var(--s800);background:#fff;font-family:var(--font);transition:border-color .2s,box-shadow .2s;outline:none;-webkit-appearance:none}
.pgw-inp:focus{border-color:var(--am);box-shadow:0 0 0 3px rgba(200,150,42,.12)}
.pgw-inp::placeholder{color:var(--s300)}
.pgw-sw{position:relative}
.pgw-sw .pgw-inp{padding-left:2.4rem}
.pgw-si{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:var(--s400);font-size:.82rem;pointer-events:none}
.pgw-ri{font-size:.7rem;color:var(--s400);margin-top:.75rem}
.pgw-ri strong{color:var(--s700);font-weight:700}

/* Table */
.pgw-tc{background:#fff;border:1px solid var(--s200);border-radius:var(--r20);box-shadow:var(--sh-sm);overflow:hidden}
.pgw-ts{overflow-x:auto;-webkit-overflow-scrolling:touch}
.pgw-tbl{width:100%;border-collapse:collapse;font-size:.8rem;font-family:var(--font)}
.pgw-tbl thead th{padding:.85rem 1.1rem;background:var(--s50);color:var(--s400);font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.09em;border-bottom:2px solid var(--am);white-space:nowrap}
.pgw-tbl tbody td{padding:.9rem 1.1rem;border-bottom:1px solid var(--s100);vertical-align:middle;transition:background .15s}
.pgw-tbl tbody tr:last-child td{border-bottom:none}
.pgw-tbl tbody tr:hover td{background:var(--s50)}
.td-num{color:var(--s300);font-size:.72rem;font-weight:600;width:4%}
.td-txt{color:var(--s500);font-size:.78rem}
.td-dt{color:var(--s400);font-size:.72rem;white-space:nowrap}

/* User cell */
.pgw-usr{display:flex;align-items:center;gap:.75rem}
.pgw-av{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--am),var(--am-lt));color:#7A4E00;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.82rem;flex-shrink:0;overflow:hidden;box-shadow:0 2px 8px rgba(200,150,42,.2)}
.pgw-av img{width:100%;height:100%;object-fit:cover}
.pgw-un{font-weight:700;color:var(--s800);font-size:.8rem;line-height:1.3}
.pgw-ue{font-size:.67rem;color:var(--s400);margin-top:.1rem}

/* Badges */
.pgw-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .62rem;border-radius:99px;font-size:.67rem;font-weight:600;white-space:nowrap}
.bg-usr{background:var(--s100);color:var(--nv);border:1px solid var(--s200)}
.bg-ok{background:var(--ok-bg);color:#065F46;border:1px solid var(--ok-bd)}
.bg-off{background:var(--s100);color:var(--s500);border:1px solid var(--s200)}

/* Actions */
.pgw-acts{display:flex;align-items:center;justify-content:center;gap:.3rem}
.pgw-act{width:30px;height:30px;border-radius:var(--r8);border:1.5px solid;display:flex;align-items:center;justify-content:center;font-size:.75rem;cursor:pointer;transition:all .18s cubic-bezier(.22,1,.36,1);background:#fff;flex-shrink:0}
.pgw-act:hover{transform:scale(1.12)}
.a-ed{color:var(--am);border-color:#FDD76A}.a-ed:hover{background:var(--am);color:#fff;border-color:var(--am)}
.a-ky{color:#2563EB;border-color:#BFDBFE}.a-ky:hover{background:#2563EB;color:#fff;border-color:#2563EB}
.a-ps{color:#7C3AED;border-color:#DDD6FE}.a-ps:hover{background:#7C3AED;color:#fff;border-color:#7C3AED}
.a-lk{color:#D97706;border-color:#FDE68A}.a-lk:hover{background:#D97706;color:#fff;border-color:#D97706}
.a-ul{color:#059669;border-color:#A7F3D0}.a-ul:hover{background:#059669;color:#fff;border-color:#059669}
.a-dl{color:#EF4444;border-color:#FECACA}.a-dl:hover{background:#EF4444;color:#fff;border-color:#EF4444}

/* Empty */
.pgw-empty{text-align:center;padding:3.5rem 1rem;color:var(--s400)}
.pgw-emp-ico{width:64px;height:64px;background:var(--s100);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.6rem;margin:0 auto 1rem;color:var(--s300)}
.pgw-empty p{font-size:.82rem;margin:0}

/* Modal */
.modal-content{border:none!important;border-radius:var(--r24)!important;box-shadow:var(--sh-xl)!important;font-family:var(--font)!important;overflow:hidden}
.pgw-mhd{padding:1.2rem 1.5rem;background:var(--s50);border-bottom:2px solid var(--am)!important;display:flex;align-items:center;justify-content:space-between}
.pgw-mhd-t{display:flex;align-items:center;gap:.6rem;font-size:.9rem;font-weight:800;color:var(--s900);font-family:var(--font)}
.pgw-mhd-ic{width:32px;height:32px;background:var(--am-dim);border-radius:var(--r8);display:flex;align-items:center;justify-content:center;font-size:.9rem;color:var(--am)}
.modal-body{padding:1.4rem!important}
.modal-footer{padding:1rem 1.5rem!important;background:var(--s50);border-top:1px solid var(--s200)!important;gap:.5rem}

/* Form */
.pgw-fs{margin-bottom:1.35rem}
.pgw-fs:last-child{margin-bottom:0}
.pgw-sh{display:flex;align-items:center;gap:.5rem;font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--am);padding-bottom:.6rem;border-bottom:1px solid var(--am-bd);margin-bottom:.95rem}
.pgw-sh .note{color:var(--s400);font-size:.6rem;font-weight:400;text-transform:none;letter-spacing:0;margin-left:.2rem}
.pgw-fld{margin-bottom:.9rem}
.pgw-fld:last-child{margin-bottom:0}
.pgw-req{color:var(--er);margin-left:.1rem}
.pgw-opt{font-weight:400;color:var(--s400);font-size:.62rem;font-style:italic;margin-left:.2rem}
.pgw-ht{font-size:.66rem;color:var(--s400);margin-top:.28rem;display:block}

/* Preview */
.pgw-prev{display:flex;align-items:center;gap:.85rem;background:var(--am-dim);border:1px solid var(--am-bd);border-radius:var(--r16);padding:.9rem 1.1rem;margin-bottom:1.2rem}
.pgw-prev-av{width:46px;height:46px;border-radius:50%;background:linear-gradient(135deg,var(--am),var(--am-lt));color:#7A4E00;font-weight:800;font-size:1.1rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 2px 8px rgba(200,150,42,.2)}
.pgw-prev-n{font-weight:700;color:var(--s800);font-size:.88rem}
.pgw-prev-e{font-size:.72rem;color:var(--s500);margin-top:.1rem}

/* Info notif */
.pgw-infobox{display:flex;align-items:flex-start;gap:.6rem;background:#EFF6FF;border:1px solid #BFDBFE;border-radius:var(--r12);padding:.8rem .95rem;font-size:.73rem;color:#1D4ED8}
.pgw-infobox i{font-size:.9rem;flex-shrink:0;margin-top:.05rem}
.pgw-infobox-t{font-weight:700;margin-bottom:.15rem}

/* Tip box */
.pgw-tip{background:var(--am-dim);border:1px solid var(--am-bd);border-radius:var(--r12);padding:.85rem 1rem;font-size:.73rem;color:#7A4E00;margin-top:1rem}
.pgw-tip-t{font-weight:700;margin-bottom:.3rem;display:flex;align-items:center;gap:.4rem}

/* Password */
.pgw-pg{display:flex}
.pgw-pg .pgw-inp{border-radius:var(--r12) 0 0 var(--r12)}
.pgw-ptog{padding:.52rem .75rem;border:1.5px solid var(--s200);border-left:none;border-radius:0 var(--r12) var(--r12) 0;background:var(--s50);cursor:pointer;color:var(--s400);font-size:.82rem;transition:all .15s;display:flex;align-items:center}
.pgw-ptog:hover{background:var(--s100);color:var(--s700)}
.pgw-pm{font-size:.7rem;margin-top:.3rem;min-height:1rem;font-weight:500}
.pm-ok{color:var(--ok)}.pm-er{color:var(--er)}.pm-wr{color:#D97706}

/* Tour btn */
.pgw-tour-btn{display:inline-flex;align-items:center;gap:.32rem;padding:.45rem .78rem;border-radius:var(--r12);font-size:.72rem;font-weight:600;color:var(--s500);background:#fff;border:1.5px solid var(--s200);cursor:pointer;transition:all .2s;font-family:var(--font)}
.pgw-tour-btn:hover{border-color:var(--am);color:var(--am);background:var(--am-dim)}

/* Responsive */
@media(max-width:768px){
    .pgw{padding:1rem .9rem 2.5rem}
    .pgw-stats{grid-template-columns:1fr 1fr}
    .pgw-stats .st-off{grid-column:span 2}
    .pgw-fr{flex-direction:column}
    .pgw-fi,.pgw-fi-lg{min-width:unset;width:100%;flex:none}
    .pgw-tbl thead th,.pgw-tbl tbody td{padding:.7rem .8rem}
    .pgw-act{width:28px;height:28px;font-size:.7rem}
}
@media(max-width:576px){
    .pgw-hd{flex-direction:column;align-items:stretch}
    .pgw-hd-r{width:100%;justify-content:flex-end}
    .pgw-stats{grid-template-columns:1fr}
    .pgw-stats .st-off{grid-column:span 1}
    .pgw-t1{font-size:1.15rem}
    .pgw-stat-val{font-size:1.5rem}
    .modal-dialog{margin:.75rem!important}
    .modal-body{padding:1.1rem!important}
}

/* Tour */
.peg-tour .driver-popover{background:#fff!important;border-radius:var(--r24)!important;box-shadow:0 20px 60px rgba(15,30,60,.18),0 0 0 1px rgba(200,150,42,.2)!important;padding:1.1rem 1.25rem!important;max-width:300px!important;font-family:var(--font)!important}
.peg-tour .driver-popover-title{font-size:.86rem!important;font-weight:800!important;color:var(--s900)!important;padding-bottom:.4rem!important;border-bottom:2px solid var(--am)!important;margin-bottom:.65rem!important}
.peg-tour .driver-popover-description{font-size:.76rem!important;color:var(--s600)!important;line-height:1.65!important}
.peg-tour .driver-popover-progress-text{color:var(--am)!important;font-size:.65rem!important;font-weight:700!important}
.peg-tour .driver-popover-navigation-btns{display:flex!important;align-items:center!important;gap:.32rem!important;margin-top:.8rem!important}
.peg-tour .driver-popover-next-btn,.peg-tour .driver-popover-done-btn{background:var(--am)!important;border:none!important;color:#fff!important;border-radius:var(--r8)!important;padding:.35rem .85rem!important;font-size:.73rem!important;font-weight:700!important;cursor:pointer!important;min-width:unset!important;width:auto!important;height:auto!important;box-shadow:none!important}
.peg-tour .driver-popover-next-btn:hover,.peg-tour .driver-popover-done-btn:hover{background:#B8841C!important}
.peg-tour .driver-popover-prev-btn{background:var(--s100)!important;border:1px solid var(--s200)!important;color:var(--s600)!important;border-radius:var(--r8)!important;padding:.35rem .85rem!important;font-size:.73rem!important;font-weight:500!important;cursor:pointer!important;min-width:unset!important;width:auto!important;height:auto!important;box-shadow:none!important}
.peg-tour .driver-popover-prev-btn:hover{background:var(--s200)!important}
.peg-tour .driver-popover-close-btn{color:var(--s300)!important;background:none!important;border:none!important;padding:0!important}
</style>

<div class="main-content"><div class="pgw">

<!-- HEADER -->
<div class="pgw-hd" id="tour-header">
    <div>
        <div class="pgw-ey"><i class="bi bi-people-fill"></i>Manajemen Pegawai</div>
        <h1 class="pgw-t1">Kelola Pegawai</h1>
        <div class="pgw-t2">Data akun dan informasi seluruh pegawai terdaftar</div>
    </div>
    <div class="pgw-hd-r">
        <button class="pgw-tour-btn" id="btnTourPegawai" onclick="mulaiTour()">
            <i class="bi bi-question-circle"></i><span>Panduan</span>
        </button>
        <a href="export_pegawai_pdf.php" target="_blank" class="pbtn pbtn-dark">
            <i class="bi bi-file-earmark-pdf-fill"></i><span>Export PDF</span>
        </a>
        <button class="pbtn pbtn-primary" data-bs-toggle="modal" data-bs-target="#tambahModal" type="button">
            <i class="bi bi-plus-circle-fill"></i><span>Tambah Pegawai</span>
        </button>
    </div>
</div>

<!-- ALERTS -->
<?php if(isset($_GET['success'])): ?>
<div class="pgw-al pgw-al-ok" id="pgAlert">
    <i class="bi bi-check-circle-fill"></i>
    <?php $msgs=['tambah'=>'Pegawai berhasil ditambahkan!','edit'=>'Data pegawai berhasil diperbarui!','reset'=>'Password berhasil direset ke email!','status'=>'Status akun berhasil diubah!','hapus'=>'Pegawai berhasil dihapus!','setpass'=>'Password pegawai berhasil diubah!']; echo $msgs[$_GET['success']]??'Berhasil!'; ?>
    <button onclick="this.parentElement.remove()" class="pgw-al-close">×</button>
</div>
<?php endif; ?>
<?php if(isset($error)): ?>
<div class="pgw-al pgw-al-er"><i class="bi bi-exclamation-triangle-fill"></i><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- STATS -->
<div class="pgw-stats" id="tour-stats">
    <div class="pgw-stat st-total">
        <div class="pgw-stat-ico"><i class="bi bi-people-fill"></i></div>
        <div><div class="pgw-stat-lbl">Total Pegawai</div><div class="pgw-stat-val"><?php echo $totalPegawai; ?></div><div class="pgw-stat-sub">Terdaftar di sistem</div></div>
    </div>
    <div class="pgw-stat st-aktif">
        <div class="pgw-stat-ico"><i class="bi bi-check-circle-fill"></i></div>
        <div><div class="pgw-stat-lbl">Akun Aktif</div><div class="pgw-stat-val"><?php echo $aktif; ?></div><div class="pgw-stat-sub">Dapat login</div></div>
    </div>
    <div class="pgw-stat st-off">
        <div class="pgw-stat-ico"><i class="bi bi-x-circle-fill"></i></div>
        <div><div class="pgw-stat-lbl">Nonaktif</div><div class="pgw-stat-val"><?php echo $nonaktif; ?></div><div class="pgw-stat-sub">Diblokir</div></div>
    </div>
</div>

<!-- FILTER -->
<div class="pgw-fc" id="tour-filter">
    <div class="pgw-fr">
        <div class="pgw-fi pgw-fi-lg">
            <label class="pgw-lbl"><i class="bi bi-search me-1"></i>Cari Pegawai</label>
            <div class="pgw-sw">
                <input type="text" id="searchInput" class="pgw-inp" placeholder="Nama, username, jabatan, atau unit kerja...">
                <i class="bi bi-search pgw-si"></i>
            </div>
        </div>
        <div class="pgw-fi">
            <label class="pgw-lbl"><i class="bi bi-calendar3 me-1"></i>Tahun Daftar</label>
            <select id="filterTahun" class="pgw-inp">
                <option value="">Semua Tahun</option>
                <?php foreach($tahunList as $t):?><option value="<?php echo $t;?>"><?php echo $t;?></option><?php endforeach;?>
            </select>
        </div>
        <div class="pgw-fi">
            <label class="pgw-lbl"><i class="bi bi-funnel me-1"></i>Status Akun</label>
            <select id="filterStatus" class="pgw-inp">
                <option value="">Semua Status</option>
                <option value="aktif">Aktif</option>
                <option value="nonaktif">Nonaktif</option>
            </select>
        </div>
    </div>
    <div class="pgw-ri" id="resultInfo">Menampilkan <strong><?php echo $totalPegawai; ?></strong> pegawai</div>
</div>

<!-- TABLE -->
<div class="pgw-tc" id="tour-tabel">
    <div class="pgw-ts">
        <table class="pgw-tbl">
            <thead>
                <tr>
                    <th style="width:4%">No</th>
                    <th style="width:24%">Pegawai</th>
                    <th style="width:14%">Username</th>
                    <th style="width:14%">Jabatan</th>
                    <th style="width:13%">Unit Kerja</th>
                    <th style="width:10%">Terdaftar</th>
                    <th style="width:8%">Status</th>
                    <th style="width:13%" class="text-center" id="tour-aksi">Aksi</th>
                </tr>
            </thead>
            <tbody id="tablePegawai">
            <?php if(empty($pegawai)):?>
                <tr><td colspan="8"><div class="pgw-empty"><div class="pgw-emp-ico"><i class="bi bi-inbox"></i></div><p>Belum ada data pegawai</p></div></td></tr>
            <?php else: $no=1; foreach($pegawai as $p):?>
                <tr class="pegawai-row"
                    data-nama="<?php echo strtolower(htmlspecialchars($p['nama']));?>"
                    data-username="<?php echo strtolower(htmlspecialchars($p['username']));?>"
                    data-jabatan="<?php echo strtolower(htmlspecialchars($p['jabatan']));?>"
                    data-unit="<?php echo strtolower(htmlspecialchars($p['unit_kerja']));?>"
                    data-tahun="<?php echo $p['created_at']?date('Y',strtotime($p['created_at'])):'';?>"
                    data-status="<?php echo $p['status'];?>">
                    <td class="td-num"><?php echo $no++;?></td>
                    <td>
                        <div class="pgw-usr">
                            <div class="pgw-av">
                                <?php if(!empty($p['foto'])):?><img src="<?php echo htmlspecialchars(foto_url($p['foto']));?>" alt=""><?php else: echo strtoupper(substr($p['nama'],0,1)); endif;?>
                            </div>
                            <div>
                                <div class="pgw-un"><?php echo htmlspecialchars($p['nama']);?></div>
                                <?php if($p['email']):?><div class="pgw-ue"><?php echo htmlspecialchars($p['email']);?></div><?php endif;?>
                            </div>
                        </div>
                    </td>
                    <td><span class="pgw-badge bg-usr"><i class="bi bi-person-badge"></i><?php echo htmlspecialchars($p['username']);?></span></td>
                    <td class="td-txt"><?php echo htmlspecialchars($p['jabatan']);?></td>
                    <td class="td-txt"><?php echo htmlspecialchars($p['unit_kerja']);?></td>
                    <td class="td-dt"><?php echo $p['created_at']?date('d M Y',strtotime($p['created_at'])):'—';?></td>
                    <td>
                        <?php if($p['status']==='aktif'):?>
                        <span class="pgw-badge bg-ok"><i class="bi bi-check-circle-fill"></i>Aktif</span>
                        <?php else:?>
                        <span class="pgw-badge bg-off"><i class="bi bi-x-circle-fill"></i>Nonaktif</span>
                        <?php endif;?>
                    </td>
                    <td class="text-center">
                        <div class="pgw-acts">
                            <button type="button" class="pgw-act a-ed btn-edit" title="Edit Data"
                                data-id="<?php echo $p['id'];?>"
                                data-nama="<?php echo htmlspecialchars($p['nama'],ENT_QUOTES);?>"
                                data-username="<?php echo htmlspecialchars($p['username'],ENT_QUOTES);?>"
                                data-email="<?php echo htmlspecialchars($p['email'],ENT_QUOTES);?>"
                                data-jabatan="<?php echo htmlspecialchars($p['jabatan'],ENT_QUOTES);?>"
                                data-unit="<?php echo htmlspecialchars($p['unit_kerja'],ENT_QUOTES);?>"
                                data-nik="<?php echo htmlspecialchars($p['nik']??'',ENT_QUOTES);?>"
                                data-tempat="<?php echo htmlspecialchars($p['tempat_lahir']??'',ENT_QUOTES);?>"
                                data-tgl="<?php echo($p['tgl_lahir']&&$p['tgl_lahir']!=='0000-00-00')?$p['tgl_lahir']:'';?>"
                                data-nohp="<?php echo htmlspecialchars($p['no_hp']??'',ENT_QUOTES);?>">
                                <i class="bi bi-pencil-fill"></i>
                            </button>
                            <button type="button" class="pgw-act a-ky" title="Reset Password ke Email"
                                onclick="confirmReset(<?php echo $p['id'];?>,'<?php echo htmlspecialchars($p['nama'],ENT_QUOTES);?>')">
                                <i class="bi bi-key"></i>
                            </button>
                            <button type="button" class="pgw-act a-ps" title="Set Password Baru"
                                onclick="openSetPass(<?php echo $p['id'];?>,'<?php echo htmlspecialchars($p['nama'],ENT_QUOTES);?>')">
                                <i class="bi bi-shield-lock"></i>
                            </button>
                            <button type="button" class="pgw-act <?php echo $p['status']==='aktif'?'a-lk':'a-ul';?>"
                                title="<?php echo $p['status']==='aktif'?'Nonaktifkan':'Aktifkan';?>"
                                onclick="confirmToggle(<?php echo $p['id'];?>,'<?php echo htmlspecialchars($p['nama'],ENT_QUOTES);?>','<?php echo $p['status'];?>')">
                                <i class="bi bi-<?php echo $p['status']==='aktif'?'lock':'unlock';?>"></i>
                            </button>
                            <button type="button" class="pgw-act a-dl" title="Hapus Pegawai"
                                onclick="confirmDelete(<?php echo $p['id'];?>,'<?php echo htmlspecialchars($p['nama'],ENT_QUOTES);?>')">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach;?>
                <tr id="noResultRow" style="display:none">
                    <td colspan="8"><div class="pgw-empty"><div class="pgw-emp-ico"><i class="bi bi-search"></i></div><p>Tidak ada hasil yang sesuai</p></div></td>
                </tr>
            <?php endif;?>
            </tbody>
        </table>
    </div>
</div>

</div></div>

<!-- ═══ MODAL EDIT ═══ -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="pgw-mhd">
                <div class="pgw-mhd-t"><div class="pgw-mhd-ic"><i class="bi bi-pencil-square"></i></div>Edit Data Pegawai</div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST"><input type="hidden" name="id" id="edit_id"><?php csrf_field(); ?>
                <div class="modal-body">
                    <div class="pgw-prev">
                        <div class="pgw-prev-av" id="editAv">?</div>
                        <div><div class="pgw-prev-n" id="editPrevNama">—</div><div class="pgw-prev-e" id="editPrevEmail">—</div></div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="pgw-fs">
                                <div class="pgw-sh"><i class="bi bi-person-fill"></i>Informasi Akun</div>
                                <div class="pgw-fld"><label class="pgw-lbl">Nama Lengkap <span class="pgw-req">*</span></label><input type="text" name="nama" id="edit_nama" class="pgw-inp" required placeholder="Nama lengkap pegawai"></div>
                                <div class="pgw-fld"><label class="pgw-lbl">Username <span class="pgw-req">*</span></label><input type="text" name="username" id="edit_username" class="pgw-inp" required placeholder="username_login"><span class="pgw-ht">Digunakan untuk login ke sistem</span></div>
                                <div class="pgw-fld"><label class="pgw-lbl">Email <span class="pgw-req">*</span></label><input type="email" name="email" id="edit_email" class="pgw-inp" required placeholder="email@contoh.com"></div>
                                <div class="pgw-fld"><label class="pgw-lbl">Jabatan <span class="pgw-req">*</span></label><input type="text" name="jabatan" id="edit_jabatan" class="pgw-inp" required placeholder="Misal: Staff, Analis, Teknisi"></div>
                                <div class="pgw-fld"><label class="pgw-lbl">Unit Kerja <span class="pgw-req">*</span></label><input type="text" name="unit_kerja" id="edit_unit_kerja" class="pgw-inp" required placeholder="Misal: Divisi IT, Hidrologi"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="pgw-fs">
                                <div class="pgw-sh"><i class="bi bi-card-text"></i>Data Pribadi</div>
                                <div class="pgw-fld"><label class="pgw-lbl">NIK <span class="pgw-opt">(Opsional)</span></label><input type="text" name="nik" id="edit_nik" class="pgw-inp" maxlength="20" placeholder="Nomor Induk Kependudukan"></div>
                                <div class="pgw-fld"><label class="pgw-lbl">Tempat Lahir <span class="pgw-opt">(Opsional)</span></label><input type="text" name="tempat_lahir" id="edit_tempat_lahir" class="pgw-inp" placeholder="Kota tempat lahir"></div>
                                <div class="pgw-fld"><label class="pgw-lbl">Tanggal Lahir <span class="pgw-opt">(Opsional)</span></label><input type="date" name="tgl_lahir" id="edit_tgl_lahir" class="pgw-inp"></div>
                                <div class="pgw-fld"><label class="pgw-lbl">No HP <span class="pgw-opt">(Opsional)</span></label><input type="text" name="no_hp" id="edit_no_hp" class="pgw-inp" placeholder="08xxxxxxxxxx"></div>
                                <div class="pgw-infobox" style="margin-top:.5rem">
                                    <i class="bi bi-shield-lock-fill" style="color:#2563eb"></i>
                                    <div><div class="pgw-infobox-t">Password tidak diubah di sini</div>Gunakan tombol <strong>Set Password 🔐</strong> atau <strong>Reset 🔑</strong> di tabel.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="pbtn pbtn-sec" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i>Batal</button>
                    <button type="submit" name="edit_pegawai" class="pbtn pbtn-primary"><i class="bi bi-check-circle-fill"></i>Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ MODAL TAMBAH ═══ -->
<div class="modal fade" id="tambahModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="pgw-mhd">
                <div class="pgw-mhd-t"><div class="pgw-mhd-ic"><i class="bi bi-person-plus-fill"></i></div>Tambah Pegawai Baru</div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <?php csrf_field(); ?>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="pgw-fs">
                                <div class="pgw-sh"><i class="bi bi-person-fill"></i>Informasi Akun <span class="note">— wajib diisi</span></div>
                                <div class="pgw-fld"><label class="pgw-lbl">Nama Lengkap <span class="pgw-req">*</span></label><input type="text" name="nama" class="pgw-inp" required placeholder="Nama lengkap pegawai"></div>
                                <div class="pgw-fld">
                                    <label class="pgw-lbl">Email <span class="pgw-req">*</span></label>
                                    <input type="email" name="email" id="emailInput" class="pgw-inp" required placeholder="email@contoh.com">
                                    <span class="pgw-ht">Email menjadi username &amp; password default</span>
                                </div>
                                <div class="pgw-fld">
                                    <label class="pgw-lbl">Username <span class="pgw-req">*</span></label>
                                    <input type="text" name="username" id="tambahUsername" class="pgw-inp" required readonly style="background:var(--s50);color:var(--s500)" placeholder="Otomatis dari email">
                                    <span class="pgw-ht">Otomatis mengikuti email</span>
                                </div>
                                <div class="pgw-fld"><label class="pgw-lbl">Jabatan <span class="pgw-req">*</span></label><input type="text" name="jabatan" class="pgw-inp" required placeholder="Misal: Staff, Analis, Teknisi"></div>
                                <div class="pgw-fld"><label class="pgw-lbl">Unit Kerja <span class="pgw-req">*</span></label><input type="text" name="unit_kerja" class="pgw-inp" required placeholder="Misal: Divisi IT, Hidrologi"></div>
                                <div class="pgw-fld">
                                    <label class="pgw-lbl">Password <span class="pgw-req">*</span></label>
                                    <div class="pgw-pg">
                                        <input type="password" name="password" class="pgw-inp" id="passwordInput" required placeholder="Min. 6 karakter">
                                        <button class="pgw-ptog" type="button" id="togglePass"><i class="bi bi-eye"></i></button>
                                    </div>
                                    <span class="pgw-ht">Default sama dengan email. Bisa diubah setelah login.</span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="pgw-fs">
                                <div class="pgw-sh"><i class="bi bi-card-text"></i>Data Pribadi <span class="note">— semua opsional</span></div>
                                <div class="pgw-fld">
                                    <label class="pgw-lbl">NIK <span class="pgw-opt">(Opsional)</span></label>
                                    <input type="text" name="nik" class="pgw-inp" maxlength="20" placeholder="Nomor Induk Kependudukan">
                                </div>
                                <div class="pgw-fld">
                                    <label class="pgw-lbl">Tempat Lahir <span class="pgw-opt">(Opsional)</span></label>
                                    <input type="text" name="tempat_lahir" class="pgw-inp" placeholder="Kota tempat lahir">
                                </div>
                                <div class="pgw-fld">
                                    <label class="pgw-lbl">Tanggal Lahir <span class="pgw-opt">(Opsional)</span></label>
                                    <input type="date" name="tgl_lahir" class="pgw-inp">
                                </div>
                                <div class="pgw-fld">
                                    <label class="pgw-lbl">No HP <span class="pgw-opt">(Opsional)</span></label>
                                    <input type="text" name="no_hp" class="pgw-inp" placeholder="08xxxxxxxxxx">
                                </div>
                                <div class="pgw-tip">
                                    <div class="pgw-tip-t"><i class="bi bi-lightbulb-fill" style="color:var(--am)"></i>Tips</div>
                                    Data pribadi bisa dilengkapi kapan saja melalui menu Edit, atau pegawai bisa mengisinya sendiri di profil setelah login.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="pbtn pbtn-sec" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i>Batal</button>
                    <button type="submit" name="tambah_pegawai" class="pbtn pbtn-primary"><i class="bi bi-person-plus-fill"></i>Simpan Pegawai</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══ MODAL SET PASSWORD ═══ -->
<div class="modal fade" id="setPassModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="pgw-mhd">
                <div class="pgw-mhd-t"><div class="pgw-mhd-ic"><i class="bi bi-shield-lock-fill"></i></div>Set Password Baru</div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST"><input type="hidden" name="id" id="setpass_id"><?php csrf_field(); ?>
                <div class="modal-body">
                    <div class="pgw-prev" style="padding:.75rem 1rem;margin-bottom:1rem">
                        <div class="pgw-prev-av" id="setpassAv" style="width:40px;height:40px;font-size:.95rem">?</div>
                        <div><div class="pgw-prev-n" id="setpassNama" style="font-size:.82rem">—</div><div class="pgw-prev-e" style="font-size:.7rem">Ganti password login</div></div>
                    </div>
                    <?php if(isset($error_pass)):?>
                    <div class="pgw-al pgw-al-er mb-3" style="font-size:.74rem;padding:.55rem .8rem"><i class="bi bi-exclamation-triangle-fill"></i><?php echo htmlspecialchars($error_pass);?></div>
                    <?php endif;?>
                    <div class="pgw-fld">
                        <label class="pgw-lbl">Password Baru <span class="pgw-req">*</span></label>
                        <div class="pgw-pg">
                            <input type="password" name="new_password" id="newPassInput" class="pgw-inp" placeholder="Min. 6 karakter" required <?php if(isset($error_pass)) echo 'autofocus';?>>
                            <button class="pgw-ptog" type="button" id="toggleNewPass"><i class="bi bi-eye"></i></button>
                        </div>
                    </div>
                    <div class="pgw-fld">
                        <label class="pgw-lbl">Konfirmasi Password <span class="pgw-req">*</span></label>
                        <div class="pgw-pg">
                            <input type="password" name="confirm_password" id="confirmPassInput" class="pgw-inp" placeholder="Ulangi password baru" required>
                            <button class="pgw-ptog" type="button" id="toggleConfirmPass"><i class="bi bi-eye"></i></button>
                        </div>
                        <div id="passMatchMsg" class="pgw-pm"></div>
                    </div>
                    <div class="pgw-infobox" style="margin-top:.75rem;font-size:.71rem">
                        <i class="bi bi-info-circle-fill"></i>
                        <div>Password baru langsung aktif saat pegawai login berikutnya.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="pbtn pbtn-sec" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i>Batal</button>
                    <button type="submit" name="set_password" id="setPassSubmit" class="pbtn pbtn-primary"><i class="bi bi-shield-check"></i>Simpan Password</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
/* Edit Modal */
document.querySelectorAll('.btn-edit').forEach(function(b){
    b.addEventListener('click',function(){
        var d=this.dataset;
        document.getElementById('edit_id').value=d.id;
        document.getElementById('edit_nama').value=d.nama;
        document.getElementById('edit_username').value=d.username;
        document.getElementById('edit_email').value=d.email;
        document.getElementById('edit_jabatan').value=d.jabatan;
        document.getElementById('edit_unit_kerja').value=d.unit;
        document.getElementById('edit_nik').value=d.nik;
        document.getElementById('edit_tempat_lahir').value=d.tempat;
        document.getElementById('edit_tgl_lahir').value=d.tgl;
        document.getElementById('edit_no_hp').value=d.nohp;
        document.getElementById('editAv').textContent=d.nama?d.nama.charAt(0).toUpperCase():'?';
        document.getElementById('editPrevNama').textContent=d.nama||'—';
        document.getElementById('editPrevEmail').textContent=d.email||'—';
        new bootstrap.Modal(document.getElementById('editModal')).show();
    });
});
document.getElementById('edit_nama').addEventListener('input',function(){
    document.getElementById('editAv').textContent=this.value?this.value.charAt(0).toUpperCase():'?';
    document.getElementById('editPrevNama').textContent=this.value||'—';
});
document.getElementById('edit_email').addEventListener('input',function(){
    document.getElementById('editPrevEmail').textContent=this.value||'—';
});

/* Tambah: email sync */
document.getElementById('emailInput').addEventListener('input',function(){
    document.getElementById('tambahUsername').value=this.value;
    document.getElementById('passwordInput').value=this.value;
});

/* Password toggles */
function setupToggle(btnId,inpId){
    document.getElementById(btnId).addEventListener('click',function(){
        var inp=document.getElementById(inpId),ico=this.querySelector('i');
        inp.type=inp.type==='password'?'text':'password';
        ico.classList.toggle('bi-eye');ico.classList.toggle('bi-eye-slash');
    });
}
setupToggle('togglePass','passwordInput');
setupToggle('toggleNewPass','newPassInput');
setupToggle('toggleConfirmPass','confirmPassInput');

/* Set Password Modal */
function openSetPass(id,nama){
    document.getElementById('setpass_id').value=id;
    document.getElementById('setpassAv').textContent=nama?nama.charAt(0).toUpperCase():'?';
    document.getElementById('setpassNama').textContent=nama||'—';
    document.getElementById('newPassInput').value='';
    document.getElementById('confirmPassInput').value='';
    document.getElementById('passMatchMsg').textContent='';
    document.getElementById('setPassSubmit').disabled=false;
    new bootstrap.Modal(document.getElementById('setPassModal')).show();
}
['newPassInput','confirmPassInput'].forEach(function(id){
    document.getElementById(id).addEventListener('input',function(){
        var p1=document.getElementById('newPassInput').value;
        var p2=document.getElementById('confirmPassInput').value;
        var msg=document.getElementById('passMatchMsg');
        var btn=document.getElementById('setPassSubmit');
        msg.className='pgw-pm';
        if(!p2){msg.textContent='';btn.disabled=false;return;}
        if(p1===p2&&p1.length>=6){msg.classList.add('pm-ok');msg.textContent='✓ Password cocok';btn.disabled=false;}
        else if(p1!==p2){msg.classList.add('pm-er');msg.textContent='✗ Password tidak cocok';btn.disabled=true;}
        else{msg.classList.add('pm-wr');msg.textContent='⚠ Minimal 6 karakter';btn.disabled=true;}
    });
});
<?php if(isset($error_pass)&&isset($setpass_open_id)):?>
window.addEventListener('load',function(){
    var m=new bootstrap.Modal(document.getElementById('setPassModal'));
    document.getElementById('setpass_id').value=<?php echo (int)$setpass_open_id;?>;
    <?php $sp=$pdo->prepare("SELECT nama FROM users WHERE id=? AND role='pegawai'");$sp->execute([(int)$setpass_open_id]);$spNama=$sp->fetchColumn()?:'';?>
    document.getElementById('setpassAv').textContent='<?php echo strtoupper(substr($spNama,0,1));?>';
    document.getElementById('setpassNama').textContent='<?php echo htmlspecialchars($spNama,ENT_QUOTES);?>';
    m.show();
});
<?php endif;?>

/* Filter */
var ft;
['searchInput','filterTahun','filterStatus'].forEach(function(id){
    document.getElementById(id).addEventListener(id==='searchInput'?'input':'change',function(){clearTimeout(ft);ft=setTimeout(filterTable,200);});
});
function filterTable(){
    var s=document.getElementById('searchInput').value.toLowerCase();
    var ty=document.getElementById('filterTahun').value,ts=document.getElementById('filterStatus').value;
    var rows=document.querySelectorAll('.pegawai-row'),n=0;
    rows.forEach(function(r){
        var ok=(s===''||r.dataset.nama.includes(s)||r.dataset.username.includes(s)||r.dataset.jabatan.includes(s)||r.dataset.unit.includes(s))&&(ty===''||r.dataset.tahun===ty)&&(ts===''||r.dataset.status===ts);
        r.style.display=ok?'':'none';if(ok)n++;
    });
    document.getElementById('resultInfo').innerHTML='Menampilkan <strong>'+n+'</strong> dari <strong>'+rows.length+'</strong> pegawai';
    var nr=document.getElementById('noResultRow');if(nr)nr.style.display=n===0?'':'none';
}

/* SweetAlert */
function sw(opts){return Swal.fire({...opts,customClass:{popup:'border-0',confirmButton:'btn btn-sm px-4 fw-semibold rounded-3',cancelButton:'btn btn-sm btn-light px-4 fw-semibold rounded-3'},buttonsStyling:true});}
function confirmReset(id,nama){sw({title:'Reset Password?',html:'Password <strong>'+nama+'</strong> akan direset ke alamat email.',icon:'question',showCancelButton:true,confirmButtonColor:'#0F1E3C',cancelButtonColor:'#94A3B8',confirmButtonText:'Ya, Reset',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed)location.href='?reset_pass='+id;});}
function confirmToggle(id,nama,status){var a=status==='aktif'?'Nonaktifkan':'Aktifkan',c=status==='aktif'?'#D97706':'#059669';sw({title:a+' Akun?',html:'Akun <strong>'+nama+'</strong> akan di'+a.toLowerCase()+'kan.',icon:'warning',showCancelButton:true,confirmButtonColor:c,cancelButtonColor:'#94A3B8',confirmButtonText:'Ya, '+a,cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed)location.href='?toggle_status='+id;});}
function confirmDelete(id,nama){sw({title:'Hapus Pegawai?',html:'Data <strong>'+nama+'</strong> akan dihapus permanen.',icon:'warning',showCancelButton:true,confirmButtonColor:'#DC2626',cancelButtonColor:'#94A3B8',confirmButtonText:'Ya, Hapus',cancelButtonText:'Batal'}).then(function(r){if(r.isConfirmed)location.href='?hapus='+id;});}

/* Auto-hide alert */
var al=document.getElementById('pgAlert');
if(al)setTimeout(function(){al.style.transition='opacity .4s,transform .4s';al.style.opacity='0';al.style.transform='translateY(-6px)';setTimeout(function(){al.remove();},420);},3500);

/* Tour */
var TK='absensi_pegawai_tour_v3';
function muatDriverJs(cb){if(window.driver&&window.driver.js){cb();return;}var sc=document.createElement('script');sc.src='https://cdnjs.cloudflare.com/ajax/libs/driver.js/1.3.1/driver.js.iife.js';sc.onload=function(){setTimeout(cb,150);};document.head.appendChild(sc);}
function buatTour(){
    var drv=window.driver.js.driver({
        popoverClass:'peg-tour',showProgress:true,progressText:'{{current}} dari {{total}}',
        nextBtnText:'Berikutnya →',prevBtnText:'← Kembali',doneBtnText:'✓ Selesai',
        allowClose:true,overlayColor:'rgba(15,30,60,.75)',smoothScroll:true,animate:true,
        onDestroyStarted:function(){localStorage.setItem(TK,'1');drv.destroy();},
        steps:[
            {popover:{title:'👥 Kelola Pegawai',description:'Pusat manajemen akun pegawai — tambah, edit, reset password, hingga nonaktifkan akun.',side:'over',align:'center'}},
            {element:'#tour-stats',popover:{title:'📊 Statistik Cepat',description:'Total pegawai terdaftar, jumlah akun aktif yang dapat login, dan akun nonaktif.',side:'bottom',align:'center'}},
            {element:'#tour-filter',popover:{title:'🔍 Filter & Pencarian',description:'Cari berdasarkan nama, username, atau jabatan. Filter tahun daftar dan status akun secara real-time.',side:'bottom',align:'start'}},
            {element:'#tour-tabel',popover:{title:'📋 Tabel Pegawai',description:'Daftar semua pegawai lengkap dengan foto, username, jabatan, unit kerja, dan status.',side:'top',align:'center'}},
            {element:'#tour-aksi',popover:{title:'⚙️ Tombol Aksi',description:'✏️ Edit data · 🔑 Reset ke email<br>🔐 Set password baru · 🔒 Nonaktifkan · 🗑️ Hapus',side:'left',align:'center'}},
            {element:'#btnTourPegawai',popover:{title:'❓ Buka Panduan Lagi',description:'Klik kapan saja untuk mengulangi panduan ini.',side:'bottom',align:'end'}},
            {popover:{title:'🎉 Siap!',description:'Anda sudah mengenal semua fitur halaman Kelola Pegawai.',side:'over',align:'center'}}
        ]
    });
    drv.drive();
}
function mulaiTour(){muatDriverJs(buatTour);}
if(!localStorage.getItem(TK)){window.addEventListener('load',function(){setTimeout(mulaiTour,700);});}
</script>

<?php include '../templates/footer.php'; ?>