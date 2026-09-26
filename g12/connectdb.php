<?php
$host = "localhost";
$usr  = "root";
$pwd  = "";
$db   = "if0_43012835_8034db";

$conn = mysqli_connect($host, $usr, $pwd, $db) or die("เชื่อมต่อฐานข้อมูลไม่ได้: " . mysqli_connect_error());
mysqli_set_charset($conn, "utf8");
?>