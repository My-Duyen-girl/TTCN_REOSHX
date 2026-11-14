<?php
require_once __DIR__.'/common.php';
try {
 kiem_tra_phuong_thuc('POST');
 $u=kiem_tra_quyen(['Nhân viên','Admin']);
 $staff=lay_ma_nhan_vien($pdo,(int)$u['MaTaiKhoan']);
 if(!$staff)
    tra_ve_json(403,'Tài khoản chưa có hồ sơ nhân viên.');
 $logId=filter_input(INPUT_POST,'MaNhatKy',FILTER_VALIDATE_INT);
 if(!$logId)
    tra_ve_json(422,'Thiếu mã nhật ký.');
 $s=$pdo->prepare('SELECT nk.MaNhatKy FROM NhatKyChamSoc nk WHERE nk.MaNhatKy=? AND nk.MaNhanVien=?');
 $s->execute([$logId,$staff]);
 if(!$s->fetch())
    tra_ve_json(403,'Không có quyền tải tệp lên nhật ký này.');
 if(empty($_FILES['tep'])||$_FILES['tep']['error']!==UPLOAD_ERR_OK)
    tra_ve_json(422,'Tệp tải lên không hợp lệ.');
$f=$_FILES['tep'];if($f['size']>30*1024*1024)
tra_ve_json(413,'Dung lượng tệp tối đa 30 MB.');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
 $types=['image/jpeg'=>['jpg','Hình ảnh'],'image/png'=>['png','Hình ảnh'],'image/gif'=>['gif','Hình ảnh'],'video/mp4'=>['mp4','Video'],'video/webm'=>['webm','Video']];
 if(!isset($types[$mime]))
    tra_ve_json(415,'Chỉ hỗ trợ JPG, PNG, GIF, MP4 và WEBM.');
 $dir=__DIR__.'/../uploads/nhat_ky';
 if(!is_dir($dir)&&!mkdir($dir,0755,true))
    tra_ve_json(500,'Không tạo được thư mục lưu tệp.');
$name=bin2hex(random_bytes(16)).'.'.$types[$mime][0];
$target=$dir.'/'.$name;if(!move_uploaded_file($f['tmp_name'],$target))tra_ve_json(500,'Không lưu được tệp.');
$path='uploads/nhat_ky/'.$name;
 try
 {
    $s=$pdo->prepare('INSERT INTO TepNhatKy(MaNhatKy,LoaiTep,DuongDanTep) VALUES(?,?,?)');
    $s->execute([$logId,$types[$mime][1],$path]);
 }
 catch(Throwable $e)
 {
    @unlink($target);throw $e;
 }
 tra_ve_json(201,'Tải tệp lên thành công.',['MaTep'=>(int)$pdo->lastInsertId(),'DuongDanTep'=>$path,'LoaiTep'=>$types[$mime][1]]);
} 
catch(PDOException $e)
{
    bat_loi($e);
}
