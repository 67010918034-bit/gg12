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
        $admin_user_escaped = mysqli_real_escape_string($conn, $admin_user);
        @mysqli_query($conn, "INSERT INTO audit_logs (user, event_type, ip_address, created_at) VALUES ('Admin: $admin_user_escaped', 'ADMIN_LOGIN', '$ip_addr', NOW())");

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

            <!-- 🔐 ADMIN LOGIN / DASHBOARD -->
            <?php if (isset($_SESSION['admin_login'])) { ?>
                <button class="btn-nav-action" onclick="openAdminDashboardModal()" style="background: #f59e0b; color: #0f172a; font-weight: 700;">
                    🛡️ แผงควบคุม Admin
                </button>
                <a href="c.php?action=admin_logout" class="btn-logout" style="color: #fca5a5;" onclick="return confirm('ออกจากระบบ Admin?')">ออกจาก Admin</a>
            <?php } else { ?>
                <button class="btn-nav-action" onclick="openAdminLoginModal()" style="border-color: #f59e0b; color: #fef08a; background: rgba(245, 158, 11, 0.15);">
                    🔐 Admin Login
                </button>
            <?php } ?>

            <!-- 👤 ปุ่มเข้าสู่ระบบของลูกค้า -->
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
            <img src="https://images.unsplash.com/photo-1511671782779-c97d3d27a1d4?auto=format&fit=crop&w=1600&q=80" class="hero-slide-bg" alt="Guitar Banner">
            <div class="hero-banner-content">
                <span class="hero-tag">🎸 PREMIUM GUITAR COLLECTION</span>
                <h1>สัมผัสประสบการณ์เสียงดนตรี <span>ระดับพรีเมียม</span></h1>
                <p>ศูนย์รวมกีตาร์ไฟฟ้า กีตาร์โปร่ง และอุปกรณ์ดนตรีคุณภาพชั้นนำ จัดส่งรวดเร็ว ปลอดภัย พร้อมประกันศูนย์</p>
                <div class="hero-actions">
                    <a href="#cat-group" class="btn-hero-primary">เลือกชมสินค้า</a>
                    <button class="btn-hero-secondary" onclick="openOrderSearchModal()">ติดตามคำสั่งซื้อ</button>
                </div>
            </div>
        </div>
    </section>

    <!-- 🌟 FEATURES SECTION -->
    <section class="features-section">
        <div class="features-grid">
            <div class="feature-item">
                <div class="feature-icon">🚚</div>
                <div class="feature-text">
                    <h4>จัดส่งฟรีทั่วไทย</h4>
                    <p>เมื่อสั่งซื้อครบ 1,500 บาทขึ้นไป</p>
                </div>
            </div>
            <div class="feature-item">
                <div class="feature-icon">🛡️</div>
                <div class="feature-text">
                    <h4>ประกันศูนย์แท้ 100%</h4>
                    <p>มั่นใจได้ในคุณภาพสินค้าทุกชิ้น</p>
                </div>
            </div>
            <div class="feature-item">
                <div class="feature-icon">💳</div>
                <div class="feature-text">
                    <h4>ชำระเงินปลายทาง</h4>
                    <p>บริการเก็บเงินปลายทาง (COD)</p>
                </div>
            </div>
            <div class="feature-item">
                <div class="feature-icon">🎧</div>
                <div class="feature-text">
                    <h4>บริการผู้เชี่ยวชาญ</h4>
                    <p>ให้คำปรึกษาและตั้งสายฟรี</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 🔍 SEARCH & CATEGORIES -->
    <div class="search-wrapper">
        <input type="text" id="searchInput" class="search-input" placeholder="🔍 ค้นหานาม/รุ่นสินค้าที่ต้องการ..." onkeyup="filterProducts()">
    </div>

    <div class="category-nav">
        <button class="cat-pill active" onclick="filterCategory('all', this)">ทั้งหมด</button>
        <?php foreach ($categories as $cid => $cat) { ?>
            <button class="cat-pill" onclick="filterCategory(<?php echo $cid; ?>, this)"><?php echo htmlspecialchars($cat['category_name']); ?></button>
        <?php } ?>
    </div>

    <!-- 🛒 MAIN PRODUCT CONTAINER -->
    <main class="container" id="cat-group">
        <?php foreach ($categories as $cid => $cat) { 
            $prods = $grouped_products[$cid] ?? [];
            if (empty($prods)) continue;
        ?>
            <section class="section-group cat-section" data-category-id="<?php echo $cid; ?>">
                <div class="section-header">
                    <h2 class="section-title"><?php echo htmlspecialchars($cat['category_name']); ?></h2>
                </div>

                <div class="product-grid">
                    <?php foreach ($prods as $prod) { 
                        $img = getProductImageDirect($prod);
                        $is_in_stock = $prod['stock'] > 0;
                    ?>
                        <div class="card product-card" data-title="<?php echo htmlspecialchars(mb_strtolower($prod['product_name'])); ?>">
                            <div class="card-img-box">
                                <span class="badge-code">ID: #<?php echo $prod['product_id']; ?></span>
                                <span class="badge-stock <?php echo $is_in_stock ? 'in-stock' : 'out-stock'; ?>">
                                    <?php echo $is_in_stock ? 'มีสินค้า (' . $prod['stock'] . ')' : 'สินค้าหมด'; ?>
                                </span>
                                <img src="<?php echo htmlspecialchars($img); ?>" alt="<?php echo htmlspecialchars($prod['product_name']); ?>">
                            </div>
                            <div class="card-body">
                                <div class="card-title"><?php echo htmlspecialchars($prod['product_name']); ?></div>
                                <div class="card-desc"><?php echo htmlspecialchars($prod['description'] ?? ''); ?></div>
                                <div class="card-footer">
                                    <div class="price">฿<?php echo number_format($prod['price'], 2); ?></div>
                                    <?php if ($is_in_stock) { ?>
                                        <a href="c.php?action=add&id=<?php echo $prod['product_id']; ?>" class="btn-cart">+ ใส่ตะกร้า</a>
                                    <?php } else { ?>
                                        <button class="btn-cart disabled" disabled>สินค้าหมด</button>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </section>
        <?php } ?>
    </main>

    <!-- 🌐 FOOTER -->
    <footer class="site-footer">
        <div class="footer-grid">
            <div class="footer-col">
                <h4>🎸 GUITAR REA STORE</h4>
                <p>ตัวแทนจำหน่ายเครื่องดนตรีสากลชั้นนำ กีตาร์ ตู้แอมป์ และอุปกรณ์ดนตรีครบวงจร</p>
            </div>
            <div class="footer-col">
                <h4>หมวดหมู่สินค้า</h4>
                <p>• กีตาร์ไฟฟ้า</p>
                <p>• กีตาร์โปร่ง</p>
                <p>• ตู้แอมป์ & อุปกรณ์เสริม</p>
            </div>
            <div class="footer-col">
                <h4>ช่วยเหลือ</h4>
                <p>• วิธีการสั่งซื้อสินค้า</p>
                <p>• นโยบายการรับประกัน</p>
                <p>• ตรวจสอบสถานะคำสั่งซื้อ</p>
            </div>
            <div class="footer-col">
                <h4>ติดต่อเรา</h4>
                <p>📍 Bangkok, Thailand</p>
                <p>📞 02-XXX-XXXX</p>
                <p>✉️ support@guitarrea.com</p>
            </div>
        </div>
    </footer>

    <!-- 🛒 FLOATING BUTTONS -->
    <button class="floating-cart-btn" onclick="openCartModal()">
        🛒 ตะกร้าสินค้า
        <?php if ($total_cart_items > 0) { ?>
            <span class="cart-badge"><?php echo $total_cart_items; ?></span>
        <?php } ?>
    </button>

    <!-- 🛍️ MODALS SECTION -->
    
    <!-- 1. MODAL ตะกร้าสินค้า / ชำระเงิน -->
    <div class="modal-overlay" id="cartModal">
        <div class="modal-content large-modal">
            <div class="modal-header">
                <h3>🛒 ตะกร้าสินค้าและชำระเงิน</h3>
                <button class="close-btn" onclick="closeModal('cartModal')">&times;</button>
            </div>
            
            <?php if (!empty($_SESSION['cart'])) { ?>
                <form action="c.php" method="POST">
                    <table class="cart-table">
                        <thead>
                            <tr>
                                <th>สินค้า</th>
                                <th>ราคา</th>
                                <th>จำนวน</th>
                                <th>รวม</th>
                                <th>การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $cart_ids = array_keys($_SESSION['cart']);
                            $ids_str = implode(',', array_map('intval', $cart_ids));
                            $cart_total = 0;
                            
                            if (!empty($ids_str)) {
                                $sql_cart = "SELECT * FROM products WHERE product_id IN ($ids_str)";
                                $rs_cart = mysqli_query($conn, $sql_cart);
                                while ($item = mysqli_fetch_assoc($rs_cart)) {
                                    $qty = $_SESSION['cart'][$item['product_id']];
                                    $subtotal = $item['price'] * $qty;
                                    $cart_total += $subtotal;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                    <td>฿<?php echo number_format($item['price'], 2); ?></td>
                                    <td>
                                        <input type="number" name="qty[<?php echo $item['product_id']; ?>]" value="<?php echo $qty; ?>" min="1" max="<?php echo $item['stock']; ?>" class="qty-input">
                                    </td>
                                    <td>฿<?php echo number_format($subtotal, 2); ?></td>
                                    <td>
                                        <a href="c.php?action=remove&id=<?php echo $item['product_id']; ?>" style="color: #ef4444; text-decoration: none;">🗑️ ลบ</a>
                                    </td>
                                </tr>
                            <?php 
                                }
                            } 
                            ?>
                        </tbody>
                    </table>
                    <div style="text-align: right; margin-bottom: 15px;">
                        <button type="submit" name="update_cart" class="btn-nav-action" style="background: var(--primary); border: none;">🔄 อัปเดตจำนวน</button>
                        <strong style="font-size: 16px; margin-left: 15px;">ราคารวมทั้งหมด: <span style="color: var(--accent-orange-hover);">฿<?php echo number_format($cart_total, 2); ?></span></strong>
                    </div>
                </form>

                <!-- ฟอร์มสั่งซื้อ -->
                <form action="c.php" method="POST" class="checkout-form">
                    <h4>📦 ข้อมูลการจัดส่งและชำระเงิน</h4>
                    <br>
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
                            <option value="cod">ชำระเงินปลายทาง (COD)</option>
                            <option value="transfer">โอนเงินผ่านธนาคาร</option>
                        </select>
                    </div>
                    <button type="submit" name="submit_order" class="btn-submit-order">✅ ยืนยันการสั่งซื้อ</button>
                </form>
            <?php } else { ?>
                <p style="text-align: center; color: var(--text-muted); padding: 40px 0;">ไม่มีสินค้าในตะกร้าของคุณ</p>
            <?php } ?>
        </div>
    </div>

    <!-- 2. MODAL เข้าสู่ระบบลูกค้า -->
    <div class="modal-overlay" id="authModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>✉️ เข้าสู่ระบบผู้ใช้</h3>
                <button class="close-btn" onclick="closeModal('authModal')">&times;</button>
            </div>
            <form action="c.php" method="POST">
                <div class="form-group">
                    <label>อีเมล (Email):</label>
                    <input type="email" name="login_email" placeholder="example@email.com" required>
                </div>
                <div class="form-group">
                    <label>ชื่อ-นามสกุล:</label>
                    <input type="text" name="login_fullname" placeholder="สมชาย สายชล">
                </div>
                <div class="form-group">
                    <label>เบอร์โทรศัพท์:</label>
                    <input type="text" name="login_phone" placeholder="0812345678">
                </div>
                <div class="form-group">
                    <label>ที่อยู่:</label>
                    <textarea name="login_address" rows="2" placeholder="ที่อยู่จัดส่งสินค้า..."></textarea>
                </div>
                <button type="submit" name="action_login_email" class="btn-submit-order">เข้าสู่ระบบ</button>
            </form>
        </div>
    </div>

    <!-- 3. MODAL เข้าสู่ระบบ ADMIN -->
    <div class="modal-overlay" id="adminLoginModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🔐 เข้าสู่ระบบ Admin</h3>
                <button class="close-btn" onclick="closeModal('adminLoginModal')">&times;</button>
            </div>
            <form action="c.php" method="POST">
                <div class="form-group">
                    <label>Username:</label>
                    <input type="text" name="admin_username" required placeholder="admin">
                </div>
                <div class="form-group">
                    <label>Password:</label>
                    <input type="password" name="admin_password" required placeholder="1234">
                </div>
                <button type="submit" name="action_admin_login" class="btn-submit-order" style="background: var(--primary);">Login Admin</button>
            </form>
        </div>
    </div>

    <!-- 4. MODAL ตรวจสอบคำสั่งซื้อ -->
    <div class="modal-overlay" id="searchOrderModal">
        <div class="modal-content large-modal">
            <div class="modal-header">
                <h3>📋 ตรวจสอบรายการสั่งซื้อ</h3>
                <button class="close-btn" onclick="closeModal('searchOrderModal')">&times;</button>
            </div>
            <form action="c.php" method="POST" style="display: flex; gap: 10px; margin-bottom: 20px;">
                <input type="text" name="search_term" class="search-input" style="box-shadow: none;" placeholder="กรอกเบอร์โทรศัพท์ หรือ หมายเลขคำสั่งซื้อ (Order ID)..." value="<?php echo htmlspecialchars($search_term_input); ?>" required>
                <button type="submit" name="action_search_order" class="btn-hero-primary" style="white-space: nowrap;">ค้นหา</button>
            </form>

            <?php if ($has_searched) { ?>
                <?php if (!empty($searched_orders)) { ?>
                    <?php foreach ($searched_orders as $ord) { ?>
                        <div style="background: #f8fafc; border: 1px solid var(--border-color); border-radius: var(--radius-md); padding: 15px; margin-bottom: 15px;">
                            <div style="display: flex; justify-content: space-between; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; margin-bottom: 10px;">
                                <strong>หมายเลขคำสั่งซื้อ: #<?php echo $ord['order_id']; ?></strong>
                                <span style="color: var(--text-muted); font-size: 12px;"><?php echo $ord['created_at']; ?></span>
                            </div>
                            <p style="font-size: 13px;"><strong>ผู้สั่งซื้อ:</strong> <?php echo htmlspecialchars($ord['customer_name']); ?> (<?php echo htmlspecialchars($ord['customer_phone']); ?>)</p>
                            <p style="font-size: 13px;"><strong>ที่อยู่:</strong> <?php echo htmlspecialchars($ord['customer_address']); ?></p>
                            
                            <table class="cart-table" style="margin-top: 10px;">
                                <thead>
                                    <tr>
                                        <th>รายการสินค้า</th>
                                        <th>จำนวน</th>
                                        <th>ราคา/ชิ้น</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ord['items'] as $it) { ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($it['product_name'] ?? 'สินค้า ID #' . $it['product_id']); ?></td>
                                            <td><?php echo $it['quantity']; ?></td>
                                            <td>฿<?php echo number_format($it['price'], 2); ?></td>
                                        </tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                            <div style="text-align: right; font-weight: 700; color: var(--accent-orange-hover);">
                                ราคารวมทั้งหมด: ฿<?php echo number_format($ord['total_amount'], 2); ?>
                            </div>
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <p style="text-align: center; color: var(--text-muted); padding: 20px;">ไม่พบรายการสั่งซื้อที่ค้นหา</p>
                <?php } ?>
            <?php } ?>
        </div>
    </div>

    <!-- 5. MODAL AUDIT LOG -->
    <div class="modal-overlay" id="auditLogModal">
        <div class="modal-content large-modal">
            <div class="modal-header">
                <h3>📜 Audit Logs (ประวัติการใช้งานระบบ)</h3>
                <button class="close-btn" onclick="closeModal('auditLogModal')">&times;</button>
            </div>
            <table class="cart-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>ผู้ใช้งาน</th>
                        <th>กิจกรรม</th>
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
                                    <span class="badge-event <?php echo strpos($log['event_type'], 'LOGIN') !== false ? 'login' : 'logout'; ?>">
                                        <?php echo htmlspecialchars($log['event_type']); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                <td><?php echo $log['created_at']; ?></td>
                            </tr>
                        <?php } ?>
                    <?php } else { ?>
                        <tr><td colspan="5" style="text-align: center;">ไม่มีข้อมูลบันทึก Log</td></tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 6. MODAL ADMIN DASHBOARD -->
    <?php if (isset($_SESSION['admin_login'])) { ?>
        <div class="modal-overlay" id="adminDashboardModal">
            <div class="modal-content large-modal">
                <div class="modal-header">
                    <h3>🛡️ แผงควบคุม Admin & สรุปยอดขาย</h3>
                    <button class="close-btn" onclick="closeModal('adminDashboardModal')">&times;</button>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px;">
                    <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 15px; border-radius: var(--radius-md);">
                        <small style="color: #1e40af;">จำนวนรวมที่ขายได้</small>
                        <h2 style="color: #1e3a8a;"><?php echo number_format($grand_total_sold); ?> ชิ้น</h2>
                    </div>
                    <div style="background: #f0fdf4; border: 1px solid #bbf7d0; padding: 15px; border-radius: var(--radius-md);">
                        <small style="color: #166534;">รายได้รวมทั้งหมด</small>
                        <h2 style="color: #14532d;">฿<?php echo number_format($grand_total_revenue, 2); ?></h2>
                    </div>
                </div>

                <h4>รายการสินค้าและการจัดการสต็อก</h4>
                <table class="cart-table" style="margin-top: 10px;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>ชื่อสินค้า</th>
                            <th>ราคา</th>
                            <th>ยอดขาย (ชิ้น)</th>
                            <th>สต็อกคงเหลือ</th>
                            <th>อัปเดตสต็อก</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($admin_products_data as $aprod) { ?>
                            <tr>
                                <td>#<?php echo $aprod['product_id']; ?></td>
                                <td><?php echo htmlspecialchars($aprod['product_name']); ?></td>
                                <td>฿<?php echo number_format($aprod['price'], 2); ?></td>
                                <td><?php echo number_format($aprod['total_sold']); ?></td>
                                <td><strong><?php echo $aprod['stock']; ?></strong></td>
                                <td>
                                    <form action="c.php" method="POST" style="display: flex; gap: 5px;">
                                        <input type="hidden" name="product_id" value="<?php echo $aprod['product_id']; ?>">
                                        <input type="number" name="stock_qty" value="<?php echo $aprod['stock']; ?>" class="qty-input" required>
                                        <button type="submit" name="update_stock" class="btn-cart" style="padding: 4px 8px;">บันทึก</button>
                                    </form>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php } ?>

    <!-- 📜 JAVASCRIPT LOGIC -->
    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        function openCartModal() { openModal('cartModal'); }
        function openAuthModal() { openModal('authModal'); }
        function openAdminLoginModal() { openModal('adminLoginModal'); }
        function openOrderSearchModal() { openModal('searchOrderModal'); }
        function openAuditLogModal() { openModal('auditLogModal'); }
        function openAdminDashboardModal() { openModal('adminDashboardModal'); }

        // กรองหมวดหมู่สินค้า
        function filterCategory(catId, btn) {
            document.querySelectorAll('.cat-pill').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');

            const sections = document.querySelectorAll('.cat-section');
            sections.forEach(sec => {
                if (catId === 'all' || sec.getAttribute('data-category-id') == catId) {
                    sec.style.display = 'block';
                } else {
                    sec.style.display = 'none';
                }
            });
        }

        // ค้นหาสินค้า real-time ในหน้าเว็บ
        function filterProducts() {
            const query = document.getElementById('searchInput').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.product-card');

            cards.forEach(card => {
                const title = card.getAttribute('data-title');
                if (title.includes(query)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // แสดง Modal อัตโนมัติเมื่อสั่งซื้อสำเร็จ หรือเมื่อมีการค้นหา
        <?php if ($receipt_data || $order_success_id > 0) { ?>
            Swal.fire({
                title: 'ทำรายการสำเร็จ!',
                text: 'สั่งซื้อสินค้าเรียบร้อยแล้ว หมายเลขคำสั่งซื้อคือ #<?php echo $show_receipt_id; ?>',
                icon: 'success',
                confirmButtonText: 'ตกลง'
            });
        <?php } ?>

        <?php if ($has_searched) { ?>
            openOrderSearchModal();
        <?php } ?>

        <?php if (isset($_GET['open_cart'])) { ?>
            openCartModal();
        <?php } ?>

        <?php if (isset($_GET['admin_panel']) && isset($_SESSION['admin_login'])) { ?>
            openAdminDashboardModal();
        <?php } ?>
    </script>
</body>
</html>
