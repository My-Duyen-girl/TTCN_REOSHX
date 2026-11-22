<?php
session_start();
require_once '../db.php';

if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Khách hàng' || empty($_SESSION['MaKhachHang'])) {
    header('Location: dangNhap.php');
    exit;
}

$maKH  = $_SESSION['MaKhachHang'];
$hoTen = $_SESSION['HoTen'] ?? 'Bạn';


// 1. Đếm tổng số thú cưng của khách hàng
$stmtCountPet = $conn->prepare("SELECT COUNT(*) FROM ThuCung WHERE MaKhachHang = ?");
$stmtCountPet->execute([$maKH]);
$tongThuCung = (int)$stmtCountPet->fetchColumn();

// 2. Đếm lịch hẹn đã xác nhận / đang thực hiện
$stmtCountConfirmed = $conn->prepare("
    SELECT COUNT(*) FROM LichHen lh
    JOIN ThuCung tc ON lh.MaThuCung = tc.MaThuCung
    WHERE tc.MaKhachHang = ? AND lh.TrangThai IN ('Đã xác nhận', 'Đang thực hiện')
");
$stmtCountConfirmed->execute([$maKH]);
$lichDaXacNhan = (int)$stmtCountConfirmed->fetchColumn();

// 3. Đếm lịch đang chờ xác nhận
$stmtCountPending = $conn->prepare("
    SELECT COUNT(*) FROM LichHen lh
    JOIN ThuCung tc ON lh.MaThuCung = tc.MaThuCung
    WHERE tc.MaKhachHang = ? AND lh.TrangThai = 'Chờ xác nhận'
");
$stmtCountPending->execute([$maKH]);
$lichChoXacNhan = (int)$stmtCountPending->fetchColumn();

// 4. Đếm dịch vụ đã hoàn thành
$stmtCountDone = $conn->prepare("
    SELECT COUNT(*) FROM LichHen lh
    JOIN ThuCung tc ON lh.MaThuCung = tc.MaThuCung
    WHERE tc.MaKhachHang = ? AND lh.TrangThai = 'Hoàn thành'
");
$stmtCountDone->execute([$maKH]);
$lichHoanThanh = (int)$stmtCountDone->fetchColumn();

// 5. Lấy tối đa 2 thú cưng mới nhất để hiển thị trên trang Tổng quan
$stmtPets = $conn->prepare("SELECT * FROM ThuCung WHERE MaKhachHang = ? ORDER BY MaThuCung DESC LIMIT 2");
$stmtPets->execute([$maKH]);
$dsThuCung = $stmtPets->fetchAll();

// 6. Lấy danh sách lịch hẹn sắp tới
$stmtAppts = $conn->prepare("
    SELECT lh.MaLichHen, lh.ThoiGianBD, lh.ThoiGianKT, lh.TrangThai,
           tc.TenThuCung, dv.TenDichVu, nv.HoTen AS TenNhanVien
    FROM LichHen lh
    JOIN ThuCung tc ON lh.MaThuCung = tc.MaThuCung
    JOIN DichVu dv ON lh.MaDichVu = dv.MaDichVu
    LEFT JOIN NhanVien nv ON lh.MaNhanVien = nv.MaNhanVien
    WHERE tc.MaKhachHang = ? AND lh.TrangThai IN ('Chờ xác nhận', 'Đã xác nhận', 'Đang thực hiện')
    ORDER BY lh.ThoiGianBD ASC
    LIMIT 3
");
$stmtAppts->execute([$maKH]);
$dsLichHen = $stmtAppts->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tổng quan Khách hàng - Pet Care & Spa</title>
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
            max-width: 1200px;
        }

        .welcome-header {
            margin-bottom: 28px;
        }

        .welcome-header h1 {
            font-size: 28px;
            font-weight: 800;
            color: #243429;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 6px;
        }

        .welcome-header p {
            font-size: 14px;
            color: #77857a;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            margin-bottom: 36px;
        }

        .stat-card {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 20px 22px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.025);
            border: 1px solid #f0ede6;
        }

        .stat-label {
            font-size: 13px;
            color: #7b887e;
            margin-bottom: 10px;
        }

        .stat-value {
            font-size: 21px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 12px;
        }

        .stat-bar {
            width: 64px;
            height: 3.5px;
            background-color: #556e57;
            border-radius: 4px;
        }

        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 18px;
        }

        .section-header h2 {
            font-size: 20px;
            font-weight: 800;
            color: #243429;
        }

        .btn-add {
            background-color: #556e57;
            color: #ffffff;
            text-decoration: none;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            display: inline-block;
            transition: background-color 0.2s;
        }

        .btn-add:hover {
            background-color: #445946;
        }

        .btn-view-all {
            background-color: #ffffff;
            color: #243429;
            border: 1px solid #d5ddd2;
            padding: 9px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }

        .btn-view-all:hover {
            background-color: #f2f5f0;
        }

        .pets-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 22px;
            margin-bottom: 36px;
        }

        .pet-card {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 20px;
            border: 1px solid #f0ede6;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.025);
            display: flex;
            gap: 20px;
        }

        .pet-left {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .pet-avatar {
            width: 155px;
            height: 155px;
            background-color: #e8efe3;
            border-radius: 12px;
            padding: 16px;
            display: flex;
            align-items: flex-start;
            justify-content: flex-start;
        }

        .pet-avatar svg {
            width: 34px;
            height: 34px;
            fill: #4f5e53;
        }

        .pet-link {
            font-size: 13px;
            font-weight: 700;
            color: #243429;
            text-decoration: none;
            padding-left: 4px;
        }

        .pet-link:hover {
            text-decoration: underline;
        }

        .pet-info {
            padding-top: 4px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .pet-name {
            font-size: 19px;
            font-weight: 800;
            color: #243429;
        }

        .pet-meta {
            font-size: 13.5px;
            color: #77857a;
        }

        .pet-status {
            font-size: 12.5px;
            font-weight: 700;
            color: #556e57;
            margin-top: 2px;
        }

        .pet-note {
            font-size: 12.5px;
            color: #6b786e;
            margin-top: 4px;
            line-height: 1.45;
        }

        .appointments-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
        }

        .appointment-card {
            background-color: #ffffff;
            border-radius: 10px;
            padding: 20px 26px;
            border: 1px solid #f0ede6;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.025);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .badge-status {
            display: inline-block;
            background-color: #e8efe3;
            color: #4c6451;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 12px;
            border-radius: 20px;
            margin-bottom: 10px;
        }

        .badge-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .appointment-service {
            font-size: 16.5px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .appointment-meta {
            font-size: 13.5px;
            color: #77857a;
        }

        .appointment-detail-link {
            font-size: 13.5px;
            font-weight: 700;
            color: #243429;
            text-decoration: none;
        }

        .appointment-detail-link:hover {
            text-decoration: underline;
        }

        .empty-box {
            background-color: #ffffff;
            border: 1px dashed #d3dcd0;
            border-radius: 10px;
            padding: 28px;
            text-align: center;
            color: #6b786e;
            font-size: 14px;
            margin-bottom: 28px;
        }

        .empty-link {
            display: inline-block;
            margin-top: 10px;
            color: #556e57;
            font-weight: 700;
            text-decoration: none;
        }

        .empty-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 960px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .pets-grid {
                grid-template-columns: 1fr;
            }
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
                <li><a href="KH_tongquan.php" class="active">Tổng quan</a></li>
                <li><a href="HS_thucung.php">Thú cưng của tôi</a></li>
                <li><a href="khachhang_datlich.php">Đặt lịch dịch vụ</a></li>
                <li><a href="khachhang_lichhen.php">Lịch hẹn của tôi</a></li>
                <li><a href="khachhang_lichhen.php?trangthai=hoanthanh">Lịch sử chăm sóc</a></li>
                <li><a href="khachhang_hoso.php">Thông tin cá nhân</a></li>
            </ul>
        </div>

        <div class="sidebar-bottom">
            <a href="trangChu.php">Trang Chủ</a>
            <a href="dangXuat.php" class="logout-link">Đăng xuất</a>
        </div>
    </aside>

    <main class="main-content">
        <div class="welcome-header">
            <h1>
                Xin chào, <?= htmlspecialchars($hoTen) ?>!
                <svg width="26" height="26" viewBox="0 0 512 512" fill="#4f5e53">
                    <path d="M256 224c-79.41 0-192 122.76-192 200.25 0 34.9 26.81 55.75 71.74 55.75 48.84 0 81.09-25.08 120.26-25.08 39.51 0 71.85 25.08 120.26 25.08 44.93 0 71.74-20.85 71.74-55.75C448 346.76 335.41 224 256 224zm-147.28-12.61c-10.4-34.65-42.44-57.09-71.56-50.13-29.12 6.96-44.29 40.69-33.89 75.34 10.4 34.65 42.44 57.09 71.56 50.13 29.12-6.96 44.29-40.69 33.89-75.34zm84.72-20.78c30.94-8.14 46.42-49.94 34.58-93.36s-46.52-72.01-77.46-63.87-46.42 49.94-34.58 93.36c11.84 43.42 46.53 72.02 77.46 63.87zm281.39-29.34c-29.12-6.96-61.15 15.48-71.56 50.13-10.4 34.65 4.77 68.38 33.89 75.34 29.12 6.96 61.15-15.48 71.56-50.13 10.4-34.65-4.77-68.38-33.89-75.34zm-156.27 29.34c30.94 8.14 65.62-20.45 77.46-63.87 11.84-43.42-3.64-85.21-34.58-93.36s-65.62 20.45-77.46 63.87c-11.84 43.42 3.64 85.22 34.58 93.36z"/>
                </svg>
            </h1>
            <p>Quản lý hồ sơ thú cưng và theo dõi trạng thái lịch hẹn của các bé.</p>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Thú cưng của tôi</div>
                <div class="stat-value"><?= $tongThuCung ?> hồ sơ</div>
                <div class="stat-bar"></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Lịch hẹn sắp tới</div>
                <div class="stat-value"><?= $lichDaXacNhan ?> lịch đã xác nhận</div>
                <div class="stat-bar"></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Đang chờ duyệt</div>
                <div class="stat-value"><?= $lichChoXacNhan ?> lịch hẹn</div>
                <div class="stat-bar"></div>
            </div>

            <div class="stat-card">
                <div class="stat-label">Lịch sử chăm sóc</div>
                <div class="stat-value"><?= $lichHoanThanh ?> lần hoàn thành</div>
                <div class="stat-bar"></div>
            </div>
        </div>

        <div class="section-header">
            <h2>Hồ sơ thú cưng</h2>

            <?php if ($tongThuCung > 0): ?>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <?php if ($tongThuCung > 2): ?>
                        <a href="KH_taoHoSo.php" class="btn-view-all">
                            Xem tất cả (<?= $tongThuCung ?> bé)
                        </a>
                    <?php endif; ?>
                    <a href="KH_taoHoSo.php" class="btn-add">+ Thêm thú cưng</a>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($tongThuCung > 0): ?>
            <div class="pets-grid">
                <?php foreach ($dsThuCung as $pet): ?>
                    <div class="pet-card">
                        <div class="pet-left">
                            <div class="pet-avatar">
                                <svg viewBox="0 0 512 512">
                                    <path d="M256 224c-79.41 0-192 122.76-192 200.25 0 34.9 26.81 55.75 71.74 55.75 48.84 0 81.09-25.08 120.26-25.08 39.51 0 71.85 25.08 120.26 25.08 44.93 0 71.74-20.85 71.74-55.75C448 346.76 335.41 224 256 224zm-147.28-12.61c-10.4-34.65-42.44-57.09-71.56-50.13-29.12 6.96-44.29 40.69-33.89 75.34 10.4 34.65 42.44 57.09 71.56 50.13 29.12-6.96 44.29-40.69 33.89-75.34zm84.72-20.78c30.94-8.14 46.42-49.94 34.58-93.36s-46.52-72.01-77.46-63.87-46.42 49.94-34.58 93.36c11.84 43.42 46.53 72.02 77.46 63.87zm281.39-29.34c-29.12-6.96-61.15 15.48-71.56 50.13-10.4 34.65 4.77 68.38 33.89 75.34 29.12 6.96 61.15-15.48 71.56-50.13 10.4-34.65-4.77-68.38-33.89-75.34zm-156.27 29.34c30.94 8.14 65.62-20.45 77.46-63.87 11.84-43.42-3.64-85.21-34.58-93.36s-65.62 20.45-77.46 63.87c-11.84 43.42 3.64 85.22 34.58 93.36z"/>
                                </svg>
                            </div>
                            <a href="KH_xemHS.php?sua=<?= $pet['MaThuCung'] ?>" class="pet-link">Xem hồ sơ</a>
                        </div>

                        <div class="pet-info">
                            <div class="pet-name"><?= htmlspecialchars($pet['TenThuCung']) ?></div>
                            <div class="pet-meta">
                                <?= htmlspecialchars($pet['Loai']) ?> ·
                                <?= htmlspecialchars($pet['GioiTinh']) ?> ·
                                <?= number_format($pet['CanNang'], 1) ?> kg
                            </div>
                            <div class="pet-status">Hồ sơ đã cập nhật</div>
                            <?php if (!empty($pet['DiUng'])): ?>
                                <div class="pet-note">Dị ứng: <?= htmlspecialchars($pet['DiUng']) ?></div>
                            <?php elseif (!empty($pet['GhiChu'])): ?>
                                <div class="pet-note">Ghi chú: <?= htmlspecialchars($pet['GhiChu']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-box">
                <p style="margin-bottom: 14px;">Bạn chưa có hồ sơ thú cưng nào trong hệ thống.</p>
                <a href="KH_taoHoSo.php" class="btn-add">+ Tạo hồ sơ thú cưng</a>
            </div>
        <?php endif; ?>

        <div class="section-header">
            <h2>Lịch hẹn sắp tới</h2>
            <a href="khachhang_datlich.php" class="btn-add">+ Đặt lịch mới</a>
        </div>

        <?php if (count($dsLichHen) > 0): ?>
            <div class="appointments-list">
                <?php foreach ($dsLichHen as $lh): ?>
                    <?php
                        $isPending = ($lh['TrangThai'] === 'Chờ xác nhận');
                        $gioBD = date('H:i - d/m/Y', strtotime($lh['ThoiGianBD']));
                        $tenNV = !empty($lh['TenNhanVien']) ? $lh['TenNhanVien'] : 'Chưa phân công';
                    ?>
                    <div class="appointment-card">
                        <div>
                            <span class="badge-status <?= $isPending ? 'badge-pending' : '' ?>">
                                <?= htmlspecialchars($lh['TrangThai']) ?>
                            </span>
                            <div class="appointment-service"><?= htmlspecialchars($lh['TenDichVu']) ?></div>
                            <div class="appointment-meta">
                                <?= htmlspecialchars($lh['TenThuCung']) ?> ·
                                <?= $gioBD ?> ·
                                Nhân viên: <?= htmlspecialchars($tenNV) ?>
                            </div>
                        </div>

                       <a href="KH_chitietLH.php?id=<?= $lh['MaLichHen'] ?>" class="appointment-detail-link">Xem chi tiết</a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="empty-box">
                Hiện chưa có lịch hẹn nào sắp tới.
                <br><a href="khachhang_datlich.php" class="empty-link">Đặt lịch chăm sóc cho bé ngay →</a>
            </div>
        <?php endif; ?>
    </main>
</div>

</body>
</html>