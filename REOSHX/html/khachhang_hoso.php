<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền truy cập Khách hàng
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Khách hàng' || empty($_SESSION['MaKhachHang'])) {
    header('Location: dangNhap.php');
    exit;
}

$maKH = (int)$_SESSION['MaKhachHang'];
$error = '';
$success = '';
$openEditForm = false;

// 1. LẤY THÔNG TIN KHÁCH HÀNG & TÀI KHOẢN HIỆN TẠI
$stmtGet = $conn->prepare("
    SELECT kh.MaKhachHang, kh.HoTen, tk.MaTaiKhoan, tk.Email, tk.SDT, tk.VaiTro
    FROM KhachHang kh
    JOIN TaiKhoan tk ON kh.MaTaiKhoan = tk.MaTaiKhoan
    WHERE kh.MaKhachHang = ?
    LIMIT 1
");
$stmtGet->execute([$maKH]);
$profile = $stmtGet->fetch();

if (!$profile) {
    header('Location: dangXuat.php');
    exit;
}

$maTK = (int)$profile['MaTaiKhoan'];

// 2. XỬ LÝ CẬP NHẬT THÔNG TIN CÁ NHÂN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cap_nhat_hoso') {
    $openEditForm = true;
    $hoTen        = trim($_POST['ho_ten'] ?? '');
    $email        = trim($_POST['email'] ?? '');
    $sdt          = trim($_POST['sdt'] ?? '');
    $matKhauMoi   = $_POST['mat_khau_moi'] ?? '';
    $xacNhanMK    = $_POST['xac_nhan_mk'] ?? '';

    if ($hoTen === '' || $email === '' || $sdt === '') {
        $error = 'Vui lòng nhập đầy đủ Họ tên, Email và Số điện thoại!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Định dạng Email không hợp lệ!';
    } elseif (!preg_match('/^[0-9]{9,11}$/', $sdt)) {
        $error = 'Số điện thoại phải gồm 9 đến 11 chữ số!';
    } elseif ($matKhauMoi !== '' && strlen($matKhauMoi) < 6) {
        $error = 'Mật khẩu mới phải có ít nhất 6 ký tự!';
    } elseif ($matKhauMoi !== $xacNhanMK) {
        $error = 'Mật khẩu xác nhận không khớp!';
    } else {
        // Kiểm tra trùng Email hoặc SĐT với các tài khoản khác
        $stmtDup = $conn->prepare("SELECT MaTaiKhoan FROM TaiKhoan WHERE (Email = ? OR SDT = ?) AND MaTaiKhoan != ?");
        $stmtDup->execute([$email, $sdt, $maTK]);

        if ($stmtDup->rowCount() > 0) {
            $error = 'Email hoặc Số điện thoại này đã được sử dụng bởi tài khoản khác!';
        } else {
            // Cập nhật bảng KhachHang
            $stmtUpdKH = $conn->prepare("UPDATE KhachHang SET HoTen = ? WHERE MaKhachHang = ?");
            $stmtUpdKH->execute([$hoTen, $maKH]);

            // Cập nhật bảng TaiKhoan (kèm mật khẩu nếu có nhập)
            if ($matKhauMoi !== '') {
                $hash = password_hash($matKhauMoi, PASSWORD_DEFAULT);
                $stmtUpdTK = $conn->prepare("UPDATE TaiKhoan SET Email = ?, SDT = ?, MatKhau = ? WHERE MaTaiKhoan = ?");
                $stmtUpdTK->execute([$email, $sdt, $hash, $maTK]);
            } else {
                $stmtUpdTK = $conn->prepare("UPDATE TaiKhoan SET Email = ?, SDT = ? WHERE MaTaiKhoan = ?");
                $stmtUpdTK->execute([$email, $sdt, $maTK]);
            }

            // Đồng bộ lại Session
            $_SESSION['HoTen'] = $hoTen;
            $_SESSION['Email'] = $email;

            // Tải lại dữ liệu mới nhất lên giao diện
            $stmtGet->execute([$maKH]);
            $profile = $stmtGet->fetch();

            $success = 'Cập nhật thông tin cá nhân thành công!';
            $openEditForm = false;
        }
    }
}

// Định dạng mã tài khoản theo mẫu PJS091001
$maTaiKhoanHienThi = 'PJS' . str_pad($profile['MaTaiKhoan'], 6, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thông tin cá nhân - Pet Care & Spa</title>
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
            padding: 0 10px 24px 10px;
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
            letter-spacing: 1px;
            color: #dbe4dc;
            margin: 6px 0 18px 0;
        }

        .sidebar-menu {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sidebar-menu a {
            display: block;
            text-decoration: none;
            color: #d5dfd7;
            font-size: 14px;
            font-weight: 500;
            padding: 11px 16px;
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
            max-width: 1150px;
        }

        .page-header {
            margin-bottom: 28px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 800;
            color: #243429;
        }

        /* THÔNG BÁO */
        .alert-error {
            background-color: #fde8e8;
            color: #c81e1e;
            border: 1px solid #f8b4b4;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            max-width: 880px;
        }

        .alert-success {
            background-color: #def7ec;
            color: #03543f;
            border: 1px solid #84e1bc;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            max-width: 880px;
        }

        /* BỐ CỤC 2 THẺ NGANG GIỐNG BẢN THIẾT KẾ */
        .profile-overview-grid {
            display: grid;
            grid-template-columns: 1fr 240px;
            gap: 20px;
            align-items: start;
            max-width: 880px;
            margin-bottom: 24px;
        }

        /* Thẻ thông tin cá nhân bên trái */
        .profile-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 16px;
            padding: 30px 34px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.02);
        }

        .profile-card-body {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 24px;
            margin-bottom: 22px;
        }

        .profile-details {
            display: flex;
            flex-direction: column;
            gap: 15px;
            font-size: 14.5px;
            color: #243429;
        }

        .detail-line strong {
            font-weight: 700;
            color: #243429;
        }

        .status-active {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: #4ea832;
            font-weight: 700;
            font-size: 14px;
            margin-top: 2px;
        }

        .status-active::before {
            content: "";
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #4ea832;
        }

        .avatar-placeholder {
            width: 155px;
            height: 155px;
            background-color: #e2e5df;
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .avatar-placeholder svg {
            width: 58px;
            height: 58px;
            fill: #8d978e;
        }

        .profile-card-footer {
            display: flex;
            justify-content: center;
        }

        .btn-toggle-edit {
            background-color: #f1f3f5;
            color: #243429;
            border: 1px solid #e2e6ea;
            padding: 11px 28px;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s, transform 0.1s;
        }

        .btn-toggle-edit:hover {
            background-color: #e4e8ec;
        }

        /* Thẻ Mã tài khoản bên phải */
        .account-code-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 16px;
            padding: 26px 22px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.02);
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 24px;
        }

        .code-badge {
            background-color: #556e57;
            color: #ffffff;
            font-size: 13px;
            font-weight: 700;
            padding: 13px 26px;
            border-radius: 10px;
            width: 100%;
        }

        .code-value {
            font-size: 24px;
            font-weight: 800;
            letter-spacing: 0.8px;
            color: #243429;
            padding-bottom: 8px;
        }

        /* ================= KHUNG CHỈNH SỬA THÔNG TIN ================= */
        .edit-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 16px;
            padding: 30px 34px;
            max-width: 880px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            display: none;
        }

        .edit-card.open {
            display: block;
        }

        .edit-card-title {
            font-size: 18px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .edit-card-sub {
            font-size: 13.5px;
            color: #77857a;
            margin-bottom: 22px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-size: 13.5px;
            font-weight: 700;
            color: #2c3e32;
        }

        .form-group label span {
            color: #c81e1e;
        }

        .form-group input {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #dce3d9;
            border-radius: 8px;
            font-size: 14.5px;
            color: #243429;
            background-color: #fcfcfa;
            outline: none;
            transition: border-color 0.2s, background-color 0.2s;
        }

        .form-group input:focus {
            border-color: #556e57;
            background-color: #ffffff;
        }

        .password-divider {
            grid-column: 1 / -1;
            border: none;
            border-top: 1px dashed #e2e6df;
            margin: 6px 0;
        }

        .password-hint {
            grid-column: 1 / -1;
            font-size: 13px;
            font-weight: 700;
            color: #556e57;
        }

        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #f0ede6;
        }

        .btn-save {
            background-color: #556e57;
            color: #ffffff;
            border: none;
            padding: 12px 26px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-save:hover {
            background-color: #445946;
        }

        .btn-cancel {
            background-color: #ffffff;
            color: #243429;
            border: 1px solid #d5ddd2;
            padding: 11px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-cancel:hover {
            background-color: #f3f6f1;
        }

        @media (max-width: 900px) {
            .profile-overview-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 780px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .profile-card-body {
                flex-direction: column-reverse;
                align-items: center;
                text-align: center;
            }
            .form-grid {
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

            <div class="role-label">KHÁCH HÀNG</div>

            <ul class="sidebar-menu">
                <li><a href="KH_tongquan.php">Tổng quan</a></li>
                <li><a href="KH_xemHS.php">Thú cưng của tôi</a></li>
                <li><a href="khachhang_datlich.php">Đặt lịch dịch vụ</a></li>
                <li><a href="khachhang_lichhen.php">Lịch hẹn của tôi</a></li>
                <li><a href="khachhang_lichhen.php?trangthai=hoanthanh">Lịch sử chăm sóc</a></li>
                <li><a href="khachhang_hoso.php" class="active">Thông tin cá nhân</a></li>
            </ul>
        </div>

        <div class="sidebar-bottom">
            <a href="trangChu.php">Trang chủ</a>
            <a href="dangXuat.php" class="logout-link">Đăng xuất</a>
        </div>
    </aside>

    <!-- NỘI DUNG CHÍNH -->
    <main class="main-content">
        <div class="page-header">
            <h1>Thông tin cá nhân</h1>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- 2 THẺ HIỂN THỊ THÔNG TIN & MÃ TÀI KHOẢN -->
        <div class="profile-overview-grid">
            <!-- Thẻ trái: Chi tiết thông tin -->
            <div class="profile-card">
                <div class="profile-card-body">
                    <div class="profile-details">
                        <div class="detail-line">
                            <strong>Họ tên:</strong> <?= htmlspecialchars($profile['HoTen']) ?>
                        </div>
                        <div class="detail-line">
                            <strong>Email:</strong> <?= htmlspecialchars($profile['Email']) ?>
                        </div>
                        <div class="detail-line">
                            <strong>SDT:</strong> <?= htmlspecialchars($profile['SDT']) ?>
                        </div>
                        <div class="detail-line">
                            <strong>Vai trò:</strong> <?= htmlspecialchars($profile['VaiTro']) ?>
                        </div>
                        <div class="status-active">Đang hoạt động</div>
                    </div>

                    <div class="avatar-placeholder" title="Ảnh đại diện khách hàng">
                        <svg viewBox="0 0 24 24">
                            <path d="M12 12c2.76 0 5-2.24 5-5s-2.24-5-5-5-5 2.24-5 5 2.24 5 5 5zm0 2c-3.33 0-10 1.67-10 5v3h20v-3c0-3.33-6.67-5-10-5z"/>
                        </svg>
                    </div>
                </div>

                <div class="profile-card-footer">
                    <button type="button" class="btn-toggle-edit" id="btn-open-edit">
                        Chỉnh sửa thông tin
                    </button>
                </div>
            </div>

            <!-- Thẻ phải: Mã tài khoản -->
            <div class="account-code-card">
                <div class="code-badge">Mã tài khoản</div>
                <div class="code-value"><?= htmlspecialchars($maTaiKhoanHienThi) ?></div>
            </div>
        </div>

        <!-- KHUNG FORM CHỈNH SỬA THÔNG TIN -->
        <div class="edit-card <?= $openEditForm ? 'open' : '' ?>" id="edit-profile-card">
            <div class="edit-card-title">Cập nhật thông tin tài khoản</div>
            <div class="edit-card-sub">Chỉnh sửa thông tin liên hệ hoặc đổi mật khẩu đăng nhập của bạn.</div>

            <form method="POST" action="khachhang_hoso.php">
                <input type="hidden" name="action" value="cap_nhat_hoso">

                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Họ và tên <span>*</span></label>
                        <input type="text" name="ho_ten"
                               value="<?= htmlspecialchars($_POST['ho_ten'] ?? $profile['HoTen']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Email đăng nhập <span>*</span></label>
                        <input type="email" name="email"
                               value="<?= htmlspecialchars($_POST['email'] ?? $profile['Email']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Số điện thoại <span>*</span></label>
                        <input type="text" name="sdt"
                               value="<?= htmlspecialchars($_POST['sdt'] ?? $profile['SDT']) ?>" required>
                    </div>

                    <hr class="password-divider">
                    <div class="password-hint">Đổi mật khẩu (để trống nếu không muốn thay đổi)</div>

                    <div class="form-group">
                        <label>Mật khẩu mới</label>
                        <input type="password" name="mat_khau_moi" placeholder="Nhập mật khẩu mới (tối thiểu 6 ký tự)">
                    </div>

                    <div class="form-group">
                        <label>Xác nhận mật khẩu mới</label>
                        <input type="password" name="xac_nhan_mk" placeholder="Nhập lại mật khẩu mới">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-save">Lưu thay đổi</button>
                    <button type="button" class="btn-cancel" id="btn-close-edit">Hủy</button>
                </div>
            </form>
        </div>
    </main>
</div>

<script>
    const editCard = document.getElementById('edit-profile-card');
    const btnOpenEdit = document.getElementById('btn-open-edit');
    const btnCloseEdit = document.getElementById('btn-close-edit');

    btnOpenEdit.addEventListener('click', () => {
        editCard.classList.toggle('open');
        if (editCard.classList.contains('open')) {
            editCard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    });

    btnCloseEdit.addEventListener('click', () => {
        editCard.classList.remove('open');
    });
</script>

</body>
</html>