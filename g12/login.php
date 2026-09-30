<?php
// 1. เปิดระบบแสดงข้อผิดพลาด (Error Reporting) เพื่อดูสาเหตุ
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
include('connectdb.php'); 

// ตรวจสอบว่าตัวแปรเชื่อมต่อฐานข้อมูลถูกต้องหรือไม่
if (!isset($conn)) {
    die("❌ Error: ไม่พบตัวแปร \$conn ในไฟล์ connectdb.php (กรุณาเช็กชื่อตัวแปรเชื่อมต่อในไฟล์ connectdb.php)");
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    
    $email    = mysqli_real_escape_string($conn, $_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $sql    = "SELECT * FROM users WHERE email = '$email' LIMIT 1";
    $result = mysqli_query($conn, $sql);

    if ($result && mysqli_num_rows($result) > 0) {
        $user = mysqli_fetch_assoc($result);

        if (password_verify($password, $user['password']) || $password == $user['password']) {
            
            $user_id    = $user['id'] ?? $user['user_id'] ?? 1;
            $event_type = 'LOGIN';
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $user_agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

            $log_sql = "INSERT INTO audit_logs (user_id, event_type, ip_address, user_agent, created_at) 
                        VALUES ('$user_id', '$event_type', '$ip_address', '$user_agent', NOW())";
            
            // สั่งรันคำสั่งบันทึก
            $query_status = mysqli_query($conn, $log_sql);

            // เช็กว่า SQL มีปัญหาหรือไม่
            if (!$query_status) {
                die("❌ Error บันทึก Log ไม่สำเร็จ: " . mysqli_error($conn));
            }

            // เปลี่ยนหน้าไปยัง c.php
            header("Location: c.php");
            exit();

        } else {
            echo "<script>alert('รหัสผ่านไม่ถูกต้อง'); window.history.back();</script>";
        }
    } else {
        echo "<script>alert('ไม่พบอีเมลนี้ในระบบ'); window.history.back();</script>";
    }
} else {
    die("❌ Error: ฟอร์มล็อกอินไม่ได้ส่งข้อมูลแบบ POST มาที่ไฟล์ login.php นี้");
}
?>
