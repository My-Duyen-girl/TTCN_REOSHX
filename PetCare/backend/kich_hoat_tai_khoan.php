<?php
require_once __DIR__.'/common.php';
try {
 $token=$_GET['token']??''; 
if(!preg_match('/^[a-f0-9]{64}$/',$token)) 
{
    tra_ve_json(422,'Mã kích hoạt không hợp lệ.');
}
 $s=$pdo->prepare('SELECT MaTaiKhoan,HanSuDung FROM KichHoatTaiKhoan WHERE MaBam=?'); 
 $s->execute([hash('sha256',$token)]); 
 $row=$s->fetch();
 if(!$row)
{
    tra_ve_json(404,'Mã kích hoạt không tồn tại hoặc đã sử dụng.');
}   
 if(strtotime($row['HanSuDung'])<time())
{
     tra_ve_json(410,'Mã kích hoạt đã hết hạn.');
}
 $pdo->beginTransaction(); 
 $s=$pdo->prepare("UPDATE TaiKhoan SET TrangThai='Hoạt động' WHERE MaTaiKhoan=? AND TrangThai='Chờ kích hoạt'");
 $s->execute([$row['MaTaiKhoan']]);
 $s=$pdo->prepare('DELETE FROM KichHoatTaiKhoan WHERE MaTaiKhoan=?'); 
 $s->execute([$row['MaTaiKhoan']]); 
 $pdo->commit();
 tra_ve_json(200,'Kích hoạt tài khoản thành công.');
} catch(PDOException $e) 
{ 
    if($pdo->inTransaction())
        $pdo->rollBack();
     bat_loi($e); 
}
