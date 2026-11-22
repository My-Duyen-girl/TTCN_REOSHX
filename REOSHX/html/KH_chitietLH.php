<?php
session_start();
require_once '../db.php';

// Kiểm tra quyền truy cập Khách hàng
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Khách hàng' || empty($_SESSION['MaKhachHang'])) {
    header('Location: dangNhap.php');
    exit;
}

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: KH_tongquan.php');
    exit;
}

$maLichHen = (int)$_GET['id'];
$maKH      = $_SESSION['MaKhachHang'];

// Truy vấn chi tiết lịch hẹn thuộc khách hàng đang đăng nhập (PDO)
$sql = "
    SELECT lh.MaLichHen, lh.ThoiGianBD, lh.ThoiGianKT, lh.TrangThai,
           tc.TenThuCung, tc.Loai, tc.GioiTinh, tc.CanNang, tc.DiUng, tc.GhiChu,
           dv.TenDichVu, dv.GiaTien,
           nv.HoTen AS TenNhanVien
    FROM LichHen lh
    JOIN ThuCung tc ON lh.MaThuCung = tc.MaThuCung
    JOIN DichVu dv ON lh.MaDichVu = dv.MaDichVu
    LEFT JOIN NhanVien nv ON lh.MaNhanVien = nv.MaNhanVien
    WHERE lh.MaLichHen = ? AND tc.MaKhachHang = ?
";

$stmt = $conn->prepare($sql);
$stmt->execute([$maLichHen, $maKH]);
$chitiet = $stmt->fetch();

if (!$chitiet) {
    echo "<script>alert('Không tìm thấy lịch hẹn!'); window.location.href='KH_tongquan.php';</script>";
    exit;
}

$ngayHen  = date('d/m/Y', strtotime($chitiet['ThoiGianBD']));
$khungGio = date('H:i', strtotime($chitiet['ThoiGianBD'])) . ' – ' . date('H:i', strtotime($chitiet['ThoiGianKT']));
$tenNV    = !empty($chitiet['TenNhanVien']) ? $chitiet['TenNhanVien'] : 'Đang sắp xếp';
$isPending = ($chitiet['TrangThai'] === 'Chờ xác nhận');
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi tiết lịch hẹn</title>
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
            max-width: 1100px;
        }

        .detail-card {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 30px 34px;
            border: 1px solid #f0ede6;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.025);
        }

        .detail-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-bottom: 20px;
            border-bottom: 1px solid #eceae4;
            margin-bottom: 24px;
            gap: 12px;
            flex-wrap: wrap;
        }

        .detail-header h1 {
            font-size: 24px;
            font-weight: 800;
            color: #243429;
        }

        .sub-text {
            font-size: 13.5px;
            color: #77857a;
            margin-top: 4px;
        }

        .badge-status {
            display: inline-block;
            background-color: #e8efe3;
            color: #4c6451;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 6px 14px;
            border-radius: 20px;
        }

        .badge-pending {
            background-color: #fef3c7;
            color: #92400e;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 22px;
        }

        .info-box {
            background-color: #faf7f2;
            border: 1px solid #eceae4;
            border-radius: 10px;
            padding: 22px;
        }

        .info-box h3 {
            font-size: 16px;
            font-weight: 800;
            color: #283b2e;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 1px dashed #d5ddd2;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 12px;
            margin-bottom: 13px;
            font-size: 14px;
        }

        .info-row span {
            color: #6b786e;
            flex-shrink: 0;
        }

        .info-row strong {
            color: #243429;
            text-align: right;
        }

        .price {
            color: #4c6451 !important;
            font-size: 16px;
        }

        .note-block {
            margin-top: 8px;
            font-size: 13.5px;
        }

        .note-block span {
            display: block;
            color: #6b786e;
            margin-bottom: 6px;
        }

        .note-block p {
            background-color: #ffffff;
            padding: 10px 12px;
            border-radius: 8px;
            border: 1px solid #e6e4dd;
            color: #36473b;
            line-height: 1.45;
        }

        .detail-actions {
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid #eceae4;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-back {
            display: inline-block;
            text-decoration: none;
            background-color: #556e57;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .btn-back:hover {
            background-color: #445946;
        }

        .btn-outline {
            display: inline-block;
            text-decoration: none;
            background-color: #ffffff;
            color: #243429;
            border: 1px solid #d5ddd2;
            padding: 10px 18px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
        }

        .btn-outline:hover {
            background-color: #f2f5f0;
        }

        @media (max-width: 860px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .detail-grid {
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
        <div class="detail-card">
            <div class="detail-header">
                <div>
                    <h1>Chi tiết lịch hẹn</h1>
                    <p class="sub-text">Thông tin lịch đặt dịch vụ chăm sóc thú cưng</p>
                </div>
                <span class="badge-status <?= $isPending ? 'badge-pending' : '' ?>">
                    <?= htmlspecialchars($chitiet['TrangThai']) ?>
                </span>
            </div>

            <div class="detail-grid">
                <div class="info-box">
                    <h3>Thông tin dịch vụ & Thời gian</h3>
                    <div class="info-row">
                        <span>Dịch vụ:</span>
                        <strong><?= htmlspecialchars($chitiet['TenDichVu']) ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Ngày hẹn:</span>
                        <strong><?= $ngayHen ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Khung giờ:</span>
                        <strong><?= $khungGio ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Nhân viên phụ trách:</span>
                        <strong><?= htmlspecialchars($tenNV) ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Chi phí dự kiến:</span>
                        <strong class="price"><?= number_format($chitiet['GiaTien'], 0, ',', '.') ?> VNĐ</strong>
                    </div>
                </div>

                <div class="info-box">
                    <h3>Thông tin thú cưng</h3>
                    <div class="info-row">
                        <span>Tên bé:</span>
                        <strong><?= htmlspecialchars($chitiet['TenThuCung']) ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Loài / Giới tính:</span>
                        <strong><?= htmlspecialchars($chitiet['Loai']) ?> · <?= htmlspecialchars($chitiet['GioiTinh']) ?></strong>
                    </div>
                    <div class="info-row">
                        <span>Cân nặng:</span>
                        <strong><?= number_format((float)$chitiet['CanNang'], 1) ?> kg</strong>
                    </div>
                    <div class="note-block">
                        <span>Lưu ý sức khỏe / Dị ứng:</span>
                        <p>
                            <?php
                            if (!empty($chitiet['DiUng'])) {
                                echo 'Dị ứng: ' . htmlspecialchars($chitiet['DiUng']);
                            } elseif (!empty($chitiet['GhiChu'])) {
                                echo htmlspecialchars($chitiet['GhiChu']);
                            } else {
                                echo 'Không có ghi chú đặc biệt';
                            }
                            ?>
                        </p>
                    </div>
                </div>
            </div>

            <div class="detail-actions">
                <a href="KH_tongquan.php" class="btn-back">Quay lại</a>
                <a href="khachhang_lichhen.php" class="btn-outline">Xem tất cả lịch hẹn</a>
            </div>
        </div>
    </main>
</div>

</body>
</html>