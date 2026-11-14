<?php
require_once __DIR__.'/common.php';
$action=$_GET['action']??'danh_sach';
try 
{
 $u=kiem_tra_dang_nhap();
 if($action==='danh_sach') 
    { 
        kiem_tra_phuong_thuc('GET');
        $sql="SELECT lh.*,tc.TenThuCung,dv.TenDichVu 
              FROM LichHen lh 
              JOIN ThuCung tc 
              ON lh.MaThuCung=tc.MaThuCung 
              JOIN DichVu dv 
              ON lh.MaDichVu=dv.MaDichVu";
        if($u['VaiTro']==='Chủ nuôi')
            {
                $sql.=' JOIN ChuNuoi cn ON tc.MaChuNuoi=cn.MaChuNuoi WHERE cn.MaTaiKhoan=?';
                $s=$pdo->prepare($sql.' ORDER BY lh.ThoiGianBD DESC');
                $s->execute([$u['MaTaiKhoan']]);
            }
            else
            {
                $s=$pdo->query($sql.' ORDER BY lh.ThoiGianBD DESC');
            }
            tra_ve_json(200,'Danh sách lịch hẹn.',$s->fetchAll()); 
        }
 if($action==='dat_lich') 
    {
         kiem_tra_phuong_thuc('POST');
         if($u['VaiTro']!=='Chủ nuôi')
            tra_ve_json(403,'Chỉ chủ nuôi được đặt lịch.');
        $d=nhan_du_lieu();
        kiem_tra_du_lieu($d,['MaThuCung','MaDichVu','ThoiGianBD','ThoiGianKT']);
        $pet=(int)$d['MaThuCung'];if(!kiem_tra_thu_cung($pdo,$pet,$u))
        tra_ve_json(403,'Thú cưng không thuộc quyền sở hữu của bạn.');
        $start=strtotime($d['ThoiGianBD']);
        $end=strtotime($d['ThoiGianKT']);if(!$start||!$end||$end<=$start||$start<time())
        tra_ve_json(422,'Thời gian đặt lịch không hợp lệ.');
        $s=$pdo->prepare("SELECT CanNang FROM ThuCung WHERE MaThuCung=? AND TrangThai='Hiển thị'");
        $s->execute([$pet]);$weight=$s->fetchColumn();
        if($weight===false)tra_ve_json(404,'Không tìm thấy hồ sơ thú cưng.');
        if($weight===null)tra_ve_json(422,'Thú cưng chưa có cân nặng để tính giá.');
        $s=$pdo->prepare("SELECT dv.MaDichVu FROM DichVu dv WHERE dv.MaDichVu=? AND dv.TrangThai='Hoạt động'");
        $s->execute([$d['MaDichVu']]);if(!$s->fetch())
        tra_ve_json(404,'Dịch vụ không tồn tại hoặc đã ngừng hoạt động.');
        $s=$pdo->prepare('SELECT GiaTien 
                          FROM GiaCanNang 
                          WHERE MaDichVu=? AND ? >= ToiThieu AND ? < ToiDa 
                          ORDER BY ToiThieu LIMIT 1');
        $s->execute([$d['MaDichVu'],$weight,$weight]);
        $price=$s->fetchColumn();
        if($price===false)
            tra_ve_json(422,'Chưa có mức giá phù hợp với cân nặng thú cưng.');
        $s=$pdo->prepare("SELECT COUNT(*) 
                          FROM LichHen 
                          WHERE MaThuCung=? AND TrangThai<>'Đã hủy' AND ThoiGianBD < ? AND ThoiGianKT > ?");
        $s->execute([$pet,$d['ThoiGianKT'],$d['ThoiGianBD']]);
        if($s->fetchColumn())
            tra_ve_json(409,'Thú cưng đã có lịch hẹn trùng thời gian.');
        $s=$pdo->prepare("INSERT INTO LichHen(MaThuCung,MaDichVu,ThoiGianBD,ThoiGianKT,TrangThai,GhiChu,TongTien) 
                            VALUES(?,?,?,?, 'Chờ xác nhận',?,?)");
        $s->execute([$pet,$d['MaDichVu'],$d['ThoiGianBD'],$d['ThoiGianKT'],$d['GhiChu']??null,$price]);
        tra_ve_json(201,'Đặt lịch thành công, đang chờ xác nhận.',['MaLichHen'=>(int)$pdo->lastInsertId(),'TongTien'=>(float)$price]); 
    }
 if($action==='huy_lich') 
    {
        kiem_tra_phuong_thuc('PATCH');$id=doc_id('id');
        $s=$pdo->prepare("SELECT lh.TrangThai,cn.MaTaiKhoan 
                          FROM LichHen lh JOIN ThuCung tc ON lh.MaThuCung=tc.MaThuCung 
                                          JOIN ChuNuoi cn ON tc.MaChuNuoi=cn.MaChuNuoi 
                          WHERE lh.MaLichHen=?");
        $s->execute([$id]);
        $row=$s->fetch();
        if(!$row)
            tra_ve_json(404,'Không tìm thấy lịch hẹn.');
        if($u['VaiTro']==='Chủ nuôi' && (int)$row['MaTaiKhoan']!==(int)$u['MaTaiKhoan'])
            tra_ve_json(403,'Không có quyền hủy lịch này.');
        if($u['VaiTro']==='Chủ nuôi' && $row['TrangThai']!=='Chờ xác nhận')
            tra_ve_json(409,'Chỉ được hủy lịch đang chờ xác nhận.');
        if(!in_array($u['VaiTro'],['Chủ nuôi','Nhân viên','Admin'],true))
            tra_ve_json(403,'Không có quyền.');
        $s=$pdo->prepare("UPDATE LichHen SET TrangThai='Đã hủy' WHERE MaLichHen=?");
        $s->execute([$id]);tra_ve_json(200,'Đã hủy lịch hẹn.'); 
    }
 if($action==='cap_nhat_trang_thai') 
    { 
        kiem_tra_phuong_thuc('PATCH');
        kiem_tra_quyen(['Nhân viên','Admin']);
        $id=doc_id('id');$d=nhan_du_lieu();
        kiem_tra_du_lieu($d,['TrangThai']);
        $allowed=['Chờ xác nhận','Đang thực hiện','Hoàn thành','Đã hủy'];
        if(!in_array($d['TrangThai'],$allowed,true))
            tra_ve_json(422,'Trạng thái lịch hẹn không hợp lệ.');
        $s=$pdo->prepare('UPDATE LichHen SET TrangThai=? WHERE MaLichHen=?');
        $s->execute([$d['TrangThai'],$id]);if(!$s->rowCount())
        tra_ve_json(404,'Không tìm thấy lịch hẹn hoặc trạng thái không thay đổi.');
        tra_ve_json(200,'Cập nhật trạng thái lịch hẹn thành công.'); 
    }
 tra_ve_json(404,'Không tìm thấy hành động.');
} 
catch(PDOException $e)
{
    bat_loi($e);
}
