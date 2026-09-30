<?php
session_start();
require_once 'connectdb.php';

// ปิดการโยน Exception ของ MySQLi ใน PHP 8.1+ เพื่อป้องกันปัญหา HTTP 500 เมื่อ SQL มีปัญหา
if (function_exists('mysqli_report')) {
    @mysqli_report(MYSQLI_REPORT_OFF);
}

// ---------------------------------------------------------------------
// 📌 1. ระบบเข้าสู่ระบบด้วยอีเมล + บันทึก Audit Log (LOGIN_SUCCESS)
// ---------------------------------------------------------------------
$auth_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_login_email'])) {
    $email    = filter_var(trim($_POST['login_email']), FILTER_SANITIZE_EMAIL);
    $fullname = trim($_POST['login_fullname'] ?? '');
    $phone    = trim($_POST['login_phone'] ?? '');
    $address  = trim($_POST['login_address'] ?? '');

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $_SESSION['user'] = [
            'email'    => $email,
            'fullname' => !empty($fullname) ? $fullname : explode('@', $email)[0],
            'phone'    => $phone,
            'address'  => $address
        ];

        $clean_email = mysqli_real_escape_string($conn, $email);
        $clean_name  = mysqli_real_escape_string($conn, $_SESSION['user']['fullname']);
        $clean_phone = mysqli_real_escape_string($conn, $phone);
        $clean_addr  = mysqli_real_escape_string($conn, $address);

        $user_val = $clean_email;
        
        try {
            $user_check = @mysqli_query($conn, "SELECT * FROM users WHERE email = '$clean_email' OR username = '$clean_email' LIMIT 1");
            if ($user_check && $u_row = mysqli_fetch_assoc($user_check)) {
                $user_val = isset($u_row['user']) ? $u_row['user'] : (isset($u_row['id']) ? $u_row['id'] : $clean_email);
            } else {
                $ins_u = "INSERT INTO users (email, username, fullname, phone, address, created_at) 
                          VALUES ('$clean_email', '$clean_email', '$clean_name', '$clean_phone', '$clean_addr', NOW())";
                if (@mysqli_query($conn, $ins_u)) {
                    $ins_id = mysqli_insert_id($conn);
                    $user_val = !empty($ins_id) ? $ins_id : $clean_email;
                }
            }
        } catch (Throwable $e) {
            $user_val = $clean_email;
        }

        // บันทึก Audit Log
        $event_type = 'LOGIN_SUCCESS';
        $ip_address = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $user_agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

        try {
            $sql_log = "INSERT INTO audit_logs (user, event_type, ip_address, user_agent, created_at) 
                        VALUES ('$user_val', '$event_type', '$ip_address', '$user_agent', NOW())";
            if (!@mysqli_query($conn, $sql_log)) {
                $sql_log_alt = "INSERT INTO audit_logs (user_id, event_type, ip_address, user_agent, created_at) 
                                VALUES ('$user_val', '$event_type', '$ip_address', '$user_agent', NOW())";
                @mysqli_query($conn, $sql_log_alt);
            }
        } catch (Throwable $e) {}

        header("Location: c.php");
        exit;
    } else {
        $auth_error = 'กรุณากรอกรูปแบบอีเมลให้ถูกต้อง';
    }
}

// ---------------------------------------------------------------------
// 📌 2. ระบบออกจากระบบ + บันทึก Audit Log (LOGOUT)
// ---------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] == 'logout') {
    if (isset($_SESSION['user'])) {
        $user_val   = mysqli_real_escape_string($conn, $_SESSION['user']['email']);
        $event_type = 'LOGOUT';
        $ip_address = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $user_agent = mysqli_real_escape_string($conn, $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown');

        try {
            $sql_logout_log = "INSERT INTO audit_logs (user, event_type, ip_address, user_agent, created_at) 
                               VALUES ('$user_val', '$event_type', '$ip_address', '$user_agent', NOW())";
            if (!@mysqli_query($conn, $sql_logout_log)) {
                $sql_logout_alt = "INSERT INTO audit_logs (user_id, event_type, ip_address, user_agent, created_at) 
                                   VALUES ('$user_val', '$event_type', '$ip_address', '$user_agent', NOW())";
                @mysqli_query($conn, $sql_logout_alt);
            }
        } catch (Throwable $e) {}
    }

    unset($_SESSION['user']);
    header("Location: c.php");
    exit;
}

// ---------------------------------------------------------------------
// 📌 3. ระบบตะกร้าสินค้า (Session Cart)
// ---------------------------------------------------------------------
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// เพิ่มสินค้าลงตะกร้า
if (isset($_GET['action']) && $_GET['action'] == 'add') {
    $p_id = intval($_GET['id']);
    $chk_sql = "SELECT stock, product_name FROM products WHERE product_id = $p_id";
    $chk_rs  = @mysqli_query($conn, $chk_sql);
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

// ปรับจำนวน/ลบสินค้า
if (isset($_POST['update_cart'])) {
    if (isset($_POST['qty']) && is_array($_POST['qty'])) {
        foreach ($_POST['qty'] as $p_id => $quantity) {
            $p_id = intval($p_id);
            $quantity = intval($quantity);
            if ($quantity > 0) {
                $_SESSION['cart'][$p_id] = $quantity;
            } else {
                unset($_SESSION['cart'][$p_id]);
            }
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

// บันทึกคำสั่งซื้อ
$order_success_id = 0;
if (isset($_POST['submit_order'])) {
    $cust_name      = mysqli_real_escape_string($conn, $_POST['cust_name'] ?? '');
    $cust_phone     = mysqli_real_escape_string($conn, $_POST['cust_phone'] ?? '');
    $cust_address   = mysqli_real_escape_string($conn, $_POST['cust_address'] ?? '');
    $payment_method = mysqli_real_escape_string($conn, $_POST['payment_method'] ?? 'cod');

    if (!empty($_SESSION['cart']) && !empty($cust_name) && !empty($cust_phone)) {
        $cart_ids = array_keys($_SESSION['cart']);
        $ids_str  = implode(',', array_map('intval', $cart_ids));
        
        $total_amount = 0;
        $order_items  = [];

        if (!empty($ids_str)) {
            $sql_p = "SELECT product_id, price, stock FROM products WHERE product_id IN ($ids_str)";
            $rs_p  = @mysqli_query($conn, $sql_p);
            if ($rs_p) {
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
        }

        $sql_ord = "INSERT INTO orders (customer_name, customer_phone, customer_address, payment_method, total_amount, created_at) 
                    VALUES ('$cust_name', '$cust_phone', '$cust_address', '$payment_method', '$total_amount', NOW())";
        
        $order_res = @mysqli_query($conn, $sql_ord);
        if (!$order_res) {
            $sql_ord = "INSERT INTO orders (customer_name, customer_phone, customer_address, total_amount, created_at) 
                        VALUES ('$cust_name', '$cust_phone', '$cust_address', '$total_amount', NOW())";
            $order_res = @mysqli_query($conn, $sql_ord);
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
// 📌 4. ระบบค้นหาคำสั่งซื้อ
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
        $rs_s = @mysqli_query($conn, $sql_s);
        
        if ($rs_s && mysqli_num_rows($rs_s) > 0) {
            while ($ord_row = mysqli_fetch_assoc($rs_s)) {
                $o_id = $ord_row['order_id'];
                
                $sql_items = "SELECT oi.*, p.product_name, p.image_url 
                              FROM order_items oi 
                              LEFT JOIN products p ON oi.product_id = p.product_id 
                              WHERE oi.order_id = $o_id";
                $rs_items = @mysqli_query($conn, $sql_items);
                
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
// 📌 5. ดึงข้อมูลใบเสร็จ
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
    $rs_r = @mysqli_query($conn, $sql_r);
    if ($rs_r && $receipt_data = mysqli_fetch_assoc($rs_r)) {
        $sql_ri = "SELECT oi.*, p.product_name 
                   FROM order_items oi 
                   LEFT JOIN products p ON oi.product_id = p.product_id 
                   WHERE oi.order_id = $show_receipt_id";
        $rs_ri = @mysqli_query($conn, $sql_ri);
        if ($rs_ri) {
            while ($row_i = mysqli_fetch_assoc($rs_ri)) {
                $receipt_items[] = $row_i;
            }
        }
    }
}

// ---------------------------------------------------------------------
// 📌 6. ฟังก์ชันดึงรูปภาพ
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
$rs_prod = @mysqli_query($conn, $sql_prod);

$grouped_products = [];
if ($rs_prod && mysqli_num_rows($rs_prod) > 0) {
    while ($prod = mysqli_fetch_assoc($rs_prod)) {
        $cat_id = $prod['category_id'] ?? 1;
        $grouped_products[$cat_id][] = $prod;
    }
}

$total_cart_items = array_sum($_SESSION['cart'] ?? []);
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>G12 Guitar Store - ร้านขายเครื่องดนตรีครบวงจร</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- FontAwesome CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Kanit', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 text-gray-800 min-h-screen flex flex-col">

    <!-- 🌐 Header & Navigation Bar -->
    <header class="bg-slate-900 text-white sticky top-0 z-40 shadow-lg">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-20 flex items-center justify-between">
            <a href="c.php" class="flex items-center gap-3">
                <div class="bg-amber-500 text-slate-900 p-2.5 rounded-xl font-bold text-2xl flex items-center justify-center">
                    <i class="fa-solid fa-guitar"></i>
                </div>
                <div>
                    <span class="text-2xl font-extrabold tracking-wider text-amber-500">G12</span>
                    <span class="text-xl font-medium text-slate-200">MUSIC</span>
                </div>
            </a>

            <div class="flex items-center gap-3 sm:gap-4">
                <!-- ปุ่มค้นหาออเดอร์ -->
                <button onclick="document.getElementById('searchModal').classList.remove('hidden')" class="bg-slate-800 hover:bg-slate-700 text-slate-200 px-3.5 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2 border border-slate-700">
                    <i class="fa-solid fa-magnifying-glass text-amber-500"></i>
                    <span class="hidden md:inline">เช็คสถานะออเดอร์</span>
                </button>

                <!-- สถานะเข้าสู่ระบบ -->
                <?php if (isset($_SESSION['user'])): ?>
                    <div class="flex items-center gap-2 bg-slate-800 px-3 py-1.5 rounded-lg border border-slate-700 text-sm">
                        <i class="fa-solid fa-circle-user text-emerald-400 text-lg"></i>
                        <span class="font-medium max-w-[120px] truncate text-slate-200"><?= htmlspecialchars($_SESSION['user']['fullname']) ?></span>
                        <a href="c.php?action=logout" class="text-rose-400 hover:text-rose-300 ml-1 font-bold" title="ออกจากระบบ">
                            <i class="fa-solid fa-right-from-bracket"></i>
                        </a>
                    </div>
                <?php else: ?>
                    <button onclick="document.getElementById('loginModal').classList.remove('hidden')" class="bg-slate-800 hover:bg-slate-700 text-amber-400 px-3.5 py-2 rounded-lg text-sm font-medium transition flex items-center gap-2 border border-slate-700">
                        <i class="fa-solid fa-user"></i>
                        <span class="hidden sm:inline">เข้าสู่ระบบ</span>
                    </button>
                <?php endif; ?>

                <!-- ปุ่มตะกร้าสินค้า -->
                <button onclick="document.getElementById('cartDrawer').classList.remove('translate-x-full')" class="relative bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold px-4 py-2 rounded-lg transition flex items-center gap-2 shadow-md">
                    <i class="fa-solid fa-cart-shopping text-lg"></i>
                    <span class="hidden sm:inline">ตะกร้า</span>
                    <?php if ($total_cart_items > 0): ?>
                        <span class="bg-rose-600 text-white text-xs font-bold px-2 py-0.5 rounded-full shadow"><?= $total_cart_items ?></span>
                    <?php endif; ?>
                </button>
            </div>
        </div>
    </header>

    <!-- 📢 Alert Messages -->
    <?php if (isset($_GET['msg']) && $_GET['msg'] == 'added'): ?>
        <div id="alert-box" class="bg-emerald-500 text-white px-4 py-3 shadow-lg text-center font-medium flex items-center justify-center gap-2 sticky top-20 z-30">
            <i class="fa-solid fa-circle-check text-xl"></i> เพิ่มสินค้าลงในตะกร้าเรียบร้อยแล้ว!
            <button onclick="this.parentElement.remove()" class="ml-4 font-bold text-lg">&times;</button>
        </div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] == 'outofstock'): ?>
        <div id="alert-box" class="bg-rose-500 text-white px-4 py-3 shadow-lg text-center font-medium flex items-center justify-center gap-2 sticky top-20 z-30">
            <i class="fa-solid fa-circle-xmark text-xl"></i> ขออภัย สินค้าในสต็อกไม่เพียงพอ!
            <button onclick="this.parentElement.remove()" class="ml-4 font-bold text-lg">&times;</button>
        </div>
    <?php endif; ?>

    <!-- 🎸 Hero Banner -->
    <div class="bg-gradient-to-r from-slate-900 via-slate-800 to-amber-950 text-white py-12 sm:py-16 px-4 shadow-inner">
        <div class="max-w-7xl mx-auto text-center space-y-4">
            <h1 class="text-3xl sm:text-5xl font-extrabold tracking-tight text-white">
                ค้นพบเสียงเพลงในแบบของคุณ
            </h1>
            <p class="text-slate-300 text-base sm:text-lg max-w-2xl mx-auto font-light">
                จำหน่ายกีตาร์ไฟฟ้า กีตาร์โปร่ง ตู้แอมป์ และอุปกรณ์ดนตรีคุณภาพสูง จากแบรนด์ชั้นนำระดับโลก
            </p>
            <div class="pt-2">
                <a href="#cat-group" class="inline-flex items-center gap-2 bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold px-6 py-3 rounded-xl transition shadow-lg transform hover:-translate-y-0.5">
                    <i class="fa-solid fa-guitar"></i> เลือกชมสินค้าทั้งหมด
                </a>
            </div>
        </div>
    </div>

    <!-- 🛍️ Main Product Section -->
    <main id="cat-group" class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 flex-1 space-y-12">
        
        <!-- รายการค้นหาออเดอร์ (ถ้ามีการค้นหา) -->
        <?php if ($has_searched): ?>
            <section class="bg-white p-6 rounded-2xl shadow-md border border-amber-200 space-y-4">
                <div class="flex items-center justify-between border-b pb-4">
                    <h2 class="text-xl font-bold text-slate-800 flex items-center gap-2">
                        <i class="fa-solid fa-box-archive text-amber-500"></i>
                        ผลการค้นหาคำสั่งซื้อสำหรับ: "<span class="text-amber-600"><?= htmlspecialchars($search_term_input) ?></span>"
                    </h2>
                    <a href="c.php" class="text-sm text-slate-500 hover:text-slate-800 underline">ล้างการค้นหา</a>
                </div>

                <?php if (!empty($searched_orders)): ?>
                    <div class="space-y-4">
                        <?php foreach ($searched_orders as $ord): ?>
                            <div class="border rounded-xl p-4 bg-slate-50 space-y-3">
                                <div class="flex flex-wrap justify-between items-center gap-2 border-b pb-3">
                                    <div>
                                        <span class="font-bold text-slate-800">ออเดอร์ #<?= $ord['order_id'] ?></span>
                                        <span class="text-xs text-slate-500 ml-2">(<?= $ord['created_at'] ?>)</span>
                                    </div>
                                    <div class="text-right">
                                        <span class="text-xs text-slate-500">ยอดรวม:</span>
                                        <span class="font-bold text-amber-600 text-lg">฿<?= number_format($ord['total_amount'], 2) ?></span>
                                    </div>
                                </div>
                                <div class="text-sm text-slate-600">
                                    <p><strong>ชื่อผู้รับ:</strong> <?= htmlspecialchars($ord['customer_name']) ?> | <strong>โทร:</strong> <?= htmlspecialchars($ord['customer_phone']) ?></p>
                                    <p><strong>ที่อยู่จัดส่ง:</strong> <?= htmlspecialchars($ord['customer_address']) ?></p>
                                </div>
                                <div class="pt-2">
                                    <a href="c.php?receipt_id=<?= $ord['order_id'] ?>" class="inline-flex items-center gap-1.5 text-xs font-bold text-slate-700 bg-slate-200 hover:bg-slate-300 px-3 py-1.5 rounded-lg transition">
                                        <i class="fa-solid fa-receipt text-amber-600"></i> ดูใบเสร็จรับเงิน
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="text-slate-500 text-center py-6">ไม่พบข้อมูลคำสั่งซื้อที่ตรงกับหมายเลขโทรศัพท์หรือรหัสออเดอร์นี้</p>
                <?php endif; ?>
            </section>
        <?php endif; ?>

        <!-- แสดงสินค้าแบ่งตามหมวดหมู่ -->
        <?php foreach ($categories as $cat_id => $cat_info): ?>
            <?php if (!empty($grouped_products[$cat_id])): ?>
                <section class="space-y-6">
                    <div class="border-l-4 border-amber-500 pl-4 py-1">
                        <h2 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($cat_info['category_name']) ?></h2>
                        <p class="text-sm text-slate-500 font-light"><?= htmlspecialchars($cat_info['description']) ?></p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-6">
                        <?php foreach ($grouped_products[$cat_id] as $p): ?>
                            <?php 
                                $img_src = getProductImageDirect($p);
                                $in_stock = intval($p['stock']) > 0;
                            ?>
                            <div class="bg-white rounded-2xl shadow-sm hover:shadow-md transition border border-slate-200 overflow-hidden flex flex-col justify-between group">
                                <div class="relative aspect-square overflow-hidden bg-slate-100 flex items-center justify-center p-4">
                                    <img src="<?= htmlspecialchars($img_src) ?>" alt="<?= htmlspecialchars($p['product_name']) ?>" class="object-contain h-full w-full group-hover:scale-105 transition duration-300">
                                    <?php if (!$in_stock): ?>
                                        <div class="absolute inset-0 bg-slate-900/60 flex items-center justify-center">
                                            <span class="bg-rose-600 text-white font-bold text-xs px-3 py-1 rounded-full uppercase tracking-wider">สินค้าหมด</span>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="p-5 flex-1 flex flex-col justify-between space-y-4">
                                    <div>
                                        <h3 class="font-bold text-slate-800 text-base line-clamp-2 leading-snug group-hover:text-amber-600 transition">
                                            <?= htmlspecialchars($p['product_name']) ?>
                                        </h3>
                                        <p class="text-xs text-slate-400 mt-1">คงเหลือ: <?= intval($p['stock']) ?> ชิ้น</p>
                                    </div>

                                    <div class="flex items-center justify-between pt-2 border-t border-slate-100">
                                        <div>
                                            <span class="text-xs text-slate-400 block">ราคา</span>
                                            <span class="text-xl font-extrabold text-amber-600">฿<?= number_format($p['price'], 2) ?></span>
                                        </div>

                                        <?php if ($in_stock): ?>
                                            <a href="c.php?action=add&id=<?= $p['product_id'] ?>" class="bg-slate-900 hover:bg-amber-500 hover:text-slate-950 text-white font-medium p-2.5 rounded-xl transition flex items-center justify-center shadow" title="เพิ่มลงตะกร้า">
                                                <i class="fa-solid fa-cart-plus text-base"></i>
                                            </a>
                                        <?php else: ?>
                                            <button disabled class="bg-slate-200 text-slate-400 font-medium p-2.5 rounded-xl cursor-not-allowed">
                                                <i class="fa-solid fa-cart-plus text-base"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        <?php endforeach; ?>

    </main>

    <!-- 🛒 Shopping Cart Drawer (สไลด์ด้านขวา) -->
    <div id="cartDrawer" class="fixed inset-0 z-50 overflow-hidden transform translate-x-full transition-transform duration-300 ease-in-out">
        <div class="absolute inset-0 bg-slate-900/50 backdrop-blur-sm" onclick="document.getElementById('cartDrawer').classList.add('translate-x-full')"></div>

        <div class="absolute inset-y-0 right-0 max-w-full flex pl-10">
            <div class="w-screen max-w-md bg-white shadow-2xl flex flex-col">
                <div class="p-5 bg-slate-900 text-white flex items-center justify-between">
                    <h2 class="text-lg font-bold flex items-center gap-2">
                        <i class="fa-solid fa-cart-shopping text-amber-500"></i> ตะกร้าสินค้าของคุณ
                    </h2>
                    <button onclick="document.getElementById('cartDrawer').classList.add('translate-x-full')" class="text-slate-400 hover:text-white text-xl font-bold">
                        &times;
                    </button>
                </div>

                <div class="p-5 flex-1 overflow-y-auto space-y-4">
                    <?php if (!empty($_SESSION['cart'])): ?>
                        <form action="c.php" method="POST" id="cartForm">
                            <input type="hidden" name="update_cart" value="1">
                            <div class="space-y-3">
                                <?php 
                                    $cart_subtotal = 0;
                                    $cart_ids = array_keys($_SESSION['cart']);
                                    $ids_str = implode(',', array_map('intval', $cart_ids));
                                    
                                    if (!empty($ids_str)) {
                                        $sql_c = "SELECT * FROM products WHERE product_id IN ($ids_str)";
                                        $rs_c = @mysqli_query($conn, $sql_c);
                                        if ($rs_c) {
                                            while ($cp = mysqli_fetch_assoc($rs_c)):
                                                $pid = $cp['product_id'];
                                                $qty = $_SESSION['cart'][$pid];
                                                $line_total = $cp['price'] * $qty;
                                                $cart_subtotal += $line_total;
                                ?>
                                                <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl border border-slate-200">
                                                    <img src="<?= htmlspecialchars(getProductImageDirect($cp)) ?>" class="w-14 h-14 object-contain rounded-lg bg-white p-1 border">
                                                    <div class="flex-1 min-w-0">
                                                        <h4 class="font-bold text-sm text-slate-800 truncate"><?= htmlspecialchars($cp['product_name']) ?></h4>
                                                        <p class="text-xs text-amber-600 font-bold">฿<?= number_format($cp['price'], 2) ?></p>
                                                        <div class="flex items-center gap-2 mt-1">
                                                            <span class="text-xs text-slate-500">จำนวน:</span>
                                                            <input type="number" name="qty[<?= $pid ?>]" value="<?= $qty ?>" min="0" max="<?= $cp['stock'] ?>" class="w-14 px-2 py-0.5 border rounded text-xs text-center font-bold">
                                                        </div>
                                                    </div>
                                                    <a href="c.php?action=remove&id=<?= $pid ?>" class="text-rose-500 hover:text-rose-700 p-1" title="ลบรายการ">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </a>
                                                </div>
                                <?php 
                                            endwhile;
                                        }
                                    }
                                ?>
                            </div>
                            <div class="mt-3 text-right">
                                <button type="submit" class="text-xs text-slate-600 hover:text-slate-900 underline font-medium">คำนวณราคาใหม่</button>
                            </div>
                        </form>

                        <!-- ฟอร์มกรอกข้อมูลสั่งซื้อ -->
                        <form action="c.php" method="POST" class="border-t pt-4 space-y-3 mt-4">
                            <h3 class="font-bold text-slate-800 text-sm flex items-center gap-1.5">
                                <i class="fa-solid fa-truck text-amber-500"></i> ข้อมูลการจัดส่ง
                            </h3>
                            <div>
                                <label class="block text-xs font-medium text-slate-600">ชื่อ-นามสกุล ผู้รับ *</label>
                                <input type="text" name="cust_name" required value="<?= htmlspecialchars($_SESSION['user']['fullname'] ?? '') ?>" class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-amber-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600">เบอร์โทรศัพท์ *</label>
                                <input type="tel" name="cust_phone" required value="<?= htmlspecialchars($_SESSION['user']['phone'] ?? '') ?>" class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-amber-500 outline-none">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600">ที่อยู่จัดส่ง *</label>
                                <textarea name="cust_address" required rows="2" class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-amber-500 outline-none"><?= htmlspecialchars($_SESSION['user']['address'] ?? '') ?></textarea>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-slate-600 mb-1">ช่องทางการชำระเงิน</label>
                                <select name="payment_method" class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-amber-500 outline-none bg-white">
                                    <option value="cod">เก็บเงินปลายทาง (COD)</option>
                                    <option value="promptpay">โอนผ่าน PromptPay / ธนาคาร</option>
                                </select>
                            </div>

                            <div class="border-t pt-3 flex justify-between items-center text-lg font-bold">
                                <span>ยอดรวมทั้งหมด:</span>
                                <span class="text-amber-600">฿<?= number_format($cart_subtotal, 2) ?></span>
                            </div>

                            <button type="submit" name="submit_order" class="w-full bg-amber-500 hover:bg-amber-600 text-slate-950 font-extrabold py-3 rounded-xl transition shadow-lg flex items-center justify-center gap-2">
                                <i class="fa-solid fa-check-circle"></i> ยืนยันสั่งซื้อสินค้า
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="text-center py-12 text-slate-400 space-y-3">
                            <i class="fa-solid fa-cart-flatbed text-5xl"></i>
                            <p>ไม่มีสินค้าในตะกร้าของคุณ</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- 🔑 Login Modal -->
    <div id="loginModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl relative space-y-4">
            <button onclick="document.getElementById('loginModal').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 text-xl font-bold">&times;</button>
            
            <div class="text-center space-y-1">
                <h3 class="text-xl font-bold text-slate-800">เข้าสู่ระบบ / ลงทะเบียน</h3>
                <p class="text-xs text-slate-500">กรอกอีเมลเพื่อเข้าสู่ระบบสั่งซื้อสินค้า</p>
            </div>

            <?php if (!empty($auth_error)): ?>
                <div class="bg-rose-100 text-rose-700 text-xs p-2.5 rounded-lg border border-rose-200">
                    <?= htmlspecialchars($auth_error) ?>
                </div>
            <?php endif; ?>

            <form action="c.php" method="POST" class="space-y-3">
                <input type="hidden" name="action_login_email" value="1">
                <div>
                    <label class="block text-xs font-medium text-slate-700">อีเมล (Email) *</label>
                    <input type="email" name="login_email" required placeholder="example@email.com" class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-amber-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700">ชื่อ-นามสกุล</label>
                    <input type="text" name="login_fullname" placeholder="สมชาย ใจดี" class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-amber-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700">เบอร์โทรศัพท์</label>
                    <input type="tel" name="login_phone" placeholder="0812345678" class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-amber-500 outline-none">
                </div>
                <div>
                    <label class="block text-xs font-medium text-slate-700">ที่อยู่จัดส่ง</label>
                    <textarea name="login_address" rows="2" placeholder="ที่อยู่..." class="w-full px-3 py-2 text-sm border rounded-lg focus:ring-2 focus:ring-amber-500 outline-none"></textarea>
                </div>

                <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-amber-400 font-bold py-2.5 rounded-xl transition shadow">
                    เข้าสู่ระบบ
                </button>
            </form>
        </div>
    </div>

    <!-- 🔍 Order Search Modal -->
    <div id="searchModal" class="fixed inset-0 z-50 hidden bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl relative space-y-4">
            <button onclick="document.getElementById('searchModal').classList.add('hidden')" class="absolute top-4 right-4 text-slate-400 hover:text-slate-600 text-xl font-bold">&times;</button>

            <div class="text-center space-y-1">
                <h3 class="text-xl font-bold text-slate-800">เช็คสถานะออเดอร์</h3>
                <p class="text-xs text-slate-500">กรอกเบอร์โทรศัพท์ หรือรหัสออเดอร์เพื่อค้นหา</p>
            </div>

            <form action="c.php" method="POST" class="space-y-3">
                <input type="hidden" name="action_search_order" value="1">
                <div>
                    <input type="text" name="search_term" required placeholder="เช่น 0812345678 หรือ 101" class="w-full px-3 py-2.5 text-sm border rounded-lg focus:ring-2 focus:ring-amber-500 outline-none text-center font-bold">
                </div>
                <button type="submit" class="w-full bg-amber-500 hover:bg-amber-600 text-slate-950 font-bold py-2.5 rounded-xl transition shadow">
                    ค้นหาออเดอร์
                </button>
            </form>
        </div>
    </div>

    <!-- 🧾 Receipt Modal (แสดงเมื่อมีคำสั่งซื้อสำเร็จ) -->
    <?php if ($receipt_data): ?>
        <div class="fixed inset-0 z-50 bg-slate-900/70 backdrop-blur-sm flex items-center justify-center p-4">
            <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
                <div class="text-center border-b pb-4 space-y-1">
                    <span class="bg-emerald-100 text-emerald-800 text-xs font-bold px-3 py-1 rounded-full">สั่งซื้อสำเร็จแล้ว</span>
                    <h2 class="text-2xl font-black text-slate-800 pt-1">ใบเสร็จรับเงิน</h2>
                    <p class="text-xs text-slate-500">หมายเลขออเดอร์ #<?= $receipt_data['order_id'] ?> | <?= $receipt_data['created_at'] ?></p>
                </div>

                <div class="text-xs text-slate-600 space-y-1 bg-slate-50 p-3 rounded-lg">
                    <p><strong>ชื่อผู้สั่งซื้อ:</strong> <?= htmlspecialchars($receipt_data['customer_name']) ?></p>
                    <p><strong>เบอร์โทรศัพท์:</strong> <?= htmlspecialchars($receipt_data['customer_phone']) ?></p>
                    <p><strong>ที่อยู่จัดส่ง:</strong> <?= htmlspecialchars($receipt_data['customer_address']) ?></p>
                </div>

                <div class="space-y-2 border-t pt-3">
                    <h4 class="font-bold text-xs text-slate-700">รายการสินค้า</h4>
                    <?php foreach ($receipt_items as $ri): ?>
                        <div class="flex justify-between items-center text-xs text-slate-700">
                            <span><?= htmlspecialchars($ri['product_name']) ?> x <?= $ri['quantity'] ?></span>
                            <span class="font-bold">฿<?= number_format($ri['price'] * $ri['quantity'], 2) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="border-t pt-3 flex justify-between items-center font-bold text-slate-900 text-base">
                    <span>ยอดรวมสุทธิ:</span>
                    <span class="text-amber-600">฿<?= number_format($receipt_data['total_amount'], 2) ?></span>
                </div>

                <div class="pt-2 flex gap-2">
                    <button onclick="window.print()" class="flex-1 bg-slate-200 hover:bg-slate-300 text-slate-800 font-bold py-2 rounded-xl text-xs transition">
                        <i class="fa-solid fa-print"></i> พิมพ์ใบเสร็จ
                    </button>
                    <a href="c.php" class="flex-1 text-center bg-slate-900 hover:bg-slate-800 text-white font-bold py-2 rounded-xl text-xs transition">
                        ปิดหน้าต่าง
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- 🦶 Footer -->
    <footer class="bg-slate-900 text-slate-400 text-xs py-8 border-t border-slate-800 mt-12">
        <div class="max-w-7xl mx-auto px-4 text-center space-y-2">
            <p class="font-medium text-slate-300">© 2026 G12 Guitar Store. All rights reserved.</p>
            <p>ระบบร้านขายเครื่องดนตรีออนไลน์ พัฒนาด้วย PHP & MySQL</p>
        </div>
    </footer>

    <!-- เปิด Cart Drawer ถ้าระบุ open_cart -->
    <?php if (isset($_GET['open_cart'])): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                document.getElementById('cartDrawer').classList.remove('translate-x-full');
            });
        </script>
    <?php endif; ?>

</body>
</html>
