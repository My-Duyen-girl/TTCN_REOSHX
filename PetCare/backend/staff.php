<?php
require_once __DIR__.'/common.php';
try 
{
 $u=kiem_tra_quyen(['Admin']);
 if(($_GET['action']??'danh_sach')==='danh_sach') 
    { 
        kiem_tra_phuong_thuc('GET');
        $s=$pdo->query('SELECT nv.MaNhanVien,nv.MaTaiKhoan,nv.HoTen,nv.ChuyenMon,nv.TrangThaiLamViec,tk.Email,tk.SDT 
                        FROM NhanVien nv 
                        JOIN TaiKhoan tk ON nv.MaTaiKhoan=tk.MaTaiKhoan 
                        ORDER BY nv.MaNhanVien DESC');
        tra_ve_json(200,'Danh sách nhân viên.',$s->fetchAll()); 
    }
 if(($_GET['action']??'')==='cap_nhat') 
    {
         kiem_tra_phuong_thuc('PUT');
         $id=doc_id('id');
         $d=nhan_du_lieu();
         kiem_tra_du_lieu($d,['HoTen','TrangThaiLamViec']);
         if(!in_array($d['TrangThaiLamViec'],['Đang làm','Nghỉ việc'],true))
            tra_ve_json(422,'Trạng thái làm việc không hợp lệ.');
        $s=$pdo->prepare('UPDATE NhanVien SET HoTen=?,ChuyenMon=?,TrangThaiLamViec=? WHERE MaNhanVien=?');
        $s->execute([$d['HoTen'],$d['ChuyenMon']??null,$d['TrangThaiLamViec'],$id]);
        tra_ve_json(200,'Cập nhật nhân viên thành công.'); 
    }
 tra_ve_json(404,'Không tìm thấy hành động.');
} catch(PDOException $e)
{
    bat_loi($e);
}
