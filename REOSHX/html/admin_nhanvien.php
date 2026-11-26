<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền truy cập Admin
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Admin') {
    header('Location: dangNhap.php');
    exit;
}

$hoTenAdmin  = $_SESSION['HoTen'] ?? 'Quản trị viên';
$error       = '';
$success     = '';
$openAddForm = false;
$detailStaff = null;

// Đảm bảo bảng nhanvien có đủ 3 cột nghiệp vụ: ChuyenMon, CaLam, TrangThai
$extraCols = [
    'ChuyenMon' => "ALTER TABLE nhanvien ADD COLUMN ChuyenMon VARCHAR(150) NOT NULL DEFAULT 'Chăm sóc tổng hợp'",
    'CaLam'     => "ALTER TABLE nhanvien ADD COLUMN CaLam VARCHAR(50) NOT NULL DEFAULT 'Cả ngày'",
    'TrangThai' => "ALTER TABLE nhanvien ADD COLUMN TrangThai VARCHAR(50) NOT NULL DEFAULT 'Đang làm'"
];
foreach ($extraCols as $colName => $alterSql) {
    $chk = $conn->query("SHOW COLUMNS FROM nhanvien LIKE '{$colName}'");
    if ($chk && $chk->rowCount() === 0) {
        $conn->exec($alterSql);
    }
}

// Tạo bảng lịch làm việc chi tiết nếu chưa tồn tại
$conn->exec("
    CREATE TABLE IF NOT EXISTS lichlamviec (
        MaLichLam INT AUTO_INCREMENT PRIMARY KEY,
        MaNhanVien INT NOT NULL,
        NgayLam DATE NOT NULL,
        CaLam VARCHAR(50) NOT NULL,
        GhiChu VARCHAR(255) NULL
    )
");

// 1. XỬ LÝ THÊM NHÂN VIÊN MỚI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_staff') {
    $openAddForm = true;
    $hoTen       = trim($_POST['ho_ten'] ?? '');
    $email       = trim($_POST['email'] ?? '');
    $sdt         = trim($_POST['sdt'] ?? '');
    $matKhau     = $_POST['mat_khau'] ?? '';
    $chuyenMon   = trim($_POST['chuyen_mon'] ?? 'Tắm & sấy');
    $caLam       = trim($_POST['ca_lam'] ?? 'Sáng');
    $diaChi      = trim($_POST['dia_chi'] ?? '');

    if ($hoTen === '' || $email === '' || $sdt === '' || $matKhau === '') {
        $error = 'Vui lòng nhập đầy đủ Họ tên, Email, Số điện thoại và Mật khẩu!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Định dạng Email không hợp lệ!';
    } elseif (!preg_match('/^[0-9]{9,11}$/', $sdt)) {
        $error = 'Số điện thoại phải gồm 9 đến 11 chữ số!';
    } elseif (strlen($matKhau) < 6) {
        $error = 'Mật khẩu khởi tạo phải có ít nhất 6 ký tự!';
    } else {
        $stmtDup = $conn->prepare("SELECT MaTaiKhoan FROM taikhoan WHERE Email = ? OR SDT = ?");
        $stmtDup->execute([$email, $sdt]);

        if ($stmtDup->rowCount() > 0) {
            $error = 'Email hoặc Số điện thoại này đã tồn tại trong hệ thống!';
        } else {
            $hash = password_hash($matKhau, PASSWORD_DEFAULT);
            $stmtTK = $conn->prepare("INSERT INTO taikhoan (Email, SDT, MatKhau, VaiTro) VALUES (?, ?, ?, 'Nhân viên')");
            $stmtTK->execute([$email, $sdt, $hash]);
            $newMaTK = (int)$conn->lastInsertId();

            $diaChiVal = ($diaChi !== '') ? $diaChi : null;
            $stmtNV = $conn->prepare("
                INSERT INTO nhanvien (MaTaiKhoan, HoTen, DiaChi, ChuyenMon, CaLam, TrangThai)
                VALUES (?, ?, ?, ?, ?, 'Đang làm')
            ");
            $stmtNV->execute([$newMaTK, $hoTen, $diaChiVal, $chuyenMon, $caLam]);

            $success     = 'Đã thêm nhân viên ' . htmlspecialchars($hoTen) . ' vào hệ thống thành công!';
            $openAddForm = false;
        }
    }
}

// 2. XỬ LÝ CẬP NHẬT HỒ SƠ CHI TIẾT NHÂN VIÊN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_staff') {
    $maNV      = (int)$_POST['ma_nhan_vien'];
    $maTK      = (int)$_POST['ma_tai_khoan'];
    $hoTen     = trim($_POST['ho_ten'] ?? '');
    $sdt       = trim($_POST['sdt'] ?? '');
    $diaChi    = trim($_POST['dia_chi'] ?? '');
    $chuyenMon = trim($_POST['chuyen_mon'] ?? '');
    $caLam     = trim($_POST['ca_lam'] ?? '');
    $trangThai = trim($_POST['trang_thai'] ?? 'Đang làm');

    if ($hoTen === '' || $sdt === '' || $chuyenMon === '') {
        $error = 'Họ tên, Số điện thoại và Chuyên môn không được để trống!';
    } elseif (!preg_match('/^[0-9]{9,11}$/', $sdt)) {
        $error = 'Số điện thoại phải gồm 9 đến 11 chữ số!';
    } else {
        $stmtDup = $conn->prepare("SELECT MaTaiKhoan FROM taikhoan WHERE SDT = ? AND MaTaiKhoan != ?");
        $stmtDup->execute([$sdt, $maTK]);
        if ($stmtDup->rowCount() > 0) {
            $error = 'Số điện thoại này đã được tài khoản khác sử dụng!';
        } else {
            $conn->prepare("UPDATE taikhoan SET SDT = ? WHERE MaTaiKhoan = ?")->execute([$sdt, $maTK]);

            $diaChiVal = ($diaChi !== '') ? $diaChi : null;
            $stmtUpd = $conn->prepare("
                UPDATE nhanvien
                SET HoTen = ?, DiaChi = ?, ChuyenMon = ?, CaLam = ?, TrangThai = ?
                WHERE MaNhanVien = ?
            ");
            $stmtUpd->execute([$hoTen, $diaChiVal, $chuyenMon, $caLam, $trangThai, $maNV]);

            $success = 'Đã cập nhật hồ sơ nhân viên NV' . str_pad($maNV, 3, '0', STR_PAD_LEFT) . '!';
        }
    }
}

// 3. XỬ LÝ THIẾT LẬP LỊCH LÀM VIỆC
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_schedule') {
    $maNV    = (int)($_POST['ma_nhan_vien'] ?? 0);
    $ngayLam = trim($_POST['ngay_lam'] ?? '');
    $caLam   = trim($_POST['ca_lam'] ?? '');
    $ghiChu  = trim($_POST['ghi_chu'] ?? '');

    if ($maNV <= 0 ||$ngayLam === '' || $caLam === '') {$error = 'Vui lòng chọn đầy đủ Nhân viên, Ngày làm và Ca làm!';
    } else {
        $ghiChuVal = ($ghiChu !== '') ?$ghiChu : null;
        $stmtInsShift =$conn->prepare("
            INSERT INTO lichlamviec (MaNhanVien, NgayLam, CaLam, GhiChu)
            VALUES (?, ?, ?, ?)
        ");
        $stmtInsShift->execute([$maNV,$ngayLam, $caLam,$ghiChuVal]);
        $success = 'Đã lưu lịch làm việc ngày ' . date('d/m/Y', strtotime($ngayLam)) . ' cho nhân viên NV' . str_pad($maNV, 3, '0', STR_PAD_LEFT) . '!';
    }
}

// 4. XÓA CA LÀM ĐÃ THIẾT LẬP
if (isset($_GET['xoa_ca'])) {$maLichLam = (int)$_GET['xoa_ca'];$conn->prepare("DELETE FROM lichlamviec WHERE MaLichLam = ?")->execute([$maLichLam]);$success = 'Đã xóa lịch phân ca!';
}

// 5. LẤY DANH SÁCH NHÂN VIÊN KÈM SỐ LỊCH HẸN ĐÃ NHẬN
$stmtStaff =$conn->query("
    SELECT nv.MaNhanVien, nv.MaTaiKhoan, nv.HoTen, nv.DiaChi,
           nv.ChuyenMon, nv.CaLam, nv.TrangThai,
           tk.Email, tk.SDT,
           COUNT(CASE WHEN lh.TrangThai != 'Đã hủy' THEN lh.MaLichHen END) AS SoLichNhan
    FROM nhanvien nv
    JOIN taikhoan tk ON nv.MaTaiKhoan = tk.MaTaiKhoan
    LEFT JOIN lichhen lh ON nv.MaNhanVien = lh.MaNhanVien
    GROUP BY nv.MaNhanVien, nv.MaTaiKhoan, nv.HoTen, nv.DiaChi,
             nv.ChuyenMon, nv.CaLam, nv.TrangThai, tk.Email, tk.SDT
    ORDER BY nv.MaNhanVien ASC
");
$dsNhanVien =$stmtStaff->fetchAll();

// 6. NẾU BẤM "CHI TIẾT" -> TẢI HỒ SƠ NHÂN VIÊN ĐÓ
$staffShifts = [];
if (isset($_GET['chitiet'])) {
    $maNVDetail = (int)$_GET['chitiet'];
    foreach ($dsNhanVien as$item) {
        if ((int)$item['MaNhanVien'] ===$maNVDetail) {
            $detailStaff =$item;
            break;
        }
    }
    if ($detailStaff) {
        $stmtShifts =$conn->prepare("
            SELECT * FROM lichlamviec
            WHERE MaNhanVien = ?
            ORDER BY NgayLam DESC, MaLichLam DESC
        ");
        $stmtShifts->execute([$maNVDetail]);
        $staffShifts =$stmtShifts->fetchAll();
    }
}

// 7. LẤY 6 LỊCH LÀM VIỆC MỚI THIẾT LẬP GẦN ĐÂY
$dsLichLamGanDay =$conn->query("
    SELECT llv.*, nv.HoTen
    FROM lichlamviec llv
    JOIN nhanvien nv ON llv.MaNhanVien = nv.MaNhanVien
    ORDER BY llv.NgayLam DESC, llv.MaLichLam DESC
    LIMIT 6
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý nhân viên</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background-color: #faf7f2;
            color: #243429;
            min-height: 100vh;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* ================= SIDEBAR TRÁI ================= */
        .sidebar {
            width: 250px;
            background-color: #283b2e;
            color: #ffffff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            padding: 28px 18px;
            flex-shrink: 0;
        }

        .sidebar-brand {
            padding: 0 10px 22px 10px;
        }

        .sidebar-brand .brand-title {
            font-size: 24px;
            font-weight: 800;
            color: #ffffff;
            line-height: 1.1;
        }

        .sidebar-brand .brand-sub {
            font-size: 9.5px;
            letter-spacing: 1px;
            color: #b7c4ba;
            font-weight: 600;
            margin-top: 3px;
        }

        .role-label {
            text-align: center;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 1.2px;
            color: #dbe4dc;
            margin: 4px 0 16px 0;
        }

        .sidebar-menu {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 5px;
        }

        .sidebar-menu a {
            display: block;
            text-decoration: none;
            color: #d5dfd7;
            font-size: 14px;
            font-weight: 500;
            padding: 10px 16px;
            border-radius: 8px;
            transition: background-color 0.2s, color 0.2s;
        }

        .sidebar-menu a:hover {
            background-color: rgba(255, 255, 255, 0.08);
            color: #ffffff;
        }

        .sidebar-menu a.active {
            background-color: #4c6451;
            color: #ffffff;
            font-weight: 600;
        }

        .sidebar-bottom {
            border-top: 1px solid rgba(255, 255, 255, 0.12);
            padding-top: 16px;
            margin-top: 20px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sidebar-bottom a {
            text-decoration: none;
            color: #c5d1c8;
            font-size: 13.5px;
            padding: 9px 16px;
            border-radius: 8px;
            transition: background-color 0.2s;
        }

        .sidebar-bottom a:hover {
            background-color: rgba(255, 255, 255, 0.08);
            color: #ffffff;
        }

        .sidebar-bottom a.logout-link {
            color: #f5a3a3;
        }

        /* ================= NỘI DUNG CHÍNH ================= */
        .main-content {
            flex: 1;
            padding: 36px 44px;
            max-width: 1260px;
        }

        .top-bar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 20px;
            margin-bottom: 24px;
            border-bottom: 1px solid #eceae4;
            flex-wrap: wrap;
        }

        .top-bar h1 {
            font-size: 28px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .top-bar p {
            font-size: 14px;
            color: #77857a;
        }

        .user-pill {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 10px;
            padding: 10px 18px;
            display: inline-flex;
            align-items: center;
            gap: 9px;
            font-size: 13.5px;
            font-weight: 600;
            color: #36473b;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
        }

        .status-dot {
            width: 8px;
            height: 8px;
            background-color: #556e57;
            border-radius: 50%;
            display: inline-block;
        }

        /* THÔNG BÁO */
        .alert-error {
            background-color: #fde8e8;
            color: #c81e1e;
            border: 1px solid #f8b4b4;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 18px;
        }

        .alert-success {
            background-color: #def7ec;
            color: #03543f;
            border: 1px solid #84e1bc;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 18px;
        }

        /* ================= THẺ TIÊU ĐỀ DANH SÁCH NHÂN VIÊN ================= */
        .header-banner-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .header-banner-card h2 {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .header-banner-card p {
            font-size: 13px;
            color: #77857a;
        }

        .btn-primary {
            background-color: #556e57;
            color: #ffffff;
            border: none;
            padding: 11px 22px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-primary:hover {
            background-color: #445946;
        }

        .btn-secondary {
            background-color: #ffffff;
            color: #243429;
            border: 1px solid #d5ddd2;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-block;
        }

        .btn-secondary:hover {
            background-color: #f3f6f1;
        }

        /* ================= FORM THÊM / CHI TIẾT CẬP NHẬT NHÂN VIÊN ================= */
        .panel-form-card {
            background-color: #ffffff;
            border: 1.5px solid #c7d4c3;
            border-radius: 14px;
            padding: 24px 28px;
            margin-bottom: 20px;
            box-shadow: 0 4px 16px rgba(36, 52, 41, 0.05);
            display: none;
        }

        .panel-form-card.open {
            display: block;
        }

        .panel-form-title {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .panel-form-sub {
            font-size: 13px;
            color: #77857a;
            margin-bottom: 18px;
        }

        .form-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 12.5px;
            font-weight: 700;
            color: #36473b;
        }

        .form-group label span {
            color: #c81e1e;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid #dce3d9;
            border-radius: 8px;
            font-size: 13.5px;
            color: #243429;
            background-color: #fcfcfa;
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #556e57;
            background-color: #ffffff;
        }

        .form-actions {
            margin-top: 18px;
            padding-top: 15px;
            border-top: 1px solid #f0ede6;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* Danh sách ca làm riêng trong khung Chi tiết nhân viên */
        .staff-shifts-box {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px dashed #dce3d9;
        }

        .staff-shifts-box h4 {
            font-size: 14px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 10px;
        }

        .shift-chip-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .shift-chip {
            background-color: #f3f6f1;
            border: 1px solid #dce3d9;
            border-radius: 8px;
            padding: 7px 12px;
            font-size: 12.5px;
            color: #2c3e32;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .shift-chip a {
            color: #c81e1e;
            text-decoration: none;
            font-weight: 700;
        }

        /* ================= BẢNG DANH SÁCH NHÂN VIÊN ================= */
        .staff-table {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 26px;
        }

        .staff-header,
        .staff-row {
            display: grid;
            grid-template-columns: 95px 1.25fr 1.25fr 0.9fr 1fr 0.95fr 90px;
            align-items: center;
            gap: 12px;
            padding: 14px 22px;
            border-radius: 10px;
        }

        .staff-header {
            background-color: #e9eee4;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #6b786e;
            text-transform: uppercase;
        }

        .staff-row {
            background-color: #ffffff;
            border: 1px solid #f0ede6;
            font-size: 13.5px;
            color: #2c3e32;
            transition: box-shadow 0.2s;
        }

        .staff-row:hover {
            box-shadow: 0 4px 14px rgba(36, 52, 41, 0.05);
        }

        .col-code {
            font-weight: 800;
            color: #243429;
        }

        .status-working {
            color: #2f6b3c;
            font-weight: 600;
        }

        .status-leave {
            color: #a16207;
            font-weight: 600;
        }

        .link-detail {
            text-decoration: none;
            font-weight: 700;
            color: #364f3c;
        }

        .link-detail:hover {
            text-decoration: underline;
        }

        /* ================= KHUNG THIẾT LẬP LỊCH LÀM VIỆC ================= */
        .schedule-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 24px 28px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .schedule-card h3 {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 18px;
        }

        .schedule-form-grid {
            display: grid;
            grid-template-columns: 1.1fr 1fr 1fr 1.3fr;
            gap: 14px;
            margin-bottom: 16px;
        }

        .schedule-footer {
            display: flex;
            justify-content: flex-end;
        }

        .recent-schedules {
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #f0ede6;
        }

        .recent-schedules h4 {
            font-size: 13.5px;
            font-weight: 700;
            color: #4f5e53;
            margin-bottom: 10px;
        }

        .schedule-mini-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .schedule-mini-item {
            background-color: #faf7f2;
            border: 1px solid #eceae4;
            border-radius: 8px;
            padding: 10px 14px;
            font-size: 13px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-del-shift {
            color: #c81e1e;
            text-decoration: none;
            font-weight: 600;
            font-size: 12.5px;
        }

        .btn-del-shift:hover {
            text-decoration: underline;
        }

        @media (max-width: 1120px) {
            .form-grid-3,
            .schedule-form-grid {
                grid-template-columns: 1fr 1fr;
            }
            .staff-header {
                display: none;
            }
            .staff-row {
                grid-template-columns: 1fr 1fr;
                gap: 8px;
            }
        }

        @media (max-width: 780px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .form-grid-3,
            .schedule-form-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<div class="layout">
    <!-- SIDEBAR TRÁI -->
    <aside class="sidebar">
        <div>
            <div class="sidebar-brand">
                <div class="brand-title">Pet care</div>
                <div class="brand-sub">CHĂM SÓC BÉ CƯNG</div>
            </div>

            <div class="role-label">ADMIN</div>

            <ul class="sidebar-menu">
                <li><a href="admin_tongquan.php">Tổng quan</a></li>
                <li><a href="admin_taikhoan.php">Quản lý tài khoản</a></li>
                <li><a href="admin_khachhang_thucung.php">Khách hàng & thú cưng</a></li>
                <li><a href="admin_dichvu.php">Dịch vụ & bảng giá</a></li>
                <li><a href="admin_lichhen.php">Lịch hẹn</a></li>
                <li><a href="admin_nhanvien.php" class="active">Nhân viên</a></li>
                <li><a href="admin_baocao.php">Báo cáo thống kê</a></li>
            </ul>
        </div>

        <div class="sidebar-bottom">
            <a href="trangChu.php">Trang chủ</a>
            <a href="dangXuat.php" class="logout-link">Đăng xuất</a>
        </div>
    </aside>

    <!-- NỘI DUNG CHÍNH -->
    <main class="main-content">
        <div class="top-bar">
            <div>
                <h1>Quản lý nhân viên</h1>
                <p>Xin chào! Đây là khu vực admin của bạn.</p>
            </div>

            <div class="user-pill">
                <span class="status-dot"></span>
                <span>Xin chào, <?= htmlspecialchars($hoTenAdmin) ?></span>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- THẺ TIÊU ĐỀ DANH SÁCH NHÂN VIÊN -->
        <div class="header-banner-card">
            <div>
                <h2>Danh sách nhân viên</h2>
                <p>Quản lý hồ sơ nhân viên, chuyên môn, lịch làm việc và tình trạng hoạt động.</p>
            </div>

            <button type="button" class="btn-primary" id="btn-toggle-add">+ Thêm nhân viên</button>
        </div>

        <!-- FORM THÊM NHÂN VIÊN MỚI -->
        <div class="panel-form-card <?= $openAddForm ? 'open' : '' ?>" id="add-staff-card">
            <div class="panel-form-title">Thêm nhân viên mới</div>
            <div class="panel-form-sub">Tạo tài khoản đăng nhập và phân công chuyên môn cho nhân viên mới.</div>

            <form method="POST" action="admin_nhanvien.php">
                <input type="hidden" name="action" value="add_staff">

                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Họ và tên <span>*</span></label>
                        <input type="text" name="ho_ten" required placeholder="Ví dụ: Nguyễn An"
                               value="<?= htmlspecialchars($_POST['ho_ten'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Email đăng nhập <span>*</span></label>
                        <input type="email" name="email" required placeholder="Ví dụ: nv.an@petcare.vn"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Số điện thoại <span>*</span></label>
                        <input type="text" name="sdt" required placeholder="Ví dụ: 0901234567"
                               value="<?= htmlspecialchars($_POST['sdt'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Chuyên môn chính <span>*</span></label>
                        <select name="chuyen_mon">
                            <option value="Tắm & sấy">Tắm & sấy</option>
                            <option value="Vệ sinh, spa">Vệ sinh, spa</option>
                            <option value="Cắt tỉa lông">Cắt tỉa lông</option>
                            <option value="Chăm sóc móng">Chăm sóc móng</option>
                            <option value="Chăm sóc toàn diện">Chăm sóc toàn diện</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Ca làm mặc định <span>*</span></label>
                        <select name="ca_lam">
                            <option value="Sáng">Sáng (08:00–12:00)</option>