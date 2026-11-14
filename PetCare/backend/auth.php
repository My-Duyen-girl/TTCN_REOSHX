<?php
require_once __DIR__.'/common.php';
require_once __DIR__.'/gui_email.php';
$action=$_GET['action']??'';
try 
{
 if($action==='dang_ky') 
   {
    kiem_tra_phuong_thuc('POST');
    $d=nhan_du_lieu(); 
    kiem_tra_du_lieu($d,['Email','SDT','MatKhau','HoTen']);
    if(!filter_var($d['Email'],FILTER_VALIDATE_EMAIL)) 
      tra_ve_json(422,'Email không đúng định dạng.');
    if(!preg_match('/^[0-9]{9,10}$/',(string)$d['SDT'])) 
      tra_ve_json(422,'Số điện thoại phải có 9 hoặc 10 chữ số.');
    if(strlen((string)$d['MatKhau'])<8) 
      tra_ve_json(422,'Mật khẩu phải có ít nhất 8 ký tự.');
    $pdo->beginTransaction();
    $s=$pdo->prepare("INSERT INTO TaiKhoan(Email,SDT,MatKhau,VaiTro,TrangThai) VALUES(?,?,?,'Chủ nuôi','Chờ kích hoạt')");
    $s->execute([$d['Email'],$d['SDT'],password_hash($d['MatKhau'],PASSWORD_DEFAULT)]); 
    $id=(int)$pdo->lastInsertId();
    $s=$pdo->prepare('INSERT INTO ChuNuoi(MaTaiKhoan,HoTen,DiaChi) VALUES(?,?,?)');
    $s->execute([$id,$d['HoTen'],$d['DiaChi']??null]);
    $token=bin2hex(random_bytes(32)); $hash=hash('sha256',$token);
    $s=$pdo->prepare('INSERT INTO KichHoatTaiKhoan(MaTaiKhoan,MaBam,HanSuDung) VALUES(?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))'); 
    $s->execute([$id,$hash]);
    $pdo->commit();
    $base=rtrim((string)($d['base_url']??''),'/');
    if ($base === '') 
      {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $projectPath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/backend/auth.php')), '/\\');
        $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $projectPath;
      }
    $link=$base.'/backend/kich_hoat_tai_khoan.php?token='.urlencode($token);
    $sent=gui_mail_xac_thuc($d['Email'],$link);
    tra_ve_json(201,$sent?'Đăng ký thành công. Vui lòng kiểm tra email để kích hoạt.':'Đã tạo tài khoản nhưng máy chủ chưa gửi được email. Quản trị viên cần cấu hình SMTP.', ['MaTaiKhoan'=>$id,'email_da_gui'=>$sent]);
 }
 if($action==='dang_nhap') 
   {
    kiem_tra_phuong_thuc('POST'); $d=nhan_du_lieu(); 
    kiem_tra_du_lieu($d,['Email','MatKhau']);
    $s=$pdo->prepare('SELECT MaTaiKhoan,Email,SDT,MatKhau,VaiTro,TrangThai FROM TaiKhoan WHERE Email=?'); 
    $s->execute([$d['Email']]); $u=$s->fetch();
    if(!$u || !password_verify($d['MatKhau'],$u['MatKhau']))
       tra_ve_json(401,'Email hoặc mật khẩu không chính xác.');
    if($u['TrangThai']!=='Hoạt động') 
      tra_ve_json(403,
    $u['TrangThai']==='Khóa'?'Tài khoản đã bị khóa.':'Tài khoản chưa được kích hoạt.');
    session_regenerate_id(true); unset($u['MatKhau']); 
    $_SESSION['user']=$u;
    tra_ve_json(200,'Đăng nhập thành công.',$u);
   }
 if($action==='dang_xuat') 
   {
       kiem_tra_phuong_thuc('POST');
       $_SESSION=[]; 
       if(ini_get('session.use_cookies')) 
         {
             $p=session_get_cookie_params(); 
             setcookie(session_name(),'',time()-42000,$p['path'],$p['domain'],$p['secure'],$p['httponly']); 
         }
         session_destroy(); 
         tra_ve_json(200,'Đăng xuất thành công.'); 
   }
 if($action==='toi_tai_khoan') 
   {
       $u=kiem_tra_dang_nhap(); 
       tra_ve_json(200,'Thông tin tài khoản.',$u); 
   }
 tra_ve_json(404,'Không tìm thấy hành động.');
} 
catch(PDOException $e) 
{
    if($pdo->inTransaction())$pdo->rollBack(); 
    if((string)$e->getCode()==='23000')
      tra_ve_json(409,'Email hoặc số điện thoại đã được sử dụng.'); 
   bat_loi($e);
}
