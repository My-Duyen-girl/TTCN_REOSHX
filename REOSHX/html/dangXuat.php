<?php
session_start(); 
// Đổi 'Nhân viên' thành 'Admin' hoặc 'Khách hàng' tùy theo trang của vai trò đó
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Nhân viên') {
    header('Location: dangNhap.php');
    exit;
}
// Lưu tạm tên người dùng để hiển thị lời chào tạm biệt trước khi xóa session
$hoTen = $_SESSION['HoTen'] ?? '';

// Xóa toàn bộ biến session
$_SESSION = [];

// Hủy cookie session trên trình duyệt (nếu có)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Chỉ hủy session khi phiên đang hoạt động để tránh báo lỗi
if (session_status() === PHP_SESSION_ACTIVE) {
    session_destroy();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng xuất</title>
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
            padding: 20px;
        }

        .logout-card {
            background-color: #ffffff;
            width: 100%;
            max-width: 440px;
            padding: 46px 40px;
            border-radius: 12px;
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

        .status-icon {
            width: 68px;
            height: 68px;
            background-color: #e8efe3;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px auto;
        }

        .status-icon svg {
            width: 32px;
            height: 32px;
            stroke: #556e57;
        }

        h1 {
            font-size: 24px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 10px;
        }

        .subtitle {
            font-size: 14px;
            color: #6b786e;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .action-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn-primary {
            display: block;
            width: 100%;
            background-color: #556e57;
            color: #ffffff;
            text-decoration: none;
            padding: 13px;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            transition: background-color 0.2s, transform 0.1s;
        }

        .btn-primary:hover {
            background-color: #445946;
        }

        .btn-primary:active {
            transform: scale(0.99);
        }

        .btn-outline {
            display: block;
            width: 100%;
            background-color: #faf7f2;
            color: #243429;
            border: 1.5px solid #dce3d9;
            text-decoration: none;
            padding: 12px;
            border-radius: 8px;
            font-size: 14.5px;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .btn-outline:hover {
            background-color: #eef3eb;
        } 
    </style>
</head>
<body>
   <div class="logout-card">
    <!-- Logo thương hiệu -->
    <div class="brand-logo">
        <svg viewBox="0 0 512 512" xmlns="http://www.w3.org/2000/svg">
            <path d="M256 224c-79.41 0-192 122.76-192 200.25 0 34.9 26.81 55.75 71.74 55.75 48.84 0 81.09-25.08 120.26-25.08 39.51 0 71.85 25.08 120.26 25.08 44.93 0 71.74-20.85 71.74-55.75C448 346.76 335.41 224 256 224zm-147.28-12.61c-10.4-34.65-42.44-57.09-71.56-50.13-29.12 6.96-44.29 40.69-33.89 75.34 10.4 34.65 42.44 57.09 71.56 50.13 29.12-6.96 44.29-40.69 33.89-75.34zm84.72-20.78c30.94-8.14 46.42-49.94 34.58-93.36s-46.52-72.01-77.46-63.87-46.42 49.94-34.58 93.36c11.84 43.42 46.53 72.02 77.46 63.87zm281.39-29.34c-29.12-6.96-61.15 15.48-71.56 50.13-10.4 34.65 4.77 68.38 33.89 75.34 29.12 6.96 61.15-15.48 71.56-50.13 10.4-34.65-4.77-68.38-33.89-75.34zm-156.27 29.34c30.94 8.14 65.62-20.45 77.46-63.87 11.84-43.42-3.64-85.21-34.58-93.36s-65.62 20.45-77.46 63.87c-11.84 43.42 3.64 85.22 34.58 93.36z"/>
        </svg>
        <span>PETCARE</span>
    </div>

    <!-- Biểu tượng xác nhận đăng xuất -->
    <div class="status-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
        </svg>
    </div>

    <h1><?= !empty($hoTen) ? 'Hẹn gặp lại, ' . htmlspecialchars($hoTen) . '!' : 'Đăng xuất thành công!' ?></h1>
    <p class="subtitle">
        Phiên làm việc của bạn đã được đóng an toàn.<br>
        Cảm ơn bạn đã tin tưởng và đồng hành cùng Pet Care & Spa.
    </p>

    <div class="action-group">
        <a href="dangNhap.php" class="btn-primary">Đăng nhập lại</a>
        <a href="trangChu.php" class="btn-outline">Về trang chủ</a>
    </div>
</div> 
</body>
</html>