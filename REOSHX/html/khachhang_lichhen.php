<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền truy cập Khách hàng
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Khách hàng' || empty($_SESSION['MaKhachHang'])) {
    header('Location: dangNhap.php');
    exit;
}

$maKH       = $_SESSION['MaKhachHang'];
$hoTen      = $_SESSION['HoTen'] ?? 'Khách hàng';
$filterMode = $_GET['trangthai'] ?? 'tatca';

// 1. Xử lý hủy lịch hẹn (chỉ cho phép hủy lịch của chính khách hàng khi còn Chờ xác nhận / Đã xác nhận)
if (isset($_GET['huy']) && is_numeric($_GET['huy'])) {
    $maHuy = (int)$_GET['huy'];
    $stmtCancel = $conn->prepare("
        UPDATE LichHen lh
        JOIN ThuCung tc ON lh.MaThuCung = tc.MaThuCung
        SET lh.TrangThai = 'Đã hủy'
        WHERE lh.MaLichHen = ? 
          AND tc.MaKhachHang = ? 
          AND lh.TrangThai IN ('Chờ xác nhận', 'Đã xác nhận')
    ");
    $stmtCancel->execute([$maHuy, $maKH]);
    header('Location: khachhang_lichhen.php?cancelled=1');
    exit;
}

// 2. Truy vấn danh sách lịch hẹn theo bộ lọc
$whereExtra = "";
if ($filterMode === 'saptoi') {
    $whereExtra = " AND lh.TrangThai IN ('Chờ xác nhận', 'Đã xác nhận', 'Đang thực hiện')";
} elseif ($filterMode === 'hoanthanh') {
    $whereExtra = " AND lh.TrangThai = 'Hoàn thành'";
} elseif ($filterMode === 'dahuy') {
    $whereExtra = " AND lh.TrangThai = 'Đã hủy'";
}

$sql = "
    SELECT lh.MaLichHen, lh.ThoiGianBD, lh.ThoiGianKT, lh.TrangThai,
           tc.TenThuCung, dv.TenDichVu, nv.HoTen AS TenNhanVien
    FROM LichHen lh
    JOIN ThuCung tc ON lh.MaThuCung = tc.MaThuCung
    JOIN DichVu dv ON lh.MaDichVu = dv.MaDichVu
    LEFT JOIN NhanVien nv ON lh.MaNhanVien = nv.MaNhanVien
    WHERE tc.MaKhachHang = ? $whereExtra
    ORDER BY lh.ThoiGianBD DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute([$maKH]);
$dsLichHen = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ($filterMode === 'hoanthanh') ? 'Lịch sử chăm sóc' : 'Lịch hẹn của tôi' ?> - Pet Care & Spa</title>
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

        .alert-success {
            background-color: #def7ec;
            color: #03543f;
            border: 1px solid #84e1bc;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 18px;
        }

        .info-banner-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 24px 28px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .info-banner-card h2 {
            font-size: 17.5px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .info-banner-card p {
            font-size: 13.5px;
            color: #77857a;
        }

        .filter-tabs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-tab {
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            padding: 8px 15px;
            border-radius: 8px;
            background-color: #faf7f2;
            color: #4f5e53;
            border: 1px solid #e6e4dd;
            transition: 0.2s;
        }

        .filter-tab:hover {
            background-color: #eef3eb;
        }

        .filter-tab.active {
            background-color: #556e57;
            color: #ffffff;
            border-color: #556e57;
        }

        .schedule-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 36px;
        }

        .schedule-header,
        .schedule-row {
            display: grid;
            grid-template-columns: 95px 1.1fr 1.5fr 1.2fr 1.2fr 1.1fr 135px;
            align-items: center;
            gap: 12px;
            padding: 14px 22px;
            border-radius: 10px;
        }

        .schedule-header {
            background-color: #e9eee4;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.6px;
            color: #6b786e;
            text-transform: uppercase;
        }

        .schedule-row {
            background-color: #ffffff;
            border: 1px solid #f0ede6;
            font-size: 13.5px;
            color: #2c3e32;
            transition: box-shadow 0.2s;
        }

        .schedule-row:hover {
            box-shadow: 0 4px 14px rgba(36, 52, 41, 0.05);
        }

        .col-code {
            font-weight: 800;
            color: #243429;
        }

        .col-staff.unassigned {
            color: #8c988f;
            font-style: italic;
        }

        .status-text {
            font-weight: 600;
            font-size: 13px;
        }

        .status-confirmed { color: #2f6b3c; }
        .status-pending   { color: #a16207; }
        .status-progress  { color: #1d4ed8; }
        .status-done      { color: #4f5e53; }
        .status-cancelled { color: #b91c1c; }

        .action-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-detail-sm {
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 600;
            color: #283b2e;
            background-color: #e8efe3;
            padding: 6px 10px;
            border-radius: 6px;
        }

        .btn-detail-sm:hover {
            background-color: #d7e3cf;
        }

        .btn-cancel-sm {
            text-decoration: none;
            font-size: 12.5px;
            font-weight: 600;
            color: #c81e1e;
            background-color: #fff5f5;
            border: 1px solid #f8b4b4;
            padding: 5px 10px;
            border-radius: 6px;
        }

        .btn-cancel-sm:hover {
            background-color: #fde8e8;
        }

        .change-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 26px 30px;
        }

        .change-card h3 {
            font-size: 17.5px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 6px;
        }

        .change-card p {
            font-size: 13.5px;
            color: #77857a;
            margin-bottom: 20px;
        }

        .change-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-primary {
            background-color: #556e57;
            color: #ffffff;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
        }

        .btn-primary:hover {
            background-color: #445946;
        }

        .btn-outline {
            background-color: #ffffff;
            color: #243429;
            border: 1px solid #d5ddd2;
            padding: 12px 22px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-outline:hover {
            background-color: #f3f6f1;
        }

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
            max-width: 480px;
            width: 100%;
            padding: 28px 30px;
            box-shadow: 0 14px 34px rgba(0, 0, 0, 0.18);
        }

        .modal-box h3 {
            font-size: 19px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 12px;
        }

        .modal-box ul {
            padding-left: 18px;
            color: #4f5e53;
            font-size: 14px;
            line-height: 1.65;
            margin-bottom: 22px;
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

        @media (max-width: 1024px) {
            .schedule-header { display: none; }
            .schedule-row { grid-template-columns: 1fr 1fr; gap: 8px; }
        }

        @media (max-width: 820px) {
            .layout { flex-direction: column; }
            .sidebar { width: 100%; }
        }
    </style>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
        <div>
            <div class="sidebar-brand">
                <div class="brand-title">Pet care</div>
                <div class="brand-sub">CHĂM SÓC BÉ CƯNG</div>
            </div>

            <div class="role-label">KHÁCH HÀNG</div>

            <ul class="sidebar-menu">
                <li><a href="KH_tongquan.php">Tổng quan</a></li>
                <li><a href="HS_thucung.php">Thú cưng của tôi</a></li>
                <li><a href="khachhang_datlich.php">Đặt lịch dịch vụ</a></li>
                <li><a href="khachhang_lichhen.php" class="<?= ($filterMode !== 'hoanthanh') ? 'active' : '' ?>">Lịch hẹn của tôi</a></li>
                <li><a href="khachhang_lichhen.php?trangthai=hoanthanh" class="<?= ($filterMode === 'hoanthanh') ? 'active' : '' ?>">Lịch sử chăm sóc</a></li>
                <li><a href="khachhang_hoso.php">Thông tin cá nhân</a></li>
            </ul>
        </div>

        <div class="sidebar-bottom">
            <a href="trangChu.php">Trang Chủ</a>
            <a href="dangXuat.php" class="logout-link">Đăng xuất</a>
        </div>
    </aside>

    <main class="main-content">
        <div class="top-bar">
            <div>
                <h1><?= ($filterMode === 'hoanthanh') ? 'Lịch sử chăm sóc' : 'Lịch hẹn của tôi' ?></h1>
                <p>Theo dõi và quản lý toàn bộ lịch hẹn chăm sóc thú cưng của bạn.</p>
            </div>
            <div class="user-pill">
                <span class="status-dot"></span>
                <?= htmlspecialchars($hoTen) ?>
            </div>
        </div>

        <?php if (isset($_GET['booked'])): ?>
            <div class="alert-success">Đặt lịch hẹn thành công! Nhân viên sẽ sớm xác nhận lịch của bé.</div>
        <?php endif; ?>

        <?php if (isset($_GET['cancelled'])): ?>
            <div class="alert-success">Đã hủy lịch hẹn thành công.</div>
        <?php endif; ?>

        <div class="info-banner-card">
            <div>
                <h2>Danh sách lịch hẹn</h2>
                <p>Lọc nhanh theo trạng thái xử lý của từng cuộc hẹn.</p>
            </div>

            <div class="filter-tabs">
                <a href="khachhang_lichhen.php" class="filter-tab <?= ($filterMode === 'tatca') ? 'active' : '' ?>">Tất cả</a>
                <a href="khachhang_lichhen.php?trangthai=saptoi" class="filter-tab <?= ($filterMode === 'saptoi') ? 'active' : '' ?>">Sắp tới</a>
                <a href="khachhang_lichhen.php?trangthai=hoanthanh" class="filter-tab <?= ($filterMode === 'hoanthanh') ? 'active' : '' ?>">Hoàn thành</a>
                <a href="khachhang_lichhen.php?trangthai=dahuy" class="filter-tab <?= ($filterMode === 'dahuy') ? 'active' : '' ?>">Đã hủy</a>
            </div>
        </div>

        <div class="schedule-list">
            <div class="schedule-header">
                <div>Mã lịch</div>
                <div>Thú cưng</div>
                <div>Dịch vụ</div>
                <div>Thời gian</div>
                <div>Nhân viên</div>
                <div>Trạng thái</div>
                <div>Thao tác</div>
            </div>

            <?php if (count($dsLichHen) > 0): ?>
                <?php foreach ($dsLichHen as $lh): ?>
                    <?php
                        $statusClass = 'status-pending';
                        if ($lh['TrangThai'] === 'Đã xác nhận') $statusClass = 'status-confirmed';
                        elseif ($lh['TrangThai'] === 'Đang thực hiện') $statusClass = 'status-progress';
                        elseif ($lh['TrangThai'] === 'Hoàn thành') $statusClass = 'status-done';
                        elseif ($lh['TrangThai'] === 'Đã hủy') $statusClass = 'status-cancelled';

                        $coTheHuy = in_array($lh['TrangThai'], ['Chờ xác nhận', 'Đã xác nhận']);
                    ?>
                    <div class="schedule-row">
                        <div class="col-code">LH<?= str_pad($lh['MaLichHen'], 3, '0', STR_PAD_LEFT) ?></div>
                        <div><?= htmlspecialchars($lh['TenThuCung']) ?></div>
                        <div><?= htmlspecialchars($lh['TenDichVu']) ?></div>
                        <div><?= date('H:i - d/m/Y', strtotime($lh['ThoiGianBD'])) ?></div>
                        <div class="col-staff <?= empty($lh['TenNhanVien']) ? 'unassigned' : '' ?>">
                            <?= !empty($lh['TenNhanVien']) ? htmlspecialchars($lh['TenNhanVien']) : 'Chưa phân công' ?>
                        </div>
                        <div>
                            <span class="status-text <?= $statusClass ?>">
                                <?= htmlspecialchars($lh['TrangThai']) ?>
                            </span>
                        </div>
                        <div class="action-group">
                            <a href="KH_chitietLH.php?id=<?= $lh['MaLichHen'] ?>" class="btn-detail-sm">Chi tiết</a>
                            <?php if ($coTheHuy): ?>
                                <a href="khachhang_lichhen.php?huy=<?= $lh['MaLichHen'] ?>"
                                   class="btn-cancel-sm"
                                   onclick="return confirm('Bạn có chắc chắn muốn hủy lịch hẹn này không?');">Hủy</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-row">
                    Không có lịch hẹn nào trong mục này.
                </div>
            <?php endif; ?>
        </div>

        <div class="change-card">
            <h3>Cần đặt lịch mới hoặc thay đổi thời gian?</h3>
            <p>Bạn có thể đặt thêm lịch chăm sóc cho bé hoặc xem quy định hủy lịch của Pet Care & Spa.</p>
            <div class="change-actions">
                <a href="khachhang_datlich.php" class="btn-primary">+ Đặt lịch mới</a>
                <button type="button" class="btn-outline" onclick="document.getElementById('policyModal').classList.add('open')">
                    Chính sách hủy lịch
                </button>
            </div>
        </div>
    </main>
</div>

<div class="modal-backdrop" id="policyModal" onclick="if(event.target===this) this.classList.remove('open')">
    <div class="modal-box">
        <h3>Chính sách hủy & dời lịch hẹn</h3>
        <ul>
            <li>Khách hàng có thể tự hủy lịch trực tuyến khi trạng thái là <strong>Chờ xác nhận</strong> hoặc <strong>Đã xác nhận</strong>.</li>
            <li>Vui lòng hủy trước giờ hẹn ít nhất 2 tiếng để nhân viên sắp xếp lại lịch chăm sóc.</li>
            <li>Nếu muốn đổi khung giờ, bạn hãy hủy lịch cũ và đặt lại khung giờ mới phù hợp.</li>
        </ul>
        <button type="button" class="btn-primary" onclick="document.getElementById('policyModal').classList.remove('open')">
            Đã hiểu
        </button>
    </div>
</div>

</body>
</html>