<?php
$conn = mysqli_connect("localhost", "root", "Wh8cCJ8#HOY!", "8034db");

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

mysqli_set_charset($conn, "utf8mb4");
?>