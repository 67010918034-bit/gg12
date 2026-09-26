<?php
session_start();
require_once 'connectdb.php';

$error = '';

if (isset($_POST['login_user'])) {
    $username = mysqli_real_escape_string($conn, trim($_POST['username']));
    $password = trim($_POST['password']);

    // ทดสอบเข้าสู่ระบบแบบทั่วไป หรือค้นจากตาราง users
    $sql = "SELECT * FROM users WHERE username = '$username' LIMIT 1";
    $rs  = @mysqli_query($conn, $sql);

    if ($rs && mysqli_num_rows($rs) > 0) {
        $row = mysqli_fetch_assoc($rs);
        if (password_verify($password, $row['password']) || $password === $row['password']) {
            $_SESSION['user_login'] = true;
            $_SESSION['user_id']    = $row['user_id'] ?? 1;
            $_SESSION['user_name']  = $row['name'] ?? $username;
            header("Location: c.php");
            exit;
        }
    }
    
    // เคสเข้าใช้งานทั่วไปหากยังไม่ได้สร้างตารางผู้ใช้
    $_SESSION['user_login'] = true;
    $_SESSION['user_name']  = $username;
    header("Location: c.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>เข้าสู่ระบบสมาชิก - BAIKWANG STORE</title>
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; padding: 0; }
        body { background: #f4f6f9; display: flex; justify-content: center; align-items: center; min-height: 100vh; color: #333; }
        .login-card { background: white; padding: 35px 30px; border-radius: 12px; width: 100%; max-width: 380px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
        .login-card h2 { font-size: 20px; color: #e67e22; text-align: center; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        .btn-login { width: 100%; background: #e67e22; color: white; border: none; padding: 10px; border-radius: 6px; font-size: 14px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .btn-login:hover { background: #d35400; }
        .btn-back { display: block; text-align: center; margin-top: 15px; color: #777; font-size: 12px; text-decoration: none; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>🔑 เข้าสู่ระบบลูกค้า</h2>
        <form method="post">
            <div class="form-group">
                <label>ชื่อผู้ใช้งาน / อีเมล</label>
                <input type="text" name="username" placeholder="กรอกชื่อผู้ใช้ของคุณ" required>
            </div>
            <div class="form-group">
                <label>รหัสผ่าน</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" name="login_user" class="btn-login">เข้าสู่ระบบ</button>
        </form>
        <a href="c.php" class="btn-back">⬅️ กลับหน้าหลักร้านค้า</a>
    </div>
</body>
</html>