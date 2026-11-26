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

$hoTenAdmin      = $_SESSION['HoTen'] ?? 'Quản trị viên';
$error           = '';
$success         = '';
$modalCustError  = '';
$modalPetError   = '';
$openCustModal   = false;
$openPetModal    = false;
$preselectCustId = (int)($_GET['them_thucung_kh'] ?? 0);
if ($preselectCustId > 0) {
    $openPetModal = true;
}

// Hàm đọc cấu trúc cột thực tế để tương thích hoàn toàn với database hiện có
function getColsMeta(PDO $conn, string $table): array {
    $cols = [];
    $stmt = $conn->query("SHOW COLUMNS FROM `{$table}`");
    if ($stmt) {
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $cols[$row['Field']] = $row;
        }
    }
    return $cols;
}

// Đảm bảo bảng khachhang có cột DiaChi
$khCols = getColsMeta($conn, 'khachhang');
if (!isset($khCols['DiaChi'])) {
    try {
        $conn->exec("ALTER TABLE khachhang ADD COLUMN DiaChi VARCHAR(255) NULL");
        $khCols = getColsMeta($conn, 'khachhang');
    } catch (PDOException $e) {}
}

// Nhận diện & bổ sung an toàn các cột nghiệp vụ của bảng thucung
$tcCols = getColsMeta($conn, 'thucung');

$colSpecies = null;
foreach (['Loai', 'LoaiThuCung', 'ChungLoai'] as $cand) {
    if (isset($tcCols[$cand])) { $colSpecies = $cand; break; }
}
if (!$colSpecies) {
    $conn->exec("ALTER TABLE thucung ADD COLUMN Loai VARCHAR(50) NOT NULL DEFAULT 'Chó'");
    $colSpecies = 'Loai';
}

$colBreed = null;
foreach (['Giong', 'GiongLoai'] as $cand) {
    if (isset($tcCols[$cand])) { $colBreed = $cand; break; }
}
if (!$colBreed) {
    $conn->exec("ALTER TABLE thucung ADD COLUMN Giong VARCHAR(100) NULL");
    $colBreed = 'Giong';
}

$colAge = null;
foreach (['Tuoi', 'DoTuoi'] as $cand) {
    if (isset($tcCols[$cand])) { $colAge = $cand; break; }
}
if (!$colAge) {
    $conn->exec("ALTER TABLE thucung ADD COLUMN Tuoi VARCHAR(50) NULL");
    $colAge = 'Tuoi';
}

$colWeight = isset($tcCols['CanNang']) ? 'CanNang' : null;
if (!$colWeight) {
    $conn->exec("ALTER TABLE thucung ADD COLUMN CanNang VARCHAR(30) NULL");
    $colWeight = 'CanNang';
}

$colGender = isset($tcCols['GioiTinh']) ? 'GioiTinh' : null;
if (!$colGender) {
    $conn->exec("ALTER TABLE thucung ADD COLUMN GioiTinh VARCHAR(20) NULL");
    $colGender = 'GioiTinh';
}

$colNote = null;
foreach (['GhiChu', 'TinhTrangSucKhoe', 'MoTa'] as $cand) {
    if (isset($tcCols[$cand])) { $colNote = $cand; break; }
}
if (!$colNote) {
    $conn->exec("ALTER TABLE thucung ADD COLUMN GhiChu TEXT NULL");
    $colNote = 'GhiChu';
}

$tcCols = getColsMeta($conn, 'thucung');

function formatMaKH($id): string {
    return 'KH' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
}

function formatMaTC($id): string {
    return 'TC' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
}

function getSpeciesBadgeClass(string $species): string {
    $lower = mb_strtolower(trim($species), 'UTF-8');
    if (strpos($lower, 'chó') !== false || strpos($lower, 'dog') !== false) return 'species-dog';
    if (strpos($lower, 'mèo') !== false || strpos($lower, 'cat') !== false) return 'species-cat';
    return 'species-other';
}

// 1. XỬ LÝ THÊM KHÁCH HÀNG MỚI (KÈM TÙY CHỌN THÚ CƯNG ĐẦU TIÊN)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_customer') {
    $openCustModal = true;
    $hoTen         = trim($_POST['ho_ten'] ?? '');
    $sdt           = trim($_POST['sdt'] ?? '');
    $email         = trim($_POST['email'] ?? '');
    $diaChi        = trim($_POST['dia_chi'] ?? '');
    $matKhau       = $_POST['mat_khau'] ?? '123456';

    // Thông tin thú cưng đi kèm (tùy chọn)
    $tenThuCung    = trim($_POST['ten_thu_cung'] ?? '');
    $loai          = trim($_POST['loai'] ?? 'Chó');
    $giong         = trim($_POST['giong'] ?? '');
    $tuoi          = trim($_POST['tuoi'] ?? '');
    $canNang       = trim($_POST['can_nang'] ?? '');
    $gioiTinh      = trim($_POST['gioi_tinh'] ?? 'Đực');
    $ghiChu        = trim($_POST['ghi_chu'] ?? '');

    if ($hoTen === '' || $sdt === '' || $email === '') {
        $modalCustError = 'Vui lòng nhập đầy đủ Họ tên, Số điện thoại và Email khách hàng!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $modalCustError = 'Định dạng Email không hợp lệ!';
    } elseif (!preg_match('/^[0-9]{9,11}$/', $sdt)) {
        $modalCustError = 'Số điện thoại phải gồm 9 đến 11 chữ số!';
    } elseif (strlen($matKhau) < 6) {
        $modalCustError = 'Mật khẩu khởi tạo phải có ít nhất 6 ký tự!';
    } else {
        try {
            $stmtDup = $conn->prepare("SELECT MaTaiKhoan FROM taikhoan WHERE Email = ? OR SDT = ?");
            $stmtDup->execute([$email, $sdt]);

            if ($stmtDup->rowCount() > 0) {
                $modalCustError = 'Email hoặc Số điện thoại này đã được đăng ký!';
            } else {
                $conn->beginTransaction();

                $hash = password_hash($matKhau, PASSWORD_DEFAULT);
                $stmtTK = $conn->prepare("INSERT INTO taikhoan (Email, SDT, MatKhau, VaiTro) VALUES (?, ?, ?, 'Khách hàng')");
                $stmtTK->execute([$email, $sdt, $hash]);
                $newMaTK = (int)$conn->lastInsertId();

                $diaChiVal = ($diaChi !== '') ? $diaChi : null;
                $stmtKH = $conn->prepare("INSERT INTO khachhang (MaTaiKhoan, HoTen, DiaChi) VALUES (?, ?, ?)");
                $stmtKH->execute([$newMaTK, $hoTen, $diaChiVal]);
                $newMaKH = (int)$conn->lastInsertId();

                if ($tenThuCung !== '') {
                    $stmtTC = $conn->prepare("
                        INSERT INTO thucung (MaKhachHang, TenThuCung, `{$colSpecies}`, `{$colBreed}`, `{$colAge}`, `{$colWeight}`, `{$colGender}`, `{$colNote}`)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmtTC->execute([
                        $newMaKH,
                        $tenThuCung,
                        $loai !== '' ? $loai : 'Chó',
                        $giong !== '' ? $giong : null,
                        $tuoi !== '' ? $tuoi : null,
                        $canNang !== '' ? $canNang : null,
                        $gioiTinh !== '' ? $gioiTinh : null,
                        $ghiChu !== '' ? $ghiChu : null
                    ]);
                }

                $conn->commit();
                $success = 'Đã thêm khách hàng ' . htmlspecialchars($hoTen) . ' (' . formatMaKH($newMaKH) . ')'
                         . ($tenThuCung !== '' ? ' cùng bé ' . htmlspecialchars($tenThuCung) : '') . ' thành công!';
                $openCustModal = false;
            }
        } catch (PDOException $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $modalCustError = 'Lỗi lưu dữ liệu: ' . $e->getMessage();
        }
    }
}

// 2. XỬ LÝ CẬP NHẬT THÔNG TIN KHÁCH HÀNG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_customer') {
    $maKH   = (int)$_POST['ma_khach_hang'];
    $maTK   = (int)$_POST['ma_tai_khoan'];
    $hoTen  = trim($_POST['ho_ten'] ?? '');
    $sdt    = trim($_POST['sdt'] ?? '');
    $email  = trim($_POST['email'] ?? '');
    $diaChi = trim($_POST['dia_chi'] ?? '');

    if ($hoTen === '' || $sdt === '' || $email === '') {
        $error = 'Họ tên, Số điện thoại và Email khách hàng không được để trống!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Định dạng Email không hợp lệ!';
    } elseif (!preg_match('/^[0-9]{9,11}$/', $sdt)) {
        $error = 'Số điện thoại phải gồm 9 đến 11 chữ số!';
    } else {
        $stmtDup = $conn->prepare("SELECT MaTaiKhoan FROM taikhoan WHERE (Email = ? OR SDT = ?) AND MaTaiKhoan != ?");
        $stmtDup->execute([$email, $sdt, $maTK]);

        if ($stmtDup->rowCount() > 0) {
            $error = 'Email hoặc Số điện thoại này đã thuộc về tài khoản khác!';
        } else {
            $conn->prepare("UPDATE taikhoan SET Email = ?, SDT = ? WHERE MaTaiKhoan = ?")->execute([$email, $sdt, $maTK]);
            $conn->prepare("UPDATE khachhang SET HoTen = ?, DiaChi = ? WHERE MaKhachHang = ?")
                 ->execute([$hoTen, $diaChi !== '' ? $diaChi : null, $maKH]);
            $success = 'Đã cập nhật hồ sơ khách hàng ' . formatMaKH($maKH) . ' (' . htmlspecialchars($hoTen) . ')!';
        }
    }
}

// 3. XỬ LÝ THÊM HOẶC CẬP NHẬT THÚ CƯNG
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_pet') {
    $maTC       = (int)($_POST['ma_thu_cung'] ?? 0);
    $maKH       = (int)($_POST['ma_khach_hang'] ?? 0);
    $tenThuCung = trim($_POST['ten_thu_cung'] ?? '');
    $loai       = trim($_POST['loai'] ?? 'Chó');
    $giong      = trim($_POST['giong'] ?? '');
    $tuoi       = trim($_POST['tuoi'] ?? '');
    $canNang    = trim($_POST['can_nang'] ?? '');
    $gioiTinh   = trim($_POST['gioi_tinh'] ?? 'Đực');
    $ghiChu     = trim($_POST['ghi_chu'] ?? '');

    if ($maKH <= 0 || $tenThuCung === '') {
        $modalPetError = 'Vui lòng chọn Chủ sở hữu và nhập Tên thú cưng!';
        $openPetModal  = true;
    } else {
        try {
            if ($maTC > 0) {
                $stmtUpdPet = $conn->prepare("
                    UPDATE thucung
                    SET MaKhachHang = ?, TenThuCung = ?, `{$colSpecies}` = ?, `{$colBreed}` = ?,
                        `{$colAge}` = ?, `{$colWeight}` = ?, `{$colGender}` = ?, `{$colNote}` = ?
                    WHERE MaThuCung = ?
                ");
                $stmtUpdPet->execute([
                    $maKH,
                    $tenThuCung,
                    $loai !== '' ? $loai : 'Chó',
                    $giong !== '' ? $giong : null,
                    $tuoi !== '' ? $tuoi : null,
                    $canNang !== '' ? $canNang : null,
                    $gioiTinh !== '' ? $gioiTinh : null,
                    $ghiChu !== '' ? $ghiChu : null,
                    $maTC
                ]);
                $success = 'Đã cập nhật thông tin bé ' . htmlspecialchars($tenThuCung) . ' (' . formatMaTC($maTC) . ')!';
            } else {
                $stmtInsPet = $conn->prepare("
                    INSERT INTO thucung (MaKhachHang, TenThuCung, `{$colSpecies}`, `{$colBreed}`, `{$colAge}`, `{$colWeight}`, `{$colGender}`, `{$colNote}`)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtInsPet->execute([
                    $maKH,
                    $tenThuCung,
                    $loai !== '' ? $loai : 'Chó',
                    $giong !== '' ? $giong : null,
                    $tuoi !== '' ? $tuoi : null,
                    $canNang !== '' ? $canNang : null,
                    $gioiTinh !== '' ? $gioiTinh : null,
                    $ghiChu !== '' ? $ghiChu : null
                ]);
                $newTC = (int)$conn->lastInsertId();
                $success = 'Đã thêm thú cưng ' . htmlspecialchars($tenThuCung) . ' (' . formatMaTC($newTC) . ') thành công!';
            }
        } catch (PDOException $e) {
            $modalPetError = 'Lỗi lưu thông tin thú cưng: ' . $e->getMessage();
            $openPetModal  = true;
        }
    }
}

// 4. XỬ LÝ XÓA THÚ CƯNG
if (isset($_GET['xoa_thucung'])) {
    $maTCXoa = (int)$_GET['xoa_thucung'];
    try {
        $conn->prepare("DELETE FROM thucung WHERE MaThuCung = ?")->execute([$maTCXoa]);
        $success = 'Đã xóa hồ sơ thú cưng ' . formatMaTC($maTCXoa) . '!';
    } catch (PDOException $e) {
        $error = 'Không thể xóa thú cưng này vì đã có lịch sử đặt dịch vụ trong hệ thống!';
    }
}

// 5. XỬ LÝ XÓA KHÁCH HÀNG
if (isset($_GET['xoa_kh'])) {
    $maKHXoa = (int)$_GET['xoa_kh'];
    try {
        $stmtGetTK = $conn->prepare("SELECT MaTaiKhoan FROM khachhang WHERE MaKhachHang = ?");
        $stmtGetTK->execute([$maKHXoa]);
        $tkId = (int)$stmtGetTK->fetchColumn();

        $conn->prepare("DELETE FROM thucung WHERE MaKhachHang = ?")->execute([$maKHXoa]);
        $conn->prepare("DELETE FROM khachhang WHERE MaKhachHang = ?")->execute([$maKHXoa]);
        if ($tkId > 0) {
            $conn->prepare("DELETE FROM taikhoan WHERE MaTaiKhoan = ?")->execute([$tkId]);
        }
        $success = 'Đã xóa khách hàng ' . formatMaKH($maKHXoa) . ' khỏi hệ thống!';
    } catch (PDOException $e) {
        $error = 'Không thể xóa khách hàng này vì đã phát sinh lịch hẹn dịch vụ liên quan!';
    }
}

// 6. THỐNG KÊ NHANH 4 THẺ KPI
$tongKhachHang = (int)$conn->query("SELECT COUNT(*) FROM khachhang")->fetchColumn();
$tongThuCung   = (int)$conn->query("SELECT COUNT(*) FROM thucung")->fetchColumn();
$soCho         = (int)$conn->query("SELECT COUNT(*) FROM thucung WHERE `{$colSpecies}` LIKE '%Chó%'")->fetchColumn();
$soMeo         = (int)$conn->query("SELECT COUNT(*) FROM thucung WHERE `{$colSpecies}` LIKE '%Mèo%'")->fetchColumn();

$khachCoLich = (int)$conn->query("
    SELECT COUNT(DISTINCT tc.MaKhachHang)
    FROM lichhen lh
    JOIN thucung tc ON lh.MaThuCung = tc.MaThuCung
    WHERE lh.TrangThai != 'Đã hủy'
")->fetchColumn();

$thuCungDangChamSoc = (int)$conn->query("
    SELECT COUNT(DISTINCT MaThuCung)
    FROM lichhen
    WHERE TrangThai IN ('Đã xác nhận', 'Đang thực hiện')
")->fetchColumn();

// 7. LỌC & TRUY VẤN DANH SÁCH KHÁCH HÀNG + THÚ CƯNG
$keyword       = trim($_GET['q'] ?? '');
$speciesFilter = $_GET['loai'] ?? 'tatca';
$petState      = $_GET['trangthai_tc'] ?? 'tatca';

$sqlCustomers = "
    SELECT kh.MaKhachHang, kh.MaTaiKhoan, kh.HoTen, kh.DiaChi,
           tk.Email, tk.SDT,
           COUNT(DISTINCT CASE WHEN lh.TrangThai != 'Đã hủy' THEN lh.MaLichHen END) AS SoLichHen
    FROM khachhang kh
    JOIN taikhoan tk ON kh.MaTaiKhoan = tk.MaTaiKhoan
    LEFT JOIN thucung tc ON kh.MaKhachHang = tc.MaKhachHang
    LEFT JOIN lichhen lh ON tc.MaThuCung = lh.MaThuCung
    WHERE 1=1
";
$params = [];

if ($keyword !== '') {
    $sqlCustomers .= " AND (kh.HoTen LIKE ? OR tk.SDT LIKE ? OR tk.Email LIKE ? OR tc.TenThuCung LIKE ?)";
    $like = '%' . $keyword . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

if ($speciesFilter !== 'tatca') {
    $sqlCustomers .= " AND EXISTS (
        SELECT 1 FROM thucung tc_f
        WHERE tc_f.MaKhachHang = kh.MaKhachHang AND tc_f.`{$colSpecies}` LIKE ?
    )";
    $params[] = '%' . $speciesFilter . '%';
}

if ($petState === 'co_tc') {
    $sqlCustomers .= " AND EXISTS (SELECT 1 FROM thucung tc_e WHERE tc_e.MaKhachHang = kh.MaKhachHang)";
} elseif ($petState === 'chua_tc') {
    $sqlCustomers .= " AND NOT EXISTS (SELECT 1 FROM thucung tc_n WHERE tc_n.MaKhachHang = kh.MaKhachHang)";
}

$sqlCustomers .= "
    GROUP BY kh.MaKhachHang, kh.MaTaiKhoan, kh.HoTen, kh.DiaChi, tk.Email, tk.SDT
    ORDER BY kh.MaKhachHang DESC
";

$stmtCust = $conn->prepare($sqlCustomers);
$stmtCust->execute($params);
$dsKhachHang = $stmtCust->fetchAll();

// Lấy toàn bộ thú cưng gom nhóm theo MaKhachHang
$allPets = $conn->query("
    SELECT MaThuCung, MaKhachHang, TenThuCung,
           `{$colSpecies}` AS Loai,
           `{$colBreed}` AS Giong,
           `{$colAge}` AS Tuoi,
           `{$colWeight}` AS CanNang,
           `{$colGender}` AS GioiTinh,
           `{$colNote}` AS GhiChu
    FROM thucung
    ORDER BY MaThuCung ASC
")->fetchAll();

$petsByCustomer = [];
$petsById       = [];
foreach ($allPets as $p) {
    $petsByCustomer[(int)$p['MaKhachHang']][] = $p;
    $petsById[(int)$p['MaThuCung']] = $p;
}

// Danh sách toàn bộ khách hàng cho dropdown Modal thêm thú cưng
$allCustomersDropdown = $conn->query("
    SELECT kh.MaKhachHang, kh.HoTen, tk.SDT
    FROM khachhang kh
    JOIN taikhoan tk ON kh.MaTaiKhoan = tk.MaTaiKhoan
    ORDER BY kh.HoTen ASC
")->fetchAll();

// Chi tiết khách hàng đang mở (nếu bấm Chi tiết)
$detailCustomer = null;
if (isset($_GET['chitiet'])) {
    $idDetail = (int)$_GET['chitiet'];
    foreach ($dsKhachHang as $cItem) {
        if ((int)$cItem['MaKhachHang'] === $idDetail) {
            $detailCustomer = $cItem;
            break;
        }
    }
    if (!$detailCustomer) {
        $stmtOneKH = $conn->prepare("
            SELECT kh.MaKhachHang, kh.MaTaiKhoan, kh.HoTen, kh.DiaChi, tk.Email, tk.SDT
            FROM khachhang kh
            JOIN taikhoan tk ON kh.MaTaiKhoan = tk.MaTaiKhoan
            WHERE kh.MaKhachHang = ?
        ");
        $stmtOneKH->execute([$idDetail]);
        $detailCustomer = $stmtOneKH->fetch();
    }
}

// Nếu bấm "Sửa thú cưng" -> mở sẵn modal thú cưng với dữ liệu bé đó
$editPet = null;
if (isset($_GET['sua_thucung'])) {
    $idPetEdit = (int)$_GET['sua_thucung'];
    if (isset($petsById[$idPetEdit])) {
        $editPet      = $petsById[$idPetEdit];
        $openPetModal = true;
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Khách hàng & thú cưng</title>
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
            max-width: 1280px;
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

        /* ================= KHUNG BỘ LỌC & THAO TÁC NHANH ================= */
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

        .top-action-buttons {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .btn-primary {
            background-color: #556e57;
            color: #ffffff;
            border: none;
            text-decoration: none;
            padding: 10px 20px;
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

        .btn-outline-green {
            background-color: #eef3eb;
            color: #2c4231;
            border: 1px solid #cdd8c8;
            text-decoration: none;
            padding: 10px 18px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background-color 0.2s;
        }

        .btn-outline-green:hover {
            background-color: #e1eadc;
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
            grid-template-columns: 1.7fr 1fr 1fr auto;
            gap: 14px;
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
            padding: 10px 14px;
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

        .btn-filter {
            background-color: #eef3eb;
            color: #243429;
            border: 1px solid #d5ddd2;
            padding: 10px 22px;
            border-radius: 9px;
            font-size: 13.5px;
            font-weight: 700;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        .btn-filter:hover {
            background-color: #dfe7da;
        }

        /* ================= KHUNG CHI TIẾT KHÁCH HÀNG & THÚ CƯNG ================= */
        .detail-drawer-card {
            background-color: #ffffff;
            border: 1.5px solid #556e57;
            border-radius: 14px;
            padding: 24px 28px;
            margin-bottom: 22px;
            box-shadow: 0 6px 20px rgba(36, 52, 41, 0.06);
        }

        .drawer-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 16px;
            margin-bottom: 18px;
            border-bottom: 1px solid #f0ede6;
            flex-wrap: wrap;
        }

        .drawer-top h3 {
            font-size: 18px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 3px;
        }

        .drawer-top p {
            font-size: 13px;
            color: #77857a;
        }

        .customer-edit-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
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

        .drawer-pets-section {
            margin-top: 22px;
            padding-top: 20px;
            border-top: 1px dashed #d5ddd2;
        }

        .drawer-pets-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
            flex-wrap: wrap;
        }

        .drawer-pets-header h4 {
            font-size: 15.5px;
            font-weight: 800;
            color: #243429;
        }

        .pet-cards-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }

        .pet-item-card {
            background-color: #faf7f2;
            border: 1px solid #e6e2d8;
            border-radius: 12px;
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 10px;
        }

        .pet-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 10px;
        }

        .pet-title-wrap {
            display: flex;
            align-items: center;
            gap: 9px;
            flex-wrap: wrap;
        }

        .pet-code-badge {
            background-color: #e9eee4;
            color: #243429;
            font-size: 11.5px;
            font-weight: 800;
            padding: 3px 8px;
            border-radius: 6px;
        }

        .pet-name-title {
            font-size: 15px;
            font-weight: 800;
            color: #243429;
        }

        .species-badge {
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 9px;
            border-radius: 20px;
        }

        .species-dog {
            background-color: #e2eadc;
            color: #2f5438;
        }

        .species-cat {
            background-color: #f3e7c9;
            color: #7a5814;
        }

        .species-other {
            background-color: #e3ecf5;
            color: #1e40af;
        }

        .pet-meta-line {
            font-size: 13px;
            color: #4f5e53;
            line-height: 1.5;
        }

        .pet-note-box {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 7px;
            padding: 8px 11px;
            font-size: 12.5px;
            color: #5b695e;
        }

        .pet-card-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-top: 6px;
            border-top: 1px solid #ece8de;
            font-size: 12.5px;
        }

        /* ================= BẢNG DANH SÁCH KHÁCH HÀNG & THÚ CƯNG ================= */
        .customer-table {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .customer-header,
        .customer-row {
            display: grid;
            grid-template-columns: 88px 1.25fr 1.25fr 1.55fr 85px 175px;
            align-items: center;
            gap: 12px;
            padding: 15px 22px;
            border-radius: 10px;
        }

        .customer-header {
            background-color: #e9eee4;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #6b786e;
            text-transform: uppercase;
        }

        .customer-row {
            background-color: #ffffff;
            border: 1px solid #f0ede6;
            font-size: 13.5px;
            color: #2c3e32;
            transition: box-shadow 0.2s, border-color 0.2s;
        }

        .customer-row:hover {
            box-shadow: 0 4px 14px rgba(36, 52, 41, 0.05);
        }

        .customer-row.active-row {
            border-color: #556e57;
            background-color: #fcfdf9;
        }

        .col-code {
            font-weight: 800;
            color: #243429;
        }

        .col-customer strong {
            display: block;
            font-size: 14px;
            font-weight: 700;
            color: #243429;
        }

        .col-customer small {
            display: block;
            font-size: 12px;
            color: #77857a;
            margin-top: 2px;
        }

        .col-contact span {
            display: block;
            font-weight: 600;
            color: #243429;
        }

        .col-contact small {
            display: block;
            font-size: 12px;
            color: #77857a;
            margin-top: 2px;
        }

        .pet-tags-wrap {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .pet-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-decoration: none;
            transition: transform 0.15s;
        }

        .pet-pill:hover {
            transform: translateY(-1px);
        }

        .no-pet-text {
            font-size: 12.5px;
            color: #8c988f;
            font-style: italic;
        }

        .booking-count-badge {
            display: inline-block;
            background-color: #f3f6f1;
            color: #36473b;
            font-weight: 700;
            font-size: 12.5px;
            padding: 4px 10px;
            border-radius: 7px;
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
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
        }

        .action-link:hover {
            text-decoration: underline;
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

        /* ================= MODAL THÊM KHÁCH HÀNG / THÚ CƯNG ================= */
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
            max-width: 680px;
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

        .modal-section-title {
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #4f6653;
            margin-bottom: 12px;
        }

        .modal-divider {
            border: none;
            border-top: 1px dashed #dce3d9;
            margin: 20px 0 16px 0;
        }

        .modal-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px 18px;
        }

        .span-2 {
            grid-column: 1 / -1;
        }

        .species-chips {
            display: flex;
            gap: 6px;
            margin-top: 4px;
        }

        .chip-species {
            background-color: #f3f6f1;
            border: 1px solid #dce3d9;
            color: #36473b;
            font-size: 12px;
            font-weight: 600;
            padding: 4px 12px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
        }

        .chip-species:hover,
        .chip-species.active {
            background-color: #556e57;
            border-color: #556e57;
            color: #ffffff;
        }

        .modal-footer {
            padding: 16px 28px 22px 28px;
            border-top: 1px solid #f0ede6;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        @media (max-width: 1180px) {
            .stats-grid,
            .customer-edit-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            .filter-form {
                grid-template-columns: 1fr 1fr;
            }
            .customer-header {
                display: none;
            }
            .customer-row {
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
            .customer-edit-grid,
            .pet-cards-grid,
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
                <li><a href="admin_khachhang_thucung.php" class="active">Khách hàng & thú cưng</a></li>
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
                <h1>Khách hàng & thú cưng</h1>
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
                <div class="stat-title">Tổng khách hàng</div>
                <div class="stat-sub">Hồ sơ thành viên đã đăng ký</div>
                <div class="stat-value"><?= number_format($tongKhachHang, 0, ',', '.') ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Tổng thú cưng</div>
                <div class="stat-sub"><?= $soCho ?> Chó · <?= $soMeo ?> Mèo</div>
                <div class="stat-value"><?= number_format($tongThuCung, 0, ',', '.') ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Khách đã đặt lịch</div>
                <div class="stat-sub">Đã sử dụng dịch vụ tại spa</div>
                <div class="stat-value"><?= number_format($khachCoLich, 0, ',', '.') ?></div>
            </div>

            <div class="stat-card">
                <div class="stat-title">Thú cưng đang chăm sóc</div>
                <div class="stat-sub">Lịch đã xác nhận / đang làm</div>
                <div class="stat-value"><?= number_format($thuCungDangChamSoc, 0, ',', '.') ?></div>
            </div>
        </div>

        <!-- KHUNG TÌM KIẾM, LỌC & NÚT THÊM NHANH -->
        <div class="filter-card">
            <div class="filter-header-row">
                <div>
                    <h2>Danh sách khách hàng & thú cưng</h2>
                    <p>Quản lý thông tin liên hệ chủ nuôi và hồ sơ sức khỏe từng thú cưng.</p>
                </div>

                <div class="top-action-buttons">
                    <button type="button" class="btn-outline-green" id="btn-open-pet-modal">
                        + Thêm thú cưng
                    </button>
                    <button type="button" class="btn-primary" id="btn-open-cust-modal">
                        + Thêm khách hàng
                    </button>
                </div>
            </div>

            <form method="GET" action="admin_khachhang_thucung.php" class="filter-form">
                <div class="filter-field">
                    <label>Tìm kiếm</label>
                    <input type="text" name="q" placeholder="Tên khách hàng, SĐT, email hoặc tên thú cưng..."
                           value="<?= htmlspecialchars($keyword) ?>">
                </div>

                <div class="filter-field">
                    <label>Loài thú cưng</label>
                    <select name="loai">
                        <option value="tatca" <?= $speciesFilter === 'tatca' ? 'selected' : '' ?>>Tất cả loài</option>
                        <option value="Chó" <?= $speciesFilter === 'Chó' ? 'selected' : '' ?>>Chó</option>
                        <option value="Mèo" <?= $speciesFilter === 'Mèo' ? 'selected' : '' ?>>Mèo</option>
                    </select>
                </div>

                <div class="filter-field">
                    <label>Hồ sơ thú cưng</label>
                    <select name="trangthai_tc">
                        <option value="tatca" <?= $petState === 'tatca' ? 'selected' : '' ?>>Tất cả khách hàng</option>
                        <option value="co_tc" <?= $petState === 'co_tc' ? 'selected' : '' ?>>Đã có thú cưng</option>
                        <option value="chua_tc" <?= $petState === 'chua_tc' ? 'selected' : '' ?>>Chưa đăng ký thú cưng</option>
                    </select>
                </div>

                <div>
                    <button type="submit" class="btn-filter">Lọc</button>
                </div>
            </form>
        </div>

        <!-- KHUNG CHI TIẾT & CẬP NHẬT HỒ SƠ KHÁCH HÀNG + DANH SÁCH THÚ CƯNG -->
        <?php if ($detailCustomer): ?>
            <?php
                $detailMaKH = (int)$detailCustomer['MaKhachHang'];
                $detailPets = $petsByCustomer[$detailMaKH] ?? [];
            ?>
            <div class="detail-drawer-card" id="customer-detail-panel">
                <div class="drawer-top">
                    <div>
                        <h3>Hồ sơ khách hàng: <?= htmlspecialchars($detailCustomer['HoTen']) ?> (<?= formatMaKH($detailMaKH) ?>)</h3>
                        <p>Chỉnh sửa thông tin liên hệ của chủ nuôi và quản lý danh sách thú cưng bên dưới.</p>
                    </div>
                    <a href="admin_khachhang_thucung.php" class="btn-secondary">Đóng chi tiết</a>
                </div>

                <form method="POST" action="admin_khachhang_thucung.php?chitiet=<?= $detailMaKH ?>">
                    <input type="hidden" name="action" value="update_customer">
                    <input type="hidden" name="ma_khach_hang" value="<?= $detailMaKH ?>">
                    <input type="hidden" name="ma_tai_khoan" value="<?= (int)$detailCustomer['MaTaiKhoan'] ?>">

                    <div class="customer-edit-grid">
                        <div class="form-group">
                            <label>Họ và tên <span class="req">*</span></label>
                            <input type="text" name="ho_ten" required value="<?= htmlspecialchars($detailCustomer['HoTen']) ?>">
                        </div>

                        <div class="form-group">
                            <label>Số điện thoại <span class="req">*</span></label>
                            <input type="text" name="sdt" required value="<?= htmlspecialchars($detailCustomer['SDT']) ?>">
                        </div>

                        <div class="form-group">
                            <label>Email <span class="req">*</span></label>
                            <input type="email" name="email" required value="<?= htmlspecialchars($detailCustomer['Email']) ?>">
                        </div>

                        <div class="form-group">
                            <label>Địa chỉ</label>
                            <input type="text" name="dia_chi" placeholder="Chưa cập nhật địa chỉ"
                                   value="<?= htmlspecialchars($detailCustomer['DiaChi'] ?? '') ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Lưu thông tin khách hàng</button>
                </form>

                <!-- Danh sách thú cưng của khách hàng này -->
                <div class="drawer-pets-section">
                    <div class="drawer-pets-header">
                        <h4>Danh sách thú cưng của <?= htmlspecialchars($detailCustomer['HoTen']) ?> (<?= count($detailPets) ?> bé)</h4>
                        <a href="admin_khachhang_thucung.php?chitiet=<?= $detailMaKH ?>&them_thucung_kh=<?= $detailMaKH ?>"
                           class="btn-outline-green">
                            + Thêm thú cưng cho khách này
                        </a>
                    </div>

                    <?php if (count($detailPets) > 0): ?>
                        <div class="pet-cards-grid">
                            <?php foreach ($detailPets as $pet): ?>
                                <div class="pet-item-card">
                                    <div>
                                        <div class="pet-card-top">
                                            <div class="pet-title-wrap">
                                                <span class="pet-code-badge"><?= formatMaTC($pet['MaThuCung']) ?></span>
                                                <span class="pet-name-title"><?= htmlspecialchars($pet['TenThuCung']) ?></span>
                                                <span class="species-badge <?= getSpeciesBadgeClass($pet['Loai'] ?? '') ?>">
                                                    <?= htmlspecialchars($pet['Loai'] ?? 'Thú cưng') ?>
                                                </span>
                                            </div>
                                        </div>

                                        <div class="pet-meta-line" style="margin-top: 8px;">
                                            Giống: <strong><?= htmlspecialchars($pet['Giong'] ?: 'Chưa rõ') ?></strong> ·
                                            Giới tính: <strong><?= htmlspecialchars($pet['GioiTinh'] ?: 'Chưa rõ') ?></strong><br>
                                            Tuổi: <strong><?= htmlspecialchars($pet['Tuoi'] ?: 'Chưa rõ') ?></strong> ·
                                            Cân nặng: <strong><?= htmlspecialchars($pet['CanNang'] ?: 'Chưa rõ') ?></strong>
                                        </div>

                                        <?php if (!empty($pet['GhiChu'])): ?>
                                            <div class="pet-note-box" style="margin-top: 8px;">
                                                Ghi chú: <?= htmlspecialchars($pet['GhiChu']) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="pet-card-actions">
                                        <a href="admin_khachhang_thucung.php?chitiet=<?= $detailMaKH ?>&sua_thucung=<?= $pet['MaThuCung'] ?>"
                                           class="action-link">
                                            Sửa hồ sơ bé
                                        </a>
                                        <span class="dot-sep">·</span>
                                        <a href="admin_khachhang_thucung.php?chitiet=<?= $detailMaKH ?>&xoa_thucung=<?= $pet['MaThuCung'] ?>"
                                           class="action-link delete"
                                           onclick="return confirm('Xóa hồ sơ bé <?= htmlspecialchars($pet['TenThuCung']) ?>?')">
                                            Xóa bé
                                        </a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-row" style="padding: 20px;">
                            Khách hàng này chưa đăng ký thú cưng nào. Bấm <strong>+ Thêm thú cưng cho khách này</strong> để tạo mới.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- BẢNG DANH SÁCH KHÁCH HÀNG & THÚ CƯNG -->
        <div class="customer-table">
            <div class="customer-header">
                <div>MÃ KH</div>
                <div>KHÁCH HÀNG</div>
                <div>LIÊN HỆ</div>
                <div>THÚ CƯNG SỞ HỮU</div>
                <div>LỊCH HẸN</div>
                <div>THAO TÁC</div>
            </div>

            <?php if (count($dsKhachHang) > 0): ?>
                <?php foreach ($dsKhachHang as $kh): ?>
                    <?php
                        $maKH      = (int)$kh['MaKhachHang'];
                        $khPets    = $petsByCustomer[$maKH] ?? [];
                        $isCurrent = ($detailCustomer && (int)$detailCustomer['MaKhachHang'] === $maKH);
                    ?>
                    <div class="customer-row <?= $isCurrent ? 'active-row' : '' ?>">
                        <div class="col-code"><?= formatMaKH($maKH) ?></div>

                        <div class="col-customer">
                            <strong><?= htmlspecialchars($kh['HoTen']) ?></strong>
                            <small><?= htmlspecialchars($kh['DiaChi'] ?: 'Chưa cập nhật địa chỉ') ?></small>
                        </div>

                        <div class="col-contact">
                            <span><?= htmlspecialchars($kh['SDT']) ?></span>
                            <small><?= htmlspecialchars($kh['Email']) ?></small>
                        </div>

                        <div class="pet-tags-wrap">
                            <?php if (count($khPets) > 0): ?>
                                <?php foreach ($khPets as $pItem): ?>
                                    <a href="admin_khachhang_thucung.php?chitiet=<?= $maKH ?>&sua_thucung=<?= $pItem['MaThuCung'] ?>"
                                       class="pet-pill <?= getSpeciesBadgeClass($pItem['Loai'] ?? '') ?>"
                                       title="Bấm để xem/sửa bé <?= htmlspecialchars($pItem['TenThuCung']) ?>">
                                        <?= htmlspecialchars($pItem['TenThuCung']) ?> (<?= htmlspecialchars($pItem['Loai'] ?? '') ?>)
                                    </a>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="no-pet-text">Chưa đăng ký thú cưng</span>
                            <?php endif; ?>
                        </div>

                        <div>
                            <span class="booking-count-badge"><?= (int)$kh['SoLichHen'] ?> lịch</span>
                        </div>

                        <div class="row-actions">
                            <a href="admin_khachhang_thucung.php?chitiet=<?= $maKH ?>#customer-detail-panel" class="action-link">
                                Chi tiết
                            </a>
                            <span class="dot-sep">·</span>
                            <a href="admin_khachhang_thucung.php?them_thucung_kh=<?= $maKH ?>" class="action-link">
                                + Bé
                            </a>
                            <span class="dot-sep">·</span>
                            <a href="admin_khachhang_thucung.php?xoa_kh=<?= $maKH ?>"
                               class="action-link delete"
                               onclick="return confirm('Bạn chắc chắn muốn xóa khách hàng <?= htmlspecialchars($kh['HoTen']) ?> không?')">
                                Xóa
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-row">
                    Không tìm thấy khách hàng hoặc thú cưng nào phù hợp với điều kiện lọc.
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<!-- ================= MODAL 1: THÊM KHÁCH HÀNG MỚI ================= -->
<div class="modal-backdrop <?= $openCustModal ? 'open' : '' ?>" id="cust-modal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3>Thêm khách hàng mới</h3>
                <p>Tạo hồ sơ khách hàng mới và có thể thêm kèm thông tin thú cưng đầu tiên.</p>
            </div>
            <button type="button" class="btn-close-modal" id="btn-close-cust-modal">×</button>
        </div>

        <form method="POST" action="admin_khachhang_thucung.php">
            <input type="hidden" name="action" value="create_customer">

            <div class="modal-body">
                <?php if (!empty($modalCustError)): ?>
                    <div class="alert-error"><?= htmlspecialchars($modalCustError) ?></div>
                <?php endif; ?>

                <div class="modal-section-title">1. Thông tin chủ nuôi</div>
                <div class="modal-grid-2">
                    <div class="form-group">
                        <label>Họ và tên khách hàng <span class="req">*</span></label>
                        <input type="text" name="ho_ten" required placeholder="Ví dụ: Lê Thu Hạnh"
                               value="<?= htmlspecialchars($openCustModal ? ($_POST['ho_ten'] ?? '') : '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Số điện thoại <span class="req">*</span></label>
                        <input type="text" name="sdt" required placeholder="Ví dụ: 0901234567"
                               value="<?= htmlspecialchars($openCustModal ? ($_POST['sdt'] ?? '') : '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Email đăng nhập <span class="req">*</span></label>
                        <input type="email" name="email" required placeholder="Ví dụ: hanh@email.com"
                               value="<?= htmlspecialchars($openCustModal ? ($_POST['email'] ?? '') : '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Mật khẩu khởi tạo <span class="req">*</span></label>
                        <input type="text" name="mat_khau" required value="123456">
                    </div>

                    <div class="form-group span-2">
                        <label>Địa chỉ</label>
                        <input type="text" name="dia_chi" placeholder="Ví dụ: Quận 8, TP. Hồ Chí Minh"
                               value="<?= htmlspecialchars($openCustModal ? ($_POST['dia_chi'] ?? '') : '') ?>">
                    </div>
                </div>

                <hr class="modal-divider">

                <div class="modal-section-title">2. Thú cưng đầu tiên (không bắt buộc)</div>
                <div class="modal-grid-2">
                    <div class="form-group">
                        <label>Tên thú cưng</label>
                        <input type="text" name="ten_thu_cung" placeholder="Ví dụ: Mochi"
                               value="<?= htmlspecialchars($openCustModal ? ($_POST['ten_thu_cung'] ?? '') : '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Loài</label>
                        <select name="loai">
                            <option value="Chó">Chó</option>
                            <option value="Mèo">Mèo</option>
                            <option value="Khác">Khác</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Giống</label>
                        <input type="text" name="giong" placeholder="Ví dụ: Poodle, Corgi, Anh lông ngắn..."
                               value="<?= htmlspecialchars($openCustModal ? ($_POST['giong'] ?? '') : '') ?>">
                    </div>

                    <div class="form-group">
                        <label>Tuổi / Cân nặng</label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                            <input type="text" name="tuoi" placeholder="VD: 2 tuổi">
                            <input type="text" name="can_nang" placeholder="VD: 4.5 kg">
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn-secondary" id="btn-cancel-cust-modal">Hủy bỏ</button>
                <button type="submit" class="btn-primary">+ Lưu khách hàng</button>
            </div>
        </form>
    </div>
</div>

<!-- ================= MODAL 2: THÊM HOẶC SỬA THÚ CƯNG ================= -->
<div class="modal-backdrop <?= $openPetModal ? 'open' : '' ?>" id="pet-modal">
    <div class="modal-box">
        <div class="modal-header">
            <div>
                <h3><?= $editPet ? 'Cập nhật hồ sơ thú cưng (' . formatMaTC($editPet['MaThuCung']) . ')' : 'Thêm thú cưng mới' ?></h3>
                <p>Nhập thông tin đặc điểm và lưu ý sức khỏe để nhân viên chăm sóc đúng quy trình.</p>
            </div>
            <a href="admin_khachhang_thucung.php<?= $detailCustomer ? '?chitiet=' . (int)$detailCustomer['MaKhachHang'] : '' ?>"
               class="btn-close-modal" style="text-decoration: none;">×</a>
        </div>

        <form method="POST" action="admin_khachhang_thucung.php<?= $detailCustomer ? '?chitiet=' . (int)$detailCustomer['MaKhachHang'] : '' ?>">
            <input type="hidden" name="action" value="save_pet">
            <input type="hidden" name="ma_thu_cung" value="<?= $editPet ? (int)$editPet['MaThuCung'] : 0 ?>">

            <div class="modal-body">
                <?php if (!empty($modalPetError)): ?>
                    <div class="alert-error"><?= htmlspecialchars($modalPetError) ?></div>
                <?php endif; ?>

                <div class="modal-grid-2">
                    <div class="form-group span-2">
                        <label>Chủ sở hữu (Khách hàng) <span class="req">*</span></label>
                        <?php
                            $selectedOwnerId = $editPet
                                ? (int)$editPet['MaKhachHang']
                                : ($preselectCustId > 0 ? $preselectCustId : (int)($_POST['ma_khach_hang'] ?? 0));
                        ?>
                        <select name="ma_khach_hang" required>
                            <option value="">-- Chọn khách hàng --</option>
                            <?php foreach ($allCustomersDropdown as $cOpt): ?>
                                <option value="<?= $cOpt['MaKhachHang'] ?>" <?= (int)$cOpt['MaKhachHang'] === $selectedOwnerId ? 'selected' : '' ?>>
                                    <?= formatMaKH($cOpt['MaKhachHang']) ?> - <?= htmlspecialchars($cOpt['HoTen']) ?> (<?= htmlspecialchars($cOpt['SDT']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Tên thú cưng <span class="req">*</span></label>
                        <input type="text" name="ten_thu_cung" required placeholder="Ví dụ: Bông"
                               value="<?= htmlspecialchars($editPet['TenThuCung'] ?? ($_POST['ten_thu_cung'] ?? '')) ?>">
                    </div>

                    <div class="form-group">
                        <label>Loài <span class="req">*</span></label>
                        <?php $curSpecies = $editPet['Loai'] ?? ($_POST['loai'] ?? 'Chó'); ?>
                        <input type="text" name="loai" id="pet-species-input" required value="<?= htmlspecialchars($curSpecies) ?>">
                        <div class="species-chips">
                            <button type="button" class="chip-species" data-val="Chó">Chó</button>
                            <button type="button" class="chip-species" data-val="Mèo">Mèo</button>
                            <button type="button" class="chip-species" data-val="Khác">Khác</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Giống</label>
                        <input type="text" name="giong" placeholder="Ví dụ: Poodle, Corgi, Mèo Ba Tư..."
                               value="<?= htmlspecialchars($editPet['Giong'] ?? ($_POST['giong'] ?? '')) ?>">
                    </div>

                    <div class="form-group">
                        <label>Giới tính</label>
                        <?php $curGender = $editPet['GioiTinh'] ?? ($_POST['gioi_tinh'] ?? 'Đực'); ?>
                        <select name="gioi_tinh">
                            <option value="Đực" <?= $curGender === 'Đực' ? 'selected' : '' ?>>Đực</option>
                            <option value="Cái" <?= $curGender === 'Cái' ? 'selected' : '' ?>>Cái</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Độ tuổi</label>
                        <input type="text" name="tuoi" placeholder="Ví dụ: 1 tuổi 6 tháng"
                               value="<?= htmlspecialchars($editPet['Tuoi'] ?? ($_POST['tuoi'] ?? '')) ?>">
                    </div>

                    <div class="form-group">
                        <label>Cân nặng</label>
                        <input type="text" name="can_nang" placeholder="Ví dụ: 5.2 kg"
                               value="<?= htmlspecialchars($editPet['CanNang'] ?? ($_POST['can_nang'] ?? '')) ?>">
                    </div>

                    <div class="form-group span-2">
                        <label>Ghi chú sức khỏe / tính cách</label>
                        <textarea name="ghi_chu" placeholder="Ví dụ: Nhạy cảm với tiếng máy sấy mạnh, da dễ kích ứng..."><?= htmlspecialchars($editPet['GhiChu'] ?? ($_POST['ghi_chu'] ?? '')) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="modal-footer">
                <a href="admin_khachhang_thucung.php<?= $detailCustomer ? '?chitiet=' . (int)$detailCustomer['MaKhachHang'] : '' ?>"
                   class="btn-secondary">Hủy bỏ</a>
                <button type="submit" class="btn-primary">
                    <?= $editPet ? 'Lưu cập nhật thú cưng' : '+ Lưu thú cưng' ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    const custModal      = document.getElementById('cust-modal');
    const btnOpenCust    = document.getElementById('btn-open-cust-modal');
    const btnCloseCust   = document.getElementById('btn-close-cust-modal');
    const btnCancelCust  = document.getElementById('btn-cancel-cust-modal');

    const petModal       = document.getElementById('pet-modal');
    const btnOpenPet     = document.getElementById('btn-open-pet-modal');
    const speciesInput   = document.getElementById('pet-species-input');
    const speciesChips   = document.querySelectorAll('.chip-species');

    if (btnOpenCust) btnOpenCust.addEventListener('click', () => custModal.classList.add('open'));
    if (btnCloseCust) btnCloseCust.addEventListener('click', () => custModal.classList.remove('open'));
    if (btnCancelCust) btnCancelCust.addEventListener('click', () => custModal.classList.remove('open'));

    if (btnOpenPet) btnOpenPet.addEventListener('click', () => petModal.classList.add('open'));

    custModal.addEventListener('click', (e) => {
        if (e.target === custModal) custModal.classList.remove('open');
    });

    speciesChips.forEach((chip) => {
        chip.addEventListener('click', () => {
            speciesChips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            if (speciesInput) speciesInput.value = chip.dataset.val;
        });
    });
</script>

</body>
</html>