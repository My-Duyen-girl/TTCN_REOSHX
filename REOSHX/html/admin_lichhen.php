<?php
session_start();
require_once '../db.php';

if (isset($conn) && $conn instanceof PDO) {
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

// Kiểm tra quyền truy cập Admin
if (!isset($_SESSION['VaiTro']) || $_SESSION['VaiTro'] !== 'Admin') {
    header('Location: dangNhap.php');
    exit;
}

$hoTenAdmin     = $_SESSION['HoTen'] ?? 'Quản trị viên';
$error          = '';
$modalError     = '';
$success        = '';
$openCreateModal = false;

// Đọc cấu trúc thực tế của bảng lichhen & thucung để tương thích mọi phiên bản database
function getTableColumns(PDO $conn, string $table): array {
    $cols = [];
    $stmt = $conn->query("SHOW COLUMNS FROM `{$table}`");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cols[$r['Field']] = $r;
        }
    }
    return $cols;
}

$lhCols = getTableColumns($conn, 'lichhen');
if (!isset($lhCols['GhiChu'])) {
    try {
        $conn->exec("ALTER TABLE lichhen ADD COLUMN GhiChu TEXT NULL");
        $lhCols = getTableColumns($conn, 'lichhen');
    } catch (PDOException $e) {}
}
$hasThoiGianKT = isset($lhCols['ThoiGianKT']);

$tcCols = getTableColumns($conn, 'thucung');
$colSpecies = 'Loai';
foreach (['Loai', 'LoaiThuCung', 'ChungLoai'] as $cand) {
    if (isset($tcCols[$cand])) {
        $colSpecies = $cand;
        break;
    }
}
$colPetNote = null;
foreach (['GhiChu', 'TinhTrangSucKhoe', 'MoTa'] as $cand) {
    if (isset($tcCols[$cand])) {
        $colPetNote = $cand;
        break;
    }
}

$dvCols = getTableColumns($conn, 'dichvu');
$hasThoiLuongDV = isset($dvCols['ThoiLuong']);

function formatMaLH($id): string {
    return 'LH' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
}

function getStatusBadgeClass(string $status): string {
    switch ($status) {
        case 'Chờ xác nhận':   return 'st-pending';
        case 'Đã xác nhận':    return 'st-confirmed';
        case 'Đang thực hiện': return 'st-progress';
        case 'Hoàn thành':     return 'st-done';
        case 'Đã hủy':         return 'st-cancelled';
        default:               return 'st-confirmed';
    }
}

// Tính thời gian kết thúc dựa theo thời lượng dịch vụ (nếu bảng lichhen có cột ThoiGianKT)
function calcEndTime(PDO $conn, int $maDV, string $startSql, bool $hasThoiLuong): string {
    $minutes = 60;
    if ($hasThoiLuong) {
        $stmt = $conn->prepare("SELECT ThoiLuong FROM dichvu WHERE MaDichVu = ?");
        $stmt->execute([$maDV]);
        $tl = (string)$stmt->fetchColumn();
        $num = (int)preg_replace('/\D+/', '', $tl);
        if ($num > 0) $minutes = $num;
    }
    return date('Y-m-d H:i:s', strtotime($startSql . " +{$minutes} minutes"));
}

// 1. XỬ LÝ DUYỆT NHANH LỊCH HẸN (1 CLICK)
if (isset($_GET['duyet'])) {
    $maLHDuyet = (int)$_GET['duyet'];
    $stmtApprove = $conn->prepare("UPDATE lichhen SET TrangThai = 'Đã xác nhận' WHERE MaLichHen = ? AND TrangThai = 'Chờ xác nhận'");
    $stmtApprove->execute([$maLHDuyet]);
    if ($stmtApprove->rowCount() > 0) {
        $success = 'Đã duyệt và xác nhận lịch hẹn ' . formatMaLH($maLHDuyet) . '!';
    }
}

// 2. XỬ LÝ TẠO LỊCH HẸN MỚI (TỪ MODAL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_booking') {
    $openCreateModal = true;
    $maThuCung       = (int)($_POST['ma_thu_cung'] ?? 0);
    $maDichVu        = (int)($_POST['ma_dich_vu'] ?? 0);
    $maNhanVien      = (int)($_POST['ma_nhan_vien'] ?? 0);
    $thoiGianRaw     = trim($_POST['thoi_gian_bd'] ?? '');
    $trangThai       = $_POST['trang_thai'] ?? 'Đã xác nhận';
    $ghiChu          = trim($_POST['ghi_chu'] ?? '');

    $validStatuses = ['Chờ xác nhận', 'Đã xác nhận', 'Đang thực hiện', 'Hoàn thành', 'Đã hủy'];

    if ($maThuCung <= 0 || $maDichVu <= 0 || $thoiGianRaw === '') {
        $modalError = 'Vui lòng chọn đầy đủ Thú cưng, Dịch vụ và Thời gian hẹn!';
    } elseif (!in_array($trangThai, $validStatuses, true)) {
        $modalError = 'Trạng thái lịch hẹn không hợp lệ!';
    } else {
        try {
            $startSql  = date('Y-m-d H:i:s', strtotime($thoiGianRaw));
            $nvVal     = ($maNhanVien > 0) ? $maNhanVien : null;
            $ghiChuVal = ($ghiChu !== '') ? $ghiChu : null;

            if ($hasThoiGianKT) {
                $endSql = calcEndTime($conn, $maDichVu, $startSql, $hasThoiLuongDV);
                $stmtIns = $conn->prepare("
                    INSERT INTO lichhen (MaThuCung, MaDichVu, MaNhanVien, ThoiGianBD, ThoiGianKT, TrangThai, GhiChu)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([$maThuCung, $maDichVu, $nvVal, $startSql, $endSql, $trangThai, $ghiChuVal]);
            } else {
                $stmtIns = $conn->prepare("
                    INSERT INTO lichhen (MaThuCung, MaDichVu, MaNhanVien, ThoiGianBD, TrangThai, GhiChu)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmtIns->execute([$maThuCung, $maDichVu, $nvVal, $startSql, $trangThai, $ghiChuVal]);
            }

            $newLH = (int)$conn->lastInsertId();
            $success = 'Đã tạo lịch hẹn mới ' . formatMaLH($newLH) . ' thành công!';
            $openCreateModal = false;
        } catch (PDOException $e) {
            $modalError = 'Lỗi tạo lịch hẹn: ' . $e->getMessage();
        }
    }
}

// 3. XỬ LÝ ĐIỀU PHỐI & CẬP NHẬT LỊCH HẸN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_booking') {
    $maLH        = (int)($_POST['ma_lich_hen'] ?? 0);
    $maDichVu    = (int)($_POST['ma_dich_vu'] ?? 0);
    $maNhanVien  = (int)($_POST['ma_nhan_vien'] ?? 0);
    $thoiGianRaw = trim($_POST['thoi_gian_bd'] ?? '');
    $trangThai   = $_POST['trang_thai'] ?? '';
    $ghiChu      = trim($_POST['ghi_chu'] ?? '');

    $validStatuses = ['Chờ xác nhận', 'Đã xác nhận', 'Đang thực hiện', 'Hoàn thành', 'Đã hủy'];

    if ($maLH <= 0 || $thoiGianRaw === '' || !in_array($trangThai, $validStatuses, true)) {
        $error = 'Dữ liệu cập nhật lịch hẹn không hợp lệ!';
    } else {
        try {
            $startSql  = date('Y-m-d H:i:s', strtotime($thoiGianRaw));
            $nvVal     = ($maNhanVien > 0) ? $maNhanVien : null;
            $ghiChuVal = ($ghiChu !== '') ? $ghiChu : null;

            // Nếu Admin gán nhân viên cho lịch đang "Chờ xác nhận" thì tự động chuyển sang "Đã xác nhận"
            if ($nvVal !== null && $trangThai === 'Chờ xác nhận') {
                $trangThai = 'Đã xác nhận';
            }

            if ($hasThoiGianKT) {
                $endSql = calcEndTime($conn, $maDichVu, $startSql, $hasThoiLuongDV);
                $stmtUpd = $conn->prepare("
                    UPDATE lichhen
                    SET MaDichVu = ?, MaNhanVien = ?, ThoiGianBD = ?, ThoiGianKT = ?, TrangThai = ?, GhiChu = ?
                    WHERE MaLichHen = ?
                ");
                $stmtUpd->execute([$maDichVu, $nvVal, $startSql, $endSql, $trangThai, $ghiChuVal, $maLH]);
            } else {
                $stmtUpd = $conn->prepare("
                    UPDATE lichhen
                    SET MaDichVu = ?, MaNhanVien = ?, ThoiGianBD = ?, TrangThai = ?, GhiChu = ?
                    WHERE MaLichHen = ?
                ");
                $stmtUpd->execute([$maDichVu, $nvVal, $startSql, $trangThai, $ghiChuVal, $maLH]);
            }

            $success = 'Đã cập nhật & điều phối lịch hẹn ' . formatMaLH($maLH) . '!';
        } catch (PDOException $e) {
            $error = 'Lỗi cập nhật lịch hẹn: ' . $e->getMessage();
        }
    }
}

// 4. XỬ LÝ XÓA LỊCH HẸN
if (isset($_GET['xoa'])) {
    $maLHXoa = (int)$_GET['xoa'];
    try {
        $conn->prepare("DELETE FROM lichhen WHERE MaLichHen = ?")->execute([$maLHXoa]);
        $success = 'Đã xóa lịch hẹn ' . formatMaLH($maLHXoa) . ' khỏi danh sách!';
    } catch (PDOException $e) {
        $error = 'Không thể xóa lịch hẹn này: ' . $e->getMessage();
    }
}

// 5. THỐNG KÊ NHANH 4 THẺ KPI
$tongLichHen = (int)$conn->query("SELECT COUNT(*) FROM lichhen")->fetchColumn();
$lichHomNay  = (int)$conn->query("SELECT COUNT(*) FROM lichhen WHERE DATE(ThoiGianBD) = CURDATE() AND TrangThai != 'Đã hủy'")->fetchColumn();

$lichChoDuyet = (int)$conn->query("SELECT COUNT(*) FROM lichhen WHERE TrangThai = 'Chờ xác nhận'")->fetchColumn();
$lichChuaGanNV = (int)$conn->query("
    SELECT COUNT(*) FROM lichhen
    WHERE MaNhanVien IS NULL AND TrangThai IN ('Chờ xác nhận', 'Đã xác nhận')
")->fetchColumn();

$lichDangPhucVu = (int)$conn->query("
    SELECT COUNT(*) FROM lichhen
    WHERE TrangThai IN ('Đã xác nhận', 'Đang thực hiện')
")->fetchColumn();

$stmtDoneStats = $conn->query("
    SELECT COUNT(*) AS sl, COALESCE(SUM(dv.GiaTien), 0) AS dt
    FROM lichhen lh
    JOIN dichvu dv ON lh.MaDichVu = dv.MaDichVu
    WHERE lh.TrangThai = 'Hoàn thành'
");
$doneStats      = $stmtDoneStats->fetch();
$soLichHoanThanh = (int)($doneStats['sl'] ?? 0);
$doanhThuHoanThanh = (float)($doneStats['dt'] ?? 0);

// 6. BỘ LỌC & TRUY VẤN DANH SÁCH LỊCH HẸN
$keyword      = trim($_GET['q'] ?? '');
$statusFilter = $_GET['trangthai'] ?? 'tatca';
$staffFilter  = $_GET['nhanvien'] ?? 'tatca';
$dateFilter   = trim($_GET['ngay'] ?? '');

$petNoteSelect = $colPetNote ? "tc.`{$colPetNote}` AS GhiChuThuCung," : "NULL AS GhiChuThuCung,";
$durationSelect = $hasThoiLuongDV ? "dv.ThoiLuong," : "'60 phút' AS ThoiLuong,";

$sql = "
    SELECT lh.MaLichHen, lh.MaThuCung, lh.MaDichVu, lh.MaNhanVien,
           lh.ThoiGianBD, lh.TrangThai, lh.GhiChu,
           tc.TenThuCung, tc.`{$colSpecies}` AS LoaiThuCung,
           {$petNoteSelect}
           kh.MaKhachHang, kh.HoTen AS TenKhachHang, tk.SDT AS SDTKhach, tk.Email AS EmailKhach,
           dv.TenDichVu, dv.GiaTien, {$durationSelect}
           nv.HoTen AS TenNhanVien
    FROM lichhen lh
    JOIN thucung tc ON lh.MaThuCung = tc.MaThuCung
    JOIN khachhang kh ON tc.MaKhachHang = kh.MaKhachHang
    JOIN taikhoan tk ON kh.MaTaiKhoan = tk.MaTaiKhoan
    JOIN dichvu dv ON lh.MaDichVu = dv.MaDichVu
    LEFT JOIN nhanvien nv ON lh.MaNhanVien = nv.MaNhanVien
    WHERE 1=1
";
$params = [];

if ($keyword !== '') {
    $sql .= " AND (
        CONCAT('LH', LPAD(lh.MaLichHen, 3, '0')) LIKE ?
        OR kh.HoTen LIKE ?
        OR tk.SDT LIKE ?
        OR tc.TenThuCung LIKE ?
        OR dv.TenDichVu LIKE ?
    )";
    $like = '%' . $keyword . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($statusFilter === 'chua_phan_cong') {
    $sql .= " AND lh.MaNhanVien IS NULL AND lh.TrangThai IN ('Chờ xác nhận', 'Đã xác nhận')";
} elseif ($statusFilter !== 'tatca') {
    $sql .= " AND lh.TrangThai = ?";
    $params[] = $statusFilter;
}

if ($staffFilter === 'unassigned') {
    $sql .= " AND lh.MaNhanVien IS NULL";
} elseif ($staffFilter !== 'tatca' && ctype_digit($staffFilter)) {
    $sql .= " AND lh.MaNhanVien = ?";
    $params[] = (int)$staffFilter;
}

if ($dateFilter !== '') {
    $sql .= " AND DATE(lh.ThoiGianBD) = ?";
    $params[] = $dateFilter;
}

$sql .= "
    ORDER BY
        CASE lh.TrangThai
            WHEN 'Chờ xác nhận' THEN 1
            WHEN 'Đang thực hiện' THEN 2
            WHEN 'Đã xác nhận' THEN 3
            WHEN 'Hoàn thành' THEN 4
            ELSE 5
        END ASC,
        lh.ThoiGianBD DESC
";

$stmtList = $conn->prepare($sql);
$stmtList->execute($params);
$dsLichHen = $stmtList->fetchAll();

// 7. DỮ LIỆU PHỤ TRỢ CHO FORM ĐIỀU PHỐI & MODAL TẠO LỊCH MỚI
$dsNhanVien = $conn->query("SELECT MaNhanVien, HoTen FROM nhanvien ORDER BY HoTen ASC")->fetchAll();
$dsDichVu   = $conn->query("SELECT MaDichVu, TenDichVu, GiaTien FROM dichvu ORDER BY MaDichVu ASC")->fetchAll();
$dsKhachHang = $conn->query("
    SELECT kh.MaKhachHang, kh.HoTen, tk.SDT
    FROM khachhang kh
    JOIN taikhoan tk ON kh.MaTaiKhoan = tk.MaTaiKhoan
    ORDER BY kh.HoTen ASC
")->fetchAll();
$dsThuCung = $conn->query("
    SELECT MaThuCung, MaKhachHang, TenThuCung, `{$colSpecies}` AS Loai
    FROM thucung
    ORDER BY TenThuCung ASC
")->fetchAll();

// Tải chi tiết lịch hẹn đang mở điều phối (nếu có ?dieuphoi=ID)
$dispatchBooking = null;
if (isset($_GET['dieuphoi'])) {
    $idDispatch = (int)$_GET['dieuphoi'];
    foreach ($dsLichHen as $bItem) {
        if ((int)$bItem['MaLichHen'] === $idDispatch) {
            $dispatchBooking = $bItem;
            break;
        }
    }
    if (!$dispatchBooking) {
        $stmtOne = $conn->prepare("
            SELECT lh.MaLichHen, lh.MaThuCung, lh.MaDichVu, lh.MaNhanVien,
                   lh.ThoiGianBD, lh.TrangThai, lh.GhiChu,
                   tc.TenThuCung, tc.`{$colSpecies}` AS LoaiThuCung,
                   {$petNoteSelect}
                   kh.MaKhachHang, kh.HoTen AS TenKhachHang, tk.SDT AS SDTKhach, tk.Email AS EmailKhach,
                   dv.TenDichVu, dv.GiaTien, {$durationSelect}
                   nv.HoTen AS TenNhanVien
            FROM lichhen lh
            JOIN thucung tc ON lh.MaThuCung = tc.MaThuCung
            JOIN khachhang kh ON tc.MaKhachHang = kh.MaKhachHang
            JOIN taikhoan tk ON kh.MaTaiKhoan = tk.MaTaiKhoan
            JOIN dichvu dv ON lh.MaDichVu = dv.MaDichVu
            LEFT JOIN nhanvien nv ON lh.MaNhanVien = nv.MaNhanVien
            WHERE lh.MaLichHen = ?
        ");
        $stmtOne->execute([$idDispatch]);
        $dispatchBooking = $stmtOne->fetch();
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý lịch hẹn</title>
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
            max-width: 1300px;
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

        /* THÔNG BÁO */
        .alert-error {
            background-color: #fde8e8;
            color: #c81e1e;
            border: 1px solid #f8b4b4;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13.5px;
            margin-bottom: 18px;
        }

        .alert-success {
            background-color: #def7ec;
            color: #03543f;
            border: 1px solid #84e1bc;
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 13.5px;
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
            border-radius: 14px;
            padding: 20px 22px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .stat-title {
            font-size: 14px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .stat-sub {
            font-size: 12.5px;
            color: #77857a;
            margin-bottom: 12px;
        }

        .stat-value {
            font-size: 25px;
            font-weight: 800;
            color: #4f6653;
            line-height: 1.15;
        }

        /* ================= KHUNG BỘ LỌC & TẠO LỊCH HẸN ================= */
        .filter-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .filter-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }

        .filter-header-row h2 {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 3px;
        }

        .filter-header-row p {
            font-size: 13px;
            color: #77857a;
        }

        .btn-primary {
            background-color: #556e57;
            color: #ffffff;
            border: none;
            text-decoration: none;
            padding: 10px 22px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
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
            padding: 10px 18px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 600;
            cursor: pointer;
            display: inline-block;
            transition: background-color 0.2s;
        }

        .btn-secondary:hover {
            background-color: #f3f6f1;
        }

        .filter-form {
            display: grid;
            grid-template-columns: 1.5fr 1fr 1fr 0.9fr auto;
            gap: 12px;
            align-items: end;
        }

        .filter-field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filter-field label {
            font-size: 12.5px;
            font-weight: 700;
            color: #4f5e53;
        }

        .filter-field input,
        .filter-field select {
            width: 100%;
            padding: 10px 13px;
            border: 1px solid #dce3d9;
            border-radius: 9px;
            font-size: 13.5px;
            color: #243429;
            background-color: #ffffff;
            outline: none;
            transition: border-color 0.2s;
        }

        .filter-field input:focus,
        .filter-field select:focus {
            border-color: #556e57;
        }

        .filter-btn-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .btn-filter {
            background-color: #eef3eb;
            color: #243429;
            border: 1px solid #d5ddd2;
            padding: 10px 18px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-filter:hover {
            background-color: #dfe7da;
        }

        /* ================= KHUNG ĐIỀU PHỐI & CẬP NHẬT LỊCH HẸN ================= */
        .dispatch-card {
            background-color: #ffffff;
            border: 1.5px solid #556e57;
            border-radius: 14px;
            padding: 24px 28px;
            margin-bottom: 22px;
            box-shadow: 0 6px 20px rgba(36, 52, 41, 0.06);
        }

        .dispatch-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 16px;
            margin-bottom: 18px;
            border-bottom: 1px solid #f0ede6;
            flex-wrap: wrap;
        }

        .dispatch-top h3 {
            font-size: 18px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .dispatch-top p {
            font-size: 13px;
            color: #77857a;
        }

        .dispatch-summary-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 14px;
            background-color: #faf7f2;
            border: 1px solid #eceae4;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 18px;
        }

        .summary-box span {
            display: block;
            font-size: 11.5px;
            font-weight: 700;
            text-transform: uppercase;
            color: #77857a;
            margin-bottom: 4px;
        }

        .summary-box strong {
            display: block;
            font-size: 14px;
            color: #243429;
        }

        .summary-box small {
            display: block;
            font-size: 12.5px;
            color: #4f5e53;
            margin-top: 2px;
        }

        .dispatch-form-grid {
            display: grid;
            grid-template-columns: 1.1fr 1fr 1fr 1.3fr;
            gap: 14px;
            margin-bottom: 16px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 12.5px;
            font-weight: 700;
            color: #36473b;
        }

        .form-group label span.req {
            color: #c81e1e;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 13px;
            border: 1px solid #dce3d9;
            border-radius: 8px;
            font-size: 13.5px;
            color: #243429;
            background-color: #fcfcfa;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #556e57;
            background-color: #ffffff;
            box-shadow: 0 0 0 3px rgba(85, 110, 87, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 70px;
        }

        /* ================= BẢNG DANH SÁCH LỊCH HẸN ================= */
        .booking-table {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .booking-header,
        .booking-row {
            display: grid;
            grid-template-columns: 86px 1.35fr 1.25fr 1.05fr 1.1fr 130px 165px;
            align-items: center;
            gap: 12px;
            padding: 15px 20px;
            border-radius: 10px;
        }

        .booking-header {
            background-color: #e9eee4;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #6b786e;
            text-transform: uppercase;
        }

        .booking-row {
            background-color: #ffffff;
            border: 1px solid #f0ede6;
            font-size: 13.5px;
            color: #2c3e32;
            transition: box-shadow 0.2s, border-color 0.2s;
        }

        .booking-row:hover {
            box-shadow: 0 4px 14px rgba(36, 52, 41, 0.05);
        }

        .booking-row.active-row {
            border-color: #556e57;
            background-color: #fcfdf9;
        }

        .col-code {
            font-weight: 800;
            color: #243429;
        }

        .col-main strong {
            display: block;
            font-size: 14px;
            font-weight: 700;
            color: #243429;
        }

        .col-main small {
            display: block;
            font-size: 12px;
            color: #77857a;
            margin-top: 2px;
        }

        .pet-tag-inline {
            display: inline-block;
            background-color: #eef3eb;
            color: #2f5438;
            font-size: 11.5px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 12px;
            margin-top: 4px;
        }

        .staff-unassigned {
            display: inline-block;
            background-color: #fef3c7;
            color: #92400e;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 7px;
        }

        .staff-assigned {
            font-weight: 600;
            color: #243429;
        }

        /* Nhãn trạng thái lịch hẹn đồng bộ với Tổng quan */
        .status-pill-badge {
            display: inline-block;
            padding: 5px 11px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-align: center;
        }

        .st-pending {
            background-color: #f3e7c9;
            color: #7a5814;
        }

        .st-confirmed {
            background-color: #d8e2e6;
            color: #234756;
        }

        .st-progress {
            background-color: #e4e2f2;
            color: #3f367a;
        }

        .st-done {
            background-color: #e2eadc;
            color: #284b31;
        }

        .st-cancelled {
            background-color: #fde8e8;
            color: #b91c1c;
        }

        .row-actions {
            display: flex;
            align-items: center;
            gap: 7px;
            flex-wrap: wrap;
        }

        .action-link {
            text-decoration: none;
            font-weight: 700;
            font-size: 12.5px;
            color: #364f3c;
        }

        .action-link:hover {
            text-decoration: underline;
        }

        .action-link.approve {
            background-color: #556e57;
            color: #ffffff;
            padding: 4px 9px;
            border-radius: 6px;
        }

        .action-link.approve:hover {
            background-color: #445946;
            text-decoration: none;
        }

        .action-link.delete {
            color: #c81e1e;
        }

        .dot-sep {
            color: #b0bab2;
        }

        .empty-row {
            background-color: #ffffff;
            border: 1px dashed #d3dcd0;
            border-radius: 10px;
            padding: 36px 20px;
            text-align: center;
            color: #6b786e;
            font-size: 14px;
        }

        /* ================= MODAL TẠO LỊCH HẸN MỚI ================= */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background-color: rgba(24, 34, 27, 0.5);
            backdrop-filter: blur(2px);
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 300;
        }

        .modal-backdrop.open {
            display: flex;
        }

        .modal-box {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 16px;
            width: 100%;
            max-width: 660px;
            max-height: 92vh;
            overflow-y: auto;
            box-shadow: 0 20px 44px rgba(24, 34, 27, 0.22);
            animation: modalFadeIn 0.2s ease-out;
        }

        @keyframes modalFadeIn {
            from {
                opacity: 0;
                transform: translateY(-10px) scale(0.98);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        .modal-header {
            padding: 22px 28px 18px 28px;
            border-bottom: 1px solid #f0ede6;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }

        .modal-header h3 {
            font-size: 20px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .modal-header p {
            font-size: 13px;
            color: #77857a;
        }

        .btn-close-modal {
            background-color: #f4f6f2;
            border: 1px solid #e2e7df;
            color: #4f5e53;
            width: 34px;
            height: 34px;
            border-radius: 8px;
            font-size: 20px;
            line-height: 1;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .btn-close-modal:hover {
            background-color: #e6ece2;
            color: #243429;
        }

        .modal-body {
            padding: 22px 28px;
        }

        .modal-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px 18px;
        }

        .span-2 {
            grid-column: 1 / -1;
        }

        .modal-footer {
            padding: 16px 28px 22px 28px;
            border-top: 1px solid #f0ede6;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        @media (max-width: 1220px) {
            .stats-grid,
            .dispatch-form-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-form {
                grid-template-columns: 1fr 1fr;
            }
            .booking-header {
                display: none;
            }
            .booking-row {
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
            .stats-grid,
            .filter-form,
            .dispatch-summary-strip,
            .dispatch-form-grid,
            .modal-grid-2 {
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
                <li><a href="admin_tongquan.php">Tổng quan</a></li>
                <li><a href="admin_taikhoan.php">Quản lý tài khoản</a></li>
                <li><a href="admin_khachhang_thucung.php">Khách hàng & thú cưng</a></li>
                <li><a href="admin_dichvu.php">Dịch vụ & bảng giá</a></li>
                <li><a href="admin_lichhen.php" class="active">Lịch hẹn</a></li>
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
                <h1>Quản lý lịch hẹn</h1>
                <p>Xin chào! Đây là khu vực admin của bạn.</p>
            </div>

            <div class="user-pill">
                <span class="status-dot"></span>
                <span>Xin chào, <?= htmlspecialchars($hoTenAdmin) ?></span>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert-success"><?= $success ?></div>
        <?php endif; ?>

        <!-- 4 THẺ THỐNG KÊ TỔNG QUAN -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-title">Tổng lịch hẹn</div>
                <div class="stat-sub"><?= $lichHomNay ?> lịch trong hôm nay</div>
                <div class="stat-value"><?= number_format($tongLichHen, 0, ',', '.') ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Chờ xác nhận</div>
                <div class="stat-sub"><?= $lichChuaGanNV ?> lịch chưa phân công NV</div>
                <div class="stat-value"><?= number_format($lichChoDuyet, 0, ',', '.') ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Đang phục vụ</div>
                <div class="stat-sub">Đã xác nhận & đang thực hiện</div>
                <div class="stat-value"><?= number_format($lichDangPhucVu, 0, ',', '.') ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Đã hoàn thành</div>
                <div class="stat-sub">Doanh thu: <?= number_format($doanhThuHoanThanh, 0, ',', '.') ?>đ</div>
                <div class="stat-value"><?= number_format($soLichHoanThanh, 0, ',', '.') ?></div>
            </div>
        </div>

        <!-- KHUNG BỘ LỌC & TẠO LỊCH HẸN MỚI -->
        <div class="filter-card">
            <div class="filter-header-row">
                <div>
                    <h2>Danh sách lịch hẹn</h2>
                    <p>Duyệt yêu cầu đặt lịch, phân công nhân viên phụ trách và cập nhật tiến độ chăm sóc.</p>
                </div>

                <button type="button" class="btn-primary" id="btn-open-create-modal">
                    + Tạo lịch hẹn mới
                </button>
            </div>

            <form method="GET" action="admin_lichhen.php" class="filter-form">
                <div class="filter-field">
                    <label>Tìm kiếm</label>
                    <input type="text" name="q" placeholder="Mã lịch, tên khách, SĐT, thú cưng..."
                           value="<?= htmlspecialchars($keyword) ?>">
                </div>

                <div class="filter-field">
                    <label>Trạng thái</label>
                    <select name="trangthai">
                        <option value="tatca" <?= $statusFilter === 'tatca' ? 'selected' : '' ?>>Tất cả trạng thái</option>
                        <option value="Chờ xác nhận" <?= $statusFilter === 'Chờ xác nhận' ? 'selected' : '' ?>>Chờ xác nhận</option>
                        <option value="chua_phan_cong" <?= $statusFilter === 'chua_phan_cong' ? 'selected' : '' ?>>Chưa phân công NV</option>
                        <option value="Đã xác nhận" <?= $statusFilter === 'Đã xác nhận' ? 'selected' : '' ?>>Đã xác nhận</option>
                        <option value="Đang thực hiện" <?= $statusFilter === 'Đang thực hiện' ? 'selected' : '' ?>>Đang thực hiện</option>
                        <option value="Hoàn thành" <?= $statusFilter === 'Hoàn thành' ? 'selected' : '' ?>>Hoàn thành</option>
                        <option value="Đã hủy" <?= $statusFilter === 'Đã hủy' ? 'selected' : '' ?>>Đã hủy</option>
                    </select>
                </div>

                <div class="filter-field">
                    <label>Nhân viên phụ trách</label>
                    <select name="nhanvien">
                        <option value="tatca" <?= $staffFilter === 'tatca' ? 'selected' : '' ?>>Tất cả nhân viên</option>
                        <option value="unassigned" <?= $staffFilter === 'unassigned' ? 'selected' : '' ?>>Chưa phân công</option>
                        <?php foreach ($dsNhanVien as $nvOpt): ?>
                            <option value="<?= $nvOpt['MaNhanVien'] ?>" <?= (string)$staffFilter === (string)$nvOpt['MaNhanVien'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($nvOpt['HoTen']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-field">
                    <label>Ngày hẹn</label>
                    <input type="date" name="ngay" value="<?= htmlspecialchars($dateFilter) ?>">
                </div>

                <div class="filter-btn-group">
                    <button type="submit" class="btn-filter">Lọc</button>
                    <?php if ($keyword !== '' || $statusFilter !== 'tatca' || $staffFilter !== 'tatca' || $dateFilter !== ''): ?>
                        <a href="admin_lichhen.php" class="btn-secondary">Đặt lại</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- KHUNG ĐIỀU PHỐI & CẬP NHẬT LỊCH HẸN -->
        <?php if ($dispatchBooking): ?>
            <?php $maLHCur = formatMaLH($dispatchBooking['MaLichHen']); ?>
            <div class="dispatch-card" id="dispatch-panel">
                <div class="dispatch-top">
                    <div>
                        <h3>Điều phối & chi tiết lịch hẹn <?= $maLHCur ?></h3>
                        <p>Phân công nhân viên thực hiện, điều chỉnh thời gian hoặc cập nhật trạng thái xử lý.</p>
                    </div>
                    <a href="admin_lichhen.php" class="btn-secondary">Đóng chi tiết</a>
                </div>

                <div class="dispatch-summary-strip">
                    <div class="summary-box">
                        <span>Khách hàng liên hệ</span>
                        <strong><?= htmlspecialchars($dispatchBooking['TenKhachHang']) ?></strong>
                        <small>SĐT: <?= htmlspecialchars($dispatchBooking['SDTKhach']) ?> · <?= htmlspecialchars($dispatchBooking['EmailKhach']) ?></small>
                    </div>

                    <div class="summary-box">
                        <span>Thú cưng chăm sóc</span>
                        <strong><?= htmlspecialchars($dispatchBooking['TenThuCung']) ?> (<?= htmlspecialchars($dispatchBooking['LoaiThuCung']) ?>)</strong>
                        <small><?= !empty($dispatchBooking['GhiChuThuCung']) ? 'Lưu ý bé: ' . htmlspecialchars($dispatchBooking['GhiChuThuCung']) : 'Không có lưu ý sức khỏe đặc biệt' ?></small>
                    </div>

                    <div class="summary-box">
                        <span>Dịch vụ đã chọn</span>
                        <strong><?= htmlspecialchars($dispatchBooking['TenDichVu']) ?></strong>
                        <small>Giá: <?= number_format((float)$dispatchBooking['GiaTien'], 0, ',', '.') ?>đ · Thời lượng: <?= htmlspecialchars($dispatchBooking['ThoiLuong']) ?></small>
                    </div>
                </div>

                <form method="POST" action="admin_lichhen.php">
                    <input type="hidden" name="action" value="update_booking">
                    <input type="hidden" name="ma_lich_hen" value="<?= (int)$dispatchBooking['MaLichHen'] ?>">

                    <div class="dispatch-form-grid">
                        <div class="form-group">
                            <label>Nhân viên phụ trách</label>
                            <select name="ma_nhan_vien">
                                <option value="0">-- Chưa phân công nhân viên --</option>
                                <?php foreach ($dsNhanVien as $nvItem): ?>
                                    <option value="<?= $nvItem['MaNhanVien'] ?>" <?= (int)$dispatchBooking['MaNhanVien'] === (int)$nvItem['MaNhanVien'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($nvItem['HoTen']) ?> (NV<?= str_pad($nvItem['MaNhanVien'], 3, '0', STR_PAD_LEFT) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Trạng thái lịch hẹn <span class="req">*</span></label>
                            <select name="trang_thai" required>
                                <?php foreach (['Chờ xác nhận', 'Đã xác nhận', 'Đang thực hiện', 'Hoàn thành', 'Đã hủy'] as $stOpt): ?>
                                    <option value="<?= $stOpt ?>" <?= $dispatchBooking['TrangThai'] === $stOpt ? 'selected' : '' ?>>
                                        <?= $stOpt ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Thời gian hẹn <span class="req">*</span></label>
                            <input type="datetime-local" name="thoi_gian_bd" required
                                   value="<?= date('Y-m-d\TH:i', strtotime($dispatchBooking['ThoiGianBD'])) ?>">
                        </div>

                        <div class="form-group">
                            <label>Dịch vụ áp dụng</label>
                            <select name="ma_dich_vu">
                                <?php foreach ($dsDichVu as $dvOpt): ?>
                                    <option value="<?= $dvOpt['MaDichVu'] ?>" <?= (int)$dispatchBooking['MaDichVu'] === (int)$dvOpt['MaDichVu'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($dvOpt['TenDichVu']) ?> (<?= number_format((float)$dvOpt['GiaTien'], 0, ',', '.') ?>đ)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Ghi chú lịch hẹn / Yêu cầu của khách</label>
                            <textarea name="ghi_chu" placeholder="Ghi chú thêm cho nhân viên chăm sóc..."><?= htmlspecialchars($dispatchBooking['GhiChu'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Lưu điều phối lịch hẹn</button>
                </form>
            </div>
        <?php endif; ?>

        <!-- BẢNG DANH SÁCH LỊCH HẸN -->
        <div class="booking-table">
            <div class="booking-header">
                <div>MÃ LỊCH</div>
                <div>KHÁCH HÀNG & THÚ CƯNG</div>
                <div>DỊCH VỤ</div>
                <div>THỜI GIAN</div>
                <div>NHÂN VIÊN</div>
                <div>TRẠNG THÁI</div>
                <div>THAO TÁC</div>
            </div>

            <?php if (count($dsLichHen) > 0): ?>
                <?php foreach ($dsLichHen as $lh): ?>
                    <?php
                        $maLHStr   = formatMaLH($lh['MaLichHen']);
                        $tsStart   = strtotime($lh['ThoiGianBD']);
                        $isCurrent = ($dispatchBooking && (int)$dispatchBooking['MaLichHen'] === (int)$lh['MaLichHen']);
                    ?>
                    <div class="booking-row <?= $isCurrent ? 'active-row' : '' ?>">
                        <div class="col-code"><?= $maLHStr ?></div>

                        <div class="col-main">
                            <strong><?= htmlspecialchars($lh['TenKhachHang']) ?></strong>
                            <small><?= htmlspecialchars($lh['SDTKhach']) ?></small>
                            <span class="pet-tag-inline">
                                <?= htmlspecialchars($lh['TenThuCung']) ?> (<?= htmlspecialchars($lh['LoaiThuCung']) ?>)
                            </span>
                        </div>

                        <div class="col-main">
                            <strong><?= htmlspecialchars($lh['TenDichVu']) ?></strong>
                            <small><?= number_format((float)$lh['GiaTien'], 0, ',', '.') ?>đ · <?= htmlspecialchars($lh['ThoiLuong']) ?></small>
                        </div>

                        <div class="col-main">
                            <strong><?= date('H:i', $tsStart) ?></strong>
                            <small><?= date('d/m/Y', $tsStart) ?></small>
                        </div>

                        <div>
                            <?php if (!empty($lh['TenNhanVien'])): ?>
                                <span class="staff-assigned"><?= htmlspecialchars($lh['TenNhanVien']) ?></span>
                            <?php else: ?>
                                <span class="staff-unassigned">Chưa phân công</span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <span class="status-pill-badge <?= getStatusBadgeClass($lh['TrangThai']) ?>">
                                <?= htmlspecialchars($lh['TrangThai']) ?>
                            </span>
                        </div>

                        <div class="row-actions">
                            <?php if ($lh['TrangThai'] === 'Chờ xác nhận'): ?>
                                <a href="admin_lichhen.php?duyet=<?= $lh['MaLichHen'] ?>" class="action-link approve">
                                    Duyệt
                                </a>
                            <?php endif; ?>

                            <a href="admin_lichhen.php?dieuphoi=<?= $lh['MaLichHen'] ?>#dispatch-panel" class="action-link">
                                Điều phối
                            </a>

                            <span class="dot-sep">·</span>
                            <a href="admin_lichhen.php?xoa=<?= $lh['MaLichHen'] ?>"
                               class="action-link delete"
                               onclick="return confirm('Bạn chắc chắn muốn xóa lịch hẹn <?= $maLHStr ?> không?')">
                                Xóa
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-row">
                    Không tìm thấy lịch hẹn nào phù hợp với điều kiện lọc hiện tại.
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- ================= MODAL TẠO LỊCH HẸN MỚI ================= -->
<div class="modal-backdrop <?= $openCreateModal ? 'open' : '' ?>" id="create-booking-modal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3>Tạo lịch hẹn mới</h3>
                <p>Đặt lịch dịch vụ trực tiếp cho khách hàng tại quầy hoặc qua điện thoại.</p>
            </div>
            <button type="button" class="btn-close-modal" id="btn-close-create-modal">×</button>
        </div>

        <form method="POST" action="admin_lichhen.php">
            <input type="hidden" name="action" value="create_booking">

            <div class="modal-body">
                <?php if (!empty($modalError)): ?>
                    <div class="alert-error"><?= htmlspecialchars($modalError) ?></div>
                <?php endif; ?>

                <div class="modal-grid-2">
                    <div class="form-group">
                        <label>Khách hàng <span class="req">*</span></label>
                        <select id="select-customer-modal" required>
                            <option value="">-- Chọn khách hàng --</option>
                            <?php foreach ($dsKhachHang as $khOpt): ?>
                                <option value="<?= $khOpt['MaKhachHang'] ?>">
                                    <?= htmlspecialchars($khOpt['HoTen']) ?> (<?= htmlspecialchars($khOpt['SDT']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Thú cưng <span class="req">*</span></label>
                        <select name="ma_thu_cung" id="select-pet-modal" required>
                            <option value="">-- Chọn thú cưng --</option>
                            <?php foreach ($dsThuCung as $tcOpt): ?>
                                <option value="<?= $tcOpt['MaThuCung'] ?>" data-kh="<?= $tcOpt['MaKhachHang'] ?>">
                                    <?= htmlspecialchars($tcOpt['TenThuCung']) ?> (<?= htmlspecialchars($tcOpt['Loai']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Dịch vụ <span class="req">*</span></label>
                        <select name="ma_dich_vu" required>
                            <option value="">-- Chọn dịch vụ --</option>
                            <?php foreach ($dsDichVu as $dvOpt): ?>
                                <option value="<?= $dvOpt['MaDichVu'] ?>">
                                    <?= htmlspecialchars($dvOpt['TenDichVu']) ?> - <?= number_format((float)$dvOpt['GiaTien'], 0, ',', '.') ?>đ
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Thời gian hẹn <span class="req">*</span></label>
                        <input type="datetime-local" name="thoi_gian_bd" required
                               value="<?= date('Y-m-d\TH:i', strtotime('+1 hour')) ?>">
                    </div>

                    <div class="form-group">
                        <label>Phân công nhân viên</label>
                        <select name="ma_nhan_vien">
                            <option value="0">-- Chưa phân công --</option>
                            <?php foreach ($dsNhanVien as $nvOpt): ?>
                                <option value="<?= $nvOpt['MaNhanVien'] ?>">
                                    <?= htmlspecialchars($nvOpt['HoTen']) ?> (NV<?= str_pad($nvOpt['MaNhanVien'], 3, '0', STR_PAD_LEFT) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Trạng thái ban đầu</label>
                        <select name="trang_thai">
                            <option value="Đã xác nhận" selected>Đã xác nhận</option>
                            <option value="Chờ xác nhận">Chờ xác nhận</option>
                            <option value="Đang thực hiện">Đang thực hiện</option>
                        </select>
                    </div>

                    <div class="form-group span-2">
                        <label>Ghi chú yêu cầu</label>
                        <textarea name="ghi_chu" placeholder="Ví dụ: Khách đặt qua hotline, yêu cầu cắt gọn lông vùng mặt..."></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="btn-cancel-create-modal">Hủy bỏ</button>
                <button type="submit" class="btn-primary">+ Lưu lịch hẹn</button>
            </div>
        </form>
    </div>
</div>

<script>
    const createModal    = document.getElementById('create-booking-modal');
    const btnOpenCreate  = document.getElementById('btn-open-create-modal');
    const btnCloseCreate = document.getElementById('btn-close-create-modal');
    const btnCancelCreate = document.getElementById('btn-cancel-create-modal');

    if (btnOpenCreate) btnOpenCreate.addEventListener('click', () => createModal.classList.add('open'));
    if (btnCloseCreate) btnCloseCreate.addEventListener('click', () => createModal.classList.remove('open'));
    if (btnCancelCreate) btnCancelCreate.addEventListener('click', () => createModal.classList.remove('open'));

    createModal.addEventListener('click', (e) => {
        if (e.target === createModal) createModal.classList.remove('open');
    });

    // Tự động lọc danh sách thú cưng theo khách hàng được chọn trong Modal
    const selectCustomer = document.getElementById('select-customer-modal');
    const selectPet      = document.getElementById('select-pet-modal');

    if (selectCustomer && selectPet) {
        const allPetOptions = Array.from(selectPet.querySelectorAll('option[data-kh]'));

        selectCustomer.addEventListener('change', () => {
            const khId = selectCustomer.value;
            selectPet.value = '';
            allPetOptions.forEach((opt) => {
                if (!khId || opt.dataset.kh === khId) {
                    opt.hidden = false;
                    opt.disabled = false;
                } else {
                    opt.hidden = true;
                    opt.disabled = true;
                }
            });
        });
    }
</script>

</body>
</html>