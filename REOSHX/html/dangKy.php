<?php
session_start();
require_once '../db.php';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ho_ten      = trim($_POST['ho_ten'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $sdt         = trim($_POST['sdt'] ?? '');
    $dia_chi     = trim($_POST['dia_chi'] ?? '');
    $mat_khau    = $_POST['mat_khau'] ?? '';
    $xac_nhan_mk = $_POST['xac_nhan_mk'] ?? '';

    if (empty($ho_ten) || empty($email) || empty($sdt) || empty($mat_khau) || empty($xac_nhan_mk)) {
        $error = 'Vui lòng điền đầy đủ các thông tin bắt buộc!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Địa chỉ Email không đúng định dạng!';
    } elseif (!preg_match('/^[0-9]{9,11}$/', $sdt)) {
        $error = 'Số điện thoại phải gồm 9 đến 11 chữ số!';
    } elseif (strlen($mat_khau) < 6) {
        $error = 'Mật khẩu phải có ít nhất 6 ký tự!';
    } elseif ($mat_khau !== $xac_nhan_mk) {
        $error = 'Mật khẩu xác nhận không khớp!';
    } else {
        // Kiểm tra trùng Email hoặc Số điện thoại trong bảng TaiKhoan
        $stmtCheck = $conn->prepare("SELECT MaTaiKhoan FROM TaiKhoan WHERE Email = ? OR SDT = ? LIMIT 1");
        $stmtCheck->execute([$email, $sdt]);

        if ($stmtCheck->rowCount() > 0) {
            $error = 'Email hoặc Số điện thoại này đã được đăng ký!';
        } else {
            try {
                $conn->beginTransaction();

                // 1. Lưu thông tin đăng nhập vào bảng TaiKhoan (Vai trò mặc định: Khách hàng)
                $hashPass = password_hash($mat_khau, PASSWORD_DEFAULT);
                $stmtTK = $conn->prepare("INSERT INTO TaiKhoan (Email, SDT, MatKhau, VaiTro) VALUES (?, ?, ?, 'Khách hàng')");
                $stmtTK->execute([$email, $sdt, $hashPass]);

                // 2. Lấy MaTaiKhoan vừa tạo và lưu hồ sơ vào bảng KhachHang
                $maTaiKhoan = $conn->lastInsertId();
                $diaChiVal = ($dia_chi !== '') ? $dia_chi : null;
                $stmtKH = $conn->prepare("INSERT INTO KhachHang (MaTaiKhoan, HoTen, DiaChi) VALUES (?, ?, ?)");
                $stmtKH->execute([$maTaiKhoan, $ho_ten, $diaChiVal]);

                $conn->commit();
                $success = 'Đăng ký tài khoản thành công! <a href="dangNhap.php">Nhấn vào đây để đăng nhập</a>';
                $_POST = []; // Xóa dữ liệu cũ trên form sau khi tạo thành công
            } catch (Exception $e) {
                $conn->rollBack();
                $error = 'Có lỗi xảy ra khi tạo tài khoản: ' . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký tài khoản</title>
    
    <style>
        * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

body {
    background-color: #6b7462;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
}

.register-card {
    background-color: #ffffff;
    width: 100%;
    max-width: 460px;
    padding: 40px 42px;
    border-radius: 6px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.18);
    text-align: center;
}

.brand-logo {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-bottom: 18px;
}

.brand-logo svg {
    width: 38px;
    height: 38px;
    fill: #98a190;
}

.brand-logo span {
    font-size: 19px;
    font-weight: 700;
    letter-spacing: 1.5px;
    color: #98a190;
}

h1 {
    font-size: 25px;
    font-weight: 700;
    color: #1e1e1e;
    margin-bottom: 6px;
}

.subtitle {
    font-size: 13.5px;
    color: #888888;
    margin-bottom: 26px;
}

.error-box {
    background-color: #fde8e8;
    color: #c81e1e;
    border: 1px solid #f8b4b4;
    padding: 10px 14px;
    border-radius: 6px;
    font-size: 13.5px;
    margin-bottom: 18px;
    text-align: left;
}

.success-box {
    background-color: #def7ec;
    color: #03543f;
    border: 1px solid #84e1bc;
    padding: 12px 14px;
    border-radius: 6px;
    font-size: 13.5px;
    margin-bottom: 18px;
    text-align: left;
    line-height: 1.5;
}

.success-box a {
    color: #03543f;
    font-weight: 700;
    text-decoration: underline;
}

.form-group {
    margin-bottom: 18px;
    text-align: left;
}

.form-group input {
    width: 100%;
    border: none;
    border-bottom: 1.5px solid #cccccc;
    padding: 9px 2px;
    font-size: 14.5px;
    color: #222222;
    outline: none;
    transition: border-color 0.25s;
}

.form-group input::placeholder {
    color: #999999;
}

.form-group input:focus {
    border-bottom-color: #1e1e1e;
}

.btn-register {
    width: 100%;
    background-color: #1e1e1e;
    color: #ffffff;
    border: none;
    padding: 13px;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 8px;
    transition: background-color 0.25s, transform 0.1s;
}

.btn-register:hover {
    background-color: #333333;
}

.btn-register:active {
    transform: scale(0.99);
}

.login-text {
    margin-top: 22px;
    font-size: 13.5px;
    color: #888888;
}

.login-text a {
    color: #1e1e1e;
    font-weight: 600;
    text-decoration: none;
}

.login-text a:hover {
    text-decoration: underline;
}
    </style>
</head>
<body>
    <div class="register-card">
    <div class="brand-logo">
        <svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
            <path d="M256 224c-79.41 0-192 122.76-192 200.25 0 34.9 26.81 55.75 71.74 55.75 48.84 0 81.09-25.08 120.26-25.08 39.51 0 71.85 25.08 120.26 25.08 44.93 0 71.74-20.85 71.74-55.75C448 346.76 335.41 224 256 224zm-147.28-12.61c-10.4-34.65-42.44-57.09-71.56-50.13-29.12 6.96-44.29 40.69-33.89 75.34 10.4 34.65 42.44 57.09 71.56 50.13 29.12-6.96 44.29-40.69 33.89-75.34zm84.72-20.78c30.94-8.14 46.42-49.94 34.58-93.36s-46.52-72.01-77.46-63.87-46.42 49.94-34.58 93.36c11.84 43.42 46.53 72.02 77.46 63.87zm281.39-29.34c-29.12-6.96-61.15 15.48-71.56 50.13-10.4 34.65 4.77 68.38 33.89 75.34 29.12 6.96 61.15-15.48 71.56-50.13 10.4-34.65-4.77-68.38-33.89-75.34zm-156.27 29.34c30.94 8.14 65.62-20.45 77.46-63.87 11.84-43.42-3.64-85.21-34.58-93.36s-65.62 20.45-77.46 63.87c-11.84 43.42 3.64 85.22 34.58 93.36z"/>
        </svg>
        <span>PETCARE</span>
    </div>

    <h1>Tạo tài khoản mới</h1>
    <p class="subtitle">Đăng ký tài khoản để đặt lịch chăm sóc cho thú cưng của bạn</p>

    <?php if (!empty($error)): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if (!empty($success)): ?>
        <div class="success-box"><?= $success ?></div>
    <?php endif; ?>

    <form method="POST" action="dangKy.php">
        <div class="form-group">
            <input type="text" name="ho_ten" placeholder="Họ và tên *"
                   value="<?= htmlspecialchars($_POST['ho_ten'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <input type="email" name="email" placeholder="Email *"
                   value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <input type="text" name="sdt" placeholder="Số điện thoại *"
                   value="<?= htmlspecialchars($_POST['sdt'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <input type="text" name="dia_chi" placeholder="Địa chỉ liên hệ (không bắt buộc)"
                   value="<?= htmlspecialchars($_POST['dia_chi'] ?? '') ?>">
        </div>

        <div class="form-group">
            <input type="password" name="mat_khau" placeholder="Mật khẩu (tối thiểu 6 ký tự) *" required>
        </div>

        <div class="form-group">
            <input type="password" name="xac_nhan_mk" placeholder="Nhập lại mật khẩu *" required>
        </div>

        <button type="submit" class="btn-register">Đăng ký</button>
    </form>

    <p class="login-text">
        Đã có tài khoản? <a href="dangNhap.php">Đăng nhập ngay</a>
    </p>
</div>
</body>
</html>