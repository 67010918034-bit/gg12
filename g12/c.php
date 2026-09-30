<?php
session_start();
require_once 'connectdb.php';

if (!isset($conn) && isset($db)) { $conn =$db; }
if (!isset($conn) && isset($con)) { $conn =$con; }

// ตรวจสอบโครงสร้างคอลัมน์ในตาราง products
$cols = [];
if ($conn) {
    $col_res = mysqli_query($conn, "SHOW COLUMNS FROM products");
    if ($col_res) {
        while ($c = mysqli_fetch_assoc($col_res)) {
            $cols[] = strtolower($c['Field']);
        }
    }
}

$id_col = in_array('id',$cols) ? 'id' : ($cols[0] ?? 'p_id');$name_col = in_array('name', $cols) ? 'name' : (in_array('title',$cols) ? 'title' : ($cols[1] ?? 'name'));$price_col = in_array('price', $cols) ? 'price' : ($cols[2] ?? 'price');

$stock_col = '';
foreach (['stock', 'qty', 'quantity', 'amount'] as $sk) {
    if (in_array($sk,$cols)) { $stock_col =$sk; break; }
}

$order_msg = '';

// ระบบสั่งซื้อสินค้าและตัดสต็อก
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['buy_product'])) {
    $p_id = intval($_POST['product_id']);
    $buy_qty = intval($_POST['buy_qty'] ?? 1);

    if ($p_id > 0 &&$buy_qty > 0 && $conn) {$check_sql = "SELECT * FROM products WHERE `$id_col` = $p_id LIMIT 1";
        $check_res = mysqli_query($conn,$check_sql);
        
        if ($check_res && mysqli_num_rows($check_res) > 0) {
            $item_data = mysqli_fetch_assoc($check_res);
            $current_stock =$stock_col ? intval($item_data[$stock_col]) : 999;
            $price = floatval($item_data[$price_col]);

            if ($current_stock >=$buy_qty) {
                // หักสต็อก
                if ($stock_col) {$update_stock = "UPDATE products SET `$stock_col` = `$stock_col` - $buy_qty WHERE `$id_col` = $p_id";
                    mysqli_query($conn,$update_stock);
                }

                // บันทึกรายการสั่งซื้อลง order_items (ถ้ามีตาราง)
                $has_order_items = mysqli_query($conn, "SHOW TABLES LIKE 'order_items'");
                if ($has_order_items && mysqli_num_rows($has_order_items) > 0) {$insert_order = "INSERT INTO order_items (product_id, quantity, price) VALUES ($p_id, $buy_qty,$price)";
                    mysqli_query($conn,$insert_order);
                }

                $order_msg = "success";
            } else {
                $order_msg = "out_of_stock";
            }
        }
    }
}

// ดึงรายการสินค้าทั้งหมด
$products_list = [];
if ($conn) {$sql_p = "SELECT * FROM products ORDER BY `$id_col` DESC";
    $res_p = mysqli_query($conn,$sql_p);
    if ($res_p) {
        while ($row = mysqli_fetch_assoc($res_p)) {
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
    <title>G12 Guitar Store - ร้านขายเครื่องดนตรี</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, sans-serif; }
        body { background-color: #0f172a; color: #f8fafc; min-height: 100vh; }
        
        /* Navigation Bar */
        .navbar { background-color: #090d16; padding: 18px 60px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #1e293b; }
        .logo { display: flex; align-items: center; gap: 12px; font-size: 22px; font-weight: 800; letter-spacing: 1px; color: #fff; }
        .logo-icon { background: #f59e0b; padding: 8px 12px; border-radius: 10px; font-size: 18px; color: #000; }
        .nav-actions { display: flex; gap: 12px; align-items: center; }
        .btn-nav { background: #1e293b; color: #f8fafc; border: none; padding: 10px 20px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 600; cursor: pointer; transition: 0.2s; }
        .btn-nav:hover { background: #334155; }
        .btn-amber { background: #f59e0b; color: #000; }
        .btn-amber:hover { background: #d97706; }

        /* Main Container */
        .container { max-width: 1280px; margin: 40px auto; padding: 0 30px; }
        .category-header { margin-bottom: 25px; border-left: 5px solid #f59e0b; padding-left: 15px; }
        .category-title { font-size: 26px; font-weight: 700; color: #fff; }
        .category-desc { color: #94a3b8; font-size: 14px; margin-top: 4px; }

        /* Grid & Cards */
        .product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr)); gap: 25px; }
        .product-card { background: #1e293b; border-radius: 16px; padding: 20px; border: 1px solid #334155; display: flex; flex-direction: column; justify-content: space-between; transition: transform 0.2s, box-shadow 0.2s; }
        .product-card:hover { transform: translateY(-4px); box-shadow: 0 10px 20px rgba(0,0,0,0.3); }
        
        .img-box { background: #fff; border-radius: 12px; padding: 15px; height: 220px; display: flex; align-items: center; justify-content: center; margin-bottom: 18px; }
        .product-img { max-width: 100%; max-height: 100%; object-fit: contain; }

        .product-info { margin-bottom: 15px; }
        .product-name { font-size: 17px; font-weight: 700; color: #fff; margin-bottom: 6px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .product-stock { font-size: 13px; color: #94a3b8; }
        .product-stock.low { color: #ef4444; font-weight: 600; }

        .product-footer { display: flex; justify-content: space-between; align-items: center; padding-top: 12px; border-top: 1px solid #334155; }
        .price-group { display: flex; flex-direction: column; }
        .price-label { font-size: 11px; color: #64748b; text-transform: uppercase; }
        .price-amount { font-size: 22px; font-weight: 800; color: #f59e0b; }

        .btn-buy { background: #f59e0b; color: #000; border: none; width: 44px; height: 44px; border-radius: 10px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 18px; transition: 0.2s; }
        .btn-buy:hover { background: #d97706; }
        .btn-buy:disabled { background: #475569; color: #94a3b8; cursor: not-allowed; }

        /* Alerts */
        .alert-toast { padding: 15px 20px; border-radius: 10px; margin-bottom: 25px; font-weight: 600; text-align: center; font-size: 15px; }
        .alert-success { background: #064e3b; color: #34d399; border: 1px solid #059669; }
        .alert-danger { background: #7f1d1d; color: #fca5a5; border: 1px solid #dc2626; }
    </style>
</head>
<body>

    <!-- Header Navbar -->
    <div class="navbar">
        <div class="logo">
            <span class="logo-icon">🎸</span>
            <span>G12 MUSIC</span>
        </div>
        <div class="nav-actions">
            <a href="admin.php" class="btn-nav">🔍 เช็คสถานะออเดอร์ / แอดมิน</a>
            <a href="admin_login.php" class="btn-nav">👤 เข้าสู่ระบบ</a>
            <a href="#" class="btn-nav btn-amber">🛒 ตะกร้า</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container">

        <?php if ($order_msg === 'success'): ?>
            <div class="alert-toast alert-success">🎉 ทำการสั่งซื้อสินค้าเรียบร้อยแล้ว! สต็อกตัดลบสำเร็จ</div>
        <?php elseif ($order_msg === 'out_of_stock'): ?>
            <div class="alert-toast alert-danger">❌ ขออภัย สินค้าชิ้นนี้หมดสต็อกแล้ว</div>
        <?php endif; ?>

        <div class="category-header">
            <h1 class="category-title">กีตาร์ไฟฟ้า</h1>
            <p class="category-desc">กีตาร์ไฟฟ้าสำหรับผู้เริ่มต้นและระดับมืออาชีพ</p>
        </div>

        <div class="product-grid">
            <?php if (!empty($products_list)): ?>
                <?php foreach ($products_list as $item):$p_id = $item[$id_col] ?? 0;
                    $p_name =$item[$name_col] ?? 'Electric Guitar';$p_price = isset($item[$price_col]) ? number_format($item[$price_col], 2) : '0.00';
                    $p_stock =$stock_col ? intval($item[$stock_col]) : 0;
                    $p_img =$item['image'] ?? $item['img'] ?? $item['p_img'] ?? '';
                    if (empty($p_img)) {$p_img = 'https://via.placeholder.com/300x300?text=Guitar'; }
                ?>
                    <div class="product-card">
                        <div class="img-box">
                            <img src="<?php echo htmlspecialchars($p_img); ?>" class="product-img" alt="Guitar" onerror="this.src='https://via.placeholder.com/300x300?text=Guitar'">
                        </div>
                        
                        <div class="product-info">
                            <div class="product-name" title="<?php echo htmlspecialchars($p_name); ?>"><?php echo htmlspecialchars($p_name); ?></div>
                            <div class="product-stock <?php echo ($p_stock <= 3) ? 'low' : ''; ?>">
                                คงเหลือ: <?php echo $p_stock; ?> ชิ้น
                            </div>
                        </div>

                        <div class="product-footer">
                            <div class="price-group">
                                <span class="price-label">ราคา</span>
                                <span class="price-amount">฿<?php echo $p_price; ?></span>
                            </div>

                            <form method="post" style="margin: 0;">
                                <input type="hidden" name="product_id" value="<?php echo $p_id; ?>">
                                <input type="hidden" name="buy_qty" value="1">
                                <button type="submit" name="buy_product" class="btn-buy" <?php echo ($p_stock <= 0) ? 'disabled' : ''; ?> title="ใส่ตะกร้า">
                                    🛒
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p style="grid-column: 1/-1; text-align: center; color: #94a3b8; padding: 40px;">ไม่พบรายการสินค้าในระบบ</p>
            <?php endif; ?>
        </div>
    </div>

</body>
</html>
