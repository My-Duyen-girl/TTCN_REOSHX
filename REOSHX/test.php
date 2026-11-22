<?php
$host = 'localhost';
$dbname = 'database1';
$username = 'root';
$password = ''; 

$status = false;
$message = '';
$tables = [];
$sampleCount = [];

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $status = true;
    $message = "Kết nối thành công tới cơ sở dữ liệu <strong>$dbname</strong>!";

    // Lấy danh sách các bảng trong database
    $stmt = $conn->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // Đếm số dòng dữ liệu hiện có trong từng bảng
    foreach ($tables as $tbl) {
        $countStmt = $conn->query("SELECT COUNT(*) FROM `$tbl`");
        $sampleCount[$tbl] = $countStmt->fetchColumn();
    }
} catch (PDOException $e) {
    $status = false;
    $message = "Kết nối thất bại: " . htmlspecialchars($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Test kết nối MySQL - Pet Care & Spa</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f9; padding: 40px; }
        .box { max-width: 600px; margin: auto; background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .success { color: #155724; background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; }
        .error { color: #721c24; background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 5px; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { border: 1px solid #ddd; padding: 10px; text-align: left; }
        th { background: #1B365D; color: #fff; }
    </style>
</head>
<body>
<div class="box">
    <h2>Kiểm tra kết nối MySQL (Pet Care & Spa)</h2>
    <div class="<?= $status ? 'success' : 'error' ?>">
        <?= $message ?>
    </div>

    <?php if ($status && count($tables) > 0): ?>
        <p>Hệ thống đã nhận diện đủ <strong><?= count($tables) ?> bảng</strong>:</p>
        <table>
            <thead>
                <tr>
                    <th>STT</th>
                    <th>Tên bảng</th>
                    <th>Số dòng dữ liệu mẫu</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tables as $index => $tbl): ?>
                    <tr>
                        <td><?= $index + 1 ?></td>
                        <td><strong><?= htmlspecialchars($tbl) ?></strong></td>
                        <td><?= $sampleCount[$tbl] ?> dòng</td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>