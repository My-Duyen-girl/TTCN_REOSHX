<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền truy cập Admin
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Admin') {
    header('Location: dangNhap.php');
    exit;
}

$hoTenAdmin = $_SESSION['HoTen'] ?? 'Quản trị viên';

// 1. NHẬN THAM SỐ BỘ LỌC BÁO CÁO
$tuNgay      = $_GET['tu_ngay'] ?? date('Y-m-01');
$denNgay     = $_GET['den_ngay'] ?? date('Y-m-d');
$loaiBaoCao  = $_GET['loai_bao_cao'] ?? 'doanhthu';

// Chuẩn hóa nếu người dùng chọn Từ ngày lớn hơn Đến ngày
if ($tuNgay > $denNgay) {
    $tmp     = $tuNgay;
    $tuNgay  = $denNgay;
    $denNgay = $tmp;
}

// 2. TRUY VẤN CÁC CHỈ SỐ TỔNG HỢP TRONG KỲ
$stmtSummary = $conn->prepare("
    SELECT
        COUNT(*) AS tong_lich,
        SUM(CASE WHEN lh.TrangThai = 'Hoàn thành' THEN 1 ELSE 0 END) AS lich_hoan_thanh,
        SUM(CASE WHEN lh.TrangThai = 'Đã hủy' THEN 1 ELSE 0 END) AS lich_huy,
        COALESCE(SUM(CASE WHEN lh.TrangThai = 'Hoàn thành' THEN dv.GiaTien ELSE 0 END), 0) AS tong_doanh_thu
    FROM lichhen lh
    JOIN dichvu dv ON lh.MaDichVu = dv.MaDichVu
    WHERE DATE(lh.ThoiGianBD) BETWEEN ? AND ?
");
$stmtSummary->execute([$tuNgay, $denNgay]);
$summary = $stmtSummary->fetch();

$tongLich       = (int)($summary['tong_lich'] ?? 0);
$lichHoanThanh  = (int)($summary['lich_hoan_thanh'] ?? 0);
$lichHuy        = (int)($summary['lich_huy'] ?? 0);
$tongDoanhThu   = (float)($summary['tong_doanh_thu'] ?? 0);

$tyLeHoanThanh  = $tongLich > 0 ? round(($lichHoanThanh / $tongLich) * 100, 1) : 0;
$tyLeHuy        = $tongLich > 0 ? round(($lichHuy / $tongLich) * 100, 1) : 0;

// 3. TÍNH TỶ LỆ KHÁCH HÀNG QUAY LẠI (Khách có >= 2 lịch hẹn trong hệ thống)
$stmtRepeat = $conn->prepare("
    SELECT
        COUNT(DISTINCT tc.MaKhachHang) AS tong_khach_ky,
        COUNT(DISTINCT CASE WHEN kh_cnt.tong_don >= 2 THEN tc.MaKhachHang END) AS khach_quay_lai
    FROM lichhen lh
    JOIN thucung tc ON lh.MaThuCung = tc.MaThuCung
    JOIN (
        SELECT tc2.MaKhachHang, COUNT(*) AS tong_don
        FROM lichhen lh2
        JOIN thucung tc2 ON lh2.MaThuCung = tc2.MaThuCung
        WHERE lh2.TrangThai != 'Đã hủy'
        GROUP BY tc2.MaKhachHang
    ) kh_cnt ON tc.MaKhachHang = kh_cnt.MaKhachHang
    WHERE DATE(lh.ThoiGianBD) BETWEEN ? AND ?
      AND lh.TrangThai != 'Đã hủy'
");
$stmtRepeat->execute([$tuNgay, $denNgay]);
$repeatData   = $stmtRepeat->fetch();
$tongKhachKy  = (int)($repeatData['tong_khach_ky'] ?? 0);
$khachQuayLai = (int)($repeatData['khach_quay_lai'] ?? 0);
$tyLeQuayLai  = $tongKhachKy > 0 ? round(($khachQuayLai / $tongKhachKy) * 100) : 0;

// 4. DOANH THU & LƯỢT ĐẶT THEO DỊCH VỤ TRONG KỲ
$stmtByService = $conn->prepare("
    SELECT dv.MaDichVu, dv.TenDichVu, dv.GiaTien,
           COUNT(CASE WHEN lh.TrangThai != 'Đã hủy' AND DATE(lh.ThoiGianBD) BETWEEN ? AND ? THEN lh.MaLichHen END) AS SoLuot,
           COUNT(CASE WHEN lh.TrangThai = 'Hoàn thành' AND DATE(lh.ThoiGianBD) BETWEEN ? AND ? THEN lh.MaLichHen END) AS SoHoanThanh,
           COALESCE(SUM(CASE WHEN lh.TrangThai = 'Hoàn thành' AND DATE(lh.ThoiGianBD) BETWEEN ? AND ? THEN dv.GiaTien ELSE 0 END), 0) AS DoanhThu
    FROM dichvu dv
    LEFT JOIN lichhen lh ON dv.MaDichVu = lh.MaDichVu
    GROUP BY dv.MaDichVu, dv.TenDichVu, dv.GiaTien
    ORDER BY DoanhThu DESC, SoLuot DESC, dv.MaDichVu ASC
");
$stmtByService->execute([$tuNgay, $denNgay, $tuNgay, $denNgay, $tuNgay, $denNgay]);
$allServiceStats = $stmtByService->fetchAll();

// Xác định dịch vụ phổ biến nhất
$dichVuPhoBien = 'Chưa có dữ liệu';
if (count($allServiceStats) > 0 && (int)$allServiceStats[0]['SoLuot'] > 0) {
    // Tìm dịch vụ có số lượt đặt cao nhất
    $topByCount = $allServiceStats;
    usort($topByCount, function ($a, $b) {
        return (int)$b['SoLuot'] <=> (int)$a['SoLuot'];
    });
    $dichVuPhoBien = $topByCount[0]['TenDichVu'];
}

// Chuẩn bị 5 cột biểu đồ (4 dịch vụ dẫn đầu + nhóm "Khác" nếu có từ 5 dịch vụ trở lên)
$chartBars = [];
if (count($allServiceStats) <= 5) {
    foreach ($allServiceStats as $srv) {
        $chartBars[] = [
            'label'   => $srv['TenDichVu'],
            'revenue' => (float)$srv['DoanhThu'],
            'count'   => (int)$srv['SoLuot']
        ];
    }
} else {
    for ($i = 0; $i < 4; $i++) {
        $chartBars[] = [
            'label'   => $allServiceStats[$i]['TenDichVu'],
            'revenue' => (float)$allServiceStats[$i]['DoanhThu'],
            'count'   => (int)$allServiceStats[$i]['SoLuot']
        ];
    }
    $otherRev = 0;
    $otherCnt = 0;
    for ($i = 4; $i < count($allServiceStats); $i++) {
        $otherRev += (float)$allServiceStats[$i]['DoanhThu'];
        $otherCnt += (int)$allServiceStats[$i]['SoLuot'];
    }
    $chartBars[] = [
        'label'   => 'Khác',
        'revenue' => $otherRev,
        'count'   => $otherCnt
    ];
}

$maxChartRev = 0;
foreach ($chartBars as $b) {
    if ($b['revenue'] > $maxChartRev) {
        $maxChartRev = $b['revenue'];
    }
}

// 5. XỬ LÝ XUẤT FILE BÁO CÁO CSV (MỞ TRỰC TIẾP BẰNG EXCEL)
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $fileName = 'BaoCao_PetCare_' . $tuNgay . '_den_' . $denNgay . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');

    // Xuất UTF-8 BOM để Excel hiển thị chuẩn tiếng Việt có dấu
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    fputcsv($out, ['BÁO CÁO & THỐNG KÊ HOẠT ĐỘNG - PET CARE & SPA']);
    fputcsv($out, ['Từ ngày', date('d/m/Y', strtotime($tuNgay)), 'Đến ngày', date('d/m/Y', strtotime($denNgay))]);
    fputcsv($out, []);

    fputcsv($out, ['CHỈ SỐ TỔNG QUAN', 'GIÁ TRỊ']);
    fputcsv($out, ['Tổng doanh thu (đã hoàn thành)', number_format($tongDoanhThu, 0, ',', '.') . ' đ']);
    fputcsv($out, ['Tổng số lịch hẹn', $tongLich]);
    fputcsv($out, ['Số lịch hoàn thành', $lichHoanThanh]);
    fputcsv($out, ['Tỷ lệ hoàn thành', str_replace('.', ',', (string)$tyLeHoanThanh) . '%']);
    fputcsv($out, ['Tỷ lệ hủy', str_replace('.', ',', (string)$tyLeHuy) . '%']);
    fputcsv($out, ['Dịch vụ phổ biến', $dichVuPhoBien]);
    fputcsv($out, ['Khách hàng quay lại', $tyLeQuayLai . '%']);
    fputcsv($out, []);

    fputcsv($out, ['CHI TIẾT DOANH THU THEO DỊCH VỤ']);
    fputcsv($out, ['Tên dịch vụ', 'Đơn giá', 'Tổng lượt đặt', 'Lượt hoàn thành', 'Doanh thu thực thu']);
    foreach ($allServiceStats as $srvRow) {
        fputcsv($out, [
            $srvRow['TenDichVu'],
            number_format($srvRow['GiaTien'], 0, ',', '.') . ' đ',
            $srvRow['SoLuot'],
            $srvRow['SoHoanThanh'],
            number_format($srvRow['DoanhThu'], 0, ',', '.') . ' đ'
        ]);
    }

    fclose($out);
    exit;
}

$exportUrl = 'admin_baocao.php?tu_ngay=' . urlencode($tuNgay)
           . '&den_ngay=' . urlencode($denNgay)
           . '&loai_bao_cao=' . urlencode($loaiBaoCao)
           . '&export=csv';
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Báo cáo & thống kê</title>
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

        /* ================= BỘ LỌC BÁO CÁO ================= */
        .filter-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 22px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .filter-card h2 {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 16px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 1fr 1fr 1.1fr auto;
            gap: 16px;
            align-items: end;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filter-group label {
            font-size: 12.5px;
            font-weight: 700;
            color: #4f5e53;
        }

        .filter-group input,
        .filter-group select {
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

        .filter-group input:focus,
        .filter-group select:focus {
            border-color: #556e57;
        }

        .btn-create-report {
            background-color: #556e57;
            color: #ffffff;
            border: none;
            padding: 11px 28px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            transition: background-color 0.2s;
        }

        .btn-create-report:hover {
            background-color: #445946;
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
            border-radius: 14px;
            padding: 20px 22px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .stat-title {
            font-size: 14px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 14px;
        }

        .stat-value {
            font-size: 24px;
            font-weight: 800;
            color: #4f6653;
            line-height: 1.2;
        }

        .stat-value.text-mode {
            font-size: 20px;
        }

        /* ================= BIỂU ĐỒ & HIỆU QUẢ VẬN HÀNH ================= */
        .analytics-grid {
            display: grid;
            grid-template-columns: 1.55fr 1fr;
            gap: 20px;
            margin-bottom: 22px;
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
            margin-bottom: 18px;
        }

        /* Biểu đồ cột dịch vụ */
        .service-chart {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 18px;
            align-items: end;
            height: 205px;
            padding-top: 14px;
        }

        .service-col {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: flex-end;
            height: 100%;
            gap: 10px;
        }

        .service-bar {
            width: 48px;
            border-radius: 7px;
            transition: transform 0.2s, opacity 0.2s;
            cursor: pointer;
        }

        .service-bar:hover {
            transform: translateY(-3px);
            opacity: 0.9;
        }

        .bar-c0 { background-color: #4c6451; }
        .bar-c1 { background-color: #e2eadc; }
        .bar-c2 { background-color: #d8e2e6; }
        .bar-c3 { background-color: #dec37a; }
        .bar-c4 { background-color: #eadbd3; }

        .service-label {
            font-size: 12px;
            font-weight: 600;
            color: #4f5e53;
            line-height: 1.3;
            max-width: 92px;
            word-break: break-word;
        }

        /* Hiệu quả vận hành */
        .ops-sub-label {
            font-size: 13px;
            color: #77857a;
            margin-top: 14px;
            margin-bottom: 8px;
        }

        .ops-main-metric {
            font-size: 24px;
            font-weight: 800;
            color: #4f6653;
            margin-bottom: 14px;
        }

        .progress-track {
            width: 100%;
            height: 11px;
            background-color: #e9eee4;
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 20px;
        }

        .progress-fill {
            height: 100%;
            background-color: #4c6451;
            border-radius: 999px;
            transition: width 0.3s ease;
        }

        .ops-stat-line {
            font-size: 14px;
            color: #36473b;
            margin-bottom: 12px;
        }

        .ops-stat-line strong {
            color: #243429;
        }

        /* ================= HÀNG NÚT XUẤT BÁO CÁO ================= */
        .export-row {
            display: flex;
            justify-content: flex-end;
            margin-bottom: 24px;
        }

        .btn-export-report {
            background-color: #ffffff;
            color: #4f6653;
            border: 1px solid #d5ddd2;
            text-decoration: none;
            padding: 13px 44px;
            border-radius: 10px;
            font-size: 15px;
            font-weight: 800;
            display: inline-block;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.02);
            transition: background-color 0.2s, border-color 0.2s;
        }

        .btn-export-report:hover {
            background-color: #eef3eb;
            border-color: #556e57;
        }

        /* ================= BẢNG CHI TIẾT THEO LOẠI BÁO CÁO ================= */
        .detail-report-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 22px 26px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .detail-report-card h3 {
            font-size: 16.5px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 14px;
        }

        .report-table-head,
        .report-table-row {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr 1.1fr;
            align-items: center;
            gap: 12px;
            padding: 12px 18px;
            border-radius: 8px;
            font-size: 13.5px;
        }

        .report-table-head {
            background-color: #e9eee4;
            font-size: 11.5px;
            font-weight: 700;
            color: #6b786e;
            text-transform: uppercase;
            margin-bottom: 6px;
        }

        .report-table-row {
            border-bottom: 1px solid #f0ede6;
            color: #2c3e32;
        }

        .report-table-row:last-child {
            border-bottom: none;
        }

        @media (max-width: 1120px) {
            .filter-grid,
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
            .filter-grid,
            .stats-grid {
                grid-template-columns: 1fr;
            }
            .service-chart {
                gap: 10px;
            }
            .service-bar {
                width: 36px;
            }
            .report-table-head {
                display: none;
            }
            .report-table-row {
                grid-template-columns: 1fr 1fr;
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
                <li><a href="admin_nhanvien.php">Nhân viên</a></li>
                <li><a href="admin_baocao.php" class="active">Báo cáo thống kê</a></li>
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
                <h1>Báo cáo & thống kê</h1>
                <p>Xin chào! Đây là khu vực admin của bạn.</p>
            </div>

            <div class="user-pill">
                <span class="status-dot"></span>
                <span>Xin chào, <?= htmlspecialchars($hoTenAdmin) ?></span>
            </div>
        </div>

        <!-- BỘ LỌC BÁO CÁO -->
        <div class="filter-card">
            <h2>Bộ lọc báo cáo</h2>

            <form method="GET" action="admin_baocao.php" class="filter-grid">
                <div class="filter-group">
                    <label>Từ ngày</label>
                    <input type="date" name="tu_ngay" value="<?= htmlspecialchars($tuNgay) ?>" required>
                </div>

                <div class="filter-group">
                    <label>Đến ngày</label>
                    <input type="date" name="den_ngay" value="<?= htmlspecialchars($denNgay) ?>" required>
                </div>

                <div class="filter-group">
                    <label>Loại báo cáo</label>
                    <select name="loai_bao_cao">
                        <option value="doanhthu" <?= $loaiBaoCao === 'doanhthu' ? 'selected' : '' ?>>Doanh thu</option>
                        <option value="dichvu" <?= $loaiBaoCao === 'dichvu' ? 'selected' : '' ?>>Hiệu suất dịch vụ</option>
                        <option value="vanhanh" <?= $loaiBaoCao === 'vanhanh' ? 'selected' : '' ?>>Hiệu quả vận hành</option>
                    </select>
                </div>

                <div>
                    <button type="submit" class="btn-create-report">Tạo báo cáo</button>
                </div>
            </form>
        </div>

        <!-- 4 THẺ CHỈ SỐ TỔNG QUAN -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-title">Tổng doanh thu</div>
                <div class="stat-value"><?= number_format($tongDoanhThu, 0, ',', '.') ?>đ</div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Số lịch hẹn</div>
                <div class="stat-value"><?= number_format($tongLich, 0, ',', '.') ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Dịch vụ phổ biến</div>
                <div class="stat-value text-mode"><?= htmlspecialchars($dichVuPhoBien) ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Tỷ lệ hoàn thành</div>
                <div class="stat-value"><?= str_replace('.', ',', (string)$tyLeHoanThanh) ?>%</div>
            </div>
        </div>

        <!-- BIỂU ĐỒ DOANH THU THEO DỊCH VỤ & HIỆU QUẢ VẬN HÀNH -->
        <div class="analytics-grid">
            <!-- Cột trái: Biểu đồ doanh thu theo dịch vụ -->
            <div class="panel-card">
                <div class="panel-title">Doanh thu theo dịch vụ</div>
                <div class="panel-sub">Rê chuột vào từng cột để xem doanh thu và số lượt đặt trong kỳ</div>

                <div class="service-chart">
                    <?php if (count($chartBars) > 0): ?>
                        <?php foreach ($chartBars as $idx => $bar): ?>
                            <?php
                                if ($maxChartRev > 0 && $bar['revenue'] > 0) {
                                    $heightPx = max(34, round(($bar['revenue'] / $maxChartRev) * 155));
                                } else {
                                    $heightPx = 24;
                                }
                                $colorClass = 'bar-c' . ($idx % 5);
                            ?>
                            <div class="service-col">
                                <div class="service-bar <?= $colorClass ?>"
                                     style="height: <?= $heightPx ?>px;"
                                     title="<?= htmlspecialchars($bar['label']) ?>: <?= number_format($bar['revenue'], 0, ',', '.') ?>đ (<?= $bar['count'] ?> lượt)">
                                </div>
                                <span class="service-label"><?= htmlspecialchars($bar['label']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <p style="grid-column: 1 / -1; color: #77857a; font-size: 13.5px;">Chưa có dữ liệu dịch vụ.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Cột phải: Hiệu quả vận hành -->
            <div class="panel-card">
                <div class="panel-title">Hiệu quả vận hành</div>

                <div class="ops-sub-label">Lịch hoàn thành</div>
                <div class="ops-main-metric">
                    <?= number_format($lichHoanThanh, 0, ',', '.') ?> / <?= number_format($tongLich, 0, ',', '.') ?> lịch
                </div>

                <div class="progress-track">
                    <div class="progress-fill" style="width: <?= min(100, max(0, $tyLeHoanThanh)) ?>%;"></div>
                </div>

                <div class="ops-stat-line">
                    Tỷ lệ hủy: <strong><?= str_replace('.', ',', (string)$tyLeHuy) ?>%</strong>
                </div>

                <div class="ops-stat-line">
                    Khách hàng quay lại: <strong><?= $tyLeQuayLai ?>%</strong>
                </div>
            </div>
        </div>

        <!-- NÚT XUẤT BÁO CÁO -->
        <div class="export-row">
            <a href="<?= htmlspecialchars($exportUrl) ?>" class="btn-export-report">Xuất báo cáo</a>
        </div>

        <!-- BẢNG SỐ LIỆU CHI TIẾT THEO DỊCH VỤ -->
        <div class="detail-report-card">
            <h3>Bảng tổng hợp chi tiết từ <?= date('d/m/Y', strtotime($tuNgay)) ?> đến <?= date('d/m/Y', strtotime($denNgay)) ?></h3>

            <div class="report-table-head">
                <div>Tên dịch vụ</div>
                <div>Tổng lượt đặt</div>
                <div>Lịch hoàn thành</div>
                <div>Doanh thu thực thu</div>
            </div>

            <?php if (count($allServiceStats) > 0): ?>
                <?php foreach ($allServiceStats as $srvItem): ?>
                    <div class="report-table-row">
                        <div><strong><?= htmlspecialchars($srvItem['TenDichVu']) ?></strong></div>
                        <div><?= (int)$srvItem['SoLuot'] ?> lượt</div>
                        <div><?= (int)$srvItem['SoHoanThanh'] ?> lịch</div>
                        <div><strong><?= number_format($srvItem['DoanhThu'], 0, ',', '.') ?>đ</strong></div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="report-table-row" style="grid-template-columns: 1fr; color: #77857a;">
                    Chưa có dữ liệu dịch vụ trong khoảng thời gian đã chọn.
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

</body>
</html>