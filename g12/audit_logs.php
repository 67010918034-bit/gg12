<?php
session_start();
require_once 'connectdb.php';

// ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION['user'])) {
    header("Location: c.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Audit Logs</title>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Prompt', sans-serif; padding: 20px; background: #f8fafc; }
        .container { max-width: 1000px; margin: 0 auto; background: #fff; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #0f172a; }
        .btn-back { display: inline-block; padding: 8px 16px; background: #2563eb; color: #fff; text-decoration: none; border-radius: 6px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <div class="container">
        <a href="c.php" class="btn-back">⬅️ กลับหน้าหลัก</a>
        <h1>📜 ประวัติการใช้งานระบบ (Audit Logs)</h1>
        <p>ยินดีต้อนรับ: <b><?php echo htmlspecialchars($_SESSION['user']['email']); ?></b></p>
        <hr style="margin: 20px 0; border: 0; border-top: 1px solid #e2e8f0;">
        
        <p>แสดงรายการ Log การทำงานในระบบ...</p>
    </div>
</body>
</html>
