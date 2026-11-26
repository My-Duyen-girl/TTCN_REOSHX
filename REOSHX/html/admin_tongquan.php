<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền truy cập Admin
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Admin') {
    header('Location: dangNhap.php');
    exit;
}

$hoTenAdmin = $_SESSION['HoTen'] ?? 'Quản trị viên';

// 1. THỐNG KÊ DOANH THU THÁNG HIỆN TẠI (Tính từ các lịch hẹn đã Hoàn thành)
$stmtRevMonth = $conn->query("
    SELECT COALESCE(SUM(dv.GiaTien), 0) AS doanh_thu, COUNT(lh.MaLichHen) AS so_don
    FROM LichHen lh
    JOIN DichVu dv ON lh.MaDichVu = dv.MaDichVu
    WHERE lh.TrangThai = 'Hoàn thành'
      AND MONTH(lh.ThoiGianBD) = MONTH(CURDATE())
      AND YEAR(lh.ThoiGianBD) = YEAR(CURDATE())
");
$revData = $stmtRevMonth->fetch();
$doanhThuThang = (float)($revData['doanh_thu'] ?? 0);
$soDonHoanThanhThang = (int)($revData['so_don'] ?? 0);

// 2. THỐNG KÊ LỊCH HẸN
$tongLichHen = (int)$conn->query("
    SELECT COUNT(*) FROM LichHen WHERE TrangThai != 'Đã hủy'
")->fetchColumn();

$lichHomNay = (int)$conn->query("
    SELECT COUNT(*) FROM LichHen 
    WHERE DATE(ThoiGianBD) = CURDATE() AND TrangThai != 'Đã hủy'
")->fetchColumn();

// 3. THỐNG KÊ KHÁCH HÀNG & THÚ CƯNG
$tongKhachHang = (int)$conn->query("SELECT COUNT(*) FROM KhachHang")->fetchColumn();
$tongThuCung   = (int)$conn->query("SELECT COUNT(*) FROM ThuCung")->fetchColumn();

// 4. THỐNG KÊ NHÂN VIÊN HOẠT ĐỘNG
$tongNhanVien = (int)$conn->query("SELECT COUNT(*) FROM NhanVien")->fetchColumn();
$nvDangNhanLich = (int)$conn->query("
    SELECT COUNT(DISTINCT MaNhanVien) FROM LichHen 
    WHERE MaNhanVien IS NOT NULL AND TrangThai IN ('Đã xác nhận', 'Đang thực hiện')
")->fetchColumn();

// 5. DOANH THU THEO TUẦN HIỆN TẠI (T2 -> CN)
$nhanThu = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
$doanhThuTuan = array_fill(0, 7, 0);

$stmtWeek = $conn->query("
    SELECT WEEKDAY(lh.ThoiGianBD) AS thu_idx, COALESCE(SUM(dv.GiaTien), 0) AS tong_tien
    FROM LichHen lh
    JOIN DichVu dv ON lh.MaDichVu = dv.MaDichVu
    WHERE lh.TrangThai = 'Hoàn thành'
      AND YEARWEEK(lh.ThoiGianBD, 1) = YEARWEEK(CURDATE(), 1)
    GROUP BY WEEKDAY(lh.ThoiGianBD)
");
foreach ($stmtWeek->fetchAll() as $row) {
    $idx = (int)$row['thu_idx'];
    if ($idx >= 0 && $idx <= 6) {
        $doanhThuTuan[$idx] = (float)$row['tong_tien'];
    }
}
$maxWeekRev = max($doanhThuTuan);
$todayIdx   = (int)date('N') - 1; // 0 = T2 ... 6 = CN

// 6. PHÂN BỐ TRẠNG THÁI LỊCH HẸN
$statusCounts = [
    'Hoàn thành'     => 0,
    'Đã xác nhận'    => 0,
    'Đang thực hiện' => 0,
    'Chờ xác nhận'   => 0
];

$stmtStatus = $conn->query("
    SELECT TrangThai, COUNT(*) AS sl
    FROM LichHen
    WHERE TrangThai != 'Đã hủy'
    GROUP BY TrangThai
");
foreach ($stmtStatus->fetchAll() as $stRow) {
    $stName = $stRow['TrangThai'];
    if (isset($statusCounts[$stName])) {
        $statusCounts[$stName] = (int)$stRow['sl'];
    }
}
$tongTrangThai = array_sum($statusCounts);

function calcPercent($part, $total) {
    if ($total <= 0) return 0;
    return round(($part / $total) * 100);
}

// 7. VIỆC CẦN XỬ LÝ
$lichChoDuyet = $statusCounts['Chờ xác nhận'];
$lichChuaPhanCong = (int)$conn->query("
    SELECT COUNT(*) FROM LichHen 
    WHERE MaNhanVien IS NULL AND TrangThai IN ('Chờ xác nhận', 'Đã xác nhận')
")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bảng điều khiển quản trị</title>
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

        /* ================= 4 THẺ THỐNG KÊ ================= */
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
            padding: 20px 22px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .stat-title {
            font-size: 14.5px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .stat-sub {
            font-size: 12.5px;
            color: #77857a;
            margin-bottom: 14px;
        }

        .stat-value {
            font-size: 25px;
            font-weight: 800;
            color: #4f6653;
            line-height: 1.15;
        }

        /* ================= BIỂU ĐỒ TUẦN & PHÂN BỐ TRẠNG THÁI ================= */
        .analytics-grid {
            display: grid;
            grid-template-columns: 1.55fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }

        .panel-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 24px 28px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .panel-title {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .panel-sub {
            font-size: 12.5px;
            color: #77857a;
            margin-bottom: 20px;
        }

        /* Biểu đồ cột 7 ngày */
        .bar-chart {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 16px;
            align-items: end;
            height: 185px;
            padding-top: 16px;
        }

        .bar-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            height: 100%;
            gap: 10px;
        }

        .bar-fill {
            width: 34px;
            background-color: #e2eadc;
            border-radius: 7px;
            transition: background-color 0.2s, transform 0.2s;
            position: relative;
        }

        .bar-fill:hover {
            transform: translateY(-3px);
            background-color: #b7c8ae;
        }

        .bar-fill.today {
            background-color: #4c6451;
        }

        .bar-label {
            font-size: 12px;
            font-weight: 700;
            color: #4f5e53;
        }

        /* Phân bố trạng thái lịch */
        .status-dist-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-top: 18px;
        }

        .status-dist-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
        }

        .status-pill-bar {
            flex: 1;
            padding: 10px 16px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            color: #243429;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .pill-done {
            background-color: #e2eadc;
        }

        .pill-confirmed {
            background-color: #d8e2e6;
        }

        .pill-progress {
            background-color: #e4e2f2;
        }

        .pill-pending {
            background-color: #f3e7c9;
        }

        .status-percent {
            width: 46px;
            text-align: right;
            font-size: 14px;
            font-weight: 800;
            color: #243429;
        }

        /* ================= KHUNG VIỆC CẦN XỬ LÝ ================= */
        .todo-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 24px 30px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }

        .todo-info h3 {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 12px;
        }

        .todo-list {
            padding-left: 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 14px;
            color: #36473b;
        }

        .btn-manage-center {
            background-color: #556e57;
            color: #ffffff;
            text-decoration: none;
            padding: 13px 28px;
            border-radius: 9px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
            transition: background-color 0.2s;
        }

        .btn-manage-center:hover {
            background-color: #445946;
        }

        @media (max-width: 1120px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .analytics-grid {
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
            .stats-grid {
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
                <li><a href="admin_tongquan.php" class="active">Tổng quan</a></li>
                <li><a href="admin_taikhoan.php">Quản lý tài khoản</a></li>
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
                <h1>Bảng điều khiển quản trị</h1>
                <p>Xin chào! Đây là khu vực admin của bạn.</p>
            </div>

            <div class="user-pill">
                <span class="status-dot"></span>
                <span>Xin chào, <?= htmlspecialchars($hoTenAdmin) ?></span>
            </div>
        </div>

        <!-- 4 THẺ THỐNG KÊ TỔNG QUAN -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-title">Doanh thu tháng</div>
                <div class="stat-sub">Từ <?= $soDonHoanThanhThang ?> lịch đã hoàn thành</div>
                <div class="stat-value"><?= number_format($doanhThuThang, 0, ',', '.') ?>đ</div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Lịch hẹn</div>
                <div class="stat-sub"><?= $lichHomNay ?> lịch trong hôm nay</div>
                <div class="stat-value"><?= number_format($tongLichHen, 0, ',', '.') ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Khách hàng</div>
                <div class="stat-sub"><?= number_format($tongThuCung, 0, ',', '.') ?> thú cưng đã đăng ký</div>
                <div class="stat-value"><?= number_format($tongKhachHang, 0, ',', '.') ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Nhân viên hoạt động</div>
                <div class="stat-sub"><?= $nvDangNhanLich ?> đang nhận lịch</div>
                <div class="stat-value"><?= $tongNhanVien ?></div>
            </div>
        </div>

        <!-- BIỂU ĐỒ DOANH THU TUẦN & PHÂN BỐ TRẠNG THÁI -->
        <div class="analytics-grid">
            <!-- Cột trái: Biểu đồ doanh thu tuần -->
            <div class="panel-card">
                <div class="panel-title">Doanh thu theo tuần</div>
                <div class="panel-sub">Thống kê từ Thứ 2 đến Chủ Nhật tuần hiện tại (rê chuột vào cột để xem chi tiết)</div>

                <div class="bar-chart">
                    <?php foreach ($nhanThu as $i => $thuLabel): ?>
                        <?php
                            $val = $doanhThuTuan[$i];
                            // Tính chiều cao cột (tối thiểu 26px để giữ bố cục cân đối khi ngày chưa có đơn)
                            if ($maxWeekRev > 0 && $val > 0) {
                                $heightPx = max(32, round(($val / $maxWeekRev) * 145));
                            } else {
                                $heightPx = 26;
                            }
                            $isToday = ($i === $todayIdx);
                        ?>
                        <div class="bar-col">
                            <div class="bar-fill <?= $isToday ? 'today' : '' ?>"
                                 style="height: <?= $heightPx ?>px;"
                                 title="<?= $thuLabel ?>: <?= number_format($val, 0, ',', '.') ?>đ">
                            </div>
                            <span class="bar-label"><?= $thuLabel ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Cột phải: Phân bố trạng thái lịch -->
            <div class="panel-card">
                <div class="panel-title">Phân bố trạng thái lịch</div>

                <div class="status-dist-list">
                    <div class="status-dist-item">
                        <div class="status-pill-bar pill-done">
                            <span>Đã hoàn thành</span>
                            <span>(<?= $statusCounts['Hoàn thành'] ?>)</span>
                        </div>
                        <div class="status-percent"><?= calcPercent($statusCounts['Hoàn thành'], $tongTrangThai) ?>%</div>
                    </div>

                    <div class="status-dist-item">
                        <div class="status-pill-bar pill-confirmed">
                            <span>Đã xác nhận</span>
                            <span>(<?= $statusCounts['Đã xác nhận'] ?>)</span>
                        </div>
                        <div class="status-percent"><?= calcPercent($statusCounts['Đã xác nhận'], $tongTrangThai) ?>%</div>
                    </div>

                    <div class="status-dist-item">
                        <div class="status-pill-bar pill-progress">
                            <span>Đang thực hiện</span>
                            <span>(<?= $statusCounts['Đang thực hiện'] ?>)</span>
                        </div>
                        <div class="status-percent"><?= calcPercent($statusCounts['Đang thực hiện'], $tongTrangThai) ?>%</div>
                    </div>

                    <div class="status-dist-item">
                        <div class="status-pill-bar pill-pending">
                            <span>Chờ xác nhận</span>
                            <span>(<?= $statusCounts['Chờ xác nhận'] ?>)</span>
                        </div>
                        <div class="status-percent"><?= calcPercent($statusCounts['Chờ xác nhận'], $tongTrangThai) ?>%</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- VIỆC CẦN XỬ LÝ -->
        <div class="todo-card">
            <div class="todo-info">
                <h3>Việc cần xử lý</h3>
                <ul class="todo-list">
                    <li><strong><?= $lichChoDuyet ?></strong> yêu cầu đặt lịch mới cần duyệt</li>
                    <li><strong><?= $lichChuaPhanCong ?></strong> lịch hẹn chưa phân công nhân viên phụ trách</li>
                </ul>
            </div>

            <a href="admin_lichhen.php" class="btn-manage-center">Mở trung tâm quản lý</a>
        </div>
    </main>
</div>

</body>
</html>