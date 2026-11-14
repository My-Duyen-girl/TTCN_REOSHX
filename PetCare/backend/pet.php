<?php
require_once __DIR__.'/common.php';
$action=$_GET['action']??'danh_sach';
try {
 $u=kiem_tra_dang_nhap(); $owner=lay_ma_chu_nuoi($pdo,(int)$u['MaTaiKhoan']);
 if($action==='danh_sach') 
  {
   kiem_tra_phuong_thuc('GET');
   if($u['VaiTro']==='Chủ nuôi') 
    {
       $s=$pdo->prepare("SELECT * FROM ThuCung WHERE MaChuNuoi=? AND TrangThai='Hiển thị' ORDER BY MaThuCung DESC");
       $s->execute([$owner]); 
    }
   else 
    {
       $s=$pdo->query("SELECT tc.*,cn.HoTen AS TenChuNuoi FROM ThuCung tc JOIN ChuNuoi cn ON tc.MaChuNuoi=cn.MaChuNuoi WHERE tc.TrangThai='Hiển thị' ORDER BY tc.MaThuCung DESC"); 
    }
   tra_ve_json(200,'Lấy danh sách thú cưng thành công.',$s->fetchAll());
  }
 if($action==='chi_tiet') 
  {
     kiem_tra_phuong_thuc('GET'); 
     $id=doc_id('id');
     if(!kiem_tra_thu_cung($pdo,$id,$u))
      tra_ve_json(403,'Không có quyền xem thú cưng này.'); 
    $s=$pdo->prepare('SELECT * FROM ThuCung WHERE MaThuCung=?');
    $s->execute([$id]);
    $pet=$s->fetch();
    if(!$pet)
      tra_ve_json(404,'Không tìm thấy thú cưng.');
    tra_ve_json(200,'Chi tiết thú cưng.',$pet); 
  }
 if($action==='them') 
  {
     kiem_tra_phuong_thuc('POST'); 
     if($u['VaiTro']!=='Chủ nuôi')
      tra_ve_json(403,'Chỉ chủ nuôi được tạo hồ sơ thú cưng.'); 
    if(!$owner)tra_ve_json(404,'Không tìm thấy hồ sơ chủ nuôi.'); 
    $d=nhan_du_lieu();kiem_tra_du_lieu($d,['TenThuCung']); 
    if(isset($d['CanNang']) && (!is_numeric($d['CanNang']) || $d['CanNang']<0))
      tra_ve_json(422,'Cân nặng không hợp lệ.'); 
    $s=$pdo->prepare("INSERT INTO ThuCung(MaChuNuoi,TenThuCung,GioiTinh,NgaySinh,Loai,Giong,CanNang,DiUng,GhiChu) VALUES(?,?,?,?,?,?,?,?,?)");
    $s->execute([$owner,$d['TenThuCung'],$d['GioiTinh']??null,$d['NgaySinh']??null,$d['Loai']??null,$d['Giong']??null,$d['CanNang']??null,$d['DiUng']??null,$d['GhiChu']??null]);
    tra_ve_json(201,'Thêm hồ sơ thú cưng thành công.',['MaThuCung'=>(int)$pdo->lastInsertId()]); 
  }
 if($action==='cap_nhat') 
  {
     kiem_tra_phuong_thuc('PUT');
     if(!in_array($u['VaiTro'],['Chủ nuôi','Admin'],true))
      tra_ve_json(403,'Nhân viên chỉ được xem hồ sơ và cập nhật tiêm chủng.');
    $id=doc_id('id');if(!kiem_tra_thu_cung($pdo,$id,$u))
    tra_ve_json(403,'Không có quyền cập nhật thú cưng này.');
    $d=nhan_du_lieu();kiem_tra_du_lieu($d,['TenThuCung']);
    if(isset($d['CanNang'])&&$d['CanNang']!==null&&(!is_numeric($d['CanNang'])||$d['CanNang']<0))
      tra_ve_json(422,'Cân nặng không hợp lệ.');
    $s=$pdo->prepare('UPDATE ThuCung SET TenThuCung=?,GioiTinh=?,NgaySinh=?,Loai=?,Giong=?,CanNang=?,DiUng=?,GhiChu=? WHERE MaThuCung=?');
    $s->execute([$d['TenThuCung'],$d['GioiTinh']??null,$d['NgaySinh']??null,$d['Loai']??null,$d['Giong']??null,$d['CanNang']??null,$d['DiUng']??null,$d['GhiChu']??null,$id]);
    tra_ve_json(200,'Cập nhật hồ sơ thú cưng thành công.'); 
  }
 if($action==='an') 
  {
     kiem_tra_phuong_thuc('PATCH');
     if(!in_array($u['VaiTro'],['Chủ nuôi','Admin'],true))
      tra_ve_json(403,'Nhân viên không được ẩn hồ sơ thú cưng.');
    $id=doc_id('id');if(!kiem_tra_thu_cung($pdo,$id,$u))
    tra_ve_json(403,'Không có quyền ẩn thú cưng này.');
    $s=$pdo->prepare("UPDATE ThuCung SET TrangThai='Đã ẩn' WHERE MaThuCung=?");
    $s->execute([$id]);tra_ve_json(200,'Đã ẩn hồ sơ thú cưng.'); 
  }
 tra_ve_json(404,'Không tìm thấy hành động.');
} 
catch(PDOException $e)
{
  bat_loi($e);
}
