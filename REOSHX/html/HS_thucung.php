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

// ID thú cưng đang chọn để hiển thị ở khung "Thông tin cần lưu ý" bên dưới
$selectedPetId = isset($_GET['sua']) ? (int)$_GET['sua'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);

// 1. XỬ LÝ LƯU THÔNG TIN CẬP NHẬT TRỰC TIẾP
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'luu_thong_tin') {
    $maTC       = (int)$_POST['ma_thu_cung'];
    $tenThuCung = trim($_POST['ten_thu_cung'] ?? '');
    $loai       = trim($_POST['loai'] ?? '');
    $gioiTinh   = $_POST['gioi_tinh'] ?? '';
    $canNang    = floatval($_POST['can_nang'] ?? 0);
    $ghiChu     = trim($_POST['ghi_chu'] ?? '');
    $diUng      = trim($_POST['di_ung'] ?? '');

    $selectedPetId = $maTC;

    if ($tenThuCung === '' || $loai === '' || !in_array($gioiTinh, ['Đực', 'Cái'])) {
        $error = 'Vui lòng nhập đầy đủ Tên thú cưng, Giống loài và Giới tính!';
    } elseif ($canNang <= 0) {
        $error = 'Cân nặng phải lớn hơn 0 kg!';
    } else {
        $ghiChuVal = ($ghiChu !== '') ? $ghiChu : null;
        $diUngVal  = ($diUng !== '') ? $diUng : null;

        $stmtUpd = $conn->prepare("
            UPDATE ThuCung
            SET TenThuCung = ?, Loai = ?, GioiTinh = ?, CanNang = ?, GhiChu = ?, DiUng = ?
            WHERE MaThuCung = ? AND MaKhachHang = ?
        ");
        $stmtUpd->execute([$tenThuCung, $loai, $gioiTinh, $canNang, $ghiChuVal, $diUngVal, $maTC, $maKH]);
        $success = 'Đã cập nhật thông tin của bé ' . htmlspecialchars($tenThuCung) . ' thành công!';
    }
}

// 2. XỬ LÝ XÓA HỒ SƠ THÚ CƯNG
if (isset($_GET['xoa'])) {
    $maTC = (int)$_GET['xoa'];
    try {
        $stmtDel = $conn->prepare("DELETE FROM ThuCung WHERE MaThuCung = ? AND MaKhachHang = ?");
        $stmtDel->execute([$maTC, $maKH]);
        $success = 'Đã xóa hồ sơ thú cưng!';
        $selectedPetId = 0;
    } catch (PDOException $e) {
        $error = 'Không thể xóa hồ sơ này vì thú cưng đã có lịch hẹn trong hệ thống!';
    }
}

// 3. LẤY DANH SÁCH TOÀN BỘ THÚ CƯNG CỦA KHÁCH HÀNG
$stmtAll = $conn->prepare("SELECT * FROM ThuCung WHERE MaKhachHang = ? ORDER BY MaThuCung ASC");
$stmtAll->execute([$maKH]);
$dsThuCung = $stmtAll->fetchAll();

// 4. CHỌN THÚ CƯNG ĐỂ ĐỔ VÀO KHUNG "THÔNG TIN CẦN LƯU Ý"
$petActive = null;
if (count($dsThuCung) > 0) {
    foreach ($dsThuCung as $item) {
        if ((int)$item['MaThuCung'] === $selectedPetId) {
            $petActive = $item;
            break;
        }
    }
    // Nếu chưa chọn cụ thể bé nào, tự động hiển thị hồ sơ bé đầu tiên
    if ($petActive === null) {
        $petActive = $dsThuCung[0];
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hồ sơ thú cưng</title>
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
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 26px;
            flex-wrap: wrap;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .page-header .sub-greeting {
            font-size: 13.5px;
            color: #77857a;
            margin-bottom: 14px;
        }

        .page-header .sub-desc {
            font-size: 14px;
            color: #5b6b5f;
        }

        .btn-add {
            background-color: #556e57;
            color: #ffffff;
            text-decoration: none;
            padding: 11px 20px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            display: inline-block;
            transition: background-color 0.2s;
        }

        .btn-add:hover {
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

        /* ================= LƯỚI 3 THẺ HỒ SƠ THÚ CƯNG ================= */
        .pets-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 28px;
        }

        .pet-card {
            background-color: #ffffff;
            border-radius: 14px;
            padding: 18px;
            border: 1.5px solid #eceae4;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.02);
            transition: transform 0.2s, border-color 0.2s, box-shadow 0.2s;
        }

        .pet-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(36, 52, 41, 0.06);
        }

        .pet-card.selected {
            border-color: #556e57;
            box-shadow: 0 6px 18px rgba(85, 110, 87, 0.14);
        }

        .pet-illustration {
            height: 150px;
            border-radius: 10px;
            background-color: #e6eee0;
            padding: 16px;
            display: flex;
            align-items: flex-end;
            justify-content: flex-start;
            margin-bottom: 16px;
        }

        .pet-illustration.cat-theme {
            background-color: #efe0d6;
        }

        .pet-emoji {
            font-size: 38px;
            line-height: 1;
            user-select: none;
        }

        .pet-name {
            font-size: 20px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .pet-breed {
            font-size: 13px;
            color: #77857a;
            margin-bottom: 6px;
        }

        .pet-weight {
            font-size: 13.5px;
            font-weight: 600;
            color: #2c3e32;
            margin-bottom: 16px;
        }

        .pet-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .status-pill {
            display: inline-block;
            background-color: #e6eee0;
            color: #4c6451;
            font-size: 11.5px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 20px;
        }

        .status-pill.has-allergy {
            background-color: #efe0d6;
            color: #8a5336;
        }

        .detail-link {
            font-size: 13px;
            font-weight: 700;
            color: #243429;
            text-decoration: none;
            transition: color 0.2s;
        }

        .detail-link:hover {
            color: #556e57;
            text-decoration: underline;
        }

        /* ================= KHUNG THÔNG TIN CẦN LƯU Ý (XEM & SỬA) ================= */
        .edit-panel {
            background-color: #ffffff;
            border-radius: 14px;
            padding: 28px 32px;
            border: 1px solid #eceae4;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.02);
        }

        .edit-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 22px;
            flex-wrap: wrap;
            gap: 10px;
        }

        .edit-panel-title {
            font-size: 18px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .edit-panel-sub {
            font-size: 13.5px;
            color: #77857a;
        }

        .selected-badge {
            background-color: #e8efe3;
            color: #364f3c;
            font-size: 12.5px;
            font-weight: 700;
            padding: 6px 14px;
            border-radius: 20px;
        }

        .edit-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px 20px;
            margin-bottom: 24px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .form-group label {
            font-size: 13px;
            font-weight: 700;
            color: #4f5e53;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #e2e6df;
            border-radius: 10px;
            font-size: 14px;
            color: #243429;
            background-color: #ffffff;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #556e57;
        }

        .edit-panel-actions {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-delete {
            background-color: #fff5f5;
            color: #c81e1e;
            border: 1px solid #f8b4b4;
            text-decoration: none;
            padding: 11px 18px;
            border-radius: 8px;
            font-size: 13.5px;
            font-weight: 600;
            transition: background-color 0.2s;
        }

        .btn-delete:hover {
            background-color: #fde8e8;
        }

        .btn-save {
            background-color: #4c6451;
            color: #ffffff;
            border: none;
            padding: 12px 28px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-save:hover {
            background-color: #3b4f3f;
        }

        /* TRẠNG THÁI CHƯA CÓ THÚ CƯNG */
        .empty-box {
            background-color: #ffffff;
            border: 1px dashed #d3dcd0;
            border-radius: 12px;
            padding: 40px 24px;
            text-align: center;
            color: #6b786e;
        }

        @media (max-width: 1024px) {
            .pets-grid,
            .edit-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 820px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .pets-grid,
            .edit-grid {
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
            <a href="trangChu.php">Trang chủ</a>
            <a href="dangXuat.php" class="logout-link">Đăng xuất</a>
        </div>
    </aside>

    <!-- NỘI DUNG CHÍNH -->
    <main class="main-content">
        <div class="page-header">
            <div>
                <h1>Hồ sơ thú cưng</h1>
                <div class="sub-greeting">Xin chào! Đây là khu vực khách hàng của bạn.</div>
                <div class="sub-desc">Quản lý thông tin các bé thú cưng của bạn.</div>
            </div>

            <a href="KH_taoHoSo.php" class="btn-add">+ Thêm thú cưng</a>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>

        <?php if (count($dsThuCung) > 0): ?>
            <!-- LƯỚI CÁC THẺ THÚ CƯNG -->
            <div class="pets-grid">
                <?php foreach ($dsThuCung as $pet): ?>
                    <?php
                        $isCat = (mb_stripos($pet['Loai'], 'mèo') !== false);
                        $isSelected = ($petActive && (int)$pet['MaThuCung'] === (int)$petActive['MaThuCung']);
                        $hasAllergy = !empty(trim($pet['DiUng'] ?? ''));
                    ?>
                    <div class="pet-card <?= $isSelected ? 'selected' : '' ?>">
                        <div class="pet-illustration <?= $isCat ? 'cat-theme' : '' ?>">
                            <span class="pet-emoji"><?= $isCat ? '🐈' : '🐕' ?></span>
                        </div>

                        <div class="pet-name"><?= htmlspecialchars($pet['TenThuCung']) ?></div>
                        <div class="pet-breed">
                            <?= htmlspecialchars($pet['Loai']) ?> · <?= htmlspecialchars($pet['GioiTinh']) ?>
                        </div>
                        <div class="pet-weight">Cân nặng: <?= number_format($pet['CanNang'], 1) ?> kg</div>

                        <div class="pet-card-footer">
                            <span class="status-pill <?= $hasAllergy ? 'has-allergy' : '' ?>">
                                <?= $hasAllergy ? 'Có lưu ý dị ứng' : 'Không có dị ứng' ?>
                            </span>
                            <a href="KH_xemHS.php?sua=<?= $pet['MaThuCung'] ?>#form-luu-y" class="detail-link">
                                Xem chi tiết →
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- KHUNG THÔNG TIN CẦN LƯU Ý (XEM & CẬP NHẬT TRỰC TIẾP) -->
            <?php if ($petActive): ?>
                <div id="form-luu-y" class="edit-panel">
                    <div class="edit-panel-header">
                        <div>
                            <div class="edit-panel-title">Thông tin cần lưu ý</div>
                            <div class="edit-panel-sub">Cập nhật thông tin để nhân viên chăm sóc thú cưng tốt hơn.</div>
                        </div>
                        <span class="selected-badge">Đang xem: <?= htmlspecialchars($petActive['TenThuCung']) ?></span>
                    </div>

                    <form method="POST" action="KH_xemHS.php?sua=<?= $petActive['MaThuCung'] ?>">
                        <input type="hidden" name="action" value="luu_thong_tin">
                        <input type="hidden" name="ma_thu_cung" value="<?= $petActive['MaThuCung'] ?>">

                        <div class="edit-grid">
                            <div class="form-group">
                                <label>Tên thú cưng</label>
                                <input type="text" name="ten_thu_cung"
                                       value="<?= htmlspecialchars($petActive['TenThuCung']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Giống loài</label>
                                <input type="text" name="loai"
                                       value="<?= htmlspecialchars($petActive['Loai']) ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Giới tính</label>
                                <select name="gioi_tinh" required>
                                    <option value="Đực" <?= $petActive['GioiTinh'] === 'Đực' ? 'selected' : '' ?>>Đực</option>
                                    <option value="Cái" <?= $petActive['GioiTinh'] === 'Cái' ? 'selected' : '' ?>>Cái</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label>Cân nặng (kg)</label>
                                <input type="number" step="0.1" min="0.1" name="can_nang"
                                       value="<?= $petActive['CanNang'] ?>" required>
                            </div>

                            <div class="form-group">
                                <label>Ghi chú sức khỏe / chăm sóc</label>
                                <input type="text" name="ghi_chu"
                                       placeholder="Ví dụ: Bình thường, hơi nhát..."
                                       value="<?= htmlspecialchars($petActive['GhiChu'] ?? '') ?>">
                            </div>

                            <div class="form-group">
                                <label>Dị ứng / lưu ý</label>
                                <input type="text" name="di_ung"
                                       placeholder="Ví dụ: Không có"
                                       value="<?= htmlspecialchars($petActive['DiUng'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="edit-panel-actions">
                            <a href="KH_xemHS.php?xoa=<?= $petActive['MaThuCung'] ?>"
                               class="btn-delete"
                               onclick="return confirm('Bạn chắc chắn muốn xóa hồ sơ của bé <?= htmlspecialchars($petActive['TenThuCung']) ?>?')">
                                Xóa hồ sơ này
                            </a>

                            <button type="submit" class="btn-save">Lưu thông tin</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-box">
                <p style="margin-bottom: 14px;">Bạn chưa có hồ sơ thú cưng nào trong hệ thống.</p>
                <a href="KH_taoHoSo.php" class="btn-add">+ Tạo hồ sơ thú cưng</a>
            </div>
        <?php endif; ?>
    </main>
</div>

</body>
</html>