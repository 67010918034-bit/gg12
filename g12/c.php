<?php
session_start();
require_once 'connectdb.php';

// ปิดการโยน Exception ของ MySQLi ใน PHP 8.1+ เพื่อป้องกันปัญหา HTTP 500 เมื่อ SQL มีข้อผิดพลาด
if (function_exists('mysqli_report')) {
    mysqli_report(MYSQLI_REPORT_OFF);
}

// ---------------------------------------------------------------------
// 📌 1. ระบบเข้าสู่ระบบด้วยอีเมล + บันทึก Audit Log (LOGIN_SUCCESS)
// ---------------------------------------------------------------------
$auth_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_login_email'])) {
    $email    = filter_var(trim($_POST['login_email']), FILTER_SANITIZE_EMAIL);
    $fullname = trim($_POST['login_fullname']);
    $phone    = trim($_POST['login_phone']);
    $address  = trim($_POST['login_address']);

    if (!empty($email) && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        // เก็บข้อมูลลง Session
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

        // ค้นหาหรือบันทึกข้อมูลผู้ใช้แบบปลอดภัย (Safe Query)
        $user_val = $clean_email;
        
        try {
            $user_check = mysqli_query($conn, "SELECT * FROM users WHERE email = '$clean_email' OR username = '$clean_email' LIMIT 1");
            if ($user_check && $u_row = mysqli_fetch_assoc($user_check)) {
                $user_val = isset($u_row['user']) ? $u_row['user'] : (isset($u_row['id']) ? $u_row['id'] : $clean_email);
            } else {
                $ins_u = "INSERT INTO users (email, username, fullname, phone, address, created_at) 
                          VALUES ('$clean_email', '$clean_email', '$clean_name', '$clean_phone', '$clean_addr', NOW())";
                if (mysqli_query($conn, $ins_u)) {
                    $ins_id = mysqli_insert_id($conn);
                    $user_val = !empty($ins_id) ? $ins_id : $clean_email;
                }
            }
        } catch (Throwable $e) {
            $user_val = $clean_email;
        }

        // 📝 บันทึก Audit Log เมื่อ เข้าสู่ระบบ (LOGIN_SUCCESS)
        $event_type = 'LOGIN_SUCCESS';
        $ip_address = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR']);
        $user_agent = mysqli_real_escape_string($conn, isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown');

        try {
            $sql_log = "INSERT INTO audit_logs (user, event_type, ip_address, user_agent, created_at) 
                        VALUES ('$user_val', '$event_type', '$ip_address', '$user_agent', NOW())";
            if (!mysqli_query($conn, $sql_log)) {
                $sql_log_alt = "INSERT INTO audit_logs (user_id, event_type, ip_address, user_agent, created_at) 
                                VALUES ('$user_val', '$event_type', '$ip_address', '$user_agent', NOW())";
                mysqli_query($conn, $sql_log_alt);
            }
        } catch (Throwable $e) {
            // ป้องกันสคริปต์หยุดทำงานหากโครงสร้าง audit_logs ยังไม่สมบูรณ์
        }

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
        $ip_address = mysqli_real_escape_string($conn, $_SERVER['REMOTE_ADDR']);
        $user_agent = mysqli_real_escape_string($conn, isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Unknown');

        // 📝 บันทึก Audit Log เมื่อ ออกจากระบบ (LOGOUT)
        try {
            $sql_logout_log = "INSERT INTO audit_logs (user, event_type, ip_address, user_agent, created_at) 
                               VALUES ('$user_val', '$event_type', '$ip_address', '$user_agent', NOW())";
            if (!mysqli_query($conn, $sql_logout_log)) {
                $sql_logout_alt = "INSERT INTO audit_logs (user_id, event_type, ip_address, user_agent, created_at) 
                                   VALUES ('$user_val', '$event_type', '$ip_address', '$user_agent', NOW())";
                mysqli_query($conn, $sql_logout_alt);
            }
        } catch (Throwable $e) {
            // ป้องกันสคริปต์หยุดทำงาน
        }
    }

    // ล้างข้อมูล Session ออกจากระบบ
    unset($_SESSION['user']);
    header("Location: c.php");
    exit;
}

// ---------------------------------------------------------------------
// 📌 3. ระบบจัดการตะกร้าสินค้า (Session Cart)
// ---------------------------------------------------------------------
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// เพิ่มสินค้าลงตะกร้า
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

// ปรับจำนวน / ลบสินค้า
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

// บันทึกคำสั่งซื้อลง DB
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
