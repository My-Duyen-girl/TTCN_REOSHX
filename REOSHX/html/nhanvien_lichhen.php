<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền truy cập Nhân viên
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Nhân viên' || empty($_SESSION['MaNhanVien'])) {
    header('Location: dangNhap.php');
    exit;
}

$maNV    = (int)$_SESSION['MaNhanVien'];
$hoTenNV = $_SESSION['HoTen'] ?? 'Nhân viên';
$error   = '';
$success = '';

// Tham số lọc & tìm kiếm hiện tại
$filter  = $_GET['loc'] ?? 'tatca';
$keyword = trim($_GET['q'] ?? '');

// 1. XỬ LÝ CẬP NHẬT TRẠNG THÁI LỊCH HẸN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ma_lich_hen'], $_POST['action_type'])) {
    $maLH   = (int)$_POST['ma_lich_hen'];
    $action = $_POST['action_type'];
    $maCode = 'PC-' . str_pad($maLH, 4, '0', STR_PAD_LEFT);

    if ($action === 'xac_nhan') {
        $stmtUpd = $conn->prepare("
            UPDATE LichHen 
            SET TrangThai = 'Đã xác nhận', MaNhanVien = ? 
            WHERE MaLichHen = ? AND TrangThai = 'Chờ xác nhận'
        ");
        $stmtUpd->execute([$maNV, $maLH]);
        if ($stmtUpd->rowCount() > 0) {
            $success = "Đã xác nhận lịch hẹn {$maCode} và phân công cho bạn phụ trách!";
        }
    } elseif ($action === 'bat_dau') {
        $stmtUpd = $conn->prepare("
            UPDATE LichHen 
            SET TrangThai = 'Đang thực hiện', MaNhanVien = COALESCE(MaNhanVien, ?) 
            WHERE MaLichHen = ? AND TrangThai = 'Đã xác nhận'
        ");
        $stmtUpd->execute([$maNV, $maLH]);
        if ($stmtUpd->rowCount() > 0) {
            $success = "Lịch hẹn {$maCode} đã chuyển sang trạng thái Đang thực hiện!";
        }
    } elseif ($action === 'hoan_thanh') {
        $stmtUpd = $conn->prepare("
            UPDATE LichHen 
            SET TrangThai = 'Hoàn thành' 
            WHERE MaLichHen = ? AND TrangThai = 'Đang thực hiện'
        ");
        $stmtUpd->execute([$maLH]);
        if ($stmtUpd->rowCount() > 0) {
            $success = "Đã hoàn thành dịch vụ cho lịch hẹn {$maCode}!";
        }
    } elseif ($action === 'huy_lich') {
        $stmtUpd = $conn->prepare("
            UPDATE LichHen 
            SET TrangThai = 'Đã hủy' 
            WHERE MaLichHen = ? AND TrangThai IN ('Chờ xác nhận', 'Đã xác nhận')
        ");
        $stmtUpd->execute([$maLH]);
        if ($stmtUpd->rowCount() > 0) {
            $success = "Đã hủy lịch hẹn {$maCode}!";
        } else {
            $error = "Không thể hủy lịch hẹn {$maCode} ở trạng thái hiện tại!";
        }
    }
}

// 2. THỐNG KÊ 4 THẺ TỔNG QUAN
$soHomNay = (int)$conn->query("
    SELECT COUNT(*) FROM LichHen 
    WHERE DATE(ThoiGianBD) = CURDATE() AND TrangThai != 'Đã hủy'
")->fetchColumn();

$soChoXacNhan = (int)$conn->query("
    SELECT COUNT(*) FROM LichHen 
    WHERE TrangThai = 'Chờ xác nhận'
")->fetchColumn();

$soDangThucHien = (int)$conn->query("
    SELECT COUNT(*) FROM LichHen 
    WHERE TrangThai = 'Đang thực hiện'
")->fetchColumn();

$soHoanThanh = (int)$conn->query("
    SELECT COUNT(*) FROM LichHen 
    WHERE TrangThai = 'Hoàn thành'
")->fetchColumn();

// 3. TRUY VẤN DANH SÁCH LỊCH HẸN THEO BỘ LỌC & TỪ KHÓA
$sql = "
    SELECT lh.MaLichHen, lh.ThoiGianBD, lh.ThoiGianKT, lh.TrangThai,
           tc.MaThuCung, tc.TenThuCung, tc.Loai,
           dv.TenDichVu,
           kh.HoTen AS TenChuNuoi, tk.SDT AS SDTChuNuoi,
           nv.HoTen AS TenNhanVien
    FROM LichHen lh
    JOIN ThuCung tc ON lh.MaThuCung = tc.MaThuCung
    JOIN KhachHang kh ON tc.MaKhachHang = kh.MaKhachHang
    JOIN TaiKhoan tk ON kh.MaTaiKhoan = tk.MaTaiKhoan
    JOIN DichVu dv ON lh.MaDichVu = dv.MaDichVu
    LEFT JOIN NhanVien nv ON lh.MaNhanVien = nv.MaNhanVien
    WHERE 1=1
";

$params = [];

if ($filter === 'homnay') {
    $sql .= " AND DATE(lh.ThoiGianBD) = CURDATE()";
} elseif ($filter === 'choxacnhan') {
    $sql .= " AND lh.TrangThai = 'Chờ xác nhận'";
} elseif ($filter === 'daxacnhan') {
    $sql .= " AND lh.TrangThai = 'Đã xác nhận'";
} elseif ($filter === 'dangthuchien') {
    $sql .= " AND lh.TrangThai = 'Đang thực hiện'";
} elseif ($filter === 'hoanthanh') {
    $sql .= " AND lh.TrangThai = 'Hoàn thành'";
}

if ($keyword !== '') {
    $sql .= " AND (tc.TenThuCung LIKE ? OR kh.HoTen LIKE ? OR tk.SDT LIKE ? OR dv.TenDichVu LIKE ?)";
    $like = '%' . $keyword . '%';
    $params = [$like, $like, $like, $like];
}

$sql .= " ORDER BY lh.ThoiGianBD DESC, lh.MaLichHen DESC";

$stmtList = $conn->prepare($sql);
$stmtList->execute($params);
$dsLichHen = $stmtList->fetchAll();

function getStatusBadgeClass($status) {
    switch ($status) {
        case 'Chờ xác nhận': return 'st-pending';
        case 'Đã xác nhận': return 'st-confirmed';
        case 'Đang thực hiện': return 'st-progress';
        case 'Hoàn thành': return 'st-done';
        case 'Đã hủy': return 'st-cancelled';
        default: return '';
    }
}

$queryKeep = 'loc=' . urlencode($filter) . ($keyword !== '' ? '&q=' . urlencode($keyword) : '');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý lịch hẹn - Pet Care & Spa</title>
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
            max-width: 1250px;
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

        /* ================= 4 THẺ THỐNG KÊ ================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 22px;
        }

        .stat-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 12px;
            padding: 20px 24px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .stat-label {
            font-size: 13.5px;
            font-weight: 700;
            color: #4f5e53;
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 26px;
            font-weight: 800;
            color: #556e57;
            line-height: 1.1;
        }

        /* ================= THANH LỌC & TÌM KIẾM ================= */
        .filter-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .filter-tabs {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-tab {
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 14px;
            border-radius: 8px;
            background-color: #ffffff;
            color: #4f5e53;
            border: 1px solid #e6e4dd;
            transition: all 0.2s;
        }

        .filter-tab:hover {
            background-color: #eef3eb;
        }

        .filter-tab.active {
            background-color: #4c6451;
            color: #ffffff;
            border-color: #4c6451;
        }

        .search-form {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .search-form input {
            padding: 8px 14px;
            border: 1px solid #dce3d9;
            border-radius: 8px;
            font-size: 13.5px;
            background-color: #ffffff;
            color: #243429;
            width: 240px;
            outline: none;
        }

        .search-form input:focus {
            border-color: #556e57;
        }

        .btn-search {
            background-color: #556e57;
            color: #ffffff;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-search:hover {
            background-color: #445946;
        }

        /* ================= KHUNG BẢNG DANH SÁCH LỊCH HẸN ================= */
        .table-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 14px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.02);
        }

        .table-header,
        .table-row {
            display: grid;
            grid-template-columns: 120px 1.15fr 1.25fr 1.2fr 1fr 1.05fr 205px;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
        }

        .table-header {
            background-color: #e9eee4;
            border-radius: 10px;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #6b786e;
            text-transform: uppercase;
        }

        .table-row {
            border-bottom: 1px solid #f0ede6;
            font-size: 13.5px;
            color: #2c3e32;
            transition: background-color 0.15s;
        }

        .table-row:last-child {
            border-bottom: none;
        }

        .table-row:hover {
            background-color: #fcfbf8;
        }

        .col-datetime {
            font-weight: 800;
            color: #243429;
            line-height: 1.35;
        }

        .col-datetime small {
            display: block;
            font-size: 12px;
            font-weight: 500;
            color: #77857a;
        }

        .col-pet strong {
            color: #243429;
            font-weight: 700;
        }

        .col-pet small,
        .col-owner small {
            display: block;
            font-size: 12px;
            color: #77857a;
            margin-top: 2px;
        }

        .staff-unassigned {
            color: #8c988f;
            font-style: italic;
            font-size: 13px;
        }

        /* NHÃN TRẠNG THÁI */
        .status-label {
            font-weight: 700;
            font-size: 13px;
        }

        .st-pending { color: #a16207; }
        .st-confirmed { color: #2f6b3c; }
        .st-progress { color: #1d4ed8; }
        .st-done { color: #5b6b5f; }
        .st-cancelled { color: #b91c1c; }

        /* CỘT THAO TÁC */
        .actions-wrap {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-action {
            border: none;
            padding: 6px 11px;
            border-radius: 6px;
            font-size: 12.5px;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .btn-action:hover {
            opacity: 0.88;
        }

        .btn-confirm {
            background-color: #e6eee0;
            color: #2f6b3c;
        }

        .btn-start {
            background-color: #dbeafe;
            color: #1d4ed8;
        }

        .btn-finish {
            background-color: #556e57;
            color: #ffffff;
        }

        .btn-cancel {
            background-color: #fee2e2;
            color: #b91c1c;
        }

        .link-profile {
            font-size: 13px;
            font-weight: 700;
            color: #364f3c;
            text-decoration: none;
            margin-left: auto;
        }

        .link-profile:hover {
            text-decoration: underline;
        }

        .empty-table {
            padding: 36px 20px;
            text-align: center;
            color: #6b786e;
            font-size: 14px;
        }

        @media (max-width: 1120px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .table-header {
                display: none;
            }
            .table-row {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
        }

        @media (max-width: 780px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .search-form input {
                width: 180px;
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

            <div class="role-label">NHÂN VIÊN</div>

            <ul class="sidebar-menu">
                <li><a href="nhanvien_tongquan.php">Tổng quan ca làm</a></li>
                <li><a href="nhanvien_lichhen.php" class="active">Quản lý lịch hẹn</a></li>
                <li><a href="nhanvien_thucung.php">Hồ sơ thú cưng</a></li>
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
                <h1>Quản lý lịch hẹn</h1>
                <p>Kiểm tra lịch chăm sóc và cập nhật tiến độ dịch vụ.</p>
            </div>

            <div class="user-pill">
                <span class="status-dot"></span>
                <span>Xin chào, <?= htmlspecialchars($hoTenNV) ?></span>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= htmlspecialchars($success) ?></div>
        <?php endif; ?>

        <!-- 4 THẺ THỐNG KÊ -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Hôm nay</div>
                <div class="stat-value"><?= $soHomNay ?> lịch</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Chờ xác nhận</div>
                <div class="stat-value"><?= $soChoXacNhan ?> lịch</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Đang thực hiện</div>
                <div class="stat-value"><?= $soDangThucHien ?> bé</div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Hoàn thành</div>
                <div class="stat-value"><?= $soHoanThanh ?> lịch</div>
            </div>
        </div>

        <!-- THANH LỌC TRẠNG THÁI & TÌM KIẾM -->
        <div class="filter-toolbar">
            <div class="filter-tabs">
                <a href="nhanvien_lichhen.php?loc=tatca" class="filter-tab <?= $filter === 'tatca' ? 'active' : '' ?>">Tất cả</a>
                <a href="nhanvien_lichhen.php?loc=homnay" class="filter-tab <?= $filter === 'homnay' ? 'active' : '' ?>">Hôm nay</a>
                <a href="nhanvien_lichhen.php?loc=choxacnhan" class="filter-tab <?= $filter === 'choxacnhan' ? 'active' : '' ?>">Chờ xác nhận</a>
                <a href="nhanvien_lichhen.php?loc=daxacnhan" class="filter-tab <?= $filter === 'daxacnhan' ? 'active' : '' ?>">Đã xác nhận</a>
                <a href="nhanvien_lichhen.php?loc=dangthuchien" class="filter-tab <?= $filter === 'dangthuchien' ? 'active' : '' ?>">Đang thực hiện</a>
                <a href="nhanvien_lichhen.php?loc=hoanthanh" class="filter-tab <?= $filter === 'hoanthanh' ? 'active' : '' ?>">Hoàn thành</a>
            </div>

            <form method="GET" action="nhanvien_lichhen.php" class="search-form">
                <input type="hidden" name="loc" value="<?= htmlspecialchars($filter) ?>">
                <input type="text" name="q" placeholder="Tìm bé cưng, chủ nuôi, SĐT..." value="<?= htmlspecialchars($keyword) ?>">
                <button type="submit" class="btn-search">Tìm</button>
            </form>
        </div>

        <!-- BẢNG DANH SÁCH LỊCH HẸN -->
        <div class="table-card">
            <div class="table-header">
                <div>GIỜ & NGÀY</div>
                <div>THÚ CƯNG</div>
                <div>DỊCH VỤ</div>
                <div>CHỦ NUÔI</div>
                <div>PHỤ TRÁCH</div>
                <div>TRẠNG THÁI</div>
                <div>THAO TÁC</div>
            </div>

            <?php if (count($dsLichHen) > 0): ?>
                <?php foreach ($dsLichHen as $row): ?>
                    <?php
                        $gioHen  = date('H:i', strtotime($row['ThoiGianBD']));
                        $ngayHen = date('d/m/Y', strtotime($row['ThoiGianBD']));
                        $status  = $row['TrangThai'];
                    ?>
                    <div class="table-row">
                        <div class="col-datetime">
                            <?= $gioHen ?>
                            <small><?= $ngayHen ?></small>
                        </div>

                        <div class="col-pet">
                            <strong><?= htmlspecialchars($row['TenThuCung']) ?></strong>
                            <small><?= htmlspecialchars($row['Loai']) ?></small>
                        </div>

                        <div><?= htmlspecialchars($row['TenDichVu']) ?></div>

                        <div class="col-owner">
                            <?= htmlspecialchars($row['TenChuNuoi']) ?>
                            <small><?= htmlspecialchars($row['SDTChuNuoi']) ?></small>
                        </div>

                        <div>
                            <?php if (!empty($row['TenNhanVien'])): ?>
                                <?= htmlspecialchars($row['TenNhanVien']) ?>
                            <?php else: ?>
                                <span class="staff-unassigned">Chưa phân công</span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <span class="status-label <?= getStatusBadgeClass($status) ?>">
                                <?= htmlspecialchars($status) ?>
                            </span>
                        </div>

                        <div class="actions-wrap">
                            <?php if ($status === 'Chờ xác nhận'): ?>
                                <form method="POST" action="nhanvien_lichhen.php?<?= $queryKeep ?>" style="display:inline;">
                                    <input type="hidden" name="ma_lich_hen" value="<?= $row['MaLichHen'] ?>">
                                    <input type="hidden" name="action_type" value="xac_nhan">
                                    <button type="submit" class="btn-action btn-confirm">Xác nhận</button>
                                </form>
                                <form method="POST" action="nhanvien_lichhen.php?<?= $queryKeep ?>" style="display:inline;"
                                      onsubmit="return confirm('Xác nhận hủy lịch hẹn này?');">
                                    <input type="hidden" name="ma_lich_hen" value="<?= $row['MaLichHen'] ?>">
                                    <input type="hidden" name="action_type" value="huy_lich">
                                    <button type="submit" class="btn-action btn-cancel">Hủy</button>
                                </form>
                            <?php elseif ($status === 'Đã xác nhận'): ?>
                                <form method="POST" action="nhanvien_lichhen.php?<?= $queryKeep ?>" style="display:inline;">
                                    <input type="hidden" name="ma_lich_hen" value="<?= $row['MaLichHen'] ?>">
                                    <input type="hidden" name="action_type" value="bat_dau">
                                    <button type="submit" class="btn-action btn-start">Bắt đầu</button>
                                </form>
                                <form method="POST" action="nhanvien_lichhen.php?<?= $queryKeep ?>" style="display:inline;"
                                      onsubmit="return confirm('Xác nhận hủy lịch hẹn này?');">
                                    <input type="hidden" name="ma_lich_hen" value="<?= $row['MaLichHen'] ?>">
                                    <input type="hidden" name="action_type" value="huy_lich">
                                    <button type="submit" class="btn-action btn-cancel">Hủy</button>
                                </form>
                            <?php elseif ($status === 'Đang thực hiện'): ?>
                                <form method="POST" action="nhanvien_lichhen.php?<?= $queryKeep ?>" style="display:inline;">
                                    <input type="hidden" name="ma_lich_hen" value="<?= $row['MaLichHen'] ?>">
                                    <input type="hidden" name="action_type" value="hoan_thanh">
                                    <button type="submit" class="btn-action btn-finish">Hoàn thành</button>
                                </form>
                            <?php endif; ?>

                            <a href="nhanvien_thucung.php?pet_id=<?= $row['MaThuCung'] ?>" class="link-profile">
                                Mở hồ sơ →
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-table">
                    Không có lịch hẹn nào phù hợp với điều kiện lọc hiện tại.
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

</body>
</html>