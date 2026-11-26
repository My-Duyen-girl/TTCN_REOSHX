<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền truy cập Nhân viên
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Nhân viên' || empty($_SESSION['MaNhanVien'])) {
    header('Location: dangNhap.php');
    exit;
}

$hoTenNV = $_SESSION['HoTen'] ?? 'Nhân viên';
$error   = '';
$success = '';

// Lấy ID thú cưng nếu nhân viên bấm "Hồ sơ" từ ca làm hoặc từ danh sách
$petId = isset($_GET['pet_id']) ? (int)$_GET['pet_id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

// 1. XỬ LÝ CẬP NHẬT CÂN NẶNG, DỊ ỨNG & GHI CHÚ CHĂM SÓC CỦA THÚ CƯNG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cap_nhat_cham_soc') {
    $maTC    = (int)$_POST['ma_thu_cung'];
    $canNang = floatval($_POST['can_nang'] ?? 0);
    $diUng   = trim($_POST['di_ung'] ?? '');
    $ghiChu  = trim($_POST['ghi_chu'] ?? '');

    $petId = $maTC;

    if ($canNang <= 0) {
        $error = 'Cân nặng của thú cưng phải lớn hơn 0 kg!';
    } else {
        $diUngVal  = ($diUng !== '') ? $diUng : null;
        $ghiChuVal = ($ghiChu !== '') ? $ghiChu : null;

        $stmtUpd = $conn->prepare("
            UPDATE ThuCung 
            SET CanNang = ?, DiUng = ?, GhiChu = ?
            WHERE MaThuCung = ?
        ");
        $stmtUpd->execute([$canNang, $diUngVal, $ghiChuVal, $maTC]);
        $success = 'Đã lưu cập nhật cân nặng và ghi chú chăm sóc vào hồ sơ thú cưng!';
    }
}

// 2. NẾU CÓ PET_ID -> LẤY CHI TIẾT 1 HỒ SƠ + LỊCH SỬ CHĂM SÓC CỦA BÉ ĐÓ
$petDetail = null;
$lichSuChamSoc = [];

if ($petId > 0) {
    $stmtDetail = $conn->prepare("
        SELECT tc.*, kh.HoTen AS TenChuNuoi, tk.SDT AS SDTChuNuoi, tk.Email AS EmailChuNuoi
        FROM ThuCung tc
        JOIN KhachHang kh ON tc.MaKhachHang = kh.MaKhachHang
        JOIN TaiKhoan tk ON kh.MaTaiKhoan = tk.MaTaiKhoan
        WHERE tc.MaThuCung = ?
        LIMIT 1
    ");
    $stmtDetail->execute([$petId]);
    $petDetail = $stmtDetail->fetch();

    if ($petDetail) {
        $stmtHist = $conn->prepare("
            SELECT lh.MaLichHen, lh.ThoiGianBD, lh.TrangThai,
                   dv.TenDichVu, nv.HoTen AS TenNhanVien
            FROM LichHen lh
            JOIN DichVu dv ON lh.MaDichVu = dv.MaDichVu
            LEFT JOIN NhanVien nv ON lh.MaNhanVien = nv.MaNhanVien
            WHERE lh.MaThuCung = ?
            ORDER BY lh.ThoiGianBD DESC
        ");
        $stmtHist->execute([$petId]);
        $lichSuChamSoc = $stmtHist->fetchAll();
    } else {
        $error = 'Không tìm thấy hồ sơ thú cưng yêu cầu!';
    }
}

// 3. NẾU KHÔNG XEM CHI TIẾT -> LẤY DANH SÁCH TẤT CẢ THÚ CƯNG (CÓ TÌM KIẾM)
$keyword = trim($_GET['q'] ?? '');
$dsThuCung = [];

if (!$petDetail) {
    $sqlList = "
        SELECT tc.*, kh.HoTen AS TenChuNuoi, tk.SDT AS SDTChuNuoi
        FROM ThuCung tc
        JOIN KhachHang kh ON tc.MaKhachHang = kh.MaKhachHang
        JOIN TaiKhoan tk ON kh.MaTaiKhoan = tk.MaTaiKhoan
    ";
    $params = [];
    if ($keyword !== '') {
        $sqlList .= " WHERE tc.TenThuCung LIKE ? OR tc.Loai LIKE ? OR kh.HoTen LIKE ? OR tk.SDT LIKE ?";
        $like = '%' . $keyword . '%';
        $params = [$like, $like, $like, $like];
    }
    $sqlList .= " ORDER BY tc.MaThuCung DESC";

    $stmtList = $conn->prepare($sqlList);
    $stmtList->execute($params);
    $dsThuCung = $stmtList->fetchAll();
}

function getHistoryStatusClass($status) {
    switch ($status) {
        case 'Hoàn thành': return 'st-done';
        case 'Đang thực hiện': return 'st-progress';
        case 'Đã xác nhận': return 'st-confirmed';
        case 'Chờ xác nhận': return 'st-pending';
        case 'Đã hủy': return 'st-cancel';
        default: return '';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $petDetail ? 'Hồ sơ: ' . htmlspecialchars($petDetail['TenThuCung']) : 'Hồ sơ thú cưng' ?> - Pet Care & Spa</title>
    <Style>
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
            max-width: 1220px;
        }

        .top-bar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
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

        .top-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
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

        .btn-secondary {
            background-color: #ffffff;
            color: #243429;
            border: 1px solid #d5ddd2;
            text-decoration: none;
            padding: 10px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            display: inline-block;
            transition: background-color 0.2s;
        }

        .btn-secondary:hover {
            background-color: #eef3eb;
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

        /* ================= CHẾ ĐỘ XEM CHI TIẾT 1 HỒ SƠ ================= */
        .detail-layout {
            display: grid;
            grid-template-columns: 320px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* Cột trái: Thẻ thông tin bé cưng & chủ nuôi */
        .pet-profile-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 24px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.02);
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .pet-banner {
            height: 110px;
            border-radius: 10px;
            background-color: #e6eee0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 46px;
        }

        .pet-banner.cat-theme {
            background-color: #efe0d6;
        }

        .pet-title-box {
            text-align: center;
        }

        .pet-title-box h2 {
            font-size: 22px;
            font-weight: 800;
            color: #243429;
        }

        .pet-title-box p {
            font-size: 13.5px;
            color: #6b786e;
            margin-top: 3px;
        }

        .pet-tags {
            display: flex;
            justify-content: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .tag-item {
            background-color: #f3f6f1;
            color: #364f3c;
            font-size: 12.5px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
        }

        .info-block {
            background-color: #faf7f2;
            border: 1px solid #eceae4;
            border-radius: 10px;
            padding: 13px 15px;
            font-size: 13.5px;
            line-height: 1.55;
            color: #2c3e32;
        }

        .info-block strong.block-label {
            display: block;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6b786e;
            margin-bottom: 4px;
        }

        .info-block.allergy-alert {
            background-color: #fffbeb;
            border-color: #fde68a;
            color: #92400e;
        }

        .info-block.allergy-alert strong.block-label {
            color: #b45309;
        }

        /* Cột phải: Form cập nhật chăm sóc & Lịch sử dịch vụ */
        .right-column {
            display: flex;
            flex-direction: column;
            gap: 22px;
        }

        .care-form-card,
        .history-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 26px 30px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.02);
        }

        .section-title {
            font-size: 18px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .section-sub {
            font-size: 13.5px;
            color: #77857a;
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 180px 1fr;
            gap: 16px 18px;
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
            font-size: 13px;
            font-weight: 700;
            color: #36473b;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 11px 14px;
            border: 1.5px solid #dce3d9;
            border-radius: 8px;
            font-size: 14px;
            color: #243429;
            background-color: #fcfcfa;
            outline: none;
            transition: border-color 0.2s, background-color 0.2s;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            border-color: #556e57;
            background-color: #ffffff;
        }

        .form-footer {
            margin-top: 18px;
            display: flex;
            justify-content: flex-end;
        }

        /* Bảng lịch sử chăm sóc của bé */
        .history-table {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .history-head,
        .history-row {
            display: grid;
            grid-template-columns: 125px 1.3fr 1.1fr 115px;
            align-items: center;
            gap: 10px;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13.5px;
        }

        .history-head {
            background-color: #e9eee4;
            font-size: 11.5px;
            font-weight: 700;
            color: #6b786e;
            text-transform: uppercase;
        }

        .history-row {
            background-color: #faf7f2;
            border: 1px solid #f0ede6;
        }

        .status-pill-sm {
            font-weight: 700;
            font-size: 12.5px;
        }

        .st-done { color: #2f6b3c; }
        .st-progress { color: #1d4ed8; }
        .st-confirmed { color: #4c6451; }
        .st-pending { color: #a16207; }
        .st-cancel { color: #b91c1c; }

        /* ================= CHẾ ĐỘ DANH SÁCH TẤT CẢ THÚ CƯNG ================= */
        .search-bar-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 22px;
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .search-bar-card input {
            flex: 1;
            min-width: 240px;
            padding: 10px 14px;
            border: 1.5px solid #dce3d9;
            border-radius: 8px;
            font-size: 14px;
            outline: none;
        }

        .search-bar-card input:focus {
            border-color: #556e57;
        }

        .pets-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }

        .pet-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 18px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 14px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .pet-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(36, 52, 41, 0.06);
        }

        .pet-card-top {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .pet-emoji-box {
            width: 54px;
            height: 54px;
            border-radius: 12px;
            background-color: #e6eee0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            flex-shrink: 0;
        }

        .pet-emoji-box.cat-theme {
            background-color: #efe0d6;
        }

        .pet-card-name {
            font-size: 17.5px;
            font-weight: 800;
            color: #243429;
        }

        .pet-card-sub {
            font-size: 13px;
            color: #6b786e;
            margin-top: 2px;
        }

        .pet-card-owner {
            font-size: 13px;
            color: #36473b;
            background-color: #faf7f2;
            padding: 10px 12px;
            border-radius: 8px;
            line-height: 1.5;
        }

        .pet-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
            padding-top: 10px;
            border-top: 1px solid #f0ede6;
        }

        .allergy-tag {
            font-size: 11.5px;
            font-weight: 700;
            padding: 5px 11px;
            border-radius: 20px;
            background-color: #e6eee0;
            color: #4c6451;
        }

        .allergy-tag.warn {
            background-color: #fef3c7;
            color: #92400e;
        }

        .btn-view-pet {
            font-size: 13px;
            font-weight: 700;
            color: #243429;
            text-decoration: none;
        }

        .btn-view-pet:hover {
            color: #556e57;
            text-decoration: underline;
        }

        .empty-box {
            background-color: #ffffff;
            border: 1px dashed #d3dcd0;
            border-radius: 12px;
            padding: 36px 20px;
            text-align: center;
            color: #6b786e;
        }

        @media (max-width: 1040px) {
            .detail-layout {
                grid-template-columns: 1fr;
            }
            .pets-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 780px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .pets-grid,
            .form-grid {
                grid-template-columns: 1fr;
            }
            .history-head {
                display: none;
            }
            .history-row {
                grid-template-columns: 1fr 1fr;
            }
        }
    </Style>
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

            <div class="role-label">NHÂN VIÊN</div>

            <ul class="sidebar-menu">
                <li><a href="nhanvien_tongquan.php">Tổng quan ca làm</a></li>
                <li><a href="nhanvien_lichhen.php">Quản lý lịch hẹn</a></li>
                <li><a href="nhanvien_thucung.php" class="active">Hồ sơ thú cưng</a></li>
                <li><a href="nhanvien_hoso.php">Thông tin cá nhân</a></li>
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
                <h1><?= $petDetail ? 'Hồ sơ thú cưng: ' . htmlspecialchars($petDetail['TenThuCung']) : 'Danh sách hồ sơ thú cưng' ?></h1>
                <p><?= $petDetail ? 'Xem thông tin sức khỏe, liên hệ chủ nuôi và cập nhật ghi chú chăm sóc.' : 'Tra cứu hồ sơ sức khỏe và lưu ý dị ứng của các bé tại Spa.' ?></p>
            </div>

            <div class="top-actions">
                <?php if ($petDetail): ?>
                    <a href="nhanvien_tongquan.php" class="btn-secondary">Quay lại</a>
                    <a href="nhanvien_thucung.php" class="btn-secondary">Tất cả thú cưng</a>
                <?php endif; ?>
                <div class="user-pill">
                    <span class="status-dot"></span>
                    <span>Xin chào, <?= htmlspecialchars($hoTenNV) ?></span>
                </div>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <?php if ($petDetail): ?>
            <!-- ================= GIAO DIỆN XEM CHI TIẾT 1 HỒ SƠ THÚ CƯNG ================= -->
            <?php
                $isCat = (mb_stripos($petDetail['Loai'], 'mèo') !== false);
                $hasAllergy = !empty(trim($petDetail['DiUng'] ?? ''));
            ?>
            <div class="detail-layout">
                <!-- Cột trái: Thông tin bé & Chủ nuôi -->
                <div class="pet-profile-card">
                    <div class="pet-banner <?= $isCat ? 'cat-theme' : '' ?>">
                        <?= $isCat ? '🐈' : '🐕' ?>
                    </div>

                    <div class="pet-title-box">
                        <h2><?= htmlspecialchars($petDetail['TenThuCung']) ?></h2>
                        <p><?= htmlspecialchars($petDetail['Loai']) ?></p>
                    </div>

                    <div class="pet-tags">
                        <span class="tag-item">Giới tính: <?= htmlspecialchars($petDetail['GioiTinh']) ?></span>
                        <span class="tag-item"><?= number_format($petDetail['CanNang'], 1) ?> kg</span>
                    </div>

                    <div class="info-block <?= $hasAllergy ? 'allergy-alert' : '' ?>">
                        <strong class="block-label">Lưu ý dị ứng</strong>
                        <?= $hasAllergy ? htmlspecialchars($petDetail['DiUng']) : 'Chưa ghi nhận dị ứng' ?>
                    </div>

                    <div class="info-block">
                        <strong class="block-label">Ghi chú chăm sóc hiện tại</strong>
                        <?= !empty($petDetail['GhiChu']) ? htmlspecialchars($petDetail['GhiChu']) : 'Không có ghi chú đặc biệt' ?>
                    </div>

                    <div class="info-block">
                        <strong class="block-label">Thông tin chủ nuôi</strong>
                        <div><strong>Họ tên:</strong> <?= htmlspecialchars($petDetail['TenChuNuoi']) ?></div>
                        <div><strong>SĐT:</strong> <?= htmlspecialchars($petDetail['SDTChuNuoi']) ?></div>
                        <div><strong>Email:</strong> <?= htmlspecialchars($petDetail['EmailChuNuoi']) ?></div>
                    </div>
                </div>

                <!-- Cột phải: Cập nhật ghi chú chăm sóc & Lịch sử dịch vụ -->
                <div class="right-column">
                    <div class="care-form-card">
                        <div class="section-title">Cập nhật ghi chú & tình trạng chăm sóc</div>
                        <div class="section-sub">Ghi lại cân nặng thực tế, phản ứng dị ứng hoặc lưu ý sau khi thực hiện dịch vụ.</div>

                        <form method="POST" action="nhanvien_thucung.php?pet_id=<?= $petDetail['MaThuCung'] ?>">
                            <input type="hidden" name="action" value="cap_nhat_cham_soc">
                            <input type="hidden" name="ma_thu_cung" value="<?= $petDetail['MaThuCung'] ?>">

                            <div class="form-grid">
                                <div class="form-group">
                                    <label>Cân nặng thực tế (kg) *</label>
                                    <input type="number" step="0.1" min="0.1" name="can_nang"
                                           value="<?= $petDetail['CanNang'] ?>" required>
                                </div>

                                <div class="form-group">
                                    <label>Tiền sử / Phát hiện dị ứng</label>
                                    <input type="text" name="di_ung"
                                           placeholder="Ví dụ: Nhạy cảm với sữa tắm hương bạc hà..."
                                           value="<?= htmlspecialchars($petDetail['DiUng'] ?? '') ?>">
                                </div>

                                <div class="form-group full-width">
                                    <label>Ghi chú chăm sóc cho các lần hẹn sau</label>
                                    <textarea name="ghi_chu" rows="3"
                                              placeholder="Ví dụ: Bé ngoan khi tắm, hơi sợ tiếng máy sấy mạnh ở vùng tai..."><?= htmlspecialchars($petDetail['GhiChu'] ?? '') ?></textarea>
                                </div>
                            </div>

                            <div class="form-footer">
                                <button type="submit" class="btn-primary">Lưu cập nhật hồ sơ</button>
                            </div>
                        </form>
                    </div>

                    <!-- Lịch sử chăm sóc của bé -->
                    <div class="history-card">
                        <div class="section-title">Lịch sử dịch vụ của <?= htmlspecialchars($petDetail['TenThuCung']) ?></div>
                        <div class="section-sub">Các lịch hẹn đã và đang thực hiện tại Spa.</div>

                        <?php if (count($lichSuChamSoc) > 0): ?>
                            <div class="history-table">
                                <div class="history-head">
                                    <div>Ngày & Giờ</div>
                                    <div>Dịch vụ</div>
                                    <div>Nhân viên phụ trách</div>
                                    <div>Trạng thái</div>
                                </div>

                                <?php foreach ($lichSuChamSoc as $ls): ?>
                                    <div class="history-row">
                                        <div><strong><?= date('d/m/Y · H:i', strtotime($ls['ThoiGianBD'])) ?></strong></div>
                                        <div><?= htmlspecialchars($ls['TenDichVu']) ?></div>
                                        <div><?= !empty($ls['TenNhanVien']) ? htmlspecialchars($ls['TenNhanVien']) : 'Chưa phân công' ?></div>
                                        <div>
                                            <span class="status-pill-sm <?= getHistoryStatusClass($ls['TrangThai']) ?>">
                                                <?= htmlspecialchars($ls['TrangThai']) ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="empty-box">Bé chưa có lịch sử dịch vụ nào.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        <?php else: ?>
            <!-- ================= DANH SÁCH TẤT CẢ THÚ CƯNG ================= -->
            <form method="GET" action="nhanvien_thucung.php" class="search-bar-card">
                <input type="text" name="q"
                       placeholder="Tìm theo tên thú cưng, giống loài, tên chủ nuôi hoặc số điện thoại..."
                       value="<?= htmlspecialchars($keyword) ?>">
                <button type="submit" class="btn-primary">Tìm kiếm</button>
                <?php if ($keyword !== ''): ?>
                    <a href="nhanvien_thucung.php" class="btn-secondary">Xóa lọc</a>
                <?php endif; ?>
            </form>

            <?php if (count($dsThuCung) > 0): ?>
                <div class="pets-grid">
                    <?php foreach ($dsThuCung as $pet): ?>
                        <?php
                            $isCat = (mb_stripos($pet['Loai'], 'mèo') !== false);
                            $hasAllergy = !empty(trim($pet['DiUng'] ?? ''));
                        ?>
                        <div class="pet-card">
                            <div>
                                <div class="pet-card-top">
                                    <div class="pet-emoji-box <?= $isCat ? 'cat-theme' : '' ?>">
                                        <?= $isCat ? '🐈' : '🐕' ?>
                                    </div>
                                    <div>
                                        <div class="pet-card-name"><?= htmlspecialchars($pet['TenThuCung']) ?></div>
                                        <div class="pet-card-sub">
                                            <?= htmlspecialchars($pet['Loai']) ?> ·
                                            <?= htmlspecialchars($pet['GioiTinh']) ?> ·
                                            <?= number_format($pet['CanNang'], 1) ?> kg
                                        </div>
                                    </div>
                                </div>

                                <div class="pet-card-owner" style="margin-top: 12px;">
                                    <div><strong>Chủ nuôi:</strong> <?= htmlspecialchars($pet['TenChuNuoi']) ?></div>
                                    <div><strong>SĐT:</strong> <?= htmlspecialchars($pet['SDTChuNuoi']) ?></div>
                                </div>
                            </div>

                            <div class="pet-card-footer">
                                <span class="allergy-tag <?= $hasAllergy ? 'warn' : '' ?>">
                                    <?= $hasAllergy ? 'Có lưu ý dị ứng' : 'Không dị ứng' ?>
                                </span>
                                <a href="nhanvien_thucung.php?pet_id=<?= $pet['MaThuCung'] ?>" class="btn-view-pet">
                                    Xem hồ sơ →
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-box">Không tìm thấy hồ sơ thú cưng phù hợp.</div>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>

</body>
</html>