
<?php

$host = "127.0.0.1";
$port = "3306";
$dbname = "datadoan";
$username = "root";
// Nhập mật khẩu MySQL thực tế của WampServer.
// Nếu tài khoản root không có mật khẩu thì để trống.
$password = "";
try {

    $conn = new PDO(
        "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $conn->setAttribute(
        PDO::ATTR_ERRMODE,
        PDO::ERRMODE_EXCEPTION
    );

    $conn->setAttribute(
        PDO::ATTR_DEFAULT_FETCH_MODE,
        PDO::FETCH_ASSOC
    );

} catch (PDOException $e) {

    http_response_code(500);

    die("Lỗi kết nối Database: " . $e->getMessage());
}

?>
