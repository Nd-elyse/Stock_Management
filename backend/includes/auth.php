<?php

// includes/auth.php - session, role-guard and shared account
// helpers used across the staff dashboards and API endpoints.


if (session_status() === PHP_SESSION_NONE) {
    // Configure secure session settings
    ini_set('session.cookie_secure', '0');           // Set to 0 for HTTP, 1 for HTTPS
    ini_set('session.cookie_httponly', '1');         // Prevent JavaScript access
    ini_set('session.cookie_samesite', 'Lax');       // Allow same-site navigation
    ini_set('session.use_strict_mode', '1');         // Reject uninitialized session IDs
    ini_set('session.gc_maxlifetime', '3600');       // 1 hour session lifetime
    ini_set('session.cookie_lifetime', '3600');       // 1 hour cookie lifetime
    session_start();
}

/**
 * Handle errors by logging detailed info and returning generic message to client
 * @param Exception $e The exception to handle
 * @param string $userMessage Generic message to show to user
 * @return array Response array with success=false and message
 */
function handle_error(Exception $e, string $userMessage = 'An error occurred. Please try again or contact support.'): array
{
    // Log detailed error server-side
    error_log(get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    
    return [
        'success' => false,
        'message' => $userMessage
    ];
}

function require_role(string $expectedRole): void
{
    // Prevent the browser (and any intermediate cache) from serving a
    // cached copy of this protected page via the back/forward button
    // after the user has logged out.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    // Check session timeout
    check_session_timeout();

    // Check if user is logged in
    if (empty($_SESSION['user'])) {
        header('Location: ../pages/login.php');
        exit;
    }

    // Check if user has the correct role
    if ($_SESSION['user']['role'] !== $expectedRole) {
        // User is logged in but has wrong role - redirect to their proper dashboard
        $roleRedirects = [
            'Admin' => '../staff/Admin.php',
            'Receptionist' => '../staff/Receptionist.php',
            'Mechanic' => '../staff/Mechanic.php',
            'Stock Manager' => '../staff/Stock_Manager.php',
        ];
        
        $userRole = $_SESSION['user']['role'];
        if (isset($roleRedirects[$userRole])) {
            header('Location: ' . $roleRedirects[$userRole]);
            exit;
        } else {
            header('Location: ../pages/login.php');
            exit;
        }
    }

    // Set session last activity timestamp for timeout handling
    $_SESSION['last_activity'] = time();
}

/** Convenience getter for the logged-in user's data (array) or null. */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * Check if session has expired and redirect to login if needed
 * Session timeout is set to 1 hour (3600 seconds)
 */
function check_session_timeout(): void
{
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 3600)) {
        // Session has expired
        session_unset();
        session_destroy();
        header('Location: ../pages/login.php?session=expired');
        exit;
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
}

/**
 *
 * @param PDO    $pdo               Active database connection.
 * @param string $sessionNameKey    $_SESSION key that stores the display
 *                                  name shown in that dashboard's sidebar
 *                                  (e.g. 'admin_name', 'receptionist_name').
 * @param bool   $syncSessionUser   When true, also keeps $_SESSION['user']
 *                                  in sync (Receptionist.php's original
 *                                  behaviour). Admin.php did not do this,
 *                                  so it stays false there to keep behaviour
 *                                  identical to the original code.
 */
function handle_profile_update_request(PDO $pdo, string $sessionNameKey, bool $syncSessionUser = false): void
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'update_profile') {
        return;
    }

    header('Content-Type: application/json');
    $response = ['success' => false, 'message' => ''];

    try {
        $userId = (int) ($_SESSION['user']['id'] ?? 0);
        if (!$userId) throw new Exception('User not logged in.');

        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $username = trim($_POST['username'] ?? '');
        $current  = $_POST['current_password'] ?? '';
        $newPass  = $_POST['new_password'] ?? '';
        $confirm  = $_POST['confirm_password'] ?? '';

        // Validate required fields
        if (empty($fullName) || empty($email) || empty($username)) {
            throw new Exception('Full name, email, and username are required.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email address.');
        }

        // Fetch current user from DB
        $stmt = $pdo->prepare("SELECT * FROM users WHERE UserID = ?");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) throw new Exception('User not found.');

        // Check if username is being changed and if it's already taken
        if ($username !== $user['Username']) {
            $checkStmt = $pdo->prepare("SELECT UserID FROM users WHERE Username = ? AND UserID != ?");
            $checkStmt->execute([$username, $userId]);
            if ($checkStmt->fetch()) {
                throw new Exception('Username already taken by another user.');
            }
        }

        // Verify current password if trying to change password or username
        if (!empty($newPass) || !empty($current) || $username !== $user['Username']) {
            if (empty($current)) throw new Exception('Current password is required to change username or password.');
            if (!password_verify($current, $user['Password'])) {
                throw new Exception('Current password is incorrect.');
            }
        }
        if (!empty($newPass)) {
            if (strlen($newPass) < 6) throw new Exception('New password must be at least 6 characters.');
            if ($newPass !== $confirm) throw new Exception('Passwords do not match.');
        }

        // Build update query
        $sql = "UPDATE users SET FullName = ?, Email = ?, Username = ?";
        $params = [$fullName, $email, $username];

        if (!empty($newPass)) {
            $hashed = password_hash($newPass, PASSWORD_DEFAULT);
            $sql .= ", Password = ?";
            $params[] = $hashed;
        }

        $sql .= " WHERE UserID = ?";
        $params[] = $userId;

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        // Update session name shown in the sidebar
        $_SESSION[$sessionNameKey] = $fullName;

        // Receptionist.php additionally kept the full session user record
        // in sync; Admin.php never did, so this only runs when asked to.
        if ($syncSessionUser) {
            $_SESSION['user']['full_name'] = $fullName;
            $_SESSION['user']['username'] = $username;
            $_SESSION['user']['email'] = $email;
        }

        $response['success'] = true;
        $response['message'] = 'Profile updated successfully.';
    } catch (Exception $e) {
        $response['message'] = $e->getMessage();
    }

    echo json_encode($response);
    exit;
}

/**
 * Best-effort audit log for sensitive actions (approvals, rejections, user
 * management, deletions). Creates the table on first use, same pattern as
 * ensure_notifications_table(). Logging failures are swallowed on purpose --
 * an audit-log write must never be the reason a real business operation
 * fails or rolls back, so call this AFTER the real transaction has already
 * committed.
 *
 * @param PDO         $pdo
 * @param int|null    $userId    Who performed the action (null if system/unauthenticated).
 * @param string      $role      Role at the time of the action.
 * @param string      $action    Short verb, e.g. 'approve', 'reject', 'delete', 'create'.
 * @param string      $resource  Entity name, e.g. 'sparepartrequest', 'purchase', 'user'.
 * @param int|null    $resourceId
 * @param string|null $before    Old value/state (free text or JSON), or null.
 * @param string|null $after     New value/state (free text or JSON), or null.
 * @param string|null $reason    Free-text reason, if any.
 */
function write_audit_log(PDO $pdo, ?int $userId, string $role, string $action, string $resource, ?int $resourceId, ?string $before = null, ?string $after = null, ?string $reason = null): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS audit_log (
                AuditID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                UserID INT(11) DEFAULT NULL,
                UserRole VARCHAR(30) DEFAULT NULL,
                Action VARCHAR(50) NOT NULL,
                Resource VARCHAR(50) NOT NULL,
                ResourceID INT(11) DEFAULT NULL,
                BeforeValue TEXT DEFAULT NULL,
                AfterValue TEXT DEFAULT NULL,
                Reason TEXT DEFAULT NULL,
                IPAddress VARCHAR(45) DEFAULT NULL,
                CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_audit_resource (Resource, ResourceID),
                KEY idx_audit_user (UserID)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");

        $stmt = $pdo->prepare(
            'INSERT INTO audit_log (UserID, UserRole, Action, Resource, ResourceID, BeforeValue, AfterValue, Reason, IPAddress)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            $role,
            $action,
            $resource,
            $resourceId,
            $before,
            $after,
            $reason,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Exception $e) {
        // Never let audit logging break the real operation it's logging.
        error_log('Audit log write failed: ' . $e->getMessage());
    }
}

/**
 * Ensure the notifications table exists. Both Admin.php and
 * Receptionist.php ran this identical guard before querying
 * notifications; Mechanic.php and Stock_Manager.php do not call it,
 * matching their original behaviour of assuming the table already exists.
 */
function ensure_notifications_table(PDO $pdo): void
{
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS notifications (
                NotificationID INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
                UserID INT(11) DEFAULT NULL,
                Type VARCHAR(50) DEFAULT NULL,
                Message TEXT DEFAULT NULL,
                IsRead TINYINT(1) DEFAULT 0,
                Link VARCHAR(255) DEFAULT NULL,
                CreatedAt DATETIME DEFAULT CURRENT_TIMESTAMP,
                Status ENUM('Pending','Resolved') DEFAULT NULL,
                ResolvedAt DATETIME DEFAULT NULL,
                KEY UserID (UserID),
                KEY idx_notifications_user_isread (UserID, IsRead),
                KEY idx_notifications_type (Type),
                CONSTRAINT fk_notifications_user FOREIGN KEY (UserID) REFERENCES users(UserID) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    } catch (PDOException $e) {
        // table may already exist
    }
}
