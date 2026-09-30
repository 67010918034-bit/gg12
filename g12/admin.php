<?php
session_start();
require_once 'connectdb.php';

// ดึงข้อมูลสินค้า + คำนวณยอดขายจาก order_items
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

// จัดการอัปเดตสต็อก
$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    $p_id = intval($_POST['product_id']);
    $new_stock = intval($_POST['stock_qty']);
    if (mysqli_query($conn, "UPDATE products SET stock = $new_stock WHERE product_id = $p_id")) {
        $msg = "อัปเดตสต็อกเรียบร้อยแล้ว!";
        header("Location: admin.php");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ระบบหลังบ้าน - Admin Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-100 p-6">
    <div class="max-w-6xl mx-auto bg-white p-6 rounded-xl shadow-md">
        <div class="flex justify-between items-center mb-6">
            <h1 class="text-2xl font-bold text-slate-800"><i class="fa-solid fa-chart-line text-amber-500"></i> รายงานยอดขาย & จัดการสต็อก</h1>
            <a href="c.php" class="bg-slate-800 text-white px-4 py-2 rounded-lg text-sm">ไปหน้าร้านค้า</a>
        </div>

        <table class="w-full text-left border-collapse">
            <thead>
                <tr class="bg-slate-900 text-white text-sm">
                    <th class="p-3">รหัส</th>
                    <th class="p-3">ชื่อสินค้า</th>
                    <th class="p-3 text-right">ราคา</th>
                    <th class="p-3 text-center">ขายแล้ว (ชิ้น)</th>
                    <th class="p-3 text-right">ยอดขายรวม</th>
                    <th class="p-3 text-center">คงเหลือในสต็อก</th>
                    <th class="p-3 text-center">แก้ไขสต็อก</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                <?php if ($result_summary): ?>
                    <?php while ($p = mysqli_fetch_assoc($result_summary)): ?>
                        <tr>
                            <td class="p-3">#<?= $p['product_id'] ?></td>
                            <td class="p-3 font-bold"><?= htmlspecialchars($p['product_name']) ?></td>
                            <td class="p-3 text-right">฿<?= number_format($p['price'], 2) ?></td>
                            <td class="p-3 text-center text-amber-600 font-bold"><?= $p['total_sold'] ?></td>
                            <td class="p-3 text-right text-emerald-600 font-bold">฿<?= number_format($p['total_revenue'], 2) ?></td>
                            <td class="p-3 text-center font-bold"><?= $p['stock'] ?></td>
                            <td class="p-3 text-center">
                                <form method="POST" class="flex justify-center gap-2">
                                    <input type="hidden" name="product_id" value="<?= $p['product_id'] ?>">
                                    <input type="number" name="stock_qty" value="<?= $p['stock'] ?>" class="w-16 border rounded text-center">
                                    <button type="submit" name="update_stock" class="bg-amber-500 text-slate-900 px-2 py-1 rounded font-bold text-xs">บันทึก</button>
                                </form>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</body>
</html>
