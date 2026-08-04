<?php
// Mechanic.php - Mechanic Dashboard
require_once __DIR__ . '/../../backend/includes/auth.php';
require_once __DIR__ . '/../../backend/config/db.php';
require_once __DIR__ . '/../../backend/includes/csrf.php';

require_role('Mechanic');
$me = current_user();
$_SESSION['mechanic_name']     = $me['full_name'];
$_SESSION['mechanic_role']     = $me['role'];
$_SESSION['mechanic_username'] = $me['username'];
$_SESSION['mechanic_email']    = $me['email'];

// Set default tab to dashboard on first load
if (!isset($_SESSION['current_tab'])) {
    $_SESSION['current_tab'] = 'dashboard';
}

// Generate CSRF token for forms
$csrf_token = generate_csrf_token();

// Jobs assigned to THIS logged-in mechanic (real data, filtered by their MechanicID)
$myJobs = [];
if (!empty($me['mechanic_id'])) {
    $stmt = $pdo->prepare(
        "SELECT rj.JobID, rj.Status, v.PlateNumber, c.FullName AS CustomerName
         FROM repairjobs rj
         LEFT JOIN vehicles v ON v.VehicleID = rj.VehicleID
         LEFT JOIN customers c ON c.CustomerID = v.CustomerID
         WHERE rj.MechanicID = ?
         ORDER BY rj.StartDate DESC"
    );
    $stmt->execute([$me['mechanic_id']]);
    $myJobs = $stmt->fetchAll();
}

// Maps a job Status to the matching badge-status modifier class, kept in
// one place so the initial server render and the JS that updates a badge
// in place after a status change (see autoUpdateStatus()) never disagree.
function job_status_badge_class(?string $status): string {
    switch ($status) {
        case 'Delivered':
        case 'Ready':
            return 'badge-delivered';
        case 'Pending':
            return 'badge-pending';
        case 'In Progress':
        case 'Diagnosed':
            return 'badge-inprogress';
        case 'Awaiting Parts':
            return 'badge-awaiting';
        case 'Cancelled':
            return 'badge-danger';
        default:
            return 'badge-ok';
    }
}

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

// Dashboard stats - derived only from this mechanic's own jobs
$activeStatuses = ['Pending', 'Diagnosed', 'In Progress'];
$activeJobsCount = count(array_filter($myJobs, fn($j) => in_array($j['Status'], $activeStatuses, true)));
$awaitingPartsCount = count(array_filter($myJobs, fn($j) => $j['Status'] === 'Awaiting Parts'));
$completedJobsCount = 0;
$partsTodayCount = 0;
$jobHistory = [];
if (!empty($me['mechanic_id'])) {
    // Combine stats queries into a single query
    $statsStmt = $pdo->prepare(
        "SELECT 
            (SELECT COUNT(*) FROM repairjobs WHERE MechanicID = ? AND Status IN ('Delivered','Ready','Completed')) AS completed_count,
            (SELECT COUNT(*) FROM sparepartrequests WHERE MechanicID = ? AND DATE(RequestedAt) = CURDATE()) AS parts_today"
    );
    $statsStmt->execute([$me['mechanic_id'], $me['mechanic_id']]);
    $stats = $statsStmt->fetch();
    $completedJobsCount = (int) $stats['completed_count'];
    $partsTodayCount = (int) $stats['parts_today'];

    $historyStmt = $pdo->prepare(
        "SELECT rj.JobID, rj.EndDate, rj.Status, v.PlateNumber,
                MAX(d.DiagnosticID) AS DiagnosticID,
                GROUP_CONCAT(DISTINCT d.Notes SEPARATOR ' | ') AS Notes
         FROM repairjobs rj
         LEFT JOIN vehicles v ON v.VehicleID = rj.VehicleID
         LEFT JOIN diagnostics d ON d.JobID = rj.JobID
         WHERE rj.MechanicID = ? AND rj.Status IN ('Delivered','Ready','Completed','Cancelled')
         GROUP BY rj.JobID
         ORDER BY rj.EndDate DESC, rj.JobID DESC"
    );
    $historyStmt->execute([$me['mechanic_id']]);
    $jobHistory = $historyStmt->fetchAll();
} else {
    $partsTodayCount = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES); ?>" />
    <title>Mechanic Dashboard | GarageManager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" />
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../staff.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" defer></script>
</head>
<body class="site-staff" data-page="mechanic">

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
                <small>Mechanic Panel</small>
            </div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">Main</div>
            <a href="" onclick="switchTab('dashboard', event)" id="nav-dashboard" class="active">
                <i class="bi bi-grid-1x2-fill"></i> Dashboard
            </a>
            <a href="" onclick="switchTab('assigned', event)" id="nav-assigned">
                <i class="bi bi-clipboard-check-fill"></i> My Jobs
            </a>
            <a href="" onclick="switchTab('parts', event)" id="nav-parts">
                <i class="bi bi-boxes"></i> Request Parts
            </a>
            <a href="" onclick="switchTab('history', event)" id="nav-history">
                <i class="bi bi-clock-history"></i> Job History
            </a>
            <a href="" onclick="switchTab('notifications', event)" id="nav-notifications">
                <i class="bi bi-bell-fill"></i> Notifications
            </a>
            <!-- System section and Logout link removed -->
        </nav>

        <!-- Sidebar Footer: User dropdown with Settings & Logout -->
        <div class="sidebar-footer">
            <div class="dropdown">
                <div class="user-info" data-bs-toggle="dropdown" aria-expanded="false">
                    <div class="user-avatar"><?php echo substr($_SESSION['mechanic_name'] ?? 'EM', 0, 2); ?></div>
                    <div>
                        <div class="user-name"><?php echo htmlspecialchars($_SESSION['mechanic_name'] ?? 'Eric Mwangi'); ?></div>
                        <div class="user-role"><?php echo htmlspecialchars($_SESSION['mechanic_role'] ?? 'Mechanic'); ?></div>
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
                    <span class="badge bg-danger rounded-pill" style="position:absolute;top:-4px;right:-6px;font-size:0.6rem;"><?php echo (int) $unreadCount; ?></span>
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
                    <h6 style="font-weight:700;color:var(--text-dark);">Welcome, Mechanic</h6>
                    <p class="text-muted">View your assigned jobs, update repair progress, request spare parts, and complete repairs.</p>
                </div>

                <div class="row g-4 mb-4 mt-1">
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon blue"><i class="bi bi-clipboard-check-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo (int) $activeJobsCount; ?></div>
                                <div class="label">Active Jobs</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon amber"><i class="bi bi-hourglass-split"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo (int) $awaitingPartsCount; ?></div>
                                <div class="label">Await. Parts</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo (int) $completedJobsCount; ?></div>
                                <div class="label">Completed</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 col-sm-6 col-lg-3">
                        <div class="stat-card">
                            <div class="stat-icon red"><i class="bi bi-tools"></i></div>
                            <div class="stat-info">
                                <div class="number"><?php echo (int) $partsTodayCount; ?></div>
                                <div class="label">Parts Today</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick assigned jobs table on dashboard -->
                <div class="table-card mt-4">
                    <div class="table-header">
                        <h6><i class="bi bi-clipboard-check-fill" style="color:var(--primary-blue);"></i> My Active Jobs</h6>
                        <a href="" onclick="switchTab('assigned', event)" class="btn-outline-blue btn-sm">View All</a>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom">
                            <thead>
                                <tr><th>Job ID</th><th>Vehicle</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($myJobs)): ?>
                                <tr><td colspan="4" class="text-center text-muted">No jobs assigned yet.</td></tr>
                                <?php else: ?>
                                <?php $rowNumber = 1; foreach (array_slice($myJobs, 0, 5) as $j): ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo htmlspecialchars($j['PlateNumber'] ?? '-'); ?></td>
                                    <td><span class="badge-status badge-pending"><?php echo htmlspecialchars($j['Status'] ?? 'Pending'); ?></span></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 
            MY JOBS TAB (assigned)
            = -->
            <div id="tab-assigned" class="tab-content" style="display:none;">
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-clipboard-check-fill" style="color:var(--primary-blue);"></i> My Jobs</h6>
                        <div class="search-box"><i class="bi bi-search"></i><input type="text" class="search-input" data-live-search="assignedTable" placeholder="Search..." onkeyup="filterTable(this,'assignedTable')" /></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="assignedTable">
                            <thead>
                                <tr>
                                    <th>Job ID</th>
                                    <th>Vehicle</th>
                                    <th>Customer</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($myJobs)): ?>
                                <tr><td colspan="6" class="text-center text-muted">No jobs assigned yet.</td></tr>
                                <?php else: ?>
                                <?php $rowNumber = 1; foreach ($myJobs as $j): ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo htmlspecialchars($j['PlateNumber'] ?? '-'); ?></td>
                                    <td class="assigned-customer-cell"><?php echo htmlspecialchars($j['CustomerName'] ?? '-'); ?></td>
                                    <td>
                                        <span class="badge-status <?php echo job_status_badge_class($j['Status']); ?>"><?php echo htmlspecialchars($j['Status'] ?? 'Pending'); ?></span>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn-action view" onclick="openDiagnosticsModal(<?php echo (int) $j['JobID']; ?>, '<?php echo htmlspecialchars($j['PlateNumber'] ?? ''); ?>')" title="Record notes">
                                            <i class="bi bi-pencil-square"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <select class="status-select" data-job-id="<?php echo (int) $j['JobID']; ?>" data-previous-status="<?php echo htmlspecialchars($j['Status'] ?? 'Pending', ENT_QUOTES); ?>" onchange="autoUpdateStatus(this)">
                                            <option value="Pending" <?php echo $j['Status'] === 'Pending' ? 'selected' : ''; ?>>Pending</option>
                                            <option value="Diagnosed" <?php echo $j['Status'] === 'Diagnosed' ? 'selected' : ''; ?>>Diagnosed</option>
                                            <option value="In Progress" <?php echo $j['Status'] === 'In Progress' ? 'selected' : ''; ?>>In Progress</option>
                                            <option value="Awaiting Parts" <?php echo $j['Status'] === 'Awaiting Parts' ? 'selected' : ''; ?>>Awaiting Parts</option>
                                            <option value="Ready" <?php echo $j['Status'] === 'Ready' ? 'selected' : ''; ?>>Ready</option>
                                            <option value="Delivered" <?php echo $j['Status'] === 'Delivered' ? 'selected' : ''; ?>>Delivered</option>
                                            <option value="Cancelled" <?php echo $j['Status'] === 'Cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                                        </select>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <p id="assignedTableFooter">Showing 1-<?php echo min(count($myJobs), 10); ?> of <?php echo count($myJobs); ?> entries</p>
                        <div class="pagination-wrapper">
                            <button class="btn-outline-blue" onclick="changePage('assigned', -1)" id="assignedPrev" disabled>Previous</button>
                            <button class="btn-blue" onclick="changePage('assigned', 1)" id="assignedNext">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            REQUEST PARTS TAB
            = -->
            <div id="tab-parts" class="tab-content" style="display:none;">
                <div class="card-custom p-4" style="max-width:700px; margin:0 auto;">
                    <h6 style="font-weight:700;color:var(--text-dark);"><i class="bi bi-boxes" style="color:var(--primary-blue);"></i> Request Spare Parts</h6>
                    <form id="partRequestForm">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="row g-3 mt-2">
                            <div class="col-md-6">
                                <label class="form-label-custom">Job <span class="text-muted">(optional)</span></label>
                                <select class="form-select form-control-custom" id="requestJobId">
                                    <option value="">Select job (optional)...</option>
                                    <?php 
                                    if (!empty($me['mechanic_id'])) {
                                        $mechJobs = $pdo->prepare("SELECT rj.JobID, v.PlateNumber, c.FullName AS CustomerName FROM repairjobs rj LEFT JOIN vehicles v ON v.VehicleID = rj.VehicleID LEFT JOIN customers c ON c.CustomerID = v.CustomerID WHERE rj.MechanicID = ? AND rj.Status IN ('Pending','Diagnosed','In Progress','Awaiting Parts') ORDER BY rj.JobID DESC");
                                        $mechJobs->execute([$me['mechanic_id']]);
                                        $mechJobs = $mechJobs->fetchAll();
                                        foreach ($mechJobs as $j): ?>
                                    <option value="<?php echo $j['JobID']; ?>" data-plate="<?php echo htmlspecialchars($j['PlateNumber'] ?? ''); ?>" data-customer="<?php echo htmlspecialchars($j['CustomerName'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($j['PlateNumber'] ?? 'Unknown'); ?> - <?php echo htmlspecialchars($j['CustomerName'] ?? 'Unknown'); ?>
                                    </option>
                                    <?php endforeach; 
                                    } else { ?>
                                    <option value="" disabled>No jobs available - contact admin</option>
                                    <?php } ?>
                                </select>
                                <small class="text-muted" id="selectedJobInfo"></small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label-custom">Part Name</label>
                                <select class="form-select form-control-custom" id="requestSparePartId" required>
                                    <option value="">Select part...</option>
                                    <?php 
                                    $spareParts = $pdo->query("SELECT SparePartID, PartName, Quantity FROM spareparts ORDER BY PartName")->fetchAll();
                                    foreach ($spareParts as $sp): ?>
                                    <option value="<?php echo $sp['SparePartID']; ?>" data-stock="<?php echo $sp['Quantity']; ?>"><?php echo htmlspecialchars($sp['PartName']); ?> (Stock: <?php echo $sp['Quantity']; ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label-custom">Quantity</label>
                                <input type="number" class="form-control form-control-custom" id="requestQuantity" min="1" value="1" required />
                            </div>
                            <div class="col-md-8">
                                <label class="form-label-custom">Reason</label>
                                <input type="text" class="form-control form-control-custom" id="requestReason" placeholder="Why do you need this part?" />
                            </div>
                        </div>
                        <button type="submit" class="btn-blue mt-4 btn-save"><i class="bi bi-send"></i> Submit Request</button>
                    </form>
                </div>
                
                <!-- Request History -->
                <div class="table-card mt-4">
                    <div class="table-header">
                        <h6><i class="bi bi-list-check" style="color:var(--primary-blue);"></i> My Requests</h6>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="requestsTable">
                            <thead><tr><th>Request ID</th><th>Part</th><th>Quantity</th><th>Reason</th><th>Status</th><th>Date</th><th>Actions</th></tr></thead>
                            <tbody id="requestsTableBody">
                                <tr><td colspan="7" class="text-center text-muted">Loading requests...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 
            JOB HISTORY TAB
            = -->
            <div id="tab-history" class="tab-content" style="display:none;">
                <div class="table-card">
                    <div class="table-header">
                        <h6><i class="bi bi-clock-history" style="color:var(--primary-blue);"></i> Job History</h6>
                        <div class="search-box"><i class="bi bi-search"></i><input type="text" class="search-input" data-live-search="historyTable" placeholder="Search..." onkeyup="filterTable(this,'historyTable')" /></div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-custom" id="historyTable">
                            <thead>
                                <tr>
                                    <th>Job ID</th>
                                    <th>Date</th>
                                    <th>Vehicle</th>
                                    <th>Status</th>
                                    <th>Notes</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($jobHistory)): ?>
                                <tr><td colspan="6" class="text-center text-muted">No completed jobs yet.</td></tr>
                                <?php else: ?>
                                <?php $rowNumber = 1; foreach ($jobHistory as $h): ?>
                                <tr>
                                    <td class="row-number"><?php echo $rowNumber++; ?></td>
                                    <td><?php echo $h['EndDate'] ? htmlspecialchars($h['EndDate']) : '-'; ?></td>
                                    <td><?php echo htmlspecialchars($h['PlateNumber'] ?? '-'); ?></td>
                                    <td><span class="badge-status badge-delivered"><?php echo htmlspecialchars($h['Status'] ?? ''); ?></span></td>
                                    <td><?php echo htmlspecialchars($h['Notes'] ?? '-'); ?></td>
                                    <td>
                                        <button class="btn-action delete" onclick="deleteJobFromHistory(<?php echo (int) $h['JobID']; ?>, '<?php echo 'Job ' . $h['JobID']; ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="table-footer">
                        <p id="historyTableFooter">Showing 1-<?php echo min(count($jobHistory), 10); ?> of <?php echo count($jobHistory); ?> entries</p>
                        <div class="pagination-wrapper">
                            <button class="btn-outline-blue" onclick="changePage('history', -1)" id="historyPrev" disabled>Previous</button>
                            <button class="btn-blue" onclick="changePage('history', 1)" id="historyNext">Next</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- 
            NOTIFICATIONS TAB (same as Admin/Receptionist style)
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
                            $iconColor = '2563eb';
                            if ($type === 'success') { $icon = 'check-circle-fill'; $iconColor = '16a34a'; }
                            if ($type === 'warning') { $icon = 'exclamation-triangle-fill'; $iconColor = 'ca8a04'; }
                            if ($type === 'danger') { $icon = 'x-circle-fill'; $iconColor = 'dc2626'; }
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
            SETTINGS TAB (for mechanic profile)
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

    <!-- 
    MODAL FOR RECORD DIAGNOSTICS
    = -->
    <div class="modal fade modal-custom" id="diagnosticsModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clipboard2-plus" style="color:var(--primary-blue);"></i> Record Notes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="diagnosticsForm" onsubmit="saveDiagnostics(event)">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label-custom">Job </label>
                            <input type="text" id="diagJobId" class="form-control form-control-custom" readonly />
                        </div>
                        <div class="col-12">
                            <label class="form-label-custom">Notes</label>
                            <textarea id="diagNotes" class="form-control form-control-custom" rows="6" placeholder="Write your notes here..." required></textarea>
                        </div>
                    </div>
                    <div class="mt-4 d-flex justify-content-end gap-2">
                        <button type="submit" class="btn-blue btn-sm btn-save"><i class="bi bi-check-lg"></i> Save Notes</button>
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
        // PHP-to-JS data bridge (kept inline since these values are
        // rendered server-side; all other logic lives in main.js)
        const assignedJobs = <?php echo json_encode($myJobs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const mechanicToday = '<?php echo date('Y-m-d'); ?>';
        const mechanicUsername = '<?php echo htmlspecialchars($me['username'] ?? ''); ?>';

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
            if ((current || newPass || confirm) || username !== mechanicUsername) {
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

        window.submitPartRequest = submitPartRequest;
        window.loadMechanicRequests = loadMechanicRequests;
        window.cancelPartRequest = cancelPartRequest;
        window.updateProfile = updateProfile;

        // Enhanced job selection functionality
        const jobSelect = document.getElementById('requestJobId');
        const jobInfoDisplay = document.getElementById('selectedJobInfo');
        
        if (jobSelect) {
            jobSelect.addEventListener('change', function() {
                const selectedOption = this.options[this.selectedIndex];
                const plate = selectedOption.getAttribute('data-plate') || '';
                const customer = selectedOption.getAttribute('data-customer') || '';
                
                if (plate && customer) {
                    jobInfoDisplay.textContent = `Vehicle: ${plate} | Customer: ${customer}`;
                    jobInfoDisplay.classList.remove('text-danger');
                    jobInfoDisplay.classList.add('text-success');
                } else if (this.value === '') {
                    jobInfoDisplay.textContent = '';
                } else {
                    jobInfoDisplay.textContent = 'Job details not available';
                    jobInfoDisplay.classList.remove('text-success');
                    jobInfoDisplay.classList.add('text-danger');
                }
            });
        }

        // Function to open diagnostics modal
        function openDiagnosticsModal(jobId, plateNumber) {
            const modal = document.getElementById('diagnosticsModal');
            const jobIdInput = document.getElementById('diagJobId');
            const modalTitle = modal.querySelector('.modal-title');
            
            if (modal && jobIdInput) {
                jobIdInput.value = jobId;
                modalTitle.innerHTML = `<i class="bi bi-clipboard2-plus" style="color:var(--primary-blue);"></i> Record Notes - Job ${jobId} (${plateNumber})`;
                
                // Load existing diagnostics if any
                fetch(`../../backend/api/jobs.php?resource=diagnostics&job_id=${jobId}`)
                    .then(response => response.json())
                    .then(result => {
                        if (result.success && result.data) {
                            const diag = result.data;
                            document.getElementById('diagNotes').value = diag.Notes || '';
                            document.getElementById('diagRecommendation').value = diag.Recommendation || '';
                            document.getElementById('diagEstimatedCost').value = diag.EstimatedCost || '';
                        }
                    })
                    .catch(error => {
                        console.error('Error loading diagnostics:', error);
                    });
                
                const modalInstance = new bootstrap.Modal(modal);
                modalInstance.show();
            }
        }

        // Function to save diagnostics
        function saveDiagnostics(event) {
            event.preventDefault();
            const form = document.getElementById('diagnosticsForm');
            const jobId = document.getElementById('diagJobId').value;
            const notes = document.getElementById('diagNotes').value;

            // Validation
            if (!jobId) {
                showToast('Job ID is required.', 'danger');
                return;
            }

            if (!notes || notes.trim().length < 5) {
                showToast('Please provide detailed notes (minimum 5 characters).', 'danger');
                document.getElementById('diagNotes').classList.add('is-invalid');
                return;
            }

            const payload = {
                job_id: jobId,
                notes: notes.trim()
            };

            const submitBtn = form.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            fetch('../../backend/api/jobs.php?resource=diagnostics', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': typeof window.getCsrfToken === 'function' ? window.getCsrfToken() : ''
                },
                body: JSON.stringify({ ...payload, csrf_token: typeof window.getCsrfToken === 'function' ? window.getCsrfToken() : '' })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showToast('Diagnostics saved successfully.', 'success');
                    const modal = document.getElementById('diagnosticsModal');
                    const modalInstance = bootstrap.Modal.getInstance(modal);
                    if (modalInstance) modalInstance.hide();
                    form.reset();
                    // Update job history table to show updated diagnostics
                    if (typeof softReload === 'function') {
                        softReload();
                    } else {
                        location.reload();
                    }
                } else {
                    showToast(result.message || 'Failed to save diagnostics.', 'danger');
                }
            })
            .catch(error => {
                console.error('Error saving diagnostics:', error);
                showToast('Network error. Please try again.', 'danger');
            })
            .finally(() => {
                if (submitBtn) submitBtn.disabled = false;
            });
        }

        // Function to delete job from history
        function deleteJobFromHistory(jobId, label) {
            if (typeof showConfirmModal === 'function') {
                showConfirmModal('Delete Job', `Are you sure you want to delete "${label}"?`, () => {
                    fetch(`../../backend/api/jobs.php?resource=repairjobs&id=${jobId}`, {
                        method: 'DELETE',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': typeof window.getCsrfToken === 'function' ? window.getCsrfToken() : ''
                        }
                    })
                    .then(response => response.json())
                    .then(result => {
                        if (result.success) {
                            showToast('Job removed successfully.', 'success');
                            if (typeof softReload === 'function') {
                                softReload();
                            } else {
                                location.reload();
                            }
                        } else {
                            showToast(result.message || 'Failed to delete job.', 'danger');
                        }
                    })
                    .catch(error => {
                        console.error('Error deleting job:', error);
                        showToast('Network error. Please try again.', 'danger');
                    });
                });
            } else {
                if (!confirm(`Are you sure you want to delete "${label}"?`)) return;
                fetch(`../../backend/api/jobs.php?resource=repairjobs&id=${jobId}`, {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': typeof window.getCsrfToken === 'function' ? window.getCsrfToken() : ''
                    }
                })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showToast('Job removed successfully.', 'success');
                        if (typeof softReload === 'function') {
                            softReload();
                        } else {
                            location.reload();
                        }
                    } else {
                        showToast(result.message || 'Failed to delete job.', 'danger');
                    }
                })
                .catch(error => {
                    console.error('Error deleting job:', error);
                    showToast('Network error. Please try again.', 'danger');
                });
            }
        }

        // Function to cancel part request
        function cancelPartRequest(requestId) {
            if (!confirm('Are you sure you want to cancel this request?')) return;
            
            fetch(`../../backend/api/inventory.php?resource=sparepartrequests&id=${requestId}`, {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': typeof window.getCsrfToken === 'function' ? window.getCsrfToken() : ''
                }
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showToast('Request cancelled successfully.', 'success');
                    if (typeof loadMechanicRequests === 'function') {
                        loadMechanicRequests();
                    }
                } else {
                    showToast(result.message || 'Failed to cancel request.', 'danger');
                }
            })
            .catch(error => {
                console.error('Error cancelling request:', error);
                showToast('Network error. Please try again.', 'danger');
            });
        }

        // Event delegation for dynamically added cancel buttons
        document.addEventListener('click', function(event) {
            const cancelBtn = event.target.closest('[data-action="cancel-request"]');
            if (cancelBtn) {
                const requestId = cancelBtn.getAttribute('data-request-id');
                if (requestId) {
                    cancelPartRequest(requestId);
                }
            }
        });

        window.openDiagnosticsModal = openDiagnosticsModal;
        window.saveDiagnostics = saveDiagnostics;
        window.deleteJobFromHistory = deleteJobFromHistory;
        window.cancelPartRequest = cancelPartRequest;

        // Same Status -> badge-class mapping as job_status_badge_class() in
        // Mechanic.php, so the badge painted here after an update always
        // matches what a fresh page load would render.
        function statusBadgeClass(status) {
            switch (status) {
                case 'Delivered':
                case 'Ready':
                    return 'badge-delivered';
                case 'Pending':
                    return 'badge-pending';
                case 'In Progress':
                case 'Diagnosed':
                    return 'badge-inprogress';
                case 'Awaiting Parts':
                    return 'badge-awaiting';
                case 'Cancelled':
                    return 'badge-danger';
                default:
                    return 'badge-ok';
            }
        }

        // Automatic status update function
        function autoUpdateStatus(selectElement) {
            const jobId = selectElement.getAttribute('data-job-id');
            const newStatus = selectElement.value;
            const previousStatus = selectElement.getAttribute('data-previous-status') || selectElement.value;
            
            if (!jobId || !newStatus) return;
            
            selectElement.setAttribute('data-previous-status', previousStatus);
            
            fetch('../../backend/api/jobs.php?resource=repairjobs&id=' + jobId, {
                method: 'PUT',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    status: newStatus
                })
            })
            .then(response => window.parseJsonResponse ? window.parseJsonResponse(response) : response.json())
            .then(data => {
                if (data.success) {
                    showToast('Status updated successfully', 'success');
                    // Reflect the change immediately, but only within this row -
                    // update the read-only Status badge next to the Action
                    // dropdown that just changed it. No full-table re-render:
                    // the table must always look and behave the same way,
                    // whether or not it's been touched.
                    selectElement.setAttribute('data-previous-status', newStatus);
                    const row = selectElement.closest('tr');
                    const badge = row ? row.querySelector('.badge-status') : null;
                    if (badge) {
                        badge.textContent = newStatus;
                        badge.className = 'badge-status ' + statusBadgeClass(newStatus);
                    }
                    // Keep the dashboard's Active Jobs / Awaiting Parts stat
                    // cards in sync with the new status, without touching
                    // this table's layout or row order.
                    if (typeof refreshCounters === 'function') refreshCounters();
                } else {
                    showToast('Failed to update status: ' + (data.message || 'Unknown error'), 'danger');
                    // Revert the select to its last known-good value
                    selectElement.value = previousStatus;
                }
            })
            .catch(error => {
                console.error('Error updating status:', error);
                showToast('Error updating status. Please check your connection and try again.', 'danger');
                selectElement.value = previousStatus;
            });
        }

        // Pagination functionality
        const paginationState = {
            assigned: { currentPage: 1, itemsPerPage: 10, totalItems: <?php echo count($myJobs); ?> },
            history: { currentPage: 1, itemsPerPage: 10, totalItems: <?php echo count($jobHistory); ?> }
        };

        function changePage(tableType, direction) {
            const state = paginationState[tableType];
            const totalPages = Math.max(1, Math.ceil(state.totalItems / state.itemsPerPage));
            const newPage = state.currentPage + direction;
            if (newPage < 1 || newPage > totalPages) return;
            state.currentPage = newPage;
            updatePaginationUI(tableType);
            paginateTable(tableType);
        }

        function updatePaginationUI(tableType) {
            const state = paginationState[tableType];
            const totalPages = Math.max(1, Math.ceil(state.totalItems / state.itemsPerPage));
            const start = state.totalItems === 0 ? 0 : (state.currentPage - 1) * state.itemsPerPage + 1;
            const end = Math.min(state.currentPage * state.itemsPerPage, state.totalItems);
            const footer = document.getElementById(`${tableType}TableFooter`);
            if (footer) {
                footer.textContent = state.totalItems === 0 ? 'Showing 0-0 of 0 entries' : `Showing ${start}-${end} of ${state.totalItems} entries`;
            }
            const prevBtn = document.getElementById(`${tableType}Prev`);
            const nextBtn = document.getElementById(`${tableType}Next`);
            if (prevBtn) prevBtn.disabled = state.currentPage === 1 || totalPages <= 1;
            if (nextBtn) nextBtn.disabled = state.currentPage === totalPages || totalPages <= 1;
        }

        function paginateTable(tableType) {
            const state = paginationState[tableType];
            const table = document.getElementById(tableType === 'assigned' ? 'assignedTable' : 'historyTable');
            if (!table) return;
            const tbody = table.querySelector('tbody');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            const dataRows = rows.filter(row => !row.classList.contains('empty-row'));
            dataRows.forEach((row, index) => {
                const shouldShow = dataRows.length === 0 ? false : (index >= (state.currentPage - 1) * state.itemsPerPage && index < state.currentPage * state.itemsPerPage);
                row.style.display = shouldShow ? '' : 'none';
            });
        }

        function submitPartRequest(event) {
            event.preventDefault();
            const form = document.getElementById('partRequestForm');
            const submitBtn = form.querySelector('button[type="submit"]');
            const payload = {
                spare_part_id: document.getElementById('requestSparePartId').value,
                quantity_requested: document.getElementById('requestQuantity').value,
                reason: document.getElementById('requestReason').value,
                job_id: document.getElementById('requestJobId').value || null
            };

            // Validation
            if (!payload.spare_part_id) {
                showToast('Please select a spare part.', 'danger');
                document.getElementById('requestSparePartId').classList.add('is-invalid');
                return;
            } else {
                document.getElementById('requestSparePartId').classList.remove('is-invalid');
            }

            if (!payload.quantity_requested || Number(payload.quantity_requested) < 1) {
                showToast('Quantity must be at least 1.', 'danger');
                document.getElementById('requestQuantity').classList.add('is-invalid');
                return;
            } else {
                document.getElementById('requestQuantity').classList.remove('is-invalid');
            }

            if (!payload.reason || payload.reason.trim().length < 5) {
                showToast('Please provide a reason for the request.', 'danger');
                document.getElementById('requestReason').classList.add('is-invalid');
                return;
            } else {
                document.getElementById('requestReason').classList.remove('is-invalid');
            }

            if (submitBtn) submitBtn.disabled = true;
            const submitHeaders = new Headers({
                'Content-Type': 'application/json',
                'X-CSRF-Token': typeof window.getCsrfToken === 'function' ? window.getCsrfToken() : ''
            });
            fetch('../../backend/api/inventory.php?resource=sparepartrequests', {
                method: 'POST',
                headers: submitHeaders,
                body: JSON.stringify({ ...payload, csrf_token: typeof window.getCsrfToken === 'function' ? window.getCsrfToken() : '' })
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    showToast(result.message || 'Request submitted successfully.', 'success');
                    form.reset();
                    if (typeof loadMechanicRequests === 'function') {
                        loadMechanicRequests();
                    }
                    // Reset job info display
                    if (jobInfoDisplay) {
                        jobInfoDisplay.textContent = '';
                    }
                } else {
                    showToast(result.message || 'Could not submit request.', 'danger');
                }
            })
            .catch(error => {
                console.error('Submit request error:', error);
                showToast('Network error. Please try again.', 'danger');
            })
            .finally(() => {
                if (submitBtn) submitBtn.disabled = false;
            });
        }

        function loadMechanicRequests() {
            fetch('../../backend/api/inventory.php?resource=sparepartrequests')
                .then(response => response.json())
                .then(result => {
                    const tbody = document.getElementById('requestsTableBody');
                    if (!tbody) return;
                    if (!result.success || !Array.isArray(result.data) || result.data.length === 0) {
                        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No requests yet.</td></tr>';
                        return;
                    }
                    tbody.innerHTML = result.data.map(req => {
                        const statusInfo = {
                            Pending: ['badge-low', 'Pending'],
                            Approved: ['badge-info', 'Approved'],
                            Rejected: ['badge-danger', 'Rejected'],
                            Fulfilled: ['badge-ok', 'Fulfilled']
                        };
                        const [statusClass, statusText] = statusInfo[req.Status] || ['badge-pending', req.Status || 'Pending'];
                        return `<tr>
                            <td>${req.RequestID}</td>
                            <td>${req.SparePartName || 'N/A'}</td>
                            <td>${req.QuantityRequested}</td>
                            <td>${req.Reason || '-'}</td>
                            <td><span class="badge-status ${statusClass}">${statusText}</span></td>
                            <td>${req.RequestedAt ? new Date(req.RequestedAt).toLocaleDateString() : '-'}</td>
                            <td>${req.Status === 'Pending' ? '<button type="button" class="btn-action delete" data-action="cancel-request" data-request-id="' + req.RequestID + '"><i class="bi bi-trash"></i></button>' : '-'}</td>
                        </tr>`;
                    }).join('');
                })
                .catch(error => {
                    console.error('Load requests error:', error);
                    if (document.getElementById('requestsTableBody')) {
                        document.getElementById('requestsTableBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger">Failed to load requests.</td></tr>';
                    }
                });
        }

        function cancelPartRequest(id) {
            if (!confirm('Cancel this pending request?')) return;
            const deleteHeaders = new Headers({
                'X-CSRF-Token': typeof window.getCsrfToken === 'function' ? window.getCsrfToken() : ''
            });
            fetch('../../backend/api/inventory.php?resource=sparepartrequests&id=' + id, {
                method: 'DELETE',
                headers: deleteHeaders,
                body: JSON.stringify({ csrf_token: typeof window.getCsrfToken === 'function' ? window.getCsrfToken() : '' })
            })
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        showToast(result.message || 'Request cancelled.', 'success');
                        loadMechanicRequests();
                    } else {
                        showToast(result.message || 'Could not cancel request.', 'danger');
                    }
                })
                .catch(error => {
                    console.error('Cancel request error:', error);
                    showToast('Network error. Please try again.', 'danger');
                });
        }

        // Initialize pagination on load
        document.addEventListener('DOMContentLoaded', function() {
            updatePaginationUI('assigned');
            updatePaginationUI('history');
            paginateTable('assigned');
            paginateTable('history');

            const partRequestForm = document.getElementById('partRequestForm');
            if (partRequestForm) {
                partRequestForm.addEventListener('submit', function(event) {
                    submitPartRequest(event);
                });
            }

            const requestsTableBody = document.getElementById('requestsTableBody');
            if (requestsTableBody) {
                requestsTableBody.addEventListener('click', function(event) {
                    const deleteButton = event.target.closest('button[data-action="cancel-request"]');
                    if (!deleteButton) return;
                    event.preventDefault();
                    cancelPartRequest(deleteButton.getAttribute('data-request-id'));
                });
            }

            // Initialize pagination
            updatePaginationUI('assigned');
            updatePaginationUI('history');
            paginateTable('assigned');
            paginateTable('history');

            loadMechanicRequests();
        });
    </script>
</body>
</html>
