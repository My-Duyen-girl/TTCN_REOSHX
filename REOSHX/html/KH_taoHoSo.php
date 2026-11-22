<?php
session_start();
require_once '../db.php';

// Kiểm tra đăng nhập với vai trò Khách hàng
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Khách hàng' || empty($_SESSION['MaKhachHang'])) {
    header('Location: dangNhap.php');
    exit;
}

$maKH = $_SESSION['MaKhachHang'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenThuCung = trim($_POST['ten_thu_cung'] ?? '');
    $loai       = trim($_POST['loai'] ?? '');
    $gioiTinh   = $_POST['gioi_tinh'] ?? '';
    $canNang    = floatval($_POST['can_nang'] ?? 0);
    $diUng      = trim($_POST['di_ung'] ?? '');
    $ghiChu     = trim($_POST['ghi_chu'] ?? '');

    if ($tenThuCung === '' || $loai === '' || !in_array($gioiTinh, ['Đực', 'Cái'])) {
        $error = 'Vui lòng nhập đầy đủ Tên thú cưng, Loài/Giống và Giới tính!';
    } elseif ($canNang <= 0) {
        $error = 'Cân nặng của thú cưng phải lớn hơn 0 kg!';
    } else {
        $diUngVal  = ($diUng !== '') ? $diUng : null;
        $ghiChuVal = ($ghiChu !== '') ? $ghiChu : null;

        $sql = "INSERT INTO ThuCung (MaKhachHang, TenThuCung, Loai, GioiTinh, CanNang, DiUng, GhiChu)
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$maKH, $tenThuCung, $loai, $gioiTinh, $canNang, $diUngVal, $ghiChuVal]);

        // Lưu thành công -> quay về trang Tổng quan để xem hồ sơ vừa tạo
        header('Location: KH_tongquan.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tạo hồ sơ thú cưng</title>
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

        /* SIDEBAR TRÁI */
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

        /* NỘI DUNG CHÍNH */
        .main-content {
            flex: 1;
            padding: 36px 44px;
            max-width: 1150px;
        }

        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 26px;
            max-width: 740px;
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

        .btn-primary {
            background-color: #556e57;
            color: #ffffff;
            border: none;
            text-decoration: none;
            padding: 12px 24px;
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
            padding: 11px 20px;
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

        /* KHUNG FORM TẠO HỒ SƠ */
        .form-card {
            background-color: #ffffff;
            border-radius: 14px;
            padding: 34px 38px;
            border: 1px solid #f0ede6;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.03);
            max-width: 740px;
        }

        .alert-error {
            background-color: #fde8e8;
            color: #c81e1e;
            border: 1px solid #f8b4b4;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 22px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
            margin-bottom: 18px;
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
            margin-top: 8px;
            padding-top: 20px;
            border-top: 1px solid #f0ede6;
        }

        @media (max-width: 860px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .form-grid {
                grid-template-columns: 1fr;
            }
            .page-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
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
                <li><a href="khachhang_thucung.php" class="active">Thú cưng của tôi</a></li>
                <li><a href="khachhang_datlich.php">Đặt lịch dịch vụ</a></li>
                <li><a href="khachhang_lichhen.php">Lịch hẹn của tôi</a></li>
                <li><a href="khachhang_lichhen.php?trangthai=hoanthanh">Lịch sử chăm sóc</a></li>
                <li><a href="khachhang_hoso.php">Thông tin cá nhân</a></li>
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
            <div>
                <h1>Tạo hồ sơ thú cưng</h1>
                <p>Điền thông tin chi tiết của bé để nhân viên Spa chăm sóc an toàn nhất.</p>
            </div>
            <a href="KH_tongquan.php" class="btn-secondary">← Quay lại</a>
        </div>

        <div class="form-card">
            <?php if (!empty($error)): ?>
                <div class="alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="KH_taoHoSo.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Tên thú cưng <span>*</span></label>
                        <input type="text" name="ten_thu_cung" placeholder="Ví dụ: Bông, Mochi, Lu..."
                               value="<?= htmlspecialchars($_POST['ten_thu_cung'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Loài / Giống <span>*</span></label>
                        <input type="text" name="loai" placeholder="Ví dụ: Chó Poodle, Mèo Anh lông ngắn..."
                               value="<?= htmlspecialchars($_POST['loai'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Giới tính <span>*</span></label>
                        <select name="gioi_tinh" required>
                            <option value="Đực" <?= (($_POST['gioi_tinh'] ?? '') === 'Đực') ? 'selected' : '' ?>>Đực</option>
                            <option value="Cái" <?= (($_POST['gioi_tinh'] ?? '') === 'Cái') ? 'selected' : '' ?>>Cái</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Cân nặng (kg) <span>*</span></label>
                        <input type="number" step="0.1" min="0.1" name="can_nang" placeholder="Ví dụ: 4.5"
                               value="<?= htmlspecialchars($_POST['can_nang'] ?? '') ?>" required>
                    </div>

                    <div class="form-group full-width">
                        <label>Tiền sử dị ứng (nếu có)</label>
                        <input type="text" name="di_ung" placeholder="Ví dụ: Dị ứng sữa tắm hương bạc hà, nhạy cảm vùng tai..."
                               value="<?= htmlspecialchars($_POST['di_ung'] ?? '') ?>">
                    </div>

                    <div class="form-group full-width">
                        <label>Ghi chú chăm sóc đặc biệt</label>
                        <textarea name="ghi_chu" rows="3" placeholder="Ví dụ: Bé hơi nhát người lạ, cần sấy nhẹ..."><?= htmlspecialchars($_POST['ghi_chu'] ?? '') ?></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn-primary">Lưu hồ sơ thú cưng</button>
                    <a href="KH_tongquan.php" class="btn-secondary">Hủy bỏ</a>
                </div>
            </form>
        </div>
    </main>
</div>

</body>
</html>