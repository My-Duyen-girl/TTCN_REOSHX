<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền truy cập Nhân viên
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Nhân viên' || empty($_SESSION['MaNhanVien'])) {
    header('Location: dangNhap.php');
    exit;
}

$maNV  = (int)$_SESSION['MaNhanVien'];
$hoTen = $_SESSION['HoTen'] ?? 'Nhân viên';
$error = '';
$success = '';

// 1. XỬ LÝ CẬP NHẬT TRẠNG THÁI NHANH NGAY TRÊN CA LÀM
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ma_lich_hen'], $_POST['step_action'])) {
    $maLH   = (int)$_POST['ma_lich_hen'];
    $action = $_POST['step_action'];

    if ($action === 'xac_nhan') {
        $stmtUpd = $conn->prepare("
            UPDATE LichHen 
            SET TrangThai = 'Đã xác nhận', MaNhanVien = ? 
            WHERE MaLichHen = ? AND TrangThai = 'Chờ xác nhận'
        ");
        $stmtUpd->execute([$maNV, $maLH]);
        if ($stmtUpd->rowCount() > 0) {
            $success = 'Đã xác nhận lịch hẹn PC-' . str_pad($maLH, 4, '0', STR_PAD_LEFT) . ' và nhận phụ trách!';
        }
    } elseif ($action === 'bat_dau') {
        $stmtUpd = $conn->prepare("
            UPDATE LichHen 
            SET TrangThai = 'Đang thực hiện', MaNhanVien = COALESCE(MaNhanVien, ?) 
            WHERE MaLichHen = ? AND TrangThai = 'Đã xác nhận'
        ");
        $stmtUpd->execute([$maNV, $maLH]);
        if ($stmtUpd->rowCount() > 0) {
            $success = 'Đã chuyển lịch hẹn PC-' . str_pad($maLH, 4, '0', STR_PAD_LEFT) . ' sang trạng thái Đang thực hiện!';
        }
    } elseif ($action === 'hoan_thanh') {
        $stmtUpd = $conn->prepare("
            UPDATE LichHen 
            SET TrangThai = 'Hoàn thành' 
            WHERE MaLichHen = ? AND TrangThai = 'Đang thực hiện'
        ");
        $stmtUpd->execute([$maLH]);
        if ($stmtUpd->rowCount() > 0) {
            $success = 'Đã hoàn thành dịch vụ cho lịch hẹn PC-' . str_pad($maLH, 4, '0', STR_PAD_LEFT) . '!';
        }
    }
}

// 2. THỐNG KÊ 4 THẺ TỔNG QUAN
// Thẻ 1: Tổng lịch hẹn trong ngày hôm nay (không tính lịch đã hủy)
$stmtToday = $conn->query("
    SELECT COUNT(*) FROM LichHen 
    WHERE DATE(ThoiGianBD) = CURDATE() AND TrangThai != 'Đã hủy'
");
$soLichHomNay = (int)$stmtToday->fetchColumn();

// Thẻ 2: Lịch đang chờ xác nhận (toàn hệ thống để nhân viên duyệt kịp thời)
$stmtPending = $conn->query("
    SELECT COUNT(*) FROM LichHen 
    WHERE TrangThai = 'Chờ xác nhận'
");
$soChoXacNhan = (int)$stmtPending->fetchColumn();

// Thẻ 3: Lịch đang thực hiện
$stmtInProgress = $conn->query("
    SELECT COUNT(*) FROM LichHen 
    WHERE TrangThai = 'Đang thực hiện'
");
$soDangThucHien = (int)$stmtInProgress->fetchColumn();

// Thẻ 4: Lịch đã hoàn thành trong hôm nay
$stmtDoneToday = $conn->query("
    SELECT COUNT(*) FROM LichHen 
    WHERE DATE(ThoiGianBD) = CURDATE() AND TrangThai = 'Hoàn thành'
");
$soHoanThanh = (int)$stmtDoneToday->fetchColumn();

// 3. LẤY DANH SÁCH LỊCH HẸN TRONG CA HÔM NAY + CÁC ĐƠN ĐANG CHỜ XÁC NHẬN
$stmtList = $conn->query("
    SELECT lh.MaLichHen, lh.ThoiGianBD, lh.TrangThai, lh.MaNhanVien,
           tc.MaThuCung, tc.TenThuCung, tc.Loai,
           dv.TenDichVu, kh.HoTen AS TenKhachHang
    FROM LichHen lh
    JOIN ThuCung tc ON lh.MaThuCung = tc.MaThuCung
    JOIN KhachHang kh ON tc.MaKhachHang = kh.MaKhachHang
    JOIN DichVu dv ON lh.MaDichVu = dv.MaDichVu
    WHERE (DATE(lh.ThoiGianBD) = CURDATE() AND lh.TrangThai != 'Đã hủy')
       OR lh.TrangThai = 'Chờ xác nhận'
    ORDER BY lh.ThoiGianBD ASC
    LIMIT 10
");
$dsCaLam = $stmtList->fetchAll();

// Định dạng ngày hiện tại tiếng Việt
$thuViet = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
$ngayHienTaiText = $thuViet[(int)date('w')] . ', ' . date('d/m/Y');

function getStaffStatusClass($status) {
    switch ($status) {
        case 'Chờ xác nhận':
            return 'status-pending';
        case 'Đã xác nhận':
            return 'status-confirmed';
        case 'Đang thực hiện':
            return 'status-progress';
        case 'Hoàn thành':
            return 'status-done';
        default:
            return '';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tổng quan ca làm - Pet Care & Spa</title>
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
            max-width: 1220px;
        }

        .top-bar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 26px;
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

        /* ================= 4 THẺ THỐNG KÊ CA LÀM ================= */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 24px;
        }

        .stat-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 22px 24px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.02);
        }

        .stat-label {
            font-size: 14px;
            font-weight: 700;
            color: #243429;
            margin-bottom: 14px;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 800;
            color: #4f6653;
            line-height: 1;
        }

        /* ================= KHUNG LỊCH LÀM VIỆC ================= */
        .shift-banner {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 22px 28px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        .shift-title {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 6px;
        }

        .shift-hours {
            font-size: 13.5px;
            color: #77857a;
        }

        .btn-all-schedule {
            background-color: #faf7f2;
            color: #243429;
            border: 1px solid #dcded7;
            text-decoration: none;
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .btn-all-schedule:hover {
            background-color: #eef3eb;
        }

        /* ================= BẢNG LỊCH HẸN TRONG CA ================= */
        .schedule-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 24px;
        }

        .schedule-header,
        .schedule-row {
            display: grid;
            grid-template-columns: 115px 1.2fr 1.3fr 1.2fr 1.1fr 165px;
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

        .col-time {
            font-weight: 800;
            color: #243429;
            line-height: 1.35;
        }

        .col-time small {
            display: block;
            font-size: 11.5px;
            font-weight: 500;
            color: #77857a;
        }

        /* NHÃN TRẠNG THÁI */
        .status-text {
            font-weight: 600;
            font-size: 13px;
        }

        .status-pending {
            color: #a16207;
        }

        .status-confirmed {
            color: #2f6b3c;
        }

        .status-progress {
            color: #1d4ed8;
        }

        .status-done {
            color: #5b6b5f;
        }

        /* THAO TÁC TRÊN TỪNG DÒNG */
        .action-cell {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-step-action {
            background: none;
            border: none;
            padding: 0;
            font-size: 13.5px;
            font-weight: 700;
            color: #243429;
            cursor: pointer;
            transition: color 0.2s;
        }

        .btn-step-action:hover {
            color: #4c6451;
            text-decoration: underline;
        }

        .btn-step-action.confirm-color {
            color: #2f6b3c;
        }

        .btn-step-action.progress-color {
            color: #1d4ed8;
        }

        .link-pet-profile {
            font-size: 13px;
            font-weight: 600;
            color: #6b786e;
            text-decoration: none;
        }

        .link-pet-profile:hover {
            color: #243429;
            text-decoration: underline;
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

        /* ================= KHUNG NHẮC VIỆC BÊN DƯỚI ================= */
        .reminder-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 24px 28px;
        }

        .reminder-card h3 {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 6px;
        }

        .reminder-card p {
            font-size: 13.5px;
            color: #77857a;
            line-height: 1.55;
        }

        @media (max-width: 1040px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .schedule-header {
                display: none;
            }
            .schedule-row {
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
                <li><a href="nhanvien_tongquan.php" class="active">Tổng quan ca làm</a></li>
                <li><a href="nhanvien_lichhen.php">Quản lý lịch hẹn</a></li>
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
                <h1>Tổng quan ca làm</h1>
                <p>Xin chào! Đây là khu vực nhân viên của bạn.</p>
            </div>

            <div class="user-pill">
                <span class="status-dot"></span>
                <span>Xin chào, <?= htmlspecialchars($hoTen) ?></span>
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
                <div class="stat-label">Lịch hôm nay</div>
                <div class="stat-number"><?= str_pad($soLichHomNay, 2, '0', STR_PAD_LEFT) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Chờ xác nhận</div>
                <div class="stat-number"><?= str_pad($soChoXacNhan, 2, '0', STR_PAD_LEFT) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Đang thực hiện</div>
                <div class="stat-number"><?= str_pad($soDangThucHien, 2, '0', STR_PAD_LEFT) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Đã hoàn thành</div>
                <div class="stat-number"><?= str_pad($soHoanThanh, 2, '0', STR_PAD_LEFT) ?></div>
            </div>
        </div>

        <!-- KHUNG LỊCH LÀM VIỆC -->
        <div class="shift-banner">
            <div>
                <div class="shift-title">Lịch làm việc · <?= htmlspecialchars($ngayHienTaiText) ?></div>
                <div class="shift-hours">Ca sáng 08:00–12:00 · Ca chiều 13:00–17:00</div>
            </div>

            <a href="nhanvien_lichhen.php" class="btn-all-schedule">Xem tất cả lịch hẹn →</a>
        </div>

        <!-- BẢNG DANH SÁCH LỊCH HẸN TRONG CA / CHỜ DUYỆT -->
        <div class="schedule-list">
            <div class="schedule-header">
                <div>GIỜ</div>
                <div>THÚ CƯNG</div>
                <div>DỊCH VỤ</div>
                <div>KHÁCH HÀNG</div>
                <div>TRẠNG THÁI</div>
                <div>THAO TÁC</div>
            </div>

            <?php if (count($dsCaLam) > 0): ?>
                <?php foreach ($dsCaLam as $row): ?>
                    <?php
                        $gioHen  = date('H:i', strtotime($row['ThoiGianBD']));
                        $ngayHen = date('d/m', strtotime($row['ThoiGianBD']));
                        $isToday = (date('Y-m-d', strtotime($row['ThoiGianBD'])) === date('Y-m-d'));
                        $status  = $row['TrangThai'];
                    ?>
                    <div class="schedule-row">
                        <div class="col-time">
                            <?= $gioHen ?>
                            <?php if (!$isToday): ?>
                                <small><?= $ngayHen ?></small>
                            <?php endif; ?>
                        </div>

                        <div>
                            <?= htmlspecialchars($row['TenThuCung']) ?> · <?= htmlspecialchars($row['Loai']) ?>
                        </div>

                        <div><?= htmlspecialchars($row['TenDichVu']) ?></div>

                        <div><?= htmlspecialchars($row['TenKhachHang']) ?></div>

                        <div>
                            <span class="status-text <?= getStaffStatusClass($status) ?>">
                                <?= htmlspecialchars($status) ?>
                            </span>
                        </div>

                        <div class="action-cell">
                            <?php if ($status === 'Chờ xác nhận'): ?>
                                <form method="POST" action="nhanvien_tongquan.php" style="display:inline;">
                                    <input type="hidden" name="ma_lich_hen" value="<?= $row['MaLichHen'] ?>">
                                    <input type="hidden" name="step_action" value="xac_nhan">
                                    <button type="submit" class="btn-step-action confirm-color">Xác nhận →</button>
                                </form>
                            <?php elseif ($status === 'Đã xác nhận'): ?>
                                <form method="POST" action="nhanvien_tongquan.php" style="display:inline;">
                                    <input type="hidden" name="ma_lich_hen" value="<?= $row['MaLichHen'] ?>">
                                    <input type="hidden" name="step_action" value="bat_dau">
                                    <button type="submit" class="btn-step-action progress-color">Bắt đầu →</button>
                                </form>
                            <?php elseif ($status === 'Đang thực hiện'): ?>
                                <form method="POST" action="nhanvien_tongquan.php" style="display:inline;">
                                    <input type="hidden" name="ma_lich_hen" value="<?= $row['MaLichHen'] ?>">
                                    <input type="hidden" name="step_action" value="hoan_thanh">
                                    <button type="submit" class="btn-step-action">Hoàn thành →</button>
                                </form>
                            <?php endif; ?>

                            <a href="nhanvien_thucung.php?pet_id=<?= $row['MaThuCung'] ?>" class="link-pet-profile">
                                Hồ sơ
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-row">
                    Hiện chưa có lịch hẹn nào trong ca làm hôm nay hoặc đang chờ xác nhận.
                </div>
            <?php endif; ?>
        </div>

        <!-- KHUNG NHẮC VIỆC -->
        <div class="reminder-card">
            <h3>Nhắc việc</h3>
            <p>
                Hoàn thành cập nhật ghi chú chăm sóc trong hồ sơ thú cưng trước khi kết thúc dịch vụ.
                <?php if ($soChoXacNhan > 0): ?>
                    Hiện còn <strong><?= $soChoXacNhan ?> lịch hẹn</strong> đang chờ xác nhận.
                <?php else: ?>
                    Kiểm tra lại các lịch hẹn sắp tới để chuẩn bị dụng cụ chăm sóc.
                <?php endif; ?>
            </p>
        </div>
    </main>
</div>

</body>
</html>