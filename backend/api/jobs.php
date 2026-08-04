<?php

// api/jobs.php - repair jobs, diagnostics & mechanics

// Pick a resource with ?resource=repairjobs|diagnostics|mechanics

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
header('Content-Type: application/json');

$resource = $_GET['resource'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];

// Require CSRF token for state-changing requests
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    require_csrf_token();
}


// REPAIR JOBS

if ($resource === 'repairjobs') {
    if (empty($_SESSION['user'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized.']);
        exit;
    }

    // A Mechanic may only ever see jobs assigned to their own MechanicID.
    $currentRole = $_SESSION['user']['role'] ?? '';
    $myMechanicId = $currentRole === 'Mechanic' ? (int) ($_SESSION['user']['mechanic_id'] ?? 0) : null;

    switch ($method) {
        case 'GET':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id) {
                $stmt = $pdo->prepare(
                    "SELECT rj.*,
                            c.FullName AS CustomerName,
                            v.PlateNumber,
                            v.Model AS VehicleModel,
                            m.FullName AS MechanicName
                     FROM repairjobs rj
                     LEFT JOIN vehicles v ON v.VehicleID = rj.VehicleID
                     LEFT JOIN customers c ON c.CustomerID = v.CustomerID
                     LEFT JOIN mechanics m ON m.MechanicID = rj.MechanicID
                     WHERE rj.JobID = ?"
                );
                $stmt->execute([$id]);
                $repairJob = $stmt->fetch();

                if (!$repairJob) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Repair job not found.']);
                    exit;
                }

                if ($currentRole === 'Mechanic' && (int) $repairJob['MechanicID'] !== $myMechanicId) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'This job is not assigned to you.']);
                    exit;
                }

                echo json_encode(['success' => true, 'data' => $repairJob]);
                break;
            }

            $sql = "SELECT rj.*,
                    c.FullName AS CustomerName,
                    v.PlateNumber,
                    v.Model AS VehicleModel,
                    m.FullName AS MechanicName
                    FROM repairjobs rj
                    LEFT JOIN vehicles v ON v.VehicleID = rj.VehicleID
                    LEFT JOIN customers c ON c.CustomerID = v.CustomerID
                    LEFT JOIN mechanics m ON m.MechanicID = rj.MechanicID";

            if ($currentRole === 'Mechanic') {
                // Mechanics only ever get their own assigned jobs, never the full list.
                if (!$myMechanicId) {
                    echo json_encode(['success' => true, 'data' => []]);
                    break;
                }
                $sql .= " WHERE rj.MechanicID = ? ORDER BY rj.JobID DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$myMechanicId]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
                break;
            }

            $sql .= " ORDER BY rj.JobID DESC";
            echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
            break;

        case 'POST':
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $vehicleId = (int) ($body['vehicle_id'] ?? 0);
            $mechanicId = (int) ($body['mechanic_id'] ?? 0) ?: null;
            $userId = (int) ($_SESSION['user']['id'] ?? 0);
            $startDate = trim($body['start_date'] ?? date('Y-m-d'));
            $endDate = trim($body['end_date'] ?? '');
            $status = trim($body['status'] ?? 'Pending');

            if (!$vehicleId) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Vehicle is required.']);
                exit;
            }

            // Validate vehicle exists
            $vehicleCheck = $pdo->prepare('SELECT VehicleID FROM vehicles WHERE VehicleID = ?');
            $vehicleCheck->execute([$vehicleId]);
            if (!$vehicleCheck->fetch()) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Vehicle not found.']);
                exit;
            }

            // Validate mechanic exists if provided
            if ($mechanicId) {
                $mechanicCheck = $pdo->prepare('SELECT MechanicID FROM mechanics WHERE MechanicID = ?');
                $mechanicCheck->execute([$mechanicId]);
                if (!$mechanicCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Mechanic not found.']);
                    exit;
                }
            }

            if ($error = validate_date_not_future_field($startDate, 'Start date')) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            if ($error = validate_date_field($endDate, 'End date', 'Y-m-d', false)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            // Validate date range if both dates are provided
            if ($startDate && $endDate && !validate_date_range($startDate, $endDate)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Start date must be before or equal to end date.']);
                exit;
            }

            $validStatuses = ['Pending', 'Diagnosed', 'In Progress', 'Awaiting Parts', 'Ready', 'Delivered', 'Cancelled'];
            if ($error = validate_enum_field($status, 'Status', $validStatuses)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO repairjobs (VehicleID, MechanicID, UserID, StartDate, EndDate, Status) VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$vehicleId, $mechanicId, $userId, $startDate, $endDate ?: null, $status]);
            $jobId = $pdo->lastInsertId();

            echo json_encode(['success' => true, 'id' => $jobId, 'message' => 'Repair job created.']);
            break;

        case 'PUT':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing job id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist', 'Mechanic'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }

            // Validate job exists
            $jobCheck = $pdo->prepare('SELECT JobID, MechanicID, StartDate, EndDate, Status FROM repairjobs WHERE JobID = ?');
            $jobCheck->execute([$id]);
            $existingJob = $jobCheck->fetch();
            if (!$existingJob) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Repair job not found.']);
                exit;
            }

            // A Mechanic can only update the status of a job assigned to them, and
            // can never reassign the job to a different mechanic.
            if ($currentRole === 'Mechanic') {
                if (!$myMechanicId || (int) $existingJob['MechanicID'] !== $myMechanicId) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'This job is not assigned to you.']);
                    exit;
                }
            }

            $body = json_decode(file_get_contents('php://input'), true) ?? [];

            // Bug fix: this used to unconditionally overwrite MechanicID/EndDate/
            // Status with whatever was in the body (or blank/null if absent), so
            // a partial update -- like the mechanic's quick status-only PUT --
            // silently wiped EndDate back to NULL every time. Every field below
            // now falls back to the job's current value when the caller didn't
            // send that key at all, and only actually changes when it's present.
            if ($currentRole === 'Mechanic') {
                $mechanicId = $myMechanicId;
            } elseif (array_key_exists('mechanic_id', $body)) {
                $mechanicId = (int) ($body['mechanic_id'] ?? 0) ?: null;
            } else {
                $mechanicId = $existingJob['MechanicID'];
            }

            // Bug fix: start_date was collected by the Edit Job form and sent as
            // start_date, but this endpoint never read it, so editing a job's
            // start date silently did nothing.
            $startDate = array_key_exists('start_date', $body) ? trim((string) $body['start_date']) : null;
            if ($startDate === '') {
                $startDate = null; // don't allow clearing a required field via empty string
            }

            $endDate = array_key_exists('end_date', $body) ? trim((string) $body['end_date']) : $existingJob['EndDate'];
            $status = array_key_exists('status', $body) ? trim((string) $body['status']) : $existingJob['Status'];

            // Validate mechanic exists if provided
            if ($mechanicId) {
                $mechanicCheck = $pdo->prepare('SELECT MechanicID FROM mechanics WHERE MechanicID = ?');
                $mechanicCheck->execute([$mechanicId]);
                if (!$mechanicCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Mechanic not found.']);
                    exit;
                }
            }

            if ($startDate !== null && ($error = validate_date_not_future_field($startDate, 'Start date'))) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            if ($error = validate_date_field($endDate, 'End date', 'Y-m-d', false)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            // Only re-validate "not in the past" when the caller actually sent a
            // new end_date in this request. Without this check, an update that
            // omits end_date (e.g. a status-only change) falls back to the job's
            // existing EndDate, and if that stored date has since passed, every
            // future update to the job would fail with this error even though
            // the end date was never touched.
            if (array_key_exists('end_date', $body) && $endDate && !validate_date_not_past($endDate)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'End date cannot be in the past.']);
                exit;
            }

            if ($startDate !== null && $endDate && !validate_date_range($startDate, $endDate)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Start date must be before or equal to end date.']);
                exit;
            }

            if ($status && $error = validate_enum_field($status, 'Status', ['Pending', 'Diagnosed', 'In Progress', 'Awaiting Parts', 'Ready', 'Delivered', 'Cancelled'], false)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            // History/closure statuses must always carry an EndDate so the job
            // is queryable as completed history, even if the caller didn't
            // explicitly send one.
            $closingStatuses = ['Delivered', 'Completed', 'Cancelled'];
            if (!$endDate && in_array($status, $closingStatuses, true)) {
                $endDate = date('Y-m-d');
            }

            $currentStatus = $existingJob['Status'];

            $pdo->beginTransaction();
            try {
                if ($startDate !== null) {
                    $stmt = $pdo->prepare(
                        'UPDATE repairjobs SET MechanicID=?, StartDate=?, EndDate=?, Status=? WHERE JobID=?'
                    );
                    $stmt->execute([$mechanicId, $startDate, $endDate ?: null, $status, $id]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE repairjobs SET MechanicID=?, EndDate=?, Status=? WHERE JobID=?'
                    );
                    $stmt->execute([$mechanicId, $endDate ?: null, $status, $id]);
                }

                // Log status change to history if status changed
                if ($status && $currentStatus && $status !== $currentStatus) {
                    // Get mechanic name for logging
                    $mechanicName = '';
                    if ($mechanicId) {
                        $mechStmt = $pdo->prepare('SELECT FullName FROM mechanics WHERE MechanicID = ?');
                        $mechStmt->execute([$mechanicId]);
                        $mechanicName = $mechStmt->fetchColumn() ?? '';
                    }

                    $historyStmt = $pdo->prepare(
                        'INSERT INTO jobhistory (JobID, PreviousStatus, NewStatus, MechanicName, ChangedByUserID, ChangedAt) 
                         VALUES (?, ?, ?, ?, ?, NOW())'
                    );
                    $historyStmt->execute([$id, $currentStatus, $status, $mechanicName, $_SESSION['user']['id']]);
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Repair job updated.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                error_log('Failed to update job: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to update job. Please try again.']);
            }
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing job id.']);
                exit;
            }
            
            // Admin and Receptionist can delete any job
            // Mechanics can only delete their own completed jobs
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                if ($currentRole === 'Mechanic') {
                    // Check if job is assigned to this mechanic and is completed
                    $jobCheck = $pdo->prepare('SELECT JobID, MechanicID, Status FROM repairjobs WHERE JobID = ?');
                    $jobCheck->execute([$id]);
                    $job = $jobCheck->fetch();
                    if (!$job) {
                        http_response_code(404);
                        echo json_encode(['success' => false, 'message' => 'Job not found.']);
                        exit;
                    }
                    if ((int) $job['MechanicID'] !== $myMechanicId) {
                        http_response_code(403);
                        echo json_encode(['success' => false, 'message' => 'This job is not assigned to you.']);
                        exit;
                    }
                    $completedStatuses = ['Delivered', 'Ready', 'Completed', 'Cancelled'];
                    if (!in_array($job['Status'], $completedStatuses, true)) {
                        http_response_code(403);
                        echo json_encode(['success' => false, 'message' => 'You can only delete completed jobs.']);
                        exit;
                    }
                } else {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                    exit;
                }
            }
            
            try {
                $stmt = $pdo->prepare('DELETE FROM repairjobs WHERE JobID = ?');
                $stmt->execute([$id]);
                reindex_table_ids($pdo, 'repairjobs', 'JobID');
                echo json_encode(['success' => true, 'message' => 'Repair job deleted.']);
            } catch (PDOException $e) {
                http_response_code(409);
                if ((int) $e->errorInfo[1] === 1451) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: this job has linked invoices. Remove them first.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
                }
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    }
    exit;
}


// DIAGNOSTICS (Mechanic only, POST only)

if ($resource === 'diagnostics') {
    if (($_SESSION['user']['role'] ?? '') !== 'Mechanic' || empty($_SESSION['user']['mechanic_id'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized.']);
        exit;
    }

    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $jobId = (int) ($body['job_id'] ?? 0);
    $mechanicId = (int) $_SESSION['user']['mechanic_id'];
    $notes = trim($body['notes'] ?? '');
    
    // Use current date if not provided
    $date = date('Y-m-d');

    $errors = [];
    if (!validate_integer($jobId, 1)) {
        $errors[] = 'Job ID is required and must be a positive integer.';
    }
    if ($error = validate_text_field($notes, 'Notes', 1, 5000)) {
        $errors[] = $error;
    }

    if (!empty($errors)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
        exit;
    }

    $ownership = $pdo->prepare('SELECT 1 FROM repairjobs WHERE JobID = ? AND MechanicID = ?');
    $ownership->execute([$jobId, $mechanicId]);
    if (!$ownership->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'This job is not assigned to you.']);
        exit;
    }

    $stmt = $pdo->prepare('INSERT INTO diagnostics (JobID, MechanicID, DiagnosticDate, Notes) VALUES (?, ?, ?, ?)');
    $stmt->execute([$jobId, $mechanicId, $date, $notes]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Notes saved successfully.']);
    exit;
}


// MECHANICS

if ($resource === 'mechanics') {
    if (empty($_SESSION['user'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized.']);
        exit;
    }

    switch ($method) {
        case 'GET':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id) {
                // Get single mechanic by ID
                $stmt = $pdo->prepare('SELECT * FROM mechanics WHERE MechanicID = ?');
                $stmt->execute([$id]);
                $mechanic = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($mechanic) {
                    echo json_encode(['success' => true, 'data' => $mechanic]);
                } else {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Mechanic not found.']);
                }
            } else {
                // Get all mechanics
                $sql = "SELECT m.MechanicID, m.FullName, m.Phone, m.Specialization, m.Salary,
                               COUNT(rj.JobID) AS AssignedJobs
                        FROM mechanics m
                        LEFT JOIN repairjobs rj ON rj.MechanicID = m.MechanicID
                        GROUP BY m.MechanicID
                        ORDER BY m.MechanicID";
                echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
            }
            break;

        case 'POST':
            if (!in_array($_SESSION['user']['role'], ['Admin'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $fullName = trim($body['full_name'] ?? '');
            $phone = trim($body['phone'] ?? '');
            $specialization = trim($body['specialization'] ?? '');
            $salary = (float) ($body['salary'] ?? 0);

            if (!$fullName) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Full name is required.']);
                exit;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO mechanics (FullName, Phone, Specialization, Salary) VALUES (?, ?, ?, ?)'
            );
            $stmt->execute([$fullName, $phone, $specialization, $salary]);
            $mechanicId = $pdo->lastInsertId();

            echo json_encode(['success' => true, 'id' => $mechanicId, 'message' => 'Mechanic created.']);
            break;

        case 'PUT':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing mechanic id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $fullName = trim($body['full_name'] ?? '');
            $phone = trim($body['phone'] ?? '');
            $specialization = trim($body['specialization'] ?? '');
            $salary = (float) ($body['salary'] ?? 0);

            $stmt = $pdo->prepare(
                'UPDATE mechanics SET FullName=?, Phone=?, Specialization=?, Salary=? WHERE MechanicID=?'
            );
            $stmt->execute([$fullName, $phone, $specialization, $salary, $id]);
            echo json_encode(['success' => true, 'message' => 'Mechanic updated.']);
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing mechanic id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            try {
                $stmt = $pdo->prepare('DELETE FROM mechanics WHERE MechanicID = ?');
                $stmt->execute([$id]);
                reindex_table_ids($pdo, 'mechanics', 'MechanicID');
                echo json_encode(['success' => true, 'message' => 'Mechanic deleted.']);
            } catch (PDOException $e) {
                http_response_code(409);
                if ((int) $e->errorInfo[1] === 1451) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: this mechanic has linked repair jobs or a user account. Remove those first.']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
                }
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    }
    exit;
}


// Unknown resource

http_response_code(400);
echo json_encode([
    'success' => false,
    'message' => 'Unknown resource. Use ?resource=repairjobs|diagnostics|mechanics',
]);
