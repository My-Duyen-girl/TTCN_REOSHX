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

$hoTenAdmin   = $_SESSION['HoTen'] ?? 'Quản trị viên';
$error        = '';
$modalError   = '';
$success      = '';
$editService  = null;
$openAddModal = isset($_GET['them']) && $_GET['them'] == '1';

// 1. Đảm bảo bảng dichvu có đủ các cột cần thiết
$extraCols = [
    'ThoiLuong' => "ALTER TABLE dichvu ADD COLUMN ThoiLuong VARCHAR(50) NOT NULL DEFAULT '60 phút'",
    'MoTa'      => "ALTER TABLE dichvu ADD COLUMN MoTa TEXT NULL",
    'TrangThai' => "ALTER TABLE dichvu ADD COLUMN TrangThai VARCHAR(50) NOT NULL DEFAULT 'Đang cung cấp'"
];
foreach ($extraCols as $colName => $alterSql) {
    try {
        $chk = $conn->query("SHOW COLUMNS FROM dichvu LIKE '{$colName}'");
        if ($chk && $chk->rowCount() === 0) {
            $conn->exec($alterSql);
        }
    } catch (PDOException $e) {
        // Bỏ qua nếu cột đã tồn tại
    }
}

// Đọc cấu trúc thực tế của bảng dichvu để tương thích mọi kiểu khóa chính / thời lượng
$colsInfo = [];
$stmtCols = $conn->query("SHOW COLUMNS FROM dichvu");
if ($stmtCols) {
    foreach ($stmtCols->fetchAll(PDO::FETCH_ASSOC) as $colMeta) {
        $colsInfo[$colMeta['Field']] = $colMeta;
    }
}

function formatMaDV($rawId) {
    $str = (string)$rawId;
    return ctype_digit($str) ? ('DV' . str_pad($str, 3, '0', STR_PAD_LEFT)) : $str;
}

function formatThoiLuongDisplay($rawTL) {
    $str = trim((string)$rawTL);
    if ($str === '') return '60 phút';
    return ctype_digit($str) ? ($str . ' phút') : $str;
}

function normalizeThoiLuongForDB($inputTL, $colsInfo) {
    $tlType = strtolower($colsInfo['ThoiLuong']['Type'] ?? 'varchar');
    if (strpos($tlType, 'int') !== false) {
        $num = (int)preg_replace('/\D+/', '', $inputTL);
        return $num > 0 ? $num : 60;
    }
    return ctype_digit($inputTL) ? ($inputTL . ' phút') : $inputTL;
}

// 2. XỬ LÝ THÊM DỊCH VỤ MỚI (TỪ MODAL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_service') {
    $openAddModal = true;
    $tenDV        = trim($_POST['ten_dich_vu'] ?? '');
    $thoiLuong    = trim($_POST['thoi_luong'] ?? '');
    $giaTien      = (float)($_POST['gia_tien'] ?? 0);
    $moTa         = trim($_POST['mo_ta'] ?? '');
    $trangThai    = $_POST['trang_thai'] ?? 'Đang cung cấp';

    if ($tenDV === '' || $thoiLuong === '') {
        $modalError = 'Vui lòng nhập đầy đủ Tên dịch vụ và Thời lượng thực hiện!';
    } elseif ($giaTien <= 0) {
        $modalError = 'Giá niêm yết của dịch vụ phải lớn hơn 0 VNĐ!';
    } elseif (!in_array($trangThai, ['Đang cung cấp', 'Tạm ẩn'])) {
        $modalError = 'Trạng thái dịch vụ không hợp lệ!';
    } else {
        try {
            $stmtDup = $conn->prepare("SELECT MaDichVu FROM dichvu WHERE TenDichVu = ? LIMIT 1");
            $stmtDup->execute([$tenDV]);

            if ($stmtDup->rowCount() > 0) {
                $modalError = 'Tên dịch vụ "' . htmlspecialchars($tenDV) . '" đã tồn tại trong danh mục!';
            } else {
                $moTaVal     = ($moTa !== '') ? $moTa : null;
                $thoiLuongDb = normalizeThoiLuongForDB($thoiLuong, $colsInfo);

                $insertCols = ['TenDichVu', 'ThoiLuong', 'GiaTien', 'MoTa', 'TrangThai'];
                $insertVals = [$tenDV, $thoiLuongDb, $giaTien, $moTaVal, $trangThai];

                // Tự động cấp MaDichVu nếu cột khóa chính không có AUTO_INCREMENT
                $pkMeta    = $colsInfo['MaDichVu'] ?? null;
                $isAutoInc = $pkMeta && stripos($pkMeta['Extra'] ?? '', 'auto_increment') !== false;
                $pkType    = strtolower($pkMeta['Type'] ?? 'int');
                $manualId  = null;

                if (!$isAutoInc) {
                    if (strpos($pkType, 'int') !== false) {
                        $manualId = (int)$conn->query("SELECT COALESCE(MAX(MaDichVu), 0) + 1 FROM dichvu")->fetchColumn();
                    } else {
                        $allIds = $conn->query("SELECT MaDichVu FROM dichvu")->fetchAll(PDO::FETCH_COLUMN);
                        $maxNum = 0;
                        foreach ($allIds as $idItem) {
                            $n = (int)preg_replace('/\D+/', '', (string)$idItem);
                            if ($n > $maxNum) $maxNum = $n;
                        }
                        $manualId = 'DV' . str_pad($maxNum + 1, 3, '0', STR_PAD_LEFT);
                    }
                    array_unshift($insertCols, 'MaDichVu');
                    array_unshift($insertVals, $manualId);
                }

                // Điền giá trị mặc định nếu bảng dichvu có thêm cột NOT NULL khác
                foreach ($colsInfo as $cName => $cMeta) {
                    if (!in_array($cName, $insertCols, true)
                        && ($cMeta['Null'] ?? '') === 'NO'
                        && ($cMeta['Default'] ?? null) === null
                        && stripos($cMeta['Extra'] ?? '', 'auto_increment') === false) {
                        $insertCols[] = $cName;
                        $cType = strtolower($cMeta['Type'] ?? '');
                        $insertVals[] = (strpos($cType, 'int') !== false || strpos($cType, 'dec') !== false || strpos($cType, 'float') !== false) ? 0 : '';
                    }
                }

                $placeholders = implode(', ', array_fill(0, count($insertCols), '?'));
                $colListSql   = implode(', ', $insertCols);
                $stmtIns      = $conn->prepare("INSERT INTO dichvu ({$colListSql}) VALUES ({$placeholders})");
                $stmtIns->execute($insertVals);

                $createdId    = $isAutoInc ? $conn->lastInsertId() : $manualId;
                $success      = 'Đã thêm dịch vụ mới ' . htmlspecialchars(formatMaDV($createdId)) . ' (' . htmlspecialchars($tenDV) . ') thành công!';
                $openAddModal = false;
            }
        } catch (PDOException $e) {
            $modalError = 'Lỗi lưu dữ liệu MySQL: ' . $e->getMessage();
        }
    }
}

// 3. XỬ LÝ CẬP NHẬT DỊCH VỤ (TỪ BIỂU MẪU CHỈNH SỬA PHÍA DƯỚI)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_service') {
    $maDV      = trim($_POST['ma_dich_vu'] ?? '');
    $tenDV     = trim($_POST['ten_dich_vu'] ?? '');
    $thoiLuong = trim($_POST['thoi_luong'] ?? '');
    $giaTien   = (float)($_POST['gia_tien'] ?? 0);
    $moTa      = trim($_POST['mo_ta'] ?? '');
    $trangThai = $_POST['trang_thai'] ?? 'Đang cung cấp';

    if ($maDV === '' || $maDV === '0') {
        $error = 'Vui lòng chọn dịch vụ cần chỉnh sửa từ danh sách phía trên!';
    } elseif ($tenDV === '' || $thoiLuong === '') {
        $error = 'Vui lòng nhập đầy đủ Tên dịch vụ và Thời lượng thực hiện!';
    } elseif ($giaTien <= 0) {
        $error = 'Giá niêm yết của dịch vụ phải lớn hơn 0 VNĐ!';
    } elseif (!in_array($trangThai, ['Đang cung cấp', 'Tạm ẩn'])) {
        $error = 'Trạng thái dịch vụ không hợp lệ!';
    } else {
        try {
            $moTaVal     = ($moTa !== '') ? $moTa : null;
            $thoiLuongDb = normalizeThoiLuongForDB($thoiLuong, $colsInfo);

            $stmtUpd = $conn->prepare("
                UPDATE dichvu
                SET TenDichVu = ?, ThoiLuong = ?, GiaTien = ?, MoTa = ?, TrangThai = ?
                WHERE MaDichVu = ?
            ");
            $stmtUpd->execute([$tenDV, $thoiLuongDb, $giaTien, $moTaVal, $trangThai, $maDV]);
            $success = 'Đã cập nhật dịch vụ ' . htmlspecialchars(formatMaDV($maDV)) . ' (' . htmlspecialchars($tenDV) . ')!';
        } catch (PDOException $e) {
            $error = 'Lỗi cập nhật dịch vụ: ' . $e->getMessage();
        }
    }
}

// 4. XỬ LÝ XÓA DỊCH VỤ
if (isset($_GET['xoa'])) {
    $maDVXoa = trim($_GET['xoa']);
    try {
        $stmtDel = $conn->prepare("DELETE FROM dichvu WHERE MaDichVu = ?");
        $stmtDel->execute([$maDVXoa]);
        $success = 'Đã xóa dịch vụ ' . htmlspecialchars(formatMaDV($maDVXoa)) . ' khỏi danh mục!';
    } catch (PDOException $e) {
        $error = 'Không thể xóa dịch vụ này vì đã có lịch hẹn của khách sử dụng! Bạn hãy chuyển trạng thái sang "Tạm ẩn".';
    }
}

// 5. NẾU BẤM "CHỈNH SỬA" -> TẢI DỮ LIỆU DỊCH VỤ ĐÓ LÊN BIỂU MẪU DƯỚI
if (isset($_GET['sua'])) {
    $maDVSua = trim($_GET['sua']);
    $stmtOne = $conn->prepare("SELECT * FROM dichvu WHERE MaDichVu = ? LIMIT 1");
    $stmtOne->execute([$maDVSua]);
    $editService = $stmtOne->fetch();
}

// 6. LẤY DANH SÁCH DỊCH VỤ & MÃ DỊCH VỤ KẾ TIẾP CHO PREVIEW
$dsDichVu = $conn->query("SELECT * FROM dichvu ORDER BY MaDichVu ASC")->fetchAll();
$maxNumPreview = 0;
foreach ($dsDichVu as $rowDv) {
    $n = (int)preg_replace('/\D+/', '', (string)$rowDv['MaDichVu']);
    if ($n > $maxNumPreview) $maxNumPreview = $n;
}
$nextCodeStr = 'DV' . str_pad($maxNumPreview + 1, 3, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý dịch vụ & bảng giá - Pet Care & Spa</title>
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

        /* ================= THẺ TIÊU ĐỀ DANH MỤC DỊCH VỤ ================= */
        .header-banner-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 22px 26px;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .header-banner-card h2 {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .header-banner-card p {
            font-size: 13px;
            color: #77857a;
        }

        .btn-primary {
            background-color: #556e57;
            color: #ffffff;
            border: none;
            text-decoration: none;
            padding: 11px 24px;
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

        /* ================= BẢNG DANH MỤC DỊCH VỤ ================= */
        .service-table {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 26px;
        }

        .service-header,
        .service-row {
            display: grid;
            grid-template-columns: 95px 1.45fr 0.9fr 1fr 1.05fr 135px;
            align-items: center;
            gap: 12px;
            padding: 14px 22px;
            border-radius: 10px;
        }

        .service-header {
            background-color: #e9eee4;
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.5px;
            color: #6b786e;
            text-transform: uppercase;
        }

        .service-row {
            background-color: #ffffff;
            border: 1px solid #f0ede6;
            font-size: 13.5px;
            color: #2c3e32;
            transition: box-shadow 0.2s, border-color 0.2s;
        }

        .service-row:hover {
            box-shadow: 0 4px 14px rgba(36, 52, 41, 0.05);
        }

        .service-row.editing-highlight {
            border-color: #556e57;
            background-color: #fcfdf9;
        }

        .col-code {
            font-weight: 800;
            color: #243429;
        }

        .col-name strong {
            color: #243429;
            font-weight: 600;
        }

        .col-name small {
            display: block;
            font-size: 12px;
            color: #77857a;
            margin-top: 2px;
        }

        .col-price {
            font-weight: 700;
            color: #243429;
        }

        .status-active {
            color: #2f6b3c;
            font-weight: 600;
        }

        .status-hidden {
            color: #8c988f;
            font-weight: 600;
        }

        .row-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-link {
            text-decoration: none;
            font-weight: 700;
            font-size: 13px;
            color: #364f3c;
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
            padding: 32px 20px;
            text-align: center;
            color: #6b786e;
            font-size: 14px;
        }

        /* ================= BIỂU MẪU CHỈNH SỬA DỊCH VỤ PHÍA DƯỚI ================= */
        .service-form-card {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 14px;
            padding: 24px 28px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.015);
        }

        .service-form-card.edit-mode {
            border: 1.5px solid #556e57;
            background-color: #fcfdf9;
        }

        .form-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 18px;
            flex-wrap: wrap;
        }

        .form-header-row h3 {
            font-size: 17px;
            font-weight: 800;
            color: #243429;
        }

        .edit-badge {
            background-color: #e9eee4;
            color: #364f3c;
            font-size: 12px;
            font-weight: 700;
            padding: 5px 12px;
            border-radius: 20px;
        }

        .service-form-grid {
            display: grid;
            grid-template-columns: 1.15fr 0.75fr 0.85fr 1.35fr 0.85fr;
            gap: 14px;
            margin-bottom: 18px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            font-size: 12.5px;
            font-weight: 700;
            color: #4f5e53;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .form-group label span.req {
            color: #c81e1e;
        }

        .price-live-tag {
            font-size: 12px;
            font-weight: 800;
            color: #4f6653;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 13px;
            border: 1px solid #dce3d9;
            border-radius: 9px;
            font-size: 13.5px;
            color: #243429;
            background-color: #ffffff;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #556e57;
            box-shadow: 0 0 0 3px rgba(85, 110, 87, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 76px;
            line-height: 1.45;
        }

        .form-footer-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        /* ================= MODAL GIAO DIỆN THÊM DỊCH VỤ MỚI ================= */
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

        .add-modal-box {
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

        .add-modal-header {
            padding: 22px 28px 18px 28px;
            border-bottom: 1px solid #f0ede6;
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
        }

        .add-modal-header h3 {
            font-size: 20px;
            font-weight: 800;
            color: #243429;
            margin-bottom: 4px;
        }

        .add-modal-header p {
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
            transition: background-color 0.2s, color 0.2s;
            flex-shrink: 0;
        }

        .btn-close-modal:hover {
            background-color: #e6ece2;
            color: #243429;
        }

        .add-modal-body {
            padding: 22px 28px;
        }

        .modal-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 18px;
        }

        .span-2 {
            grid-column: 1 / -1;
        }

        /* Nút chọn nhanh thời lượng */
        .duration-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 4px;
        }

        .chip-duration {
            background-color: #f3f6f1;
            border: 1px solid #dce3d9;
            color: #36473b;
            font-size: 11.5px;
            font-weight: 600;
            padding: 4px 10px;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.15s;
        }

        .chip-duration:hover,
        .chip-duration.active {
            background-color: #556e57;
            border-color: #556e57;
            color: #ffffff;
        }

        /* Khung xem trước thẻ dịch vụ */
        .preview-box {
            margin-top: 18px;
            background-color: #faf7f2;
            border: 1px dashed #cbd6c7;
            border-radius: 12px;
            padding: 14px 18px;
        }

        .preview-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.6px;
            text-transform: uppercase;
            color: #77857a;
            margin-bottom: 8px;
        }

        .preview-card-inner {
            background-color: #ffffff;
            border: 1px solid #eceae4;
            border-radius: 9px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }

        .preview-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .preview-code {
            background-color: #e9eee4;
            color: #243429;
            font-size: 12px;
            font-weight: 800;
            padding: 5px 10px;
            border-radius: 6px;
        }

        .preview-name {
            font-size: 14px;
            font-weight: 700;
            color: #243429;
        }

        .preview-desc {
            font-size: 12px;
            color: #77857a;
            margin-top: 2px;
        }

        .preview-right {
            text-align: right;
        }

        .preview-price {
            font-size: 15px;
            font-weight: 800;
            color: #4f6653;
        }

        .preview-meta {
            font-size: 12px;
            color: #6b786e;
            margin-top: 2px;
        }

        .add-modal-footer {
            padding: 16px 28px 22px 28px;
            border-top: 1px solid #f0ede6;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        @media (max-width: 1180px) {
            .service-form-grid {
                grid-template-columns: 1fr 1fr;
            }
            .service-header {
                display: none;
            }
            .service-row {
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
            .service-form-grid,
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
                <li><a href="admin_dichvu.php" class="active">Dịch vụ & bảng giá</a></li>
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
                <h1>Quản lý dịch vụ & bảng giá</h1>
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

        <!-- THẺ TIÊU ĐỀ DANH MỤC DỊCH VỤ -->
        <div class="header-banner-card">
            <div>
                <h2>Danh mục dịch vụ</h2>
                <p>Cập nhật tên dịch vụ, mô tả, thời lượng và giá niêm yết.</p>
            </div>

            <button type="button" class="btn-primary" id="btn-open-add-modal">
                + Thêm dịch vụ
            </button>
        </div>

        <!-- BẢNG DANH MỤC DỊCH VỤ -->
        <div class="service-table">
            <div class="service-header">
                <div>MÃ DV</div>
                <div>TÊN DỊCH VỤ</div>
                <div>THỜI LƯỢNG</div>
                <div>GIÁ NIÊM YẾT</div>
                <div>TRẠNG THÁI</div>
                <div>THAO TÁC</div>
            </div>

            <?php if (count($dsDichVu) > 0): ?>
                <?php foreach ($dsDichVu as $dv): ?>
                    <?php
                        $maDVCode  = formatMaDV($dv['MaDichVu']);
                        $statusVal = $dv['TrangThai'] ?? 'Đang cung cấp';
                        $isHidden  = ($statusVal === 'Tạm ẩn');
                        $isEditing = ($editService && (string)$editService['MaDichVu'] === (string)$dv['MaDichVu']);
                    ?>
                    <div class="service-row <?= $isEditing ? 'editing-highlight' : '' ?>">
                        <div class="col-code"><?= htmlspecialchars($maDVCode) ?></div>

                        <div class="col-name">
                            <strong><?= htmlspecialchars($dv['TenDichVu']) ?></strong>
                            <?php if (!empty($dv['MoTa'])): ?>
                                <small><?= htmlspecialchars($dv['MoTa']) ?></small>
                            <?php endif; ?>
                        </div>

                        <div><?= htmlspecialchars(formatThoiLuongDisplay($dv['ThoiLuong'] ?? '60 phút')) ?></div>

                        <div class="col-price"><?= number_format((float)$dv['GiaTien'], 0, ',', '.') ?>đ</div>

                        <div>
                            <span class="<?= $isHidden ? 'status-hidden' : 'status-active' ?>">
                                <?= htmlspecialchars($statusVal) ?>
                            </span>
                        </div>

                        <div class="row-actions">
                            <a href="admin_dichvu.php?sua=<?= urlencode((string)$dv['MaDichVu']) ?>#edit-service-section" class="action-link">
                                Chỉnh sửa
                            </a>
                            <span class="dot-sep">·</span>
                            <a href="admin_dichvu.php?xoa=<?= urlencode((string)$dv['MaDichVu']) ?>"
                               class="action-link delete"
                               onclick="return confirm('Bạn chắc chắn muốn xóa dịch vụ <?= htmlspecialchars($dv['TenDichVu']) ?> không?')">
                                Xóa
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-row">
                    Chưa có dịch vụ nào trong danh mục. Bấm nút <strong>+ Thêm dịch vụ</strong> ở góc trên để tạo dịch vụ đầu tiên.
                </div>
            <?php endif; ?>
        </div>

        <!-- BIỂU MẪU CHỈNH SỬA NHANH DỊCH VỤ -->
        <div class="service-form-card <?= $editService ? 'edit-mode' : '' ?>" id="edit-service-section">
            <div class="form-header-row">
                <h3>Biểu mẫu chỉnh sửa dịch vụ</h3>
                <?php if ($editService): ?>
                    <span class="edit-badge">
                        Đang chỉnh sửa: <?= htmlspecialchars(formatMaDV($editService['MaDichVu'])) ?>
                    </span>
                <?php else: ?>
                    <span style="font-size: 12.5px; color: #77857a;">
                        Chọn <strong>Chỉnh sửa</strong> ở một dịch vụ phía trên để cập nhật nhanh
                    </span>
                <?php endif; ?>
            </div>

            <form method="POST" action="admin_dichvu.php">
                <input type="hidden" name="action" value="update_service">
                <input type="hidden" name="ma_dich_vu" value="<?= htmlspecialchars((string)($editService['MaDichVu'] ?? '')) ?>">

                <div class="service-form-grid">
                    <div class="form-group">
                        <label><span>Tên dịch vụ <span class="req">*</span></span></label>
                        <input type="text" name="ten_dich_vu" required
                               placeholder="Chọn dịch vụ cần sửa..."
                               value="<?= htmlspecialchars($editService['TenDichVu'] ?? '') ?>"
                               <?= $editService ? '' : 'disabled' ?>>
                    </div>

                    <div class="form-group">
                        <label><span>Thời lượng <span class="req">*</span></span></label>
                        <input type="text" name="thoi_luong" required
                               placeholder="60 phút"
                               value="<?= htmlspecialchars($editService ? formatThoiLuongDisplay($editService['ThoiLuong'] ?? '60 phút') : '') ?>"
                               <?= $editService ? '' : 'disabled' ?>>
                    </div>

                    <div class="form-group">
                        <label><span>Giá (VNĐ) <span class="req">*</span></span></label>
                        <input type="number" name="gia_tien" min="1000" step="1" required
                               placeholder="220000"
                               value="<?= $editService ? (int)$editService['GiaTien'] : '' ?>"
                               <?= $editService ? '' : 'disabled' ?>>
                    </div>

                    <div class="form-group">
                        <label>Mô tả</label>
                        <input type="text" name="mo_ta"
                               placeholder="Mô tả quy trình dịch vụ"
                               value="<?= htmlspecialchars($editService['MoTa'] ?? '') ?>"
                               <?= $editService ? '' : 'disabled' ?>>
                    </div>

                    <div class="form-group">
                        <label>Trạng thái</label>
                        <?php $curStatus = $editService['TrangThai'] ?? 'Đang cung cấp'; ?>
                        <select name="trang_thai" <?= $editService ? '' : 'disabled' ?>>
                            <option value="Đang cung cấp" <?= $curStatus === 'Đang cung cấp' ? 'selected' : '' ?>>Đang cung cấp</option>
                            <option value="Tạm ẩn" <?= $curStatus === 'Tạm ẩn' ? 'selected' : '' ?>>Tạm ẩn</option>
                        </select>
                    </div>
                </div>

                <?php if ($editService): ?>
                    <div class="form-footer-actions">
                        <a href="admin_dichvu.php" class="btn-secondary">Hủy chỉnh sửa</a>
                        <button type="submit" class="btn-primary">Lưu cập nhật</button>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </main>
</div>

<!-- ================= GIAO DIỆN MODAL THÊM DỊCH VỤ MỚI ================= -->
<div class="modal-backdrop <?= $openAddModal ? 'open' : '' ?>" id="add-service-modal">
    <div class="add-modal-box">
        <div class="add-modal-header">
            <div>
                <h3>Thêm dịch vụ mới</h3>
                <p>Thiết lập tên dịch vụ, thời lượng, mức giá và mô tả hiển thị cho khách đặt lịch.</p>
            </div>
            <button type="button" class="btn-close-modal" id="btn-close-add-modal" title="Đóng">×</button>
        </div>

        <form method="POST" action="admin_dichvu.php">
            <input type="hidden" name="action" value="create_service">

            <div class="add-modal-body">
                <?php if (!empty($modalError)): ?>
                    <div class="alert-error"><?= htmlspecialchars($modalError) ?></div>
                <?php endif; ?>

                <div class="modal-grid-2">
                    <div class="form-group">
                        <label><span>Tên dịch vụ <span class="req">*</span></span></label>
                        <input type="text" name="ten_dich_vu" id="new-ten-dv" required
                               placeholder="Ví dụ: Tắm & sấy khử mùi"
                               value="<?= htmlspecialchars($openAddModal ? ($_POST['ten_dich_vu'] ?? '') : '') ?>">
                    </div>

                    <div class="form-group">
                        <label>
                            <span>Giá niêm yết (VNĐ) <span class="req">*</span></span>
                            <span class="price-live-tag" id="live-price-display">0đ</span>
                        </label>
                        <input type="number" name="gia_tien" id="new-gia-dv" min="1000" step="1" required
                               placeholder="Ví dụ: 220000"
                               value="<?= htmlspecialchars($openAddModal ? ($_POST['gia_tien'] ?? '') : '') ?>">
                    </div>

                    <div class="form-group">
                        <label><span>Thời lượng thực hiện <span class="req">*</span></span></label>
                        <input type="text" name="thoi_luong" id="new-thoiluong-dv" required
                               placeholder="Ví dụ: 60 phút"
                               value="<?= htmlspecialchars($openAddModal ? ($_POST['thoi_luong'] ?? '60 phút') : '60 phút') ?>">
                        <div class="duration-chips">
                            <button type="button" class="chip-duration" data-val="30 phút">30 phút</button>
                            <button type="button" class="chip-duration" data-val="45 phút">45 phút</button>
                            <button type="button" class="chip-duration active" data-val="60 phút">60 phút</button>
                            <button type="button" class="chip-duration" data-val="90 phút">90 phút</button>
                            <button type="button" class="chip-duration" data-val="120 phút">120 phút</button>
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Trạng thái cung cấp</label>
                        <?php $newStatus = $openAddModal ? ($_POST['trang_thai'] ?? 'Đang cung cấp') : 'Đang cung cấp'; ?>
                        <select name="trang_thai" id="new-trangthai-dv">
                            <option value="Đang cung cấp" <?= $newStatus === 'Đang cung cấp' ? 'selected' : '' ?>>Đang cung cấp (Hiển thị đặt lịch)</option>
                            <option value="Tạm ẩn" <?= $newStatus === 'Tạm ẩn' ? 'selected' : '' ?>>Tạm ẩn (Chưa mở bán)</option>
                        </select>
                    </div>

                    <div class="form-group span-2">
                        <label>Mô tả chi tiết dịch vụ</label>
                        <textarea name="mo_ta" id="new-mota-dv"
                                  placeholder="Mô tả các bước chăm sóc (VD: Tắm sữa tắm dịu nhẹ, sấy tạo kiểu, vệ sinh tai và cắt móng cơ bản)..."><?= htmlspecialchars($openAddModal ? ($_POST['mo_ta'] ?? '') : '') ?></textarea>
                    </div>
                </div>

                <!-- Khung xem trước trực quan -->
                <div class="preview-box">
                    <div class="preview-label">Xem trước hiển thị trên bảng giá</div>
                    <div class="preview-card-inner">
                        <div class="preview-left">
                            <span class="preview-code"><?= htmlspecialchars($nextCodeStr) ?></span>
                            <div>
                                <div class="preview-name" id="pv-name">Tên dịch vụ mới</div>
                                <div class="preview-desc" id="pv-desc">Chưa có mô tả chi tiết</div>
                            </div>
                        </div>
                        <div class="preview-right">
                            <div class="preview-price" id="pv-price">0đ</div>
                            <div class="preview-meta">
                                <span id="pv-duration">60 phút</span> · <span id="pv-status">Đang cung cấp</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="add-modal-footer">
                <button type="button" class="btn-secondary" id="btn-cancel-add-modal">Hủy bỏ</button>
                <button type="submit" class="btn-primary">+ Lưu dịch vụ mới</button>
            </div>
        </form>
    </div>
</div>

<script>
    const addModal      = document.getElementById('add-service-modal');
    const btnOpenModal  = document.getElementById('btn-open-add-modal');
    const btnCloseModal = document.getElementById('btn-close-add-modal');
    const btnCancel     = document.getElementById('btn-cancel-add-modal');

    const inputTen       = document.getElementById('new-ten-dv');
    const inputGia       = document.getElementById('new-gia-dv');
    const inputThoiLuong = document.getElementById('new-thoiluong-dv');
    const selectStatus   = document.getElementById('new-trangthai-dv');
    const inputMoTa      = document.getElementById('new-mota-dv');

    const livePriceTag   = document.getElementById('live-price-display');
    const pvName         = document.getElementById('pv-name');
    const pvDesc         = document.getElementById('pv-desc');
    const pvPrice        = document.getElementById('pv-price');
    const pvDuration     = document.getElementById('pv-duration');
    const pvStatus       = document.getElementById('pv-status');
    const durationChips  = document.querySelectorAll('.chip-duration');

    function formatVND(num) {
        const val = parseInt(num, 10);
        if (isNaN(val) || val <= 0) return '0đ';
        return val.toLocaleString('vi-VN') + 'đ';
    }

    function updatePreview() {
        const formattedPrice = formatVND(inputGia.value);
        livePriceTag.textContent = formattedPrice;
        pvPrice.textContent      = formattedPrice;
        pvName.textContent       = inputTen.value.trim() || 'Tên dịch vụ mới';
        pvDesc.textContent       = inputMoTa.value.trim() || 'Chưa có mô tả chi tiết';
        pvDuration.textContent   = inputThoiLuong.value.trim() || '60 phút';
        pvStatus.textContent     = selectStatus.value;
    }

    function openAddServiceModal() {
        addModal.classList.add('open');
        updatePreview();
        setTimeout(() => inputTen.focus(), 60);
    }

    function closeAddServiceModal() {
        addModal.classList.remove('open');
    }

    if (btnOpenModal) btnOpenModal.addEventListener('click', openAddServiceModal);
    if (btnCloseModal) btnCloseModal.addEventListener('click', closeAddServiceModal);
    if (btnCancel) btnCancel.addEventListener('click', closeAddServiceModal);

    addModal.addEventListener('click', (e) => {
        if (e.target === addModal) closeAddServiceModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && addModal.classList.contains('open')) {
            closeAddServiceModal();
        }
    });

    durationChips.forEach((chip) => {
        chip.addEventListener('click', () => {
            durationChips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            inputThoiLuong.value = chip.dataset.val;
            updatePreview();
        });
    });

    [inputTen, inputGia, inputThoiLuong, selectStatus, inputMoTa].forEach((el) => {
        if (el) el.addEventListener('input', updatePreview);
    });

    if (addModal.classList.contains('open')) {
        updatePreview();
    }
</script>

</body>
</html>