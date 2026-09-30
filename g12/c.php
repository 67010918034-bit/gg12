<?php
session_start();
require_once 'connectdb.php';

if (!isset($conn) && isset($db)) { $conn =$db; }
if (!isset($conn) && isset($con)) { $conn =$con; }

if (!isset($_SESSION['admin_login']) && !isset($_SESSION['admin']) && !isset($_SESSION['username'])) {
    header("Location: admin_login.php");
    exit();
}

if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

$admin_name = $_SESSION['admin'] ?? $_SESSION['username'] ?? 'Admin';

// --- ตัวแปรสรุปข้อมูล ---
$total_products = 0;
$total_stock = 0;
$total_sold = 0;
$total_revenue = 0;
$products_list = [];

if ($conn) {
    // 1. ตรวจสอบชื่อคอลัมน์ในตาราง products
    $col_res = mysqli_query($conn, "SHOW COLUMNS FROM products");
    $cols = [];
    if ($col_res) {
        while ($c = mysqli_fetch_assoc($col_res)) {
            $cols[] = strtolower($c['Field']);
        }
    }

    $id_col = in_array('id', $cols) ? 'id' : ($cols[0] ?? 'p_id');
    $name_col = in_array('name',$cols) ? 'name' : (in_array('title', $cols) ? 'title' : ($cols[1] ?? 'name'));
    $price_col = in_array('price',$cols) ? 'price' : ($cols[2] ?? 'price');$stock_col = '';
    foreach (['stock', 'qty', 'quantity', 'amount'] as $sk) {
        if (in_array($sk,$cols)) { $stock_col =$sk; break; }
    }

    // 2. ดึงยอดขายจากตาราง order_items หรือ orders (ถ้ามี)
    $sold_data = [];
    $has_order_items = mysqli_query($conn, "SHOW TABLES LIKE 'order_items'");
    if ($has_order_items && mysqli_num_rows($has_order_items) > 0) {
        $sold_query = mysqli_query($conn, "SELECT product_id, SUM(quantity) AS sold_qty, SUM(quantity * price) AS total_price FROM order_items GROUP BY product_id");
        if ($sold_query) {
            while ($s = mysqli_fetch_assoc($sold_query)) {
                $sold_data[$s['product_id']] = [
                    'qty' => $s['sold_qty'] ?? 0,                     'price' =>$s['total_price'] ?? 0
                ];
                $total_sold +=$s['sold_qty'] ?? 0;
                $total_revenue +=$s['total_price'] ?? 0;
            }
        }
    }

    // 3. ดึงรายการสินค้าทั้งหมด + คำนวณสต็อก
    $res1 = mysqli_query($conn, "SELECT COUNT(*) AS total FROM products");
    if ($res1) { $total_products = mysqli_fetch_assoc($res1)['total'] ?? 0; }

    if ($stock_col) {
        $res2 = mysqli_query($conn, "SELECT SUM(`$stock_col`) AS sum_stock FROM products");
        if ($res2) { $total_stock = mysqli_fetch_assoc($res2)['sum_stock'] ?? 0; }
    }

    $sql_p = "SELECT * FROM products ORDER BY `$id_col` DESC";
    $res_p = mysqli_query($conn,$sql_p);
    if ($res_p) {
        while ($row = mysqli_fetch_assoc($res_p)) {
            $p_id =$row[$id_col];$row['sold_qty'] = $sold_data[$p_id]['qty'] ?? 0;
            $row['sold_revenue'] = $sold_data[$p_id]['price'] ?? 0;
            $products_list[] =$row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>รายงานสต็อกและยอดขาย - BAIKWANG STORE</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, sans-serif; }
        body { background-color: #f4f6f9; color: #333; display: flex; min-height: 100vh; }
        .sidebar { width: 250px; background-color: #1e272e; color: #fff; padding: 20px 0; flex-shrink: 0; }
        .sidebar h2 { text-align: center; font-size: 20px; padding-bottom: 20px; border-bottom: 1px solid #34495e; color: #00d2d3; }
        .sidebar ul { list-style: none; margin-top: 20px; }
        .sidebar ul li a { display: block; padding: 12px 25px; color: #dcdde1; text-decoration: none; font-size: 15px; }
        .sidebar ul li a:hover, .sidebar ul li a.active { background-color: #34495e; color: #fff; border-left: 4px solid #00d2d3; }
        
        .main-content { flex-grow: 1; padding: 30px; }
        .header-bar { display: flex; justify-content: space-between; align-items: center; background: #fff; padding: 15px 25px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 25px; }
        .btn-logout { background-color: #ff4d4d; color: white; padding: 8px 15px; text-decoration: none; border-radius: 5px; font-size: 13px; font-weight: bold; }

        .dashboard-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); border-left: 5px solid #00d2d3; }
        .card h3 { font-size: 13px; color: #7f8c8d; margin-bottom: 8px; }
        .card p { font-size: 22px; font-weight: bold; color: #2c3e50; }

        .content-box { background: #fff; padding: 25px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .content-box h2 { font-size: 18px; margin-bottom: 15px; color: #2c3e50; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table th, table td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #ddd; font-size: 14px; }
        table th { background-color: #f8f9fa; color: #2c3e50; }
        table tr:hover { background-color: #f1f2f6; }
        
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: bold; color: white; }
        .bg-success { background-color: #10ac84; }
        .bg-warning { background-color: #ff9f43; }
        .bg-danger { background-color: #ee5253; }
    </style>
</head>
<body>

    <div class="sidebar">
        <h2>BAIKWANG ADMIN</h2>
        <ul>
            <li><a href="admin.php" class="active">📊 เช็คสต็อก & ยอดขาย</a></li>
            <li><a href="c.php">🛍️ หน้าหน้าร้านค้า</a></li>
            <li><a href="?logout=1" style="color: #ff6b6b;">🚪 ออกจากระบบ</a></li>
        </ul>
    </div>

    <div class="main-content">
        <div class="header-bar">
            <div>ยินดีต้อนรับผู้ดูแลระบบ: <span style="color: #10ac84; font-weight: bold;"><?php echo htmlspecialchars($admin_name); ?></span></div>
            <a href="?logout=1" class="btn-logout">ออกจากระบบ</a>
        </div>

        <!-- การ์ดสรุปผล -->
        <div class="dashboard-cards">
            <div class="card" style="border-left-color: #10ac84;">
                <h3>ขายไปแล้วทั้งหมด</h3>
                <p style="color: #10ac84;"><?php echo number_format($total_sold); ?> ชิ้น</p>
            </div>
            <div class="card" style="border-left-color: #2e86de;">
                <h3>รายได้รวมจากการขาย</h3>
                <p style="color: #2e86de;">฿<?php echo number_format($total_revenue, 2); ?></p>
            </div>
            <div class="card" style="border-left-color: #ff9f43;">
                <h3>คงเหลือในสต็อก</h3>
                <p><?php echo number_format($total_stock); ?> ชิ้น</p>
            </div>
            <div class="card" style="border-left-color: #8395a7;">
                <h3>รายการสินค้าทั้งหมด</h3>
                <p><?php echo number_format($total_products); ?> รายการ</p>
            </div>
        </div>

        <!-- ตารางรายละเอียด -->
        <div class="content-box">
            <h2>📋 รายงานเช็คสต็อกและจำนวนที่ขายได้</h2>
            <table>
                <thead>
                    <tr>
                        <th># รหัส</th>
                        <th>ชื่อสินค้า</th>
                        <th>ราคา/ชิ้น</th>
                        <th>ขายไปแล้ว (ชิ้น)</th>
                        <th>คงเหลือในสต็อก</th>
                        <th>สถานะสต็อก</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($products_list)): ?>
                        <?php foreach ($products_list as$item): 
                            $id =$item[$id_col] ?? '-';$p_name = $item[$name_col] ?? 'ไม่ระบุชื่อ';
                            $p_price = isset($item[$price_col]) ? number_format($item[$price_col], 2) : '0.00';
                            $p_stock =$stock_col ? ($item[$stock_col] ?? 0) : 0;
                            $p_sold =$item['sold_qty'] ?? 0;
                        ?>
                            <tr>
                                <td>#<?php echo htmlspecialchars($id); ?></td>
                                <td><b><?php echo htmlspecialchars($p_name); ?></b></td>
                                <td>฿<?php echo $p_price; ?></td>
                                <td><b style="color: #10ac84; font-size: 15px;"><?php echo number_format($p_sold); ?></b> ชิ้น</td>
                                <td><b style="font-size: 15px;"><?php echo number_format($p_stock); ?></b> ชิ้น</td>
                                <td>
                                    <?php if ($p_stock > 10): ?>
                                        <span class="badge bg-success">พร้อมขาย</span>
                                    <?php elseif ($p_stock > 0): ?>
                                        <span class="badge bg-warning">ใกล้หมด</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">สินค้าหมด</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #888; padding: 20px;">
                                ไม่พบข้อมูลสินค้าในระบบ
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</body>
</html>
