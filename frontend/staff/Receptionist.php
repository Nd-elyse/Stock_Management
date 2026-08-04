<?php
require_once __DIR__ . '/../../backend/includes/auth.php';
require_once __DIR__ . '/../../backend/config/db.php';
require_once __DIR__ . '/../../backend/includes/csrf.php';

// Enforce Receptionist role
require_role('Receptionist');
$me = current_user();
$_SESSION['receptionist_name'] = $me['full_name'] ?? 'Reception User';
$_SESSION['receptionist_role'] = $me['role'] ?? 'Receptionist';

// Set default tab to dashboard on first load
if (!isset($_SESSION['current_tab'])) {
    $_SESSION['current_tab'] = 'dashboard';
}

// Generate CSRF token for forms
$csrf_token = generate_csrf_token();

handle_profile_update_request($pdo, 'receptionist_name', true);


// 0. ENSURE INVOICE TABLE HAS REQUIRED COLUMNS

try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS LabourCharges DECIMAL(10,2) DEFAULT 0");
    $pdo->exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS SparePartsCost DECIMAL(10,2) DEFAULT 0");
    $pdo->exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS Taxes DECIMAL(10,2) DEFAULT 0");
    $pdo->exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS Discounts DECIMAL(10,2) DEFAULT 0");
    // Also add VehicleID if not exists (optional, for linking)
    $pdo->exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS VehicleID INT(11) DEFAULT NULL");
} catch (PDOException $e) {
    // ignore if columns already exist or table not found
}


// 1. DASHBOARD STATS (real counts) - Optimized to use a single query

$stats = $pdo->query(
    "SELECT 
        (SELECT COUNT(*) FROM customers) AS total_customers,
        (SELECT COUNT(*) FROM vehicles) AS total_vehicles,
        (SELECT COUNT(*) FROM repairjobs) AS total_jobs,
        (SELECT COUNT(*) FROM invoices) AS total_invoices,
        (SELECT COUNT(*) FROM payments) AS total_payments,
        (SELECT COUNT(*) FROM repairjobs WHERE Status = 'Pending') AS pending_jobs,
        (SELECT COUNT(*) FROM repairjobs WHERE Status NOT IN ('Delivered','Cancelled')) AS active_jobs,
        (SELECT COUNT(*) FROM invoices i WHERE i.InvoiceID NOT IN (SELECT DISTINCT InvoiceID FROM payments)) AS unpaid_invoices"
)->fetch();

$totalCustomers = (int) $stats['total_customers'];
$totalVehicles  = (int) $stats['total_vehicles'];
$totalJobs      = (int) $stats['total_jobs'];
$totalInvoices  = (int) $stats['total_invoices'];
$totalPayments  = (int) $stats['total_payments'];
$pendingJobs    = (int) $stats['pending_jobs'];
$activeJobs     = (int) $stats['active_jobs'];
$unpaidInvoices = (int) $stats['unpaid_invoices'];


// 2. CUSTOMERS

$customers = $pdo->query(
    "SELECT c.*, COUNT(v.VehicleID) AS VehicleCount
     FROM customers c
     LEFT JOIN vehicles v ON v.CustomerID = c.CustomerID
     GROUP BY c.CustomerID
     ORDER BY c.CustomerID"
)->fetchAll();


// 3. VEHICLES (with owner name)

$vehicles = $pdo->query(
    "SELECT v.*, c.FullName AS OwnerName
     FROM vehicles v
     LEFT JOIN customers c ON c.CustomerID = v.CustomerID
     ORDER BY v.VehicleID"
)->fetchAll();


// 4. REPAIR JOBS (with customer, vehicle, mechanic names)

$repairJobs = $pdo->query(
    "SELECT rj.*,
            c.FullName AS CustomerName,
            v.PlateNumber,
            m.FullName AS MechanicName
     FROM repairjobs rj
     LEFT JOIN vehicles v ON v.VehicleID = rj.VehicleID
     LEFT JOIN customers c ON c.CustomerID = v.CustomerID
     LEFT JOIN mechanics m ON m.MechanicID = rj.MechanicID
     ORDER BY rj.JobID DESC"
)->fetchAll();

// Job status breakdown for stats (used elsewhere, but cards removed from UI)
$statusCounts = $pdo->query("
    SELECT Status, COUNT(*) as count 
    FROM repairjobs 
    WHERE Status IN ('Pending','Diagnosed','In Progress','Awaiting Parts','Ready for Collection','Delivered','Cancelled')
    GROUP BY Status
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Ensure all statuses exist with 0 count
$allStatuses = ['Pending','Diagnosed','In Progress','Awaiting Parts','Ready for Collection','Delivered','Cancelled'];
$statusCounts = array_merge(array_fill_keys($allStatuses, 0), $statusCounts);


// 5. INVOICES (with customer name and all breakdown fields)

$invoices = $pdo->query(
    "SELECT i.*, 
            c.FullName AS CustomerName,
            v.PlateNumber,
            v.Model AS VehicleModel,
            COALESCE(SUM(p.Amount), 0) AS TotalPaid,
            (SELECT p2.PaymentMethod FROM payments p2 WHERE p2.InvoiceID = i.InvoiceID ORDER BY p2.PaymentID DESC LIMIT 1) AS PaymentMethod,
            (SELECT p2.PaymentID FROM payments p2 WHERE p2.InvoiceID = i.InvoiceID ORDER BY p2.PaymentID DESC LIMIT 1) AS PaymentID
     FROM invoices i
     LEFT JOIN customers c ON c.CustomerID = i.CustomerID
     LEFT JOIN vehicles v ON v.VehicleID = i.VehicleID
     LEFT JOIN payments p ON p.InvoiceID = i.InvoiceID
     GROUP BY i.InvoiceID
     ORDER BY i.InvoiceID DESC"
)->fetchAll();
// PaymentStatus is now derived from the actual total paid vs. the invoice
// total (same logic as backend/api/billing.php) instead of coming from a
// single arbitrary payment row, which is what caused the duplication above.
foreach ($invoices as &$inv) {
    $totalPaid = (float) $inv['TotalPaid'];
    $totalDue = (float) $inv['TotalAmount'];
    if ($totalPaid <= 0) {
        $inv['PaymentStatus'] = 'Pending';
    } elseif ($totalPaid + 0.01 < $totalDue) {
        $inv['PaymentStatus'] = 'Partial';
    } else {
        $inv['PaymentStatus'] = 'Paid';
    }
}
unset($inv);

// Payment stats
// Bug fix: these used to be COUNT(*) over a JOIN with payments, so an invoice
// with multiple Paid (or multiple Partial) payment rows -- e.g. an invoice
// paid off across several installments -- was counted once per row instead
// of once per invoice. COUNT(DISTINCT i.InvoiceID) fixes that.
$paidInvoices = (int) $pdo->query("SELECT COUNT(DISTINCT i.InvoiceID) FROM invoices i JOIN payments p ON p.InvoiceID = i.InvoiceID WHERE p.PaymentStatus = 'Paid'")->fetchColumn();
$partialInvoices = (int) $pdo->query("SELECT COUNT(DISTINCT i.InvoiceID) FROM invoices i JOIN payments p ON p.InvoiceID = i.InvoiceID WHERE p.PaymentStatus = 'Partial'")->fetchColumn();
$pendingInvoices = $totalInvoices - $paidInvoices - $partialInvoices; // not perfect but approximate


// 6. PAYMENTS (with customer name)

$payments = $pdo->query(
    "SELECT p.*, c.FullName AS CustomerName
     FROM payments p
     JOIN invoices i ON i.InvoiceID = p.InvoiceID
     JOIN customers c ON c.CustomerID = i.CustomerID
     ORDER BY p.PaymentID DESC"
)->fetchAll();

// Payment stats
$totalInvoiced = (float) $pdo->query("SELECT COALESCE(SUM(TotalAmount),0) FROM invoices")->fetchColumn();
// Bug fix: this used to only sum payments where PaymentStatus = 'Paid', which
// excludes every dollar collected via a Partial payment -- Amount already
// received on an invoice that isn't fully settled yet was invisible to this
// stat. Every recorded payment row represents money actually collected,
// regardless of whether its invoice ended up fully paid, so sum them all.
$totalCollected = (float) $pdo->query("SELECT COALESCE(SUM(Amount),0) FROM payments")->fetchColumn();
$outstanding = $totalInvoiced - $totalCollected;
$collectionRate = $totalInvoiced > 0 ? round(($totalCollected / $totalInvoiced) * 100, 1) : 0;


// 7. NOTIFICATIONS (from database)

// Ensure table exists (shared with Admin.php)
ensure_notifications_table($pdo);

$notifications = $pdo->prepare(
    "SELECT n.*, u.FullName AS UserFullName
     FROM notifications n
     LEFT JOIN users u ON u.UserID = n.UserID
     WHERE n.UserID = ? OR n.UserID IS NULL
     ORDER BY n.CreatedAt DESC
     LIMIT 50"
);
$notifications->execute([$_SESSION['user']['id']]);
$notifications = $notifications->fetchAll();

$unreadCount = count(array_filter($notifications, fn($n) => $n['IsRead'] == 0));


// 8. Mechanics list for dropdowns

$mechanics = $pdo->query('SELECT MechanicID, FullName FROM mechanics ORDER BY FullName')->fetchAll();


// 9. Users list for notification dropdown

$users = $pdo->query('SELECT UserID, FullName FROM users ORDER BY FullName')->fetchAll();


// 10. Fetch vehicle list for invoice modal (optional)

$allVehicles = $pdo->query("SELECT VehicleID, PlateNumber, Model, Manufacturer, CustomerID FROM vehicles ORDER BY PlateNumber")->fetchAll();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>" />
    <title>Receptionist Dashboard | GarageManager</title>
    <!-- External CSS & Fonts -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;400;600;700;800&display=swap" rel="stylesheet" />
    <!-- Staff custom CSS -->
    <link rel="stylesheet" href="../staff.css" />
    <!-- Chart.js for dashboard charts (optional, but we keep) -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
    <!-- Invoice print styling now lives in style.css (scoped to
         body.site-staff[data-page="receptionist"]) -->
</head>
<body class="site-staff" data-page="receptionist">

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
                <small>Reception Desk</small>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">Main</div>
            <a href="#" onclick="switchTab('dashboard', event)" id="nav-dashboard" class="active">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
            <a href="#" onclick="switchTab('customers', event)" id="nav-customers">
                <i class="bi bi-people-fill"></i> Customers
            </a>
            <a href="#" onclick="switchTab('vehicles', event)" id="nav-vehicles">
                <i class="bi bi-car-front-fill"></i> Vehicles
            </a>
            <a href="#" onclick="switchTab('repairjobs', event)" id="nav-repairjobs">
                <i class="bi bi-clipboard-check-fill"></i> Repair Jobs
            </a>
            <a href="#" onclick="switchTab('invoices', event)" id="nav-invoices">
                <i class="bi bi-receipt-cutoff"></i> Invoices
            </a>
            <a href="#" onclick="switchTab('payments', event)" id="nav-payments">
                <i class="bi bi-cash-coin"></i> Payments
            </a>
            <a href="#" onclick="switchTab('notifications', event)" id="nav-notifications">
                <i class="bi bi-bell-fill"></i> Notifications
                <?php if ($unreadCount > 0): ?>
                <span class="badge bg-danger rounded-pill ms-auto" style="font-size:0.6rem;"><?php echo $unreadCount; ?></span>
                <?php endif; ?>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="dropdown">
                <div class="user-info" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar"><?php echo substr($_SESSION['receptionist_name'] ?? 'RU', 0, 2); ?></div>
                    <div>
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['receptionist_name'] ?? 'Reception User'); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars($_SESSION['receptionist_role'] ?? 'Reception Desk'); ?></div>
                    </div>
                </div>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="bi bi-gear"></i>Settings</a></li>
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
                <h5>
                    <span id="pageTitle">Dashboard</span>
                </h5>
            </div>
            <div class="topbar-actions">
                <div class="search-box d-none d-md-block">
                    <i class="bi bi-search"></i>
                    <input type="text" placeholder="Search..." id="globalSearch" onkeyup="filterTable()" />
                </div>
                <button class="btn-action position-relative" onclick="switchTab('notifications', event)">
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
                <!-- Welcome card -->
                <div class="card-custom p-4">
                    <h6 style="font-weight:700;color:var(--text-dark);">Welcome to the Reception Dashboard</h6>
                    <p class="text-muted">Quick overview of your daily operations. Use the side menu to manage customers, vehicles, repair jobs, invoices, payments, and notifications.</p>
                </div>
                <div class="row g-4 mb-4 mt-1">
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-calendar2-check"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $pendingJobs; ?></div>
                                <div class="label">Pending Requests</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalCustomers; ?></div>
                                <div class="label">Customers</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-clipboard-check-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $activeJobs; ?></div>
                                <div class="label">Active Jobs</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="bi bi-receipt-cutoff"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $unpaidInvoices; ?></div>
                                <div class="label">Unpaid</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            CUSTOMERS TAB (with CRUD)
            = -->
            <div id="tab-customers" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalCustomers; ?></div>
                                <div class="label">Total Records</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalCustomers; ?></div>
                                <div class="label">Active</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-hourglass-split"></i></div>
                            <div class="stat-info">
                                <div class="number">0</div>
                                <div class="label">Pending</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="bi bi-x-circle-fill"></i></div>
                            <div class="stat-info">
                                <div class="number">0</div>
                                <div class="label">Inactive</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-people-fill" style="color:var(--primary-blue);"></i> Customer List</h6>
                        <div class="d-flex gap-2">
                            <div class="search-box"><i class="bi bi-search"></i><input type="text" class="search-input" data-live-search="customerTable" placeholder="Search..." onkeyup="filterTable(this,'customerTable')" /></div>
                            <button class="btn-blue" data-bs-toggle="modal" data-bs-target="#customerModal"><i class="bi bi-plus-lg"></i> Add Customer</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="customerTable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Full Name</th>
                                    <th>Phone</th>
                                    <th>Email</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowNumber = 1; foreach ($customers as $c): ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo htmlspecialchars($c['FullName']); ?></td>
                                    <td><?php echo htmlspecialchars($c['Phone'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($c['Email'] ?? ''); ?></td>
                                    <td>
                                        <button class="btn-action view" onclick="viewCustomer(<?php echo $c['CustomerID']; ?>)"><i class="bi bi-eye"></i></button>
                                        <button class="btn-action edit" onclick="editCustomer(<?php echo htmlspecialchars(json_encode($c), ENT_QUOTES); ?>)"><i class="bi bi-pencil"></i></button>
                                        <button class="btn-action delete" onclick="deleteCustomer(<?php echo $c['CustomerID']; ?>, '<?php echo htmlspecialchars($c['FullName'], ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($customers)): ?>
                                <tr><td colspan="5" class="text-center text-muted">No customers found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <span style="font-size:0.85rem;color:var(--text-muted);">Showing <?php echo count($customers); ?> customers</span>
                        <div class="pagination-wrapper">
                            <button class="btn-outline-blue" onclick="prevPage()">Previous</button>
                            <button class="btn-blue" onclick="nextPage()">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            VEHICLES TAB (with CRUD)
            = -->
            <div id="tab-vehicles" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-car-front-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalVehicles; ?></div>
                                <div class="label">Total Records</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalVehicles; ?></div>
                                <div class="label">Active</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-hourglass-split"></i></div>
                            <div class="stat-info">
                                <div class="number">0</div>
                                <div class="label">Pending</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="bi bi-x-circle-fill"></i></div>
                            <div class="stat-info">
                                <div class="number">0</div>
                                <div class="label">Inactive</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-car-front-fill" style="color:var(--primary-blue);"></i> Vehicle List</h6>
                        <div class="d-flex gap-2">
                            <div class="search-box"><i class="bi bi-search"></i><input type="text" class="search-input" data-live-search="vehicleTable" placeholder="Search..." onkeyup="filterTable(this,'vehicleTable')" /></div>
                            <button class="btn-blue" data-bs-toggle="modal" data-bs-target="#vehicleModal"><i class="bi bi-plus-lg"></i> Add Vehicle</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="vehicleTable">
                            <thead>
                                <tr>
                                    <th>VehicleID</th>
                                    <th>Owner</th>
                                    <th>Plate Number</th>
                                    <th>Transmission</th>
                                    <th>Engine Number</th>
                                    <th>Manufacturer</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowNumber = 1; foreach ($vehicles as $v): ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo htmlspecialchars($v['OwnerName'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($v['PlateNumber'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($v['Transmission'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($v['EngineNumber'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($v['Manufacturer'] ?? ''); ?></td>
                                    <td>
                                        <button class="btn-action view" onclick="viewVehicle(<?php echo $v['VehicleID']; ?>)"><i class="bi bi-eye"></i></button>
                                        <button class="btn-action edit" onclick="editVehicle(<?php echo htmlspecialchars(json_encode($v), ENT_QUOTES); ?>)"><i class="bi bi-pencil"></i></button>
                                        <button class="btn-action delete" onclick="deleteVehicle(<?php echo $v['VehicleID']; ?>, '<?php echo htmlspecialchars($v['PlateNumber'] ?? 'this vehicle', ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($vehicles)): ?>
                                <tr><td colspan="7" class="text-center text-muted">No vehicles found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <span style="font-size:0.85rem;color:var(--text-muted);">Showing <?php echo count($vehicles); ?> vehicles</span>
                        <div class="pagination-wrapper">
                            <button class="btn-outline-blue" onclick="prevPage()">Previous</button>
                            <button class="btn-blue" onclick="nextPage()">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            REPAIR JOBS TAB (with CRUD) - STATUS CARDS REMOVED
            = -->
            <div id="tab-repairjobs" class="tab-content" style="display:none;">
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-wrench-adjustable" style="color:var(--primary-blue);"></i> Repair Jobs</h6>
                        <div class="d-flex gap-2">
                            <div class="search-box"><i class="bi bi-search"></i><input type="text" class="search-input" data-live-search="jobTable" placeholder="Search..." onkeyup="filterTable(this,'jobTable')" /></div>
                            <button class="btn-blue" data-bs-toggle="modal" data-bs-target="#jobModal"><i class="bi bi-plus-lg"></i> Add Repair Job</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="jobTable">
                            <thead>
                                <tr>
                                    <th>Job</th>
                                    <th>Customer</th>
                                    <th>Vehicle</th>
                                    <th>Mechanic</th>
                                    <th>Start Date</th>
                                    <th>End Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowNumber = 1; foreach ($repairJobs as $rj): ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo htmlspecialchars($rj['CustomerName'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($rj['PlateNumber'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($rj['MechanicName'] ?? 'Unassigned'); ?></td>
                                    <td><?php echo $rj['StartDate'] ? date('M d, Y', strtotime($rj['StartDate'])) : ''; ?></td>
                                    <td><?php echo $rj['EndDate'] ? date('M d, Y', strtotime($rj['EndDate'])) : ''; ?></td>
                                    <td><span class="badge-status <?php echo $rj['Status'] == 'Delivered' ? 'badge-ok' : ($rj['Status'] == 'Pending' ? 'badge-low' : 'badge-info'); ?>"><?php echo htmlspecialchars($rj['Status']); ?></span></td>
                                    <td>
                                        <button class="btn-action view" onclick="viewJob(<?php echo $rj['JobID']; ?>)"><i class="bi bi-eye"></i></button>
                                        <button class="btn-action edit" onclick="editJob(<?php echo htmlspecialchars(json_encode($rj), ENT_QUOTES); ?>)"><i class="bi bi-pencil"></i></button>
                                        <button class="btn-action delete" onclick="deleteJob(<?php echo $rj['JobID']; ?>, '<?php echo 'Job '.$rj['JobID']; ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($repairJobs)): ?>
                                <tr><td colspan="8" class="text-center text-muted">No repair jobs found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <span style="font-size:0.85rem;color:var(--text-muted);">Showing <?php echo count($repairJobs); ?> jobs</span>
                        <div class="pagination-wrapper">
                            <button class="btn-outline-blue" onclick="prevPage()">Previous</button>
                            <button class="btn-blue" onclick="nextPage()">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            INVOICES TAB (with full details)
            = -->
            <div id="tab-invoices" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-receipt"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $totalInvoices; ?></div>
                                <div class="label">Total</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $paidInvoices; ?></div>
                                <div class="label">Paid</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-hourglass-split"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $pendingInvoices; ?></div>
                                <div class="label">Pending</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="bi bi-currency-exchange"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $partialInvoices; ?></div>
                                <div class="label">Partial</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-receipt-cutoff" style="color:var(--primary-blue);"></i> Invoice List</h6>
                        <div class="d-flex gap-2">
                            <div class="search-box"><i class="bi bi-search"></i><input type="text" class="search-input" data-live-search="invTable" placeholder="Search..." onkeyup="filterTable(this,'invTable')" /></div>
                            <button class="btn-blue" data-bs-toggle="modal" data-bs-target="#invoiceModal"><i class="bi bi-plus-lg"></i> Add Invoice</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="invTable">
                            <thead>
                                <tr>
                                    <th>Invoice</th>
                                    <th>Customer</th>
                                    <th>Labour (RWF)</th>
                                    <th>Spare Parts (RWF)</th>
                                    <th>Taxes (RWF)</th>
                                    <th>Discounts (RWF)</th>
                                    <th>Total (RWF)</th>
                                    <th>Payment Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowNumber = 1; foreach ($invoices as $inv): ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo htmlspecialchars($inv['CustomerName'] ?? ''); ?></td>
                                    <td><?php echo number_format((float) ($inv['LabourCharges'] ?? 0)); ?></td>
                                    <td><?php echo number_format((float) ($inv['SparePartsCost'] ?? 0)); ?></td>
                                    <td><?php echo number_format((float) ($inv['Taxes'] ?? 0)); ?></td>
                                    <td><?php echo number_format((float) ($inv['Discounts'] ?? 0)); ?></td>
                                    <td><?php echo number_format((float) ($inv['TotalAmount'] ?? 0)); ?></td>
                                    <td>
                                        <?php
                                        $status = $inv['PaymentStatus'] ?? 'Pending';
                                        $badge = $status == 'Paid' ? 'badge-ok' : ($status == 'Partial' ? 'badge-low' : 'badge-danger');
                                        ?>
                                        <span class="badge-status <?php echo $badge; ?>"><?php echo htmlspecialchars($status); ?></span>
                                    </td>
                                    <td class="invoice-actions">
                                        <button class="btn-action view" onclick="printInvoiceDirectly(<?php echo $inv['InvoiceID']; ?>)"><i class="bi bi-printer"></i></button>
                                        <button class="btn-action edit" onclick="editInvoice(<?php echo htmlspecialchars(json_encode($inv), ENT_QUOTES); ?>)"><i class="bi bi-pencil"></i></button>
                                        <button class="btn-action delete" onclick="deleteInvoice(<?php echo $inv['InvoiceID']; ?>, '<?php echo 'Invoice '.$inv['InvoiceID']; ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($invoices)): ?>
                                <tr><td colspan="9" class="text-center text-muted">No invoices found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <span style="font-size:0.85rem;color:var(--text-muted);">Showing <?php echo count($invoices); ?> invoices</span>
                        <div class="pagination-wrapper">
                            <button class="btn-outline-blue" onclick="prevPage()">Previous</button>
                            <button class="btn-blue" onclick="nextPage()">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            PAYMENTS TAB (with CRUD)
            = -->
            <div id="tab-payments" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-cash-stack"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo number_format($totalInvoiced); ?></div>
                                <div class="label">Invoiced</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-cash-coin"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo number_format($totalCollected); ?></div>
                                <div class="label">Collected</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="bi bi-currency-exchange"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo number_format($outstanding); ?></div>
                                <div class="label">Outstanding</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-percent"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo $collectionRate; ?>%</div>
                                <div class="label">Rate</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-cash-coin" style="color:var(--primary-blue);"></i> Payment Records</h6>
                        <div class="d-flex gap-2">
                            <div class="search-box"><i class="bi bi-search"></i><input type="text" class="search-input" data-live-search="payTable" placeholder="Search..." onkeyup="filterTable(this,'payTable')" /></div>
                            <button class="btn-blue" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="bi bi-plus-lg"></i> Add Payment</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="payTable">
                            <thead>
                                <tr>
                                    <th>Payment</th>
                                    <th>Customer</th>
                                    <th>Amount</th>
                                    <th>Method</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $rowNumber = 1; foreach ($payments as $pay): ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo htmlspecialchars($pay['CustomerName'] ?? ''); ?></td>
                                    <td><?php echo number_format((float) $pay['Amount']); ?></td>
                                    <td><?php echo htmlspecialchars($pay['PaymentMethod'] ?? ''); ?></td>
                                    <td><?php echo date('M d, Y', strtotime($pay['PaymentDate'])); ?></td>
                                    <td><span class="badge-status <?php echo $pay['PaymentStatus'] == 'Paid' ? 'badge-ok' : ($pay['PaymentStatus'] == 'Partial' ? 'badge-low' : 'badge-danger'); ?>"><?php echo htmlspecialchars($pay['PaymentStatus']); ?></span></td>
                                    <td>
                                        <button class="btn-action view" onclick="viewPayment(<?php echo $pay['PaymentID']; ?>)"><i class="bi bi-eye"></i></button>
                                        <button class="btn-action edit" onclick="editPayment(<?php echo htmlspecialchars(json_encode($pay), ENT_QUOTES); ?>)"><i class="bi bi-pencil"></i></button>
                                        <button class="btn-action delete" onclick="deletePayment(<?php echo $pay['PaymentID']; ?>, '<?php echo 'Payment '.$pay['PaymentID']; ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($payments)): ?>
                                <tr><td colspan="7" class="text-center text-muted">No payments found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <span style="font-size:0.85rem;color:var(--text-muted);">Showing <?php echo count($payments); ?> payments</span>
                        <div class="pagination-wrapper">
                            <button class="btn-outline-blue" onclick="prevPage()">Previous</button>
                            <button class="btn-blue" onclick="nextPage()">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            NOTIFICATIONS TAB (from database)
            = -->
            <div id="tab-notifications" class="tab-content" style="display:none;">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h6 style="font-weight:700;color:var(--text-dark);"><i class="bi bi-bell-fill" style="color:var(--primary-blue);"></i> All Notifications</h6>
                        <div class="d-flex gap-2">
                            <button class="btn-outline-blue btn-sm" onclick="markAllRead()"><i class="bi bi-check-all"></i> Mark All Read</button>
                            
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
                            $userId = $n['UserID'] ?? null;
                            $isBroadcast = $userId === null;
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
                                <?php if (!$isRead && !$isBroadcast): ?>
                                <button class="btn-action" onclick="markNotificationRead(<?php echo $notifId; ?>)" title="Mark as read">
                                    <i class="bi bi-check-circle"></i>
                                </button>
                                <?php endif; ?>
                                <?php if (!$isBroadcast): ?>
                                <button class="btn-action delete" onclick="deleteNotification(<?php echo $notifId; ?>)">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div><!-- /dashboard-content -->
    </div><!-- /dashboard-main -->


    <!-- 
    MODALS (CRUD forms)
    = -->

    <!-- Customer Modal -->
    <div class="modal fade modal-custom" id="customerModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="customerModalTitle"><i class="bi bi-person-plus" style="color:var(--primary-blue);"></i> Add Customer</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="customerForm" onsubmit="saveCustomer(event)">
                    <input type="hidden" id="editingCustomerId" value="" />
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Full Name</label>
                            <input type="text" id="customerFullName" class="form-control-custom" required data-allow-numeric="false" pattern="[a-zA-Z\s\-']+" title="Name should contain only letters and spaces" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Phone</label>
                            <input type="tel" id="customerPhone" class="form-control-custom" required pattern="^(079|078|072|073)\d{7}$" maxlength="10" title="Phone must be exactly 10 digits starting with 079, 078, 072, or 073" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Email</label>
                            <input type="email" id="customerEmail" class="form-control-custom" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Address</label>
                            <input type="text" id="customerAddress" class="form-control-custom" data-allow-numeric="true" pattern="[a-zA-Z0-9\s\-'\.,#]+" title="Address can contain letters, numbers, and basic punctuation" />
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- Vehicle Modal -->
    <div class="modal fade modal-custom" id="vehicleModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="vehicleModalTitle"><i class="bi bi-car-front" style="color:var(--primary-blue);"></i> Add Vehicle</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="vehicleForm" onsubmit="saveVehicle(event)">
                    <input type="hidden" id="editingVehicleId" value="" />
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Owner</label>
                            <select id="vehicleOwner" class="form-select-custom" required>
                                <option value="">Select owner...</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?php echo $c['CustomerID']; ?>"><?php echo htmlspecialchars($c['FullName']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Plate Number</label>
                            <input type="text" id="vehiclePlate" class="form-control-custom" required data-allow-numeric="true" pattern="[a-zA-Z0-9\s\-]+" title="Plate number can contain letters, numbers, and hyphens" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Manufacturer</label>
                            <input type="text" id="vehicleMake" class="form-control-custom" required data-allow-numeric="false" pattern="[a-zA-Z\s\-']+" title="Manufacturer should contain only letters and spaces" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Model</label>
                            <input type="text" id="vehicleModel" class="form-control-custom" required data-allow-numeric="true" pattern="[a-zA-Z0-9\s\-']+" title="Model can contain letters, numbers, and basic punctuation" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Year</label>
                            <input type="text" id="vehicleYear" class="form-control-custom" required pattern="\d{4}" min="1900" max="<?php echo date('Y') + 1; ?>" data-allow-numeric="true" data-year-field="true" title="Year must be exactly 4 digits (e.g., 2026)" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Chassis Number</label>
                            <input type="text" id="vehicleChassis" class="form-control-custom" data-allow-numeric="true" pattern="[a-zA-Z0-9\-]+" title="Chassis number can contain letters, numbers, and hyphens" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Engine Number</label>
                            <input type="text" id="vehicleEngine" class="form-control-custom" data-allow-numeric="true" pattern="[a-zA-Z0-9\-]+" title="Engine number can contain letters, numbers, and hyphens" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Fuel Type</label>
                            <select id="vehicleFuel" class="form-select-custom">
                                <option value="Petrol">Petrol</option>
                                <option value="Diesel">Diesel</option>
                                <option value="Electric">Electric</option>
                                <option value="Hybrid">Hybrid</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Transmission</label>
                            <select id="vehicleTransmission" class="form-select-custom">
                                <option value="Manual">Manual</option>
                                <option value="Automatic">Automatic</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Mileage</label>
                            <input type="number" id="vehicleMileage" class="form-control-custom" min="0" data-allow-numeric="true" />
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- Repair Job Modal -->
    <div class="modal fade modal-custom" id="jobModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="jobModalTitle"><i class="bi bi-tools" style="color:var(--primary-blue);"></i> Add Repair Job</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="jobForm" onsubmit="saveJob(event)">
                    <input type="hidden" id="editingJobId" value="" />
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Vehicle</label>
                            <select id="jobVehicle" class="form-select-custom" required>
                                <option value="">Select vehicle...</option>
                                <?php foreach ($vehicles as $v): ?>
                                <option value="<?php echo $v['VehicleID']; ?>"><?php echo htmlspecialchars($v['PlateNumber'] . ' - ' . ($v['OwnerName'] ?? '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Mechanic</label>
                            <select id="jobMechanic" class="form-select-custom" required>
                                <option value="">Select mechanic...</option>
                                <?php foreach ($mechanics as $m): ?>
                                <option value="<?php echo $m['MechanicID']; ?>"><?php echo htmlspecialchars($m['FullName']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Start Date</label>
                            <input type="date" id="jobStartDate" class="form-control-custom" min="2000-01-01" required />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">End Date</label>
                            <input type="date" id="jobEndDate" class="form-control-custom" min="2000-01-01" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Status</label>
                            <select id="jobStatus" class="form-select-custom" required>
                                <option value="Pending">Pending</option>
                                <option value="Diagnosed">Diagnosed</option>
                                <option value="In Progress">In Progress</option>
                                <option value="Awaiting Parts">Awaiting Parts</option>
                                <option value="Ready for Collection">Ready for Collection</option>
                                <option value="Delivered">Delivered</option>
                                <option value="Cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- Invoice Modal (Add/Edit) -->
    <div class="modal fade modal-custom" id="invoiceModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="invoiceModalTitle"><i class="bi bi-receipt" style="color:var(--primary-blue);"></i> Add Invoice</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="invoiceForm" onsubmit="saveInvoice(event)">
                    <input type="hidden" id="editingInvoiceId" value="" />
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Customer</label>
                            <select id="invoiceCustomer" class="form-select-custom" required>
                                <option value="">Select customer...</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?php echo $c['CustomerID']; ?>"><?php echo htmlspecialchars($c['FullName']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Job</label>
                            <select id="invoiceJob" class="form-select-custom">
                                <option value="">Optional</option>
                                <?php foreach ($repairJobs as $rj): ?>
                                <option value="<?php echo $rj['JobID']; ?>">#<?php echo $rj['JobID']; ?> - <?php echo htmlspecialchars($rj['CustomerName'] ?? ''); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Vehicle</label>
                            <select id="invoiceVehicle" class="form-select-custom">
                                <option value="">Select vehicle (optional)</option>
                                <?php foreach ($allVehicles as $v): ?>
                                <option value="<?php echo $v['VehicleID']; ?>"><?php echo htmlspecialchars($v['PlateNumber'] . ' - ' . $v['Model']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Invoice Date</label>
                            <input type="date" id="invoiceDate" class="form-control-custom" min="2000-01-01" required />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Labour Charges (RWF)</label>
                            <input type="number" id="invoiceLabour" class="form-control-custom" step="0.01" value="0" oninput="calculateTotal()" min="0" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Spare Parts Cost (RWF)</label>
                            <input type="number" id="invoiceParts" class="form-control-custom" step="0.01" value="0" oninput="calculateTotal()" min="0" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Tax Rate (%)</label>
                            <input type="number" id="invoiceTaxRate" class="form-control-custom" step="0.1" value="18" oninput="calculateTotal()" placeholder="e.g., 18" min="0" max="100" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Tax Amount (RWF)</label>
                            <input type="number" id="invoiceTaxes" class="form-control-custom" step="0.01" value="0" oninput="calculateTotal()" min="0" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Discount Rate (%)</label>
                            <input type="number" id="invoiceDiscountRate" class="form-control-custom" step="0.1" value="0" oninput="calculateTotal()" placeholder="e.g., 10" min="0" max="100" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label-custom">Discount Amount (RWF)</label>
                            <input type="number" id="invoiceDiscounts" class="form-control-custom" step="0.01" value="0" oninput="calculateTotal()" min="0" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Total Amount (auto-calc)</label>
                            <input type="number" id="invoiceTotal" class="form-control-custom" step="0.01" readonly data-allow-numeric="true" />
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- View Invoice Modal (Detailed) -->
    <div class="modal fade modal-custom invoice-modal" id="viewInvoiceModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-receipt" style="color:var(--primary-blue);"></i> Invoice Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewInvoiceBody">
                <!-- Dynamic content loaded via JS -->
                <div id="invoicePrintArea">
                    <!-- Will be filled by JavaScript -->
                </div>
            </div>
            <div class="modal-footer view-footer no-print">
                <button type="button" class="btn-outline-blue btn-sm" data-bs-dismiss="modal">Close</button>
                <button class="btn-blue btn-sm" onclick="printInvoice()"><i class="bi bi-printer"></i> Print</button>
            </div>
        </div></div>
    </div>

    <!-- Payment Modal -->
    <div class="modal fade modal-custom" id="paymentModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="paymentModalTitle"><i class="bi bi-cash" style="color:var(--primary-blue);"></i> Add Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="paymentForm" onsubmit="savePayment(event)">
                    <input type="hidden" id="editingPaymentId" value="" />
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Invoice</label>
                            <select id="paymentInvoice" class="form-select-custom" required>
                                <option value="">Select invoice...</option>
                                <?php foreach ($invoices as $inv): ?>
                                <option value="<?php echo $inv['InvoiceID']; ?>"><?php echo $inv['InvoiceID']; ?> - <?php echo htmlspecialchars($inv['CustomerName'] ?? ''); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Amount (RWF)</label>
                            <input type="number" id="paymentAmount" class="form-control-custom" required step="0.01" min="0" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Method</label>
                            <select id="paymentMethod" class="form-select-custom" required>
                                <option value="Cash">Cash</option>
                                <option value="Mobile Money">Mobile Money</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Credit Card">Credit Card</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Status</label>
                            <select id="paymentStatus" class="form-select-custom" required>
                                <option value="Paid">Paid</option>
                                <option value="Pending">Pending</option>
                                <option value="Partial">Partial</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Payment Date</label>
                            <input type="date" id="paymentDate" class="form-control-custom" min="2000-01-01" required />
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- Notification Modal -->
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
                            <select id="notifUserId" class="form-select-custom">
                                <option value="">All Users</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?php echo $u['UserID']; ?>"><?php echo htmlspecialchars($u['FullName']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Type</label>
                            <select id="notifType" class="form-select-custom" required>
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
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- Notification Details Modal -->
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

    <!-- 
    PROFILE SETTINGS MODAL
    = -->
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
                            <input type="text" id="profileFullName" class="form-control-custom" value="<?php echo htmlspecialchars($me['full_name'] ?? ''); ?>" required data-allow-numeric="false" pattern="[a-zA-Z\s\-']+" title="Name should contain only letters and spaces" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Username</label>
                            <input type="text" id="profileUsername" class="form-control-custom" value="<?php echo htmlspecialchars($me['username'] ?? ''); ?>" required data-allow-numeric="true" pattern="[a-zA-Z0-9_]+" title="Username can contain letters, numbers, and underscores" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Email</label>
                            <input type="email" id="profileEmail" class="form-control-custom" value="<?php echo htmlspecialchars($me['email'] ?? ''); ?>" required />
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
        const receptionistUsername = '<?php echo htmlspecialchars($me['username'] ?? ''); ?>';
    </script>
</body>
</html>
