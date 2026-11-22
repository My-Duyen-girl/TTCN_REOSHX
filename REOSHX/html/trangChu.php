<?php
session_start();
require_once '../db.php';

// Lấy danh sách dịch vụ từ bảng DichVu để hiển thị động lên trang chủ
$stmtDV = $conn->query("SELECT * FROM DichVu ORDER BY MaDichVu ASC LIMIT 4");
$dsDichVu = $stmtDV->fetchAll();

// Xác định đường dẫn trang làm việc theo vai trò nếu người dùng đã đăng nhập
$dashboardLink = 'dangNhap.php';
$dashboardLabel = 'Đặt lịch ngay';

if (isset($_SESSION['VaiTro'])) {
    if ($_SESSION['VaiTro'] === 'Khách hàng') {
        $dashboardLink = 'khachhang_thucung.php';
        $dashboardLabel = 'Đặt lịch ngay';
    } elseif ($_SESSION['VaiTro'] === 'Nhân viên') {
        $dashboardLink = 'nhanvien_lichhen.php';
        $dashboardLabel = 'Quản lý lịch hẹn';
    } elseif ($_SESSION['VaiTro'] === 'Admin') {
        $dashboardLink = 'admin_khachhang.php';
        $dashboardLabel = 'Trang quản trị';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trang chủ</title>

    <style>
        * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

html {
    scroll-behavior: smooth;
}

body {
    background-color: #faf7f2;
    color: #243429;
    line-height: 1.6;
}

.container {
    max-width: 1160px;
    margin: 0 auto;
    padding: 0 24px;
}

/* ================= HEADER ================= */
.navbar {
    background-color: #ffffff;
    border-bottom: 1px solid #eceae4;
    position: sticky;
    top: 0;
    z-index: 100;
}

.navbar-inner {
    height: 76px;
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.brand {
    text-decoration: none;
    display: flex;
    flex-direction: column;
}

.brand-name {
    font-size: 22px;
    font-weight: 800;
    color: #2b3d30;
    line-height: 1.1;
}

.brand-sub {
    font-size: 9.5px;
    font-weight: 600;
    letter-spacing: 1px;
    color: #7b887e;
    margin-top: 2px;
}

.nav-links {
    display: flex;
    align-items: center;
    gap: 34px;
}

.nav-links a {
    text-decoration: none;
    color: #36473b;
    font-size: 14.5px;
    font-weight: 500;
    transition: color 0.2s;
}

.nav-links a:hover {
    color: #556e57;
}

.nav-actions {
    display: flex;
    align-items: center;
    gap: 20px;
}

.link-login {
    text-decoration: none;
    color: #2b3d30;
    font-size: 14.5px;
    font-weight: 600;
}

.link-login:hover {
    text-decoration: underline;
}

.user-greeting {
    font-size: 14px;
    color: #4f6354;
    font-weight: 600;
}

.btn-primary {
    background-color: #556e57;
    color: #ffffff;
    text-decoration: none;
    padding: 11px 22px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    display: inline-block;
    transition: background-color 0.2s, transform 0.1s;
}

.btn-primary:hover {
    background-color: #445946;
}

.btn-outline {
    background-color: #ffffff;
    color: #2b3d30;
    text-decoration: none;
    padding: 11px 22px;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    border: 1px solid #dfe5dc;
    display: inline-block;
    transition: background-color 0.2s;
}

.btn-outline:hover {
    background-color: #f3f6f1;
}

/* ================= HERO SECTION ================= */
.hero {
    background-color: #e8efe3;
    padding: 64px 0;
}

.hero-inner {
    display: grid;
    grid-template-columns: 1.15fr 0.85fr;
    align-items: center;
    gap: 48px;
}

.hero-eyebrow {
    font-size: 11.5px;
    font-weight: 700;
    letter-spacing: 1.2px;
    color: #556e57;
    text-transform: uppercase;
    margin-bottom: 14px;
}

.hero-title {
    font-size: 44px;
    font-weight: 800;
    color: #27382c;
    line-height: 1.18;
    margin-bottom: 18px;
}

.hero-desc {
    font-size: 15.5px;
    color: #5b6b5f;
    max-width: 470px;
    margin-bottom: 30px;
}

.hero-buttons {
    display: flex;
    align-items: center;
    gap: 14px;
}

.hero-art-box {
    background-color: #f3e7d3;
    border-radius: 20px;
    height: 360px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 10px 28px rgba(43, 61, 48, 0.06);
}

.hero-art-box svg {
    width: 240px;
    height: 240px;
}

/* ================= SERVICES SECTION ================= */
.services {
    padding: 64px 0;
    background-color: #faf7f2;
}

.section-title {
    font-size: 26px;
    font-weight: 800;
    color: #27382c;
    margin-bottom: 28px;
}

.services-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

.service-card {
    background-color: #ffffff;
    border-radius: 12px;
    padding: 24px 22px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.03);
    border: 1px solid #f0ede6;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    transition: transform 0.2s, box-shadow 0.2s;
}

.service-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 22px rgba(43, 61, 48, 0.08);
}

.service-icon {
    width: 38px;
    height: 38px;
    background-color: #e8efe3;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 18px;
}

.service-icon svg {
    width: 18px;
    height: 18px;
    fill: #556e57;
}

.service-name {
    font-size: 16px;
    font-weight: 700;
    color: #243429;
    margin-bottom: 6px;
}

.service-note {
    font-size: 13.5px;
    color: #77857a;
    margin-bottom: 14px;
}

.service-price {
    font-size: 14.5px;
    font-weight: 700;
    color: #556e57;
    padding-top: 10px;
    border-top: 1px dashed #eceae4;
}

/* ================= PROCESS SECTION ================= */
.process {
    padding: 56px 0;
    background-color: #ffffff;
    border-top: 1px solid #f0ede6;
}

.process-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 24px;
}

.process-step {
    background-color: #faf7f2;
    padding: 26px;
    border-radius: 12px;
    border: 1px solid #eceae4;
}

.step-num {
    display: inline-block;
    background-color: #556e57;
    color: #ffffff;
    font-size: 12px;
    font-weight: 700;
    padding: 4px 10px;
    border-radius: 20px;
    margin-bottom: 12px;
}

.process-step h3 {
    font-size: 17px;
    color: #243429;
    margin-bottom: 8px;
}

.process-step p {
    font-size: 14px;
    color: #66756a;
}

/* ================= FOOTER ================= */
.footer {
    background-color: #27382c;
    color: #dbe4dc;
    padding: 36px 0;
    font-size: 14px;
}

.footer-inner {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
}

.footer strong {
    color: #ffffff;
    font-size: 16px;
}

/* Responsive */
@media (max-width: 920px) {
    .hero-inner {
        grid-template-columns: 1fr;
    }
    .services-grid {
        grid-template-columns: repeat(2, 1fr);
    }
    .process-grid {
        grid-template-columns: 1fr;
    }
    .nav-links {
        display: none;
    }
}
    </style>
</head>
<body>
    <!-- THANH ĐIỀU HƯỚNG -->
    <header class="navbar">
        <div class="container navbar-inner">
            <a href="trangChu.php" class="brand">
                <span class="brand-name">Pet care</span>
                <span class="brand-sub">CHĂM SÓC BÉ CƯNG</span>
            </a>

            <nav class="nav-links">
                <a href="trangChu.php">Trang chủ</a>
                <a href="#dich-vu">Dịch vụ</a>
                <a href="#quy-trinh">Quy trình</a>
                <a href="#ve-chung-toi">Về chúng tôi</a>
            </nav>

            <div class="nav-actions">
                <?php if (isset($_SESSION['VaiTro'])): ?>
                    <span class="user-greeting">Xin chào, <?= htmlspecialchars($_SESSION['HoTen']) ?></span>
                    <a href="<?= $dashboardLink ?>" class="btn-primary"><?= $dashboardLabel ?></a>
                    <a href="dangXuat.php" class="link-login">Đăng xuất</a>
                <?php else: ?>
                    <a href="dangNhap.php" class="link-login">Đăng nhập</a>
                    <a href="dangNhap.php" class="btn-primary">Đặt lịch ngay</a>
                <?php endif; ?>
            </div>
        </div>
    </header>

    <!-- PHẦN MỞ ĐẦU (HERO) -->
    <section class="hero">
        <div class="container hero-inner">
            <div class="hero-content">
                <div class="hero-eyebrow">PET CARE & SPA · YÊU THƯƠNG TỪNG KHOẢNH KHẮC</div>
                <h1 class="hero-title">Chăm sóc bé cưng,<br>nhẹ nhàng như ở nhà.</h1>
                <p class="hero-desc">
                    Đặt lịch spa trực tuyến, quản lý hồ sơ thú cưng và theo dõi trạng thái chăm sóc của bé một cách thuận tiện, minh bạch.
                </p>
                <div class="hero-buttons">
                    <a href="#dich-vu" class="btn-primary">Khám phá dịch vụ</a>
                    <a href="#quy-trinh" class="btn-outline">Tìm hiểu thêm</a>
                </div>
            </div>

            <!-- Hình minh họa tối giản giống bản thiết kế -->
            <div class="hero-art-box">
                <svg viewBox="0 0 260 260" xmlns="http://www.w3.org/2000/svg">
                    <!-- Tai trái -->
                    <rect x="36" y="64" width="66" height="128" rx="33" fill="#986c4a"/>
                    <!-- Tai phải -->
                    <rect x="158" y="64" width="66" height="128" rx="33" fill="#986c4a"/>
                    <!-- Khuôn mặt tròn -->
                    <circle cx="130" cy="134" r="78" fill="#b48b63"/>
                    <!-- Chiếc mũi nhỏ -->
                    <circle cx="130" cy="146" r="11" fill="#31473a"/>
                </svg>
            </div>
        </div>
    </section>

    <!-- DANH MỤC DỊCH VỤ (LẤY TỪ MYSQL) -->
    <section id="dich-vu" class="services">
        <div class="container">
            <h2 class="section-title">Dịch vụ dành riêng cho bé</h2>

            <div class="services-grid">
                <?php
                $moTaNgan = [
                    'Sạch sẽ, thơm tho, dịu nhẹ cho da.',
                    'Gọn gàng, đáng yêu đúng chuẩn.',
                    'Thư giãn và chăm sóc toàn diện.',
                    'Vệ sinh kỹ lưỡng, an toàn cho bé.'
                ];
                ?>
                <?php foreach ($dsDichVu as $index => $dv): ?>
                    <div class="service-card">
                        <div>
                            <div class="service-icon">
                                <svg viewBox="0 0 24 24">
                                    <path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/>
                                </svg>
                            </div>
                            <div class="service-name"><?= htmlspecialchars($dv['TenDichVu']) ?></div>
                            <div class="service-note"><?= $moTaNgan[$index % count($moTaNgan)] ?></div>
                        </div>
                        <div class="service-price">
                            <?= number_format($dv['GiaTien'], 0, ',', '.') ?> VNĐ
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- QUY TRÌNH ĐẶT LỊCH -->
    <section id="quy-trinh" class="process">
        <div class="container">
            <h2 class="section-title">Quy trình đặt lịch 3 bước</h2>
            <div class="process-grid">
                <div class="process-step">
                    <span class="step-num">Bước 1</span>
                    <h3>Đăng ký & Tạo hồ sơ thú cưng</h3>
                    <p>Tạo tài khoản khách hàng và cập nhật thông tin loài, cân nặng, lưu ý dị ứng của bé.</p>
                </div>
                <div class="process-step">
                    <span class="step-num">Bước 2</span>
                    <h3>Chọn dịch vụ & Khung giờ hẹn</h3>
                    <p>Lựa chọn gói dịch vụ phù hợp cùng thời gian bắt đầu, kết thúc mong muốn.</p>
                </div>
                <div class="process-step">
                    <span class="step-num">Bước 3</span>
                    <h3>Xác nhận & Chăm sóc tại Spa</h3>
                    <p>Nhân viên tiếp nhận duyệt lịch hẹn và cập nhật trạng thái chăm sóc theo thời gian thực.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- CHÂN TRANG (VỀ CHÚNG TÔI) -->
    <footer id="ve-chung-toi" class="footer">
        <div class="container footer-inner">
            <div>
                <strong>Pet Care & Spa</strong>
                <p>Hệ thống quản lý và đặt lịch dịch vụ chăm sóc thú cưng trực tuyến.</p>
            </div>
            <div>
                <p>Giờ mở cửa: 08:00 – 20:00 (Thứ 2 – Chủ nhật)</p>
                <p>Hotline hỗ trợ: 0901 000 001</p>
            </div>
        </div>
    </footer>
</body>
</html>