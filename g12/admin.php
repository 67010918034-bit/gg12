<?php
session_start();
require_once 'connectdb.php';

// ตรวจสอบชื่อตัวแปรเชื่อมต่อ DB
if (!isset($conn) && isset($db)) { $conn = $db; }
if (!isset($conn) && isset($con)) { $conn = $con; }

// ฟังก์ชันสำหรับดึง IP Address ของผู้ใช้งาน
function getClientIP() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        return $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }
}

// ---------------------------------------------------------------------
// 📌 ระบบเข้าสู่ระบบสำหรับ ADMIN
// ---------------------------------------------------------------------
$admin_error = '';
if (isset($_POST['action_admin_login'])) {
    $admin_user = trim($_POST['admin_username']);
    $admin_pass = trim($_POST['admin_password']);

    // ตรวจสอบรหัสผ่าน Admin (กำหนดตั้งต้นไว้ที่ admin / 1234)
    if ($admin_user === 'admin' && $admin_pass === '1234') {
        $_SESSION['admin_login'] = true;
        $_SESSION['admin_user']  = $admin_user;

        // บันทึก Audit Log เมื่อ Admin เข้าสู่ระบบ
        $ip_addr = mysqli_real_escape_string($conn, getClientIP());
        @mysqli_query($conn, "INSERT INTO audit_logs (user, event_type, ip_address, created_at) VALUES ('Admin: $admin_user', 'ADMIN_LOGIN', '$ip_addr', NOW())");

        header("Location: c.php?admin_panel=1");
        exit;
    } else {
        $admin_error = 'ชื่อผู้ใช้หรือรหัสผ่าน Admin ไม่ถูกต้อง!';
    }
}

// ออกจากระบบ Admin
if (isset($_GET['action']) && $_GET['action'] == 'admin_logout') {
    unset($_SESSION['admin_login']);
    unset($_SESSION['admin_user']);
    header("Location: c.php");
    exit;
}

// ---------------------------------------------------------------------
// 📌 ระบบจัดการสต็อกสินค้า (เฉพาะ Admin)
// ---------------------------------------------------------------------
$stock_msg = '';
if (isset($_SESSION['admin_login']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_stock'])) {
    $p_id      = intval($_POST['product_id']);
    $new_stock = intval($_POST['stock_qty']);

    $update_sql = "UPDATE products SET stock = $new_stock WHERE product_id = $p_id";
    if (mysqli_query($conn, $update_sql)) {
        $stock_msg = "อัปเดตสต็อกสินค้าเรียบร้อยแล้ว!";
    }
}

// ---------------------------------------------------------------------
// 📌 ดึงข้อมูลสำหรับ ADMIN DASHBOARD (สรุปยอดขาย + รายการสินค้า)
// ---------------------------------------------------------------------
$admin_products_data = [];
$grand_total_sold    = 0;
$grand_total_revenue = 0;

if (isset($_SESSION['admin_login'])) {
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

    $rs_summary = mysqli_query($conn, $sql_summary);
    if ($rs_summary) {
        while ($row = mysqli_fetch_assoc($rs_summary)) {
            $grand_total_sold    += $row['total_sold'];
            $grand_total_revenue += $row['total_revenue'];
            $admin_products_data[] = $row;
        }
    }
}

// ---------------------------------------------------------------------
// 📌 ระบบเข้าสู่ระบบด้วยอีเมล (ผู้ใช้ทั่วไป) + บันทึก Audit Log
// ---------------------------------------------------------------------
$auth_error = '';
if (isset($_POST['action_login_email'])) {
    $email    = filter_var(trim($_POST['login_email']), FILTER_SANITIZE_EMAIL);
    $fullname = trim($_POST['login_fullname']);
    $phone    = trim($_POST['login_phone']);
    $address  = trim($_POST['login_address']);

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['user'] = [
            'email'    => $email,
            'fullname' => !empty($fullname) ? $fullname : explode('@', $email)[0],
            'phone'    => $phone,
            'address'  => $address
        ];

        $user_val   = mysqli_real_escape_string($conn, $email);
        $event_type = 'LOGIN_SUCCESS';
        $ip_addr    = mysqli_real_escape_string($conn, getClientIP());

        $sql_log = "INSERT INTO audit_logs (user, event_type, ip_address, created_at) 
                    VALUES ('$user_val', '$event_type', '$ip_addr', NOW())";
        @mysqli_query($conn, $sql_log);

        header("Location: c.php");
        exit;
    } else {
        $auth_error = 'กรุณากรอกรูปแบบอีเมลให้ถูกต้อง';
    }
}

// ---------------------------------------------------------------------
// 📌 ออกจากระบบผู้ใช้ทั่วไป
// ---------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    if (isset($_SESSION['user']['email'])) {
        $user_val   = mysqli_real_escape_string($conn, $_SESSION['user']['email']);
        $event_type = 'LOGOUT';
        $ip_addr    = mysqli_real_escape_string($conn, getClientIP());

        $sql_log = "INSERT INTO audit_logs (user, event_type, ip_address, created_at) 
                    VALUES ('$user_val', '$event_type', '$ip_addr', NOW())";
        @mysqli_query($conn, $sql_log);
    }

    unset($_SESSION['user']);
    header("Location: c.php");
    exit;
}

// ---------------------------------------------------------------------
// 📌 ดึงข้อมูล Audit Logs จาก Database
// ---------------------------------------------------------------------
$audit_logs_list = [];
$sql_fetch_logs = "SELECT * FROM audit_logs ORDER BY id DESC LIMIT 100";
$rs_logs = @mysqli_query($conn, $sql_fetch_logs);
if ($rs_logs) {
    while ($row_log = mysqli_fetch_assoc($rs_logs)) {
        $audit_logs_list[] = $row_log;
    }
}

// ---------------------------------------------------------------------
// 📌 ระบบจัดการตะกร้าสินค้า (Session Cart)
// ---------------------------------------------------------------------
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// 1. เพิ่มสินค้าลงตะกร้า
if (isset($_GET['action']) && $_GET['action'] == 'add') {
    $p_id = intval($_GET['id']);
    
    $chk_sql = "SELECT stock, product_name FROM products WHERE product_id = $p_id";
    $chk_rs  = mysqli_query($conn, $chk_sql);
    if ($chk_rs && $prod_data = mysqli_fetch_assoc($chk_rs)) {
        $curr_in_cart = isset($_SESSION['cart'][$p_id]) ? $_SESSION['cart'][$p_id] : 0;
        if ($curr_in_cart + 1 <= $prod_data['stock']) {
            $_SESSION['cart'][$p_id] = $curr_in_cart + 1;
            header("Location: c.php?msg=added#cat-group");
            exit;
        } else {
            header("Location: c.php?msg=outofstock#cat-group");
            exit;
        }
    }
}

// 2. ปรับจำนวน / ลบสินค้า
if (isset($_POST['update_cart'])) {
    foreach ($_POST['qty'] as $p_id => $quantity) {
        $p_id = intval($p_id);
        $quantity = intval($quantity);
        if ($quantity > 0) {
            $_SESSION['cart'][$p_id] = $quantity;
        } else {
            unset($_SESSION['cart'][$p_id]);
        }
    }
    header("Location: c.php?open_cart=1");
    exit;
}

if (isset($_GET['action']) && $_GET['action'] == 'remove') {
    $p_id = intval($_GET['id']);
    unset($_SESSION['cart'][$p_id]);
    header("Location: c.php?open_cart=1");
    exit;
}

// 3. บันทึกคำสั่งซื้อลง DB และตัดสต็อก
$order_success_id = 0;
if (isset($_POST['submit_order'])) {
    $cust_name      = mysqli_real_escape_string($conn, $_POST['cust_name']);
    $cust_phone     = mysqli_real_escape_string($conn, $_POST['cust_phone']);
    $cust_address   = mysqli_real_escape_string($conn, $_POST['cust_address']);
    $payment_method = isset($_POST['payment_method']) ? mysqli_real_escape_string($conn, $_POST['payment_method']) : 'cod';

    if (!empty($_SESSION['cart']) && !empty($cust_name) && !empty($cust_phone)) {
        $cart_ids = array_keys($_SESSION['cart']);
        $ids_str  = implode(',', array_map('intval', $cart_ids));
        
        $total_amount = 0;
        $order_items  = [];

        if (!empty($ids_str)) {
            $sql_p = "SELECT product_id, price, stock FROM products WHERE product_id IN ($ids_str)";
            $rs_p  = mysqli_query($conn, $sql_p);
            while ($row = mysqli_fetch_assoc($rs_p)) {
                $pid   = $row['product_id'];
                $qty   = $_SESSION['cart'][$pid];
                $price = $row['price'];
                $total_amount += ($price * $qty);

                $order_items[] = [
                    'product_id' => $pid,
                    'price'      => $price,
                    'quantity'   => $qty
                ];
            }
        }

        $sql_ord = "INSERT INTO orders (customer_name, customer_phone, customer_address, payment_method, total_amount, created_at) 
                    VALUES ('$cust_name', '$cust_phone', '$cust_address', '$payment_method', '$total_amount', NOW())";
        
        $order_res = @mysqli_query($conn, $sql_ord);
        
        if (!$order_res) {
            $sql_ord = "INSERT INTO orders (customer_name, customer_phone, customer_address, total_amount, created_at) 
                        VALUES ('$cust_name', '$cust_phone', '$cust_address', '$total_amount', NOW())";
            $order_res = mysqli_query($conn, $sql_ord);
        }

        if ($order_res) {
            $order_id = mysqli_insert_id($conn);

            foreach ($order_items as $item) {
                $item_pid   = intval($item['product_id']);
                $item_price = floatval($item['price']);
                $item_qty   = intval($item['quantity']);

                @mysqli_query($conn, "INSERT INTO order_items (order_id, product_id, price, quantity) 
                                     VALUES ('$order_id', '$item_pid', '$item_price', '$item_qty')");

                @mysqli_query($conn, "UPDATE products SET stock = stock - $item_qty WHERE product_id = '$item_pid'");
            }

            $_SESSION['cart'] = [];
            $_SESSION['last_payment_method'] = $payment_method;
            $order_success_id = $order_id;
        }
    }
}

// ---------------------------------------------------------------------
// 📌 ระบบค้นหา/ตรวจสอบสินค้าที่สั่งซื้อ (Check Orders)
// ---------------------------------------------------------------------
$searched_orders = [];
$search_term_input = '';
$has_searched = false;

if (isset($_POST['action_search_order']) || isset($_GET['search_term'])) {
    $has_searched = true;
    $search_term_input = isset($_POST['search_term']) ? trim($_POST['search_term']) : trim($_GET['search_term']);
    $clean_term = mysqli_real_escape_string($conn, $search_term_input);

    if (!empty($clean_term)) {
        $sql_s = "SELECT * FROM orders WHERE customer_phone = '$clean_term' OR order_id = '$clean_term' ORDER BY order_id DESC";
        $rs_s = mysqli_query($conn, $sql_s);
        
        if ($rs_s && mysqli_num_rows($rs_s) > 0) {
            while ($ord_row = mysqli_fetch_assoc($rs_s)) {
                $o_id = $ord_row['order_id'];
                
                $sql_items = "SELECT oi.*, p.product_name, p.image_url 
                              FROM order_items oi 
                              LEFT JOIN products p ON oi.product_id = p.product_id 
                              WHERE oi.order_id = $o_id";
                $rs_items = mysqli_query($conn, $sql_items);
                
                $items = [];
                if ($rs_items) {
                    while ($i_row = mysqli_fetch_assoc($rs_items)) {
                        $items[] = $i_row;
                    }
                }
                $ord_row['items'] = $items;
                $searched_orders[] = $ord_row;
            }
        }
    }
}

// ---------------------------------------------------------------------
// 📌 ดึงข้อมูลสำหรับใบเสร็จรับเงิน (Receipt Data)
// ---------------------------------------------------------------------
$receipt_data = null;
$receipt_items = [];
$show_receipt_id = 0;

if (isset($_GET['receipt_id'])) {
    $show_receipt_id = intval($_GET['receipt_id']);
} elseif ($order_success_id > 0) {
    $show_receipt_id = $order_success_id;
}

if ($show_receipt_id > 0) {
    $sql_r = "SELECT * FROM orders WHERE order_id = $show_receipt_id";
    $rs_r = mysqli_query($conn, $sql_r);
    if ($rs_r && $receipt_data = mysqli_fetch_assoc($rs_r)) {
        $sql_ri = "SELECT oi.*, p.product_name 
                   FROM order_items oi 
                   LEFT JOIN products p ON oi.product_id = p.product_id 
                   WHERE oi.order_id = $show_receipt_id";
        $rs_ri = mysqli_query($conn, $sql_ri);
        if ($rs_ri) {
            while ($row_i = mysqli_fetch_assoc($rs_ri)) {
                $receipt_items[] = $row_i;
            }
        }
    }
}

// ---------------------------------------------------------------------
// 📌 ฟังก์ชันดึงรูปภาพจาก Database
// ---------------------------------------------------------------------
function getProductImageDirect($prod) {
    $possible_keys = ['image_url', 'product_img', 'image', 'picture', 'img', 'photo', 'product_image'];
    $img_value = '';

    foreach ($possible_keys as $key) {
        if (!empty($prod[$key])) {
            $img_value = trim($prod[$key]);
            break;
        }
    }

    if (empty($img_value)) {
        return 'https://images.unsplash.com/photo-1510915361894-db8b60106cb1?auto=format&fit=crop&w=400&q=80';
    }

    if (filter_var($img_value, FILTER_VALIDATE_URL) || strpos($img_value, '/') !== false) {
        return $img_value;
    }

    if (file_exists('img/' . $img_value)) {
        return 'img/' . $img_value;
    } elseif (file_exists('images/' . $img_value)) {
        return 'images/' . $img_value;
    } elseif (file_exists('uploads/' . $img_value)) {
        return 'uploads/' . $img_value;
    } elseif (file_exists($img_value)) {
        return $img_value;
    }

    return 'img/' . $img_value;
}

$categories = [
    1 => ['category_name' => 'กีตาร์ไฟฟ้า', 'description' => 'กีตาร์ไฟฟ้าสำหรับผู้เริ่มต้นและระดับมืออาชีพ'],
    2 => ['category_name' => 'กีตาร์โปร่ง', 'description' => 'กีตาร์โปร่งคุณภาพเยี่ยม เสียงนุ่มกังวาน'],
    3 => ['category_name' => 'กีตาร์คลาสสิก', 'description' => 'กีตาร์สายเอ็นไพเราะ นุ่มนวล'],
    4 => ['category_name' => 'แอมป์กีตาร์', 'description' => 'ตู้แอมป์ขยายเสียงคุณภาพสูง'],
    5 => ['category_name' => 'อุปกรณ์เสริม', 'description' => 'สาย กีตาร์ ปิ๊ก กระเป๋า และอุปกรณ์อื่นๆ']
];

$sql_prod = "SELECT * FROM products ORDER BY product_id ASC";
$rs_prod = mysqli_query($conn, $sql_prod);

$grouped_products = [];
if ($rs_prod && mysqli_num_rows($rs_prod) > 0) {
    while ($prod = mysqli_fetch_assoc($rs_prod)) {
        $cat_id = $prod['category_id'];
        $grouped_products[$cat_id][] = $prod;
    }
}

$total_cart_items = array_sum($_SESSION['cart']);
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GUITAR REA STORE - Premium Guitar Shop</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        :root {
            --primary: #0f172a;
            --primary-accent: #2563eb;
            --accent-orange: #f59e0b;
            --accent-orange-hover: #d97706;
            --bg-main: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --radius-lg: 16px;
            --radius-md: 10px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.04);
            --shadow-hover: 0 12px 28px -4px rgba(15, 23, 42, 0.12);
        }

        * { box-sizing: border-box; font-family: 'Prompt', sans-serif; margin: 0; padding: 0; }
        body { background-color: var(--bg-main); color: var(--text-main); }

        .top-navbar {
            background: var(--primary); color: #ffffff; padding: 12px 24px;
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 900; box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .brand-logo { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 18px; color: #ffffff; text-decoration: none; }
        .brand-logo span { color: var(--accent-orange); }
        .user-nav-box { display: flex; align-items: center; gap: 10px; font-size: 13px; }
        .user-badge { background: rgba(255,255,255,0.1); padding: 5px 12px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.15); }
        
        .btn-nav-action {
            background: rgba(255, 255, 255, 0.12); color: white; border: 1px solid rgba(255, 255, 255, 0.25);
            padding: 6px 14px; border-radius: 20px; cursor: pointer; font-size: 12px; font-weight: 500;
            transition: all 0.2s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px;
        }
        .btn-nav-action:hover { background: rgba(255, 255, 255, 0.25); transform: translateY(-1px); }

        .btn-auth-email {
            background: linear-gradient(135deg, var(--accent-orange), var(--accent-orange-hover));
            color: white; border: none; padding: 6px 16px; border-radius: 20px;
            cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s;
            box-shadow: 0 2px 8px rgba(245, 158, 11, 0.3);
        }
        .btn-auth-email:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(245, 158, 11, 0.5); }
        .btn-logout { color: #f87171; text-decoration: none; font-weight: 600; margin-left: 8px; }

        .hero-slider-wrapper {
            position: relative; width: 100%; height: 480px;
            background: #0f172a; overflow: hidden;
        }
        .hero-slide {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            opacity: 0; visibility: hidden; transition: opacity 0.8s ease-in-out, visibility 0.8s ease-in-out;
            display: flex; align-items: center; justify-content: center;
        }
        .hero-slide.active { opacity: 1; visibility: visible; }
        .hero-slide-bg {
            position: absolute; top: 0; left: 0; width: 100%; height: 100%;
            object-fit: cover; filter: brightness(0.42);
        }
        .hero-banner-content {
            position: relative; z-index: 5; color: #ffffff; text-align: center;
            padding: 20px; max-width: 800px;
        }
        .hero-tag {
            display: inline-block; background: rgba(245, 158, 11, 0.25); color: var(--accent-orange);
            padding: 4px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-bottom: 12px;
            border: 1px solid rgba(245, 158, 11, 0.5); backdrop-filter: blur(4px);
        }
        .hero-banner-content h1 { font-size: 38px; font-weight: 700; margin-bottom: 12px; color: #ffffff; line-height: 1.2; }
        .hero-banner-content h1 span { color: var(--accent-orange); }
        .hero-banner-content p { font-size: 15px; color: #cbd5e1; font-weight: 300; max-width: 600px; margin: 0 auto 24px; }
        .hero-actions { display: flex; gap: 12px; justify-content: center; flex-wrap: wrap; }
        
        .btn-hero-primary {
            background: var(--accent-orange); color: #fff; padding: 12px 28px; border-radius: 30px;
            text-decoration: none; font-weight: 600; font-size: 14px; transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.4); border: none; cursor: pointer;
        }
        .btn-hero-primary:hover { background: var(--accent-orange-hover); transform: translateY(-2px); }
        .btn-hero-secondary {
            background: rgba(255, 255, 255, 0.15); color: #fff; padding: 12px 28px; border-radius: 30px;
            text-decoration: none; font-weight: 600; font-size: 14px; border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s; backdrop-filter: blur(5px); cursor: pointer;
        }
        .btn-hero-secondary:hover { background: rgba(255, 255, 255, 0.25); transform: translateY(-2px); }

        .features-section { background: #ffffff; border-bottom: 1px solid var(--border-color); padding: 25px 20px; }
        .features-grid { max-width: 1180px; margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; }
        .feature-item { display: flex; align-items: center; gap: 14px; background: var(--bg-main); padding: 14px 18px; border-radius: var(--radius-md); border: 1px solid var(--border-color); }
        .feature-icon { font-size: 26px; }
        .feature-text h4 { font-size: 13px; font-weight: 600; color: var(--primary); margin-bottom: 2px; }
        .feature-text p { font-size: 11px; color: var(--text-muted); }

        .search-wrapper { max-width: 560px; margin: 30px auto 25px; padding: 0 20px; position: relative; z-index: 10; }
        .search-input {
            width: 100%; padding: 14px 22px; border-radius: 30px; border: 1px solid var(--border-color);
            font-size: 14px; outline: none; box-shadow: 0 8px 25px rgba(0,0,0,0.08); background: #ffffff;
        }

        .category-nav { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; max-width: 1000px; margin: 0 auto 35px; padding: 0 20px; }
        .cat-pill {
            background: #ffffff; border: 1px solid var(--border-color); color: var(--text-muted);
            padding: 8px 20px; border-radius: 25px; font-size: 13px; font-weight: 500; cursor: pointer; transition: all 0.2s;
        }
        .cat-pill:hover, .cat-pill.active { background: var(--primary); color: #ffffff; border-color: var(--primary); }

        .container { max-width: 1180px; margin: 0 auto; padding: 0 20px 60px; }
        .section-group { margin-bottom: 45px; }
        .section-header { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 18px; border-bottom: 2px solid var(--border-color); padding-bottom: 10px; }
        .section-title { font-size: 22px; font-weight: 700; color: var(--primary); }

        .product-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 22px; }
        .card {
            background: var(--card-bg); border-radius: var(--radius-lg); overflow: hidden;
            border: 1px solid var(--border-color); display: flex; flex-direction: column; box-shadow: var(--shadow-sm); transition: all 0.3s;
        }
        .card:hover { transform: translateY(-6px); box-shadow: var(--shadow-hover); }
        .card-img-box { position: relative; width: 100%; height: 210px; background: #fff; display: flex; align-items: center; justify-content: center; border-bottom: 1px solid #f1f5f9; }
        .card-img-box img { width: 100%; height: 100%; object-fit: contain; padding: 12px; }

        .badge-code { position: absolute; top: 10px; left: 10px; background: rgba(15, 23, 42, 0.75); color: #fff; font-size: 10px; padding: 3px 8px; border-radius: 6px; }
        .badge-stock { position: absolute; top: 10px; right: 10px; font-size: 10px; padding: 3px 8px; border-radius: 12px; color: #fff; }
        .badge-stock.in-stock { background-color: #10b981; }
        .badge-stock.out-stock { background-color: #ef4444; }

        .card-body { padding: 16px; display: flex; flex-direction: column; flex-grow: 1; }
        .card-title { font-size: 14px; font-weight: 600; height: 40px; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .card-desc { font-size: 12px; color: var(--text-muted); margin-bottom: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        .card-footer { display: flex; justify-content: space-between; align-items: center; margin-top: auto; padding-top: 10px; border-top: 1px dashed var(--border-color); }
        .price { font-size: 17px; font-weight: 700; color: var(--accent-orange-hover); }
        .btn-cart { background: var(--primary); color: #fff; border: none; padding: 8px 14px; border-radius: 8px; font-size: 12px; font-weight: 600; text-decoration: none; cursor: pointer; }
        .btn-cart:hover { background: var(--primary-accent); }
        .btn-cart.disabled { background: #cbd5e1; color: #94a3b8; cursor: not-allowed; }

        .site-footer { background: var(--primary); color: #94a3b8; padding: 50px 20px 100px; font-size: 13px; border-top: 3px solid var(--accent-orange); }
        .footer-grid { max-width: 1180px; margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: 30px; }
        .footer-col h4 { color: #ffffff; font-size: 15px; font-weight: 600; margin-bottom: 15px; border-bottom: 2px solid #334155; padding-bottom: 8px; display: inline-block; }
        .footer-col p { line-height: 1.6; margin-bottom: 10px; }

        .floating-cart-btn {
            position: fixed; bottom: 25px; right: 25px; background: linear-gradient(135deg, var(--accent-orange), var(--accent-orange-hover));
            color: #fff; border: none; padding: 14px 24px; border-radius: 30px; font-size: 14px; font-weight: 600;
            box-shadow: 0 6px 20px rgba(245, 158, 11, 0.4); cursor: pointer; z-index: 999; display: flex; align-items: center; gap: 8px;
        }
        .cart-badge { background-color: #ef4444; color: white; border-radius: 50%; padding: 2px 7px; font-size: 11px; }

        .floating-chat-btn {
            position: fixed; bottom: 25px; left: 25px; background: var(--primary); color: #fff; border: none;
            padding: 14px 22px; border-radius: 30px; font-size: 14px; font-weight: 600;
            box-shadow: 0 6px 20px rgba(15, 23, 42, 0.3); cursor: pointer; z-index: 999;
        }

        .chatbot-window {
            display: none; position: fixed; bottom: 85px; left: 25px; width: 420px; height: 680px; max-height: calc(100vh - 120px); max-width: calc(100vw - 50px);
            background: #fff; border-radius: var(--radius-lg); box-shadow: 0 12px 35px rgba(0,0,0,0.25);
            z-index: 1000; flex-direction: column; overflow: hidden; border: 1px solid var(--border-color);
        }
        .chatbot-window.active { display: flex; }
        .chatbot-header { background: var(--primary); color: #fff; padding: 12px 18px; font-size: 14px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .close-chat-btn { background: none; border: none; color: #fff; font-size: 20px; cursor: pointer; }

        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(5px); z-index: 1000;
            justify-content: center; align-items: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: white; width: 90%; max-width: 600px; max-height: 85vh;
            border-radius: var(--radius-lg); padding: 24px; overflow-y: auto; position: relative; box-shadow: 0 20px 40px rgba(0,0,0,0.2);
        }
        .modal-content.large-modal { max-width: 900px; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 12px; margin-bottom: 18px; }
        .close-btn { background: none; border: none; font-size: 22px; cursor: pointer; color: var(--text-muted); }

        .cart-table { width: 100%; border-collapse: collapse; margin-bottom: 15px; font-size: 13px; }
        .cart-table th, .cart-table td { padding: 10px; text-align: left; border-bottom: 1px solid #f1f5f9; }
        .qty-input { width: 50px; text-align: center; padding: 4px; border: 1px solid var(--border-color); border-radius: 6px; }

        .checkout-form { background: #f8fafc; padding: 18px; border-radius: var(--radius-md); margin-top: 15px; border: 1px solid var(--border-color); }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 6px; color: var(--text-main); }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%; padding: 10px; border: 1px solid var(--border-color); border-radius: 8px; font-size: 13px; outline: none; background: #fff;
        }

        .btn-submit-order {
            background: #10b981; color: white; border: none; padding: 12px; border-radius: 8px;
            width: 100%; font-size: 14px; font-weight: 600; cursor: pointer; margin-top: 10px; transition: background 0.2s;
        }
        .btn-submit-order:hover { background: #059669; }

        .badge-event { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; display: inline-block; }
        .badge-event.login { background: #dcfce7; color: #166534; }
        .badge-event.logout { background: #fee2e2; color: #991b1b; }

        @media (max-width: 992px) { 
            .product-grid { grid-template-columns: repeat(3, 1fr); } 
            .features-grid { grid-template-columns: repeat(2, 1fr); }
            .hero-slider-wrapper { height: 400px; }
        }
        @media (max-width: 768px) { 
            .product-grid { grid-template-columns: repeat(2, 1fr); } 
            .hero-slider-wrapper { height: 350px; }
        }
        @media (max-width: 480px) { 
            .product-grid { grid-template-columns: repeat(1, 1fr); } 
            .features-grid { grid-template-columns: 1fr; }
            .chatbot-window { bottom: 0; left: 0; width: 100vw; height: 100vh; max-width: 100vw; max-height: 100vh; border-radius: 0; }
        }
    </style>
</head>
<body>

    <!-- 📌 TOP NAVBAR -->
    <nav class="top-navbar">
        <a href="c.php" class="brand-logo">🎸 GUITAR REA <span>STORE</span></a>
        <div class="user-nav-box">
            <button class="btn-nav-action" onclick="openOrderSearchModal()">📋 ตรวจสอบคำสั่งซื้อ</button>
            <button class="btn-nav-action" onclick="openAuditLogModal()" style="background: rgba(245, 158, 11, 0.2); border-color: rgba(245, 158, 11, 0.5); color: #fef08a;">📜 ดู Log ระบบ</button>

            <!-- 🛡️ ส่วนควบคุม ADMIN LOGIN / DASHBOARD BUTTON -->
            <?php if (isset($_SESSION['admin_login'])) { ?>
                <button class="btn-nav-action" onclick="openAdminDashboardModal()" style="background: #f59e0b; color: #0f172a; font-weight: 700;">
                    🛡️ แผงควบคุม Admin
                </button>
                <a href="c.php?action=admin_logout" class="btn-logout" style="color: #fca5a5;" onclick="return confirm('ออกจากระบบ Admin?')">ออกจาก Admin</a>
            <?php } else { ?>
                <button class="btn-nav-action" onclick="openAdminLoginModal()" style="border-color: #f59e0b; color: #fef08a;">
                    🔐 Admin Login
                </button>
            <?php } ?>

            <?php if (isset($_SESSION['user'])) { ?>
                <div class="user-badge">
                    📧 <b><?php echo htmlspecialchars($_SESSION['user']['email']); ?></b>
                </div>
                <a href="c.php?action=logout" class="btn-logout" onclick="return confirm('ต้องการออกจากระบบหรือไม่?')">ออกจากระบบ</a>
            <?php } else { ?>
                <button class="btn-auth-email" onclick="openAuthModal()">✉️ เข้าสู่ระบบ</button>
            <?php } ?>
        </div>
    </nav>

    <!-- 🌄 HERO HOMEPAGE SLIDER SECTION -->
    <section class="hero-slider-wrapper">
        <div class="hero-slide active">
            <img src="https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?auto=format&fit=crop&w=1600&q=80" alt="Guitar Rea Store Banner 1" class="hero-slide-bg">
            <div class="hero-banner-content">
                <span class="hero-tag">🔥 OFFICIAL GUITAR STORE</span>
                <h1>GUITAR REA <span>STORE</span></h1>
                <p>ศูนย์รวมกีตาร์ไฟฟ้า โปร่ง คลาสสิก ตู้แอมป์ และอุปกรณ์ดนตรีคุณภาพพรีเมียม การันตีของแท้พร้อมบริการหลังการขาย</p>
                <div class="hero-actions">
                    <a href="#cat-group" class="btn-hero-primary">🛍️ เลือกซื้อสินค้าทันที</a>
                    <button class="btn-hero-secondary" onclick="openOrderSearchModal()">📋 ตรวจสอบสินค้าที่สั่ง</button>
                </div>
            </div>
        </div>

        <div class="hero-slide">
            <img src="https://images.unsplash.com/photo-1564186763535-ebb21ef5277f?auto=format&fit=crop&w=1600&q=80" alt="Guitar Rea Store Banner 2" class="hero-slide-bg">
            <div class="hero-banner-content">
                <span class="hero-tag">🎸 NEW ARRIVALS</span>
                <h1>สินค้ามาใหม่ <span>โปรโมชั่นพิเศษ</span></h1>
                <p>สัมผัสพลังเสียงระดับสตูดิโอด้วยกีตาร์คัดสรรเกรดพรีเมียม พร้อมส่วนลดสูงสุดในรอบปี</p>
                <div class="hero-actions">
                    <a href="#cat-group" class="btn-hero-primary">🔥 ดูโปรโมชั่นพิเศษ</a>
                </div>
            </div>
        </div>
    </section>

    <!-- 🌟 FEATURES SECTION -->
    <section class="features-section">
        <div class="features-grid">
            <div class="feature-item">
                <span class="feature-icon">🚚</span>
                <div class="feature-text">
                    <h4>จัดส่งฟรีทั่วไทย</h4>
                    <p>สั่งซื้อครบ 1,500 บาทขึ้นไป</p>
                </div>
            </div>
            <div class="feature-item">
                <span class="feature-icon">🛡</span>
                <div class="feature-text">
                    <h4>รับประกันสินค้าแท้</h4>
                    <p>รับประกันศูนย์ไทย 100%</p>
                </div>
            </div>
            <div class="feature-item">
                <span class="feature-icon">💳</span>
                <div class="feature-text">
                    <h4>ชำระเงินสะดวก</h4>
                    <p>โอนเงิน / QR / เก็บเงินปลายทาง</p>
                </div>
            </div>
            <div class="feature-item">
                <span class="feature-icon">🎧</span>
                <div class="feature-text">
                    <h4>บริการด้วยใจ</h4>
                    <p>ปรึกษาทีมงานมืออาชีพได้ 24 ชม.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 🔍 SEARCH & CATEGORY NAV -->
    <div class="search-wrapper">
        <input type="text" id="searchInput" class="search-input" placeholder="🔍 ค้นหากีตาร์, อุปกรณ์ดนตรี..." onkeyup="filterProducts()">
    </div>

    <div class="category-nav" id="cat-group">
        <button class="cat-pill active" onclick="filterCategory('all', this)">ทั้งหมด</button>
        <?php foreach ($categories as $cid => $cinfo) { ?>
            <button class="cat-pill" onclick="filterCategory(<?php echo $cid; ?>, this)"><?php echo htmlspecialchars($cinfo['category_name']); ?></button>
        <?php } ?>
    </div>

    <!-- 🛒 MAIN PRODUCT CONTAINER -->
    <div class="container">
        <?php if (!empty($grouped_products)) { ?>
            <?php foreach ($grouped_products as $cat_id => $prods) { 
                $cat_title = isset($categories[$cat_id]) ? $categories[$cat_id]['category_name'] : 'สินค้าทั่วไป';
            ?>
                <div class="section-group product-section-group" data-catid="<?php echo $cat_id; ?>">
                    <div class="section-header">
                        <h2 class="section-title">🎸 <?php echo htmlspecialchars($cat_title); ?></h2>
                    </div>
                    <div class="product-grid">
                        <?php foreach ($prods as $prod) { 
                            $p_id    = $prod['product_id'];
                            $p_name  = $prod['product_name'];
                            $p_desc  = $prod['description'] ?? '';
                            $p_price = number_format($prod['price'], 2);
                            $p_stock = intval($prod['stock']);
                            $p_img   = getProductImageDirect($prod);
                        ?>
                            <div class="card product-card" data-title="<?php echo htmlspecialchars(mb_strtolower($p_name)); ?>">
                                <div class="card-img-box">
                                    <span class="badge-code">#<?php echo $p_id; ?></span>
                                    <?php if ($p_stock > 0) { ?>
                                        <span class="badge-stock in-stock">มีสินค้า (<?php echo $p_stock; ?>)</span>
                                    <?php } else { ?>
                                        <span class="badge-stock out-stock">สินค้าหมด</span>
                                    <?php } ?>
                                    <img src="<?php echo htmlspecialchars($p_img); ?>" alt="<?php echo htmlspecialchars($p_name); ?>" loading="lazy">
                                </div>
                                <div class="card-body">
                                    <h3 class="card-title"><?php echo htmlspecialchars($p_name); ?></h3>
                                    <p class="card-desc"><?php echo htmlspecialchars($p_desc); ?></p>
                                    <div class="card-footer">
                                        <span class="price">฿<?php echo $p_price; ?></span>
                                        <?php if ($p_stock > 0) { ?>
                                            <a href="c.php?action=add&id=<?php echo $p_id; ?>" class="btn-cart">🛒 ใส่ตะกร้า</a>
                                        <?php } else { ?>
                                            <button class="btn-cart disabled" disabled>สินค้าหมด</button>
                                        <?php } ?>
                                    </div>
                                </div>
                            </div>
                        <?php } ?>
                    </div>
                </div>
            <?php } ?>
        <?php } else { ?>
            <p style="text-align: center; color: var(--text-muted); margin: 40px 0;">ไม่พบข้อมูลสินค้าในระบบ</p>
        <?php } ?>
    </div>

    <!-- 🤖 CHATBOT WINDOW & BUTTON -->
    <button class="floating-chat-btn" onclick="toggleChatbot()">🤖 ผู้ช่วย AI</button>

    <div class="chatbot-window" id="chatbotWindow">
        <div class="chatbot-header">
            <span>🤖 ผู้ช่วยตอบคำถาม Guitar Rea</span>
            <button class="close-chat-btn" onclick="toggleChatbot()">&times;</button>
        </div>
        <iframe
            src="https://udify.app/chatbot/MhX5u5mK0EmvvK20"
            style="width: 100%; height: 100%; border: none;"
            allow="microphone;clipboard-write">
        </iframe>
    </div>

    <!-- 🛒 FLOATING CART BUTTON -->
    <button class="floating-cart-btn" onclick="openCartModal()">
        🛒 ตะกร้าสินค้า <span class="cart-badge"><?php echo $total_cart_items; ?></span>
    </button>

    <!-- 🛒 CART MODAL -->
    <div class="modal-overlay" id="cartModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🛒 ตะกร้าสินค้าของคุณ</h3>
                <button class="close-btn" onclick="closeCartModal()">&times;</button>
            </div>
            
            <?php if (!empty($_SESSION['cart'])) { 
                $cart_ids = array_keys($_SESSION['cart']);
                $ids_str  = implode(',', array_map('intval', $cart_ids));
                $sql_c    = "SELECT * FROM products WHERE product_id IN ($ids_str)";
                $rs_c     = mysqli_query($conn, $sql_c);
                $grand_total = 0;
            ?>
                <form action="c.php" method="POST">
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th>สินค้า</th>
                                <th>ราคา</th>
                                <th>จำนวน</th>
                                <th>รวม</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($item = mysqli_fetch_assoc($rs_c)) { 
                                $pid   = $item['product_id'];
                                $qty   = $_SESSION['cart'][$pid];
                                $sub   = $item['price'] * $qty;
                                $grand_total += $sub;
                            ?>
                                <tr>
                                    <td><b><?php echo htmlspecialchars($item['product_name']); ?></b></td>
                                    <td>฿<?php echo number_format($item['price'], 2); ?></td>
                                    <td>
                                        <input type="number" name="qty[<?php echo $pid; ?>]" value="<?php echo $qty; ?>" min="1" max="<?php echo $item['stock']; ?>" class="qty-input">
                                    </td>
                                    <td>฿<?php echo number_format($sub, 2); ?></td>
                                    <td><a href="c.php?action=remove&id=<?php echo $pid; ?>" style="color: #ef4444; text-decoration: none;">❌</a></td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                        <button type="submit" name="update_cart" style="background: var(--text-muted); color: white; border: none; padding: 6px 12px; border-radius: 6px; cursor: pointer; font-size: 12px;">🔄 อัปเดตจำนวน</button>
                        <span style="font-size: 16px; font-weight: 700; color: var(--accent-orange-hover);">ราคารวม: ฿<?php echo number_format($grand_total, 2); ?></span>
                    </div>
                </form>

                <form action="c.php" method="POST" class="checkout-form">
                    <h4 style="margin-bottom: 12px; font-size: 14px; color: var(--primary);">📝 กรอกข้อมูลจัดส่ง</h4>
                    <div class="form-group">
                        <label>ชื่อ-นามสกุล ผู้รับ:</label>
                        <input type="text" name="cust_name" required value="<?php echo htmlspecialchars($_SESSION['user']['fullname'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>เบอร์โทรศัพท์:</label>
                        <input type="text" name="cust_phone" required value="<?php echo htmlspecialchars($_SESSION['user']['phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>ที่อยู่จัดส่ง:</label>
                        <textarea name="cust_address" rows="3" required><?php echo htmlspecialchars($_SESSION['user']['address'] ?? ''); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>ช่องทางการชำระเงิน:</label>
                        <select name="payment_method">
                            <option value="cod">เก็บเงินปลายทาง (COD)</option>
                            <option value="transfer">โอนเงินผ่านธนาคาร / QR Code</option>
                        </select>
                    </div>
                    <button type="submit" name="submit_order" class="btn-submit-order">✅ ยืนยันการสั่งซื้อ</button>
                </form>
            <?php } else { ?>
                <p style="text-align: center; color: var(--text-muted); padding: 30px 0;">ไม่มีสินค้าในตะกร้า</p>
            <?php } ?>
        </div>
    </div>

    <!-- 🔑 USER LOGIN MODAL -->
    <div class="modal-overlay" id="authModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>✉️ เข้าสู่ระบบ / ลงทะเบียนผู้ใช้</h3>
                <button class="close-btn" onclick="closeAuthModal()">&times;</button>
            </div>
            <?php if (!empty($auth_error)) { ?>
                <div style="background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 6px; font-size: 12px; margin-bottom: 12px;">
                    <?php echo $auth_error; ?>
                </div>
            <?php } ?>
            <form action="c.php" method="POST">
                <div class="form-group">
                    <label>อีเมล (Required):</label>
                    <input type="email" name="login_email" required placeholder="example@email.com">
                </div>
                <div class="form-group">
                    <label>ชื่อ-นามสกุล:</label>
                    <input type="text" name="login_fullname" placeholder="สมชาย ใจดี">
                </div>
                <div class="form-group">
                    <label>เบอร์โทรศัพท์:</label>
                    <input type="text" name="login_phone" placeholder="0812345678">
                </div>
                <div class="form-group">
                    <label>ที่อยู่จัดส่ง:</label>
                    <textarea name="login_address" rows="2" placeholder="ที่อยู่ของคุณ..."></textarea>
                </div>
                <button type="submit" name="action_login_email" class="btn-submit-order">เข้าสู่ระบบ</button>
            </form>
        </div>
    </div>

    <!-- 🔐 ADMIN LOGIN MODAL -->
    <div class="modal-overlay" id="adminLoginModal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3>🔐 เข้าสู่ระบบผู้ดูแลระบบ (Admin)</h3>
                <button class="close-btn" onclick="closeAdminLoginModal()">&times;</button>
            </div>
            
            <?php if (!empty($admin_error)) { ?>
                <div style="background: #fee2e2; color: #991b1b; padding: 10px; border-radius: 6px; font-size: 12px; margin-bottom: 12px;">
                    <?php echo $admin_error; ?>
                </div>
            <?php } ?>

            <form action="c.php" method="POST">
                <div class="form-group">
                    <label>ชื่อผู้ใช้งาน (Username):</label>
                    <input type="text" name="admin_username" required placeholder="admin">
                </div>
                <div class="form-group">
                    <label>รหัสผ่าน (Password):</label>
                    <input type="password" name="admin_password" required placeholder="••••••••">
                </div>
                <button type="submit" name="action_admin_login" class="btn-submit-order" style="background: #f59e0b; color: #0f172a; font-weight: bold;">
                    เข้าสู่ระบบ Admin
                </button>
            </form>
        </div>
    </div>

    <!-- 🛡️️ ADMIN DASHBOARD MODAL -->
    <?php if (isset($_SESSION['admin_login'])) { ?>
    <div class="modal-overlay" id="adminDashboardModal">
        <div class="modal-content large-modal">
            <div class="modal-header">
                <h3>🛡️ ระบบจัดการหลังร้าน (Admin Dashboard)</h3>
                <button class="close-btn" onclick="closeAdminDashboardModal()">&times;</button>
            </div>

            <?php if (!empty($stock_msg)) { ?>
                <div style="background: #dcfce7; color: #166534; padding: 10px; border-radius: 6px; font-size: 13px; margin-bottom: 15px;">
                    ✅ <?php echo $stock_msg; ?>
                </div>
            <?php } ?>

            <!-- สรุปภาพรวมยอดขาย -->
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 20px;">
                <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px; border-radius: 10px;">
                    <p style="font-size: 12px; color: #166534;">ยอดขายรวมทั้งหมด</p>
                    <h3 style="font-size: 20px; color: #15803d; font-weight: bold;">฿<?php echo number_format($grand_total_revenue, 2); ?></h3>
                </div>
                <div style="background: #fffbeb; border: 1px solid #fef3c7; padding: 15px; border-radius: 10px;">
                    <p style="font-size: 12px; color: #92400e;">สินค้าขายได้แล้ว</p>
                    <h3 style="font-size: 20px; color: #b45309; font-weight: bold;"><?php echo number_format($grand_total_sold); ?> ชิ้น</h3>
                </div>
                <div style="background: #f8fafc; border: 1px solid #e2e8f0; padding: 15px; border-radius: 10px;">
                    <p style="font-size: 12px; color: #475569;">รายการสินค้าทั้งหมด</p>
                    <h3 style="font-size: 20px; color: #1e293b; font-weight: bold;"><?php echo count($admin_products_data); ?> รายการ</h3>
                </div>
            </div>

            <!-- ตารางรายการสินค้า + ปรับสต็อก -->
            <h4 style="margin-bottom: 10px; font-size: 14px; color: #0f172a;">📦 รายการสินค้า ยอดขาย และปรับสต็อก</h4>
            <table class="cart-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ชื่อสินค้า</th>
                        <th>ราคา</th>
                        <th>ขายแล้ว</th>
                        <th>ยอดขายรวม</th>
                        <th>คงเหลือ</th>
                        <th style="text-align: center;">แก้ไขสต็อก</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($admin_products_data) > 0) { ?>
                        <?php foreach ($admin_products_data as $ap) { ?>
                            <tr>
                                <td>#<?php echo $ap['product_id']; ?></td>
                                <td><b><?php echo htmlspecialchars($ap['product_name']); ?></b></td>
                                <td>฿<?php echo number_format($ap['price'], 2); ?></td>
                                <td><?php echo number_format($ap['total_sold']); ?></td>
                                <td style="color: #16a34a; font-weight: bold;">฿<?php echo number_format($ap['total_revenue'], 2); ?></td>
                                <td>
                                    <span style="padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; <?php echo $ap['stock'] < 5 ? 'background: #fee2e2; color: #991b1b;' : 'background: #f1f5f9; color: #334155;'; ?>">
                                        <?php echo number_format($ap['stock']); ?>
                                    </span>
                                </td>
                                <td>
                                    <form action="c.php" method="POST" style="display: flex; gap: 5px; justify-content: center;">
                                        <input type="hidden" name="product_id" value="<?php echo $ap['product_id']; ?>">
                                        <input type="number" name="stock_qty" value="<?php echo $ap['stock']; ?>" min="0" style="width: 60px; padding: 3px; text-align: center; border: 1px solid #ccc; border-radius: 4px;">
                                        <button type="submit" name="update_stock" style="background: #0f172a; color: white; border: none; padding: 4px 10px; border-radius: 4px; font-size: 11px; cursor: pointer;">
                                            บันทึก
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: var(--text-muted);">ไม่พบข้อมูลสินค้า</td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php } ?>

    <!-- 📋 ORDER SEARCH MODAL -->
    <div class="modal-overlay" id="orderSearchModal">
        <div class="modal-content large-modal">
            <div class="modal-header">
                <h3>📋 ตรวจสอบรายการสั่งซื้อ</h3>
                <button class="close-btn" onclick="closeOrderSearchModal()">&times;</button>
            </div>
            <form action="c.php" method="POST" style="display: flex; gap: 10px; margin-bottom: 20px;">
                <input type="text" name="search_term" required placeholder="ป้อนเบอร์โทรศัพท์ หรือ รหัสคำสั่งซื้อ..." value="<?php echo htmlspecialchars($search_term_input); ?>" style="flex-grow: 1; padding: 10px; border: 1px solid var(--border-color); border-radius: 8px;">
                <button type="submit" name="action_search_order" style="background: var(--primary); color: white; border: none; padding: 10px 20px; border-radius: 8px; cursor: pointer;">🔍 ค้นหา</button>
            </form>

            <?php if ($has_searched) { ?>
                <?php if (!empty($searched_orders)) { ?>
                    <?php foreach ($searched_orders as $s_ord) { ?>
                        <div style="border: 1px solid var(--border-color); border-radius: 8px; padding: 15px; margin-bottom: 15px; background: #fafafa;">
                            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 10px; font-size: 13px;">
                                <span><b>คำสั่งซื้อ #<?php echo $s_ord['order_id']; ?></b></span>
                                <span style="color: var(--text-muted);"><?php echo $s_ord['created_at']; ?></span>
                            </div>
                            <p style="font-size: 13px; margin-bottom: 4px;">👤 <b>ผู้สั่งซื้อ:</b> <?php echo htmlspecialchars($s_ord['customer_name']); ?> (<?php echo htmlspecialchars($s_ord['customer_phone']); ?>)</p>
                            <p style="font-size: 13px; margin-bottom: 10px;">📍 <b>ที่อยู่:</b> <?php echo htmlspecialchars($s_ord['customer_address']); ?></p>
                            
                            <table class="cart-table">
                                <thead>
                                    <tr>
                                        <th>สินค้า</th>
                                        <th>ราคา</th>
                                        <th>จำนวน</th>
                                        <th>รวม</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($s_ord['items'] as $s_item) { ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($s_item['product_name']); ?></td>
                                            <td>฿<?php echo number_format($s_item['price'], 2); ?></td>
                                            <td><?php echo $s_item['quantity']; ?></td>
                                            <td>฿<?php echo number_format($s_item['price'] * $s_item['quantity'], 2); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 10px;">
                                <a href="c.php?receipt_id=<?php echo $s_ord['order_id']; ?>" style="color: var(--primary-accent); text-decoration: none; font-size: 12px; font-weight: 600;">🧾 ดูใบเสร็จ</a>
                                <span style="font-weight: 700; color: var(--accent-orange-hover);">ยอดรวมทั้งสิ้น: ฿<?php echo number_format($s_ord['total_amount'], 2); ?></span>
                            </div>
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 20px;">ไม่พบรายการสั่งซื้อสำหรับข้อมูลที่คุณค้นหา</p>
                <?php } ?>
            <?php } ?>
        </div>
    </div>

    <!-- 📜 AUDIT LOG MODAL -->
    <div class="modal-overlay" id="auditLogModal">
        <div class="modal-content large-modal">
            <div class="modal-header">
                <h3>📜 Audit Logs (ประวัติการใช้งานระบบ)</h3>
                <button class="close-btn" onclick="closeAuditLogModal()">&times;</button>
            </div>
            <table class="cart-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ผู้ใช้งาน (User)</th>
                        <th>เหตุการณ์ (Event)</th>
                        <th>IP Address</th>
                        <th>เวลา</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($audit_logs_list)) { ?>
                        <?php foreach ($audit_logs_list as $log) { ?>
                            <tr>
                                <td>#<?php echo $log['id']; ?></td>
                                <td><?php echo htmlspecialchars($log['user']); ?></td>
                                <td>
                                    <?php if (strpos($log['event_type'], 'LOGIN') !== false) { ?>
                                        <span class="badge-event login"><?php echo $log['event_type']; ?></span>
                                    <?php } else { ?>
                                        <span class="badge-event logout"><?php echo $log['event_type']; ?></span>
                                    <?php } ?>
                                </td>
                                <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                <td><?php echo $log['created_at']; ?></td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: var(--text-muted);">ยังไม่มีข้อมูลประวัติการใช้งาน</td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 🧾 RECEIPT MODAL -->
    <?php if ($receipt_data) { ?>
        <div class="modal-overlay active" id="receiptModal">
            <div class="modal-content">
                <div class="modal-header">
                    <h3>🧾 ใบเสร็จรับเงิน (Receipt)</h3>
                    <button class="close-btn" onclick="document.getElementById('receiptModal').classList.remove('active')">&times;</button>
                </div>
                <div style="text-align: center; margin-bottom: 20px;">
                    <h2 style="color: var(--primary);">GUITAR REA STORE</h2>
                    <p style="font-size: 12px; color: var(--text-muted);">ใบเสร็จรับเงินอย่างย่อ / คำสั่งซื้อ #<?php echo $receipt_data['order_id']; ?></p>
                    <p style="font-size: 11px; color: var(--text-muted);"><?php echo $receipt_data['created_at']; ?></p>
                </div>
                <div style="font-size: 12px; margin-bottom: 15px; background: #f8fafc; padding: 10px; border-radius: 6px;">
                    <p><b>ลูกค้า:</b> <?php echo htmlspecialchars($receipt_data['customer_name']); ?></p>
                    <p><b>เบอร์โทร:</b> <?php echo htmlspecialchars($receipt_data['customer_phone']); ?></p>
                    <p><b>ที่อยู่จัดส่ง:</b> <?php echo htmlspecialchars($receipt_data['customer_address']); ?></p>
                </div>
                <table class="cart-table">
                    <thead>
                        <tr>
                            <th>รายการ</th>
                            <th>จำนวน</th>
                            <th>ราคา/หน่วย</th>
                            <th>รวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($receipt_items as $r_item) { ?>
                            <tr>
                                <td><?php echo htmlspecialchars($r_item['product_name']); ?></td>
                                <td><?php echo $r_item['quantity']; ?></td>
                                <td>฿<?php echo number_format($r_item['price'], 2); ?></td>
                                <td>฿<?php echo number_format($r_item['price'] * $r_item['quantity'], 2); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
                <div style="text-align: right; font-size: 16px; font-weight: 700; color: var(--accent-orange-hover); margin-top: 15px;">
                    ยอดชำระสุทธิ: ฿<?php echo number_format($receipt_data['total_amount'], 2); ?>
                </div>
                <button onclick="window.print()" style="width: 100%; padding: 10px; background: var(--primary); color: white; border: none; border-radius: 8px; font-weight: 600; margin-top: 15px; cursor: pointer;">🖨️ พิมพ์ใบเสร็จ</button>
            </div>
        </div>
    <?php } ?>

    <!-- 🌐 FOOTER -->
    <footer class="site-footer">
        <div class="footer-grid">
            <div class="footer-col">
                <h4>GUITAR REA STORE</h4>
                <p>ร้านจำหน่ายกีตาร์และอุปกรณ์ดนตรีครบวงจร คัดสรรสินค้าคุณภาพเพื่อมิวสิเชียลทุกคน</p>
            </div>
            <div class="footer-col">
                <h4>หมวดหมู่สินค้า</h4>
                <p>กีตาร์ไฟฟ้า / กีตาร์โปร่ง</p>
                <p>กีตาร์คลาสสิก / แอมป์กีตาร์</p>
            </div>
            <div class="footer-col">
                <h4>การบริการลูกค้า</h4>
                <p>ตรวจสอบคำสั่งซื้อ</p>
                <p>การรับประกันสินค้า</p>
            </div>
            <div class="footer-col">
                <h4>ติดต่อเรา</h4>
                <p>📞 โทร: 02-123-4567</p>
                <p>📧 อีเมล: contact@guitarrea.com</p>
            </div>
        </div>
    </footer>

    <!-- 📜 JAVASCRIPT -->
    <script>
        // Hero Slider
        let currentSlide = 0;
        const slides = document.querySelectorAll('.hero-slide');
        
        function nextSlide() {
            if (slides.length <= 1) return;
            slides[currentSlide].classList.remove('active');
            currentSlide = (currentSlide + 1) % slides.length;
            slides[currentSlide].classList.add('active');
        }
        setInterval(nextSlide, 5000);

        // Chatbot Toggle
        function toggleChatbot() {
            const bot = document.getElementById('chatbotWindow');
            bot.classList.toggle('active');
        }

        // Modals Toggle
        function openCartModal() { document.getElementById('cartModal').classList.add('active'); }
        function closeCartModal() { document.getElementById('cartModal').classList.remove('active'); }

        function openAuthModal() { document.getElementById('authModal').classList.add('active'); }
        function closeAuthModal() { document.getElementById('authModal').classList.remove('active'); }

        function openAdminLoginModal() { document.getElementById('adminLoginModal').classList.add('active'); }
        function closeAdminLoginModal() { document.getElementById('adminLoginModal').classList.remove('active'); }

        function openAdminDashboardModal() { document.getElementById('adminDashboardModal').classList.add('active'); }
        function closeAdminDashboardModal() { document.getElementById('adminDashboardModal').classList.remove('active'); }

        function openOrderSearchModal() { document.getElementById('orderSearchModal').classList.add('active'); }
        function closeOrderSearchModal() { document.getElementById('orderSearchModal').classList.remove('active'); }

        function openAuditLogModal() { document.getElementById('auditLogModal').classList.add('active'); }
        function closeAuditLogModal() { document.getElementById('auditLogModal').classList.remove('active'); }

        // Category Filter
        function filterCategory(catId, btn) {
            document.querySelectorAll('.cat-pill').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const groups = document.querySelectorAll('.product-section-group');
            groups.forEach(group => {
                if (catId === 'all' || group.getAttribute('data-catid') == catId) {
                    group.style.display = 'block';
                } else {
                    group.style.display = 'none';
                }
            });
        }

        // Search Filter
        function filterProducts() {
            const term = document.getElementById('searchInput').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.product-card');

            cards.forEach(card => {
                const title = card.getAttribute('data-title');
                if (title.includes(term)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Auto Open URL Parameters Check
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.has('open_cart')) { openCartModal(); }
        if (urlParams.has('admin_panel')) { openAdminDashboardModal(); }
    </script>
</body>
</html>
