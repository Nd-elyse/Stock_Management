<?php
// Pick an endpoint with ?resource=
//   login            (POST)               - was login.php
//   verify-otp       (POST)               - was verify-otp.php
//   resend-otp       (POST)               - was resend-otp.php
//   cancel-otp       (POST)               - was cancel-otp.php
//   logout           (GET, via link)      - was logout.php
//   password-reset   (GET/POST/PUT/DELETE)- was passwordresetrequests.php

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

$resource = $_GET['resource'] ?? '';

// LOGIN - verifies credentials, then emails a one-time code

if ($resource === 'login') {
    session_start();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../includes/otp_mailer.php';

    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($data['username'] ?? '');
    $password = (string) ($data['password'] ?? '');

    if ($username === '' || $password === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter both username and password.']);
        exit;
    }

    // Bug/security fix: OTP attempts were rate-limited (5 tries, see verify-otp
    // below) but the password step itself had no throttling at all, so a
    // script could brute-force the password field indefinitely before ever
    // reaching the OTP stage. This locks the session out for 60s after 5
    // wrong passwords for the same username, mirroring the pattern already
    // used for OTP attempts elsewhere in this file.
    if (!isset($_SESSION['login_attempts'])) {
        $_SESSION['login_attempts'] = [];
    }
    $attemptKey = 'u:' . $username;
    $attempt = $_SESSION['login_attempts'][$attemptKey] ?? ['count' => 0, 'locked_until' => 0];

    if ($attempt['locked_until'] > time()) {
        $wait = $attempt['locked_until'] - time();
        echo json_encode(['success' => false, 'message' => "Too many failed attempts. Please try again in {$wait}s."]);
        exit;
    }

    // Look the user up by username only (never trust a role sent from the browser)
    $stmt = $pdo->prepare('SELECT * FROM users WHERE Username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['Password'])) {
        $attempt['count']++;
        if ($attempt['count'] >= 5) {
            $attempt['locked_until'] = time() + 60;
            $attempt['count'] = 0;
        }
        $_SESSION['login_attempts'][$attemptKey] = $attempt;
        echo json_encode(['success' => false, 'message' => 'Invalid username or password.']);
        exit;
    }

    // Correct password - clear any prior failed-attempt tracking for this username.
    unset($_SESSION['login_attempts'][$attemptKey]);

    if ($user['Status'] !== 'Active') {
        echo json_encode(['success' => false, 'message' => 'This account is inactive. Contact your admin.']);
        exit;
    }

    if (empty($user['Email'])) {
        echo json_encode(['success' => false, 'message' => 'This account has no email on file. Contact your admin.']);
        exit;
    }

    // Password is correct - now generate and email a one-time code instead of
    // logging the user in immediately.
    $otp = generate_otp_code(6);

    $_SESSION['otp_pending'] = [
        'user_id'    => $user['UserID'],
        'username'   => $user['Username'],
        'email'      => $user['Email'],
        'otp'        => $otp,
        'expires_at' => time() + 300, // 5 minutes
        'attempts'   => 0,
        'last_sent'  => time(),
    ];
    session_write_close();

    $sendResult = send_otp_email($user['Email'], $user['FullName'] ?? $user['Username'], $otp);

    if (!$sendResult['success']) {
        echo json_encode(['success' => false, 'message' => $sendResult['message']]);
        exit;
    }

    // Mask the email a little so it isn't shown in full on screen
    $maskedEmail = preg_replace('/^(.).*(@.*)$/', '$1***$2', $user['Email']);

    echo json_encode([
        'success'      => true,
        'otp_required' => true,
        'message'      => 'A verification code was sent to ' . $maskedEmail . '.',
    ]);
    exit;
}

// VERIFY-OTP - completes login once the emailed code is entered

if ($resource === 'verify-otp') {
    session_start();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config/db.php';

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $otp  = trim((string) ($data['otp'] ?? ''));

    if ($otp === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter the verification code.']);
        exit;
    }

    $pending = $_SESSION['otp_pending'] ?? null;

    if (!$pending) {
        echo json_encode(['success' => false, 'message' => 'No pending verification. Please log in again.']);
        exit;
    }

    if (time() > $pending['expires_at']) {
        unset($_SESSION['otp_pending']);
        echo json_encode(['success' => false, 'message' => 'This code has expired. Please log in again to get a new one.']);
        exit;
    }

    if ($pending['attempts'] >= 5) {
        unset($_SESSION['otp_pending']);
        echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Please log in again.']);
        exit;
    }

    if (!hash_equals((string) $pending['otp'], $otp)) {
        $_SESSION['otp_pending']['attempts'] = $pending['attempts'] + 1;
        $remaining = 5 - $_SESSION['otp_pending']['attempts'];
        echo json_encode(['success' => false, 'message' => "Incorrect code. {$remaining} attempt(s) remaining."]);
        exit;
    }

    // Code is correct - load the fresh user record and establish the real session.
    $stmt = $pdo->prepare('SELECT * FROM users WHERE UserID = ? LIMIT 1');
    $stmt->execute([$pending['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['Status'] !== 'Active') {
        unset($_SESSION['otp_pending']);
        echo json_encode(['success' => false, 'message' => 'This account is no longer active. Contact your admin.']);
        exit;
    }

    unset($_SESSION['otp_pending']);

    $_SESSION['user'] = [
        'id'          => $user['UserID'],
        'username'    => $user['Username'],
        'full_name'   => $user['FullName'],
        'email'       => $user['Email'],
        'phone'       => $user['Phone'],
        'role'        => $user['Role'],          // Admin | Receptionist | Mechanic | Stock Manager
        'mechanic_id' => $user['MechanicID'],
    ];

    // These are relative to the login page's own URL (frontend/pages/login.php
    // resolves them client-side via window.location.href), not to this API file.
    $redirects = [
        'Admin'          => '../staff/Admin.php',
        'Receptionist'   => '../staff/Receptionist.php',
        'Mechanic'       => '../staff/Mechanic.php',
        'Stock Manager'  => '../staff/Stock_Manager.php',
    ];

    echo json_encode([
        'success'  => true,
        'role'     => $user['Role'],
        'redirect' => $redirects[$user['Role']] ?? '../pages/login.php',
    ]);
    exit;
}

// RESEND-OTP - re-sends a fresh code, throttled to once/30s

if ($resource === 'resend-otp') {
    session_start();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../includes/otp_mailer.php';

    $pending = $_SESSION['otp_pending'] ?? null;

    if (!$pending) {
        echo json_encode(['success' => false, 'message' => 'No pending verification. Please log in again.']);
        exit;
    }

    // Throttle: at most one resend every 30 seconds.
    if (time() - $pending['last_sent'] < 30) {
        $wait = 30 - (time() - $pending['last_sent']);
        echo json_encode(['success' => false, 'message' => "Please wait {$wait}s before requesting another code."]);
        exit;
    }

    $otp = generate_otp_code(6);

    $_SESSION['otp_pending']['otp']        = $otp;
    $_SESSION['otp_pending']['expires_at'] = time() + 300;
    $_SESSION['otp_pending']['attempts']   = 0;
    $_SESSION['otp_pending']['last_sent']  = time();

    session_write_close();

    $sendResult = send_otp_email($pending['email'], $pending['username'], $otp);

    if (!$sendResult['success']) {
        echo json_encode(['success' => false, 'message' => $sendResult['message']]);
        exit;
    }

    $maskedEmail = preg_replace('/^(.).*(@.*)$/', '$1***$2', $pending['email']);
    echo json_encode(['success' => true, 'message' => 'A new code was sent to ' . $maskedEmail . '.']);
    exit;
}

// CANCEL-OTP - abandons a pending login verification

if ($resource === 'cancel-otp') {
    session_start();
    header('Content-Type: application/json');

    unset($_SESSION['otp_pending']);

    echo json_encode(['success' => true]);
    exit;
}


// LOGOUT - destroys the session and sends the user back to login

if ($resource === 'logout') {
    session_start();

    $_SESSION = [];

    // Also remove the session cookie itself so a stale cookie can't be reused.
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    // Make sure this response itself is never cached either.
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    header('Location: ../../frontend/pages/login.php');
    exit;
}

// FORGOT-PASSWORD - self-service reset via emailed OTP (distinct from the
// Admin-ticket based `password-reset` resource below, which is untouched)

if ($resource === 'forgot-password') {
    session_start();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../includes/otp_mailer.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($data['username'] ?? '');
    $email    = trim($data['email'] ?? '');

    if ($username === '' || $email === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter both username and email.']);
        exit;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE Username = ? AND LOWER(Email) = LOWER(?) LIMIT 1');
    $stmt->execute([$username, $email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Return generic message to prevent user enumeration
        echo json_encode(['success' => true, 'message' => 'If an account matches, a verification code will be sent.']);
        exit;
    }

    if ($user['Status'] !== 'Active') {
        echo json_encode(['success' => false, 'message' => 'This account is inactive. Contact your admin.']);
        exit;
    }

    if (empty($user['Email'])) {
        echo json_encode(['success' => false, 'message' => 'This account has no email on file. Contact your admin.']);
        exit;
    }

    $otp = generate_otp_code(6);

    $_SESSION['pw_reset'] = [
        'user_id'     => $user['UserID'],
        'email'       => $user['Email'],
        'otp'         => $otp,
        'expires_at'  => time() + 600, // 10 minutes
        'attempts'    => 0,
        'last_sent'   => time(),
        'verified'    => false,
        'reset_token' => null,
    ];
    session_write_close();

    $sendResult = send_otp_email($user['Email'], $user['FullName'] ?? $user['Username'], $otp);

    if (!$sendResult['success']) {
        echo json_encode(['success' => false, 'message' => $sendResult['message']]);
        exit;
    }

    $maskedEmail = preg_replace('/^(.).*(@.*)$/', '$1***$2', $user['Email']);

    echo json_encode([
        'success' => true,
        'message' => 'A verification code was sent to ' . $maskedEmail . '.',
        'email'   => $maskedEmail,
    ]);
    exit;
}

// FORGOT-VERIFY-OTP - confirms the emailed code for a self-service reset

if ($resource === 'forgot-verify-otp') {
    session_start();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config/db.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $otp  = trim((string) ($data['otp'] ?? ''));

    if ($otp === '') {
        echo json_encode(['success' => false, 'message' => 'Please enter the verification code.']);
        exit;
    }

    $pending = $_SESSION['pw_reset'] ?? null;

    if (!$pending) {
        echo json_encode(['success' => false, 'message' => 'No pending password reset. Please start again.']);
        exit;
    }

    if (time() > $pending['expires_at']) {
        unset($_SESSION['pw_reset']);
        echo json_encode(['success' => false, 'message' => 'This code has expired. Please request a new one.']);
        exit;
    }

    if ($pending['attempts'] >= 5) {
        unset($_SESSION['pw_reset']);
        echo json_encode(['success' => false, 'message' => 'Too many incorrect attempts. Please request a new one.']);
        exit;
    }

    if (!hash_equals((string) $pending['otp'], $otp)) {
        $_SESSION['pw_reset']['attempts'] = $pending['attempts'] + 1;
        $remaining = 5 - $_SESSION['pw_reset']['attempts'];
        echo json_encode(['success' => false, 'message' => "Incorrect code. {$remaining} attempt(s) remaining."]);
        exit;
    }

    $_SESSION['pw_reset']['verified']    = true;
    $_SESSION['pw_reset']['reset_token'] = bin2hex(random_bytes(32));

    echo json_encode(['success' => true, 'message' => 'Code verified. You may now set a new password.']);
    exit;
}

// FORGOT-RESET-PASSWORD - sets the new password once the OTP has been verified

if ($resource === 'forgot-reset-password') {
    session_start();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config/db.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $pending = $_SESSION['pw_reset'] ?? null;

    if (!$pending || empty($pending['verified'])) {
        echo json_encode(['success' => false, 'message' => 'Please verify your code before setting a new password.']);
        exit;
    }

    $data     = json_decode(file_get_contents('php://input'), true) ?? [];
    $password = (string) ($data['password'] ?? '');
    $confirm  = (string) ($data['confirm'] ?? '');

    if (strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters long.']);
        exit;
    }

    if ($password !== $confirm) {
        echo json_encode(['success' => false, 'message' => 'Passwords do not match.']);
        exit;
    }

    try {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET Password = ? WHERE UserID = ?');
        $stmt->execute([$hashed, $pending['user_id']]);
    } catch (PDOException $e) {
        http_response_code(500);
        error_log('Failed to update password: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to update password. Please try again.']);
        exit;
    }

    // OTP is now used up and can never be replayed.
    unset($_SESSION['pw_reset']);

    echo json_encode(['success' => true, 'message' => 'Password updated. You can now sign in.']);
    exit;
}

// SESSION-RENEW - heartbeat to keep session alive
if ($resource === 'session-renew') {
    session_start();
    header('Content-Type: application/json');
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }
    
    // Check if user is logged in
    if (empty($_SESSION['user'])) {
        echo json_encode(['success' => false, 'message' => 'Not logged in.']);
        exit;
    }
    
    // Update last activity time
    $_SESSION['last_activity'] = time();
    
    echo json_encode(['success' => true, 'message' => 'Session renewed.']);
    exit;
}

// FORGOT-RESEND-OTP - re-sends a fresh code for a self-service reset, throttled to once/30s

if ($resource === 'forgot-resend-otp') {
    session_start();
    header('Content-Type: application/json');
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../includes/otp_mailer.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $pending = $_SESSION['pw_reset'] ?? null;

    if (!$pending) {
        echo json_encode(['success' => false, 'message' => 'No pending password reset. Please start again.']);
        exit;
    }

    if (time() - $pending['last_sent'] < 30) {
        $wait = 30 - (time() - $pending['last_sent']);
        echo json_encode(['success' => false, 'message' => "Please wait {$wait}s before requesting another code."]);
        exit;
    }

    $stmt = $pdo->prepare('SELECT FullName, Username FROM users WHERE UserID = ? LIMIT 1');
    $stmt->execute([$pending['user_id']]);
    $user = $stmt->fetch();

    $otp = generate_otp_code(6);

    $_SESSION['pw_reset']['otp']        = $otp;
    $_SESSION['pw_reset']['expires_at'] = time() + 600;
    $_SESSION['pw_reset']['attempts']   = 0;
    $_SESSION['pw_reset']['last_sent']  = time();
    $_SESSION['pw_reset']['verified']   = false;

    session_write_close();

    $sendResult = send_otp_email($pending['email'], $user['FullName'] ?? ($user['Username'] ?? ''), $otp);

    if (!$sendResult['success']) {
        echo json_encode(['success' => false, 'message' => $sendResult['message']]);
        exit;
    }

    $maskedEmail = preg_replace('/^(.).*(@.*)$/', '$1***$2', $pending['email']);
    echo json_encode(['success' => true, 'message' => 'A new code was sent to ' . $maskedEmail . '.']);
    exit;
}

// PASSWORD-RESET - public submission + Admin review/resolve

if ($resource === 'password-reset') {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../config/db.php';
    header('Content-Type: application/json');

    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            // Only Admin can view all reset requests
            if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'Admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }

            // Password-reset tickets are stored as notifications rows
            // (Type = 'password_reset'), one per request, keyed to the
            // requesting user via UserID. Shape the result to look like the
            // old passwordresetrequests rows so existing consumers keep working.
            $sql = "SELECT n.NotificationID AS RequestID,
                           u.Username,
                           n.Message AS Note,
                           n.Status,
                           n.CreatedAt,
                           n.ResolvedAt
                    FROM notifications n
                    JOIN users u ON u.UserID = n.UserID
                    WHERE n.Type = 'password_reset'
                    ORDER BY n.CreatedAt DESC";
            echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
            break;

        case 'POST':
            // Public endpoint - no auth required for submitting password reset request
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $username = trim($body['username'] ?? '');
            $note = trim($body['note'] ?? '');

            if (!$username) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Username is required.']);
                exit;
            }

            // Check if user exists
            $userStmt = $pdo->prepare('SELECT UserID, FullName FROM users WHERE Username = ?');
            $userStmt->execute([$username]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Username not found.']);
                exit;
            }

            try {
                // The ticket itself: one notifications row tied to the
                // requesting user (Type = 'password_reset', Status = 'Pending').
                // This replaces the old dedicated passwordresetrequests table
                // and, unlike it, references the user by UserID instead of a
                // raw, unvalidated username string.
                $stmt = $pdo->prepare(
                    "INSERT INTO notifications (UserID, Type, Message, Link, Status) VALUES (?, 'password_reset', ?, '#settings', 'Pending')"
                );
                $stmt->execute([$user['UserID'], $note]);
                $requestId = $pdo->lastInsertId();

                // Notify all Admins (separate alert rows so each admin's own
                // notification bell picks it up, distinct from the ticket above).
                $admins = $pdo->query("SELECT UserID FROM users WHERE Role = 'Admin' AND Status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($admins as $adminId) {
                    $notifStmt = $pdo->prepare(
                        'INSERT INTO notifications (UserID, Type, Message, Link) VALUES (?, ?, ?, ?)'
                    );
                    $notifStmt->execute([
                        $adminId,
                        'password_reset_alert',
                        "Password reset request from {$username}",
                        '#settings'
                    ]);
                }

                echo json_encode(['success' => true, 'id' => $requestId, 'message' => 'Password reset request submitted. An admin will contact you shortly.']);
            } catch (PDOException $e) {
                http_response_code(500);
                error_log('Failed to submit request: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to submit request. Please try again.']);
            }
            break;

        case 'PUT':
            // Admin only - mark as resolved
            if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'Admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }

            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing request id.']);
                exit;
            }

            $action = $_GET['action'] ?? '';
            if ($action === 'resolve') {
                $stmt = $pdo->prepare("UPDATE notifications SET Status = 'Resolved', ResolvedAt = NOW() WHERE NotificationID = ? AND Type = 'password_reset'");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Request marked as resolved.']);
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action. Use ?action=resolve']);
            }
            break;

        case 'DELETE':
            // Admin only
            if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'Admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }

            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing request id.']);
                exit;
            }

            try {
                $stmt = $pdo->prepare("DELETE FROM notifications WHERE NotificationID = ? AND Type = 'password_reset'");
                $stmt->execute([$id]);
                echo json_encode(['success' => true, 'message' => 'Request deleted.']);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Failed to delete request.']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    }
    exit;
}

// Unknown resource

header('Content-Type: application/json');
http_response_code(400);
echo json_encode([
    'success' => false,
    'message' => 'Unknown resource. Use ?resource=login|verify-otp|resend-otp|cancel-otp|logout|password-reset|forgot-password|forgot-verify-otp|forgot-reset-password|forgot-resend-otp',
]);
