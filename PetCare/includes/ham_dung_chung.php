
<?php

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/ket_noi_csdl.php';


function tra_ve_json(
    int $ma_trang_thai,
    string $thong_bao,
    $du_lieu = null
): void {

    http_response_code($ma_trang_thai);

    header('Content-Type: application/json; charset=utf-8');

    echo json_encode([
        'thanh_cong' => $ma_trang_thai >= 200 && $ma_trang_thai < 300,
        'thong_bao' => $thong_bao,
        'du_lieu' => $du_lieu
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


function nhan_du_lieu(): array
{
    $noi_dung = file_get_contents('php://input');

    if (!empty($noi_dung)) {

        $du_lieu = json_decode($noi_dung, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($du_lieu)) {
            return $du_lieu;
        }
    }

    return $_POST;
}


function kiem_tra_phuong_thuc(string $phuong_thuc): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $phuong_thuc) {
        header('Allow: ' . $phuong_thuc);

        tra_ve_json(405,'Phương thức yêu cầu không hợp lệ.' );
    }
}



function lay_nguoi_dung_hien_tai(): ?array
{
    return $_SESSION['user'] ?? null;
}



function kiem_tra_dang_nhap(): array
{
    $nguoi_dung = lay_nguoi_dung_hien_tai();

    if (!$nguoi_dung) {
        tra_ve_json(
            401,
            'Vui lòng đăng nhập để tiếp tục.'
        );
    }

    return $nguoi_dung;
}



function kiem_tra_quyen(array $cac_vai_tro): array
{
    $nguoi_dung = kiem_tra_dang_nhap();

    if (!in_array($nguoi_dung['VaiTro'], $cac_vai_tro, true)) {
        tra_ve_json(
            403,
            'Bạn không có quyền thực hiện chức năng này.'
        );
    }

    return $nguoi_dung;
}


function lay_ma_chu_nuoi(PDO $pdo, int $ma_tai_khoan): ?int
{
    $sql = "SELECT MaChuNuoi
            FROM ChuNuoi
            WHERE MaTaiKhoan = ?";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ma_tai_khoan]);

    $ket_qua = $stmt->fetchColumn();

    return $ket_qua === false ? null : (int)$ket_qua;
}



function lay_ma_nhan_vien(PDO $pdo, int $ma_tai_khoan): ?int
{
    $sql = "SELECT MaNhanVien
            FROM NhanVien
            WHERE MaTaiKhoan = ?
            AND TrangThaiLamViec = 'Đang làm'";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$ma_tai_khoan]);

    $ket_qua = $stmt->fetchColumn();

    return $ket_qua === false ? null : (int)$ket_qua;
}



function kiem_tra_thu_cung(
    PDO $pdo,
    int $ma_thu_cung,
    array $nguoi_dung
): bool {

    if (
        $nguoi_dung['VaiTro'] === 'Admin' ||
        $nguoi_dung['VaiTro'] === 'Nhân viên'
    ) {
        return true;
    }

    $sql = "SELECT COUNT(*)
            FROM ThuCung tc
            JOIN ChuNuoi cn
                ON tc.MaChuNuoi = cn.MaChuNuoi
            WHERE tc.MaThuCung = ?
            AND cn.MaTaiKhoan = ?";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        $ma_thu_cung,
        $nguoi_dung['MaTaiKhoan']
    ]);

    return (int)$stmt->fetchColumn() > 0;
}



function kiem_tra_du_lieu(array $du_lieu, array $cac_truong): void
{
    foreach ($cac_truong as $truong) {

        if (!isset($du_lieu[$truong]) || trim((string)$du_lieu[$truong]) === '')
        {

            tra_ve_json(
                422,
                'Vui lòng nhập đầy đủ thông tin: ' . $truong
            );
        }
    }
}

?>
