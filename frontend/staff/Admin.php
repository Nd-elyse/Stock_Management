<?php

require_once __DIR__ . '/../../backend/includes/auth.php';
require_once __DIR__ . '/../../backend/config/db.php';
require_once __DIR__ . '/../../backend/includes/csrf.php';

// Enforce Admin role
require_role('Admin');
$admin = current_user();
$_SESSION['admin_name'] = $admin['full_name'] ?? 'Admin User';

// Set default tab to dashboard on first load
if (!isset($_SESSION['current_tab'])) {
    $_SESSION['current_tab'] = 'dashboard';
}

// Generate CSRF token for forms
$csrf_token = generate_csrf_token();

handle_profile_update_request($pdo, 'admin_name', false);


// 1. DASHBOARD STATS - Optimized to use a single query instead of multiple separate queries

$stats = $pdo->query(
    "SELECT 
        (SELECT COUNT(*) FROM users) AS total_users,
        (SELECT COUNT(*) FROM mechanics) AS total_mechanics,
        (SELECT COUNT(*) FROM suppliers) AS total_suppliers,
        (SELECT COUNT(*) FROM spareparts) AS total_parts,
        (SELECT COUNT(*) FROM customers) AS total_customers,
        (SELECT COUNT(*) FROM vehicles) AS total_vehicles,
        (SELECT COUNT(*) FROM repairjobs) AS total_jobs,
        (SELECT COUNT(*) FROM invoices) AS total_invoices"
)->fetch();

$totalUsers     = (int) $stats['total_users'];
$totalMechanics = (int) $stats['total_mechanics'];
$totalSuppliers = (int) $stats['total_suppliers'];
$totalParts     = (int) $stats['total_parts'];
$totalCustomers = (int) $stats['total_customers'];
$totalVehicles  = (int) $stats['total_vehicles'];
$totalJobs      = (int) $stats['total_jobs'];
$totalInvoices  = (int) $stats['total_invoices'];


// 2. USERS (with CRUD)

$users = $pdo->query(
    "SELECT UserID, Username, Role, FullName, Email, Phone, Status
     FROM users ORDER BY UserID"
)->fetchAll();

$roleCounts = [];
foreach ($users as $u) {
    $roleCounts[$u['Role']] = ($roleCounts[$u['Role']] ?? 0) + 1;
}
$totalRoles = count($roleCounts);
$inactiveCount = count(array_filter($users, fn($u) => $u['Status'] === 'Inactive'));


// 3. MECHANICS (view only)

$mechanics = $pdo->query(
    "SELECT m.MechanicID, m.FullName, m.Phone, m.Specialization, m.Salary,
            COUNT(rj.JobID) AS AssignedJobs
     FROM mechanics m
     LEFT JOIN repairjobs rj ON rj.MechanicID = m.MechanicID
     GROUP BY m.MechanicID
     ORDER BY m.MechanicID"
)->fetchAll();

$activeMechanics = count($mechanics);
$totalAssignedJobs = array_sum(array_column($mechanics, 'AssignedJobs'));


// 4. SUPPLIERS (view only)

$suppliers = $pdo->query('SELECT * FROM suppliers ORDER BY SupplierID')->fetchAll();
$activeSuppliers = count($suppliers);
$totalPurchases = (int) $pdo->query('SELECT COUNT(*) FROM purchases')->fetchColumn();


// 5. SPARE PARTS (view only)

$spareParts = $pdo->query(
    "SELECT sp.SparePartID, sp.PartName, sp.UnitPrice, sp.Quantity,
            sp.ReorderLevel,
            c.CategoryName, s.CompanyName AS SupplierName
     FROM spareparts sp
     LEFT JOIN categories c ON c.CategoryID = sp.CategoryID
     LEFT JOIN suppliers  s ON s.SupplierID = sp.SupplierID
     ORDER BY sp.SparePartID"
)->fetchAll();

$lowStockCount = count(array_filter($spareParts, fn($p) => $p['Quantity'] <= $p['ReorderLevel']));


// 6. NOTIFICATIONS (from database only)

// Ensure notifications table exists (shared with Receptionist.php)
ensure_notifications_table($pdo);

$notifications = $pdo->query(
    "SELECT n.*, u.FullName AS UserFullName
     FROM notifications n
     LEFT JOIN users u ON u.UserID = n.UserID
     ORDER BY n.CreatedAt DESC
     LIMIT 50"
)->fetchAll();

$unreadCount = count(array_filter($notifications, fn($n) => $n['IsRead'] == 0));


// 7. CONTACT MESSAGES

$contactMessages = [];
$unreadMessagesCount = 0;
try {
    $contactMessages = $pdo->query(
        "SELECT * FROM contactmessages ORDER BY CreatedAt DESC"
    )->fetchAll();
    $unreadMessagesCount = count(array_filter($contactMessages, fn($m) => $m['IsRead'] == 0));
} catch (PDOException $e) {
    // Table may not exist yet - will be created when SQL is imported
    $contactMessages = [];
    $unreadMessagesCount = 0;
}


// 8. REPORTS DATA

$repairJobsReport = $pdo->query(
    "SELECT rj.JobID, rj.StartDate, rj.EndDate, rj.Status,
            v.PlateNumber, c.FullName AS CustomerName,
            m.FullName AS MechanicName
     FROM repairjobs rj
     JOIN vehicles v ON v.VehicleID = rj.VehicleID
     JOIN customers c ON c.CustomerID = v.CustomerID
     LEFT JOIN mechanics m ON m.MechanicID = rj.MechanicID
     ORDER BY rj.StartDate DESC
     LIMIT 20"
)->fetchAll();

$customersReport = $pdo->query(
    "SELECT CustomerID, FullName, Phone, Email, Address, RegistrationDate
     FROM customers
     ORDER BY RegistrationDate DESC
     LIMIT 20"
)->fetchAll();

$mechanicsReport = $pdo->query(
    "SELECT m.MechanicID, m.FullName, m.Phone, m.Specialization, m.Salary,
            COUNT(rj.JobID) AS JobCount
     FROM mechanics m
     LEFT JOIN repairjobs rj ON rj.MechanicID = m.MechanicID
     GROUP BY m.MechanicID
     ORDER BY m.MechanicID"
)->fetchAll();

$inventoryReport = $pdo->query(
    "SELECT sp.SparePartID, sp.PartName, sp.Quantity, sp.UnitPrice,
            sp.ReorderLevel,
            c.CategoryName, s.CompanyName AS SupplierName
     FROM spareparts sp
     LEFT JOIN categories c ON c.CategoryID = sp.CategoryID
     LEFT JOIN suppliers s ON s.SupplierID = sp.SupplierID
     ORDER BY sp.PartName"
)->fetchAll();

$suppliersReport = $pdo->query(
    "SELECT s.*, COUNT(p.PurchaseID) AS PurchaseCount
     FROM suppliers s
     LEFT JOIN purchases p ON p.SupplierID = s.SupplierID
     GROUP BY s.SupplierID
     ORDER BY s.CompanyName"
)->fetchAll();

$purchasesReport = $pdo->query(
    "SELECT p.PurchaseID, p.PurchaseDate, p.TotalAmount,
            s.CompanyName AS SupplierName, u.Username AS UserName
     FROM purchases p
     JOIN suppliers s ON s.SupplierID = p.SupplierID
     JOIN users u ON u.UserID = p.UserID
     ORDER BY p.PurchaseDate DESC
     LIMIT 20"
)->fetchAll();

$paymentsReport = $pdo->query(
    "SELECT pay.PaymentID, pay.Amount, pay.PaymentMethod, pay.PaymentStatus, pay.PaymentDate,
            c.FullName AS CustomerName
     FROM payments pay
     JOIN invoices i ON i.InvoiceID = pay.InvoiceID
     JOIN customers c ON c.CustomerID = i.CustomerID
     ORDER BY pay.PaymentDate DESC
     LIMIT 20"
)->fetchAll();

$vehiclesReport = $pdo->query(
    "SELECT v.VehicleID, v.PlateNumber, v.Manufacturer, v.Model, v.Year,
            v.Transmission, v.FuelType, v.Mileage,
            c.FullName AS CustomerName
     FROM vehicles v
     JOIN customers c ON c.CustomerID = v.CustomerID
     ORDER BY v.VehicleID"
)->fetchAll();

$reportStats = [
    'repairs' => [
        'total' => $totalJobs,
        'completed' => (int) $pdo->query("SELECT COUNT(*) FROM repairjobs WHERE Status = 'Delivered'")->fetchColumn(),
        'in_progress' => (int) $pdo->query("SELECT COUNT(*) FROM repairjobs WHERE Status = 'In Progress' OR Status = 'Diagnosed' OR Status = 'Awaiting Parts'")->fetchColumn(),
    ],
    'customers' => [
        'total' => $totalCustomers,
        'active' => $totalCustomers,
        'new_this_month' => (int) $pdo->query("SELECT COUNT(*) FROM customers WHERE RegistrationDate >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn(),
    ],
    'mechanics' => [
        'total' => $totalMechanics,
        'active' => $totalMechanics,
        'jobs_assigned' => $totalAssignedJobs,
    ],
    'inventory' => [
        'total_parts' => $totalParts,
        'low_stock' => $lowStockCount,
        'stock_health' => $totalParts > 0 ? round((($totalParts - $lowStockCount) / $totalParts) * 100) : 0,
    ],
    'suppliers' => [
        'total' => $totalSuppliers,
        'active' => $totalSuppliers,
        'purchases' => $totalPurchases,
    ],
    'purchases' => [
        'total' => $totalPurchases,
        'total_amount' => (float) $pdo->query("SELECT COALESCE(SUM(TotalAmount), 0) FROM purchases")->fetchColumn(),
        'this_month' => (int) $pdo->query("SELECT COUNT(*) FROM purchases WHERE PurchaseDate >= DATE_FORMAT(NOW(), '%Y-%m-01')")->fetchColumn(),
    ],
    'payments' => [
        'total' => (int) $pdo->query("SELECT COUNT(*) FROM payments")->fetchColumn(),
        'completed' => (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE PaymentStatus = 'Paid'")->fetchColumn(),
        'pending' => (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE PaymentStatus = 'Pending'")->fetchColumn(),
    ],
    'vehicles' => [
        'total' => $totalVehicles,
        'active' => $totalVehicles,
        'inactive' => 0,
    ],
];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>" />
    <title>Admin Dashboard | GarageManager</title>
    <!-- External CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;600;700;800&display=swap" rel="stylesheet" />
    <!-- Staff custom CSS -->
    <link rel="stylesheet" href="../staff.css" />
    <!-- Libraries for PDF/Excel export -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf-autotable/3.5.31/jspdf.plugin.autotable.min.js" defer></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" defer></script>
</head>
<body class="site-staff" data-page="admin">

    <!-- ====== TOAST CONTAINER ====== -->
    <div id="toastContainer" class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 9999;"></div>

    <!-- 
    SIDEBAR OVERLAY (mobile)
    = -->
    <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>

    <!-- 
    SIDEBAR
    = -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-brand">
            <i class="bi bi-wrench-adjustable-circle-fill"></i>
            <div class="brand-text">
                Garage<span>Manager</span>
                <small>Admin Panel</small>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">Main</div>
            <a href="" onclick="switchTab('dashboard', event)" id="nav-dashboard" class="active">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
            <a href="#" onclick="switchTab('notifications', event)" id="nav-notifications">
                <i class="bi bi-bell-fill"></i> Notifications
                <?php if ($unreadCount > 0): ?>
                <span class="badge bg-danger rounded-pill ms-auto" style="font-size:0.6rem;"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a>
            <a href="#" onclick="switchTab('messages', event)" id="nav-messages">
                <i class="bi bi-envelope-fill"></i> Messages
            </a>

            <div class="nav-section">Administration</div>
            <a href="#" onclick="switchTab('users', event)" id="nav-users" >
                <i class="bi bi-people-fill"></i> Users
            </a>
            <a href="#" onclick="switchTab('mechanics', event)" id="nav-mechanics">
                <i class="bi bi-wrench-adjustable"></i> Mechanics
            </a>
            <a href="#" onclick="switchTab('suppliers', event)" id="nav-suppliers">
                <i class="bi bi-truck-flatbed"></i> Suppliers
            </a>
            <a href="#" onclick="switchTab('spare-parts', event)" id="nav-spare-parts">
                <i class="bi bi-boxes"></i> Spare Parts
            </a>

            <div class="nav-section">Insights &amp; System</div>
            <a href="#" onclick="switchTab('reports', event)" id="nav-reports">
                <i class="bi bi-bar-chart-fill"></i> Reports
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="dropdown">
                <div class="user-info" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar"><?php echo substr($_SESSION['admin_name'] ?? 'AD', 0, 2); ?></div>
                    <div>
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin User'); ?></div>
                        <div class="user-role">Administrator</div>
                    </div>
                </div>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="bi bi-gear"></i> Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="../../backend/api/auth.php?resource=logout"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                </ul>
            </div>
        </div>
    </aside>

    <!-- 
    MAIN CONTENT
    = -->
    <div class="dashboard-main">

        <!-- TOPBAR -->
        <div class="dashboard-topbar">
            <div class="d-flex align-items-center gap-2">
                <button class="btn-action d-lg-none" id="sidebarToggle" onclick="toggleSidebar()">
                    <i class="bi bi-list"></i>
                </button>
                <h5 id="pageTitle">Manage Users</h5>
            </div>
            <div class="topbar-actions">
                <div class="search-box d-none d-lg-flex">
                    <i class="bi bi-search"></i>
                    <input type="text" placeholder="Search records..." id="globalSearch" onkeyup="filterTable('userTable')" />
                </div>
                <button class="btn-action position-relative" onclick="switchTab('notifications', event)" title="Notifications">
                    <i class="bi bi-bell-fill" style="font-size:1.4rem;"></i>
                    <?php if ($unreadCount > 0): ?>
                    <span class="badge bg-danger rounded-pill" style="position:absolute;top:-4px;right:-6px;font-size:0.6rem;"><?php echo $unreadCount; ?></span>
                    <?php endif; ?>
                </button>
            </div>
        </div>

        <div class="dashboard-content">

            <!-- 
            DASHBOARD TAB
            = -->
            <div id="tab-dashboard" class="tab-content" style="display:block;">
                <div class="card-custom p-4">
                    <h6 style="font-weight:700;color:var(--text-dark);">Welcome to the Dashboard</h6>
                    <p class="text-muted">Overview of your garage management system. All stats are live from the database.</p>
                </div>
                <div class="row g-4 mb-4 mt-1">
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalUsers; ?></div>
                                <div class="label">Total Users</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-wrench-adjustable"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalMechanics; ?></div>
                                <div class="label">Mechanics</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-truck-flatbed"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalSuppliers; ?></div>
                                <div class="label">Suppliers</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="bi bi-boxes"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalParts; ?></div>
                                <div class="label">Spare Parts</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon purple"><i class="bi bi-person-badge"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalCustomers; ?></div>
                                <div class="label">Customers</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon slate"><i class="bi bi-truck"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalVehicles; ?></div>
                                <div class="label">Vehicles</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-tools"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalJobs; ?></div>
                                <div class="label">Repair Jobs</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-receipt"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalInvoices; ?></div>
                                <div class="label">Invoices</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            NOTIFICATIONS TAB — Real data from notifications table
            = -->
            <div id="tab-notifications" class="tab-content" style="display:none;">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h6 style="font-weight:700;color:var(--text-dark);"><i class="bi bi-bell-fill" style="color:var(--primary-blue);"></i> All Notifications</h6>
                        <div class="d-flex gap-2">
                            <button class="btn-outline-blue btn-sm" onclick="markAllRead()"><i class="bi bi-check-all"></i> Mark All Read</button>
                            <button class="btn-blue btn-sm" data-bs-toggle="modal" data-bs-target="#notificationModal"><i class="bi bi-plus-lg"></i> Add</button>
                        </div>
                    </div>
                    <div id="notificationList">
                        <?php if (empty($notifications)): ?>
                        <div class="text-center py-4 text-muted">No notifications yet.</div>
                        <?php else: ?>
                        <?php foreach ($notifications as $n): ?>
                        <?php
                            $isRead = $n['IsRead'] ?? 0;
                            $notifId = $n['NotificationID'] ?? 0;
                            $type = $n['Type'] ?? 'system';
                            $message = $n['Message'] ?? '';
                            $link = $n['Link'] ?? '#';
                            $time = $n['CreatedAt'] ?? date('Y-m-d H:i:s');
                            $icon = match($type) {
                                'job'     => 'bi-plus-circle-fill',
                                'stock'   => 'bi-exclamation-triangle-fill',
                                'payment' => 'bi-cash-coin',
                                default   => 'bi-info-circle-fill'
                            };
                            $color = match($type) {
                                'job'     => '#2563eb',
                                'stock'   => '#dc2626',
                                'payment' => '#16a34a',
                                default   => '#6b7280'
                            };
                        ?>
                        <div class="notif-item d-flex align-items-center gap-3 <?php echo $isRead ? '' : 'unread'; ?>"
                             data-id="<?php echo $notifId; ?>" data-read="<?php echo $isRead; ?>" style="cursor:pointer;"
                             onclick='viewNotificationDetails(<?php echo json_encode($n, JSON_HEX_APOS); ?>)'>
                            <div class="notif-icon" style="color:<?php echo $color; ?>;">
                                <i class="bi <?php echo $icon; ?>"></i>
                            </div>
                            <div class="notif-message">
                                <?php echo htmlspecialchars($message); ?>
                            </div>
                            <div class="notif-time"><?php echo date('M d, H:i', strtotime($time)); ?></div>
                            <div class="d-flex gap-1" onclick="event.stopPropagation();">
                                <?php if (!$isRead): ?>
                                <button class="btn-action" onclick="markNotificationRead(<?php echo $notifId; ?>)" title="Mark as read">
                                    <i class="bi bi-check-circle"></i>
                                </button>
                                <?php endif; ?>
                                <button class="btn-action edit" onclick='editNotification(<?php echo htmlspecialchars(json_encode($n), ENT_QUOTES); ?>)'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button class="btn-action delete" onclick="deleteNotification(<?php echo $notifId; ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 
            MESSAGES TAB (Contact Messages)
            = -->
            <div id="tab-messages" class="tab-content" style="display:none;">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h6 style="font-weight:700;color:var(--text-dark);"><i class="bi bi-envelope-fill" style="color:var(--primary-blue);"></i> Contact Messages</h6>
                        <button class="btn-outline-blue btn-sm" onclick="markAllMessagesRead()"><i class="bi bi-check-all"></i> Mark All Read</button>
                    </div>
                    <div id="messagesList">
                        <?php if (empty($contactMessages)): ?>
                        <div class="text-center py-4 text-muted">No messages yet.</div>
                        <?php else: ?>
                        <?php foreach ($contactMessages as $m): ?>
                        <?php
                            $isRead = $m['IsRead'] ?? 0;
                            $msgId = $m['MessageID'] ?? 0;
                        ?>
                        <div class="list-group-item d-flex gap-3 align-items-center py-3 border-bottom <?php echo $isRead ? 'opacity-75' : ''; ?>">
                            <i class="bi bi-envelope-fill" style="color:<?php echo $isRead ? '#6c757d' : '#2563eb'; ?>;font-size:1.3rem;"></i>
                            <div class="flex-grow-1">
                                <div style="font-weight:600;font-size:0.95rem;"><?php echo htmlspecialchars($m['Subject'] ?? 'No Subject'); ?></div>
                                <div style="font-size:0.85rem;color:var(--text-muted);">From: <?php echo htmlspecialchars($m['FullName']); ?> (<?php echo htmlspecialchars($m['Email']); ?>)</div>
                                <div style="font-size:0.85rem;color:var(--text-muted);"><?php echo date('M d, Y g:i A', strtotime($m['CreatedAt'])); ?></div>
                                <div style="font-size:0.9rem;margin-top:4px;"><?php echo htmlspecialchars(substr($m['Message'], 0, 100)) . (strlen($m['Message']) > 100 ? '...' : ''); ?></div>
                            </div>
                            <?php if (!$isRead): ?><span class="badge bg-primary rounded-pill">New</span><?php endif; ?>
                            <button class="btn-action delete" onclick="deleteMessage(<?php echo $msgId; ?>)"><i class="bi bi-trash"></i></button>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 
            1. MANAGE USERS (with CRUD)
            = -->
            <div id="tab-users" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo count($users); ?></div>
                                <div class="label">Total Users</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-shield-lock-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalRoles; ?></div>
                                <div class="label">Roles</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-person-x-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $inactiveCount; ?></div>
                                <div class="label">Inactive Accounts</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="filter-toolbar filter-toolbar-inline mb-4">
                    <div class="d-flex align-items-center gap-2 flex-wrap w-100">
                        <button class="btn-blue btn-sm" data-bs-toggle="modal" data-bs-target="#userModal">
                            <i class="bi bi-plus-lg"></i> Add User
                        </button>
                        <div class="search-box">
                            <i class="bi bi-search"></i>
                            <input type="text" placeholder="Search users..." id="userSearch" oninput="filterUsers()" />
                        </div>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-people-fill" style="color:var(--primary-blue);"></i> User Management</h6>
                        <span style="font-size:0.8rem;color:var(--text-muted);" id="userCountDisplay">Showing <?php echo count($users); ?> users</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="userTable">
                            <thead>
                                <tr>
                                    <th>Full Name</th>
                                    <th>Username</th>
                                    <th>Role</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                <tr data-user-id="<?php echo (int) $u['UserID']; ?>" data-role="<?php echo htmlspecialchars($u['Role']); ?>" data-status="<?php echo htmlspecialchars($u['Status']); ?>">
                                    <td><?php echo htmlspecialchars($u['FullName']); ?></td>
                                    <td><?php echo htmlspecialchars($u['Username']); ?></td>
                                    <td><?php echo htmlspecialchars($u['Role']); ?></td>
                                    <td><?php echo htmlspecialchars($u['Email']); ?></td>
                                    <td><?php echo htmlspecialchars($u['Phone']); ?></td>
                                    <td><span class="badge-status <?php echo $u['Status'] === 'Active' ? 'badge-ok' : 'badge-low'; ?>"><?php echo htmlspecialchars($u['Status']); ?></span></td>
                                    <td>
                                        <button class="btn-action edit" onclick='editUser(<?php echo json_encode($u); ?>)'><i class="bi bi-pencil"></i></button>
                                        <button class="btn-action delete" onclick="deleteUser(<?php echo (int) $u['UserID']; ?>, '<?php echo htmlspecialchars($u['FullName'], ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <p id="userTableFooter">Showing 1-<?php echo count($users); ?> of <?php echo count($users); ?> users</p>
                        <div class="pagination-wrapper">
                            <button class="btn-outline-blue" id="userPrevBtn" onclick="prevUserPage()">Previous</button>
                            <button class="btn-blue" id="userNextBtn" onclick="nextUserPage()">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            2. MANAGE MECHANICS (CRUD)
            = -->
            <div id="tab-mechanics" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-person-badge"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo count($mechanics); ?></div>
                                <div class="label">Total Mechanics</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $activeMechanics; ?></div>
                                <div class="label">Active</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-clock-history"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalAssignedJobs; ?></div>
                                <div class="label">Assigned Jobs</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="filter-toolbar mb-4">
                    <div></div>
                    <div class="d-flex gap-2 flex-wrap">
                        <div class="search-box"><i class="bi bi-search"></i><input type="text" placeholder="Search mechanics..." id="mechanicSearch" onkeyup="filterTable('mechanicTable')" /></div>
                    </div>
                </div>
                <p class="text-muted small mb-3"><i class="bi bi-info-circle"></i> To add a new mechanic, create a user with the <strong>Mechanic</strong> role in <a href="#" onclick="switchTab('users', event)">Manage Users</a>.</p>

                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-person-badge" style="color:var(--primary-blue);"></i> Mechanics Directory</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="mechanicTable">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Specialty</th>
                                    <th>Phone</th>
                                    <th>Salary (RWF)</th>
                                    <th>Assigned Jobs</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mechanics as $m): ?>
                                <tr data-specialization="<?php echo htmlspecialchars($m['Specialization'] ?? ''); ?>">
                                    <td><?php echo htmlspecialchars($m['FullName']); ?></td>
                                    <td><?php echo htmlspecialchars($m['Specialization'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($m['Phone'] ?? ''); ?></td>
                                    <td><?php echo number_format((float) $m['Salary']); ?></td>
                                    <td><?php echo (int) $m['AssignedJobs']; ?></td>
                                    <td><span class="badge-status badge-ok">Active</span></td>
                                    <td>
                                        <button class="btn-action edit" onclick='editMechanic(<?php echo json_encode($m); ?>)'><i class="bi bi-pencil"></i></button>
                                        <button class="btn-action delete" onclick="deleteMechanic(<?php echo (int) $m['MechanicID']; ?>, '<?php echo htmlspecialchars($m['FullName'], ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <p>Showing <?php echo count($mechanics); ?> mechanics</p>
                        <div class="pagination-wrapper">
                            <button class="btn-outline-blue" onclick="prevPage()">Previous</button>
                            <button class="btn-blue" onclick="nextPage()">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            3. MANAGE SUPPLIERS (CRUD)
            = -->
            <div id="tab-suppliers" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-truck-flatbed"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo count($suppliers); ?></div>
                                <div class="label">Total Suppliers</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $activeSuppliers; ?></div>
                                <div class="label">Active</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-cart-plus-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalPurchases; ?></div>
                                <div class="label">Total Purchases</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="filter-toolbar mb-4">
                    <div></div>
                    <div class="d-flex gap-2 flex-wrap">
                        <div class="search-box"><i class="bi bi-search"></i><input type="text" placeholder="Search suppliers..." id="supplierSearch" onkeyup="filterTable('supplierTable')" /></div>
                        <button class="btn-blue btn-sm" data-bs-toggle="modal" data-bs-target="#supplierModal"><i class="bi bi-plus-lg"></i> Add Supplier</button>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-truck-flatbed" style="color:var(--primary-blue);"></i> Supplier Directory</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="supplierTable">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Contact</th>
                                    <th>Email</th>
                                    <th>Address</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($suppliers as $s): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($s['CompanyName']); ?></td>
                                    <td><?php echo htmlspecialchars($s['Phone'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($s['Email'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($s['Address'] ?? ''); ?></td>
                                    <td><span class="badge-status badge-ok">Active</span></td>
                                    <td>
                                        <button class="btn-action edit" onclick='editSupplier(<?php echo json_encode($s); ?>)'><i class="bi bi-pencil"></i></button>
                                        <button class="btn-action delete" onclick="deleteSupplier(<?php echo (int) $s['SupplierID']; ?>, '<?php echo htmlspecialchars($s['CompanyName'], ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <p>Showing <?php echo count($suppliers); ?> suppliers</p>
                        <div class="pagination-wrapper">
                            <button class="btn-outline-blue" onclick="prevPage()">Previous</button>
                            <button class="btn-blue" onclick="nextPage()">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            4. MANAGE SPARE PARTS (VIEW ONLY)
            = -->
            <div id="tab-spare-parts" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-boxes"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo count($spareParts); ?></div>
                                <div class="label">Total Parts</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $lowStockCount; ?></div>
                                <div class="label">Low Stock</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-md-4">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-arrow-down-left-circle"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo (int) $pdo->query('SELECT COUNT(*) FROM stocktransactions')->fetchColumn(); ?></div>
                                <div class="label">Stock Adjustments</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="filter-toolbar mb-4">
                    <div></div>
                    <div class="d-flex gap-2 flex-wrap">
                        <div class="search-box"><i class="bi bi-search"></i><input type="text" placeholder="Search spare parts..." id="sparePartSearch" onkeyup="filterTable('sparePartTable')" /></div>
                    </div>
                </div>

                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-boxes" style="color:var(--primary-blue);"></i> Spare Parts Inventory</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="sparePartTable">
                            <thead>
                                <tr>
                                    <th>Part Name</th>
                                    <th>Category</th>
                                    <th>Stock</th>
                                    <th>Min Level</th>
                                    <th>Unit Price (RWF)</th>
                                    <th>Supplier</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($spareParts as $p): $isLow = $p['Quantity'] <= $p['ReorderLevel']; ?>
                                <tr data-category="<?php echo htmlspecialchars($p['CategoryName'] ?? ''); ?>">
                                    <td><?php echo htmlspecialchars($p['PartName']); ?></td>
                                    <td><?php echo htmlspecialchars($p['CategoryName'] ?? ''); ?></td>
                                    <td><?php echo (int) $p['Quantity']; ?></td>
                                    <td><?php echo (int) $p['ReorderLevel']; ?></td>
                                    <td><?php echo number_format((float) $p['UnitPrice']); ?></td>
                                    <td><?php echo htmlspecialchars($p['SupplierName'] ?? ''); ?></td>
                                    <td><span class="badge-status <?php echo $isLow ? 'badge-low' : 'badge-ok'; ?>"><?php echo $isLow ? 'Low' : 'In Stock'; ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <p>Showing <?php echo count($spareParts); ?> parts</p>
                        <div class="pagination-wrapper">
                            <button class="btn-outline-blue" onclick="prevPage()">Previous</button>
                            <button class="btn-blue" onclick="nextPage()">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            5. MANAGE REPORTS
            = -->
            <div id="tab-reports" class="tab-content" style="display:none;">
                <div class="card-custom p-4 mb-4">
                    <div id="reportPills" class="report-pills">
                        <button type="button" class="report-pill active" onclick="switchReport('repairs', event)">Repairs</button>
                        <button type="button" class="report-pill" onclick="switchReport('customers', event)">Customers</button>
                        <button type="button" class="report-pill" onclick="switchReport('mechanics', event)">Mechanics</button>
                        <button type="button" class="report-pill" onclick="switchReport('inventory', event)">Inventory</button>
                        <button type="button" class="report-pill" onclick="switchReport('suppliers', event)">Suppliers</button>
                        <button type="button" class="report-pill" onclick="switchReport('purchases', event)">Purchases</button>
                        <button type="button" class="report-pill" onclick="switchReport('payments', event)">Payments</button>
                        <button type="button" class="report-pill" onclick="switchReport('vehicles', event)">Vehicles</button>
                    </div>

                    <!-- Repairs Report -->
                    <div id="report-repairs" class="report-section">
                        <div class="row g-4">
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-tools"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['repairs']['total']; ?></div><div class="label">Total Repairs</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['repairs']['completed']; ?></div><div class="label">Completed</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon amber"><i class="bi bi-clock-history"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['repairs']['in_progress']; ?></div><div class="label">In Progress</div></div></div></div>
                        </div>
                        <div class="table-card mt-4">
                            <div class="table-header">
                                <h6><i class="bi bi-tools" style="color:var(--primary-blue);"></i> Repairs Report</h6>
                                <div class="d-flex gap-2">
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('repairs', 'pdf')"><i class="bi bi-file-pdf"></i> PDF</button>
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('repairs', 'excel')"><i class="bi bi-file-excel"></i> Excel</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-custom" id="repairsTable">
                                    <thead>
                                        <tr><th>JobID</th><th>Customer</th><th>Vehicle</th><th>Mechanic</th><th>Start Date</th><th>Status</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php $rowNumber = 1; foreach ($repairJobsReport as $rj): ?>
                                        <tr>
                                            <td class="row-number"><?php echo $rowNumber++; ?></td>
                                            <td><?php echo htmlspecialchars($rj['CustomerName'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($rj['PlateNumber'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($rj['MechanicName'] ?? 'Unassigned'); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($rj['StartDate'])); ?></td>
                                            <td><span class="badge-status <?php echo $rj['Status'] === 'Delivered' ? 'badge-ok' : ($rj['Status'] === 'Pending' ? 'badge-low' : 'badge-info'); ?>"><?php echo htmlspecialchars($rj['Status']); ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($repairJobsReport)): ?>
                                        <tr><td colspan="6" class="text-center text-muted">No repair jobs found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Customers Report -->
                    <div id="report-customers" class="report-section" style="display:none;">
                        <div class="row g-4">
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-people-fill"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['customers']['total']; ?></div><div class="label">Total Customers</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-person-check"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['customers']['active']; ?></div><div class="label">Active</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon amber"><i class="bi bi-person-plus"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['customers']['new_this_month']; ?></div><div class="label">New (this month)</div></div></div></div>
                        </div>
                        <div class="table-card mt-4">
                            <div class="table-header">
                                <h6><i class="bi bi-people-fill" style="color:var(--primary-blue);"></i> Customers Report</h6>
                                <div class="d-flex gap-2">
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('customers', 'pdf')"><i class="bi bi-file-pdf"></i> PDF</button>
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('customers', 'excel')"><i class="bi bi-file-excel"></i> Excel</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-custom" id="customersTable">
                                    <thead><tr><th>CustomerID</th><th>FullName</th><th>Phone</th><th>Email</th><th>Address</th><th>RegistrationDate</th></tr></thead>
                                    <tbody>
                                        <?php $rowNumber = 1; foreach ($customersReport as $c): ?>
                                        <tr>
                                            <td class="row-number"><?php echo $rowNumber++; ?></td>
                                            <td><?php echo htmlspecialchars($c['FullName']); ?></td>
                                            <td><?php echo htmlspecialchars($c['Phone'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($c['Email'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($c['Address'] ?? ''); ?></td>
                                            <td><?php echo date('M d, Y', strtotime($c['RegistrationDate'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($customersReport)): ?>
                                        <tr><td colspan="6" class="text-center text-muted">No customers found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Mechanics Report -->
                    <div id="report-mechanics" class="report-section" style="display:none;">
                        <div class="row g-4">
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-person-badge"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['mechanics']['total']; ?></div><div class="label">Total Mechanics</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['mechanics']['active']; ?></div><div class="label">Active</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon amber"><i class="bi bi-clock-history"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['mechanics']['jobs_assigned']; ?></div><div class="label">Jobs Assigned</div></div></div></div>
                        </div>
                        <div class="table-card mt-4">
                            <div class="table-header">
                                <h6><i class="bi bi-person-badge" style="color:var(--primary-blue);"></i> Mechanics Report</h6>
                                <div class="d-flex gap-2">
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('mechanics', 'pdf')"><i class="bi bi-file-pdf"></i> PDF</button>
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('mechanics', 'excel')"><i class="bi bi-file-excel"></i> Excel</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-custom" id="mechanicsReportTable">
                                    <thead><tr><th>MechanicID</th><th>FullName</th><th>Phone</th><th>Specialization</th><th>Salary</th><th>Jobs</th></tr></thead>
                                    <tbody>
                                        <?php $rowNumber = 1; foreach ($mechanicsReport as $m): ?>
                                        <tr>
                                            <td class="row-number"><?php echo $rowNumber++; ?></td>
                                            <td><?php echo htmlspecialchars($m['FullName']); ?></td>
                                            <td><?php echo htmlspecialchars($m['Phone'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($m['Specialization'] ?? ''); ?></td>
                                            <td><?php echo number_format((float) $m['Salary']); ?></td>
                                            <td><?php echo (int) $m['JobCount']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($mechanicsReport)): ?>
                                        <tr><td colspan="6" class="text-center text-muted">No mechanics found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Inventory Report -->
                    <div id="report-inventory" class="report-section" style="display:none;">
                        <div class="row g-4">
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-boxes"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['inventory']['total_parts']; ?></div><div class="label">Total Parts</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['inventory']['low_stock']; ?></div><div class="label">Low Stock</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['inventory']['stock_health']; ?>%</div><div class="label">Stock Health</div></div></div></div>
                        </div>
                        <div class="table-card mt-4">
                            <div class="table-header">
                                <h6><i class="bi bi-boxes" style="color:var(--primary-blue);"></i> Inventory Report</h6>
                                <div class="d-flex gap-2">
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('inventory', 'pdf')"><i class="bi bi-file-pdf"></i> PDF</button>
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('inventory', 'excel')"><i class="bi bi-file-excel"></i> Excel</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-custom" id="inventoryTable">
                                    <thead><tr><th>PartName</th><th>Category</th><th>Quantity</th><th>Min Level</th><th>Unit Price</th><th>Supplier</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($inventoryReport as $iv): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($iv['PartName']); ?></td>
                                            <td><?php echo htmlspecialchars($iv['CategoryName'] ?? ''); ?></td>
                                            <td><?php echo (int) $iv['Quantity']; ?></td>
                                            <td><?php echo (int) $iv['ReorderLevel']; ?></td>
                                            <td><?php echo number_format((float) $iv['UnitPrice']); ?></td>
                                            <td><?php echo htmlspecialchars($iv['SupplierName'] ?? ''); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($inventoryReport)): ?>
                                        <tr><td colspan="6" class="text-center text-muted">No inventory items found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Suppliers Report -->
                    <div id="report-suppliers" class="report-section" style="display:none;">
                        <div class="row g-4">
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-truck-flatbed"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['suppliers']['total']; ?></div><div class="label">Total Suppliers</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['suppliers']['active']; ?></div><div class="label">Active</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon amber"><i class="bi bi-cart-plus-fill"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['suppliers']['purchases']; ?></div><div class="label">Purchases</div></div></div></div>
                        </div>
                        <div class="table-card mt-4">
                            <div class="table-header">
                                <h6><i class="bi bi-truck-flatbed" style="color:var(--primary-blue);"></i> Suppliers Report</h6>
                                <div class="d-flex gap-2">
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('suppliers', 'pdf')"><i class="bi bi-file-pdf"></i> PDF</button>
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('suppliers', 'excel')"><i class="bi bi-file-excel"></i> Excel</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-custom" id="suppliersReportTable">
                                    <thead><tr><th>CompanyName</th><th>Phone</th><th>Email</th><th>Address</th><th>Purchases</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($suppliersReport as $sr): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($sr['CompanyName']); ?></td>
                                            <td><?php echo htmlspecialchars($sr['Phone'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($sr['Email'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($sr['Address'] ?? ''); ?></td>
                                            <td><?php echo (int) $sr['PurchaseCount']; ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($suppliersReport)): ?>
                                        <tr><td colspan="5" class="text-center text-muted">No suppliers found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Purchases Report -->
                    <div id="report-purchases" class="report-section" style="display:none;">
                        <div class="row g-4">
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-cart-check"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['purchases']['total']; ?></div><div class="label">Total Purchases</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-currency-dollar"></i></div><div class="stat-info"><div class="number"><?php echo number_format($reportStats['purchases']['total_amount']); ?></div><div class="label">Total Amount (RWF)</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon amber"><i class="bi bi-clock"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['purchases']['this_month']; ?></div><div class="label">This Month</div></div></div></div>
                        </div>
                        <div class="table-card mt-4">
                            <div class="table-header">
                                <h6><i class="bi bi-cart-check" style="color:var(--primary-blue);"></i> Purchases Report</h6>
                                <div class="d-flex gap-2">
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('purchases', 'pdf')"><i class="bi bi-file-pdf"></i> PDF</button>
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('purchases', 'excel')"><i class="bi bi-file-excel"></i> Excel</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-custom" id="purchasesTable">
                                    <thead><tr><th>PurchaseID</th><th>Date</th><th>Total Amount</th><th>Supplier</th><th>User</th></tr></thead>
                                    <tbody>
                                        <?php $rowNumber = 1; foreach ($purchasesReport as $pr): ?>
                                        <tr>
                                            <td class="row-number"><?php echo $rowNumber++; ?></td>
                                            <td><?php echo date('M d, Y', strtotime($pr['PurchaseDate'])); ?></td>
                                            <td><?php echo number_format((float) $pr['TotalAmount']); ?></td>
                                            <td><?php echo htmlspecialchars($pr['SupplierName']); ?></td>
                                            <td><?php echo htmlspecialchars($pr['UserName']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($purchasesReport)): ?>
                                        <tr><td colspan="5" class="text-center text-muted">No purchases found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Payments Report -->
                    <div id="report-payments" class="report-section" style="display:none;">
                        <div class="row g-4">
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-credit-card"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['payments']['total']; ?></div><div class="label">Total Payments</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['payments']['completed']; ?></div><div class="label">Completed</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon amber"><i class="bi bi-clock-history"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['payments']['pending']; ?></div><div class="label">Pending</div></div></div></div>
                        </div>
                        <div class="table-card mt-4">
                            <div class="table-header">
                                <h6><i class="bi bi-credit-card" style="color:var(--primary-blue);"></i> Payments Report</h6>
                                <div class="d-flex gap-2">
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('payments', 'pdf')"><i class="bi bi-file-pdf"></i> PDF</button>
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('payments', 'excel')"><i class="bi bi-file-excel"></i> Excel</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-custom" id="paymentsTable">
                                    <thead><tr><th>PaymentID</th><th>Customer</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr></thead>
                                    <tbody>
                                        <?php $rowNumber = 1; foreach ($paymentsReport as $pm): ?>
                                        <tr>
                                            <td class="row-number"><?php echo $rowNumber++; ?></td>
                                            <td><?php echo htmlspecialchars($pm['CustomerName'] ?? ''); ?></td>
                                            <td><?php echo number_format((float) $pm['Amount']); ?></td>
                                            <td><?php echo htmlspecialchars($pm['PaymentMethod'] ?? ''); ?></td>
                                            <td><span class="badge-status <?php echo $pm['PaymentStatus'] === 'Paid' ? 'badge-ok' : ($pm['PaymentStatus'] === 'Pending' ? 'badge-low' : 'badge-info'); ?>"><?php echo htmlspecialchars($pm['PaymentStatus']); ?></span></td>
                                            <td><?php echo date('M d, Y', strtotime($pm['PaymentDate'])); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($paymentsReport)): ?>
                                        <tr><td colspan="6" class="text-center text-muted">No payments found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Vehicles Report -->
                    <div id="report-vehicles" class="report-section" style="display:none;">
                        <div class="row g-4">
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-truck"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['vehicles']['total']; ?></div><div class="label">Total Vehicles</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['vehicles']['active']; ?></div><div class="label">Active</div></div></div></div>
                            <div class="col-md-4"><div class="stat-card"><div class="stat-icon amber"><i class="bi bi-clock-history"></i></div><div class="stat-info"><div class="number"><?php echo $reportStats['vehicles']['inactive']; ?></div><div class="label">Inactive</div></div></div></div>
                        </div>
                        <div class="table-card mt-4">
                            <div class="table-header">
                                <h6><i class="bi bi-truck" style="color:var(--primary-blue);"></i> Vehicles Report</h6>
                                <div class="d-flex gap-2">
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('vehicles', 'pdf')"><i class="bi bi-file-pdf"></i> PDF</button>
                                    <button class="btn-outline-blue btn-sm" onclick="exportReport('vehicles', 'excel')"><i class="bi bi-file-excel"></i> Excel</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-custom" id="vehiclesTable">
                                    <thead><tr><th>VehicleID</th><th>Plate</th><th>Manufacturer</th><th>Model</th><th>Year</th><th>Transmission</th><th>Customer</th></tr></thead>
                                    <tbody>
                                        <?php $rowNumber = 1; foreach ($vehiclesReport as $vr): ?>
                                        <tr>
                                            <td class="row-number"><?php echo $rowNumber++; ?></td>
                                            <td><?php echo htmlspecialchars($vr['PlateNumber'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($vr['Manufacturer'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($vr['Model'] ?? ''); ?></td>
                                            <td><?php echo $vr['Year'] ?? ''; ?></td>
                                            <td><?php echo htmlspecialchars($vr['Transmission'] ?? ''); ?></td>
                                            <td><?php echo htmlspecialchars($vr['CustomerName'] ?? ''); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($vehiclesReport)): ?>
                                        <tr><td colspan="7" class="text-center text-muted">No vehicles found.</td></tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            6. SETTINGS (Profile & Password only)
            = -->
            <div id="tab-settings" class="tab-content" style="display:none;">
                <div class="card-custom p-4">
                    <h6 style="font-weight:700;color:var(--text-dark);margin-bottom:1.5rem;">
                        <i class="bi bi-person-circle" style="color:var(--primary-blue);"></i> Profile Settings
                    </h6>
                    <p class="text-muted">Access your profile settings via the Settings option in the user dropdown menu above.</p>
                </div>
            </div>

        </div><!-- /dashboard-content -->
    </div><!-- /dashboard-main -->


    <!-- 
    MODALS
    = -->

    <!-- PROFILE SETTINGS MODAL -->
    <div class="modal fade modal-custom" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-gear" style="color:var(--primary-blue);"></i> Profile Settings</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="profileForm" onsubmit="updateProfile(event)">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Full Name</label>
                            <input type="text" id="profileFullName" class="form-control-custom" value="<?php echo htmlspecialchars($admin['full_name'] ?? ''); ?>" required data-allow-numeric="false" pattern="[a-zA-Z\s\-']+" title="Name should contain only letters and spaces" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Username</label>
                            <input type="text" id="profileUsername" class="form-control-custom" value="<?php echo htmlspecialchars($admin['username'] ?? ''); ?>" required data-allow-numeric="true" pattern="[a-zA-Z0-9_]+" title="Username can contain letters, numbers, and underscores" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Email</label>
                            <input type="email" id="profileEmail" class="form-control-custom" value="<?php echo htmlspecialchars($admin['email'] ?? ''); ?>" required />
                        </div>
                        <div class="col-12">
                            <hr />
                            <h6 class="text-muted" style="font-size:0.9rem;">Change Password (optional)</h6>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Current Password</label>
                            <input type="password" id="profileCurrentPassword" class="form-control-custom" placeholder="Required to change password or username" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">New Password</label>
                            <input type="password" id="profileNewPassword" class="form-control-custom" placeholder="Min 6 characters" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Confirm New Password</label>
                            <input type="password" id="profileConfirmPassword" class="form-control-custom" placeholder="Confirm new password" />
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Update Profile</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- USER MODAL -->
    <div class="modal fade modal-custom" id="userModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle"><i class="bi bi-person-plus" style="color:var(--primary-blue);"></i> Add User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="userForm" onsubmit="saveUser(event)">
                    <input type="hidden" id="editingUserId" value="" />
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Role</label>
                            <select class="form-select-custom" id="roleSelect" required>
                                <option value="">Select role...</option>
                                <option value="Admin">Admin</option>
                                <option value="Receptionist">Receptionist</option>
                                <option value="Stock Manager">Stock Manager</option>
                                <option value="Mechanic">Mechanic</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Full Name</label>
                            <input type="text" id="userFullName" class="form-control-custom" required data-allow-numeric="false" pattern="[a-zA-Z\s\-']+" title="Name should contain only letters and spaces" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Phone</label>
                            <input type="tel" id="userPhone" class="form-control-custom" required pattern="^(079|078|072|073)\d{7}$" maxlength="10" title="Phone must be exactly 10 digits starting with 079, 078, 072, or 073" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Email</label>
                            <input type="email" id="userEmail" class="form-control-custom" required />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Username</label>
                            <input type="text" id="userUsername" class="form-control-custom" required data-allow-numeric="true" pattern="[a-zA-Z0-9_]+" title="Username can contain letters, numbers, and underscores" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Password</label>
                            <input type="password" id="userPassword" class="form-control-custom" required />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Confirm Password</label>
                            <input type="password" id="userConfirmPassword" class="form-control-custom" required />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Status</label>
                            <select class="form-select-custom" id="userStatus">
                                <option value="Active">Active</option>
                                <option value="Inactive">Inactive</option>
                            </select>
                        </div>
                        <!-- Mechanic-specific fields - shown only when Role = Mechanic -->
                        <div id="mechanicFields" style="display:none;">
                            <div class="col-md-6">
                                <label class="form-label-custom">Specialization</label>
                                <select class="form-select-custom" id="mechanicSpecialization">
                                    <option value="">Select specialization...</option>
                                    <option value="Engine Repair">Engine Repair</option>
                                    <option value="Electrical">Electrical</option>
                                    <option value="Brakes & Suspension">Brakes & Suspension</option>
                                    <option value="Transmission">Transmission</option>
                                    <option value="General">General</option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Salary</label>
                                <input type="number" id="mechanicSalary" class="form-control-custom" placeholder="e.g., 450000" min="0" step="0.01" data-allow-numeric="true" />
                            </div>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save User</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- NOTIFICATION MODAL -->
    <div class="modal fade modal-custom" id="notificationModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="notificationModalTitle"><i class="bi bi-bell-plus" style="color:var(--primary-blue);"></i> Add Notification</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="notificationForm" onsubmit="saveNotification(event)">
                    <input type="hidden" id="editingNotificationId" value="" />
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">User</label>
                            <select class="form-select-custom" id="notifUserId">
                                <option value="">All Users</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['UserID']; ?>"><?php echo htmlspecialchars($u['FullName']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Type</label>
                            <select class="form-select-custom" id="notifType" required>
                                <option value="system">System</option>
                                <option value="job">Job</option>
                                <option value="stock">Stock</option>
                                <option value="payment">Payment</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label-custom">Message</label>
                            <textarea id="notifMessage" class="form-control-custom" rows="3" required></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label-custom">Link (optional)</label>
                            <input type="text" id="notifLink" class="form-control-custom" placeholder="# or ?tab=..." />
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save Notification</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- NOTIFICATION DETAILS MODAL -->
    <div class="modal fade modal-custom" id="notificationDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-bell-fill" style="color:var(--primary-blue);"></i> Notification Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="notificationDetailsBody"></div>
            <div class="modal-footer view-footer">
                <button type="button" class="btn-outline-blue btn-sm" data-bs-dismiss="modal">Close</button>
            </div>
        </div></div>
    </div>

    <!-- MECHANIC MODAL -->
    <div class="modal fade modal-custom" id="mechanicModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mechanicModalTitle"><i class="bi bi-person-plus" style="color:var(--primary-blue);"></i> Add Mechanic</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="mechanicForm" onsubmit="saveMechanic(event)">
                    <input type="hidden" id="editingMechanicId" value="" />
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Full Name</label>
                            <input type="text" id="mechFullName" class="form-control-custom" required data-allow-numeric="false" pattern="[a-zA-Z\s\-']+" title="Name should contain only letters and spaces" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Phone</label>
                            <input type="tel" id="mechPhone" class="form-control-custom" required pattern="^(079|078|072|073)\d{7}$" maxlength="10" title="Phone must be exactly 10 digits starting with 079, 078, 072, or 073" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Specialization</label>
                            <select class="form-select-custom" id="mechSpecialization" required>
                                <option value="">Select specialty...</option>
                                <option value="Engine Repair">Engine Repair</option>
                                <option value="Electrical">Electrical</option>
                                <option value="Brakes & Suspension">Brakes & Suspension</option>
                                <option value="Transmission">Transmission</option>
                                <option value="Body & Paint">Body & Paint</option>
                                <option value="Diagnostics">Diagnostics</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Salary (RWF)</label>
                            <input type="number" id="mechSalary" class="form-control-custom" required min="0" step="0.01" data-allow-numeric="true" />
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save Mechanic</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- SUPPLIER MODAL -->
    <div class="modal fade modal-custom" id="supplierModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="supplierModalTitle"><i class="bi bi-truck-plus" style="color:var(--primary-blue);"></i> Add Supplier</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="supplierForm" onsubmit="saveSupplier(event)">
                    <input type="hidden" id="editingSupplierId" value="" />
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Company Name</label>
                            <input type="text" id="supCompanyName" class="form-control-custom" required data-allow-numeric="false" pattern="[a-zA-Z0-9\s\-']+" title="Company name can contain letters, numbers, and basic punctuation" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Phone</label>
                            <input type="tel" id="supPhone" class="form-control-custom" required pattern="^(079|078|072|073)\d{7}$" maxlength="10" title="Phone must be exactly 10 digits starting with 079, 078, 072, or 073" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Email</label>
                            <input type="email" id="supEmail" class="form-control-custom" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Address</label>
                            <input type="text" id="supAddress" class="form-control-custom" data-allow-numeric="true" pattern="[a-zA-Z0-9\s\-'\.,#]+" title="Address can contain letters, numbers, and basic punctuation" />
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save Supplier</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

        <!-- Shared Details Modal -->
    <div class="modal fade modal-custom" id="detailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="detailsModalTitle"></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body"><div class="view-details-grid" id="detailsModalBody"></div></div>
                <div class="modal-footer view-footer no-print">
                    <button type="button" class="btn-outline-blue btn-sm" data-bs-dismiss="modal">Close</button>
                    <button type="button" id="detailsModalPrintBtn" class="btn-blue btn-sm" onclick="printModalContent('detailsModalBody')"><i class="bi bi-printer"></i> Print</button>
                </div>
            </div>
        </div>
    </div>

    <!-- 
    SCRIPTS
    = -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js" defer></script>
    <script src="../main.js?v=<?php echo filemtime(__DIR__ . '/../main.js'); ?>" defer></script>

    <script>
        // PHP-to-JS data bridge (kept inline since this value is
        // rendered server-side; all other logic lives in main.js)
        const adminUsername = '<?php echo htmlspecialchars($admin['username'] ?? ''); ?>';

        // 
        // ADMIN PROFILE UPDATE FUNCTIONS
        // 
        function toggleMechanicFields() {
            const role = document.getElementById('roleSelect').value;
            const mechFields = document.getElementById('mechanicFields');
            if (role === 'Mechanic') {
                mechFields.style.display = 'block';
            } else {
                mechFields.style.display = 'none';
            }
        }

        function updateProfile(e) {
            e.preventDefault();

            const fullName = document.getElementById('profileFullName').value.trim();
            const username = document.getElementById('profileUsername').value.trim();
            const email = document.getElementById('profileEmail').value.trim();
            const current = document.getElementById('profileCurrentPassword').value;
            const newPass = document.getElementById('profileNewPassword').value;
            const confirm = document.getElementById('profileConfirmPassword').value;

            // Basic validation
            if (!fullName || !username || !email) {
                showToast('Full name, username, and email are required.', 'danger');
                return;
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showToast('Please enter a valid email address.', 'danger');
                return;
            }
            // If password fields are filled or username changed, validate
            if ((current || newPass || confirm) || username !== adminUsername) {
                if (!current) {
                    showToast('Current password is required to change password or username.', 'danger');
                    return;
                }
                if (newPass.length > 0 && newPass.length < 6) {
                    showToast('New password must be at least 6 characters.', 'danger');
                    return;
                }
                if (newPass !== confirm) {
                    showToast('New passwords do not match.', 'danger');
                    return;
                }
            }

            const formData = new FormData();
            formData.append('action', 'update_profile');
            formData.append('full_name', fullName);
            formData.append('username', username);
            formData.append('email', email);
            formData.append('current_password', current);
            formData.append('new_password', newPass);
            formData.append('confirm_password', confirm);

            fetch(window.location.href, {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message, 'success');
                    document.querySelector('.user-name').textContent = fullName;
                    bootstrap.Modal.getInstance(document.getElementById('profileModal')).hide();
                    document.getElementById('profileForm').reset();
                    // Reset values after modal close
                    setTimeout(() => {
                        document.getElementById('profileFullName').value = fullName;
                        document.getElementById('profileUsername').value = username;
                        document.getElementById('profileEmail').value = email;
                    }, 300);
                } else {
                    showToast(result.message, 'danger');
                }
            })
            .catch(() => showToast('Network error. Please try again.', 'danger'));
        }

        // User filtering function with pagination support
        function filterUsers() {
            const searchInput = document.getElementById('userSearch');
            const roleFilterEl = document.getElementById('userRoleFilter');
            const statusFilterEl = document.getElementById('userStatusFilter');
            const table = document.getElementById('userTable');
            
            if (!table || !roleFilterEl || !statusFilterEl) {
                console.error('Required elements not found');
                return;
            }
            
            const searchTerm = (searchInput?.value || '').trim().toLowerCase();
            const roleFilter = roleFilterEl.value.trim().toLowerCase();
            const statusFilter = statusFilterEl.value.trim().toLowerCase();
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            let visibleCount = 0;
            const filteredRows = [];
            
            rows.forEach((row) => {
                const rowRole = (row.getAttribute('data-role') || '').trim().toLowerCase();
                const rowStatus = (row.getAttribute('data-status') || '').trim().toLowerCase();
                const rowText = row.textContent.toLowerCase();
                
                let showRow = true;
                
                if (searchTerm && !rowText.includes(searchTerm)) {
                    showRow = false;
                }
                
                if (roleFilter && rowRole !== roleFilter) {
                    showRow = false;
                }
                
                if (statusFilter && rowStatus !== statusFilter) {
                    showRow = false;
                }
                
                if (showRow) {
                    visibleCount++;
                    filteredRows.push(row);
                }
            });
            
            // Store filtered rows for pagination
            window.userFilteredRows = filteredRows;
            window.userCurrentPage = 1;
            window.userItemsPerPage = 10;
            
            // Hide all rows before pagination shows the selected page
            rows.forEach(row => row.style.display = 'none');
            applyUserPagination();
            
            const countDisplay = document.getElementById('userCountDisplay');
            if (countDisplay) {
                countDisplay.textContent = `Showing ${visibleCount} users`;
            }
            
            const footer = document.getElementById('userTableFooter');
            if (footer) {
                footer.textContent = `Showing ${visibleCount} of ${rows.length} users`;
            }
        }
        
        // Pagination for user table
        function applyUserPagination() {
            const table = document.getElementById('userTable');
            if (!table) return;
            
            const tbody = table.querySelector('tbody');
            const rows = window.userFilteredRows || Array.from(tbody.querySelectorAll('tr'));
            const currentPage = window.userCurrentPage || 1;
            const itemsPerPage = window.userItemsPerPage || 10;
            
            const totalPages = Math.max(1, Math.ceil(rows.length / itemsPerPage));
            const start = (currentPage - 1) * itemsPerPage;
            const end = Math.min(currentPage * itemsPerPage, rows.length);
            
            // Show/hide rows based on pagination
            rows.forEach((row, index) => {
                const isVisible = index >= start && index < end;
                row.style.display = isVisible ? 'table-row' : 'none';
            });
            
            // Update pagination controls
            const prevBtn = document.getElementById('userPrevBtn');
            const nextBtn = document.getElementById('userNextBtn');
            const footer = document.getElementById('userTableFooter');
            
            if (prevBtn) prevBtn.disabled = currentPage === 1 || totalPages <= 1;
            if (nextBtn) nextBtn.disabled = currentPage === totalPages || totalPages <= 1;
            
            if (footer) {
                footer.textContent = `Showing ${start + 1}-${end} of ${rows.length} users`;
            }
        }
        
        function prevUserPage() {
            if (window.userCurrentPage > 1) {
                window.userCurrentPage--;
                applyUserPagination();
            }
        }
        
        function nextUserPage() {
            const rows = window.userFilteredRows || [];
            const totalPages = Math.max(1, Math.ceil(rows.length / (window.userItemsPerPage || 10)));
            
            if (window.userCurrentPage < totalPages) {
                window.userCurrentPage++;
                applyUserPagination();
            }
        }
        
        // Search functionality for user table
        function searchUsers(searchTerm) {
            const table = document.getElementById('userTable');
            if (!table) return;
            
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const roleFilter = document.getElementById('userRoleFilter').value.trim().toLowerCase();
            const statusFilter = document.getElementById('userStatusFilter').value.trim().toLowerCase();
            const normalizedSearch = (searchTerm || '').trim().toLowerCase();
            
            let visibleCount = 0;
            const filteredRows = [];
            
            rows.forEach((row) => {
                const rowRole = (row.getAttribute('data-role') || '').trim().toLowerCase();
                const rowStatus = (row.getAttribute('data-status') || '').trim().toLowerCase();
                const rowText = row.textContent.toLowerCase();
                
                let showRow = true;
                
                if (roleFilter && rowRole !== roleFilter) {
                    showRow = false;
                }
                
                if (statusFilter && rowStatus !== statusFilter) {
                    showRow = false;
                }
                
                if (normalizedSearch && !rowText.includes(normalizedSearch)) {
                    showRow = false;
                }
                
                if (showRow) {
                    visibleCount++;
                    filteredRows.push(row);
                }
            });
            
            window.userFilteredRows = filteredRows;
            window.userCurrentPage = 1;
            rows.forEach(row => row.style.display = 'none');
            applyUserPagination();
            
            const countDisplay = document.getElementById('userCountDisplay');
            if (countDisplay) {
                countDisplay.textContent = `Showing ${visibleCount} users`;
            }
        }
        
        // Sorting functionality for user table
        let userSortColumn = '';
        let userSortDirection = 'asc';
        
        function sortUsers(column) {
            const table = document.getElementById('userTable');
            if (!table) return;
            
            // Toggle direction if same column
            if (userSortColumn === column) {
                userSortDirection = userSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                userSortColumn = column;
                userSortDirection = 'asc';
            }
            
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            
            // Get column index
            const headers = table.querySelectorAll('thead th');
            let columnIndex = -1;
            headers.forEach((th, index) => {
                if (th.textContent.toLowerCase().includes(column.toLowerCase())) {
                    columnIndex = index;
                }
            });
            
            if (columnIndex === -1) return;
            
            // Sort rows
            rows.sort((a, b) => {
                const aText = a.cells[columnIndex].textContent.trim();
                const bText = b.cells[columnIndex].textContent.trim();
                
                const comparison = aText.localeCompare(bText);
                return userSortDirection === 'asc' ? comparison : -comparison;
            });
            
            // Reorder rows in DOM
            rows.forEach(row => tbody.appendChild(row));
            
            // Update sort indicators
            headers.forEach((th, index) => {
                th.classList.remove('sort-asc', 'sort-desc');
                if (index === columnIndex) {
                    th.classList.add(userSortDirection === 'asc' ? 'sort-asc' : 'sort-desc');
                }
            });
            
            // Reapply filters and pagination
            filterUsers();
        }

        // Initialize filters on page load
        document.addEventListener('DOMContentLoaded', function() {
            const userSearch = document.getElementById('userSearch');
            const roleFilter = document.getElementById('userRoleFilter');
            const statusFilter = document.getElementById('userStatusFilter');
            
            if (userSearch) {
                userSearch.addEventListener('input', filterTable);
            }
            if (roleFilter) {
                roleFilter.addEventListener('change', filterTable);
            }
            if (statusFilter) {
                statusFilter.addEventListener('change', filterTable);
            }

            window.userCurrentPage = 1;
            window.userItemsPerPage = 10;

            const userTable = document.getElementById('userTable');
            if (userTable) {
                const tbody = userTable.querySelector('tbody');
                window.userFilteredRows = Array.from(tbody.querySelectorAll('tr'));
            }

            // Apply initial table filters and pagination state when page loads
            filterTable();

            window.prevUserPage = prevUserPage;
            window.nextUserPage = nextUserPage;
            window.searchUsers = searchUsers;
            window.sortUsers = sortUsers;
        });
        
        // Enhanced delete function that works with filtering
        function deleteUser(userId, fullName) {
            if (!confirm(`Are you sure you want to delete ${fullName}? This action cannot be undone.`)) return;
            
            // Find the delete button to show loading state
            const table = document.getElementById('userTable');
            const tbody = table.querySelector('tbody');
            const row = tbody.querySelector(`tr[data-user-id="${userId}"]`);
            const buttonElement = row ? row.querySelector('.btn-action.delete') : null;
            
            if (buttonElement) {
                buttonElement.disabled = true;
                buttonElement.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            }
            
            fetch('../../backend/api/users.php?resource=users&id=' + userId, {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' }
            })
            .then(res => res.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message || 'User deleted successfully.', 'success');
                    // Remove row from table and reapply filters
                    if (row) {
                        row.remove();
                        // Reapply filters to maintain current view
                        filterUsers();
                        // Keep the "Total Users" stat cards (Dashboard + Users
                        // tab) in sync now that a row is gone, without a full
                        // page reload.
                        if (typeof refreshCounters === 'function') refreshCounters();
                    } else {
                        // Fallback to reload if row not found
                        if (typeof softReload === 'function') {
                            softReload();
                        } else {
                            location.reload();
                        }
                    }
                } else {
                    showToast(result.message || 'Failed to delete user.', 'danger');
                    if (buttonElement) {
                        buttonElement.disabled = false;
                        buttonElement.innerHTML = '<i class="bi bi-trash"></i>';
                    }
                }
            })
            .catch(() => {
                showToast('Network error. Please try again.', 'danger');
                if (buttonElement) {
                    buttonElement.disabled = false;
                    buttonElement.innerHTML = '<i class="bi bi-trash"></i>';
                }
            });
        }
        
        // Make functions globally accessible
        window.deleteUser = deleteUser;
        window.filterUsers = filterUsers;
        window.prevUserPage = prevUserPage;
        window.nextUserPage = nextUserPage;
        window.searchUsers = searchUsers;
        window.sortUsers = sortUsers;
        window.toggleMechanicFields = toggleMechanicFields;
        window.updateProfile = updateProfile;

        // Legacy helper for non-user table filtering only
        function legacyFilterTable(tableId) {
            const table = document.getElementById(tableId);
            if (!table) return;
            
            const tbody = table.querySelector('tbody');
            const rows = tbody.querySelectorAll('tr');
            
            // Get filter inputs based on table type
            let searchInput, categoryFilter, specialtyFilter;
            
            if (tableId === 'mechanicTable') {
                searchInput = document.getElementById('mechanicSearch');
                specialtyFilter = document.getElementById('mechanicSpecialtyFilter');
            } else if (tableId === 'supplierTable') {
                searchInput = document.getElementById('supplierSearch');
            } else if (tableId === 'sparePartTable') {
                searchInput = document.getElementById('sparePartSearch');
                categoryFilter = document.getElementById('sparePartCategoryFilter');
            }
            
            const searchTerm = searchInput ? searchInput.value.toLowerCase() : '';
            const categoryValue = categoryFilter ? categoryFilter.value : '';
            const specialtyValue = specialtyFilter ? specialtyFilter.value : '';
            
            let visibleCount = 0;
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                let showRow = true;
                
                // Search filter
                if (searchTerm && !text.includes(searchTerm)) {
                    showRow = false;
                }
                
                // Category filter (for spare parts table - use data-category attribute)
                if (categoryValue && tableId === 'sparePartTable') {
                    const rowCategory = row.getAttribute('data-category');
                    if (rowCategory && rowCategory !== categoryValue) {
                        showRow = false;
                    }
                }
                
                // Specialty filter (for mechanics table - use data-specialization attribute)
                if (specialtyValue && tableId === 'mechanicTable') {
                    const rowSpecialty = row.getAttribute('data-specialization');
                    if (rowSpecialty && rowSpecialty !== specialtyValue) {
                        showRow = false;
                    }
                }
                
                row.style.display = showRow ? '' : 'none';
                
                if (showRow) {
                    visibleCount++;
                }
            });
            
            // Update count display if exists
            const countDisplay = document.getElementById(tableId.replace('Table', 'CountDisplay'));
            if (countDisplay) {
                countDisplay.textContent = `Showing ${visibleCount} records`;
            }
        }
    </script>
</body>
</html>