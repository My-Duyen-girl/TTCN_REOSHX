<?php
require_once __DIR__.'/common.php';
try {
 kiem_tra_phuong_thuc('POST');
 kiem_tra_quyen(['Admin']);
 $d=nhan_du_lieu();
 kiem_tra_du_lieu($d,['Email','SDT','MatKhau','HoTen']);
 if(!filter_var($d['Email'],FILTER_VALIDATE_EMAIL))
    tra_ve_json(422,'Email không hợp lệ.');
if(strlen($d['MatKhau'])<8)
    tra_ve_json(422,'Mật khẩu tối thiểu 8 ký tự.');
 $pdo->beginTransaction();
 $s=$pdo->prepare("INSERT INTO TaiKhoan(Email,SDT,MatKhau,VaiTro,TrangThai) VALUES(?,?,?,'Nhân viên','Hoạt động')");
 $s->execute([$d['Email'],$d['SDT'],password_hash($d['MatKhau'],PASSWORD_DEFAULT)]);
 $account=(int)$pdo->lastInsertId();
 $s=$pdo->prepare("INSERT INTO NhanVien(MaTaiKhoan,HoTen,ChuyenMon,TrangThaiLamViec) VALUES(?,?,?,'Đang làm')");
 $s->execute([$account,$d['HoTen'],$d['ChuyenMon']??null]);
 $staff=(int)$pdo->lastInsertId();
 $pdo->commit();tra_ve_json(201,'Tạo tài khoản nhân viên thành công.',['MaTaiKhoan'=>$account,'MaNhanVien'=>$staff]);
} 
catch(PDOException $e)
{
    if($pdo->inTransaction())
        $pdo->rollBack();if((string)
    $e->getCode()==='23000')
    tra_ve_json(409,'Email hoặc số điện thoại đã tồn tại.');
    bat_loi($e);
}
