<?php

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
header('Content-Type: application/json');

if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Require CSRF token for state-changing requests, but skip for mark_read and delete
// since these are safe operations that only change notification status
$requireCsrf = true;
if ($action === 'mark_read' || $action === 'mark_all_read' || ($method === 'DELETE' && isset($_GET['id']))) {
    $requireCsrf = false;
}

if ($requireCsrf && in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    require_csrf_token();
}

switch ($method) {
    case 'GET':
        $scope = $_GET['scope'] ?? 'own';
        $currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
        
        if ($scope === 'all' && $_SESSION['user']['role'] === 'Admin') {
            // Admin can see all notifications
            $sql = "SELECT n.*, u.FullName AS UserFullName
                    FROM notifications n
                    LEFT JOIN users u ON u.UserID = n.UserID
                    ORDER BY n.CreatedAt DESC
                    LIMIT 50";
            echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
        } else {
            // Regular users see their own notifications AND broadcast notifications (UserID IS NULL)
            $sql = "SELECT n.*, u.FullName AS UserFullName
                    FROM notifications n
                    LEFT JOIN users u ON u.UserID = n.UserID
                    WHERE n.UserID = ? OR n.UserID IS NULL
                    ORDER BY n.CreatedAt DESC
                    LIMIT 50";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$currentUserId]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        }
        break;

    case 'POST':
        if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Not authorized.']);
            exit;
        }
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $userId = (int) ($body['user_id'] ?? 0) ?: null;
        if ($_SESSION['user']['role'] === 'Receptionist') {
            $userId = (int) $_SESSION['user']['id'];
        }
        $type = trim($body['type'] ?? 'system');
        $message = trim($body['message'] ?? '');
        $link = trim($body['link'] ?? '#');

        if (!$message) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Message is required.']);
            exit;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO notifications (UserID, Type, Message, Link) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $type, $message, $link]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Notification created.']);
        break;

    case 'PUT':
        $currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
        
        if ($action === 'mark_read') {
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing notification id.']);
                exit;
            }
            // Check if notification exists
            $checkStmt = $pdo->prepare('SELECT UserID FROM notifications WHERE NotificationID = ?');
            $checkStmt->execute([$id]);
            $notif = $checkStmt->fetch();
            
            if (!$notif) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Notification not found.']);
                exit;
            }
            
            // Allow users to mark their own notifications as read
            // Admin can mark any notification as read
            if ($_SESSION['user']['role'] !== 'Admin') {
                // For regular users, only mark personal notifications
                if ($notif['UserID'] === null) {
                    // Broadcast notifications - cannot be marked as read
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Cannot mark broadcast notifications as read.']);
                    exit;
                }
                $stmt = $pdo->prepare('UPDATE notifications SET IsRead = 1 WHERE NotificationID = ? AND UserID = ?');
                $stmt->execute([$id, $currentUserId]);
                if ($stmt->rowCount() === 0) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Not authorized to mark this notification.']);
                    exit;
                }
            } else {
                // Admin can mark any notification as read
                $stmt = $pdo->prepare('UPDATE notifications SET IsRead = 1 WHERE NotificationID = ?');
                $stmt->execute([$id]);
            }
            echo json_encode(['success' => true, 'message' => 'Marked as read.']);
        } elseif ($action === 'mark_all_read') {
            // Mark current user's personal notifications as read
            $stmt = $pdo->prepare('UPDATE notifications SET IsRead = 1 WHERE UserID = ? AND IsRead = 0');
            $stmt->execute([$currentUserId]);
            echo json_encode(['success' => true, 'message' => 'All marked as read.']);
        } else {
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing notification id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $userId = (int) ($body['user_id'] ?? 0) ?: null;
            $type = trim($body['type'] ?? 'system');
            $message = trim($body['message'] ?? '');
            $link = trim($body['link'] ?? '#');
            if ($_SESSION['user']['role'] === 'Admin') {
                $stmt = $pdo->prepare('UPDATE notifications SET UserID=?, Type=?, Message=?, Link=? WHERE NotificationID=?');
                $stmt->execute([$userId, $type, $message, $link, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE notifications SET Type=?, Message=?, Link=? WHERE NotificationID=? AND UserID=?');
                $stmt->execute([$type, $message, $link, $id, $currentUserId]);
                if ($stmt->rowCount() === 0) {
                    $ownershipCheck = $pdo->prepare('SELECT 1 FROM notifications WHERE NotificationID = ? AND UserID = ?');
                    $ownershipCheck->execute([$id, $currentUserId]);
                    if (!$ownershipCheck->fetchColumn()) {
                        http_response_code(403);
                        echo json_encode(['success' => false, 'message' => 'Not authorized to edit this notification.']);
                        exit;
                    }
                }
            }
            echo json_encode(['success' => true, 'message' => 'Notification updated.']);
        }
        break;

    case 'DELETE':
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Missing notification id.']);
            exit;
        }
        $currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
        
        // Check if notification exists
        $checkStmt = $pdo->prepare('SELECT UserID FROM notifications WHERE NotificationID = ?');
        $checkStmt->execute([$id]);
        $notif = $checkStmt->fetch();
        
        if (!$notif) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Notification not found.']);
            exit;
        }
        
        // Allow Admin to delete any notification
        if ($_SESSION['user']['role'] === 'Admin') {
            $stmt = $pdo->prepare('DELETE FROM notifications WHERE NotificationID = ?');
            $stmt->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Notification deleted.']);
        } else {
            // Users can only delete their own personal notifications (not broadcast)
            if ($notif['UserID'] === null) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Cannot delete broadcast notifications.']);
                exit;
            }
            $stmt = $pdo->prepare('DELETE FROM notifications WHERE NotificationID = ? AND UserID = ?');
            $stmt->execute([$id, $currentUserId]);
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Notification deleted.']);
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized to delete this notification.']);
            }
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
}
