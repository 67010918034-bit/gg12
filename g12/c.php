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

        /* 🤖 Chatbot Window (ปรับแต่งเพื่อรองรับ iframe Dify) */
        .chatbot-window {
            display: none; position: fixed; bottom: 85px; left: 25px; width: 420px; height: 680px; max-height: calc(100vh - 120px); max-width: calc(100vw - 50px);
            background: #fff; border-radius: var(--radius-lg); box-shadow: 0 12px 35px rgba(0,0,0,0.25);
            z-index: 1000; flex-direction: column; overflow: hidden; border: 1px solid var(--border-color);
        }
        .chatbot-window.active { display: flex; }
        .chatbot-header { background: var(--primary); color: #fff; padding: 12px 18px; font-size: 14px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .close-chat-btn { background: none; border: none; color: #fff; font-size: 20px; cursor: pointer; }
        .chatbot-body-iframe { flex-grow: 1; width: 100%; height: 100%; border: none; }

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
                <span class="feature-icon">🛡️</span>
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
                <div class="section-group cat-block" data-cat="<?php echo $cat_id; ?>">
                    <div class="section-header">
                        <h2 class="section-title">🎸 <?php echo htmlspecialchars($cat_title); ?></h2>
                    </div>
                    <div class="product-grid">
                        <?php foreach ($prods as $p) { 
                            $img_src = getProductImageDirect($p);
                            $in_stock = intval($p['stock']) > 0;
                            $p_name = isset($p['product_name']) ? $p['product_name'] : (isset($p['name']) ? $p['name'] : 'สินค้า');
                            $p_code = isset($p['product_code']) ? $p['product_code'] : (isset($p['sku']) ? $p['sku'] : 'P'.$p['product_id']);
                        ?>
                            <div class="card product-card" data-name="<?php echo htmlspecialchars(mb_strtolower($p_name)); ?>">
                                <div class="card-img-box">
                                    <span class="badge-code"><?php echo htmlspecialchars($p_code); ?></span>
                                    <span class="badge-stock <?php echo $in_stock ? 'in-stock' : 'out-stock'; ?>">
                                        <?php echo $in_stock ? 'มีสินค้า ('.$p['stock'].')' : 'สินค้าหมด'; ?>
                                    </span>
                                    <img src="<?php echo htmlspecialchars($img_src); ?>" alt="<?php echo htmlspecialchars($p_name); ?>">
                                </div>
                                <div class="card-body">
                                    <h3 class="card-title"><?php echo htmlspecialchars($p_name); ?></h3>
                                    <p class="card-desc"><?php echo htmlspecialchars(isset($p['description']) ? $p['description'] : ''); ?></p>
                                    <div class="card-footer">
                                        <span class="price">฿<?php echo number_format($p['price'], 2); ?></span>
                                        <?php if ($in_stock) { ?>
                                            <a href="c.php?action=add&id=<?php echo $p['product_id']; ?>" class="btn-cart">🛒 ใส่ตะกร้า</a>
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
            <p style="text-align:center; padding: 40px; color: var(--text-muted);">ยังไม่มีรายการสินค้าในระบบ</p>
        <?php } ?>
    </div>

    <!-- 📌 FLOATING ACTION BUTTONS -->
    <button class="floating-cart-btn" onclick="openCartModal()">
        🛒 ตะกร้าสินค้า <span class="cart-badge"><?php echo $total_cart_items; ?></span>
    </button>

    <button class="floating-chat-btn" onclick="toggleChatbot()">💬 แชทสอบถาม AI</button>

    <!-- 🤖 CHATBOT WINDOW (DIFY IFRAME INTEGRATED) -->
    <div class="chatbot-window" id="chatbotWindow">
        <div class="chatbot-header">
            <span>🤖 ผู้ช่วยตอบคำถาม (AI Assistant)</span>
            <button class="close-chat-btn" onclick="toggleChatbot()">×</button>
        </div>
        <iframe
            src="https://udify.app/chatbot/MhX5u5mK0EmvvK20"
            class="chatbot-body-iframe"
            frameborder="0"
            allow="microphone;clipboard-write">
        </iframe>
    </div>

    <!-- 🛒 MODAL: ตะกร้าสินค้า & สั่งซื้อ -->
    <div class="modal-overlay" id="cartModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>🛒 ตะกร้าสินค้าของคุณ</h3>
                <button class="close-btn" onclick="closeCartModal()">×</button>
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
                                <th>จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $sum_total = 0;
                            $c_ids = array_keys($_SESSION['cart']);
                            if (!empty($c_ids)) {
                                $c_ids_str = implode(',', array_map('intval', $c_ids));
                                $cart_rs = mysqli_query($conn, "SELECT * FROM products WHERE product_id IN ($c_ids_str)");
                                while ($cp = mysqli_fetch_assoc($cart_rs)) {
                                    $cid = $cp['product_id'];
                                    $cqty = $_SESSION['cart'][$cid];
                                    $subtotal = $cp['price'] * $cqty;
                                    $sum_total += $subtotal;
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($cp['product_name']); ?></td>
                                    <td>฿<?php echo number_format($cp['price'], 2); ?></td>
                                    <td>
                                        <input type="number" name="qty[<?php echo $cid; ?>]" value="<?php echo $cqty; ?>" min="1" max="<?php echo $cp['stock']; ?>" class="qty-input">
                                    </td>
                                    <td>฿<?php echo number_format($subtotal, 2); ?></td>
                                    <td><a href="c.php?action=remove&id=<?php echo $cid; ?>" style="color:#ef4444; text-decoration:none;">ลบ</a></td>
                                </tr>
                            <?php 
                                }
                            }
                            ?>
                        </tbody>
                    </table>
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                        <button type="submit" name="update_cart" class="btn-nav-action" style="color:var(--primary); border-color:var(--border-color);">🔄 คำนวณใหม่</button>
                        <strong style="font-size:16px; color:var(--accent-orange-hover);">ยอดรวมทั้งสิ้น: ฿<?php echo number_format($sum_total, 2); ?></strong>
                    </div>
                </form>

                <form action="c.php" method="POST" class="checkout-form">
                    <h4 style="margin-bottom:10px;">📦 ข้อมูลการจัดส่งและชำระเงิน</h4>
                    <div class="form-group">
                        <label>ชื่อ-นามสกุล ผู้รับ *</label>
                        <input type="text" name="cust_name" required value="<?php echo isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']['fullname']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>เบอร์โทรศัพท์ *</label>
                        <input type="tel" name="cust_phone" required value="<?php echo isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']['phone']) : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label>ที่อยู่จัดส่ง *</label>
                        <textarea name="cust_address" rows="3" required><?php echo isset($_SESSION['user']) ? htmlspecialchars($_SESSION['user']['address']) : ''; ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>ช่องทางการชำระเงิน</label>
                        <select name="payment_method">
                            <option value="cod">🚚 เก็บเงินปลายทาง (COD)</option>
                            <option value="promptpay">📱 โอนเงินผ่านธนาคาร / QR PromptPay</option>
                            <option value="credit">💳 บัตรเครดิต / เดบิต</option>
                        </select>
                    </div>
                    <button type="submit" name="submit_order" class="btn-submit-order">✅ ยืนยันการสั่งซื้อ</button>
                </form>
            <?php } else { ?>
                <p style="text-align:center; padding:30px; color:var(--text-muted);">ไม่มีสินค้าในตะกร้า</p>
            <?php } ?>
        </div>
    </div>

    <!-- 📋 MODAL: ตรวจสอบคำสั่งซื้อ -->
    <div class="modal-overlay <?php echo $has_searched ? 'active' : ''; ?>" id="orderSearchModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📋 ตรวจสอบคำสั่งซื้อ</h3>
                <button class="close-btn" onclick="closeOrderSearchModal()">×</button>
            </div>
            <form action="c.php" method="POST" style="margin-bottom:20px;">
                <div class="form-group">
                    <label>กรอกเบอร์โทรศัพท์ หรือ เลขที่คำสั่งซื้อ</label>
                    <div style="display:flex; gap:8px;">
                        <input type="text" name="search_term" value="<?php echo htmlspecialchars($search_term_input); ?>" placeholder="เช่น 0812345678 หรือ 1001" required>
                        <button type="submit" name="action_search_order" class="btn-auth-email" style="white-space:nowrap;">ค้นหา</button>
                    </div>
                </div>
            </form>

            <?php if ($has_searched) { ?>
                <?php if (!empty($searched_orders)) { ?>
                    <?php foreach ($searched_orders as $s_ord) { ?>
                        <div class="order-history-card">
                            <div class="order-history-header">
                                <strong>คำสั่งซื้อ #<?php echo $s_ord['order_id']; ?></strong>
                                <span style="font-size:12px; color:var(--text-muted);"><?php echo $s_ord['created_at']; ?></span>
                            </div>
                            <p style="font-size:12px;"><b>ผู้รับ:</b> <?php echo htmlspecialchars($s_ord['customer_name']); ?> (<?php echo htmlspecialchars($s_ord['customer_phone']); ?>)</p>
                            <p style="font-size:12px;"><b>ที่อยู่:</b> <?php echo htmlspecialchars($s_ord['customer_address']); ?></p>
                            
                            <div class="order-items-list">
                                <?php foreach ($s_ord['items'] as $s_item) { ?>
                                    <div class="order-item-row">
                                        <span><?php echo htmlspecialchars($s_item['product_name']); ?> x <?php echo $s_item['quantity']; ?></span>
                                        <span>฿<?php echo number_format($s_item['price'] * $s_item['quantity'], 2); ?></span>
                                    </div>
                                <?php } ?>
                            </div>
                            <div style="text-align:right; font-weight:700; color:var(--accent-orange-hover);">
                                ยอดรวม: ฿<?php echo number_format($s_ord['total_amount'], 2); ?>
                            </div>
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <p style="text-align:center; padding:20px; color:var(--text-muted);">ไม่พบข้อมูลคำสั่งซื้อดังกล่าว</p>
                <?php } ?>
            <?php } ?>
        </div>
    </div>

    <!-- ✉️ MODAL: เข้าสู่ระบบด้วยอีเมล -->
    <div class="modal-overlay" id="authModal">
        <div class="modal-content" style="max-width: 400px;">
            <div class="modal-header">
                <h3>✉️ เข้าสู่ระบบ / ข้อมูลผู้ซื้อ</h3>
                <button class="close-btn" onclick="closeAuthModal()">×</button>
            </div>
            <form action="c.php" method="POST">
                <div class="form-group">
                    <label>อีเมลของคุณ *</label>
                    <input type="email" name="login_email" required placeholder="example@email.com">
                </div>
                <div class="form-group">
                    <label>ชื่อ-นามสกุล</label>
                    <input type="text" name="login_fullname" placeholder="กรอกชื่อของคุณ">
                </div>
                <div class="form-group">
                    <label>เบอร์โทรศัพท์</label>
                    <input type="tel" name="login_phone" placeholder="08X-XXX-XXXX">
                </div>
                <div class="form-group">
                    <label>ที่อยู่จัดส่งสินค้า</label>
                    <textarea name="login_address" rows="2" placeholder="ที่อยู่สำหรับจัดส่ง"></textarea>
                </div>
                <button type="submit" name="action_login_email" class="btn-submit-order">บันทึกข้อมูลเข้าสู่ระบบ</button>
            </form>
        </div>
    </div>

    <!-- 📜 SITE FOOTER -->
    <footer class="site-footer">
        <div class="footer-grid">
            <div class="footer-col">
                <h4>GUITAR REA STORE</h4>
                <p>ร้านขายกีตาร์และอุปกรณ์ดนตรีครบวงจร การันตีคุณภาพระดับพรีเมียม จัดส่งรวดเร็วทั่วประเทศ</p>
            </div>
            <div class="footer-col">
                <h4>หมวดหมู่สินค้า</h4>
                <p>• กีตาร์ไฟฟ้า</p>
                <p>• กีตาร์โปร่ง</p>
                <p>• ตู้แอมป์ & เอฟเฟกต์</p>
            </div>
            <div class="footer-col">
                <h4>ติดต่อเรา</h4>
                <p>📍 Bangkok, Thailand</p>
                <p>📞 02-XXX-XXXX</p>
                <p>✉️ support@guitarrea.com</p>
            </div>
            <div class="footer-col">
                <h4>เวลาทำการ</h4>
                <p>เปิดให้บริการทุกวัน</p>
                <p>09:00 น. - 20:00 น.</p>
            </div>
        </div>
    </footer>

    <!-- ⚙️ JAVASCRIPT LOGIC -->
    <script>
        // เปิด-ปิด Chatbot Window
        function toggleChatbot() {
            const botWin = document.getElementById('chatbotWindow');
            botWin.classList.toggle('active');
        }

        // เปิด-ปิด Cart Modal
        function openCartModal() {
            document.getElementById('cartModal').classList.add('active');
        }
        function closeCartModal() {
            document.getElementById('cartModal').classList.remove('active');
        }

        // เปิด-ปิด Auth Modal
        function openAuthModal() {
            document.getElementById('authModal').classList.add('active');
        }
        function closeAuthModal() {
            document.getElementById('authModal').classList.remove('active');
        }

        // เปิด-ปิด Order Search Modal
        function openOrderSearchModal() {
            document.getElementById('orderSearchModal').classList.add('active');
        }
        function closeOrderSearchModal() {
            document.getElementById('orderSearchModal').classList.remove('active');
        }

        // ค้นหาสินค้า Realtime
        function filterProducts() {
            const val = document.getElementById('searchInput').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.product-card');

            cards.forEach(card => {
                const pName = card.getAttribute('data-name');
                if (pName.includes(val)) {
                    card.style.display = 'flex';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // กรองหมวดหมู่สินค้า
        function filterCategory(catId, btn) {
            const pills = document.querySelectorAll('.cat-pill');
            pills.forEach(p => p.classList.remove('active'));
            btn.classList.add('active');

            const blocks = document.querySelectorAll('.cat-block');
            blocks.forEach(block => {
                if (catId === 'all' || block.getAttribute('data-cat') == catId) {
                    block.style.display = 'block';
                } else {
                    block.style.display = 'none';
                }
            });
        }

        // Hero Banner Slider Logic
        let currentSlide = 0;
        const slides = document.querySelectorAll('.hero-slide');
        if (slides.length > 0) {
            setInterval(() => {
                slides[currentSlide].classList.remove('active');
                currentSlide = (currentSlide + 1) % slides.length;
                slides[currentSlide].classList.add('active');
            }, 5000);
        }

        <?php if (isset($_GET['open_cart']) && $_GET['open_cart'] == 1) { ?>
            openCartModal();
        <?php } ?>

        <?php if ($order_success_id > 0) { ?>
            Swal.fire({
                icon: 'success',
                title: 'สั่งซื้อสำเร็จ!',
                text: 'หมายเลขคำสั่งซื้อของคุณคือ #<?php echo $order_success_id; ?>',
                confirmButtonColor: '#2563eb'
            });
        <?php } ?>
    </script>
</body>
</html>
