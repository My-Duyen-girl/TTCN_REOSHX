<?php
session_start();
require_once '../db.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_id = trim($_POST['login_id'] ?? '');
    $mat_khau = $_POST['mat_khau'] ?? '';

    if (empty($login_id) || empty($mat_khau)) {
        $error = 'Vui lòng nhập đầy đủ Email (hoặc SĐT) và Mật khẩu!';
    } else {
        // Tìm tài khoản theo Email hoặc Số điện thoại
        $stmt = $conn->prepare("SELECT * FROM TaiKhoan WHERE Email = ? OR SDT = ? LIMIT 1");
        $stmt->execute([$login_id, $login_id]);
        $user = $stmt->fetch();

        if ($user && password_verify($mat_khau, $user['MatKhau'])) {
            $_SESSION['MaTaiKhoan'] = $user['MaTaiKhoan'];
            $_SESSION['Email']      = $user['Email'];
            $_SESSION['VaiTro']     = $user['VaiTro'];

            // Điều hướng theo từng vai trò
            if ($user['VaiTro'] === 'Admin') {
                $_SESSION['HoTen'] = 'Quản trị viên';
                header('Location: admin_tongquan.php');
                exit;
            } elseif ($user['VaiTro'] === 'Nhân viên') {
                $stmtNV = $conn->prepare("SELECT MaNhanVien, HoTen FROM NhanVien WHERE MaTaiKhoan = ?");
                $stmtNV->execute([$user['MaTaiKhoan']]);
                $nv = $stmtNV->fetch();

                $_SESSION['MaNhanVien'] = $nv['MaNhanVien'] ?? null;
                $_SESSION['HoTen']      = $nv['HoTen'] ?? 'Nhân viên';
                header('Location: nhanvien_tongquan.php');
                exit;
            } else {
                $stmtKH = $conn->prepare("SELECT MaKhachHang, HoTen FROM KhachHang WHERE MaTaiKhoan = ?");
                $stmtKH->execute([$user['MaTaiKhoan']]);
                $kh = $stmtKH->fetch();

                $_SESSION['MaKhachHang'] = $kh['MaKhachHang'] ?? null;
                $_SESSION['HoTen']       = $kh['HoTen'] ?? 'Khách hàng';
                header('Location: KH_tongquan.php');
                exit;
            }
        } else {
            $error = 'Email/Số điện thoại hoặc mật khẩu không chính xác!';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        body {
            background-color: #6b7462; /* Tông xanh rêu dịu giống khung nền mẫu */
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-card {
            background-color: #ffffff;
            width: 100%;
            max-width: 430px;
            padding: 48px 42px;
            border-radius: 6px;
            box-shadow: 0 12px 30px rgba(0, 0, 0, 0.18);
            text-align: center;
        }
        .brand-logo {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-bottom: 24px;
        }
        .brand-logo svg {
            width: 42px;
            height: 42px;
            fill: #98a190;
        }
        .brand-logo span {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 1.5px;
            color: #98a190;
        }
        h1 {
            font-size: 26px;
            font-weight: 700;
            color: #1e1e1e;
            margin-bottom: 8px;
        }
        .subtitle {
            font-size: 13.5px;
            color: #888888;
            margin-bottom: 32px;
        }
        .error-box {
            background-color: #fde8e8;
            color: #c81e1e;
            border: 1px solid #f8b4b4;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 13.5px;
            margin-bottom: 20px;
            text-align: left;
        }
        .form-group {
            margin-bottom: 24px;
            text-align: left;
        }
        .form-group input {
            width: 100%;
            border: none;
            border-bottom: 1.5px solid #cccccc;
            padding: 10px 2px;
            font-size: 15px;
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
        .options-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 28px;
            font-size: 13px;
            color: #777777;
        }
        .remember-label {
            display: flex;
            align-items: center;
            gap: 7px;
            cursor: pointer;
        }
        .remember-label input[type="checkbox"] {
            accent-color: #1e1e1e;
            width: 15px;
            height: 15px;
            cursor: pointer;
        }
        .btn-login {
            width: 100%;
            background-color: #1e1e1e;
            color: #ffffff;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.25s, transform 0.1s;
        }
        .btn-login:hover {
            background-color: #333333;
        }
        .btn-login:active {
            transform: scale(0.99);
        }
        .register-text {
            margin-top: 24px;
            font-size: 13.5px;
            color: #888888;
        }
        .register-text a {
            color: #1e1e1e;
            font-weight: 600;
            text-decoration: none;
        }
        .register-text a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
  <div class="login-card">
    <!-- Logo dấu chân PETCARE -->
    <div class="brand-logo">
        <svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
            <path d="M256 224c-79.41 0-192 122.76-192 200.25 0 34.9 26.81 55.75 71.74 55.75 48.84 0 81.09-25.08 120.26-25.08 39.51 0 71.85 25.08 120.26 25.08 44.93 0 71.74-20.85 71.74-55.75C448 346.76 335.41 224 256 224zm-147.28-12.61c-10.4-34.65-42.44-57.09-71.56-50.13-29.12 6.96-44.29 40.69-33.89 75.34 10.4 34.65 42.44 57.09 71.56 50.13 29.12-6.96 44.29-40.69 33.89-75.34zm84.72-20.78c30.94-8.14 46.42-49.94 34.58-93.36s-46.52-72.01-77.46-63.87-46.42 49.94-34.58 93.36c11.84 43.42 46.53 72.02 77.46 63.87zm281.39-29.34c-29.12-6.96-61.15 15.48-71.56 50.13-10.4 34.65 4.77 68.38 33.89 75.34 29.12 6.96 61.15-15.48 71.56-50.13 10.4-34.65-4.77-68.38-33.89-75.34zm-156.27 29.34c30.94 8.14 65.62-20.45 77.46-63.87 11.84-43.42-3.64-85.21-34.58-93.36s-65.62 20.45-77.46 63.87c-11.84 43.42 3.64 85.22 34.58 93.36z"/>
        </svg>
        <span>PETCARE</span>
    </div>

    <h1>Chào mừng trở lại!</h1>
    <p class="subtitle">Vui lòng nhập thông tin tài khoản của bạn</p>

    <?php if (!empty($error)): ?>
        <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" action="dangNhap.php">
        <div class="form-group">
            <input type="text" name="login_id" placeholder="Email hoặc Số điện thoại"
                   value="<?= htmlspecialchars($_POST['login_id'] ?? '') ?>" required>
        </div>

        <div class="form-group">
            <input type="password" name="mat_khau" placeholder="Mật khẩu" required>
        </div>

        <div class="options-row">
            <label class="remember-label">
                <input type="checkbox" name="remember"> Ghi nhớ đăng nhập
            </label>
        </div>

        <button type="submit" class="btn-login">Đăng nhập</button>
    </form>

    <p class="register-text">
        Chưa có tài khoản? <a href="dangKy.php">Đăng ký </a>
    </p>
</div>  
</body>
</html>