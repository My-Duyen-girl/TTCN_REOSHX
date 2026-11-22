<?php
require_once 'db.php';
$hash = password_hash('123456', PASSWORD_DEFAULT);
$conn->prepare("UPDATE TaiKhoan SET MatKhau = ?")->execute([$hash]);
echo "Đã đổi mật khẩu tất cả tài khoản thành 123456!";
?>