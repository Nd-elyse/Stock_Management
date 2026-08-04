-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 29, 2026 at 02:08 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `management`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `resource_type` varchar(50) DEFAULT NULL,
  `resource_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `username`, `action`, `resource_type`, `resource_id`, `details`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 1, 'admin', 'login_otp_sent', NULL, NULL, 'OTP sent to email', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 14:24:28'),
(2, 1, 'admin', 'login_otp_sent', NULL, NULL, 'OTP sent to email', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 14:26:01'),
(3, 1, 'admin', 'login_otp_sent', NULL, NULL, 'OTP sent to email', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 14:30:47'),
(4, 2, 'reception', 'login_otp_sent', NULL, NULL, 'OTP sent to email', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 14:31:31'),
(5, 1, 'admin', 'login_otp_sent', NULL, NULL, 'OTP sent to email', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 14:36:19'),
(6, 1, 'admin', 'login_otp_sent', NULL, NULL, 'OTP sent to email', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 14:38:30'),
(7, 1, 'admin', 'login_otp_sent', NULL, NULL, 'OTP sent to email', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 14:39:25'),
(8, 1, 'admin', 'login_otp_sent', NULL, NULL, 'OTP sent to email', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 14:39:39'),
(9, 1, 'admin', 'login_otp_sent', NULL, NULL, 'OTP sent to email', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/150.0.0.0 Safari/537.36', '2026-07-27 14:42:44');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `CategoryID` int(11) NOT NULL,
  `CategoryName` varchar(100) DEFAULT NULL,
  `Description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`CategoryID`, `CategoryName`, `Description`) VALUES
(1, 'Brake', 'Brake pads, discs, calipers'),
(2, 'Filters', 'Oil, air, fuel filters'),
(3, 'Ignition1', 'Spark plugs, coils'),
(7, 'Brake1', 'Brake pads, discs, calipers, screws');

-- --------------------------------------------------------

--
-- Table structure for table `contactmessages`
--

CREATE TABLE `contactmessages` (
  `MessageID` int(11) NOT NULL,
  `FullName` varchar(100) NOT NULL,
  `Email` varchar(100) NOT NULL,
  `Phone` varchar(20) DEFAULT NULL,
  `Subject` varchar(150) DEFAULT NULL,
  `Message` text NOT NULL,
  `IsRead` tinyint(1) DEFAULT 0,
  `CreatedAt` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `contactmessages`
--

INSERT INTO `contactmessages` (`MessageID`, `FullName`, `Email`, `Phone`, `Subject`, `Message`, `IsRead`, `CreatedAt`) VALUES
(2, 'Ndayambaje Elyse', 'elysend69@gmail.com', '+250 792257855', 'Technical Support', 'hello guy', 1, '2026-07-23 14:28:17'),
(4, 'Ndayambaje Elyse', 'elysend69@gmail.com', '+250 792257855', 'Technical Support', 'Thank you God', 0, '2026-07-29 13:21:24');

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `CustomerID` int(11) NOT NULL,
  `FullName` varchar(100) NOT NULL,
  `Phone` varchar(20) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `Address` varchar(200) DEFAULT NULL,
  `RegistrationDate` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`CustomerID`, `FullName`, `Phone`, `Email`, `Address`, `RegistrationDate`) VALUES
(1, 'John Doe', '+250788123456', 'john@example.com', 'KN 5 Ave, Kigali', '2026-01-15'),
(2, 'Jane Smith', '+250722456789', 'jane@example.com', 'KG 12 St, Kigali', '2026-02-20'),
(3, 'Paul Kagame', '+250788789012', 'paul@example.com', 'KN 3 Rd, Kigali', '2026-03-10'),
(4, 'Marie Rose', '+250722321654', 'marie@example.com', 'KG 7 Ave, Kigali', '2026-04-05');

-- --------------------------------------------------------

--
-- Table structure for table `diagnostics`
--

CREATE TABLE `diagnostics` (
  `DiagnosticID` int(11) NOT NULL,
  `JobID` int(11) NOT NULL,
  `MechanicID` int(11) NOT NULL,
  `DiagnosticDate` date NOT NULL,
  `Notes` text NOT NULL,
  `Recommendation` varchar(100) DEFAULT NULL,
  `EstimatedCost` decimal(10,2) DEFAULT NULL,
  `CreatedAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoiceitems`
--

CREATE TABLE `invoiceitems` (
  `InvoiceItemID` int(11) NOT NULL,
  `InvoiceID` int(11) DEFAULT NULL,
  `SparePartID` int(11) DEFAULT NULL,
  `Quantity` int(11) DEFAULT NULL,
  `Price` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoiceitems`
--

INSERT INTO `invoiceitems` (`InvoiceItemID`, `InvoiceID`, `SparePartID`, `Quantity`, `Price`) VALUES
(2, 2, NULL, 1, 20000.00),
(3, 3, NULL, 1, 12000.00),
(4, 4, 1, 2, 30000.00);

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `InvoiceID` int(11) NOT NULL,
  `CustomerID` int(11) DEFAULT NULL,
  `JobID` int(11) DEFAULT NULL,
  `InvoiceDate` date DEFAULT NULL,
  `TotalAmount` decimal(10,2) DEFAULT NULL,
  `LabourCharges` decimal(10,2) DEFAULT 0.00,
  `SparePartsCost` decimal(10,2) DEFAULT 0.00,
  `Taxes` decimal(10,2) DEFAULT 0.00,
  `Discounts` decimal(10,2) DEFAULT 0.00,
  `TaxRate` decimal(5,2) DEFAULT 18.00,
  `DiscountRate` decimal(5,2) DEFAULT 0.00,
  `VehicleID` int(11) DEFAULT NULL
) ;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`InvoiceID`, `CustomerID`, `JobID`, `InvoiceDate`, `TotalAmount`, `LabourCharges`, `SparePartsCost`, `Taxes`, `Discounts`, `TaxRate`, `DiscountRate`, `VehicleID`) VALUES
(2, 2, 2, '2026-07-08', 41300.00, 0.00, 0.00, 0.00, 0.00, 18.00, 0.00, NULL),
(3, 3, 3, '2026-06-28', 19060.00, 0.00, 0.00, 0.00, 0.00, 18.00, 0.00, NULL),
(4, 4, 4, '2026-07-13', 524995.75, 500000.00, 25000.10, 0.90, 5.25, 18.00, 1.00, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `jobhistory`
--

CREATE TABLE `jobhistory` (
  `HistoryID` int(11) NOT NULL,
  `JobID` int(11) NOT NULL,
  `PreviousStatus` enum('Pending','Diagnosed','In Progress','Awaiting Parts','Ready','Ready for Collection','Delivered','Cancelled') DEFAULT NULL,
  `NewStatus` enum('Pending','Diagnosed','In Progress','Awaiting Parts','Ready','Ready for Collection','Delivered','Cancelled') NOT NULL,
  `MechanicID` int(11) DEFAULT NULL,
  `MechanicName` varchar(100) DEFAULT NULL,
  `ChangedByUserID` int(11) DEFAULT NULL,
  `ChangedAt` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mechanics`
--

CREATE TABLE `mechanics` (
  `MechanicID` int(11) NOT NULL,
  `FullName` varchar(100) DEFAULT NULL,
  `Phone` varchar(20) DEFAULT NULL,
  `Specialization` varchar(100) DEFAULT NULL,
  `Salary` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `mechanics`
--

INSERT INTO `mechanics` (`MechanicID`, `FullName`, `Phone`, `Specialization`, `Salary`) VALUES
(6, 'Ndayambaje Elyse', '+250788300002', 'Brakes & Suspension', 40000000.00),
(7, 'James Habimana', '+250788100001', 'Brakes & Suspension', 99999999.99),
(8, 'Mugisha kai', '+250788555000', 'Electrical', 5000000.00);

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `NotificationID` int(11) NOT NULL,
  `UserID` int(11) DEFAULT NULL,
  `Type` varchar(50) DEFAULT NULL,
  `Message` text DEFAULT NULL,
  `IsRead` tinyint(1) DEFAULT 0,
  `Link` varchar(255) DEFAULT NULL,
  `CreatedAt` datetime DEFAULT current_timestamp(),
  `Status` enum('Pending','Resolved') DEFAULT NULL,
  `ResolvedAt` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`NotificationID`, `UserID`, `Type`, `Message`, `IsRead`, `Link`, `CreatedAt`, `Status`, `ResolvedAt`) VALUES
(2, 1, 'job', 'New repair job #5 has been created.', 1, '?tab=jobs', '2026-07-22 09:33:40', NULL, NULL),
(4, 1, 'payment', 'Payment of 45,000 RWF received from John Doe.', 1, '?tab=payments', '2026-07-22 07:33:40', NULL, NULL),
(7, 1, 'job', 'Password reset request from elyse', 1, '#settings', '2026-07-23 14:26:34', NULL, NULL),
(19, 1, 'contact', 'New contact message from Ndayambaje Elyse', 1, '#messages', '2026-07-29 13:21:24', NULL, NULL),
(20, 3, 'part_request', 'New spare part request #5 for Spark Plug (NGK) (x1)', 1, '#requests', '2026-07-29 13:32:49', NULL, NULL),
(23, 1, 'password_reset', '', 0, '#settings', '2026-07-29 14:01:13', 'Pending', NULL),
(24, 1, 'password_reset_alert', 'Password reset request from admin', 0, '#settings', '2026-07-29 14:01:13', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `PaymentID` int(11) NOT NULL,
  `InvoiceID` int(11) DEFAULT NULL,
  `Amount` decimal(10,2) DEFAULT NULL,
  `PaymentMethod` varchar(30) DEFAULT NULL,
  `PaymentStatus` enum('Pending','Partial','Paid') DEFAULT NULL,
  `PaymentDate` date DEFAULT NULL
) ;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`PaymentID`, `InvoiceID`, `Amount`, `PaymentMethod`, `PaymentStatus`, `PaymentDate`) VALUES
(2, 2, 0.00, 'Mobile Money', 'Pending', '2026-07-08'),
(3, 3, 10000.00, 'Mobile Money', 'Partial', '2026-07-08'),
(4, 4, 59900.00, 'Mobile Money', 'Paid', '2026-07-13');

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `PurchaseID` int(11) NOT NULL,
  `SupplierID` int(11) DEFAULT NULL,
  `UserID` int(11) DEFAULT NULL,
  `PurchaseDate` date DEFAULT NULL,
  `TotalAmount` decimal(10,2) DEFAULT NULL,
  `Status` enum('Pending','Approved','Processed') DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `purchases`
--

INSERT INTO `purchases` (`PurchaseID`, `SupplierID`, `UserID`, `PurchaseDate`, `TotalAmount`, `Status`) VALUES
(1, 1, 3, '2026-07-10', 450000.00, 'Pending'),
(3, 1, 3, '2026-07-29', 500000.00, 'Pending');

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL,
  `identifier` varchar(255) NOT NULL,
  `endpoint` varchar(100) NOT NULL,
  `attempt_count` int(11) DEFAULT 1,
  `first_attempt` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_attempt` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `blocked_until` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `repairjobs`
--

CREATE TABLE `repairjobs` (
  `JobID` int(11) NOT NULL,
  `VehicleID` int(11) DEFAULT NULL,
  `MechanicID` int(11) DEFAULT NULL,
  `UserID` int(11) DEFAULT NULL,
  `StartDate` date DEFAULT NULL,
  `EndDate` date DEFAULT NULL,
  `Status` enum('Pending','Diagnosed','In Progress','Awaiting Parts','Ready','Ready for Collection','Delivered','Cancelled') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `repairjobs`
--

INSERT INTO `repairjobs` (`JobID`, `VehicleID`, `MechanicID`, `UserID`, `StartDate`, `EndDate`, `Status`) VALUES
(1, 1, NULL, 2, '2026-07-18', '2026-07-21', 'Diagnosed'),
(2, 2, 7, 2, '2026-07-17', '2026-07-22', 'In Progress'),
(3, 3, 7, 2, '2026-07-16', '2026-07-20', 'Diagnosed'),
(4, 4, 6, 2, '2026-07-15', '2026-07-23', 'Awaiting Parts');

-- --------------------------------------------------------


--
-- Table structure for table `sparepartrequests`
--

CREATE TABLE `sparepartrequests` (
  `RequestID` int(11) NOT NULL,
  `MechanicID` int(11) NOT NULL,
  `SparePartID` int(11) NOT NULL,
  `JobID` int(11) DEFAULT NULL,
  `QuantityRequested` int(11) NOT NULL,
  `Reason` varchar(255) DEFAULT NULL,
  `Status` enum('Pending','Fulfilled','Rejected') NOT NULL DEFAULT 'Pending',
  `RequestedAt` datetime NOT NULL DEFAULT current_timestamp(),
  `DecidedAt` date DEFAULT NULL,
  `DecidedByUserID` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sparepartrequests`
--

INSERT INTO `sparepartrequests` (`RequestID`, `MechanicID`, `SparePartID`, `JobID`, `QuantityRequested`, `Reason`, `Status`, `RequestedAt`, `DecidedAt`, `DecidedByUserID`) VALUES
(1, 6, 1, NULL, 1, 'hello', 'Fulfilled', '2026-07-27 13:27:27', '2026-07-27', 3),
(2, 7, 1, NULL, 1, 'hello', 'Rejected', '2026-07-27 13:48:47', '2026-07-27', 3),
(3, 7, 1, NULL, 1, 'guy', 'Rejected', '2026-07-28 13:42:32', '2026-07-29', 3),
(5, 7, 3, NULL, 1, 'lycee', 'Fulfilled', '2026-07-29 13:32:49', '2026-07-29', 3);

-- --------------------------------------------------------

--
-- Table structure for table `spareparts`
--

CREATE TABLE `spareparts` (
  `SparePartID` int(11) NOT NULL,
  `CategoryID` int(11) DEFAULT NULL,
  `SupplierID` int(11) DEFAULT NULL,
  `PartName` varchar(100) DEFAULT NULL,
  `UnitPrice` decimal(10,2) DEFAULT NULL,
  `Quantity` int(11) DEFAULT NULL,
  `ReorderLevel` int(11) NOT NULL DEFAULT 10
) ;

--
-- Dumping data for table `spareparts`
--

INSERT INTO `spareparts` (`SparePartID`, `CategoryID`, `SupplierID`, `PartName`, `UnitPrice`, `Quantity`, `ReorderLevel`) VALUES
(1, 1, 1, 'Brake Pads (Front)', 15000.00, 24, 10),
(2, 2, 2, 'Oil Filter', 5000.00, 41, 15),
(3, 3, 1, 'Spark Plug (NGK)', 3000.00, 59, 20),
(7, 3, 2, 'bluk', 6000.00, 10, 18);

-- --------------------------------------------------------

--
-- Table structure for table `stocktransactions`
--

CREATE TABLE `stocktransactions` (
  `TransactionID` int(11) NOT NULL,
  `SparePartID` int(11) DEFAULT NULL,
  `TransactionType` enum('Purchase','Usage','Adjustment','Sale','Restoration','Pending') DEFAULT NULL,
  `Quantity` int(11) DEFAULT NULL,
  `TransactionDate` date DEFAULT NULL,
  `PurchaseID` int(11) DEFAULT NULL,
  `UnitPrice` decimal(10,2) DEFAULT NULL
) ;

--
-- Dumping data for table `stocktransactions`
--

INSERT INTO `stocktransactions` (`TransactionID`, `SparePartID`, `TransactionType`, `Quantity`, `TransactionDate`, `PurchaseID`, `UnitPrice`) VALUES
(1, 1, 'Purchase', 20, '2026-07-10', 1, 15000.00),
(2, 3, 'Purchase', 50, '2026-07-10', 1, 3000.00),
(4, 1, 'Usage', 2, '2026-07-19', NULL, NULL),
(5, 2, 'Adjustment', 1, '2026-07-23', NULL, NULL),
(7, 1, 'Usage', 1, '2026-07-27', NULL, NULL),
(8, 7, 'Adjustment', 10, '2026-07-29', NULL, NULL),
(9, 3, 'Usage', 1, '2026-07-29', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `SupplierID` int(11) NOT NULL,
  `CompanyName` varchar(100) DEFAULT NULL,
  `Phone` varchar(20) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `Address` varchar(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`SupplierID`, `CompanyName`, `Phone`, `Email`, `Address`) VALUES
(1, 'AutoParts Ltd', '+250788500001', 'sales@autoparts.rw', 'KN 2 Ave, Kigali'),
(2, 'MotoSupply', '+250722500002', 'info@motosupply.rw', 'KG 15 St, Kigali'),
(3, 'Rubber Co Ltd', '+250788500003', 'orders@rubberco.rw', 'KN 8 Ave, Kigali'),
(6, 'Lihemu ltd', '0792257855', 'elysend69@gmail.com', 'Kamonyi');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `UserID` int(11) NOT NULL,
  `MechanicID` int(11) DEFAULT NULL,
  `Username` varchar(50) DEFAULT NULL,
  `Password` varchar(260) DEFAULT NULL,
  `Role` enum('Admin','Receptionist','Mechanic','Stock Manager') DEFAULT NULL,
  `FullName` varchar(100) DEFAULT NULL,
  `Email` varchar(100) DEFAULT NULL,
  `Phone` varchar(20) DEFAULT NULL,
  `Status` enum('Active','Inactive') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`UserID`, `MechanicID`, `Username`, `Password`, `Role`, `FullName`, `Email`, `Phone`, `Status`) VALUES
(1, NULL, 'admin', '$2b$10$ZyW4btZEsQwtRyOzl.r.1eZ1wRR53sREi3i3oKCYl.iqXQ6Gnu1sa', 'Admin', 'Admin User', 'elysend69@gmail.com', '+250788555000', 'Active'),
(2, NULL, 'reception', '$2b$10$vHRIUVmK37eZWhJLs1LLCu4DN4R2LRamfstRZ3v530JStDAjmd1y.', 'Receptionist', 'Reception User', 'elysenda69@gmail.com', '+250788300001', 'Active'),
(3, NULL, 'stock', '$2b$10$frdEL66qmpwybvJvY29kduywY3unU6CA.Ew4nq.Z5Sxo6a89cRfN.', 'Stock Manager', 'Stock User', 'ndayambajeelyse6@gmail.com', '+250788300002', 'Active'),
(4, 7, 'elyse', '$2b$10$4XTKuo20JBqdr1YhZbz.aOkHTvHvJB1MUCA6E8YNNYoOntjm/EoLe', 'Mechanic', 'James Habimana', 'ndelyse69@gmail.com', '+250788100001', 'Active'),
(7, 6, 'kai', '$2y$10$3F43UMwSZNBEjnI2y0qFXeuBZBJGylT.nt4f3TivET55M4IcJUqPu', 'Mechanic', 'Ndayambaje Elyse', 'elyvinegisa@gmail.com', '+250788300002', 'Active'),
(8, 8, 'mugisha', '$2y$10$BhJNh7.Woo5h7IlmJswDe.gDSxiIB6ulZizqrKfaRbE5zQGDGzSAa', 'Mechanic', 'Mugisha kai', 'elysend100@gmail.com', '+250788555000', 'Active');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `VehicleID` int(11) NOT NULL,
  `CustomerID` int(11) DEFAULT NULL,
  `PlateNumber` varchar(20) DEFAULT NULL,
  `Manufacturer` varchar(50) DEFAULT NULL,
  `Model` varchar(50) DEFAULT NULL,
  `Year` year(4) DEFAULT NULL,
  `ChassisNumber` varchar(50) DEFAULT NULL,
  `EngineNumber` varchar(50) DEFAULT NULL,
  `FuelType` varchar(30) DEFAULT NULL,
  `Transmission` varchar(30) DEFAULT NULL,
  `Mileage` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`VehicleID`, `CustomerID`, `PlateNumber`, `Manufacturer`, `Model`, `Year`, `ChassisNumber`, `EngineNumber`, `FuelType`, `Transmission`, `Mileage`) VALUES
(1, 1, 'RAB 123 A', 'Toyota', 'Corolla', '2020', 'JTDBR32E720123456', '2ZR1234567', 'Petrol', 'Automatic', 45000),
(2, 2, 'RAC 456 B', 'Honda', 'Civic', '2019', '2HGFC2F59KH123456', 'K20Z2345678', 'Diesel', 'Manual', 62000),
(3, 3, 'RAD 789 C', 'Nissan', 'X-Trail', '2021', 'JN1TENT32Z0123456', 'QR25DE89012', 'Petrol', 'Automatic', 29000),
(4, 4, 'RAE 012 D', 'Volkswagen', 'Golf', '2022', 'WVWZZZ1KZBW123456', 'EA211345678', 'Petrol', 'Automatic', 15000);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`),
  ADD KEY `idx_action` (`action`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_resource` (`resource_type`,`resource_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`CategoryID`),
  ADD KEY `idx_categories_name` (`CategoryName`);

--
-- Indexes for table `contactmessages`
--
ALTER TABLE `contactmessages`
  ADD PRIMARY KEY (`MessageID`),
  ADD KEY `idx_contactmessages_isread` (`IsRead`),
  ADD KEY `idx_contactmessages_createdat` (`CreatedAt`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`CustomerID`),
  ADD KEY `idx_customers_email` (`Email`),
  ADD KEY `idx_customers_phone` (`Phone`),
  ADD KEY `idx_customers_regdate` (`RegistrationDate`);

--
-- Indexes for table `diagnostics`
--
ALTER TABLE `diagnostics`
  ADD PRIMARY KEY (`DiagnosticID`),
  ADD KEY `JobID` (`JobID`),
  ADD KEY `MechanicID` (`MechanicID`),
  ADD KEY `idx_diagnostics_date` (`DiagnosticDate`);

--
-- Indexes for table `invoiceitems`
--
ALTER TABLE `invoiceitems`
  ADD PRIMARY KEY (`InvoiceItemID`),
  ADD KEY `invoiceitems_ibfk_1` (`InvoiceID`),
  ADD KEY `invoiceitems_ibfk_2` (`SparePartID`),
  ADD KEY `idx_invoiceitems_invoice_part` (`InvoiceID`,`SparePartID`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`InvoiceID`),
  ADD KEY `invoices_ibfk_1` (`CustomerID`),
  ADD KEY `invoices_ibfk_2` (`JobID`),
  ADD KEY `VehicleID` (`VehicleID`),
  ADD KEY `idx_invoices_date` (`InvoiceDate`);

--
-- Indexes for table `jobhistory`
--
ALTER TABLE `jobhistory`
  ADD KEY `idx_jobhistory_status` (`NewStatus`),
  ADD KEY `idx_jobhistory_mechanic` (`MechanicID`);

--
-- Indexes for table `mechanics`
--
ALTER TABLE `mechanics`
  ADD PRIMARY KEY (`MechanicID`),
  ADD KEY `idx_mechanics_name` (`FullName`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`NotificationID`),
  ADD KEY `UserID` (`UserID`),
  ADD KEY `idx_notifications_user_isread` (`UserID`,`IsRead`),
  ADD KEY `idx_notifications_type` (`Type`),
  ADD KEY `idx_notifications_createdat` (`CreatedAt`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`PaymentID`),
  ADD KEY `payments_ibfk_1` (`InvoiceID`),
  ADD KEY `idx_payments_status` (`PaymentStatus`),
  ADD KEY `idx_payments_date` (`PaymentDate`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`PurchaseID`),
  ADD KEY `purchases_ibfk_1` (`SupplierID`),
  ADD KEY `purchases_ibfk_2` (`UserID`),
  ADD KEY `idx_purchases_date` (`PurchaseDate`),
  ADD KEY `idx_purchases_status` (`Status`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_identifier_endpoint` (`identifier`,`endpoint`),
  ADD KEY `idx_blocked_until` (`blocked_until`);

--
-- Indexes for table `repairjobs`
--
ALTER TABLE `repairjobs`
  ADD PRIMARY KEY (`JobID`),
  ADD KEY `repairjobs_ibfk_2` (`MechanicID`),
  ADD KEY `repairjobs_ibfk_3` (`UserID`),
  ADD KEY `repairjobs_ibfk_1` (`VehicleID`),
  ADD KEY `idx_repairjobs_status` (`Status`),
  ADD KEY `idx_repairjobs_mechanic_status` (`MechanicID`,`Status`),
  ADD KEY `idx_repairjobs_startdate` (`StartDate`),
  ADD KEY `idx_repairjobs_status_date` (`Status`,`StartDate`),
  ADD KEY `idx_repairjobs_status_enddate` (`Status`,`EndDate`);

--
-- Indexes for table `sparepartrequests`
--
ALTER TABLE `sparepartrequests`
  ADD PRIMARY KEY (`RequestID`),
  ADD KEY `idx_sparepartrequests_mechanic` (`MechanicID`),
  ADD KEY `idx_sparepartrequests_part` (`SparePartID`),
  ADD KEY `idx_sparepartrequests_job` (`JobID`),
  ADD KEY `idx_sparepartrequests_decidedby` (`DecidedByUserID`),
  ADD KEY `idx_sparepartrequests_status` (`Status`),
  ADD KEY `idx_sparepartrequests_requestedat` (`RequestedAt`),
  ADD KEY `idx_spr_status` (`Status`);

--
-- Indexes for table `spareparts`
--
ALTER TABLE `spareparts`
  ADD PRIMARY KEY (`SparePartID`),
  ADD KEY `idx_spareparts_category` (`CategoryID`),
  ADD KEY `idx_spareparts_supplier` (`SupplierID`),
  ADD KEY `idx_spareparts_name` (`PartName`),
  ADD KEY `idx_spareparts_lowstock` (`Quantity`,`ReorderLevel`),
  ADD KEY `idx_spareparts_quantity` (`Quantity`);

--
-- Indexes for table `stocktransactions`
--
ALTER TABLE `stocktransactions`
  ADD PRIMARY KEY (`TransactionID`),
  ADD KEY `stocktransactions_ibfk_1` (`SparePartID`),
  ADD KEY `idx_stocktransactions_part_date` (`SparePartID`,`TransactionDate`),
  ADD KEY `idx_stocktransactions_purchase` (`PurchaseID`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`SupplierID`),
  ADD KEY `idx_suppliers_company` (`CompanyName`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`UserID`),
  ADD UNIQUE KEY `Username` (`Username`),
  ADD KEY `idx_users_mechanic` (`MechanicID`),
  ADD KEY `idx_users_role` (`Role`),
  ADD KEY `idx_users_email` (`Email`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`VehicleID`),
  ADD UNIQUE KEY `ChassisNumber` (`ChassisNumber`),
  ADD UNIQUE KEY `PlateNumber` (`PlateNumber`),
  ADD KEY `idx_vehicles_customer` (`CustomerID`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `CategoryID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `contactmessages`
--
ALTER TABLE `contactmessages`
  MODIFY `MessageID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `CustomerID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `diagnostics`
--
ALTER TABLE `diagnostics`
  MODIFY `DiagnosticID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `invoiceitems`
--
ALTER TABLE `invoiceitems`
  MODIFY `InvoiceItemID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `InvoiceID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `mechanics`
--
ALTER TABLE `mechanics`
  MODIFY `MechanicID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `NotificationID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `PaymentID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `PurchaseID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `repairjobs`
--
ALTER TABLE `repairjobs`
  MODIFY `JobID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sparepartrequests`
--
ALTER TABLE `sparepartrequests`
  MODIFY `RequestID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `spareparts`
--
ALTER TABLE `spareparts`
  MODIFY `SparePartID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stocktransactions`
--
ALTER TABLE `stocktransactions`
  MODIFY `TransactionID` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `SupplierID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `UserID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `VehicleID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `diagnostics`
--
ALTER TABLE `diagnostics`
  ADD CONSTRAINT `diagnostics_ibfk_1` FOREIGN KEY (`JobID`) REFERENCES `repairjobs` (`JobID`) ON DELETE CASCADE,
  ADD CONSTRAINT `diagnostics_ibfk_2` FOREIGN KEY (`MechanicID`) REFERENCES `mechanics` (`MechanicID`) ON DELETE CASCADE;

--
-- Constraints for table `invoiceitems`
--
ALTER TABLE `invoiceitems`
  ADD CONSTRAINT `invoiceitems_ibfk_1` FOREIGN KEY (`InvoiceID`) REFERENCES `invoices` (`InvoiceID`) ON DELETE CASCADE,
  ADD CONSTRAINT `invoiceitems_ibfk_2` FOREIGN KEY (`SparePartID`) REFERENCES `spareparts` (`SparePartID`) ON DELETE SET NULL;

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`CustomerID`) REFERENCES `customers` (`CustomerID`),
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`JobID`) REFERENCES `repairjobs` (`JobID`) ON DELETE SET NULL,
  ADD CONSTRAINT `invoices_ibfk_3` FOREIGN KEY (`VehicleID`) REFERENCES `vehicles` (`VehicleID`) ON DELETE SET NULL;

--
-- Constraints for table `jobhistory`
--
ALTER TABLE `jobhistory`
  ADD CONSTRAINT `fk_jobhistory_mechanic` FOREIGN KEY (`MechanicID`) REFERENCES `mechanics` (`MechanicID`) ON DELETE SET NULL;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`InvoiceID`) REFERENCES `invoices` (`InvoiceID`) ON DELETE CASCADE;

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`SupplierID`) REFERENCES `suppliers` (`SupplierID`),
  ADD CONSTRAINT `purchases_ibfk_2` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE SET NULL;

--
-- Constraints for table `repairjobs`
--
ALTER TABLE `repairjobs`
  ADD CONSTRAINT `repairjobs_ibfk_1` FOREIGN KEY (`VehicleID`) REFERENCES `vehicles` (`VehicleID`) ON DELETE SET NULL,
  ADD CONSTRAINT `repairjobs_ibfk_2` FOREIGN KEY (`MechanicID`) REFERENCES `mechanics` (`MechanicID`) ON DELETE SET NULL,
  ADD CONSTRAINT `repairjobs_ibfk_3` FOREIGN KEY (`UserID`) REFERENCES `users` (`UserID`) ON DELETE SET NULL;

--
-- Constraints for table `sparepartrequests`
--
ALTER TABLE `sparepartrequests`
  ADD CONSTRAINT `sparepartrequests_ibfk_1` FOREIGN KEY (`MechanicID`) REFERENCES `mechanics` (`MechanicID`) ON DELETE CASCADE,
  ADD CONSTRAINT `sparepartrequests_ibfk_2` FOREIGN KEY (`SparePartID`) REFERENCES `spareparts` (`SparePartID`) ON DELETE CASCADE,
  ADD CONSTRAINT `sparepartrequests_ibfk_3` FOREIGN KEY (`JobID`) REFERENCES `repairjobs` (`JobID`) ON DELETE SET NULL,
  ADD CONSTRAINT `sparepartrequests_ibfk_4` FOREIGN KEY (`DecidedByUserID`) REFERENCES `users` (`UserID`) ON DELETE SET NULL;

--
-- Constraints for table `spareparts`
--
ALTER TABLE `spareparts`
  ADD CONSTRAINT `spareparts_ibfk_1` FOREIGN KEY (`CategoryID`) REFERENCES `categories` (`CategoryID`) ON DELETE SET NULL,
  ADD CONSTRAINT `spareparts_ibfk_2` FOREIGN KEY (`SupplierID`) REFERENCES `suppliers` (`SupplierID`) ON DELETE SET NULL;

--
-- Constraints for table `stocktransactions`
--
ALTER TABLE `stocktransactions`
  ADD CONSTRAINT `stocktransactions_ibfk_1` FOREIGN KEY (`SparePartID`) REFERENCES `spareparts` (`SparePartID`) ON DELETE CASCADE,
  ADD CONSTRAINT `stocktransactions_ibfk_2` FOREIGN KEY (`PurchaseID`) REFERENCES `purchases` (`PurchaseID`) ON DELETE SET NULL;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`MechanicID`) REFERENCES `mechanics` (`MechanicID`) ON DELETE SET NULL;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`CustomerID`) REFERENCES `customers` (`CustomerID`) ON DELETE SET NULL;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
