
<?php
require_once "config/ket_noi_csdl.php";
$email = "nguyenduyen1924@gmail.com";
$sdt = "0967993984";
$password = "24102005";
$hoTen = "Nguyễn Thị Mỹ Duyên";

$check = $conn->prepare(
    "SELECT MaTaiKhoan FROM TaiKhoan
     WHERE Email = ? OR SDT = ?"
);

$check->execute([$email, $sdt]);

if ($check->fetch()) {
    exit("Email hoặc số điện thoại đã tồn tại!");
}
$hash = password_hash($password, PASSWORD_DEFAULT);
$sql = "INSERT INTO TaiKhoan
        (Email, SDT, MatKhau, VaiTro, TrangThai, NgayTao)
        VALUES (?, ?, ?, 'Admin', 'Hoạt động', NOW())";

$stmt = $conn->prepare($sql);
$stmt->execute([$email, $sdt, $hash]);

echo "Tạo tài khoản Admin thành công!";

?>
