<?php
require_once __DIR__.'/common.php';
$action=$_GET['action']??'danh_sach';
try 
{
 $u=kiem_tra_dang_nhap();
 if($action==='danh_sach') 
    {
     kiem_tra_phuong_thuc('GET');
     $petId=doc_id('ma_thu_cung');
     if(!kiem_tra_thu_cung($pdo,$petId,$u))
        tra_ve_json(403,'Không có quyền xem lịch sử tiêm chủng.');
    $s=$pdo->prepare('SELECT * FROM TiemChung WHERE MaThuCung=? ORDER BY NgayTiem DESC');
    $s->execute([$petId]);
    tra_ve_json(200,'Lấy lịch sử tiêm chủng thành công.',$s->fetchAll());
    }
 if($action==='them') 
    {
         kiem_tra_phuong_thuc('POST');
         kiem_tra_quyen(['Nhân viên','Admin']);
         $d=nhan_du_lieu();
         kiem_tra_du_lieu($d,['MaThuCung','NgayTiem','NgayHen']);
         $petId=(int)$d['MaThuCung'];
         $s=$pdo->prepare('SELECT MaThuCung FROM ThuCung WHERE MaThuCung=?');
         $s->execute([$petId]);
         if(!$s->fetch())
            tra_ve_json(404,'Không tìm thấy thú cưng.');
        if(strtotime($d['NgayHen'])<strtotime($d['NgayTiem']))
            tra_ve_json(422,'Ngày hẹn tái chủng không được trước ngày tiêm.');
        $s=$pdo->prepare('INSERT INTO TiemChung(MaThuCung,TenVacxin,NgayTiem,NgayHen) VALUES(?,?,?,?)');
        $s->execute([$petId,$d['TenVacxin']??null,$d['NgayTiem'],$d['NgayHen']]);
        tra_ve_json(201,'Thêm lịch sử tiêm chủng thành công.',['MaTiemChung'=>(int)$pdo->lastInsertId()]); 
    }
 if($action==='xoa') 
    {
         kiem_tra_phuong_thuc('DELETE');
         kiem_tra_quyen(['Nhân viên','Admin']);
         $id=doc_id('id');
         $s=$pdo->prepare('DELETE FROM TiemChung WHERE MaTiemChung=?');
         $s->execute([$id]);tra_ve_json(200,'Đã xóa bản ghi tiêm chủng.'); 
    }
 tra_ve_json(404,'Không tìm thấy hành động.');
} 
catch(PDOException $e)
{
    bat_loi($e);
}
