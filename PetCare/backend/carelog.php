<?php
require_once __DIR__.'/common.php';
$action=$_GET['action']??'danh_sach';
try 
{
 $u=kiem_tra_dang_nhap();
 if($action==='danh_sach') 
    { 
        kiem_tra_phuong_thuc('GET');
        $sql="SELECT nk.*,nv.HoTen AS TenNhanVien,lh.MaThuCung,tc.TenThuCung,lh.MaLichHen 
              FROM NhatKyChamSoc nk JOIN NhanVien nv ON nk.MaNhanVien=nv.MaNhanVien 
                                    JOIN LichHen lh ON nk.MaLichHen=lh.MaLichHen 
                                    JOIN ThuCung tc ON lh.MaThuCung=tc.MaThuCung";
        if($u['VaiTro']==='Chủ nuôi')
            {
                $sql.=' JOIN ChuNuoi cn ON tc.MaChuNuoi=cn.MaChuNuoi WHERE cn.MaTaiKhoan=?';
                $s=$pdo->prepare($sql.' ORDER BY nk.ThoiGianGhiNhan DESC');
                $s->execute([$u['MaTaiKhoan']]);
            }
        else
            {
                $s=$pdo->query($sql.' ORDER BY nk.ThoiGianGhiNhan DESC');
            }
        $rows=$s->fetchAll();
        foreach($rows as &$r)
            {
                $q=$pdo->prepare('SELECT * FROM TepNhatKy WHERE MaNhatKy=?');
                $q->execute([$r['MaNhatKy']]);
                $r['TepDinhKem']=$q->fetchAll();
            }
        tra_ve_json(200,'Danh sách nhật ký chăm sóc.',$rows);
    }
 if($action==='them') 
    {
        kiem_tra_phuong_thuc('POST');
        $u=kiem_tra_quyen(['Nhân viên','Admin']);
        $staff=lay_ma_nhan_vien($pdo,(int)$u['MaTaiKhoan']);
        if(!$staff)tra_ve_json(403,'Tài khoản chưa có hồ sơ nhân viên đang làm.');
        $d=nhan_du_lieu();kiem_tra_du_lieu($d,['MaLichHen','NoiDungChamSoc']);
        $s=$pdo->prepare("SELECT TrangThai FROM LichHen WHERE MaLichHen=?");
        $s->execute([$d['MaLichHen']]);
        $status=$s->fetchColumn();
        if(!$status)tra_ve_json(404,'Không tìm thấy lịch hẹn.');
        if(!in_array($status,['Đang thực hiện','Hoàn thành'],true))
            tra_ve_json(409,'Chỉ tạo nhật ký khi lịch đang thực hiện hoặc đã hoàn thành.');
        $s=$pdo->prepare('INSERT INTO NhatKyChamSoc(MaLichHen,MaNhanVien,NoiDungChamSoc) VALUES(?,?,?)');
        $s->execute([$d['MaLichHen'],$staff,$d['NoiDungChamSoc']]);
        tra_ve_json(201,'Tạo nhật ký chăm sóc thành công.',['MaNhatKy'=>(int)$pdo->lastInsertId()]); 
    }
 tra_ve_json(404,'Không tìm thấy hành động.');
}
catch(PDOException $e)
{
    bat_loi($e);
}
