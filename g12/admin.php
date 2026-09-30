<?php
session_start();
require_once 'connectdb.php';

// ตรวจสอบชื่อตัวแปรเชื่อมต่อ DB
if (!isset($conn) && isset($db)) { $conn = $db; }
if (!isset($conn) && isset($con)) { $conn = $con; }

// ตรวจสอบการเข้าสู่ระบบ
if (!isset($_SESSION['admin_login']) && !isset($_SESSION['admin'])) {
    header("Location: admin_login.php");
    exit;
}

// ---------------------------------------------------------------------
// 📌 จัดการอัปเดตสต็อกสินค้า
// ---------------------------------------------------------------------
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    $p_id = intval($_POST['product_id']);
    $new_stock = intval($_POST['stock_qty']);
    
    $update_sql = "UPDATE products SET stock = $new_stock WHERE product_id = $p_id";
    if (mysqli_query($conn, $update_sql)) {
        $msg = "อัปเดตสต็อกสินค้าเรียบร้อยแล้ว!";
    }
}

// ---------------------------------------------------------------------
// 📌 ดึงข้อมูลสินค้า + คำนวณยอดขายจาก order_items
// ---------------------------------------------------------------------
$sql_summary = "SELECT 
                    p.product_id, 
                    p.product_name, 
                    p.price, 
                    p.stock, 
                    COALESCE(SUM(oi.quantity), 0) AS total_sold,
                    COALESCE(SUM(oi.quantity * oi.price), 0) AS total_revenue
                FROM products p
                LEFT JOIN order_items oi ON p.product_id = oi.product_id
                GROUP BY p.product_id
                ORDER BY p.product_id ASC";

$result_summary = mysqli_query($conn, $sql_summary);

// คำนวณยอดขายรวมทั้งหมด
$grand_total_sold = 0;
$grand_total_revenue = 0;
$products_data = [];

if ($result_summary) {
    while ($row = mysqli_fetch_assoc($result_summary)) {
        $grand_total_sold += $row['total_sold'];
        $grand_total_revenue += $row['total_revenue'];
        $products_data[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ระบบหลังบ้าน - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style> body { font-family: 'Kanit', sans-serif; } </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen">

    <!-- Navbar -->
    <header class="bg-slate-900 text-white shadow-lg sticky top-0 z-30">
        <div class="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="bg-amber-500 text-slate-900 p-2 rounded-lg font-bold text-lg">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <h1 class="text-xl font-bold tracking-wide">ระบบจัดการหลังร้าน (Admin Dashboard)</h1>
            </div>
            <div class="flex items-center gap-4">
                <span class="text-sm text-slate-300">ผู้ใช้งาน: <strong class="text-amber-400"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></strong></span>
                <a href="c.php" class="bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold px-3.5 py-1.5 rounded-lg text-sm transition">
                    <i class="fa-solid fa-store"></i> ไปหน้าร้านค้า
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 py-8 space-y-6">

        <?php if (!empty($msg)): ?>
            <div class="bg-emerald-500 text-white px-4 py-3 rounded-xl shadow-md font-medium flex items-center gap-2">
                <i class="fa-solid fa-circle-check"></i> <?= $msg ?>
            </div>
        <?php endif; ?>

        <!-- Cards สรุปภาพรวม -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500 font-medium">ยอดขายรวมทั้งหมด</p>
                    <h3 class="text-2xl font-bold text-emerald-600 mt-1">฿<?= number_format($grand_total_revenue, 2) ?></h3>
                </div>
                <div class="bg-emerald-100 text-emerald-600 p-4 rounded-xl text-2xl">
                    <i class="fa-solid fa-money-bill-wave"></i>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500 font-medium">จำนวนสินค้าที่ขายได้รวม</p>
                    <h3 class="text-2xl font-bold text-amber-600 mt-1"><?= number_format($grand_total_sold) ?> <span class="text-sm font-normal text-slate-500">ชิ้น</span></h3>
                </div>
                <div class="bg-amber-100 text-amber-600 p-4 rounded-xl text-2xl">
                    <i class="fa-solid fa-boxes-packing"></i>
                </div>
            </div>

            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 flex items-center justify-between">
                <div>
                    <p class="text-xs text-slate-500 font-medium">รายการสินค้าทั้งหมด</p>
                    <h3 class="text-2xl font-bold text-slate-800 mt-1"><?= count($products_data) ?> <span class="text-sm font-normal text-slate-500">รายการ</span></h3>
                </div>
                <div class="bg-slate-100 text-slate-700 p-4 rounded-xl text-2xl">
                    <i class="fa-solid fa-list"></i>
                </div>
            </div>
        </div>

        <!-- ตารางแสดงรายการและแก้ไขสต็อก -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="p-5 border-b border-slate-100 bg-slate-50">
                <h2 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                    <i class="fa-solid fa-list-check text-amber-500"></i> รายการสินค้า สต็อก และยอดขาย
                </h2>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-900 text-white text-sm">
                            <th class="p-4">รหัสสินค้า</th>
                            <th class="p-4">ชื่อสินค้า</th>
                            <th class="p-4 text-right">ราคา/ชิ้น</th>
                            <th class="p-4 text-center">ขายแล้ว (ชิ้น)</th>
                            <th class="p-4 text-right">ยอดขายรวม</th>
                            <th class="p-4 text-center">คงเหลือในสต็อก</th>
                            <th class="p-4 text-center">แก้ไขสต็อก</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-200 text-sm">
                        <?php if (count($products_data) > 0): ?>
                            <?php foreach ($products_data as $p): ?>
                                <tr class="hover:bg-slate-50 transition">
                                    <td class="p-4 font-mono text-slate-500">#<?= $p['product_id'] ?></td>
                                    <td class="p-4 font-bold text-slate-800"><?= htmlspecialchars($p['product_name']) ?></td>
                                    <td class="p-4 text-right font-medium">฿<?= number_format($p['price'], 2) ?></td>
                                    <td class="p-4 text-center">
                                        <span class="bg-amber-100 text-amber-800 px-3 py-1 rounded-full font-bold">
                                            <?= number_format($p['total_sold']) ?>
                                        </span>
                                    </td>
                                    <td class="p-4 text-right font-bold text-emerald-600">
                                        ฿<?= number_format($p['total_revenue'], 2) ?>
                                    </td>
                                    <td class="p-4 text-center">
                                        <span class="px-3 py-1 rounded-full font-bold <?= $p['stock'] < 5 ? 'bg-rose-100 text-rose-700' : 'bg-slate-100 text-slate-700' ?>">
                                            <?= number_format($p['stock']) ?>
                                        </span>
                                    </td>
                                    <td class="p-4">
                                        <form method="POST" class="flex justify-center items-center gap-2">
                                            <input type="hidden" name="product_id" value="<?= $p['product_id'] ?>">
                                            <input type="number" name="stock_qty" value="<?= $p['stock'] ?>" min="0" class="w-20 px-2 py-1 border rounded-lg text-center font-bold focus:ring-2 focus:ring-amber-500 outline-none">
                                            <button type="submit" name="update_stock" class="bg-slate-900 hover:bg-amber-500 hover:text-slate-950 text-white font-bold px-3 py-1 rounded-lg text-xs transition">
                                                บันทึก
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="7" class="text-center p-6 text-slate-400">ไม่พบข้อมูลสินค้า</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

</body>
</html>
