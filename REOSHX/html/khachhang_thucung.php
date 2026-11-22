<?php
session_start();
// Chặn nếu chưa đăng nhập hoặc không phải Khách hàng
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Khách hàng') {
    header('Location: dangNhap.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Trang Khách Hàng - Pet Care & Spa</title>
    <link href="KH_tongquan.php">
</head>
<body style="font-family: 'Segoe UI', sans-serif; padding: 40px;">
    <h2>Đăng nhập thành công!</h2>
    <p>Xin chào khách hàng: <strong><?= htmlspecialchars($_SESSION['HoTen']) ?></strong></p>
    <p>Email: <strong><?= htmlspecialchars($_SESSION['Email']) ?></strong></p>
    <p>Mã Khách Hàng (MaKhachHang): <strong><?= $_SESSION['MaKhachHang'] ?></strong></p>
    <br>
    <a href="dangXuat.php" style="color: red; font-weight: bold;">Đăng xuất</a>
</body>
</html>