<?php
session_start();
require_once 'connectdb.php';

// ---------------------------------------------------------------------
// 📌 ระบบเข้าสู่ระบบด้วยอีเมล
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
        header("Location: c.php");
        exit;
    } else {
        $auth_error = 'กรุณากรอกรูปแบบอีเมลให้ถูกต้อง';
    }
}

// ออกจากระบบ
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    unset($_SESSION['user']);
    header("Location: c.php");
    exit;
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

$payment_names = [
    'cod'       => '🚚 เก็บเงินปลายทาง (Cash on Delivery)',
    'promptpay' => '📱 โอนเงินผ่านธนาคาร / QR PromptPay',
    'credit'    => '💳 บัตรเครดิต / เดบิต'
];

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
$all_products_for_bot = [];

if ($rs_prod && mysqli_num_rows($rs_prod) > 0) {
    while ($prod = mysqli_fetch_assoc($rs_prod)) {
        $cat_id = $prod['category_id'];
        $grouped_products[$cat_id][] = $prod;

        $cat_name = isset($categories[$cat_id]) ? $categories[$cat_id]['category_name'] : 'สินค้าทั่วไป';
        $all_products_for_bot[] = [
            'id'        => $prod['product_id'],
            'name'      => isset($prod['product_name']) ? $prod['product_name'] : (isset($prod['name']) ? $prod['name'] : 'สินค้า'),
            'code'      => isset($prod['product_code']) ? $prod['product_code'] : (isset($prod['sku']) ? $prod['sku'] : "P".$prod['product_id']),
            'price'     => number_format($prod['price'], 2),
            'raw_price' => floatval($prod['price']),
            'stock'     => intval($prod['stock']),
            'category'  => $cat_name,
            'desc'      => isset($prod['description']) ? $prod['description'] : ''
        ];
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

        .slider-arrow {
            position: absolute; top: 50%; transform: translateY(-50%);
            background: rgba(15, 23, 42, 0.5); color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2); width: 44px; height: 44px;
            border-radius: 50%; font-size: 20px; cursor: pointer; z-index: 10;
            display: flex; align-items: center; justify-content: center;
            transition: all 0.3s ease; backdrop-filter: blur(4px);
        }
        .slider-arrow:hover { background: var(--accent-orange); border-color: var(--accent-orange); }
        .slider-arrow.prev { left: 20px; }
        .slider-arrow.next { right: 20px; }

        .slider-dots {
            position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%);
            display: flex; gap: 8px; z-index: 10;
        }
        .dot {
            width: 12px; height: 12px; border-radius: 50%; background: rgba(255,255,255,0.4);
            cursor: pointer; transition: all 0.3s ease;
        }
        .dot.active { background: var(--accent-orange); width: 28px; border-radius: 12px; }

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
            display: none; position: fixed; bottom: 90px; left: 25px; width: 380px; height: 500px;
            background: #fff; border-radius: var(--radius-lg); box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            z-index: 1000; flex-direction: column; overflow: hidden; border: 1px solid var(--border-color);
        }
        .chatbot-window.active { display: flex; }
        .chatbot-header { background: var(--primary); color: #fff; padding: 14px 18px; font-size: 14px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
        .close-chat-btn { background: none; border: none; color: #fff; font-size: 20px; cursor: pointer; }
        .chatbot-body { flex-grow: 1; padding: 14px; overflow-y: auto; background: #f8fafc; display: flex; flex-direction: column; gap: 10px; }
        .chat-msg { max-width: 88%; padding: 10px 14px; border-radius: 12px; font-size: 12px; line-height: 1.5; }
        .bot-msg { background: #e0f2fe; color: #0369a1; align-self: flex-start; }
        .user-msg { background: var(--primary-accent); color: #fff; align-self: flex-end; }
        .chatbot-footer { display: flex; padding: 10px; background: #fff; border-top: 1px solid var(--border-color); }
        .chatbot-footer input { flex-grow: 1; border: 1px solid var(--border-color); padding: 8px 14px; border-radius: 20px; font-size: 12px; outline: none; }
        .chatbot-footer button { background: var(--primary-accent); color: white; border: none; padding: 8px 16px; margin-left: 6px; border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer; }

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

        .payment-options-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 10px; }
        .payment-card {
            border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 8px;
            text-align: center; cursor: pointer; background: #fff; transition: all 0.2s; font-size: 12px; font-weight: 500;
        }
        .payment-card.active { border-color: var(--primary-accent); background: #eff6ff; color: var(--primary-accent); font-weight: 600; }

        .btn-submit-order {
            background: #10b981; color: white; border: none; padding: 12px; border-radius: 8px;
            width: 100%; font-size: 14px; font-weight: 600; cursor: pointer; margin-top: 10px; transition: background 0.2s;
        }
        .btn-submit-order:hover { background: #059669; }

        .order-history-card {
            background: #ffffff; border: 1px solid var(--border-color); border-radius: var(--radius-md);
            padding: 16px; margin-bottom: 16px; box-shadow: var(--shadow-sm);
        }
        .order-history-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; border-bottom: 1px solid #f1f5f9; padding-bottom: 8px; }
        .order-items-list { background: #f8fafc; border-radius: 8px; padding: 10px; font-size: 12px; margin: 10px 0; }
        .order-item-row { display: flex; justify-content: space-between; margin-bottom: 6px; }

        .receipt-box { background: #ffffff; border: 1px solid #cbd5e1; border-radius: 12px; padding: 24px; font-size: 13px; color: #1e293b; line-height: 1.6; }
        .receipt-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px dashed #cbd5e1; padding-bottom: 16px; margin-bottom: 16px; }
        .receipt-title { font-size: 20px; font-weight: 700; color: #0f172a; }
        .receipt-info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; background: #f8fafc; padding: 12px; border-radius: 8px; margin-bottom: 16px; }
        .receipt-table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        .receipt-table th, .receipt-table td { padding: 8px 10px; border-bottom: 1px solid #e2e8f0; }
        .receipt-table th { background: #f1f5f9; text-align: left; font-size: 12px; }
        .receipt-total { text-align: right; font-size: 16px; font-weight: 700; color: #d97706; margin-top: 10px; }

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
        }
    </style>
</head>
<body>

    <!-- 📌 TOP NAVBAR -->
    <nav class="top-navbar">
        <a href="c.php" class="brand-logo">🎸 GUITAR REA <span>STORE</span></a>
        <div class="user-nav-box">
            <button class="btn-nav-action" onclick="openOrderSearchModal()">📋 ตรวจสอบคำสั่งซื้อ</button>

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
                <p>สัมผัสพลังเสียงระดับสตูดิโอด้วยกีตาร์ไฟฟ้าและตู้แอมป์รุ่นล่าสุด พร้อมส่วนลดสุดพิเศษเฉพาะเดือนนี้เท่านั้น</p>
                <div class="hero-actions">
                    <a href="#cat-1" class="btn-hero-primary">🔥 เลือกชมกีตาร์ไฟฟ้า</a>
                </div>
            </div>
        </div>

        <button class="slider-arrow prev" onclick="moveSlide(-1)">❮</button>
        <button class="slider-arrow next" onclick="moveSlide(1)">❯</button>

        <div class="slider-dots">
            <span class="dot active" onclick="currentSlide(0)"></span>
            <span class="dot" onclick="currentSlide(1)"></span>
        </div>
    </section>

    <!-- 🚚 FEATURES SECTION -->
    <section class="features-section">
        <div class="features-grid">
            <div class="feature-item">
                <div class="feature-icon">🚚</div>
                <div class="feature-text">
                    <h4>จัดส่งฟรีทั่วประเทศ</h4>
                    <p>เมื่อซื้อสินค้าครบ 2,000 บาทขึ้นไป</p>
                </div>
            </div>
            <div class="feature-item">
                <div class="feature-icon">🛡️</div>
                <div class="feature-text">
                    <h4>การันตีของแท้ 100%</h4>
                    <p>สินค้าคุณภาพตรงปก รับประกันศูนย์</p>
                </div>
            </div>
            <div class="feature-item">
                <div class="feature-icon">💳</div>
                <div class="feature-text">
                    <h4>ชำระเงินสะดวก</h4>
                    <p>รองรับโอนเงิน บัตรเครดิต และปลายทาง</p>
                </div>
            </div>
            <div class="feature-item">
                <div class="feature-icon">🎧</div>
                <div class="feature-text">
                    <h4>ผู้เชี่ยวชาญให้คำปรึกษา</h4>
                    <p>มีระบบแชท AI คอยช่วยเหลือตลอด 24 ชม.</p>
                </div>
            </div>
        </div>
    </section>

    <!-- 🔍 SEARCH SECTION -->
    <div class="search-wrapper">
        <input type="text" id="searchInput" class="search-input" placeholder="🔍 ค้นหากีตาร์, แอมป์, รหัสสินค้า..." onkeyup="filterProducts()">
    </div>

    <!-- 🏷️ CATEGORY FILTER -->
    <div class="category-nav" id="cat-group">
        <button class="cat-pill active" onclick="filterCategory('all', this)">ทั้งหมด</button>
        <?php foreach ($categories as $cid => $cdata) { ?>
            <button class="cat-pill" onclick="filterCategory(<?php echo $cid; ?>, this)"><?php echo htmlspecialchars($cdata['category_name']); ?></button>
        <?php } ?>
    </div>

    <!-- 🛍️ PRODUCT CONTAINER -->
    <div class="container">
        <?php 
        if (!empty($grouped_products)) {
            foreach ($grouped_products as $cat_id => $prods) {
                $cname = isset($categories[$cat_id]) ? $categories[$cat_id]['category_name'] : 'สินค้าทั่วไป';
                $cdesc = isset($categories[$cat_id]) ? $categories[$cat_id]['description'] : '';
        ?>
            <div class="section-group category-block" id="cat-<?php echo $cat_id; ?>" data-cat-id="<?php echo $cat_id; ?>">
                <div class="section-header">
                    <div>
                        <h2 class="section-title"><?php echo htmlspecialchars($cname); ?></h2>
                        <span style="font-size: 12px; color: var(--text-muted);"><?php echo htmlspecialchars($cdesc); ?></span>
                    </div>
                </div>
                <div class="product-grid">
                    <?php foreach ($prods as $prod) { 
                        $p_id = $prod['product_id'];
                        $p_name = isset($prod['product_name']) ? $prod['product_name'] : (isset($prod['name']) ? $prod['name'] : 'สินค้า');
                        $p_code = isset($prod['product_code']) ? $prod['product_code'] : (isset($prod['sku']) ? $prod['sku'] : "P".$p_id);
                        $p_price = floatval($prod['price']);
                        $p_stock = intval($prod['stock']);
                        
                        $img_url = getProductImageDirect($prod);
                        $raw_filename = !empty($prod['image_url']) ? trim($prod['image_url']) : '';
                    ?>
                        <div class="card product-card" data-title="<?php echo strtolower(htmlspecialchars($p_name)); ?>" data-code="<?php echo strtolower(htmlspecialchars($p_code)); ?>">
                            <div class="card-img-box">
                                <span class="badge-code"><?php echo htmlspecialchars($p_code); ?></span>
                                <span class="badge-stock <?php echo ($p_stock > 0) ? 'in-stock' : 'out-stock'; ?>">
                                    <?php echo ($p_stock > 0) ? "คงเหลือ $p_stock" : "สินค้าหมด"; ?>
                                </span>
                                <img src="<?php echo htmlspecialchars($img_url); ?>" 
                                     alt="<?php echo htmlspecialchars($p_name); ?>" 
                                     data-raw="<?php echo htmlspecialchars($raw_filename); ?>"
                                     onerror="if(!this.getAttribute('data-tried-direct')){ this.setAttribute('data-tried-direct', 'true'); this.src = this.getAttribute('data-raw'); } else { this.src='https://images.unsplash.com/photo-1510915361894-db8b60106cb1?auto=format&fit=crop&w=400&q=80'; }">
                            </div>
                            <div class="card-body">
                                <h3 class="card-title"><?php echo htmlspecialchars($p_name); ?></h3>
                                <p class="card-desc"><?php echo isset($prod['description']) ? htmlspecialchars($prod['description']) : ''; ?></p>
                                <div class="card-footer">
                                    <span class="price">฿<?php echo number_format($p_price, 2); ?></span>
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
        <?php 
            }
        } else {
            echo '<div style="text-align:center; padding: 40px; color: var(--text-muted);">ไม่พบข้อมูลสินค้าในระบบ</div>';
        } 
        ?>
    </div>

    <!-- 📄 FOOTER -->
    <footer class="site-footer">
        <div class="footer-grid">
            <div class="footer-col">
                <h4>GUITAR REA STORE</h4>
                <p>ร้านจำหน่ายอุปกรณ์ดนตรี กีตาร์ไฟฟ้า กีตาร์โปร่ง ตู้แอมป์ และอุปกรณ์ดนตรีครบวงจร คุณภาพดีเยี่ยม การันตีศูนย์ไทย</p>
            </div>
            <div class="footer-col">
                <h4>หมวดหมู่สินค้า</h4>
                <p>• กีตาร์ไฟฟ้า</p>
                <p>• กีตาร์โปร่ง / คลาสสิก</p>
                <p>• แอมป์และเอฟเฟกต์</p>
                <p>• อุปกรณ์เสริมดนตรี</p>
            </div>
            <div class="footer-col">
                <h4>การบริการลูกค้า</h4>
                <p>• วิธีการสั่งซื้อสินค้า</p>
                <p>• ตรวจสอบรายการสั่งซื้อ</p>
                <p>• การรับประกันสินค้า</p>
            </div>
            <div class="footer-col">
                <h4>ติดต่อเรา</h4>
                <p>📍 GUITAR REA STORE</p>
                <p>📞 โทร: 02-123-4567</p>
                <p>✉️ อีเมล: support@guitarreastore.com</p>
            </div>
        </div>
    </footer>

    <!-- 🛒 FLOATING CART BUTTON -->
    <button class="floating-cart-btn" onclick="openCartModal()">
        🛒 ตะกร้าสินค้า
        <?php if ($total_cart_items > 0) { ?>
            <span class="cart-badge"><?php echo $total_cart_items; ?></span>
        <?php } ?>
    </button>

    <!-- 💬 FLOATING CHATBOT BUTTON -->
    <button class="floating-chat-btn" onclick="toggleChatbot()">
        💬 สอบถาม AI ผู้เชี่ยวชาญ
    </button>

    <!-- 🤖 CHATBOT WINDOW -->
    <div class="chatbot-window" id="chatbotWindow">
        <div class="chatbot-header">
            <span>🤖 AI ผู้ช่วย Guitar Rea</span>
            <button class="close-chat-btn" onclick="toggleChatbot()">✕</button>
        </div>
        <div class="chatbot-body" id="chatBody">
            <div class="chat-msg bot-msg">
                สวัสดีครับ! ยินดีต้อนรับสู่ GUITAR REA STORE 🎸<br>คุณสามารถสอบถาม AI ได้ทั้ง:<br>
                • ค้นหาสินค้าตามงบ เช่น <i>"งบ 3000"</i><br>
                • เช็คสต็อก / ราคา เช่น <i>"ตัวไหนถูกสุด"</i><br>
                • ให้แนะนำ เช่น <i>"แนะนำกีตาร์ไฟฟ้า"</i>
            </div>
        </div>
        <div class="chatbot-footer">
            <input type="text" id="chatInput" placeholder="พิมพ์ข้อความคำถามที่นี่..." onkeypress="handleChatKeyPress(event)">
            <button onclick="sendChatMessage()">ส่ง</button>
        </div>
    </div>

    <!-- 📋 ORDER SEARCH MODAL -->
    <div class="modal-overlay <?php echo $has_searched ? 'active' : ''; ?>" id="orderSearchModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📋 ตรวจสอบรายการสินค้าที่สั่งซื้อ</h3>
                <button class="close-btn" onclick="closeOrderSearchModal()">✕</button>
            </div>
            
            <form action="c.php" method="POST" style="margin-bottom:20px;">
                <div class="form-group" style="display:flex; gap:8px;">
                    <input type="text" name="search_term" placeholder="ระบุเบอร์โทรศัพท์ หรือ เลขที่สั่งซื้อ (เช่น 1, 2)" value="<?php echo htmlspecialchars($search_term_input); ?>" required style="flex-grow:1;">
                    <button type="submit" name="action_search_order" style="background:var(--primary-accent); color:white; border:none; padding:10px 18px; border-radius:8px; font-weight:600; cursor:pointer; font-size:13px; white-space:nowrap;">🔍 ค้นหา</button>
                </div>
            </form>

            <?php if ($has_searched): ?>
                <?php if (!empty($searched_orders)): ?>
                    <p style="font-size:13px; color:var(--text-muted); margin-bottom:12px;">พบคำสั่งซื้อทั้งหมด <b><?php echo count($searched_orders); ?></b> รายการ:</p>
                    
                    <?php foreach ($searched_orders as $ord): ?>
                        <div class="order-history-card">
                            <div class="order-history-header">
                                <div>
                                    <strong style="color:var(--primary-accent); font-size:14px;">คำสั่งซื้อ #ORD-<?php echo str_pad($ord['order_id'], 5, '0', STR_PAD_LEFT); ?></strong>
                                    <div style="font-size:11px; color:var(--text-muted);"><?php echo date('d/m/Y H:i', strtotime($ord['created_at'])); ?></div>
                                </div>
                                <a href="c.php?receipt_id=<?php echo $ord['order_id']; ?>" style="background:var(--primary); color:white; padding:4px 10px; border-radius:6px; font-size:11px; text-decoration:none; font-weight:500;">🧾 ใบเสร็จ</a>
                            </div>

                            <div style="font-size:12px; margin-bottom:6px;">
                                👤 <b>ชื่อผู้รับ:</b> <?php echo htmlspecialchars($ord['customer_name']); ?> | 📞 <?php echo htmlspecialchars($ord['customer_phone']); ?><br>
                                📍 <b>ที่อยู่:</b> <?php echo htmlspecialchars($ord['customer_address']); ?>
                            </div>

                            <div class="order-items-list">
                                <b style="display:block; margin-bottom:6px; border-bottom:1px dashed #cbd5e1; padding-bottom:4px;">📦 สินค้าในออเดอร์นี้:</b>
                                <?php foreach ($ord['items'] as $it): 
                                    $pname = !empty($it['product_name']) ? $it['product_name'] : "สินค้า รหัส #".$it['product_id'];
                                ?>
                                    <div class="order-item-row">
                                        <span>• <?php echo htmlspecialchars($pname); ?> x <b><?php echo $it['quantity']; ?></b></span>
                                        <span>฿<?php echo number_format($it['price'] * $it['quantity'], 2); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="display:flex; justify-content:space-between; align-items:center; margin-top:8px;">
                                <span style="font-size:11px; color:#10b981; font-weight:600; background:#d1fae5; padding:3px 8px; border-radius:12px;">✅ สั่งซื้อเรียบร้อย</span>
                                <strong style="color:var(--accent-orange-hover); font-size:14px;">ยอดรวม: ฿<?php echo number_format($ord['total_amount'], 2); ?></strong>
                            </div>
                        </div>
                    <?php endforeach; ?>

                <?php else: ?>
                    <div style="text-align:center; padding:30px 0; color:var(--text-muted);">
                        <p style="font-size:36px; margin-bottom:8px;">🔍</p>
                        <p>ไม่พบรายการคำสั่งซื้อของ "<b><?php echo htmlspecialchars($search_term_input); ?></b>"</p>
                        <span style="font-size:11px;">โปรดตรวจสอบเบอร์โทรศัพท์หรือเลขที่สั่งซื้ออีกครั้ง</span>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- 🛒 CART & CHECKOUT MODAL -->
    <div class="modal-overlay" id="cartModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🛒 ตะกร้าสินค้าของคุณ</h3>
                <button class="close-btn" onclick="closeCartModal()">✕</button>
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
                                <th>ลบ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $cart_ids = array_keys($_SESSION['cart']);
                            $ids_str = implode(',', array_map('intval', $cart_ids));
                            $total_cart_price = 0;

                            if (!empty($ids_str)) {
                                $sql_c = "SELECT * FROM products WHERE product_id IN ($ids_str)";
                                $rs_c = mysqli_query($conn, $sql_c);
                                while ($c_item = mysqli_fetch_assoc($rs_c)) {
                                    $cid = $c_item['product_id'];
                                    $cqty = $_SESSION['cart'][$cid];
                                    $cprice = floatval($c_item['price']);
                                    $subtotal = $cprice * $cqty;
                                    $total_cart_price += $subtotal;
                                    $cname = isset($c_item['product_name']) ? $c_item['product_name'] : $c_item['name'];
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cname); ?></td>
                                    <td>฿<?php echo number_format($cprice, 2); ?></td>
                                    <td>
                                        <input type="number" name="qty[<?php echo $cid; ?>]" value="<?php echo $cqty; ?>" min="1" max="<?php echo $c_item['stock']; ?>" class="qty-input">
                                    </td>
                                    <td>฿<?php echo number_format($subtotal, 2); ?></td>
                                    <td>
                                        <a href="c.php?action=remove&id=<?php echo $cid; ?>" style="color:red; text-decoration:none;">🗑️</a>
                                    </td>
                                </tr>
                            <?php 
                                }
                            } 
                            ?>
                        </tbody>
                    </table>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <button type="submit" name="update_cart" style="background:#64748b; color:white; border:none; padding:6px 12px; border-radius:6px; cursor:pointer; font-size:12px;">🔄 อัปเดตจำนวน</button>
                        <strong style="font-size:16px; color:var(--accent-orange-hover);">ยอดรวมทั้งหมด: ฿<?php echo number_format($total_cart_price, 2); ?></strong>
                    </div>
                </form>

                <form action="c.php" method="POST" class="checkout-form">
                    <h4 style="margin-bottom:12px; color:var(--primary);">📦 ข้อมูลการจัดส่งและการชำระเงิน</h4>
                    <div class="form-group">
                        <label>ชื่อ-นามสกุล ผู้รับ:</label>
                        <input type="text" name="cust_name" required value="<?php echo isset($_SESSION['user']['fullname']) ? htmlspecialchars($_SESSION['user']['fullname']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>เบอร์โทรศัพท์:</label>
                        <input type="text" name="cust_phone" required value="<?php echo isset($_SESSION['user']['phone']) ? htmlspecialchars($_SESSION['user']['phone']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>ที่อยู่จัดส่ง:</label>
                        <textarea name="cust_address" rows="2" required><?php echo isset($_SESSION['user']['address']) ? htmlspecialchars($_SESSION['user']['address']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>ช่องทางการชำระเงิน:</label>
                        <input type="hidden" name="payment_method" id="selected_payment" value="cod">
                        <div class="payment-options-grid">
                            <div class="payment-card active" onclick="selectPayment('cod', this)">🚚 เก็บเงินปลายทาง</div>
                            <div class="payment-card" onclick="selectPayment('promptpay', this)">📱 โอนผ่าน QR</div>
                            <div class="payment-card" onclick="selectPayment('credit', this)">💳 บัตรเครดิต</div>
                        </div>
                    </div>

                    <button type="submit" name="submit_order" class="btn-submit-order">✅ ยืนยันการสั่งซื้อ</button>
                </form>
            <?php } else { ?>
                <div style="text-align:center; padding:30px 0; color:var(--text-muted);">
                    <p style="font-size:48px; margin-bottom:10px;">🛒</p>
                    <p>ไม่มีสินค้าในตะกร้าของคุณ</p>
                </div>
            <?php } ?>
        </div>
    </div>

    <!-- ✉️ AUTHENTICATION MODAL -->
    <div class="modal-overlay" id="authModal">
        <div class="modal-content" style="max-width:420px;">
            <div class="modal-header">
                <h3>✉️ เข้าสู่ระบบด้วยอีเมล</h3>
                <button class="close-btn" onclick="closeAuthModal()">✕</button>
            </div>
            <form action="c.php" method="POST">
                <div class="form-group">
                    <label>อีเมล (Required):</label>
                    <input type="email" name="login_email" required placeholder="example@domain.com">
                </div>
                <div class="form-group">
                    <label>ชื่อ-นามสกุล:</label>
                    <input type="text" name="login_fullname" placeholder="ระบุชื่อของคุณ">
                </div>
                <div class="form-group">
                    <label>เบอร์โทรศัพท์:</label>
                    <input type="text" name="login_phone" placeholder="08X-XXX-XXXX">
                </div>
                <div class="form-group">
                    <label>ที่อยู่จัดส่ง:</label>
                    <textarea name="login_address" rows="2" placeholder="ระบุที่อยู่จัดส่งแบบรายละเอียด"></textarea>
                </div>
                <button type="submit" name="action_login_email" style="width:100%; background:var(--primary); color:white; border:none; padding:10px; border-radius:8px; font-weight:600; cursor:pointer;">บันทึกข้อมูลเข้าสู่ระบบ</button>
            </form>
        </div>
    </div>

    <!-- 🧾 RECEIPT MODAL -->
    <?php if ($receipt_data) { ?>
    <div class="modal-overlay active" id="receiptModal">
        <div class="modal-content" style="max-width:620px;">
            <div class="modal-header">
                <h3>🧾 ใบเสร็จรับเงิน / Receipt</h3>
                <button class="close-btn" onclick="closeReceiptModal()">✕</button>
            </div>
            <div class="receipt-box">
                <div class="receipt-header">
                    <div>
                        <div class="receipt-title">GUITAR REA STORE</div>
                        <div style="font-size:11px; color:#64748b;">ใบเสร็จรับเงินอย่างย่อ</div>
                    </div>
                    <div style="text-align:right;">
                        <b style="font-size:14px; color:#2563eb;">#ORD-<?php echo str_pad($receipt_data['order_id'], 5, '0', STR_PAD_LEFT); ?></b>
                        <div style="font-size:11px; color:#64748b;"><?php echo date('d/m/Y H:i', strtotime($receipt_data['created_at'])); ?></div>
                    </div>
                </div>

                <div class="receipt-info-grid">
                    <div>
                        <strong>ผู้สั่งซื้อ:</strong> <?php echo htmlspecialchars($receipt_data['customer_name']); ?><br>
                        <strong>เบอร์โทร:</strong> <?php echo htmlspecialchars($receipt_data['customer_phone']); ?>
                    </div>
                    <div>
                        <strong>วิธีชำระเงิน:</strong><br>
                        <?php 
                        $pm = isset($receipt_data['payment_method']) ? $receipt_data['payment_method'] : 'cod';
                        echo isset($payment_names[$pm]) ? $payment_names[$pm] : $pm; 
                        ?>
                    </div>
                </div>

                <div style="margin-bottom:12px;">
                    <strong>ที่อยู่จัดส่ง:</strong> <?php echo htmlspecialchars($receipt_data['customer_address']); ?>
                </div>

                <table class="receipt-table">
                    <thead>
                        <tr>
                            <th>รายการสินค้า</th>
                            <th style="text-align:center;">จำนวน</th>
                            <th style="text-align:right;">ราคา/หน่วย</th>
                            <th style="text-align:right;">รวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($receipt_items as $item) { 
                            $item_sum = $item['price'] * $item['quantity'];
                            $pname = !empty($item['product_name']) ? $item['product_name'] : "สินค้า รหัส #".$item['product_id'];
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($pname); ?></td>
                                <td style="text-align:center;"><?php echo $item['quantity']; ?></td>
                                <td style="text-align:right;">฿<?php echo number_format($item['price'], 2); ?></td>
                                <td style="text-align:right;">฿<?php echo number_format($item_sum, 2); ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>

                <div class="receipt-total">
                    ยอดชำระสุทธิ: ฿<?php echo number_format($receipt_data['total_amount'], 2); ?>
                </div>
            </div>
            <div style="margin-top:15px; text-align:right;">
                <button onclick="window.print()" style="background:#0f172a; color:white; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-size:12px; margin-right:8px;">🖨️ พิมพ์ใบเสร็จ</button>
                <button onclick="closeReceiptModal()" style="background:#cbd5e1; color:#1e293b; border:none; padding:8px 16px; border-radius:6px; cursor:pointer; font-size:12px;">ปิดหน้าต่าง</button>
            </div>
        </div>
    </div>
    <?php } ?>

    <script>
        const productsBotData = <?php echo json_encode($all_products_for_bot); ?>;

        // 📌 1. Hero Slider Logic
        let currentSlideIdx = 0;
        const slides = document.querySelectorAll('.hero-slide');
        const dots = document.querySelectorAll('.dot');

        function showSlide(index) {
            if (slides.length === 0) return;
            if (index >= slides.length) currentSlideIdx = 0;
            else if (index < 0) currentSlideIdx = slides.length - 1;
            else currentSlideIdx = index;

            slides.forEach(s => s.classList.remove('active'));
            dots.forEach(d => d.classList.remove('active'));

            slides[currentSlideIdx].classList.add('active');
            if (dots[currentSlideIdx]) dots[currentSlideIdx].classList.add('active');
        }

        function moveSlide(step) { showSlide(currentSlideIdx + step); }
        function currentSlide(index) { showSlide(index); }
        setInterval(() => { moveSlide(1); }, 6000);

        // 📌 2. Filter & Search Logic
        function filterCategory(catId, btn) {
            document.querySelectorAll('.cat-pill').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');

            const blocks = document.querySelectorAll('.category-block');
            blocks.forEach(block => {
                if (catId === 'all' || block.getAttribute('data-cat-id') == catId) {
                    block.style.display = 'block';
                } else {
                    block.style.display = 'none';
                }
            });
        }

        function filterProducts() {
            const query = document.getElementById('searchInput').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.product-card');

            cards.forEach(card => {
                const title = card.getAttribute('data-title');
                const code = card.getAttribute('data-code');
                if (title.includes(query) || code.includes(query)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // 📌 3. Modal Handlers
        function openCartModal() { document.getElementById('cartModal').classList.add('active'); }
        function closeCartModal() { document.getElementById('cartModal').classList.remove('active'); }

        function openAuthModal() { document.getElementById('authModal').classList.add('active'); }
        function closeAuthModal() { document.getElementById('authModal').classList.remove('active'); }

        function openOrderSearchModal() { document.getElementById('orderSearchModal').classList.add('active'); }
        function closeOrderSearchModal() { document.getElementById('orderSearchModal').classList.remove('active'); }

        function openReceiptModal() { 
            const rm = document.getElementById('receiptModal');
            if(rm) rm.classList.add('active'); 
        }
        function closeReceiptModal() { 
            const rm = document.getElementById('receiptModal');
            if(rm) rm.classList.remove('active'); 
        }

        function selectPayment(method, el) {
            document.getElementById('selected_payment').value = method;
            document.querySelectorAll('.payment-card').forEach(c => c.classList.remove('active'));
            el.classList.add('active');
        }

        // 📌 4. Smart Database AI Chatbot Logic
        function toggleChatbot() {
            const win = document.getElementById('chatbotWindow');
            win.classList.toggle('active');
        }

        function handleChatKeyPress(e) {
            if (e.key === 'Enter') sendChatMessage();
        }

        function sendChatMessage() {
            const input = document.getElementById('chatInput');
            const msg = input.value.trim();
            if (!msg) return;

            appendChatMsg(msg, 'user-msg');
            input.value = '';

            setTimeout(() => {
                const botReply = generateBotReply(msg);
                appendChatMsg(botReply, 'bot-msg');
            }, 400);
        }

        function appendChatMsg(text, typeClass) {
            const body = document.getElementById('chatBody');
            const div = document.createElement('div');
            div.className = `chat-msg ${typeClass}`;
            div.innerHTML = text;
            body.appendChild(div);
            body.scrollTop = body.scrollHeight;
        }

        function generateBotReply(userText) {
            const txt = userText.toLowerCase().trim();

            // A. ทักทาย
            if (txt.includes('สวัสดี') || txt.includes('หวัดดี') || txt.includes('ดีครับ') || txt.includes('ดีค่ะ') || txt.includes('hello') || txt.includes('hi')) {
                return 'สวัสดีครับ! 😊 ยินดีต้อนรับสู่ GUITAR REA STORE มีอะไรให้ผู้ช่วย AI แนะนำเกี่ยวกับสินค้าในร้าน สอบถามได้เลยครับ!';
            }

            // B. ค้นหาตามงบประมาณ (เช่น "งบ 3000", "ราคาไม่เกิน 5000", "งบประมาณ 2000")
            const numMatch = txt.match(/\d+/);
            if ((txt.includes('งบ') || txt.includes('ไม่เกิน') || txt.includes('ราคา')) && numMatch) {
                const maxBudget = parseFloat(numMatch[0]);
                if (!isNaN(maxBudget) && maxBudget > 50) {
                    const inBudget = productsBotData.filter(p => p.raw_price <= maxBudget);
                    if (inBudget.length > 0) {
                        let res = `💡 <b>พบสินค้าอยู่ในงบไม่เกิน ฿${maxBudget.toLocaleString()} (${inBudget.length} รายการ):</b><br><br>`;
                        inBudget.slice(0, 4).forEach(p => {
                            const st = p.stock > 0 ? `<span style="color:#10b981;">[มีของ]</span>` : `<span style="color:#ef4444;">[หมด]</span>`;
                            res += `• <b>${p.name}</b><br>&nbsp;&nbsp;ราคา: <b style="color:#d97706;">฿${p.price}</b> ${st}<br>`;
                        });
                        if (inBudget.length > 4) res += `<br><i>แสดง 4 รายการแรกจากสินค้าทั้งหมดในงบครับ</i>`;
                        return res;
                    } else {
                        return `ขออภัยครับ ไม่พบสินค้าที่มีราคาไม่เกิน ฿${maxBudget.toLocaleString()} ในคลังสินค้าขณะนี้ครับ`;
                    }
                }
            }

            // C. ค้นหาสินค้าถูกสุด / แพงสุด
            if (txt.includes('ถูกสุด') || txt.includes('ถูกที่สุด') || txt.includes('ราคาถูก')) {
                const sorted = [...productsBotData].sort((a, b) => a.raw_price - b.raw_price);
                if (sorted.length > 0) {
                    const p = sorted[0];
                    return `🏷️ <b>สินค้าที่ราคาประหยัด/ถูกที่สุดในร้าน:</b><br>• <b>${p.name}</b> (${p.code})<br>💰 ราคา: <b style="color:#d97706;">฿${p.price}</b><br>📦 คงเหลือ: ${p.stock} ชิ้น (${p.category})`;
                }
            }
            if (txt.includes('แพงสุด') || txt.includes('แพงที่สุด') || txt.includes('ท็อป') || txt.includes('พรีเมียม')) {
                const sorted = [...productsBotData].sort((a, b) => b.raw_price - a.raw_price);
                if (sorted.length > 0) {
                    const p = sorted[0];
                    return `👑 <b>สินค้าพรีเมียมราคาสูงที่สุดในร้าน:</b><br>• <b>${p.name}</b> (${p.code})<br>💰 ราคา: <b style="color:#d97706;">฿${p.price}</b><br>📦 คงเหลือ: ${p.stock} ชิ้น (${p.category})`;
                }
            }

            // D. ขอคำแนะนำ / สินค้าขายดี
            if (txt.includes('แนะนำ') || txt.includes('ขายดี') || txt.includes('ตัวไหนดี') || txt.includes('ยอดฮิต')) {
                const avail = productsBotData.filter(p => p.stock > 0);
                const randoms = avail.sort(() => 0.5 - Math.random()).slice(0, 3);
                let res = `⭐ <b>สินค้าแนะนำยอดนิยมประจำร้าน:</b><br><br>`;
                randoms.forEach(p => {
                    res += `🎸 <b>${p.name}</b> [${p.category}]<br>&nbsp;&nbsp;ราคา: <b style="color:#d97706;">฿${p.price}</b> (สต็อก: ${p.stock} ชิ้น)<br>`;
                });
                return res;
            }

            // E. เช็คสินค้าพร้อมส่ง / สินค้าหมด
            if (txt.includes('พร้อมส่ง') || txt.includes('มีของไหม') || txt.includes('สต็อก')) {
                const availCount = productsBotData.filter(p => p.stock > 0).length;
                return `📦 ปัจจุบันมีสินค้าที่มีสต็อกพร้อมจัดส่งทั้งหมด <b>${availCount}</b> รายการครับ สามารถเลือกสั่งซื้อได้ทันที!`;
            }
            if (txt.includes('หมด')) {
                const outOfStock = productsBotData.filter(p => p.stock <= 0);
                if (outOfStock.length > 0) {
                    let res = `⚠️ <b>รายการสินค้าที่หมดสต็อกชั่วคราว:</b><br>`;
                    outOfStock.forEach(p => { res += `• ${p.name}<br>`; });
                    return res;
                } else {
                    return '🎉 สินค้าทุกรายการในระบบขณะนี้มีสต็อกพร้อมส่งทั้งหมดครับ!';
                }
            }

            // F. ตรวจสอบออเดอร์ / คำสั่งซื้อ
            if (txt.includes('เช็ค') || txt.includes('ตาม') || txt.includes('ออเดอร์') || txt.includes('พัสดุ') || txt.includes('คำสั่งซื้อ')) {
                return '📋 คุณสามารถตรวจสอบสินค้าที่สั่งได้ง่ายๆ โดยกดปุ่ม <b>"ตรวจสอบคำสั่งซื้อ"</b> บนเมนูด้านบน แล้วพิมพ์ <b>เบอร์โทรศัพท์</b> ได้เลยครับ!';
            }

            // G. ค่าจัดส่ง / ชำระเงิน / ติดต่อ
            if (txt.includes('ส่ง') || txt.includes('ค่าส่ง') || txt.includes('ขนส่ง')) {
                return '🚚 <b>ข้อมูลการจัดส่ง:</b><br>• จัดส่งฟรี! เมื่อสั่งซื้อครบ 2,000 บาทขึ้นไป<br>• ยอดไม่ถึง 2,000 บาท คิดค่าจัดส่งตามจริง<br>• ระยะเวลาจัดส่งประมาณ 1-3 วันทำการ';
            }
            if (txt.includes('ชำระ') || txt.includes('จ่าย') || txt.includes('โอน') || txt.includes('ปลายทาง') || txt.includes('บัตร')) {
                return '💳 <b>ช่องทางการชำระเงิน:</b><br>1. 🚚 เก็บเงินปลายทาง (COD)<br>2. 📱 โอนเงิน / QR PromptPay<br>3. 💳 บัตรเครดิต / เดบิต';
            }
            if (txt.includes('ติดต่อ') || txt.includes('เบอร์') || txt.includes('โทร') || txt.includes('ร้านอยู่ที่ไหน')) {
                return '📞 <b>ติดต่อร้าน GUITAR REA STORE:</b><br>• โทร: 02-123-4567<br>• อีเมล: support@guitarreastore.com<br>• เปิดทำการทุกวัน 09:00 - 18:00 น.';
            }

            // H. ค้นหาทั่วไปจากชื่อสินค้า/รหัส/หมวดหมู่/รายละเอียด
            const matched = productsBotData.filter(p => 
                p.name.toLowerCase().includes(txt) || 
                p.category.toLowerCase().includes(txt) || 
                p.code.toLowerCase().includes(txt) ||
                p.desc.toLowerCase().includes(txt)
            );

            if (matched.length > 0) {
                let res = `🔎 <b>พบข้อมูลสินค้าที่เกี่ยวข้อง (${matched.length} รายการ):</b><br><br>`;
                matched.slice(0, 4).forEach(p => {
                    const st = p.stock > 0 ? `<span style="color:#10b981;">(พร้อมส่ง: ${p.stock})</span>` : `<span style="color:#ef4444;">(สินค้าหมด)</span>`;
                    res += `• <b>${p.name}</b> [${p.code}]<br>&nbsp;&nbsp;หมวด: ${p.category} | ราคา: <b style="color:#d97706;">฿${p.price}</b> ${st}<br>`;
                });
                if (matched.length > 4) {
                    res += `<br><i>💡 แสดง 4 จาก ${matched.length} รายการ (พิมพ์ชื่อสินค้าให้แคบลงเพื่อค้นหาอย่างเจาะจง)</i>`;
                }
                return res;
            }

            // Default Response
            return '🤖 ขออภัยครับ AI ไม่พบข้อมูลสินค้าที่ตรงกับคำตอบ<br>ลองพิมพ์คำค้นหา เช่น:<br>• <i>"งบ 4000"</i><br>• <i>"กีตาร์โปร่ง"</i><br>• <i>"แนะนำแอมป์"</i><br>• <i>"สินค้าถูกที่สุด"</i>';
        }

        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('open_cart') === '1') {
            openCartModal();
        }
    </script>

    <!-- 📌 SWEETALERT2 NOTIFICATION FOR ORDER SUCCESS -->
    <?php if ($order_success_id > 0): ?>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                icon: 'success',
                title: 'ยืนยันคำสั่งซื้อสำเร็จ!',
                text: 'ขอบคุณสำหรับการสั่งซื้อ เลขที่คำสั่งซื้อของคุณคือ #ORD-<?php echo str_pad($order_success_id, 5, '0', STR_PAD_LEFT); ?>',
                confirmButtonText: '🧾 ดูใบเสร็จรับเงิน',
                confirmButtonColor: '#10b981',
                allowOutsideClick: false
            }).then((result) => {
                if (result.isConfirmed) {
                    openReceiptModal();
                }
            });
        });
    </script>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) &&$_GET['msg'] === 'added'): ?>
    <script>
        Swal.fire({
            icon: 'success',
            title: 'เพิ่มสินค้าลงตะกร้าแล้ว',
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 2000,
            timerProgressBar: true
        });
    </script>
    <?php endif; ?>

    <?php if (isset($_GET['msg']) &&$_GET['msg'] === 'outofstock'): ?>
    <script>
        Swal.fire({
            icon: 'error',
            title: 'สินค้าในสต็อกไม่พอ',
            text: 'ไม่สามารถเพิ่มจำนวนสินค้าเกินจำนวนสต็อกที่มีอยู่ได้',
            confirmButtonColor: '#ef4444'
        });
    </script>
    <?php endif; ?>

</body>
</html>