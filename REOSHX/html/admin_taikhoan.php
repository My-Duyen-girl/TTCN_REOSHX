<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền truy cập Admin
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Admin') {
    header('Location: dangNhap.php');
    exit;
}

$hoTenAdmin    = $_SESSION['HoTen'] ?? 'Quản trị viên';
$currentAdminId = (int)($_SESSION['MaTaiKhoan'] ?? 0);
$error         = '';
$success       = '';
$openForm      = false;
$editAccount   = null;

// Tham số lọc & tìm kiếm
$keyword    = trim($_GET['q'] ?? '');
$roleFilter = $_GET['vaitro'] ?? 'tatca';
$sortOrder  = $_GET['sapxep'] ?? 'moinhat';

// 1. XỬ LÝ TẠO TÀI KHOẢN MỚI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_account') {
    $openForm = true;
    $hoTen    = trim($_POST['ho_ten'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $sdt      = trim($_POST['sdt'] ?? '');
    $vaiTro   = $_POST['vai_tro'] ?? 'Khách hàng';
    $matKhau  = $_POST['mat_khau'] ?? '';
    $diaChi   = trim($_POST['dia_chi'] ?? '');

    if ($hoTen === '' || $email === '' || $sdt === '' || $matKhau === '') {
        $error = 'Vui lòng nhập đầy đủ Họ tên, Email, Số điện thoại và Mật khẩu!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Định dạng Email không hợp lệ!';
    } elseif (!preg_match('/^[0-9]{9,11}$/', $sdt)) {
        $error = 'Số điện thoại phải gồm 9 đến 11 chữ số!';
    } elseif (!in_array($vaiTro, ['Khách hàng', 'Nhân viên', 'Admin'])) {
        $error = 'Vai trò được chọn không hợp lệ!';
    } elseif (strlen($matKhau) < 6) {
        $error = 'Mật khẩu khởi tạo phải có ít nhất 6 ký tự!';
    } else {
        // Kiểm tra trùng Email hoặc SĐT
        $stmtDup = $conn->prepare("SELECT MaTaiKhoan FROM taikhoan WHERE Email = ? OR SDT = ?");
        $stmtDup->execute([$email, $sdt]);

        if ($stmtDup->rowCount() > 0) {
            $error = 'Email hoặc Số điện thoại đã tồn tại trong hệ thống!';
        } else {
            $hash = password_hash($matKhau, PASSWORD_DEFAULT);
            $stmtInsTK = $conn->prepare("INSERT INTO taikhoan (Email, SDT, MatKhau, VaiTro) VALUES (?, ?, ?, ?)");
            $stmtInsTK->execute([$email, $sdt, $hash, $vaiTro]);
            $newMaTK = (int)$conn->lastInsertId();

            // Tạo hồ sơ liên kết tương ứng với vai trò
            if ($vaiTro === 'Khách hàng') {
                $stmtInsKH = $conn->prepare("INSERT INTO khachhang (MaTaiKhoan, HoTen) VALUES (?, ?)");
                $stmtInsKH->execute([$newMaTK, $hoTen]);
            } elseif ($vaiTro === 'Nhân viên') {
                $diaChiVal = ($diaChi !== '') ? $diaChi : null;
                $stmtInsNV = $conn->prepare("INSERT INTO nhanvien (MaTaiKhoan, HoTen, DiaChi) VALUES (?, ?, ?)");
                $stmtInsNV->execute([$newMaTK, $hoTen, $diaChiVal]);
            }

            $success  = 'Đã tạo tài khoản mới cho ' . htmlspecialchars($hoTen) . ' (' . $vaiTro . ') thành công!';
            $openForm = false;
        }
    }
}

// 2. XỬ LÝ CẬP NHẬT TÀI KHOẢN & PHÂN QUYỀN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_account') {
    $maTK       = (int)$_POST['ma_tai_khoan'];
    $hoTen      = trim($_POST['ho_ten'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $sdt        = trim($_POST['sdt'] ?? '');
    $vaiTro     = $_POST['vai_tro'] ?? '';
    $matKhauMoi = $_POST['mat_khau'] ?? '';
    $diaChi     = trim($_POST['dia_chi'] ?? '');

    if ($hoTen === '' || $email === '' || $sdt === '') {
        $error = 'Vui lòng nhập đầy đủ Họ tên, Email và Số điện thoại!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Định dạng Email không hợp lệ!';
    } elseif (!preg_match('/^[0-9]{9,11}$/', $sdt)) {
        $error = 'Số điện thoại phải gồm 9 đến 11 chữ số!';
    } elseif (!in_array($vaiTro, ['Khách hàng', 'Nhân viên', 'Admin'])) {
        $error = 'Vai trò không hợp lệ!';
    } elseif ($matKhauMoi !== '' && strlen($matKhauMoi) < 6) {
        $error = 'Mật khẩu mới phải có ít nhất 6 ký tự!';
    } else {
        $stmtDup = $conn->prepare("SELECT MaTaiKhoan FROM taikhoan WHERE (Email = ? OR SDT = ?) AND MaTaiKhoan != ?");
        $stmtDup->execute([$email, $sdt, $maTK]);

        if ($stmtDup->rowCount() > 0) {
            $error = 'Email hoặc Số điện thoại đã được sử dụng bởi tài khoản khác!';
        } else {
            if ($matKhauMoi !== '') {
                $hash = password_hash($matKhauMoi, PASSWORD_DEFAULT);
                $stmtUpdTK = $conn->prepare("UPDATE taikhoan SET Email = ?, SDT = ?, VaiTro = ?, MatKhau = ? WHERE MaTaiKhoan = ?");
                $stmtUpdTK->execute([$email, $sdt, $vaiTro, $hash, $maTK]);
            } else {
                $stmtUpdTK = $conn->prepare("UPDATE taikhoan SET Email = ?, SDT = ?, VaiTro = ? WHERE MaTaiKhoan = ?");
                $stmtUpdTK->execute([$email, $sdt, $vaiTro, $maTK]);
            }

            // Đồng bộ hồ sơ bên bảng khachhang hoặc nhanvien
            if ($vaiTro === 'Khách hàng') {
                $checkKH = $conn->prepare("SELECT MaKhachHang FROM khachhang WHERE MaTaiKhoan = ?");
                $checkKH->execute([$maTK]);
                if ($checkKH->rowCount() > 0) {
                    $conn->prepare("UPDATE khachhang SET HoTen = ? WHERE MaTaiKhoan = ?")->execute([$hoTen, $maTK]);
                } else {
                    $conn->prepare("INSERT INTO khachhang (MaTaiKhoan, HoTen) VALUES (?, ?)")->execute([$maTK, $hoTen]);
                }
            } elseif ($vaiTro === 'Nhân viên') {
                $diaChiVal = ($diaChi !== '') ? $diaChi : null;
                $checkNV = $conn->prepare("SELECT MaNhanVien FROM nhanvien WHERE MaTaiKhoan = ?");
                $checkNV->execute([$maTK]);
                if ($checkNV->rowCount() > 0) {
                    $conn->prepare("UPDATE nhanvien SET HoTen = ?, DiaChi = ? WHERE MaTaiKhoan = ?")->execute([$hoTen, $diaChiVal, $maTK]);
                } else {
                    $conn->prepare("INSERT INTO nhanvien (MaTaiKhoan, HoTen, DiaChi) VALUES (?, ?, ?)")->execute([$maTK, $hoTen, $diaChiVal]);
                }
            }

            $success = 'Đã cập nhật thông tin và quyền truy cập cho tài khoản TK-' . str_pad($maTK, 4, '0', STR_PAD_LEFT) . '!';
        }
    }
}

// 3. XỬ LÝ XÓA TÀI KHOẢN
if (isset($_GET['xoa'])) {
    $maTKXoa = (int)$_GET['xoa'];
    if ($maTKXoa === $currentAdminId) {
        $error = 'Bạn không thể xóa tài khoản Admin đang đăng nhập!';
    } else {
        try {
            $conn->prepare("DELETE FROM khachhang WHERE MaTaiKhoan = ?")->execute([$maTKXoa]);
            $conn->prepare("DELETE FROM nhanvien WHERE MaTaiKhoan = ?")->execute([$maTKXoa]);
            $conn->prepare("DELETE FROM taikhoan WHERE MaTaiKhoan = ?")->execute([$maTKXoa]);
            $success = 'Đã xóa tài khoản TK-' . str_pad($maTKXoa, 4, '0', STR_PAD_LEFT) . ' khỏi hệ thống!';
        } catch (PDOException $e) {
            $error = 'Không thể xóa tài khoản này vì đã phát sinh dữ liệu thú cưng hoặc lịch hẹn liên quan!';
        }
    }
}

// 4. NẾU BẤM "SỬA" -> TẢI DỮ LIỆU TÀI KHOẢN ĐÓ LÊN FORM
if (isset($_GET['sua'])) {
    $maTKSua = (int)$_GET['sua'];
    $stmtOne = $conn->prepare("
        SELECT tk.MaTaiKhoan, tk.Email, tk.SDT, tk.VaiTro,
               COALESCE(kh.HoTen, nv.HoTen, 'Quản trị viên') AS HoTen,
               nv.DiaChi
        FROM taikhoan tk
        LEFT JOIN khachhang kh ON tk.MaTaiKhoan = kh.MaTaiKhoan
        LEFT JOIN nhanvien nv ON tk.MaTaiKhoan = nv.MaTaiKhoan
        WHERE tk.MaTaiKhoan = ?
        LIMIT 1
    ");
    $stmtOne->execute([$maTKSua]);
    $editAccount = $stmtOne->fetch();
    if ($editAccount) {
        $openForm = true;
    }
}

// 5. TRUY VẤN DANH SÁCH TÀI KHOẢN
$sql = "
    SELECT tk.MaTaiKhoan, tk.Email, tk.SDT, tk.VaiTro,
           COALESCE(kh.HoTen, nv.HoTen, 'Quản trị viên') AS HoTen
    FROM taikhoan tk
    LEFT JOIN khachhang kh ON tk.MaTaiKhoan = kh.MaTaiKhoan
    LEFT JOIN nhanvien nv ON tk.MaTaiKhoan = nv.MaTaiKhoan
    WHERE 1=1
";
$params = [];

if ($roleFilter !== 'tatca' && in_array($roleFilter, ['Khách hàng', 'Nhân viên', 'Admin'])) {
    $sql .= " AND tk.VaiTro = ?";
    $params[] = $roleFilter;
}

if ($keyword !== '') {
    $sql .= " AND (COALESCE(kh.HoTen, nv.HoTen, 'Quản trị viên') LIKE ? OR tk.Email LIKE ? OR tk.SDT LIKE ?)";
    $like = '%' . $keyword . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql .= ($sortOrder === 'cunhat') ? " ORDER BY tk.MaTaiKhoan ASC" : " ORDER BY tk.MaTaiKhoan DESC";

$stmtList = $conn->prepare($sql);
$stmtList->execute($params);
$dsTaiKhoan = $stmtList->fetchAll();

function getRoleBadgeClass($role) {
    if ($role === 'Admin') return 'role-admin';
    if ($role === 'Nhân viên') return 'role-staff';
    return 'role-customer';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý tài khoản</title>
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

        /* ================= KHUNG TÌM KIẾM & LỌC ================= */
        .filter-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .filter-card-title {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .filter-card-sub {
            font-size: 13px;
            color: #77857a;
            margin-bottom: 18px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1.6fr 1fr 1fr auto;
            gap: 14px;
            align-items: end;
        }

        .filter-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filter-field label {
            font-size: 12.5px;
            font-weight: 700;
            color: #4f5e53;
        }

        .filter-field input,
        .filter-field select {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #dce3d9;
            border-radius: 9px;
            font-size: 13.5px;
            color: #243429;
            background-color: #ffffff;
            outline: none;
            transition: border-color 0.2s;
        }

        .filter-field input:focus,
        .filter-field select:focus {
            border-color: #556e57;
        }

        .filter-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-filter {
            background-color: #eef3eb;
            color: #243429;
            border: 1px solid #d5ddd2;
            padding: 10px 16px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-filter:hover {
            background-color: #dfe7da;
        }

        .btn-create {
            background-color: #556e57;
            color: #ffffff;
            border: none;
            padding: 10px 20px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: background-color 0.2s;
        }

        .btn-create:hover {
            background-color: #445946;
        }

        /* ================= FORM THÊM / SỬA TÀI KHOẢN ================= */
        .editor-card {
            background-color: #ffffff;
            border: 1.5px solid #c7d4c3;
            border-radius: 14px;
            padding: 24px 28px;
            margin-bottom: 22px;
            box-shadow: 0 4px 16px rgba(36, 52, 41, 0.05);
            display: none;
        }

        .editor-card.open {
            display: block;
        }

        .editor-title {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .editor-sub {
            font-size: 13px;
            color: #77857a;
            margin-bottom: 18px;
        }

        .editor-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px 18px;
        }

        .editor-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .editor-group label {
            font-size: 13px;
            font-weight: 700;
            color: #36473b;
        }

        .editor-group label span {
            color: #c81e1e;
        }

        .editor-group input,
        .editor-group select {
            width: 100%;
            padding: 10px 13px;
            border: 1.5px solid #dce3d9;
            border-radius: 8px;
            font-size: 14px;
            color: #243429;
            background-color: #fcfcfa;
            outline: none;
        }

        .editor-group input:focus,
        .editor-group select:focus {
            border-color: #556e57;
            background-color: #ffffff;
        }

        .editor-footer {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid #f0ede6;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-save {
            background-color: #556e57;
            color: #ffffff;
            border: none;
            padding: 10px 22px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
        }

        .btn-save:hover {
            background-color: #445946;
        }

        .btn-cancel {
            background-color: #ffffff;
            color: #243429;
            border: 1px solid #d5ddd2;
            text-decoration: none;
            padding: 9px 18px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-cancel:hover {
            background-color: #f3f6f1;
        }

        /* ================= BẢNG DANH SÁCH TÀI KHOẢN ================= */
        .account-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 26px;
        }

        .account-header,
        .account-row {
            display: grid;
            grid-template-columns: 1.3fr 1.5fr 1fr 0.9fr 0.9fr 130px;
            align-items: center;
            gap: 12px;
            padding: 14px 22px;
            border-radius: 10px;
        }

        .account-header {
            background-color: #e9eee4;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #6b786e;
            text-transform: uppercase;
        }

        .account-row {
            background-color: #ffffff;
            border: 1px solid #f0ede6;
            font-size: 13.5px;
            color: #2c3e32;
            transition: box-shadow 0.2s;
        }

        .account-row:hover {
            box-shadow: 0 4px 14px rgba(36, 52, 41, 0.05);
        }

        .col-name {
            font-weight: 700;
            color: #243429;
        }

        .col-name small {
            display: block;
            font-size: 11.5px;
            font-weight: 500;
            color: #77857a;
            margin-top: 2px;
        }

        .role-badge {
            display: inline-block;
            font-size: 12.5px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
        }

        .role-admin {
            background-color: #e8efe3;
            color: #364f3c;
        }

        .role-staff {
            background-color: #e3ecf5;
            color: #1e40af;
        }

        .role-customer {
            background-color: #f5f3ee;
            color: #4f5e53;
        }

        .status-active-text {
            color: #2f6b3c;
            font-weight: 600;
            font-size: 13px;
        }

        .row-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .action-link {
            text-decoration: none;
            font-weight: 700;
            color: #364f3c;
        }

        .action-link:hover {
            text-decoration: underline;
        }

        .action-link.delete {
            color: #c81e1e;
        }

        .dot-sep {
            color: #b0bab2;
        }

        .empty-row {
            background-color: #ffffff;
            border: 1px dashed #d3dcd0;
            border-radius: 10px;
            padding: 32px 20px;
            text-align: center;
            color: #6b786e;
            font-size: 14px;
        }

        /* ================= KHUNG PHÂN QUYỀN HỆ THỐNG ================= */
        .permission-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 24px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .permission-info h3 {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 8px;
        }

        .permission-info p {
            font-size: 13.5px;
            color: #4f5e53;
            line-height: 1.5;
        }

        .btn-role-guide {
            background-color: #ffffff;
            color: #243429;
            border: 1px solid #d5ddd2;
            padding: 11px 22px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-role-guide:hover {
            background-color: #f3f6f1;
        }

        /* MODAL MA TRẬN PHÂN QUYỀN */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background-color: rgba(24, 34, 27, 0.45);
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 200;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal-box {
            background-color: #ffffff;
            border-radius: 14px;
            max-width: 540px;
            width: 100%;
            padding: 28px 30px;
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.18);
        }

        .modal-box h3 {
            font-size: 19px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 14px;
        }

        .role-matrix {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 20px;
        }

        .role-matrix-item {
            background-color: #faf7f2;
            border: 1px solid #eceae4;
            border-radius: 10px;
            padding: 12px 15px;
            font-size: 13.5px;
            line-height: 1.55;
            color: #36473b;
        }

        .role-matrix-item strong {
            display: block;
            color: #243429;
            margin-bottom: 2px;
        }

        @media (max-width: 1120px) {
            .filter-form {
                grid-template-columns: 1fr 1fr;
            }
            .editor-grid {
                grid-template-columns: 1fr 1fr;
            }
            .account-header {
                display: none;
            }
            .account-row {
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
            .filter-form,
            .editor-grid {
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
                <li><a href="admin_taikhoan.php" class="active">Quản lý tài khoản</a></li>
                <li><a href="admin_khachhang_thucung.php">Khách hàng & thú cưng</a></li>
                <li><a href="admin_dichvu.php">Dịch vụ & bảng giá</a></li>
                <li><a href="admin_lichhen.php">Lịch hẹn</a></li>
                <li><a href="admin_nhanvien.php">Nhân viên</a></li>
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
                <h1>Quản lý tài khoản</h1>
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
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>

        <!-- KHUNG TÌM KIẾM & LỌC -->
        <div class="filter-card">
            <div class="filter-card-title">Danh sách tài khoản</div>
            <div class="filter-card-sub">Quản lý quyền truy cập và thông tin tài khoản khách hàng, nhân viên, quản trị viên.</div>

            <form method="GET" action="admin_taikhoan.php" class="filter-form">
                <div class="filter-field">
                    <label>Tìm kiếm</label>
                    <input type="text" name="q" placeholder="Tên, email hoặc số điện thoại"
                           value="<?= htmlspecialchars($keyword) ?>">
                </div>

                <div class="filter-field">
                    <label>Vai trò</label>
                    <select name="vaitro">
                        <option value="tatca" <?= $roleFilter === 'tatca' ? 'selected' : '' ?>>Tất cả vai trò</option>
                        <option value="Khách hàng" <?= $roleFilter === 'Khách hàng' ? 'selected' : '' ?>>Khách hàng</option>
                        <option value="Nhân viên" <?= $roleFilter === 'Nhân viên' ? 'selected' : '' ?>>Nhân viên</option>
                        <option value="Admin" <?= $roleFilter === 'Admin' ? 'selected' : '' ?>>Admin</option>
                    </select>
                </div>

                <div class="filter-field">
                    <label>Sắp xếp</label>
                    <select name="sapxep">
                        <option value="moinhat" <?= $sortOrder === 'moinhat' ? 'selected' : '' ?>>Mới nhất</option>
                        <option value="cunhat" <?= $sortOrder === 'cunhat' ? 'selected' : '' ?>>Cũ nhất</option>
                    </select>
                </div>

                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Lọc</button>
                    <button type="button" class="btn-create" id="btn-toggle-create">+ Tạo tài khoản</button>
                </div>
            </form>
        </div>

        <!-- FORM TẠO MỚI HOẶC CHỈNH SỬA TÀI KHOẢN -->
        <div class="editor-card <?= $openForm ? 'open' : '' ?>" id="account-editor-card">
            <div class="editor-title">
                <?= $editAccount ? 'Chỉnh sửa tài khoản TK-' . str_pad($editAccount['MaTaiKhoan'], 4, '0', STR_PAD_LEFT) : 'Tạo tài khoản mới' ?>
            </div>
            <div class="editor-sub">
                <?= $editAccount ? 'Cập nhật thông tin cá nhân, phân quyền vai trò hoặc đặt lại mật khẩu đăng nhập.' : 'Khởi tạo tài khoản truy cập mới cho Nhân viên, Khách hàng hoặc Quản trị viên.' ?>
            </div>

            <form method="POST" action="admin_taikhoan.php">
                <input type="hidden" name="action" value="<?= $editAccount ? 'update_account' : 'create_account' ?>">
                <?php if ($editAccount): ?>
                    <input type="hidden" name="ma_tai_khoan" value="<?= $editAccount['MaTaiKhoan'] ?>">
                <?php endif; ?>

                <div class="editor-grid">
                    <div class="editor-group">
                        <label>Họ và tên <span>*</span></label>
                        <input type="text" name="ho_ten" required
                               placeholder="Ví dụ: Trần Minh An"
                               value="<?= htmlspecialchars($editAccount['HoTen'] ?? ($_POST['ho_ten'] ?? '')) ?>">
                    </div>

                    <div class="editor-group">
                        <label>Email đăng nhập <span>*</span></label>
                        <input type="email" name="email" required
                               placeholder="Ví dụ: an@petcare.vn"
                               value="<?= htmlspecialchars($editAccount['Email'] ?? ($_POST['email'] ?? '')) ?>">
                    </div>

                    <div class="editor-group">
                        <label>Số điện thoại <span>*</span></label>
                        <input type="text" name="sdt" required
                               placeholder="Ví dụ: 0901234567"
                               value="<?= htmlspecialchars($editAccount['SDT'] ?? ($_POST['sdt'] ?? '')) ?>">
                    </div>

                    <div class="editor-group">
                        <label>Vai trò hệ thống <span>*</span></label>
                        <?php $curRole = $editAccount['VaiTro'] ?? ($_POST['vai_tro'] ?? 'Nhân viên'); ?>
                        <select name="vai_tro" id="select-role-editor">
                            <option value="Khách hàng" <?= $curRole === 'Khách hàng' ? 'selected' : '' ?>>Khách hàng</option>
                            <option value="Nhân viên" <?= $curRole === 'Nhân viên' ? 'selected' : '' ?>>Nhân viên</option>
                            <option value="Admin" <?= $curRole === 'Admin' ? 'selected' : '' ?>>Admin</option>
                        </select>
                    </div>

                    <div class="editor-group">
                        <label><?= $editAccount ? 'Mật khẩu mới (để trống nếu giữ nguyên)' : 'Mật khẩu khởi tạo *' ?></label>
                        <input type="password" name="mat_khau" <?= $editAccount ? '' : 'required' ?>
                               placeholder="Tối thiểu 6 ký tự">
                    </div>

                    <div class="editor-group" id="group-dia-chi">
                        <label>Địa chỉ (dành cho Nhân viên)</label>
                        <input type="text" name="dia_chi"
                               placeholder="Ví dụ: Quận 8, TP. Hồ Chí Minh"
                               value="<?= htmlspecialchars($editAccount['DiaChi'] ?? ($_POST['dia_chi'] ?? '')) ?>">
                    </div>
                </div>

                <div class="editor-footer">
                    <button type="submit" class="btn-save">
                        <?= $editAccount ? 'Lưu cập nhật' : 'Tạo tài khoản' ?>
                    </button>
                    <a href="admin_taikhoan.php" class="btn-cancel">Hủy</a>
                </div>
            </form>
        </div>

        <!-- BẢNG DANH SÁCH TÀI KHOẢN -->
        <div class="account-list">
            <div class="account-header">
                <div>HỌ TÊN</div>
                <div>EMAIL</div>
                <div>SỐ ĐIỆN THOẠI</div>
                <div>VAI TRÒ</div>
                <div>TRẠNG THÁI</div>
                <div>THAO TÁC</div>
            </div>

            <?php if (count($dsTaiKhoan) > 0): ?>
                <?php foreach ($dsTaiKhoan as $tk): ?>
                    <?php $maTKFormat = 'TK-' . str_pad($tk['MaTaiKhoan'], 4, '0', STR_PAD_LEFT); ?>
                    <div class="account-row">
                        <div class="col-name">
                            <?= htmlspecialchars($tk['HoTen']) ?>
                            <small><?= $maTKFormat ?></small>
                        </div>

                        <div><?= htmlspecialchars($tk['Email']) ?></div>

                        <div><?= htmlspecialchars($tk['SDT']) ?></div>

                        <div>
                            <span class="role-badge <?= getRoleBadgeClass($tk['VaiTro']) ?>">
                                <?= htmlspecialchars($tk['VaiTro']) ?>
                            </span>
                        </div>

                        <div>
                            <span class="status-active-text">Hoạt động</span>
                        </div>

                        <div class="row-actions">
                            <a href="admin_taikhoan.php?sua=<?= $tk['MaTaiKhoan'] ?>#account-editor-card" class="action-link">
                                <?= $tk['VaiTro'] === 'Admin' ? 'Sửa quyền' : 'Sửa' ?>
                            </a>

                            <?php if ((int)$tk['MaTaiKhoan'] !== $currentAdminId): ?>
                                <span class="dot-sep">·</span>
                                <a href="admin_taikhoan.php?xoa=<?= $tk['MaTaiKhoan'] ?>"
                                   class="action-link delete"
                                   onclick="return confirm('Bạn chắc chắn muốn xóa tài khoản <?= htmlspecialchars($tk['Email']) ?> không?')">
                                    Xóa
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-row">
                    Không tìm thấy tài khoản nào phù hợp với điều kiện lọc.
                </div>
            <?php endif; ?>
        </div>

        <!-- KHUNG PHÂN QUYỀN HỆ THỐNG -->
        <div class="permission-card">
            <div class="permission-info">
                <h3>Phân quyền hệ thống</h3>
                <p>Admin: toàn quyền quản trị · Nhân viên: lịch hẹn, hồ sơ chăm sóc · Khách hàng: hồ sơ thú cưng, đặt lịch</p>
            </div>

            <button type="button" class="btn-role-guide" id="btn-open-role-modal">Thiết lập vai trò</button>
        </div>
    </main>
</div>

<!-- HỘP THOẠI CHI TIẾT PHÂN QUYỀN VAI TRÒ -->
<div class="modal-backdrop" id="role-modal">
    <div class="modal-box">
        <h3>Ma trận phân quyền theo vai trò</h3>
        <div class="role-matrix">
            <div class="role-matrix-item">
                <strong>Admin (Quản trị viên)</strong>
                Toàn quyền quản lý tài khoản người dùng, khách hàng, thú cưng, danh mục dịch vụ & bảng giá, điều phối lịch hẹn, nhân sự và xem báo cáo doanh thu.
            </div>
            <div class="role-matrix-item">
                <strong>Nhân viên (Chuyên viên chăm sóc)</strong>
                Xem ca làm việc, xác nhận và cập nhật tiến độ lịch hẹn (Chờ xác nhận → Đã xác nhận → Đang thực hiện → Hoàn thành), cập nhật ghi chú sức khỏe thú cưng.
            </div>
            <div class="role-matrix-item">
                <strong>Khách hàng</strong>
                Quản lý hồ sơ thú cưng cá nhân, đặt lịch dịch vụ trực tuyến, theo dõi trạng thái hoặc hủy lịch hẹn trước giờ thực hiện.
            </div>
        </div>
        <button type="button" class="btn-save" id="btn-close-role-modal" style="width: 100%;">Đóng</button>
    </div>
</div>

<script>
    const editorCard = document.getElementById('account-editor-card');
    const btnToggleCreate = document.getElementById('btn-toggle-create');

    if (btnToggleCreate) {
        btnToggleCreate.addEventListener('click', () => {
            editorCard.classList.toggle('open');
            if (editorCard.classList.contains('open')) {
                editorCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    }

    const roleModal = document.getElementById('role-modal');
    const btnOpenModal = document.getElementById('btn-open-role-modal');
    const btnCloseModal = document.getElementById('btn-close-role-modal');

    btnOpenModal.addEventListener('click', () => roleModal.classList.add('open'));
    btnCloseModal.addEventListener('click', () => roleModal.classList.remove('open'));
    roleModal.addEventListener('click', (e) => {
        if (e.target === roleModal) roleModal.classList.remove('open');
    });
</script>

</body>
</html>