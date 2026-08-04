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

$method = $_SERVER['REQUEST_METHOD'];

// Require CSRF token for state-changing requests
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    require_csrf_token();
}

switch ($method) {
    case 'GET':
        // Only Admin can view all messages
        if (empty($_SESSION['user']) || $_SESSION['user']['role'] !== 'Admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not authorized.']);
            exit;
        }
        
        $sql = "SELECT * FROM contactmessages ORDER BY CreatedAt DESC";
        echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
        break;

    case 'POST':
        // Public endpoint - no auth required for submitting contact form
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $fullName = trim($body['full_name'] ?? '');
        $email = trim($body['email'] ?? '');
        $phone = trim($body['phone'] ?? '');
        $subject = trim($body['subject'] ?? '');
        $message = trim($body['message'] ?? '');

        $errors = [];
        if ($error = validate_name_field($fullName)) {
            $errors[] = $error;
        }
        if ($error = validate_email_field($email)) {
            $errors[] = $error;
        }
        if ($phone && $error = validate_phone_field($phone, false)) {
            $errors[] = $error;
        }
        if ($error = validate_text_field($message, 'Message', 1, 5000)) {
            $errors[] = $error;
        }
        if ($subject && $error = validate_text_field($subject, 'Subject', null, 150, false)) {
            $errors[] = $error;
        }

        if (!empty($errors)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
            exit;
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO contactmessages (FullName, Email, Phone, Subject, Message) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$fullName, $email, $phone, $subject, $message]);
            $messageId = $pdo->lastInsertId();

            // Notify all Admins
            $admins = $pdo->query("SELECT UserID FROM users WHERE Role = 'Admin' AND Status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($admins as $adminId) {
                $notifStmt = $pdo->prepare(
                    'INSERT INTO notifications (UserID, Type, Message, Link) VALUES (?, ?, ?, ?)'
                );
                $notifStmt->execute([
                    $adminId,
                    'contact',
                    "New contact message from {$fullName}",
                    '#messages'
                ]);
            }

            echo json_encode(['success' => true, 'id' => $messageId, 'message' => 'Message sent successfully.']);
        } catch (PDOException $e) {
            http_response_code(500);
            error_log('Failed to send message: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Failed to send message. Please try again.']);
        }
        break;

    case 'PUT':
        // Admin only - mark as read
        if ($_SESSION['user']['role'] !== 'Admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not authorized.']);
            exit;
        }

        $action = $_GET['action'] ?? '';
        if ($action === 'mark_read') {
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing message id.']);
                exit;
            }
            $stmt = $pdo->prepare('UPDATE contactmessages SET IsRead = 1 WHERE MessageID = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Message marked as read.']);
        } elseif ($action === 'mark_all_read') {
            $stmt = $pdo->prepare('UPDATE contactmessages SET IsRead = 1');
            $stmt->execute();
            echo json_encode(['success' => true, 'message' => 'All messages marked as read.']);
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action. Use ?action=mark_read or ?action=mark_all_read']);
        }
        break;

    case 'DELETE':
        // Admin only
        if ($_SESSION['user']['role'] !== 'Admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not authorized.']);
            exit;
        }

        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Missing message id.']);
            exit;
        }

        try {
            $stmt = $pdo->prepare('DELETE FROM contactmessages WHERE MessageID = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Message deleted.']);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to delete message.']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
}
