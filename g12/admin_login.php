<?php
session_start();
require_once 'connectdb.php';

if (!isset($conn) && isset($db)) { $conn =$db; }
if (!isset($conn) && isset($con)) { $conn =$con; }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_admin'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (!$conn) {$error = "ไม่สามารถเชื่อมต่อฐานข้อมูลได้ กรุณาตรวจสอบไฟล์ connectdb.php";
    } else {
        // 1. ค้นหาตารางแอดมินที่มีอยู่ในฐานข้อมูล
        $table_found = '';$possible_tables = ['admin', 'admins', 'tb_admin', 'tbl_admin', 'users'];

        foreach ($possible_tables as$tb) {
            $check = @mysqli_query($conn, "SHOW TABLES LIKE '$tb'");
            if ($check && mysqli_num_rows($check) > 0) {
                $table_found =$tb;
                break;
            }
        }

        if (empty($table_found)) {$error = "ไม่พบตารางข้อมูลผู้ดูแลระบบในฐานข้อมูล";
        } else {
            // 2. ดึงชื่อคอลัมน์ทั้งหมดของตารางนั้นมาตรวจสอบ
            $col_res = mysqli_query($conn, "SHOW COLUMNS FROM `$table_found`");
            $columns = [];
            while ($c = mysqli_fetch_assoc($col_res)) {
                $columns[] = strtolower($c['Field']);
            }

            $username_clean = mysqli_real_escape_string($conn, $username);$password_clean = mysqli_real_escape_string($conn,$password);

            // 3. ตรวจหาคอลัมน์ชื่อผู้ใช้และรหัสผ่านจากที่มีอยู่จริงในตาราง
            $user_col = '';
            foreach (['name', 'username', 'user', 'email', 'admin_name', 'admin_user', 'a_username'] as $f) {
                if (in_array($f,$columns)) { $user_col =$f; break; }
            }
            if (!$user_col && count($columns) > 0) {$user_col = $columns[1] ?? $columns[0]; }

            $pass_col = '';
            foreach (['password', 'pass', 'pwd', 'admin_password', 'a_pass'] as $p) {
                if (in_array($p,$columns)) { $pass_col =$p; break; }
            }
            if (!$pass_col && count($columns) > 1) {$pass_col = $columns[2] ?? $columns[1]; }

            // 4. สั่ง Query โดยใช้คอลัมน์ที่ตรวจพบจริงเท่านั้น
            $sql = "SELECT * FROM `$table_found` WHERE `$user_col` = '$username_clean' AND `$pass_col` = '$password_clean' LIMIT 1";
            $rs = @mysqli_query($conn,$sql);

            if ($rs && mysqli_num_rows($rs) > 0) {$row = mysqli_fetch_assoc($rs);$_SESSION['admin_login'] = true;
                $_SESSION['admin']       =$username;
                $_SESSION['role']        = 'admin';$_SESSION['admin_id']    = $row['admin_id'] ?? $row['id'] ?? 1;
                $_SESSION['user_name']   = $row[$user_col] ?? 'Admin';
                $_SESSION['username']    =$_SESSION['user_name'];

                header("Location: admin.php");
                exit;
            } else {
                $error = 'ชื่อผู้ใช้/อีเมล หรือรหัสผ่านไม่ถูกต้อง!';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>เข้าสู่ระบบผู้ดูแลระบบ - BAIKWANG STORE</title>
    <style>
        * { box-sizing: border-box; font-family: 'Segoe UI', Tahoma, sans-serif; margin: 0; padding: 0; }
        body { background: #0f2027; display: flex; justify-content: center; align-items: center; min-height: 100vh; color: #333; }
        .login-card { background: white; padding: 35px 30px; border-radius: 12px; width: 100%; max-width: 400px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
        .login-card h2 { font-size: 20px; color: #1e3799; text-align: center; margin-bottom: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; font-size: 13px; font-weight: bold; margin-bottom: 5px; }
        .form-group input { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
        .btn-login { width: 100%; background: #1e3799; color: white; border: none; padding: 10px; border-radius: 6px; font-size: 14px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        .btn-login:hover { background: #0c2461; }
        .error-msg { background: #ffe6e6; color: red; padding: 10px; border-radius: 6px; font-size: 13px; margin-bottom: 15px; text-align: center; line-height: 1.4; }
        .btn-back { display: block; text-align: center; margin-top: 15px; color: #777; font-size: 12px; text-decoration: none; }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>🔐 เข้าสู่ระบบผู้ดูแลระบบ</h2>
        <?php if ($error != '') { echo "<div class='error-msg'>$error</div>"; } ?>
        <form method="post">
            <div class="form-group">
                <label>ชื่อผู้ใช้ หรือ อีเมล</label>
                <input type="text" name="username" placeholder="กรอกชื่อผู้ใช้" required>
            </div>
            <div class="form-group">
                <label>รหัสผ่าน (Password)</label>
                <input type="password" name="password" placeholder="••••••••" required>
            </div>
            <button type="submit" name="login_admin" class="btn-login">เข้าสู่ระบบ</button>
        </form>
        <a href="c.php" class="btn-back">⬅ กลับหน้าหลักร้านค้า</a>
    </div>
</body>
</html>
