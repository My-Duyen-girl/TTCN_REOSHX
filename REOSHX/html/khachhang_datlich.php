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

// 1. Lấy danh sách thú cưng của khách hàng hiện tại
$stmtPets = $conn->prepare("SELECT * FROM ThuCung WHERE MaKhachHang = ? ORDER BY MaThuCung ASC");
$stmtPets->execute([$maKH]);
$dsThuCung = $stmtPets->fetchAll();

// 2. Lấy danh sách dịch vụ từ bảng DichVu
$stmtServices = $conn->query("SELECT * FROM DichVu ORDER BY MaDichVu ASC");
$dsDichVu = $stmtServices->fetchAll();

// 3. Tạo mảng 5 ngày sắp tới tính từ ngày mai (để tránh đặt lùi giờ trong quá khứ)
$thuViet = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'];
$thuDayDu = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
$dsNgay = [];
for ($i = 1; $i <= 5; $i++) {
    $ts = strtotime("+$i day");
    $w = (int)date('w', $ts);
    $dsNgay[] = [
        'value'      => date('Y-m-d', $ts),
        'shortLabel' => date('d', $ts) . ' ' . $thuViet[$w],
        'fullLabel'  => $thuDayDu[$w] . ', ' . date('d/m', $ts)
    ];
}

// Danh sách khung giờ chuẩn của Spa
$dsKhungGio = ['09:00', '09:30', '10:00', '10:30', '14:00', '14:30', '15:30', '16:30'];

// Giá trị mặc định (nếu có truyền ?pet_id=... từ trang Xem hồ sơ thì tự chọn sẵn bé đó)
$selectedPetId     = isset($_POST['ma_thu_cung']) ? (int)$_POST['ma_thu_cung'] : (isset($_GET['pet_id']) ? (int)$_GET['pet_id'] : ($dsThuCung[0]['MaThuCung'] ?? 0));
$selectedServiceId = isset($_POST['ma_dich_vu']) ? (int)$_POST['ma_dich_vu'] : ($dsDichVu[0]['MaDichVu'] ?? 0);
$selectedDate      = $_POST['ngay_hen'] ?? $dsNgay[0]['value'];
$selectedTime      = $_POST['khung_gio'] ?? '09:30';
$ghiChuInput       = $_POST['ghi_chu'] ?? '';

// 4. XỬ LÝ KHI KHÁCH HÀNG BẤM "XÁC NHẬN ĐẶT LỊCH"
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $maThuCung = (int)($_POST['ma_thu_cung'] ?? 0);
    $maDichVu  = (int)($_POST['ma_dich_vu'] ?? 0);
    $ngayHen   = trim($_POST['ngay_hen'] ?? '');
    $khungGio  = trim($_POST['khung_gio'] ?? '');
    $ghiChu    = trim($_POST['ghi_chu'] ?? '');

    // Kiểm tra thú cưng có đúng thuộc sở hữu của khách hàng này không
    $stmtCheckPet = $conn->prepare("SELECT MaThuCung FROM ThuCung WHERE MaThuCung = ? AND MaKhachHang = ?");
    $stmtCheckPet->execute([$maThuCung, $maKH]);

    if ($stmtCheckPet->rowCount() === 0) {
        $error = 'Vui lòng chọn thú cưng hợp lệ của bạn!';
    } elseif ($maDichVu <= 0 || !in_array($khungGio, $dsKhungGio) || empty($ngayHen)) {
        $error = 'Vui lòng chọn đầy đủ dịch vụ, ngày hẹn và khung giờ!';
    } else {
        $thoiGianBD = $ngayHen . ' ' . $khungGio . ':00';
        $thoiGianKT = date('Y-m-d H:i:s', strtotime($thoiGianBD . ' +1 hour'));

        // Kiểm tra bé này có bị trùng lịch hẹn đang hoạt động trong cùng khung giờ không
        $stmtOverlap = $conn->prepare("
            SELECT MaLichHen FROM LichHen 
            WHERE MaThuCung = ? 
              AND TrangThai IN ('Chờ xác nhận', 'Đã xác nhận', 'Đang thực hiện')
              AND (ThoiGianBD < ? AND ThoiGianKT > ?)
        ");
        $stmtOverlap->execute([$maThuCung, $thoiGianKT, $thoiGianBD]);

        if ($stmtOverlap->rowCount() > 0) {
            $error = 'Bé thú cưng này đã có lịch hẹn trùng vào khung giờ trên. Vui lòng chọn giờ khác!';
        } else {
           
            $sqlInsert = "INSERT INTO LichHen (MaThuCung, MaDichVu, MaNhanVien, ThoiGianBD, ThoiGianKT, TrangThai)
              VALUES (?, ?, NULL, ?, ?, 'Chờ xác nhận')";
            $stmtIns = $conn->prepare($sqlInsert);
            $stmtIns->execute([$maThuCung, $maDichVu, $thoiGianBD, $thoiGianKT]);

            $stmtIns = $conn->prepare($sqlInsert);
            $stmtIns->execute([$maThuCung, $maDichVu, $thoiGianBD, $thoiGianKT]);

            // Chuyển thẳng sang trang Lịch hẹn của tôi để xem ngay lịch vừa đặt
            header('Location: khachhang_lichhen.php?booked=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt lịch dịch vụ</title>
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
            margin-bottom: 26px;
        }

        .page-header h1 {
            font-size: 28px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 6px;
        }

        .page-header p {
            font-size: 14px;
            color: #77857a;
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
            padding: 14px 18px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 22px;
            line-height: 1.5;
        }

        .alert-success a {
            color: #03543f;
            font-weight: 700;
            text-decoration: underline;
        }

        /* BỐ CỤC 2 CỘT: FORM TRÁI - TÓM TẮT PHẢI */
        .booking-grid {
            display: grid;
            grid-template-columns: 1fr 310px;
            gap: 24px;
            align-items: start;
        }

        .booking-form-card {
            background-color: #ffffff;
            border-radius: 12px;
            padding: 30px 34px;
            border: 1px solid #f0ede6;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.02);
        }

        .card-title {
            font-size: 20px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 22px;
        }

        .form-group {
            margin-bottom: 22px;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #4f5e53;
            margin-bottom: 9px;
        }

        .form-group select,
        .form-group input[type="text"] {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid #e6e8e3;
            border-radius: 8px;
            font-size: 14.5px;
            color: #243429;
            background-color: #ffffff;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-group select:focus,
        .form-group input[type="text"]:focus {
            border-color: #556e57;
        }

        /* CHỌN NGÀY HẸN (THẺ NGANG) */
        .date-pills {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
        }

        .date-pill {
            background-color: #faf7f2;
            border: 1.5px solid #f0ede6;
            border-radius: 10px;
            padding: 14px 10px;
            text-align: center;
            font-size: 14px;
            font-weight: 700;
            color: #243429;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }

        .date-pill:hover {
            border-color: #b8c7b4;
            background-color: #f2f5ef;
        }

        .date-pill.active {
            background-color: #556e57;
            border-color: #556e57;
            color: #ffffff;
        }

        /* CHỌN KHUNG GIỜ */
        .time-pills {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
        }

        .time-pill {
            background-color: #ffffff;
            border: 1.5px solid #eceae4;
            border-radius: 8px;
            padding: 11px 10px;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            color: #243429;
            cursor: pointer;
            transition: all 0.2s;
            user-select: none;
        }

        .time-pill:hover {
            background-color: #f3f6f1;
            border-color: #c5d1c2;
        }

        .time-pill.active {
            background-color: #e4ede0;
            border-color: #e4ede0;
            color: #2c4232;
            font-weight: 700;
        }

        .btn-submit {
            width: 100%;
            background-color: #556e57;
            color: #ffffff;
            border: none;
            padding: 14px;
            border-radius: 8px;
            font-size: 14.5px;
            font-weight: 700;
            cursor: pointer;
            margin-top: 10px;
            transition: background-color 0.2s, transform 0.1s;
        }

        .btn-submit:hover {
            background-color: #445946;
        }

        .btn-submit:active {
            transform: scale(0.99);
        }

        /* ================= THẺ TÓM TẮT LỊCH HẸN BÊN PHẢI ================= */
        .summary-card {
            background-color: #e8efe3;
            border-radius: 10px;
            padding: 26px 24px;
            position: sticky;
            top: 24px;
        }

        .summary-title {
            font-size: 19px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 18px;
        }

        .summary-pet {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 15px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 10px;
        }

        .summary-pet svg {
            width: 16px;
            height: 16px;
            fill: #4f5e53;
            flex-shrink: 0;
        }

        .summary-row {
            font-size: 13.5px;
            color: #5b6b5f;
            margin-bottom: 10px;
            line-height: 1.45;
        }

        .summary-price {
            font-size: 14px;
            font-weight: 700;
            color: #364f3c;
            margin-top: 4px;
        }

        .summary-divider {
            border: none;
            border-top: 1px solid #d4dfd0;
            margin: 18px 0;
        }

        .summary-note {
            font-size: 12.5px;
            color: #6b786e;
            line-height: 1.5;
        }

        /* TRẠNG THÁI CHƯA CÓ THÚ CƯNG */
        .empty-pet-notice {
            background-color: #ffffff;
            border: 1px dashed #d3dcd0;
            border-radius: 12px;
            padding: 40px 24px;
            text-align: center;
            color: #5b6b5f;
        }

        .btn-create-pet {
            display: inline-block;
            margin-top: 14px;
            background-color: #556e57;
            color: #ffffff;
            text-decoration: none;
            padding: 11px 22px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        @media (max-width: 1024px) {
            .booking-grid {
                grid-template-columns: 1fr;
            }
            .date-pills {
                grid-template-columns: repeat(3, 1fr);
            }
        }

        @media (max-width: 820px) {
            .layout {
                flex-direction: column;
            }
            .sidebar {
                width: 100%;
            }
            .time-pills {
                grid-template-columns: repeat(2, 1fr);
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
                <li><a href="KH_xemHS.php">Thú cưng của tôi</a></li>
                <li><a href="khachhang_datlich.php" class="active">Đặt lịch dịch vụ</a></li>
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
            <h1>Đặt lịch chăm sóc</h1>
            <p>Chọn thú cưng, dịch vụ và thời gian phù hợp.</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>

        <?php if (count($dsThuCung) === 0): ?>
            <!-- Nếu khách hàng chưa có thú cưng nào thì nhắc tạo hồ sơ trước -->
            <div class="empty-pet-notice">
                <p>Bạn cần có ít nhất 1 hồ sơ thú cưng trước khi đặt lịch chăm sóc.</p>
                <a href="KH_taoHoSo.php" class="btn-create-pet">+ Tạo hồ sơ thú cưng ngay</a>
            </div>
        <?php else: ?>
            <div class="booking-grid">
                <!-- CỘT TRÁI: FORM THÔNG TIN LỊCH HẸN -->
                <div class="booking-form-card">
                    <div class="card-title">Thông tin lịch hẹn</div>

                    <form method="POST" action="khachhang_datlich.php">
                        <!-- 1. Chọn thú cưng -->
                        <div class="form-group">
                            <label for="select-pet">Chọn thú cưng *</label>
                            <select name="ma_thu_cung" id="select-pet" required>
                                <?php foreach ($dsThuCung as $pet): ?>
                                    <option value="<?= $pet['MaThuCung'] ?>"
                                            data-name="<?= htmlspecialchars($pet['TenThuCung']) ?>"
                                            <?= ((int)$pet['MaThuCung'] === $selectedPetId) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($pet['TenThuCung']) ?> — <?= htmlspecialchars($pet['Loai']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 2. Chọn dịch vụ -->
                        <div class="form-group">
                            <label for="select-service">Dịch vụ *</label>
                            <select name="ma_dich_vu" id="select-service" required>
                                <?php foreach ($dsDichVu as $dv): ?>
                                    <option value="<?= $dv['MaDichVu'] ?>"
                                            data-name="<?= htmlspecialchars($dv['TenDichVu']) ?>"
                                            data-price="<?= number_format($dv['GiaTien'], 0, ',', '.') ?> VNĐ"
                                            <?= ((int)$dv['MaDichVu'] === $selectedServiceId) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dv['TenDichVu']) ?> (<?= number_format($dv['GiaTien'], 0, ',', '.') ?> VNĐ)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 3. Chọn ngày hẹn -->
                        <div class="form-group">
                            <label>Ngày hẹn *</label>
                            <input type="hidden" name="ngay_hen" id="input-date" value="<?= htmlspecialchars($selectedDate) ?>">
                            <div class="date-pills">
                                <?php foreach ($dsNgay as $ngay): ?>
                                    <div class="date-pill <?= ($ngay['value'] === $selectedDate) ? 'active' : '' ?>"
                                         data-value="<?= $ngay['value'] ?>"
                                         data-full="<?= htmlspecialchars($ngay['fullLabel']) ?>">
                                        <?= htmlspecialchars($ngay['shortLabel']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 4. Chọn khung giờ -->
                        <div class="form-group">
                            <label>Khung giờ *</label>
                            <input type="hidden" name="khung_gio" id="input-time" value="<?= htmlspecialchars($selectedTime) ?>">
                            <div class="time-pills">
                                <?php foreach ($dsKhungGio as $gio): ?>
                                    <div class="time-pill <?= ($gio === $selectedTime) ? 'active' : '' ?>"
                                         data-time="<?= $gio ?>">
                                        <?= $gio ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- 5. Ghi chú cho nhân viên 
                        <div class="form-group">
                            <label for="input-note">Ghi chú cho nhân viên</label>
                            <input type="text" name="ghi_chu" id="input-note"
                                   placeholder="Ví dụ: Lưu ý phần da nhạy cảm của bé..."
                                   value="<?= htmlspecialchars($ghiChuInput) ?>">
                        </div>
                        -->

                        <button type="submit" class="btn-submit">Xác nhận đặt lịch</button>
                    </form>
                </div>

                <!-- CỘT PHẢI: TÓM TẮT LỊCH HẸN (CẬP NHẬT TỰ ĐỘNG) -->
                <aside class="summary-card">
                    <div class="summary-title">Tóm tắt lịch hẹn</div>

                    <div class="summary-pet">
                        <svg viewBox="0 0 512 512">
                            <path d="M256 224c-79.41 0-192 122.76-192 200.25 0 34.9 26.81 55.75 71.74 55.75 48.84 0 81.09-25.08 120.26-25.08 39.51 0 71.85 25.08 120.26 25.08 44.93 0 71.74-20.85 71.74-55.75C448 346.76 335.41 224 256 224zm-147.28-12.61c-10.4-34.65-42.44-57.09-71.56-50.13-29.12 6.96-44.29 40.69-33.89 75.34 10.4 34.65 42.44 57.09 71.56 50.13 29.12-6.96 44.29-40.69 33.89-75.34zm84.72-20.78c30.94-8.14 46.42-49.94 34.58-93.36s-46.52-72.01-77.46-63.87-46.42 49.94-34.58 93.36c11.84 43.42 46.53 72.02 77.46 63.87zm281.39-29.34c-29.12-6.96-61.15 15.48-71.56 50.13-10.4 34.65 4.77 68.38 33.89 75.34 29.12 6.96 61.15-15.48 71.56-50.13 10.4-34.65-4.77-68.38-33.89-75.34zm-156.27 29.34c30.94 8.14 65.62-20.45 77.46-63.87 11.84-43.42-3.64-85.21-34.58-93.36s-65.62 20.45-77.46 63.87c-11.84 43.42 3.64 85.22 34.58 93.36z"/>
                        </svg>
                        <span id="sum-pet-name"></span>
                    </div>

                    <div class="summary-row" id="sum-service-name"></div>
                    <div class="summary-row" id="sum-date-label"></div>
                    <div class="summary-row" id="sum-time-range"></div>
                    <div class="summary-price" id="sum-price"></div>

                    <hr class="summary-divider">

                    <div class="summary-note">
                        Lịch hẹn sẽ được nhân viên xác nhận sau khi kiểm tra.
                    </div>
                </aside>
            </div>
        <?php endif; ?>
    </main>
</div>

<script>
    const selectPet     = document.getElementById('select-pet');
    const selectService = document.getElementById('select-service');
    const inputDate     = document.getElementById('input-date');
    const inputTime     = document.getElementById('input-time');
    const datePills     = document.querySelectorAll('.date-pill');
    const timePills     = document.querySelectorAll('.time-pill');

    function calcEndTime(startTime) {
        const [h, m] = startTime.split(':').map(Number);
        const endH = String(h + 1).padStart(2, '0');
        const endM = String(m).padStart(2, '0');
        return `${startTime} – ${endH}:${endM}`;
    }

    function updateSummary() {
        if (!selectPet) return;
        const petOpt = selectPet.options[selectPet.selectedIndex];
        const srvOpt = selectService.options[selectService.selectedIndex];
        const activeDatePill = document.querySelector('.date-pill.active');

        document.getElementById('sum-pet-name').textContent     = petOpt ? petOpt.dataset.name : '';
        document.getElementById('sum-service-name').textContent = srvOpt ? srvOpt.dataset.name : '';
        document.getElementById('sum-price').textContent        = srvOpt ? 'Dự kiến: ' + srvOpt.dataset.price : '';
        document.getElementById('sum-date-label').textContent   = activeDatePill ? activeDatePill.dataset.full : '';
        document.getElementById('sum-time-range').textContent   = calcEndTime(inputTime.value);
    }

    datePills.forEach(pill => {
        pill.addEventListener('click', () => {
            datePills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            inputDate.value = pill.dataset.value;
            updateSummary();
        });
    });

    timePills.forEach(pill => {
        pill.addEventListener('click', () => {
            timePills.forEach(p => p.classList.remove('active'));
            pill.classList.add('active');
            inputTime.value = pill.dataset.time;
            updateSummary();
        });
    });

    if (selectPet) selectPet.addEventListener('change', updateSummary);
    if (selectService) selectService.addEventListener('change', updateSummary);

    // Khởi tạo dữ liệu tóm tắt ngay khi mở trang
    updateSummary();
</script>

</body>
</html>