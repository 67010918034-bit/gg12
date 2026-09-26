-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql303.infinityfree.com
-- Generation Time: Sep 26, 2026 at 06:50 AM
-- Server version: 11.4.13-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `if0_43012835_8034db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `admin_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`admin_id`, `name`, `email`, `password`, `created_at`) VALUES
(1, 'admin34', '67010918034@msu.ac.th', 'Kwang_67010918034.', '2026-09-25 14:48:25');

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `order_id` int(11) NOT NULL,
  `customer_name` varchar(255) NOT NULL,
  `customer_phone` varchar(50) NOT NULL,
  `customer_address` text NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `payment_method` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `orders`
--

INSERT INTO `orders` (`order_id`, `customer_name`, `customer_phone`, `customer_address`, `total_amount`, `created_at`, `payment_method`) VALUES
(1, 'กวาง', '0937762415', '465/7', '6990.00', '2026-09-25 14:41:29', NULL),
(2, 'กวาง', '0937762415', '456/8', '290.00', '2026-09-25 15:28:23', NULL),
(3, 'นารีรัตน์ จักทอน', '0937762415', '1234', '6990.00', '2026-09-25 21:23:23', 'cod'),
(4, 'นารีรัตน์ จักทอน', '0937762415', '8952', '6490.00', '2026-09-25 21:24:04', 'cod'),
(5, 'ใบกวาง', '0937762415', '000', '5990.00', '2026-09-25 21:27:48', 'cod'),
(6, 'Few', '0994658786', '324/7', '290.00', '2026-09-25 21:45:10', 'cod'),
(7, 'few', '0994658786', '825/1', '690.00', '2026-09-25 23:28:11', 'cod'),
(8, 'Hfe', '0994658786', '2818', '5380.00', '2026-09-26 00:04:17', 'cod'),
(9, 'Hfe', '0994658786', '2818', '10680.00', '2026-09-26 00:05:11', 'cod'),
(10, 'Nareerat', '0937762415', '456', '6490.00', '2026-09-26 00:12:01', 'cod'),
(11, 'Kwangja', '0937762415', 'Akkks', '5990.00', '2026-09-26 00:13:34', 'cod'),
(12, 'Sisksk', '0937762415', '234', '11980.00', '2026-09-26 00:27:14', 'cod'),
(13, 'few', '0994658786', '825/1', '7290.00', '2026-09-26 03:20:27', 'cod'),
(14, 'few', '0994658786', '825/1', '6990.00', '2026-09-26 03:29:45', 'cod'),
(15, 'few', '0994658786', '825/1', '6990.00', '2026-09-26 03:37:14', 'cod'),
(16, 'few', '0994658786', '825/1', '6990.00', '2026-09-26 03:40:09', 'cod');

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `item_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `order_items`
--

INSERT INTO `order_items` (`item_id`, `order_id`, `product_id`, `price`, `quantity`) VALUES
(1, 1, 2, '6990.00', 1),
(2, 2, 11, '290.00', 1),
(3, 3, 2, '6990.00', 1),
(4, 4, 3, '6490.00', 1),
(5, 5, 1, '5990.00', 1),
(6, 6, 11, '290.00', 1),
(7, 7, 9, '690.00', 1),
(8, 8, 9, '690.00', 1),
(9, 8, 19, '4690.00', 1),
(10, 9, 1, '5990.00', 1),
(11, 9, 19, '4690.00', 1),
(12, 10, 3, '6490.00', 1),
(13, 11, 1, '5990.00', 1),
(14, 12, 1, '5990.00', 2),
(15, 13, 4, '7290.00', 1),
(16, 14, 2, '6990.00', 1),
(17, 15, 2, '6990.00', 1),
(18, 16, 2, '6990.00', 1);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `product_name` varchar(255) NOT NULL,
  `product_code` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `stock` int(11) NOT NULL DEFAULT 0,
  `image_url` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf16 COLLATE=utf16_thai_520_w2;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `category_id`, `product_name`, `product_code`, `description`, `price`, `stock`, `image_url`, `status`, `created_at`, `updated_at`) VALUES
(1, 1, ' Electric Guitar ', 'BK-EG-001', 'กีตาร์ไฟฟ้าสีดำ เหมาะสำหรับผู้เริ่มต้นและเล่นเพลงร็อก', '5990.00', 95, '1.jpg', 'active', '2026-08-08 11:11:07', '2026-09-26 10:16:32'),
(2, 1, 'Electric Guitar Sunburst', 'BK-EG-002', 'กีตาร์ไฟฟ้าสี Sunburst ดีไซน์คลาสสิก เสียงคมชัด', '6990.00', 1, '2.jpg', 'active', '2026-08-08 11:11:07', '2026-09-26 10:40:09'),
(3, 1, ' Electric Guitar ', 'BK-EG-003', 'กีตาร์ไฟฟ้าสีขาว ดีไซน์สวย น้ำหนักเล่นง่าย', '6490.00', 3, '3.jpg', 'active', '2026-08-08 11:11:07', '2026-09-26 10:16:49'),
(4, 1, 'Electric Guitar ', 'BK-EG-004', 'กีตาร์ไฟฟ้าสีน้ำเงิน เหมาะสำหรับเพลง Rock และ Pop', '7290.00', 3, '4.jpg', 'active', '2026-08-08 11:11:07', '2026-09-26 10:20:27'),
(5, 2, ' Acoustic Guitar Natural', 'BK-AG-001', 'กีตาร์โปร่งไม้สีธรรมชาติ เสียงอบอุ่น เหมาะสำหรับมือใหม่', '4290.00', 10, '7.jpg', 'active', '2026-08-08 11:11:07', '2026-09-26 10:17:18'),
(6, 2, 'Acoustic Guitar ', 'BK-AG-002', 'กีตาร์โปร่งสีดำ ดีไซน์ทันสมัย เสียงกังวาน', '4590.00', 7, '5.jpg', 'active', '2026-08-08 11:11:07', '2026-09-26 10:17:34'),
(7, 3, ' Classical Guitar', 'BK-CG-001', 'กีตาร์คลาสสิกสายเอ็น เหมาะสำหรับการฝึกและเล่นเพลงคลาสสิก', '3890.00', 6, '13.jpg', 'active', '2026-08-08 11:11:07', '2026-09-26 10:17:43'),
(8, 4, 'Guitar Amplifier 20W', 'BK-AMP-001', 'แอมป์กีตาร์ขนาด 20 วัตต์ เหมาะสำหรับซ้อมที่บ้าน', '2490.00', 9, '17.jpg', 'active', '2026-08-08 11:11:07', '2026-09-26 10:17:54'),
(9, 5, 'กระเป๋ากีตาร์ ', 'BK-ACC-001', 'กระเป๋ากีตาร์แบบบุฟองน้ำ ป้องกันรอยขีดข่วนและแรงกระแทก', '690.00', 13, '10.jpg', 'active', '2026-08-08 11:11:07', '2026-09-26 10:18:01'),
(10, 5, 'Digital Guitar Tuner', 'BK-ACC-002', 'เครื่องตั้งสายกีตาร์ดิจิทัล ใช้งานง่ายและแม่นยำ', '390.00', 20, '21.jpg', 'active', '2026-08-08 11:11:07', '2026-09-25 05:57:11'),
(11, 5, 'สายแจ็คกีตาร์ 3 เมตร', 'BK-ACC-003', 'สายสัญญาณกีตาร์ความยาว 3 เมตร หัวแจ็คมาตรฐาน', '290.00', 23, '22.jpg', 'active', '2026-08-08 11:11:07', '2026-09-26 04:45:10'),
(12, 5, 'ชุดปิ๊กกีตาร์ 10 ชิ้น', 'BK-ACC-004', 'ปิ๊กกีตาร์หลากหลายความหนา จำนวน 10 ชิ้น', '150.00', 30, '23.jpg', 'active', '2026-08-08 11:11:07', '2026-09-25 05:57:30'),
(13, 4, 'Guitar Amplifier 40W', 'BK-AMP-002', 'แอมป์กีตาร์ไฟฟ้าขนาด 40 วัตต์ พร้อมเอฟเฟกต์ Distortion และ Reverb ในตัว เสียงดีกำลังขับสูง', '3890.00', 6, '18.jpg', 'active', '2026-09-25 05:43:34', '2026-09-26 10:18:26'),
(14, 4, ' Acoustic Amp 30W', 'BK-AMP-003', 'แอมป์กีตาร์โปร่ง 30 วัตต์ มีช่องเสียบไมค์และ Bluetooth ในตัว เหมาะสำหรับสายโฟล์คซอง', '3290.00', 5, '19.jpg', 'active', '2026-09-25 05:43:34', '2026-09-26 10:18:33'),
(15, 4, 'Portable Mini Amp 15W', 'BK-AMP-004', 'แอมป์กีตาร์พกพาขนาด 15 วัตต์ น้ำหนักเบา เสียบหูฟังซ้อมส่วนตัวได้ พกพาสะดวก', '1890.00', 10, '20.jpg', 'active', '2026-09-25 05:43:34', '2026-09-26 10:18:37'),
(16, 3, 'Classical Guitar Cedar Top', 'BK-CG-002', 'กีตาร์คลาสสิกไม้หน้าซีดาร์ เสียงอบอุ่น กังวาน นุ่มนวล เหมาะสำหรับเพลงคลาสสิก', '4290.00', 8, '14.jpg', 'active', '2026-09-25 05:45:40', '2026-09-26 10:18:43'),
(17, 3, 'Classical Guitar Spruce Top', 'BK-CG-003', 'กีตาร์คลาสสิกไม้หน้าสปรูซ เสียงใส พุ่ง กังวาน คอจับง่าย เหมาะสำหรับผู้เริ่มฝึก', '4590.00', 5, '15.jpg', 'active', '2026-09-25 05:45:40', '2026-09-26 10:18:54'),
(18, 3, ' Classical Guitar Rosewood', 'BK-CG-004', 'กีตาร์คลาสสิกไม้โรสวู้ด เสียงมีมิติ เบสลึกหนา งานประกอบปราณีตระดับพรีเมียม', '5290.00', 6, '16.jpg', 'active', '2026-09-25 05:45:40', '2026-09-26 10:18:59'),
(19, 2, ' Acoustic Guitar Mahogany', 'BK-AG-004', 'กีตาร์โปร่งไม้มาฮอกกานี เสียงนุ่มลึก อบอุ่น มีมิติ เหมาะสำหรับสาย Fingerstyle', '4690.00', 6, '6.jpg', 'active', '2026-09-25 05:49:07', '2026-09-26 10:19:17');

-- --------------------------------------------------------

--
-- Table structure for table `product_categories`
--

CREATE TABLE `product_categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf16 COLLATE=utf16_thai_520_w2;

--
-- Dumping data for table `product_categories`
--

INSERT INTO `product_categories` (`category_id`, `category_name`, `description`, `created_at`) VALUES
(1, 'กีตาร์ไฟฟ้า', 'กีตาร์ไฟฟ้าสำหรับผู้เริ่มต้นและนักดนตรี', '2026-08-08 11:10:14'),
(2, 'กีตาร์โปร่ง', 'กีตาร์โปร่งสำหรับเล่นเพลงทั่วไป', '2026-08-08 11:10:14'),
(3, 'กีตาร์คลาสสิก', 'กีตาร์สายเอ็นสำหรับเล่นเพลงคลาสสิก', '2026-08-08 11:10:14'),
(4, 'แอมป์กีตาร์', 'เครื่องขยายเสียงสำหรับกีตาร์', '2026-08-08 11:10:14'),
(5, 'อุปกรณ์เสริม', 'อุปกรณ์ต่าง ๆ สำหรับกีตาร์', '2026-08-08 11:10:14');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `address` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password`, `fullname`, `phone`, `address`, `created_at`) VALUES
(1, 'nareerat', '$2y$10$nPZxM730Dgw6hebifEYGXuztJ9Y8T.bSwc87x00wZqo7/xQ1RWCpS', 'นารีรัตน์ จักทอน', '0937762415', '465/7', '2026-09-25 15:29:47');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`order_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD UNIQUE KEY `product_code` (`product_code`),
  ADD KEY `fk_products_category` (`category_id`);

--
-- Indexes for table `product_categories`
--
ALTER TABLE `product_categories`
  ADD PRIMARY KEY (`category_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `product_categories`
--
ALTER TABLE `product_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `order_items`
--
ALTER TABLE `order_items`
  ADD CONSTRAINT `order_items_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `orders` (`order_id`) ON DELETE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `product_categories` (`category_id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
