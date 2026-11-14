<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Ho_Chi_Minh');
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/ket_noi_csdl.php';
function tra_ve_json(int $status, string $message, $data=null): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['thanh_cong'=>$status>=200 && $status<300,'thong_bao'=>$message,'du_lieu'=>$data], JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function nhan_du_lieu(): array {
    $raw=file_get_contents('php://input');
    if ($raw !== '') {
        $data=json_decode($raw,true);
        if (json_last_error()!==JSON_ERROR_NONE || !is_array($data)) tra_ve_json(400,'Dữ liệu JSON không hợp lệ.');
        return $data;
    }
    return $_POST;
}
function kiem_tra_phuong_thuc(string $method): void {
    if (($_SERVER['REQUEST_METHOD']??'GET')!==$method) { header('Allow: '.$method); tra_ve_json(405,'Phương thức HTTP không hợp lệ.'); }
}
function lay_nguoi_dung_hien_tai(): ?array { return $_SESSION['user']??null; }
function kiem_tra_dang_nhap(): array {
     $u=lay_nguoi_dung_hien_tai(); 
     if(!$u) tra_ve_json(401,'Vui lòng đăng nhập.');
     return $u; 
}
function kiem_tra_quyen(array $roles): array {
     $u=kiem_tra_dang_nhap(); 
     if(!in_array($u['VaiTro'],$roles,true)) 
        tra_ve_json(403,'Bạn không có quyền thực hiện chức năng này.'); 
    return $u; 
}
function kiem_tra_du_lieu(array $data,array $fields): void {
     foreach($fields as $f) 
        if(!isset($data[$f]) || trim((string)$data[$f])==='') 
            tra_ve_json(422,'Thiếu thông tin bắt buộc: '.$f); 
}
function lay_ma_chu_nuoi(PDO $pdo,int $accountId): ?int {
     $s=$pdo->prepare('SELECT MaChuNuoi FROM ChuNuoi WHERE MaTaiKhoan=?'); 
     $s->execute([$accountId]); 
     $v=$s->fetchColumn(); 
     return $v===false?null:(int)$v; 
}
function lay_ma_nhan_vien(PDO $pdo,int $accountId): ?int {
     $s=$pdo->prepare("SELECT MaNhanVien FROM NhanVien WHERE MaTaiKhoan=? AND TrangThaiLamViec='Đang làm'"); 
     $s->execute([$accountId]); 
     $v=$s->fetchColumn(); 
     return $v===false?null:(int)$v; 
}
function kiem_tra_thu_cung(PDO $pdo,int $petId,array $user): bool {
    if(in_array($user['VaiTro'],['Admin','Nhân viên'],true)) return true;
    $s=$pdo->prepare('SELECT COUNT(*) FROM ThuCung tc JOIN ChuNuoi cn ON tc.MaChuNuoi=cn.MaChuNuoi WHERE tc.MaThuCung=? AND cn.MaTaiKhoan=?');
    $s->execute([$petId,$user['MaTaiKhoan']]); return (int)$s->fetchColumn()>0;
}
function bat_loi(PDOException $e): never { 
    error_log($e->getMessage()); 
    tra_ve_json(500,'Lỗi xử lý cơ sở dữ liệu.'); 
}
function doc_id(string $key): int { 
    $id=filter_input(INPUT_GET,$key,FILTER_VALIDATE_INT); 
    if(!$id || $id<1) 
        tra_ve_json(422,'Mã định danh không hợp lệ.'); 
    return (int)$id; 
}