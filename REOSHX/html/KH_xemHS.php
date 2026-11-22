<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền truy cập Khách hàng
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Khách hàng' || empty($_SESSION['MaKhachHang'])) {
    header('Location: dangNhap.php');
    exit;
}

$maKH = $_SESSION['MaKhachHang'];
$error = '';
$success = '';

// Xác định ID thú cưng đang được chọn để xem & chỉnh sửa trực tiếp
$selectedPetId = isset($_GET['sua']) ? (int)$_GET['sua'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

// 1. XỬ LÝ CẬP NHẬT HỒ SƠ THÚ CƯNG TRỰC TIẾP TRÊN GIAO DIỆN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cap_nhat') {
    $maTC       = (int)$_POST['ma_thu_cung'];
    $tenThuCung = trim($_POST['ten_thu_cung'] ?? '');
    $loai       = trim($_POST['loai'] ?? '');
    $gioiTinh   = $_POST['gioi_tinh'] ?? '';
    $canNang    = floatval($_POST['can_nang'] ?? 0);
    $diUng      = trim($_POST['di_ung'] ?? '');
    $ghiChu     = trim($_POST['ghi_chu'] ?? '');

    $selectedPetId = $maTC;

    if ($tenThuCung === '' || $loai === '' || !in_array($gioiTinh, ['Đực', 'Cái'])) {
        $error = 'Vui lòng nhập đầy đủ Tên thú cưng, Loài/Giống và Giới tính!';
    } elseif ($canNang <= 0) {
        $error = 'Cân nặng của thú cưng phải lớn hơn 0 kg!';
    } else {
        $diUngVal  = ($diUng !== '') ? $diUng : null;
        $ghiChuVal = ($ghiChu !== '') ? $ghiChu : null;

        $stmtUpd = $conn->prepare("
            UPDATE ThuCung 
            SET TenThuCung = ?, Loai = ?, GioiTinh = ?, CanNang = ?, DiUng = ?, GhiChu = ?
            WHERE MaThuCung = ? AND MaKhachHang = ?
        ");
        $stmtUpd->execute([$tenThuCung, $loai, $gioiTinh, $canNang, $diUngVal, $ghiChuVal, $maTC, $maKH]);
        $success = 'Đã cập nhật thông tin hồ sơ của bé ' . htmlspecialchars($tenThuCung) . ' thành công!';
    }
}

// 2. XỬ LÝ XÓA HỒ SƠ THÚ CƯNG
if (isset($_GET['xoa'])) {
    $maTC = (int)$_GET['xoa'];
    try {
        $stmtDel = $conn->prepare("DELETE FROM ThuCung WHERE MaThuCung = ? AND MaKhachHang = ?");
        $stmtDel->execute([$maTC, $maKH]);
        $success = 'Đã xóa hồ sơ thú cưng khỏi danh sách!';
        $selectedPetId = 0;
    } catch (PDOException $e) {
        $error = 'Không thể xóa hồ sơ này vì thú cưng đã có dữ liệu lịch hẹn trong hệ thống!';
    }
}

// 3. LẤY TOÀN BỘ DANH SÁCH THÚ CƯNG CỦA KHÁCH HÀNG
$stmtAll = $conn->prepare("SELECT * FROM ThuCung WHERE MaKhachHang = ? ORDER BY MaThuCung DESC");
$stmtAll->execute([$maKH]);
$dsThuCung = $stmtAll->fetchAll();

// 4. LẤY THÔNG TIN CHI TIẾT 1 BÉ NẾU ĐANG MỞ CHẾ ĐỘ XEM & CHỈNH SỬA
$petDetail = null;
if ($selectedPetId > 0) {
    foreach ($dsThuCung as $item) {
        if ((int)$item['MaThuCung'] === $selectedPetId) {
            $petDetail = $item;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $petDetail ? 'Hồ sơ: ' . htmlspecialchars($petDetail['TenThuCung']) : 'Thú cưng của tôi' ?> - Pet Care & Spa</title>
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
            max-width: 1200px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
            flex-wrap: wrap;
        }

        .page-header h1 {
            font-size: 26px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .page-header p {
            font-size: 14px;
            color: #77857a;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .btn-primary {
            background-color: #556e57;
            color: #ffffff;
            border: none;
            text-decoration: none;
            padding: 11px 22px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-block;
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
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: inline-block;
            transition: background-color 0.2s;
        }

        .btn-secondary:hover {
            background-color: #f2f5f0;
        }

        .btn-danger-outline {
            background-color: #fff5f5;
            color: #c81e1e;
            border: 1px solid #f8b4b4;
            text-decoration: none;
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            display: inline-block;
            text-align: center;
            transition: background-color 0.2s;
        }

        .btn-danger-outline:hover {
            background-color: #fde8e8;
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
        }

        .alert-success {
            background-color: #def7ec;
            color: #03543f;
            border: 1px solid #84e1bc;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        /* THANH CHUYỂN NHANH GIỮA CÁC BÉ (KHI CÓ NHIỀU THÚ CƯNG) */
        .pet-switcher {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }

        .pet-switcher-label {
            font-size: 13px;
            font-weight: 700;
            color: #6b786e;
        }

        .pet-pill {
            text-decoration: none;
            padding: 7px 15px;
            border-radius: 20px;
            font-size: 13.5px;
            font-weight: 600;
            background-color: #ffffff;
            color: #243429;
            border: 1px solid #dce3d9;
            transition: all 0.2s;
        }

        .pet-pill:hover {
            background-color: #eef3eb;
        }

        .pet-pill.active {
            background-color: #283b2e;
            color: #ffffff;
            border-color: #283b2e;
        }

        /* ================= KHUNG XEM & CHỈNH SỬA TRỰC TIẾP ================= */
        .detail-edit-grid {
            display: grid;
            grid-template-columns: 310px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* Cột trái: Thẻ xem thông tin hiện tại */
        .profile-summary-card {
            background-color: #ffffff;
            border-radius: 14px;
            padding: 26px 24px;
            border: 1px solid #f0ede6;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .summary-avatar {
            width: 84px;
            height: 84px;
            border-radius: 18px;
            background-color: #e8efe3;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto;
        }

        .summary-avatar svg {
            width: 40px;
            height: 40px;
            fill: #4f5e53;
        }

        .summary-header {
            text-align: center;
        }

        .summary-name {
            font-size: 22px;
            font-weight: 800;
            color: #243429;
        }

        .summary-sub {
            font-size: 13.5px;
            color: #6b786e;
            margin-top: 4px;
        }

        .summary-badges {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .info-tag {
            background-color: #f3f6f1;
            color: #3a4f40;
            font-size: 12.5px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
        }

        .summary-box {
            background-color: #faf7f2;
            border: 1px solid #eceae4;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.5;
            color: #36473b;
        }

        .summary-box.allergy-warning {
            background-color: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }

        .summary-box strong {
            display: block;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            margin-bottom: 3px;
        }

        /* Cột phải: Form chỉnh sửa trực tiếp */
        .edit-form-card {
            background-color: #ffffff;
            border-radius: 14px;
            padding: 30px 34px;
            border: 1px solid #f0ede6;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
        }

        .edit-form-title {
            font-size: 18px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .edit-form-sub {
            font-size: 13.5px;
            color: #77857a;
            margin-bottom: 22px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
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

        .form-group input,
        .form-group select,
        .form-group textarea {
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

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #556e57;
            background-color: #ffffff;
        }

        .form-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 22px;
            padding-top: 18px;
            border-top: 1px solid #f0ede6;
            flex-wrap: wrap;
        }

        /* ================= DANH SÁCH TẤT CẢ THÚ CƯNG ================= */
        .pets-list-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        .pet-item-card {
            background-color: #ffffff;
            border: 1px solid #f0ede6;
            border-radius: 12px;
            padding: 22px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.025);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 14px;
        }

        .pet-item-top {
            display: flex;
            gap: 16px;
            align-items: center;
        }

        .pet-badge-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            background-color: #e8efe3;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .pet-badge-icon svg {
            width: 28px;
            height: 28px;
            fill: #4f5e53;
        }

        .pet-item-title {
            font-size: 18.5px;
            font-weight: 800;
            color: #243429;
        }

        .pet-item-sub {
            font-size: 13.5px;
            color: #6b786e;
            margin-top: 3px;
        }

        .pet-item-extra {
            font-size: 13px;
            color: #4f5e53;
            background-color: #faf7f2;
            padding: 11px 14px;
            border-radius: 8px;
            line-height: 1.55;
        }

        .pet-item-actions {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            border-top: 1px solid #f0ede6;
            padding-top: 12px;
        }

        .btn-edit-sm {
            background-color: #556e57;
            color: #ffffff;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .btn-edit-sm:hover {
            background-color: #445946;
        }

        .btn-del-sm {
            background-color: #fde8e8;
            color: #c81e1e;
            text-decoration: none;
            padding: 8px 14px;
            border-radius: 7px;
            font-size: 13px;
            font-weight: 600;
        }

        .btn-del-sm:hover {
            background-color: #fbd5d5;
        }

        .empty-state-card {
            background-color: #ffffff;
            border: 1px dashed #d3dcd0;
            border-radius: 12px;
            padding: 40px 24px;
            text-align: center;
        }

        @media (max-width: 960px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .detail-edit-grid,
            .pets-list-grid,
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
                <li><a href="KH_xemHS.php" class="active">Thú cưng của tôi</a></li>
                <li><a href="khachhang_datlich.php">Đặt lịch dịch vụ</a></li>
                <li><a href="khachhang_lichhen.php">Lịch hẹn của tôi</a></li>
                <li><a href="khachhang_lichhen.php?trangthai=hoanthanh">Lịch sử chăm sóc</a></li>
                <li><a href="khachhang_hoso.php">Thông tin cá nhân</a></li>
            </ul>
        </div>

        <div class="sidebar-bottom">
            <a href="trangChu.php">← Trang chủ</a>
            <a href="dangXuat.php" class="logout-link">Đăng xuất</a>
        </div>
    </aside>

    <!-- NỘI DUNG CHÍNH -->
    <main class="main-content">
        <?php if ($petDetail): ?>
            <!-- ================= CHẾ ĐỘ XEM & CHỈNH SỬA TRỰC TIẾP 1 HỒ SƠ ================= -->
            <div class="page-header">
                <div>
                    <h1>Hồ sơ thú cưng: <?= htmlspecialchars($petDetail['TenThuCung']) ?></h1>
                    <p>Xem thông tin chi tiết và chỉnh sửa trực tiếp bên dưới.</p>
                </div>
                <div class="header-actions">
                    <a href="KH_tongquan.php" class="btn-secondary">Quay lại</a>
                    <?php if (count($dsThuCung) > 1): ?>
                        <a href="KH_xemHS.php" class="btn-secondary">Danh sách tất cả (<?= count($dsThuCung) ?>)</a>
                    <?php endif; ?>
                    <a href="KH_taoHoSo.php" class="btn-primary">+ Thêm thú cưng</a>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert-success"><?= $success ?></div>
            <?php endif; ?>

            <?php if (count($dsThuCung) > 1): ?>
                <div class="pet-switcher">
                    <span class="pet-switcher-label">Chọn nhanh hồ sơ:</span>
                    <?php foreach ($dsThuCung as $p): ?>
                        <a href="KH_xemHS.php?sua=<?= $p['MaThuCung'] ?>"
                           class="pet-pill <?= ((int)$p['MaThuCung'] === (int)$petDetail['MaThuCung']) ? 'active' : '' ?>">
                            <?= htmlspecialchars($p['TenThuCung']) ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="detail-edit-grid">
                <!-- Cột trái: Thẻ hiển thị thông tin hiện tại -->
                <div class="profile-summary-card">
                    <div class="summary-avatar">
                        <svg viewBox="0 0 512 512">
                            <path d="M256 224c-79.41 0-192 122.76-192 200.25 0 34.9 26.81 55.75 71.74 55.75 48.84 0 81.09-25.08 120.26-25.08 39.51 0 71.85 25.08 120.26 25.08 44.93 0 71.74-20.85 71.74-55.75C448 346.76 335.41 224 256 224zm-147.28-12.61c-10.4-34.65-42.44-57.09-71.56-50.13-29.12 6.96-44.29 40.69-33.89 75.34 10.4 34.65 42.44 57.09 71.56 50.13 29.12-6.96 44.29-40.69 33.89-75.34zm84.72-20.78c30.94-8.14 46.42-49.94 34.58-93.36s-46.52-72.01-77.46-63.87-46.42 49.94-34.58 93.36c11.84 43.42 46.53 72.02 77.46 63.87zm281.39-29.34c-29.12-6.96-61.15 15.48-71.56 50.13-10.4 34.65 4.77 68.38 33.89 75.34 29.12 6.96 61.15-15.48 71.56-50.13 10.4-34.65-4.77-68.38-33.89-75.34zm-156.27 29.34c30.94 8.14 65.62-20.45 77.46-63.87 11.84-43.42-3.64-85.21-34.58-93.36s-65.62 20.45-77.46 63.87c-11.84 43.42 3.64 85.22 34.58 93.36z"/>
                        </svg>
                    </div>

                    <div class="summary-header">
                        <div class="summary-name"><?= htmlspecialchars($petDetail['TenThuCung']) ?></div>
                        <div class="summary-sub"><?= htmlspecialchars($petDetail['Loai']) ?></div>
                    </div>

                    <div class="summary-badges">
                        <span class="info-tag">Giới tính: <?= htmlspecialchars($petDetail['GioiTinh']) ?></span>
                        <span class="info-tag"><?= number_format($petDetail['CanNang'], 1) ?> kg</span>
                    </div>

                    <div class="summary-box <?= !empty($petDetail['DiUng']) ? 'allergy-warning' : '' ?>">
                        <strong>Tiền sử dị ứng</strong>
                        <?= !empty($petDetail['DiUng']) ? htmlspecialchars($petDetail['DiUng']) : 'Chưa ghi nhận dị ứng' ?>
                    </div>

                    <div class="summary-box">
                        <strong>Ghi chú chăm sóc</strong>
                        <?= !empty($petDetail['GhiChu']) ? htmlspecialchars($petDetail['GhiChu']) : 'Không có ghi chú đặc biệt' ?>
                    </div>

                    <a href="KH_xemHS.php?xoa=<?= $petDetail['MaThuCung'] ?>"
                       class="btn-danger-outline"
                       onclick="return confirm('Bạn chắc chắn muốn xóa hồ sơ của bé <?= htmlspecialchars($petDetail['TenThuCung']) ?>?')">
                        Xóa hồ sơ thú cưng
                    </a>
                </div>

                <!-- Cột phải: Form chỉnh sửa & lưu trực tiếp -->
                <div class="edit-form-card">
                    <div class="edit-form-title">Chỉnh sửa thông tin hồ sơ</div>
                    <div class="edit-form-sub">Thay đổi các thông tin bên dưới và nhấn "Lưu thay đổi" để cập nhật ngay.</div>

                    <form method="POST" action="KH_xemHS.php?sua=<?= $petDetail['MaThuCung'] ?>">
                        <input type="hidden" name="action" value="cap_nhat">
                        <input type="hidden" name="ma_thu_cung" value="<?= $petDetail['MaThuCung'] ?>">

                        <div class="form-grid">
                            <div class="form-group">
                                <label>Tên thú cưng <span>*</span></label>
                                <input type="text" name="ten_thu_cung"
                                       value="<?= htmlspecialchars($petDetail['TenThuCung']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Loài / Giống <span>*</span></label>
                                <input type="text" name="loai"
                                       value="<?= htmlspecialchars($petDetail['Loai']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Giới tính <span>*</span></label>
                                <select name="gioi_tinh" required>
                                    <option value="Đực" <?= $petDetail['GioiTinh'] === 'Đực' ? 'selected' : '' ?>>Đực</option>
                                    <option value="Cái" <?= $petDetail['GioiTinh'] === 'Cái' ? 'selected' : '' ?>>Cái</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Cân nặng (kg) <span>*</span></label>
                                <input type="number" step="0.1" min="0.1" name="can_nang"
                                       value="<?= $petDetail['CanNang'] ?>" required>
                            </div>

                            <div class="form-group full-width">
                                <label>Tiền sử dị ứng</label>
                                <input type="text" name="di_ung"
                                       placeholder="Ví dụ: Dị ứng sữa tắm hương bạc hà..."
                                       value="<?= htmlspecialchars($petDetail['DiUng'] ?? '') ?>">
                            </div>

                            <div class="form-group full-width">
                                <label>Ghi chú chăm sóc đặc biệt</label>
                                <textarea name="ghi_chu" rows="3"
                                          placeholder="Ví dụ: Bé hơi nhát, cần sấy nhẹ..."><?= htmlspecialchars($petDetail['GhiChu'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">Lưu thay đổi</button>
                            <a href="khachhang_datlich.php?pet_id=<?= $petDetail['MaThuCung'] ?>" class="btn-secondary">
                                Đặt lịch cho bé này →
                            </a>
                        </div>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- ================= CHẾ ĐỘ XEM DANH SÁCH TẤT CẢ THÚ CƯNG ================= -->
            <div class="page-header">
                <div>
                    <h1>Thú cưng của tôi (<?= count($dsThuCung) ?> bé)</h1>
                    <p>Chọn "Xem & Sửa hồ sơ" trên từng thẻ để xem chi tiết hoặc cập nhật thông tin.</p>
                </div>
                <a href="KH_taoHoSo.php" class="btn-primary">
                    <?= count($dsThuCung) === 0 ? '+ Tạo hồ sơ thú cưng' : '+ Thêm thú cưng' ?>
                </a>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <?php if (!empty($success)): ?>
                <div class="alert-success"><?= $success ?></div>
            <?php endif; ?>

            <?php if (count($dsThuCung) > 0): ?>
                <div class="pets-list-grid">
                    <?php foreach ($dsThuCung as $pet): ?>
                        <div class="pet-item-card">
                            <div>
                                <div class="pet-item-top">
                                    <div class="pet-badge-icon">
                                        <svg viewBox="0 0 512 512">
                                            <path d="M256 224c-79.41 0-192 122.76-192 200.25 0 34.9 26.81 55.75 71.74 55.75 48.84 0 81.09-25.08 120.26-25.08 39.51 0 71.85 25.08 120.26 25.08 44.93 0 71.74-20.85 71.74-55.75C448 346.76 335.41 224 256 224zm-147.28-12.61c-10.4-34.65-42.44-57.09-71.56-50.13-29.12 6.96-44.29 40.69-33.89 75.34 10.4 34.65 42.44 57.09 71.56 50.13 29.12-6.96 44.29-40.69 33.89-75.34zm84.72-20.78c30.94-8.14 46.42-49.94 34.58-93.36s-46.52-72.01-77.46-63.87-46.42 49.94-34.58 93.36c11.84 43.42 46.53 72.02 77.46 63.87zm281.39-29.34c-29.12-6.96-61.15 15.48-71.56 50.13-10.4 34.65 4.77 68.38 33.89 75.34 29.12 6.96 61.15-15.48 71.56-50.13 10.4-34.65-4.77-68.38-33.89-75.34zm-156.27 29.34c30.94 8.14 65.62-20.45 77.46-63.87 11.84-43.42-3.64-85.21-34.58-93.36s-65.62 20.45-77.46 63.87c-11.84 43.42 3.64 85.22 34.58 93.36z"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <div class="pet-item-title"><?= htmlspecialchars($pet['TenThuCung']) ?></div>
                                        <div class="pet-item-sub">
                                            <?= htmlspecialchars($pet['Loai']) ?> ·
                                            <?= htmlspecialchars($pet['GioiTinh']) ?> ·
                                            <?= number_format($pet['CanNang'], 1) ?> kg
                                        </div>
                                    </div>
                                </div>

                                <div class="pet-item-extra" style="margin-top: 14px;">
                                    <div><strong>Dị ứng:</strong> <?= !empty($pet['DiUng']) ? htmlspecialchars($pet['DiUng']) : 'Không có' ?></div>
                                    <div><strong>Ghi chú:</strong> <?= !empty($pet['GhiChu']) ? htmlspecialchars($pet['GhiChu']) : 'Không có' ?></div>
                                </div>
                            </div>

                            <div class="pet-item-actions">
                                <a href="KH_xemHS.php?sua=<?= $pet['MaThuCung'] ?>" class="btn-edit-sm">
                                    Xem & Sửa hồ sơ
                                </a>
                                <a href="KH_xemHS.php?xoa=<?= $pet['MaThuCung'] ?>"
                                   class="btn-del-sm"
                                   onclick="return confirm('Bạn chắc chắn muốn xóa hồ sơ của bé <?= htmlspecialchars($pet['TenThuCung']) ?>?')">
                                    Xóa
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state-card">
                    <p style="color: #6b786e; margin-bottom: 16px;">Bạn chưa có hồ sơ thú cưng nào trong hệ thống.</p>
                    <a href="KH_taoHoSo.php" class="btn-primary">+ Tạo hồ sơ thú cưng</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>

</body>
</html>