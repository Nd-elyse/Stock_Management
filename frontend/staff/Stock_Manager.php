<?php
// Stock_Manager.php - Stock Manager Dashboard
require_once __DIR__ . '/../../backend/includes/auth.php';
require_once __DIR__ . '/../../backend/config/db.php';
require_once __DIR__ . '/../../backend/includes/csrf.php';

require_role('Stock Manager');
$me = current_user();
$_SESSION['stock_manager_name']     = $me['full_name'];
$_SESSION['stock_manager_role']     = $me['role'];
$_SESSION['stock_manager_username'] = $me['username'];
$_SESSION['stock_manager_email']    = $me['email'];

// Set default tab to dashboard on first load
if (!isset($_SESSION['current_tab'])) {
    $_SESSION['current_tab'] = 'dashboard';
}

// Generate CSRF token for forms
$csrf_token = generate_csrf_token();

// Real spare parts / stock data (same query used by api/spareparts.php)
$spareParts = $pdo->query(
    "SELECT sp.SparePartID, sp.PartName, sp.UnitPrice, sp.Quantity,
            sp.ReorderLevel,
            c.CategoryName, s.CompanyName AS SupplierName
     FROM spareparts sp
     LEFT JOIN categories c ON c.CategoryID = sp.CategoryID
     LEFT JOIN suppliers  s ON s.SupplierID = sp.SupplierID
     ORDER BY sp.SparePartID"
)->fetchAll();
$suppliers = $pdo->query(
    "SELECT s.*, COUNT(p.PurchaseID) AS PurchaseCount
     FROM suppliers s
     LEFT JOIN purchases p ON p.SupplierID = s.SupplierID
     GROUP BY s.SupplierID
     ORDER BY s.SupplierID"
)->fetchAll();
$categories = $pdo->query('SELECT * FROM categories ORDER BY CategoryID')->fetchAll();
$stockTransactions = $pdo->query(
    "SELECT st.TransactionID, st.TransactionDate, sp.PartName, st.TransactionType, st.Quantity,
            st.BeforeQty, st.AfterQty, st.UserID,
            u.FullName AS UserName
     FROM stocktransactions st
     JOIN spareparts sp ON sp.SparePartID = st.SparePartID
     LEFT JOIN users u ON u.UserID = st.UserID
     ORDER BY st.TransactionDate DESC, st.TransactionID DESC
     LIMIT 50"
)->fetchAll();
$purchases = $pdo->query(
    "SELECT p.PurchaseID, p.PurchaseDate, p.TotalAmount, p.Status,
            s.CompanyName AS SupplierName, u.Username AS UserName
     FROM purchases p
     LEFT JOIN suppliers s ON s.SupplierID = p.SupplierID
     LEFT JOIN users u ON u.UserID = p.UserID
     ORDER BY p.PurchaseDate DESC, p.PurchaseID DESC"
)->fetchAll();
$totalParts = count($spareParts);
$lowStockCount = count(array_filter($spareParts, fn($p) => $p['Quantity'] <= $p['ReorderLevel']));
$totalCategories = count($categories);
$totalSuppliers = count($suppliers);
$totalPurchases = count($purchases);
$pendingRequestCount = (int) $pdo->query('SELECT COUNT(*) FROM sparepartrequests WHERE Status = "Pending"')->fetchColumn();

// Notifications - per-user scoped plus broadcast notifications
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>" />
    <title>Stock Manager Dashboard | GarageManager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet" />
   <link rel="stylesheet" href="../staff.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
</head>
<body class="site-staff" data-page="stock-manager">

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
                <small>Stock Panel</small>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">Main</div>
            <a href="#" onclick="switchTab('dashboard', event)" id="nav-dashboard" class="active">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
            <a href="#" onclick="switchTab('spareparts', event)" id="nav-spareparts">
                <i class="bi bi-boxes"></i> Spare Parts
            </a>
            <a href="#" onclick="switchTab('categories', event)" id="nav-categories">
                <i class="bi bi-tags-fill"></i> Categories
            </a>
            <a href="#" onclick="switchTab('suppliers', event)" id="nav-suppliers">
                <i class="bi bi-truck"></i> Suppliers
            </a>
            <a href="#" onclick="switchTab('inventory', event)" id="nav-inventory">
                <i class="bi bi-clipboard-data"></i> Inventory
            </a>
            <a href="#" onclick="switchTab('purchases', event)" id="nav-purchases">
                <i class="bi bi-cart-plus-fill"></i> Purchases
            </a>
            <a href="#" onclick="switchTab('requests', event)" id="nav-requests">
                <i class="bi bi-clipboard-data-fill"></i> Part Requests
            </a>
            <a href="#" onclick="switchTab('notifications', event)" id="nav-notifications">
                <i class="bi bi-bell-fill"></i> Notifications
            </a>
            <!-- System section removed -->
        </nav>

        <!-- Sidebar Footer: User dropdown with Settings & Logout -->
        <div class="sidebar-footer">
            <div class="dropdown">
                <div class="user-info" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar"><?php echo substr($_SESSION['stock_manager_name'] ?? 'SM', 0, 2); ?></div>
                    <div>
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['stock_manager_name'] ?? 'Stock User'); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars($_SESSION['stock_manager_role'] ?? 'Stock Manager'); ?></div>
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
                <button class="btn-action edit d-lg-none" id="sidebarToggle" onclick="toggleSidebar()">
                    <i class="bi bi-list"></i>
                </button>
                <h5 id="pageTitle">Dashboard</h5>
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
                <div class="card-custom p-4">
                    <h6 style="font-weight:700;color:var(--text-dark);">Welcome to the Stock Dashboard</h6>
                    <p class="text-muted">Monitor inventory levels, manage spare parts, categories, suppliers, purchases, and part requests.</p>
                </div>
                <div class="row g-4 mb-4 mt-1">
                    <div class="col-6 col-sm-6 col-lg-4">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-boxes"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo (int) $totalParts; ?></div>
                                <div class="label">Total Parts</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-4">
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo (int) $lowStockCount; ?></div>
                                <div class="label">Low Stock</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-4">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-clipboard-data-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo (int) $pendingRequestCount; ?></div>
                                <div class="label">Pending Req.</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-4">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-tags-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo (int) $totalCategories; ?></div>
                                <div class="label">Categories</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-4">
                        <div class="stat-card">
                            <div class="stat-icon cyan"><i class="bi bi-truck"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo (int) $totalSuppliers; ?></div>
                                <div class="label">Suppliers</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-4">
                        <div class="stat-card">
                            <div class="stat-icon purple"><i class="bi bi-cart-plus-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo (int) $totalPurchases; ?></div>
                                <div class="label">Purchases</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            SPARE PARTS TAB
            = -->
            <div id="tab-spareparts" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-boxes"></i></div><div class="stat-info"><div class="number" id="statTotalParts"><?php echo $totalParts; ?></div><div class="label">Total Parts</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-check-circle"></i></div><div class="stat-info"><div class="number" id="statInStock"><?php echo $totalParts - $lowStockCount; ?></div><div class="label">In Stock</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></div><div class="stat-info"><div class="number" id="statLowStock"><?php echo $lowStockCount; ?></div><div class="label">Low Stock</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon amber"><i class="bi bi-x-circle"></i></div><div class="stat-info"><div class="number" id="statOutOfStock"><?php echo count(array_filter($spareParts, fn($p) => $p['Quantity'] == 0)); ?></div><div class="label">Out of Stock</div></div></div></div>
                </div>
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-boxes" style="color:var(--primary-blue);"></i> Spare Parts Inventory</h6>
                        <div class="d-flex gap-2">
                            <div class="search-box"><i class="bi bi-search"></i><input type="text" class="search-input" data-live-search="partsTable" placeholder="Search..." id="partsSearch" onkeyup="filterTable('partsTable')" /></div>
                            <button class="btn-blue" onclick="showPartModal()"><i class="bi bi-plus-lg"></i> Add Spare Part</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="partsTable">
                            <thead><tr><th>ID</th><th>Part Name</th><th>Category</th><th>Stock</th><th>Unit Price</th><th>Supplier</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php $rowNumber = 1; foreach ($spareParts as $sp): ?>
                                <?php
                                    $stockStatus = $sp['Quantity'] == 0 ? 'Out' : ($sp['Quantity'] <= $sp['ReorderLevel'] ? 'Low' : 'In Stock');
                                    $badgeClass = $stockStatus === 'Out' ? 'badge-low' : ($stockStatus === 'Low' ? 'badge-low' : 'badge-ok');
                                ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo htmlspecialchars($sp['PartName']); ?></td>
                                    <td><?php echo htmlspecialchars($sp['CategoryName'] ?? 'N/A'); ?></td>
                                    <td><?php echo (int) $sp['Quantity']; ?></td>
                                    <td><?php echo number_format($sp['UnitPrice'], 0); ?> RWF</td>
                                    <td><?php echo htmlspecialchars($sp['SupplierName'] ?? 'N/A'); ?></td>
                                    <td><span class="badge-status <?php echo $badgeClass; ?>"><?php echo $stockStatus; ?></span></td>
                                    <td>
                                        <button class="btn-action view" onclick="viewPart(<?php echo (int) $sp['SparePartID']; ?>)"><i class="bi bi-eye"></i></button>

                                        <button class="btn-action delete" onclick="deletePart(<?php echo (int) $sp['SparePartID']; ?>, '<?php echo htmlspecialchars($sp['PartName'], ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 
            CATEGORIES TAB
            = -->
            <div id="tab-categories" class="tab-content" style="display:none;">
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-tags-fill" style="color:var(--primary-blue);"></i> Part Categories</h6>
                        <div class="d-flex gap-2">
                            <div class="search-box"><i class="bi bi-search"></i><input type="text" class="search-input" data-live-search="catTable" placeholder="Search..." onkeyup="filterTable(this,'catTable')" /></div>
                            <button class="btn-blue" data-bs-toggle="modal" data-bs-target="#categoryModal"><i class="bi bi-plus-lg"></i> Add Category</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="catTable">
                            <thead><tr><th>ID</th><th>Name</th><th>Description</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php $rowNumber = 1; foreach ($categories as $c): ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo htmlspecialchars($c['CategoryName']); ?></td>
                                    <td><?php echo htmlspecialchars($c['Description'] ?? ''); ?></td>
                                    <td>
                                        <button class="btn-action edit" onclick='editCategory(<?php echo json_encode($c); ?>)'><i class="bi bi-pencil"></i></button>
                                        <button class="btn-action delete" onclick="deleteCategory(<?php echo (int) $c['CategoryID']; ?>, '<?php echo htmlspecialchars($c['CategoryName'], ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 
            SUPPLIERS TAB
            = -->
            <div id="tab-suppliers" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-people-fill"></i></div><div class="stat-info"><div class="number"><?php echo (int) $totalSuppliers; ?></div><div class="label">Total Suppliers</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div><div class="stat-info"><div class="number"><?php echo (int) $totalPurchases; ?></div><div class="label">Purchases Recorded</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon amber"><i class="bi bi-tags-fill"></i></div><div class="stat-info"><div class="number"><?php echo (int) $totalCategories; ?></div><div class="label">Categories</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon red"><i class="bi bi-boxes"></i></div><div class="stat-info"><div class="number"><?php echo (int) $totalParts; ?></div><div class="label">Linked Parts</div></div></div></div>
                </div>
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-truck" style="color:var(--primary-blue);"></i> Supplier List</h6>
                        <div class="d-flex gap-2">
                            <div class="search-box"><i class="bi bi-search"></i><input type="text" class="search-input" data-live-search="supTable" placeholder="Search..." onkeyup="filterTable(this,'supTable')" /></div>
                            <button class="btn-blue" data-bs-toggle="modal" data-bs-target="#supplierModal"><i class="bi bi-plus-lg"></i> Add Supplier</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="supTable">
                            <thead><tr><th>ID</th><th>Name</th><th>Phone</th><th>Email</th><th>Address</th><th>Purchases</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php if (empty($suppliers)): ?>
                                <tr><td colspan="7" class="text-center text-muted">No suppliers found.</td></tr>
                                <?php else: ?>
                                <?php $rowNumber = 1; foreach ($suppliers as $s): ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo htmlspecialchars($s['CompanyName']); ?></td>
                                    <td><?php echo htmlspecialchars($s['Phone'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($s['Email'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($s['Address'] ?? ''); ?></td>
                                    <td><?php echo (int) ($s['PurchaseCount'] ?? 0); ?></td>
                                    <td>
                                        <button class="btn-action view" onclick="viewSupplier(<?php echo (int) $s['SupplierID']; ?>)"><i class="bi bi-eye"></i></button>
                                        <button class="btn-action edit" onclick='editSupplier(<?php echo json_encode($s, JSON_HEX_APOS); ?>)'><i class="bi bi-pencil"></i></button>
                                        <button class="btn-action delete" onclick="deleteSupplier(<?php echo (int) $s['SupplierID']; ?>, '<?php echo htmlspecialchars($s['CompanyName'], ENT_QUOTES); ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 
            INVENTORY TAB
            = -->
            <div id="tab-inventory" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-6 col-sm-6 col-lg-4"><div class="stat-card"><div class="stat-icon red"><i class="bi bi-exclamation-triangle"></i></div><div class="stat-info"><div class="number" id="statInvLowStock"><?php echo $lowStockCount; ?></div><div class="label">Low Stock</div></div></div></div>
                    <div class="col-6 col-sm-6 col-lg-4"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-boxes"></i></div><div class="stat-info"><div class="number" id="statInvTotalParts"><?php echo $totalParts; ?></div><div class="label">Total Parts</div></div></div></div>
                    <div class="col-6 col-sm-6 col-lg-4"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-clock-history"></i></div><div class="stat-info"><div class="number" id="statRecentMovements"><?php echo count($stockTransactions); ?></div><div class="label">Recent Movements</div></div></div></div>
                </div>
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-clock-history" style="color:var(--primary-blue);"></i> Stock Movement History</h6>
                        <button class="btn-blue btn-sm" onclick="loadStockMovements()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="stockMovementTable">
                            <thead><tr><th>Date</th><th>Part</th><th>Type</th><th>Moved</th><th>Before</th><th>After</th><th>User</th><th>Actions</th></tr></thead>
                            <tbody id="stockMovementTableBody">
                                <?php foreach ($stockTransactions as $st): ?>
                                <?php
                                    $quantity = (int) $st['Quantity'];
                                    $transactionType = $st['TransactionType'];
                                    $beforeQty = isset($st['BeforeQty']) ? (int) $st['BeforeQty'] : null;
                                    $afterQty = isset($st['AfterQty']) ? (int) $st['AfterQty'] : null;
                                    
                                    // Determine movement style
                                    if ($transactionType === 'Purchase' || $transactionType === 'Adjustment') {
                                        $qtyClass = 'text-success';
                                        $qtyPrefix = '+';
                                    } elseif ($transactionType === 'Usage' || $transactionType === 'Sale') {
                                        $qtyClass = 'text-danger';
                                        $qtyPrefix = '-';
                                    } else {
                                        $qtyClass = 'text-primary';
                                        $qtyPrefix = '';
                                    }
                                    
                                    // For historical data without before/after, calculate them
                                    if ($beforeQty === null) {
                                        $beforeQty = max(0, $afterQty - ($transactionType === 'Purchase' || $transactionType === 'Adjustment' ? $quantity : -$quantity));
                                    }
                                    if ($afterQty === null) {
                                        $afterQty = $beforeQty + ($transactionType === 'Purchase' || $transactionType === 'Adjustment' ? $quantity : -$quantity);
                                    }
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($st['TransactionDate']); ?></td>
                                    <td><?php echo htmlspecialchars($st['PartName']); ?></td>
                                    <td><span class="badge-status <?php echo $transactionType === 'Purchase' ? 'badge-ok' : ($transactionType === 'Usage' || $transactionType === 'Sale' ? 'badge-low' : 'badge-info'); ?>"><?php echo htmlspecialchars($transactionType); ?></span></td>
                                    <td class="<?php echo $qtyClass; ?> fw-bold"><?php echo $qtyPrefix . $quantity; ?></td>
                                    <td><?php echo $beforeQty; ?></td>
                                    <td><?php echo $afterQty; ?></td>
                                    <td><?php echo htmlspecialchars($st['UserName'] ?? 'System'); ?></td>
                                    <td><button class="btn-action delete" onclick="deleteStockMovement(<?php echo (int) $st['TransactionID']; ?>)" title="Delete"><i class="bi bi-trash"></i></button></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($stockTransactions)): ?>
                                <tr><td colspan="8" class="text-center text-muted">No stock transactions found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 
            PURCHASES TAB
            = -->
            <div id="tab-purchases" class="tab-content" style="display:none;">
                <div class="row g-4 mb-4">
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon blue"><i class="bi bi-cart-fill"></i></div><div class="stat-info"><div class="number"><?php echo count($purchases); ?></div><div class="label">Total Orders</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon green"><i class="bi bi-check-circle"></i></div><div class="stat-info"><div class="number"><?php echo count($purchases); ?></div><div class="label">Received</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon amber"><i class="bi bi-hourglass-split"></i></div><div class="stat-info"><div class="number">0</div><div class="label">Pending</div></div></div></div>
                    <div class="col-sm-6 col-lg-3"><div class="stat-card"><div class="stat-icon red"><i class="bi bi-x-circle"></i></div><div class="stat-info"><div class="number">0</div><div class="label">Cancelled</div></div></div></div>
                </div>
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-cart-plus-fill" style="color:var(--primary-blue);"></i> Purchase Orders</h6>
                        <div class="d-flex gap-2">
                            <div class="search-box"><i class="bi bi-search"></i><input type="text" class="search-input" data-live-search="poTable" placeholder="Search..." onkeyup="filterTable(this,'poTable')" /></div>
                            <button class="btn-blue" data-bs-toggle="modal" data-bs-target="#purchaseModal"><i class="bi bi-plus-lg"></i> Add Purchase</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="poTable">
                            <thead><tr><th>ID</th><th>Date</th><th>Supplier</th><th>Total (RWF)</th><th>Created By</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php $rowNumber = 1; foreach ($purchases as $p): ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo htmlspecialchars($p['PurchaseDate']); ?></td>
                                    <td><?php echo htmlspecialchars($p['SupplierName']); ?></td>
                                    <td><?php echo number_format((float) $p['TotalAmount']); ?></td>
                                    <td><?php echo htmlspecialchars($p['UserName']); ?></td>
                                    <td>
                                        <button class="btn-action view" onclick="viewPurchase(<?php echo (int) $p['PurchaseID']; ?>)"><i class="bi bi-eye"></i></button>
                                        <button class="btn-action print" onclick="printPurchase(<?php echo (int) $p['PurchaseID']; ?>)"><i class="bi bi-printer"></i></button>
                                        <button class="btn-action delete" onclick="deletePurchase(<?php echo (int) $p['PurchaseID']; ?>, '<?php echo (int) $p['PurchaseID']; ?>')"><i class="bi bi-trash"></i></button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($purchases)): ?>
                                <tr><td colspan="6" class="text-center text-muted">No purchases found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 
            PART REQUESTS TAB
            = -->
            <div id="tab-requests" class="tab-content" style="display:none;">
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-boxes" style="color:var(--primary-blue);"></i> Mechanic Part Requests</h6>
                        <button class="btn-blue btn-sm" onclick="loadPartRequests()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom table-fixed-layout" id="partRequestsTable">
                            <colgroup>
                                <col style="width:9%;">
                                <col style="width:12%;">
                                <col style="width:8%;">
                                <col style="width:15%;">
                                <col style="width:6%;">
                                <col style="width:8%;">
                                <col style="width:14%;">
                                <col style="width:9%;">
                                <col style="width:9%;">
                                <col style="width:10%;">
                            </colgroup>
                            <thead><tr><th>Request ID</th><th>Mechanic</th><th>Job</th><th>Part</th><th>Qty</th><th>Stock</th><th>Reason</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                            <tbody id="partRequestsTableBody">
                                <tr><td colspan="10" class="text-center text-muted">Loading requests...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 
            NOTIFICATIONS TAB (updated to match image)
            = -->
            <div id="tab-notifications" class="tab-content" style="display:none;">
                <div class="card-custom p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h6 style="font-weight:700;color:var(--text-dark);"><i class="bi bi-bell-fill" style="color:var(--primary-blue);"></i> All Notifications</h6>
                        <button class="btn-outline-blue btn-sm" onclick="markAllRead()"><i class="bi bi-check-all"></i> Mark All Read</button>
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
                            $type = $n['Type'] ?? 'info';
                            $icon = 'info-circle-fill';
                            $iconColor = '#2563eb';
                            if ($type === 'success') { $icon = 'check-circle-fill'; $iconColor = '#16a34a'; }
                            if ($type === 'warning') { $icon = 'exclamation-triangle-fill'; $iconColor = '#ca8a04'; }
                            if ($type === 'danger') { $icon = 'x-circle-fill'; $iconColor = '#dc2626'; }
                        ?>
                        <div class="list-group-item d-flex gap-3 align-items-center py-3 border-bottom <?php echo $isRead ? 'opacity-75' : ''; ?>"
                             data-id="<?php echo $notifId; ?>" style="cursor:pointer;"
                             onclick='viewNotificationDetails(<?php echo json_encode($n, JSON_HEX_APOS); ?>)'>
                            <i class="bi bi-<?php echo $icon; ?>" style="color:<?php echo $iconColor; ?>;font-size:1.3rem;"></i>
                            <div class="flex-grow-1">
                                <div style="font-weight:600;font-size:0.95rem;"><?php echo htmlspecialchars($n['Message'] ?? 'Notification'); ?></div>
                                <div style="font-size:0.85rem;color:var(--text-muted);"><?php echo date('M d, Y g:i A', strtotime($n['CreatedAt'])); ?></div>
                            </div>
                            <?php if (!$isRead): ?><span class="badge bg-primary rounded-pill">New</span><?php endif; ?>
                            <?php if (!$isBroadcast): ?>
                            <button class="btn-action delete" onclick="event.stopPropagation(); deleteNotification(<?php echo $notifId; ?>)"><i class="bi bi-trash"></i></button>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- 
            SETTINGS TAB (profile & password)
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

    <!-- Add/Edit Spare Part Modal -->
    <div class="modal fade modal-custom" id="partModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="partModalTitle"><i class="bi bi-boxes" style="color:var(--primary-blue);"></i> Add Spare Part</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="partForm" onsubmit="savePart(event)">
                    <input type="hidden" id="editingPartId" value="" />
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Part Name</label>
                            <input type="text" id="partName" class="form-control form-control-custom" required data-allow-numeric="true" pattern="[a-zA-Z0-9\s\-']+" title="Part name can contain letters, numbers, and basic punctuation" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Category</label>
                            <select id="partCategory" class="form-select form-control-custom" required>
                                <option value="">Select category...</option>
                                <?php foreach ($categories as $c): ?>
                                <option value="<?php echo (int) $c['CategoryID']; ?>"><?php echo htmlspecialchars($c['CategoryName']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Supplier</label>
                            <select id="partSupplier" class="form-select form-control-custom">
                                <option value="">Select supplier...</option>
                                <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo (int) $s['SupplierID']; ?>"><?php echo htmlspecialchars($s['CompanyName']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Unit Price (RWF)</label>
                            <input type="number" id="partPrice" class="form-control form-control-custom" required min="0" step="0.01" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Initial Stock</label>
                            <input type="number" id="partQuantity" class="form-control form-control-custom" required min="0" data-allow-numeric="true" />
                        </div>
                        <div class="col-md-4">
                            <label class="form-label-custom">Reorder Level</label>
                            <input type="number" id="partReorderLevel" class="form-control form-control-custom" required min="0" value="10" data-allow-numeric="true" />
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save Spare Part</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- Add Category Modal -->
    <div class="modal fade modal-custom" id="categoryModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="categoryModalTitle"><i class="bi bi-tags" style="color:var(--primary-blue);"></i> Add Category</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="categoryForm" onsubmit="saveCategory(event)">
                    <input type="hidden" id="editingCategoryId" value="" />
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Category Name</label>
                            <input type="text" id="catName" class="form-control form-control-custom" required data-allow-numeric="false" pattern="[a-zA-Z\s\-']+" title="Category name should contain only letters and spaces" />
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Description</label>
                            <input type="text" id="catDescription" class="form-control form-control-custom" data-allow-numeric="true" pattern="[a-zA-Z0-9\s\-']+" title="Description can contain letters, numbers, and basic punctuation" />
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save">Save Category</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- Add Supplier Modal -->
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
                        <div class="col-md-6"><label class="form-label-custom">Company Name</label><input type="text" id="supCompanyName" class="form-control form-control-custom" required data-allow-numeric="false" pattern="[a-zA-Z0-9\s\-']+" title="Company name can contain letters, numbers, and basic punctuation" /></div>
                        <div class="col-md-6"><label class="form-label-custom">Phone</label><input type="tel" id="supPhone" class="form-control form-control-custom" pattern="^(079|078|072|073)\d{7}$" maxlength="10" title="Phone must be exactly 10 digits starting with 079, 078, 072, or 073" data-allow-numeric="true" /></div>
                        <div class="col-md-6"><label class="form-label-custom">Email</label><input type="email" id="supEmail" class="form-control form-control-custom" /></div>
                        <div class="col-md-6"><label class="form-label-custom">Address</label><input type="text" id="supAddress" class="form-control form-control-custom" data-allow-numeric="true" pattern="[a-zA-Z0-9\s\-'\.,#]+" title="Address can contain letters, numbers, and basic punctuation" /></div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save">Save Supplier</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- Stock In Modal -->
    <div class="modal fade modal-custom" id="stockInModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Record Stock In</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="stockInForm" onsubmit="submitStockAdjust(event, 'stockInForm', 'stockInModal', 'Purchase');">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label-custom">Part</label><select id="stockInPart" class="form-select form-control-custom" required></select></div>
                        <div class="col-md-6"><label class="form-label-custom">Qty</label><input type="number" id="stockInQty" class="form-control form-control-custom" required min="1" data-allow-numeric="true" /></div>
                        <div class="col-md-6"><label class="form-label-custom">PO Reference</label><input type="text" id="stockInRef" class="form-control form-control-custom" data-allow-numeric="true" pattern="[a-zA-Z0-9\-]+" title="PO reference can contain letters, numbers, and hyphens" /></div>
                        <div class="col-md-6"><label class="form-label-custom">Date</label><input type="date" class="form-control form-control-custom" value="<?php echo date('Y-m-d'); ?>" min="2000-01-01" readonly title="Stock movements are always logged on today's date." /></div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save">Record</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- Stock Out Modal -->
    <div class="modal fade modal-custom" id="stockOutModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Record Stock Out</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="stockOutForm" onsubmit="submitStockAdjust(event, 'stockOutForm', 'stockOutModal', 'Usage');">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label-custom">Part</label><select id="stockOutPart" class="form-select form-control-custom" required></select></div>
                        <div class="col-md-6"><label class="form-label-custom">Qty</label><input type="number" id="stockOutQty" class="form-control form-control-custom" required min="1" data-allow-numeric="true" /></div>
                        <div class="col-md-6"><label class="form-label-custom">Reference (Job)</label><input type="text" id="stockOutRef" class="form-control form-control-custom" data-allow-numeric="true" pattern="[a-zA-Z0-9\-]+" title="Reference can contain letters, numbers, and hyphens" /></div>
                        <div class="col-md-6"><label class="form-label-custom">Date</label><input type="date" class="form-control form-control-custom" value="<?php echo date('Y-m-d'); ?>" min="2000-01-01" readonly title="Stock movements are always logged on today's date." /></div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save">Record</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>

    <!-- Adjustment Modal -->
    <div class="modal fade modal-custom" id="adjustModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Manual Adjustment</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <form id="adjustForm" onsubmit="submitStockAdjustment(event);">
                    <div class="row g-3">
                        <div class="col-md-6"><label class="form-label-custom">Part</label><select id="adjustPart" class="form-select form-control-custom" required></select></div>
                        <div class="col-md-6"><label class="form-label-custom">Qty</label><input type="number" id="adjustQty" class="form-control form-control-custom" required min="1" data-allow-numeric="true" /></div>
                        <div class="col-md-6"><label class="form-label-custom">Adjustment Type</label><select id="adjustType" class="form-select form-control-custom"><option value="add">Add (+)</option><option value="subtract">Subtract (-)</option></select></div>
                        <div class="col-md-6"><label class="form-label-custom">Date</label><input type="date" class="form-control form-control-custom" value="<?php echo date('Y-m-d'); ?>" min="2000-01-01" readonly title="Stock movements are always logged on today's date." /></div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save">Adjust</button>
                    </div>
                </form>
            </div>
        </div></div>
    </div>


    <!-- Add Purchase Order Modal -->
    <div class="modal fade modal-custom" id="purchaseModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-cart-plus" style="color:var(--primary-blue);"></i> Create Purchase Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="purchaseForm" onsubmit="savePurchase(event)">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Supplier</label>
                            <select id="purchaseSupplier" class="form-select form-control-custom" required>
                                <option value="">Select supplier...</option>
                                <?php foreach ($suppliers as $s): ?>
                                <option value="<?php echo $s['SupplierID']; ?>"><?php echo htmlspecialchars($s['CompanyName']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label-custom">Total Amount (RWF)</label>
                            <input type="number" id="purchaseTotal" class="form-control form-control-custom" required min="0" />
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save">Create Purchase</button>
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
        const stockManagerUsername = '<?php echo htmlspecialchars($me['username'] ?? ''); ?>';

        const purchases = <?php echo json_encode($purchases, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const suppliers = <?php echo json_encode($suppliers, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        // Profile update function
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
            if ((current || newPass || confirm) || username !== stockManagerUsername) {
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

        window.updateProfile = updateProfile;

        function viewSupplier(supplierId) {
            const supplier = suppliers.find(s => s.SupplierID === supplierId);
            if (!supplier) return;

            const html = `
                <div class="vd-item"><strong>Supplier ID:</strong> ${supplier.SupplierID}</div>
                <div class="vd-item"><strong>Company Name:</strong> ${supplier.CompanyName}</div>
                <div class="vd-item"><strong>Phone:</strong> ${supplier.Phone || 'N/A'}</div>
                <div class="vd-item"><strong>Email:</strong> ${supplier.Email || 'N/A'}</div>
                <div class="vd-item vd-full"><strong>Address:</strong> ${supplier.Address || 'N/A'}</div>
                <div class="vd-item vd-full"><strong>Purchases Recorded:</strong> ${supplier.PurchaseCount || 0}</div>
            `;
            document.getElementById('detailsModalTitle').textContent = 'Supplier Details';
            document.getElementById('detailsModalBody').innerHTML = html;
            const supplierPrintBtn = document.getElementById('detailsModalPrintBtn');
            if (supplierPrintBtn) {
                supplierPrintBtn.style.display = '';
                // The shared modal's Print button previously called printModalContent()
                // with no container id, so it silently did nothing. Point it at a
                // dedicated, properly formatted supplier report instead.
                supplierPrintBtn.onclick = function () { printSupplierReport(supplier); };
            }
            new bootstrap.Modal(document.getElementById('detailsModal')).show();
        }

        function printSupplierReport(supplier) {
            const printWindow = window.open('', '_blank');
            if (!printWindow) {
                if (typeof showToast === 'function') {
                    showToast('Please allow pop-ups to print this report.', 'danger');
                }
                return;
            }
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Supplier Report - ${supplier.CompanyName}</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                        .header h1 { margin: 0; color: #333; }
                        .header p { margin: 5px 0; color: #666; }
                        .details { margin: 20px 0; }
                        .details table { width: 100%; border-collapse: collapse; }
                        .details th, .details td { padding: 10px; border: 1px solid #ddd; text-align: left; }
                        .details th { background: #f5f5f5; width: 220px; }
                        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
                        @media print { body { -webkit-print-color-adjust: exact; } }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>SUPPLIER REPORT</h1>
                        <p>Garage Management System</p>
                    </div>
                    <div class="details">
                        <table>
                            <tr><th>Supplier ID</th><td>${supplier.SupplierID}</td></tr>
                            <tr><th>Company Name</th><td>${supplier.CompanyName}</td></tr>
                            <tr><th>Phone</th><td>${supplier.Phone || 'N/A'}</td></tr>
                            <tr><th>Email</th><td>${supplier.Email || 'N/A'}</td></tr>
                            <tr><th>Address</th><td>${supplier.Address || 'N/A'}</td></tr>
                            <tr><th>Purchases Recorded</th><td>${supplier.PurchaseCount || 0}</td></tr>
                        </table>
                    </div>
                    <div class="footer">
                        <p>Generated on ${new Date().toLocaleString()}</p>
                        <p>This is an official document from Garage Management System</p>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.focus();
            // Give the new document a moment to finish rendering before printing.
            setTimeout(function () { printWindow.print(); }, 250);
        }

        function viewPurchase(purchaseId) {
            const purchase = purchases.find(p => p.PurchaseID === purchaseId);
            if (!purchase) return;

            const html = `
                <div class="vd-item"><strong>Purchase ID:</strong> ${purchase.PurchaseID}</div>
                <div class="vd-item"><strong>Date:</strong> ${purchase.PurchaseDate}</div>
                <div class="vd-item"><strong>Supplier:</strong> ${purchase.SupplierName}</div>
                <div class="vd-item"><strong>Total Amount:</strong> ${number_format(purchase.TotalAmount)} RWF</div>
                <div class="vd-item vd-full"><strong>Created By:</strong> ${purchase.UserName}</div>
            `;
            document.getElementById('detailsModalTitle').textContent = 'Purchase Order Details';
            document.getElementById('detailsModalBody').innerHTML = html;
            // Purchase Orders view should only show the Close button - no Print
            // or other actions - so hide the shared modal's Print button here.
            const purchasePrintBtn = document.getElementById('detailsModalPrintBtn');
            if (purchasePrintBtn) purchasePrintBtn.style.display = 'none';
            new bootstrap.Modal(document.getElementById('detailsModal')).show();
        }

        function printPurchase(purchaseId) {
            const purchase = purchases.find(p => p.PurchaseID === purchaseId);
            if (!purchase) return;

            const printWindow = window.open('', '_blank');
            printWindow.document.write(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Purchase Order ${purchase.PurchaseID}</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 20px; }
                        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 20px; }
                        .header h1 { margin: 0; color: #333; }
                        .header p { margin: 5px 0; color: #666; }
                        .details { margin: 20px 0; }
                        .details table { width: 100%; border-collapse: collapse; }
                        .details th, .details td { padding: 10px; border: 1px solid #ddd; text-align: left; }
                        .details th { background: #f5f5f5; }
                        .footer { margin-top: 30px; text-align: center; color: #666; font-size: 12px; }
                        @media print { body { -webkit-print-color-adjust: exact; } }
                    </style>
                </head>
                <body>
                    <div class="header">
                        <h1>PURCHASE ORDER</h1>
                        <p>Garage Management System</p>
                    </div>
                    <div class="details">
                        <table>
                            <tr><th>Purchase ID</th><td>${purchase.PurchaseID}</td></tr>
                            <tr><th>Date</th><td>${purchase.PurchaseDate}</td></tr>
                            <tr><th>Supplier</th><td>${purchase.SupplierName}</td></tr>
                            <tr><th>Total Amount</th><td>${number_format(purchase.TotalAmount)} RWF</td></tr>
                            <tr><th>Created By</th><td>${purchase.UserName}</td></tr>
                        </table>
                    </div>
                    <div class="footer">
                        <p>Generated on ${new Date().toLocaleString()}</p>
                        <p>This is an official document from Garage Management System</p>
                    </div>
                </body>
                </html>
            `);
            printWindow.document.close();
            printWindow.print();
        }

        function number_format(num) {
            return new Intl.NumberFormat('en-US').format(num);
        }

        // Make functions globally accessible
        window.viewSupplier = viewSupplier;
        window.printSupplierReport = printSupplierReport;
        window.viewPurchase = viewPurchase;
        window.printPurchase = printPurchase;
        window.number_format = number_format;
    </script>
</body>
</html>
