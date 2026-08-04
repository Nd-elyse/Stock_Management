<?php

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
header('Content-Type: application/json');

// Only logged-in Admins may manage users
if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'Admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// Require CSRF token for state-changing requests
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    require_csrf_token();
}

switch ($method) {

    // LIST
    case 'GET':
        $stmt = $pdo->query('SELECT UserID, MechanicID, Username, Role, FullName, Email, Phone, Status FROM users ORDER BY UserID');
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    //  CREATE
    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $fullName = trim($body['full_name'] ?? '');
        $username = trim($body['username'] ?? '');
        $email    = trim($body['email'] ?? '');
        $phone    = trim($body['phone'] ?? '');
        $role     = $body['role'] ?? '';
        $status   = $body['status'] ?? 'Active';
        $password = (string) ($body['password'] ?? '');
        $specialization = trim($body['specialization'] ?? '');
        $salary = (float) ($body['salary'] ?? 0);

        // Comprehensive validation using validation utility
        $errors = [];
        
        if ($error = validate_name_field($fullName)) {
            $errors[] = $error;
        }
        
        if ($error = validate_username_field($username)) {
            $errors[] = $error;
        }
        
        if ($email && $error = validate_email_field($email)) {
            $errors[] = $error;
        }
        
        if ($phone && $error = validate_phone_rwanda_field($phone)) {
            $errors[] = $error;
        }
        
        if ($error = validate_password_field($password, true)) {
            $errors[] = $error;
        }
        
        $validRoles = ['Admin', 'Receptionist', 'Mechanic', 'Stock Manager'];
        if (!validate_enum($role, $validRoles)) {
            $errors[] = 'Invalid role. Must be one of: ' . implode(', ', $validRoles);
        }
        
        $validStatuses = ['Active', 'Inactive'];
        if (!validate_enum($status, $validStatuses)) {
            $errors[] = 'Invalid status. Must be Active or Inactive.';
        }
        
        if ($role === 'Mechanic' && !validate_required($specialization)) {
            $errors[] = 'Specialization is required for Mechanic role.';
        }
        
        if ($role === 'Mechanic' && $error = validate_positive_numeric_field($salary, 'Salary')) {
            $errors[] = $error;
        }
        
        if (!empty($errors)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
            exit;
        }
        
        if ($specialization && !validate_string_length($specialization, null, 50)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Specialization must not exceed 50 characters.']);
            exit;
        }
        
        if ($salary < 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Salary cannot be negative.']);
            exit;
        }
        
        // Check for duplicate username
        if (check_duplicate($pdo, 'users', 'Username', $username, null, 'UserID')) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Username already exists.']);
            exit;
        }
        
        // Check for duplicate email if provided
        if ($email && check_duplicate($pdo, 'users', 'Email', $email, null, 'UserID')) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Email already exists.']);
            exit;
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            // Create user
            $stmt = $pdo->prepare(
                'INSERT INTO users (Username, Password, Role, FullName, Email, Phone, Status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$username, $hash, $role, $fullName, $email, $phone, $status]);
            $userId = $pdo->lastInsertId();

            // If role is Mechanic, create linked mechanics record
            if ($role === 'Mechanic') {
                $mechStmt = $pdo->prepare(
                    'INSERT INTO mechanics (FullName, Phone, Specialization, Salary) VALUES (?, ?, ?, ?)'
                );
                $mechStmt->execute([$fullName, $phone, $specialization, $salary]);
                $mechanicId = $pdo->lastInsertId();

                // Update user with MechanicID
                $updateStmt = $pdo->prepare('UPDATE users SET MechanicID = ? WHERE UserID = ?');
                $updateStmt->execute([$mechanicId, $userId]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'id' => $userId, 'message' => 'User created.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            error_log('Failed to create user: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to create user. Please try again.']);
        }
        break;

    //  UPDATE
    case 'PUT':
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Missing user id.']);
            exit;
        }
        
        // Validate user exists
        $userCheck = $pdo->prepare('SELECT UserID FROM users WHERE UserID = ?');
        $userCheck->execute([$id]);
        if (!$userCheck->fetch()) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'User not found.']);
            exit;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        $fullName = trim($body['full_name'] ?? '');
        $username = trim($body['username'] ?? '');
        $email    = trim($body['email'] ?? '');
        $phone    = trim($body['phone'] ?? '');
        $role     = $body['role'] ?? '';
        $status   = $body['status'] ?? 'Active';
        $password = (string) ($body['password'] ?? ''); // optional on edit
        $specialization = trim($body['specialization'] ?? '');
        $salary = (float) ($body['salary'] ?? 0);

        if ($fullName && strlen($fullName) > 100) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Full name must not exceed 100 characters.']);
            exit;
        }
        
        if ($username) {
            if (strlen($username) < 3 || strlen($username) > 50) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Username must be between 3 and 50 characters.']);
                exit;
            }
            
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Username can only contain letters, numbers, and underscores.']);
                exit;
            }
            
            // Check for duplicate username (excluding current user)
            $usernameCheck = $pdo->prepare('SELECT UserID FROM users WHERE Username = ? AND UserID != ?');
            $usernameCheck->execute([$username, $id]);
            if ($usernameCheck->fetch()) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Username already exists for another user.']);
                exit;
            }
        }
        
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid email format.']);
            exit;
        }
        
        if ($email && strlen($email) > 100) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Email must not exceed 100 characters.']);
            exit;
        }
        
        if ($email) {
            // Check for duplicate email (excluding current user)
            $emailCheck = $pdo->prepare('SELECT UserID FROM users WHERE Email = ? AND UserID != ?');
            $emailCheck->execute([$email, $id]);
            if ($emailCheck->fetch()) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Email already exists for another user.']);
                exit;
            }
        }
        
        if ($phone && strlen($phone) > 20) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Phone number must not exceed 20 characters.']);
            exit;
        }
        
        if ($password && strlen($password) < 6) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Password must be at least 6 characters.']);
            exit;
        }
        
        if ($role) {
            $validRoles = ['Admin', 'Receptionist', 'Mechanic', 'Stock Manager'];
            if (!in_array($role, $validRoles, true)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invalid role. Must be one of: ' . implode(', ', $validRoles)]);
                exit;
            }
        }
        
        $validStatuses = ['Active', 'Inactive'];
        if (!in_array($status, $validStatuses, true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Invalid status. Must be Active or Inactive.']);
            exit;
        }
        
        if ($role === 'Mechanic' && !$specialization) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Specialization is required for Mechanic role.']);
            exit;
        }
        
        if ($specialization && strlen($specialization) > 50) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Specialization must not exceed 50 characters.']);
            exit;
        }
        
        if ($salary < 0) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Salary cannot be negative.']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            // Get current user data to check role change
            $currentStmt = $pdo->prepare('SELECT Role, MechanicID FROM users WHERE UserID = ?');
            $currentStmt->execute([$id]);
            $currentUser = $currentStmt->fetch(PDO::FETCH_ASSOC);

            // Update user
            if ($password !== '') {
                $stmt = $pdo->prepare(
                    'UPDATE users SET Username=?, Password=?, Role=?, FullName=?, Email=?, Phone=?, Status=? WHERE UserID=?'
                );
                $stmt->execute([$username, password_hash($password, PASSWORD_BCRYPT), $role, $fullName, $email, $phone, $status, $id]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE users SET Username=?, Role=?, FullName=?, Email=?, Phone=?, Status=? WHERE UserID=?'
                );
                $stmt->execute([$username, $role, $fullName, $email, $phone, $status, $id]);
            }

            // Handle mechanic profile
            if ($role === 'Mechanic') {
                if ($currentUser['MechanicID']) {
                    // Update existing mechanic record
                    $mechStmt = $pdo->prepare(
                        'UPDATE mechanics SET FullName=?, Phone=?, Specialization=?, Salary=? WHERE MechanicID=?'
                    );
                    $mechStmt->execute([$fullName, $phone, $specialization, $salary, $currentUser['MechanicID']]);
                } else {
                    // Create new mechanic record and link to user
                    $mechStmt = $pdo->prepare(
                        'INSERT INTO mechanics (FullName, Phone, Specialization, Salary) VALUES (?, ?, ?, ?)'
                    );
                    $mechStmt->execute([$fullName, $phone, $specialization, $salary]);
                    $mechanicId = $pdo->lastInsertId();

                    $updateStmt = $pdo->prepare('UPDATE users SET MechanicID = ? WHERE UserID = ?');
                    $updateStmt->execute([$mechanicId, $id]);
                }
            } elseif ($currentUser['Role'] === 'Mechanic' && $role !== 'Mechanic') {
                // Role changed from Mechanic to something else - unlink mechanic record
                $updateStmt = $pdo->prepare('UPDATE users SET MechanicID = NULL WHERE UserID = ?');
                $updateStmt->execute([$id]);
            }

            $pdo->commit();
            echo json_encode(['success' => true, 'message' => 'User updated.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            error_log('Failed to update user: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to update user. Please try again.']);
        }
        break;

    //  DELETE
    case 'DELETE':
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Missing user id.']);
            exit;
        }
        try {
            $deletedUserStmt = $pdo->prepare('SELECT Username, Role FROM users WHERE UserID = ?');
            $deletedUserStmt->execute([$id]);
            $deletedUser = $deletedUserStmt->fetch(PDO::FETCH_ASSOC);

            $stmt = $pdo->prepare('DELETE FROM users WHERE UserID = ?');
            $stmt->execute([$id]);
            if ($deletedUser) {
                write_audit_log(
                    $pdo, (int) ($_SESSION['user']['id'] ?? 0), $_SESSION['user']['role'] ?? '', 'delete', 'user', $id,
                    $deletedUser['Username'] . ' (' . $deletedUser['Role'] . ')', null, null
                );
            }
            echo json_encode(['success' => true, 'message' => 'User deleted.']);
        } catch (PDOException $e) {
            http_response_code(409);
            if ((int) $e->errorInfo[1] === 1451) {
                echo json_encode(['success' => false, 'message' => 'Cannot delete: this user has linked data (mechanic profile, jobs, etc.). Remove those first.']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
            }
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
}
