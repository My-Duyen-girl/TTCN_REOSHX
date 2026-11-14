<?php
require_once __DIR__.'/common.php';
$action=$_GET['action']??'danh_sach';
try 
{
 if($action==='danh_sach') 
{ 
    kiem_tra_phuong_thuc('GET');
    $s=$pdo->query("SELECT d.*, (SELECT COUNT(*) 
                                 FROM GiaCanNang g 
                                 WHERE g.MaDichVu=d.MaDichVu) AS SoMucGia 
                                 FROM DichVu d 
                                 ORDER BY d.MaDichVu DESC");
    tra_ve_json(200,'Danh sách dịch vụ.',$s->fetchAll()); }
 $u=kiem_tra_quyen(['Admin']);
 if($action==='them') 
    { 
        kiem_tra_phuong_thuc('POST');
        $d=nhan_du_lieu();kiem_tra_du_lieu($d,['TenDichVu']);
        $s=$pdo->prepare('INSERT INTO DichVu(TenDichVu,MoTa,TrangThai) VALUES(?,?,?)');
        $s->execute([$d['TenDichVu'],$d['MoTa']??null,$d['TrangThai']??'Hoạt động']);
        tra_ve_json(201,'Thêm dịch vụ thành công.',['MaDichVu'=>(int)$pdo->lastInsertId()]); 
    }
 if($action==='cap_nhat') 
    { 
        kiem_tra_phuong_thuc('PUT');$id=doc_id('id');
        $d=nhan_du_lieu();
        kiem_tra_du_lieu($d,['TenDichVu','TrangThai']);
        $s=$pdo->prepare('UPDATE DichVu SET TenDichVu=?,MoTa=?,TrangThai=? WHERE MaDichVu=?');
        $s->execute([$d['TenDichVu'],$d['MoTa']??null,$d['TrangThai'],$id]);tra_ve_json(200,'Cập nhật dịch vụ thành công.'); 
    }
 if($action==='them_gia') 
    {
         kiem_tra_phuong_thuc('POST');
         $d=nhan_du_lieu();
         kiem_tra_du_lieu($d,['MaDichVu','ToiThieu','ToiDa','GiaTien']);
         if(!is_numeric($d['ToiThieu'])||!is_numeric($d['ToiDa'])||!is_numeric($d['GiaTien'])||$d['ToiThieu']<0||$d['ToiDa']<=$d['ToiThieu']||$d['GiaTien']<0)
            tra_ve_json(422,'Khoảng cân nặng hoặc giá tiền không hợp lệ.');
        $s=$pdo->prepare('SELECT COUNT(*) FROM DichVu WHERE MaDichVu=?');
        $s->execute([$d['MaDichVu']]);if(!$s->fetchColumn())
        tra_ve_json(404,'Không tìm thấy dịch vụ.');
        $s=$pdo->prepare('SELECT COUNT(*) FROM GiaCanNang WHERE MaDichVu=? AND NOT (? <= ToiThieu OR ? >= ToiDa)');
        $s->execute([$d['MaDichVu'],$d['ToiDa'],$d['ToiThieu']]);
        if($s->fetchColumn())
            tra_ve_json(409,'Khoảng cân nặng bị trùng với mức giá đã có.');
        $s=$pdo->prepare('INSERT INTO GiaCanNang(MaDichVu,ToiThieu,ToiDa,GiaTien) VALUES(?,?,?,?)');
        $s->execute([$d['MaDichVu'],$d['ToiThieu'],$d['ToiDa'],$d['GiaTien']]);
        tra_ve_json(201,'Thêm mức giá thành công.',['MaGia'=>(int)$pdo->lastInsertId()]); 
    }
 if($action==='bang_gia') 
    {
         kiem_tra_phuong_thuc('GET');
         $id=doc_id('ma_dich_vu');
         $s=$pdo->prepare('SELECT * FROM GiaCanNang WHERE MaDichVu=? ORDER BY ToiThieu');
         $s->execute([$id]);tra_ve_json(200,'Bảng giá dịch vụ.',
         $s->fetchAll()); 
    }
 tra_ve_json(404,'Không tìm thấy hành động.');
} 
catch(PDOException $e)
{
    bat_loi($e);
}
