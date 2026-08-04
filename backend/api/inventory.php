<?php

// api/inventory.php - stock & procurement endpoints

// Pick a resource with ?resource=
//   categories         (GET/POST/PUT/DELETE)
//   suppliers          (GET/POST/PUT/DELETE)
//   spareparts         (GET/POST/PUT/DELETE, plus POST ?action=adjust)
//   sparepartrequests  (GET/POST/PUT/DELETE, PUT ?action=approve|reject)
//   purchases          (GET/POST/PUT/DELETE, PUT ?action=approve)

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/validation.php';
header('Content-Type: application/json');

if (empty($_SESSION['user'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

$resource = $_GET['resource'] ?? '';
$method   = $_SERVER['REQUEST_METHOD'];

// Require CSRF token for state-changing requests
if (in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'])) {
    require_csrf_token();
}

// Shared Approve/Reject logic for sparepartrequests, used by both the
// PUT ?action=approve|reject form and the equivalent POST form.

function handle_sparepartrequest_approve(PDO $pdo, int $id, int $decidedByUserId): void
{
    // Row-lock the request so two simultaneous approvals can't both pass the
    // "Pending" check (guards against double-approving the same request).
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM sparepartrequests WHERE RequestID = ? AND Status = "Pending" FOR UPDATE');
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Request not found or already processed.']);
            return;
        }

        // Check stock availability (guards against approving more than QuantityAvailable)
        $stockStmt = $pdo->prepare('SELECT Quantity FROM spareparts WHERE SparePartID = ? FOR UPDATE');
        $stockStmt->execute([$request['SparePartID']]);
        $stock = $stockStmt->fetch(PDO::FETCH_ASSOC);

        if (!$stock || $stock['Quantity'] < $request['QuantityRequested']) {
            $pdo->rollBack();
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Insufficient stock. Current: ' . ($stock['Quantity'] ?? 0) . ', Requested: ' . $request['QuantityRequested']]);
            return;
        }

        // Get current stock before transaction
        $beforeQty = $stock['Quantity'];

        // Deduct stock
        $pdo->prepare('UPDATE spareparts SET Quantity = Quantity - ? WHERE SparePartID = ?')
            ->execute([$request['QuantityRequested'], $request['SparePartID']]);

        // Get new stock after transaction
        $afterQty = $beforeQty - $request['QuantityRequested'];

        // Log stock transaction (Stock Out) with accurate before/after quantities
        $pdo->prepare(
            'INSERT INTO stocktransactions (SparePartID, TransactionType, Quantity, TransactionDate, BeforeQty, AfterQty, UserID) VALUES (?, ?, ?, CURDATE(), ?, ?, ?)'
        )->execute([$request['SparePartID'], 'Usage', $request['QuantityRequested'], $beforeQty, $afterQty, $decidedByUserId]);

        // Update request status
        $pdo->prepare('UPDATE sparepartrequests SET Status = "Fulfilled", DecidedAt = CURDATE(), DecidedByUserID = ? WHERE RequestID = ?')
            ->execute([$decidedByUserId, $id]);

        // Notify the requesting mechanic
        $notifStmt = $pdo->prepare(
            'INSERT INTO notifications (UserID, Type, Message, Link) 
             SELECT u.UserID, "part_request", ?, ? FROM users u WHERE u.MechanicID = ?'
        );
        $notifStmt->execute([
            "Your spare part request #{$id} has been approved and fulfilled.",
            '#requests',
            $request['MechanicID']
        ]);

        $pdo->commit();
        write_audit_log(
            $pdo, $decidedByUserId, $_SESSION['user']['role'] ?? '', 'approve', 'sparepartrequest', $id,
            'Pending', 'Fulfilled', "Deducted {$request['QuantityRequested']} of SparePartID {$request['SparePartID']}"
        );
        echo json_encode(['success' => true, 'message' => 'Request approved and stock deducted.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        error_log('Failed to approve request: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to approve request. Please try again.']);
    }
}

function handle_sparepartrequest_reject(PDO $pdo, int $id, int $decidedByUserId, string $reason): void
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM sparepartrequests WHERE RequestID = ? AND Status = "Pending" FOR UPDATE');
        $stmt->execute([$id]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Request not found or already processed.']);
            return;
        }

        $pdo->prepare('UPDATE sparepartrequests SET Status = "Rejected", Reason = ?, DecidedAt = CURDATE(), DecidedByUserID = ? WHERE RequestID = ?')
            ->execute([$reason, $decidedByUserId, $id]);

        // Notify the requesting mechanic
        $notifStmt = $pdo->prepare(
            'INSERT INTO notifications (UserID, Type, Message, Link) 
             SELECT u.UserID, "part_request", ?, ? FROM users u WHERE u.MechanicID = ?'
        );
        $notifStmt->execute([
            "Your spare part request #{$id} has been rejected. Reason: {$reason}",
            '#requests',
            $request['MechanicID']
        ]);

        $pdo->commit();
        write_audit_log(
            $pdo, $decidedByUserId, $_SESSION['user']['role'] ?? '', 'reject', 'sparepartrequest', $id,
            'Pending', 'Rejected', $reason
        );
        echo json_encode(['success' => true, 'message' => 'Request rejected.']);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        http_response_code(500);
        error_log('Failed to reject request: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Failed to reject request. Please try again.']);
    }
}


// CATEGORIES

if ($resource === 'categories') {
    // Helper: fetch a single category row so the UI can patch its table
    // without a full page reload.
    $fetchCategory = function (int $id) use ($pdo) {
        $s = $pdo->prepare('SELECT * FROM categories WHERE CategoryID = ?');
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    switch ($method) {
        case 'GET':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id) {
                $row = $fetchCategory($id);
                if (!$row) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Category not found.']);
                    exit;
                }
                echo json_encode(['success' => true, 'data' => $row]);
                break;
            }
            // PartCount lets the Categories table show live usage figures.
            echo json_encode([
                'success' => true,
                'data' => $pdo->query(
                    'SELECT c.*, COUNT(sp.SparePartID) AS PartCount
                     FROM categories c
                     LEFT JOIN spareparts sp ON sp.CategoryID = c.CategoryID
                     GROUP BY c.CategoryID, c.CategoryName, c.Description
                     ORDER BY c.CategoryID'
                )->fetchAll()
            ]);
            break;

        case 'POST':
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Stock Manager'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $name = trim($body['category_name'] ?? '');
            $description = trim($body['description'] ?? '');
            if ($error = validate_text_field($name, 'Category name', 1, 100)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
            if ($error = validate_text_field($description, 'Description', null, 500, false)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
            // Duplicate guard (case-insensitive)
            $dup = $pdo->prepare('SELECT CategoryID FROM categories WHERE LOWER(CategoryName) = LOWER(?) LIMIT 1');
            $dup->execute([$name]);
            if ($dup->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'A category named "' . $name . '" already exists.']);
                exit;
            }
            $stmt = $pdo->prepare('INSERT INTO categories (CategoryName, Description) VALUES (?, ?)');
            $stmt->execute([$name, $description]);
            $newId = (int) $pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'id'      => $newId,
                'data'    => $fetchCategory($newId),
                'message' => 'Category created.'
            ]);
            break;

        case 'PUT':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing category id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Stock Manager'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            if (!$fetchCategory($id)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Category not found.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $name = trim($body['category_name'] ?? '');
            $description = trim($body['description'] ?? '');
            if ($error = validate_text_field($name, 'Category name', 1, 100)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
            if ($error = validate_text_field($description, 'Description', null, 500, false)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
            $dup = $pdo->prepare('SELECT CategoryID FROM categories WHERE LOWER(CategoryName) = LOWER(?) AND CategoryID <> ? LIMIT 1');
            $dup->execute([$name, $id]);
            if ($dup->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Another category is already named "' . $name . '".']);
                exit;
            }
            $stmt = $pdo->prepare('UPDATE categories SET CategoryName=?, Description=? WHERE CategoryID=?');
            $stmt->execute([$name, $description, $id]);
            echo json_encode([
                'success' => true,
                'id'      => $id,
                'data'    => $fetchCategory($id),
                'message' => 'Category updated.'
            ]);
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing category id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Stock Manager'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            if (!$fetchCategory($id)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Category not found or already deleted.']);
                exit;
            }
            // Pre-flight check gives a friendlier message than an FK error.
            $inUse = $pdo->prepare('SELECT COUNT(*) FROM spareparts WHERE CategoryID = ?');
            $inUse->execute([$id]);
            $count = (int) $inUse->fetchColumn();
            if ($count > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'Cannot delete: ' . $count . ' spare part' . ($count === 1 ? ' is' : 's are') . ' still linked to this category.'
                ]);
                exit;
            }
            try {
                $stmt = $pdo->prepare('DELETE FROM categories WHERE CategoryID = ?');
                $stmt->execute([$id]);
                reindex_table_ids($pdo, 'categories', 'CategoryID');
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Category deleted.']);
            } catch (PDOException $e) {
                http_response_code(409);
                if ((int) $e->errorInfo[1] === 1451) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: this category has linked spare parts. Remove those first.']);
                } else {
                    error_log('Category delete failed: ' . $e->getMessage());
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


// SUPPLIERS

if ($resource === 'suppliers') {
    $fetchSupplier = function (int $id) use ($pdo) {
        $s = $pdo->prepare(
            'SELECT s.*,
                    COUNT(DISTINCT sp.SparePartID) AS PartCount,
                    COUNT(DISTINCT p.PurchaseID) AS PurchaseCount
             FROM suppliers s
             LEFT JOIN spareparts sp ON sp.SupplierID = s.SupplierID
             LEFT JOIN purchases p ON p.SupplierID = s.SupplierID
             WHERE s.SupplierID = ?
             GROUP BY s.SupplierID, s.CompanyName, s.Phone, s.Email, s.Address'
        );
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    /** Shared validation for supplier create/update. Returns [clean, errorMessage]. */
    $validateSupplier = function (array $body) {
        $name    = trim($body['company_name'] ?? '');
        $phone   = trim($body['phone'] ?? '');
        $email   = trim($body['email'] ?? '');
        $address = trim($body['address'] ?? '');

        if ($error = validate_text_field($name, 'Company name', 1, 150)) {
            return [null, $error];
        }
        if ($email !== '' && ($error = validate_email_field($email, false))) {
            return [null, $error];
        }
        if ($phone !== '' && ($error = validate_phone_field($phone, false))) {
            return [null, $error];
        }
        if ($error = validate_text_field($address, 'Address', null, 300, false)) {
            return [null, $error];
        }
        return [compact('name', 'phone', 'email', 'address'), null];
    };

    switch ($method) {
        case 'GET':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id) {
                $row = $fetchSupplier($id);
                if (!$row) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
                    exit;
                }
                echo json_encode(['success' => true, 'data' => $row]);
                break;
            }
            echo json_encode([
                'success' => true,
                'data' => $pdo->query(
                    'SELECT s.*,
                            COUNT(DISTINCT sp.SparePartID) AS PartCount,
                            COUNT(DISTINCT p.PurchaseID) AS PurchaseCount
                     FROM suppliers s
                     LEFT JOIN spareparts sp ON sp.SupplierID = s.SupplierID
                     LEFT JOIN purchases p ON p.SupplierID = s.SupplierID
                     GROUP BY s.SupplierID, s.CompanyName, s.Phone, s.Email, s.Address
                     ORDER BY s.SupplierID'
                )->fetchAll()
            ]);
            break;

        case 'POST':
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Stock Manager'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            [$clean, $error] = $validateSupplier($body);
            if ($error) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
            $dup = $pdo->prepare('SELECT SupplierID FROM suppliers WHERE LOWER(CompanyName) = LOWER(?) LIMIT 1');
            $dup->execute([$clean['name']]);
            if ($dup->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'A supplier named "' . $clean['name'] . '" already exists.']);
                exit;
            }
            $stmt = $pdo->prepare('INSERT INTO suppliers (CompanyName, Phone, Email, Address) VALUES (?, ?, ?, ?)');
            $stmt->execute([$clean['name'], $clean['phone'], $clean['email'], $clean['address']]);
            $newId = (int) $pdo->lastInsertId();
            echo json_encode([
                'success' => true,
                'id'      => $newId,
                'data'    => $fetchSupplier($newId),
                'message' => 'Supplier created.'
            ]);
            break;

        case 'PUT':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing supplier id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Stock Manager'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            if (!$fetchSupplier($id)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            [$clean, $error] = $validateSupplier($body);
            if ($error) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
            $dup = $pdo->prepare('SELECT SupplierID FROM suppliers WHERE LOWER(CompanyName) = LOWER(?) AND SupplierID <> ? LIMIT 1');
            $dup->execute([$clean['name'], $id]);
            if ($dup->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Another supplier is already named "' . $clean['name'] . '".']);
                exit;
            }
            $stmt = $pdo->prepare('UPDATE suppliers SET CompanyName=?, Phone=?, Email=?, Address=? WHERE SupplierID=?');
            $stmt->execute([$clean['name'], $clean['phone'], $clean['email'], $clean['address'], $id]);
            echo json_encode([
                'success' => true,
                'id'      => $id,
                'data'    => $fetchSupplier($id),
                'message' => 'Supplier updated.'
            ]);
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing supplier id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Stock Manager'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $existing = $fetchSupplier($id);
            if (!$existing) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Supplier not found or already deleted.']);
                exit;
            }
            $linked = (int) $existing['PartCount'] + (int) $existing['PurchaseCount'];
            if ($linked > 0) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'Cannot delete: this supplier still has ' . (int) $existing['PartCount'] .
                                 ' spare part(s) and ' . (int) $existing['PurchaseCount'] . ' purchase(s) linked to it.'
                ]);
                exit;
            }
            try {
                $stmt = $pdo->prepare('DELETE FROM suppliers WHERE SupplierID = ?');
                $stmt->execute([$id]);
                reindex_table_ids($pdo, 'suppliers', 'SupplierID');
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Supplier deleted.']);
            } catch (PDOException $e) {
                http_response_code(409);
                if ((int) $e->errorInfo[1] === 1451) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: this supplier has linked spare parts or purchases. Remove those first.']);
                } else {
                    error_log('Supplier delete failed: ' . $e->getMessage());
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


// SPARE PARTS

if ($resource === 'spareparts') {
    // Any logged-in staff member may view/manage stock (Admin or Stock Manager)
    if (!in_array($_SESSION['user']['role'], ['Admin', 'Stock Manager'], true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Not authorized.']);
        exit;
    }

    $action = $_GET['action'] ?? '';

    // Returns one spare part joined with its category/supplier names, in the
    // exact shape the Spare Parts table renders, so the UI can insert/replace a
    // single row after a save instead of reloading the page.
    $fetchPart = function (int $id) use ($pdo) {
        $s = $pdo->prepare(
            "SELECT sp.SparePartID, sp.PartName, sp.UnitPrice, sp.Quantity, sp.ReorderLevel,
                    sp.CategoryID, sp.SupplierID,
                    c.CategoryName, s.CompanyName AS SupplierName
             FROM spareparts sp
             LEFT JOIN categories c ON c.CategoryID = sp.CategoryID
             LEFT JOIN suppliers  s ON s.SupplierID = sp.SupplierID
             WHERE sp.SparePartID = ?"
        );
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['LowStock']          = (int) $row['Quantity'] <= (int) ($row['ReorderLevel'] ?? 10);
        $row['QuantityAvailable'] = (int) $row['Quantity'];
        $row['OutOfStock']        = ((int) $row['Quantity']) <= 0;
        return $row;
    };


    // STOCK ADJUSTMENT
    if ($method === 'POST' && $action === 'adjust') {
        $body        = json_decode(file_get_contents('php://input'), true) ?? [];
        $sparePartId = (int) ($body['spare_part_id'] ?? 0);
        $qty         = (int) ($body['quantity'] ?? 0);
        $type        = $body['type'] ?? 'Adjustment'; // Purchase | Usage | Adjustment
        // Bug fix: previously a "Adjustment" always increased stock (delta was
        // always positive unless type === 'Usage'), so a manual "Subtract"
        // adjustment silently added stock instead of removing it. direction
        // lets the caller say which way a Purchase/Adjustment should go;
        // Usage is always a decrease regardless of direction.
        $direction   = $body['direction'] ?? 'add'; // add | subtract

        if (!$sparePartId) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'spare_part_id is required.']);
            exit;
        }

        if (!$qty) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'quantity is required and must be non-zero.']);
            exit;
        }

        if (!in_array($type, ['Purchase', 'Usage', 'Adjustment'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'type must be Purchase, Usage, or Adjustment.']);
            exit;
        }

        if (!in_array($direction, ['add', 'subtract'], true)) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'direction must be add or subtract.']);
            exit;
        }

        // Validate spare part exists
        $partCheck = $pdo->prepare('SELECT SparePartID, Quantity FROM spareparts WHERE SparePartID = ?');
        $partCheck->execute([$sparePartId]);
        $part = $partCheck->fetch();
        if (!$part) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Spare part not found.']);
            exit;
        }

        $isDecrease = ($type === 'Usage') || ($type === 'Adjustment' && $direction === 'subtract');

        // Check if enough stock for a decrease
        if ($isDecrease && $part['Quantity'] < $qty) {
            http_response_code(422);
            echo json_encode(['success' => false, 'message' => 'Insufficient stock. Available: ' . $part['Quantity']]);
            exit;
        }

        $delta = $isDecrease ? -abs($qty) : abs($qty);

        // Get current stock before transaction
        $beforeQty = $part['Quantity'];
        $afterQty = $beforeQty + $delta;

        // Wrap in a transaction so the quantity update + log row succeed/fail together
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE spareparts SET Quantity = Quantity + ? WHERE SparePartID = ?')
                ->execute([$delta, $sparePartId]);

            $pdo->prepare(
                'INSERT INTO stocktransactions (SparePartID, TransactionType, Quantity, TransactionDate, BeforeQty, AfterQty, UserID) VALUES (?, ?, ?, CURDATE(), ?, ?, ?)'
            )->execute([$sparePartId, $type, abs($qty), $beforeQty, $afterQty, $_SESSION['user']['id']]);

            $pdo->commit();
            echo json_encode(['success' => true, 'data' => $fetchPart($sparePartId), 'message' => 'Stock updated.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            http_response_code(500);
            error_log('Stock update failed: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Stock update failed. Please try again.']);
        }
        exit;
    }

    switch ($method) {

        // LIST
        case 'GET':
            $sql = "SELECT sp.SparePartID, sp.PartName, sp.UnitPrice, sp.Quantity,
                           sp.ReorderLevel,
                           c.CategoryName, s.CompanyName AS SupplierName,
                           sp.CategoryID, sp.SupplierID
                    FROM spareparts sp
                    LEFT JOIN categories c ON c.CategoryID = sp.CategoryID
                    LEFT JOIN suppliers  s ON s.SupplierID = sp.SupplierID
                    ORDER BY sp.SparePartID";
            $parts = $pdo->query($sql)->fetchAll();

            // ReorderLevel now lives directly on spareparts (was a separate
            // `inventory` table before the schema consolidation).
            foreach ($parts as &$p) {
                $reorder                 = $p['ReorderLevel'] ?? 10;
                $p['LowStock']           = $p['Quantity'] <= $reorder;
                // Convenience fields for dropdowns/cards so the frontend
                // doesn't have to re-derive availability from Quantity.
                $p['QuantityAvailable']  = (int) $p['Quantity'];
                $p['OutOfStock']         = ((int) $p['Quantity']) <= 0;
            }
            echo json_encode(['success' => true, 'data' => $parts]);
            break;

        //  CREATE
        case 'POST':
            $body       = json_decode(file_get_contents('php://input'), true) ?? [];
            $partName   = trim($body['part_name'] ?? '');
            $categoryId = (int) ($body['category_id'] ?? 0) ?: null;
            $supplierId = (int) ($body['supplier_id'] ?? 0) ?: null;
            $unitPrice  = (float) ($body['unit_price'] ?? 0);
            $quantity   = (int) ($body['quantity'] ?? 0);

            if ($error = validate_text_field($partName, 'Part name', 1, 100)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            if ($error = validate_non_negative_numeric_field($unitPrice, 'Unit price')) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            if ($error = validate_non_negative_numeric_field($quantity, 'Quantity')) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            // Validate category exists if provided
            if ($categoryId) {
                $categoryCheck = $pdo->prepare('SELECT CategoryID FROM categories WHERE CategoryID = ?');
                $categoryCheck->execute([$categoryId]);
                if (!$categoryCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Category not found.']);
                    exit;
                }
            }

            // Validate supplier exists if provided
            if ($supplierId) {
                $supplierCheck = $pdo->prepare('SELECT SupplierID FROM suppliers WHERE SupplierID = ?');
                $supplierCheck->execute([$supplierId]);
                if (!$supplierCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
                    exit;
                }
            }

            $reorderLevel = (int) ($body['reorder_level'] ?? 10);
            if ($error = validate_non_negative_numeric_field($reorderLevel, 'Reorder level')) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            // Duplicate guard: the same part name must not be registered twice
            // for the same supplier (case-insensitive).
            $dup = $pdo->prepare(
                'SELECT SparePartID FROM spareparts
                 WHERE LOWER(PartName) = LOWER(?) AND (SupplierID <=> ?) LIMIT 1'
            );
            $dup->execute([$partName, $supplierId]);
            if ($dup->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'A spare part named "' . $partName . '" already exists for this supplier.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO spareparts (CategoryID, SupplierID, PartName, UnitPrice, Quantity, ReorderLevel)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$categoryId, $supplierId, $partName, $unitPrice, $quantity, $reorderLevel]);
                $newId = (int) $pdo->lastInsertId();

                // Log the opening stock so the Inventory/transactions view stays truthful.
                if ($quantity > 0) {
                    $pdo->prepare(
                        'INSERT INTO stocktransactions (SparePartID, TransactionType, Quantity, TransactionDate, BeforeQty, AfterQty, UserID) VALUES (?, "Adjustment", ?, CURDATE(), 0, ?, ?)'
                    )->execute([$newId, $quantity, $quantity, $_SESSION['user']['id']]);
                }
                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Spare part create failed: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Could not save the spare part. Please try again.']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'id'      => $newId,
                'data'    => $fetchPart($newId),
                'message' => 'Spare part created.'
            ]);
            break;

        //  UPDATE
        case 'PUT':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing part id.']);
                exit;
            }

            // Validate spare part exists
            $existingPart = $fetchPart($id);
            if (!$existingPart) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Spare part not found.']);
                exit;
            }

            $body       = json_decode(file_get_contents('php://input'), true) ?? [];
            $partName   = trim($body['part_name'] ?? '');
            $categoryId = (int) ($body['category_id'] ?? 0) ?: null;
            $supplierId = (int) ($body['supplier_id'] ?? 0) ?: null;
            $unitPrice  = (float) ($body['unit_price'] ?? 0);
            $reorderLevel = isset($body['reorder_level']) ? (int) $body['reorder_level'] : null;
            if ($reorderLevel !== null && $reorderLevel < 0) {
                $reorderLevel = 0;
            }
            // Quantity is optional on edit; when supplied we also log the delta
            // as a stock transaction so the inventory ledger stays consistent.
            $newQuantity = array_key_exists('quantity', $body) && $body['quantity'] !== ''
                ? (int) $body['quantity']
                : null;

            if ($error = validate_text_field($partName, 'Part name', 1, 100)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            if ($error = validate_numeric_field($unitPrice, 'Unit price', 0, null)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            if ($newQuantity !== null && !validate_integer($newQuantity, 0, null)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Quantity must be a whole number greater than or equal to 0.']);
                exit;
            }

            // Validate category exists if provided
            if ($categoryId) {
                $categoryCheck = $pdo->prepare('SELECT CategoryID FROM categories WHERE CategoryID = ?');
                $categoryCheck->execute([$categoryId]);
                if (!$categoryCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Category not found.']);
                    exit;
                }
            }

            // Validate supplier exists if provided
            if ($supplierId) {
                $supplierCheck = $pdo->prepare('SELECT SupplierID FROM suppliers WHERE SupplierID = ?');
                $supplierCheck->execute([$supplierId]);
                if (!$supplierCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Supplier not found.']);
                    exit;
                }
            }

            // Duplicate guard, ignoring the row being edited
            $dup = $pdo->prepare(
                'SELECT SparePartID FROM spareparts
                 WHERE LOWER(PartName) = LOWER(?) AND (SupplierID <=> ?) AND SparePartID <> ? LIMIT 1'
            );
            $dup->execute([$partName, $supplierId, $id]);
            if ($dup->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Another spare part named "' . $partName . '" already exists for this supplier.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                if ($reorderLevel !== null) {
                    $pdo->prepare(
                        'UPDATE spareparts SET CategoryID=?, SupplierID=?, PartName=?, UnitPrice=?, ReorderLevel=? WHERE SparePartID=?'
                    )->execute([$categoryId, $supplierId, $partName, $unitPrice, $reorderLevel, $id]);
                } else {
                    $pdo->prepare(
                        'UPDATE spareparts SET CategoryID=?, SupplierID=?, PartName=?, UnitPrice=? WHERE SparePartID=?'
                    )->execute([$categoryId, $supplierId, $partName, $unitPrice, $id]);
                }

                if ($newQuantity !== null && $newQuantity !== (int) $existingPart['Quantity']) {
                    $delta = $newQuantity - (int) $existingPart['Quantity'];
                    $beforeQty = (int) $existingPart['Quantity'];
                    $afterQty = $newQuantity;
                    $pdo->prepare('UPDATE spareparts SET Quantity = ? WHERE SparePartID = ?')
                        ->execute([$newQuantity, $id]);
                    $pdo->prepare(
                        'INSERT INTO stocktransactions (SparePartID, TransactionType, Quantity, TransactionDate, BeforeQty, AfterQty, UserID) VALUES (?, "Adjustment", ?, CURDATE(), ?, ?, ?)'
                    )->execute([$id, abs($delta), $beforeQty, $afterQty, $_SESSION['user']['id']]);
                }
                $pdo->commit();
            } catch (PDOException $e) {
                $pdo->rollBack();
                error_log('Spare part update failed: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Could not update the spare part. Please try again.']);
                exit;
            }

            echo json_encode([
                'success' => true,
                'id'      => $id,
                'data'    => $fetchPart($id),
                'message' => 'Spare part updated.'
            ]);
            break;

        //  DELETE
        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing part id.']);
                exit;
            }
            if (!$fetchPart($id)) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Spare part not found or already deleted.']);
                exit;
            }
            try {
                // Stock transactions are an audit trail of this part, so remove
                // them with the part itself; invoice lines are real financial
                // records and must block the delete instead.
                $invoiceLines = $pdo->prepare('SELECT COUNT(*) FROM invoiceitems WHERE SparePartID = ?');
                $invoiceLines->execute([$id]);
                if ((int) $invoiceLines->fetchColumn() > 0) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: this spare part appears on one or more invoices.']);
                    exit;
                }

                $openRequests = $pdo->prepare('SELECT COUNT(*) FROM sparepartrequests WHERE SparePartID = ? AND Status = "Pending"');
                $openRequests->execute([$id]);
                if ((int) $openRequests->fetchColumn() > 0) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: this spare part has pending mechanic requests.']);
                    exit;
                }

                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM stocktransactions WHERE SparePartID = ?')->execute([$id]);
                reindex_table_ids($pdo, 'stocktransactions', 'TransactionID');
                $pdo->prepare('DELETE FROM spareparts WHERE SparePartID = ?')->execute([$id]);
                reindex_table_ids($pdo, 'spareparts', 'SparePartID');
                $pdo->commit();
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Spare part deleted.']);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                http_response_code(409);
                if ((int) $e->errorInfo[1] === 1451) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: this spare part has linked records (invoices or requests). Remove those first.']);
                } else {
                    error_log('Spare part delete failed: ' . $e->getMessage());
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


// STOCK MOVEMENT HISTORY (audit trail of spareparts quantity changes)

if ($resource === 'stocktransactions') {
    switch ($method) {

        // LIST - same shape as the server-rendered Stock Movement History table,
        // used to refresh that table via AJAX (e.g. right after a request approval).
        case 'GET':
            $sql = "SELECT st.TransactionID, st.TransactionDate, sp.PartName, st.TransactionType, st.Quantity,
                           st.BeforeQty, st.AfterQty, st.UserID,
                           u.FullName AS UserName
                    FROM stocktransactions st
                    JOIN spareparts sp ON sp.SparePartID = st.SparePartID
                    LEFT JOIN users u ON u.UserID = st.UserID
                    ORDER BY st.TransactionDate DESC, st.TransactionID DESC
                    LIMIT 50";
            $rows = $pdo->query($sql)->fetchAll();
            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // DELETE - removes a single log entry from the movement history.
        // This only prunes the audit trail; it intentionally does NOT reverse
        // the stock quantity change the entry recorded.
        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing transaction id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Stock Manager'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $check = $pdo->prepare('SELECT TransactionID FROM stocktransactions WHERE TransactionID = ?');
            $check->execute([$id]);
            if (!$check->fetch()) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Stock movement record not found or already deleted.']);
                exit;
            }
            try {
                $pdo->prepare('DELETE FROM stocktransactions WHERE TransactionID = ?')->execute([$id]);
                reindex_table_ids($pdo, 'stocktransactions', 'TransactionID');
                write_audit_log(
                    $pdo, (int) ($_SESSION['user']['id'] ?? 0), $_SESSION['user']['role'] ?? '', 'delete', 'stocktransaction', $id,
                    null, null, 'Deleted stock movement record #' . $id
                );
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Stock movement record deleted.']);
            } catch (PDOException $e) {
                http_response_code(500);
                error_log('Stock transaction delete failed: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    }
    exit;
}


// SPARE PART REQUESTS

if ($resource === 'sparepartrequests') {
    $action = $_GET['action'] ?? '';
    $currentUserId = (int) ($_SESSION['user']['id'] ?? 0);
    $currentRole = $_SESSION['user']['role'] ?? '';

    // Get mechanic ID from user if logged in as Mechanic
    $mechanicId = null;
    if ($currentRole === 'Mechanic') {
        $stmt = $pdo->prepare('SELECT MechanicID FROM users WHERE UserID = ?');
        $stmt->execute([$currentUserId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        $mechanicId = $user['MechanicID'] ?? null;
    }

    switch ($method) {
        case 'GET':
            if ($currentRole === 'Mechanic' && $mechanicId) {
                // Mechanic sees only their own requests
                $sql = "SELECT spr.*, 
                               m.FullName AS MechanicName,
                               sp.PartName AS SparePartName,
                               sp.Quantity AS CurrentStock,
                               rj.JobID,
                               v.PlateNumber,
                               u.FullName AS DecidedByName
                        FROM sparepartrequests spr
                        LEFT JOIN mechanics m ON m.MechanicID = spr.MechanicID
                        LEFT JOIN spareparts sp ON sp.SparePartID = spr.SparePartID
                        LEFT JOIN repairjobs rj ON rj.JobID = spr.JobID
                        LEFT JOIN vehicles v ON v.VehicleID = rj.VehicleID
                        LEFT JOIN users u ON u.UserID = spr.DecidedByUserID
                        WHERE spr.MechanicID = ?
                        ORDER BY spr.RequestedAt DESC";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$mechanicId]);
                echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
            } elseif (in_array($currentRole, ['Admin', 'Stock Manager'], true)) {
                // Admin and Stock Manager see all requests
                $sql = "SELECT spr.*, 
                               m.FullName AS MechanicName,
                               sp.PartName AS SparePartName,
                               sp.Quantity AS CurrentStock,
                               rj.JobID,
                               v.PlateNumber,
                               u.FullName AS DecidedByName
                        FROM sparepartrequests spr
                        LEFT JOIN mechanics m ON m.MechanicID = spr.MechanicID
                        LEFT JOIN spareparts sp ON sp.SparePartID = spr.SparePartID
                        LEFT JOIN repairjobs rj ON rj.JobID = spr.JobID
                        LEFT JOIN vehicles v ON v.VehicleID = rj.VehicleID
                        LEFT JOIN users u ON u.UserID = spr.DecidedByUserID
                        ORDER BY spr.RequestedAt DESC";
                echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
            }
            break;

        case 'POST':
            // Admin/Stock Manager may also Approve or Reject via
            // POST ?action=approve|reject (in addition to the existing PUT
            // form below), so the frontend can use either verb.
            if ($action === 'approve' || $action === 'reject') {
                if (!in_array($currentRole, ['Admin', 'Stock Manager'], true)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                    exit;
                }

                $reqId = (int) ($_GET['id'] ?? 0);
                if (!$reqId) {
                    $postBody = json_decode(file_get_contents('php://input'), true) ?? [];
                    $reqId = (int) ($postBody['id'] ?? $postBody['request_id'] ?? 0);
                }
                if (!$reqId) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Missing request id.']);
                    exit;
                }

                if ($action === 'approve') {
                    handle_sparepartrequest_approve($pdo, $reqId, $currentUserId);
                } else {
                    $rejectBody = json_decode(file_get_contents('php://input'), true) ?? [];
                    handle_sparepartrequest_reject($pdo, $reqId, $currentUserId, trim($rejectBody['reason'] ?? ''));
                }
                exit;
            }

            if ($currentRole !== 'Mechanic' || !$mechanicId) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Only mechanics can create requests.']);
                exit;
            }

            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $sparePartId = (int) ($body['spare_part_id'] ?? 0);
            $quantityRequested = (int) ($body['quantity_requested'] ?? 0);
            $jobId = (int) ($body['job_id'] ?? 0) ?: null;
            $reason = trim($body['reason'] ?? '');

            if (!$sparePartId) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Please choose a spare part.']);
                exit;
            }
            if ($quantityRequested <= 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Quantity must be at least 1.']);
                exit;
            }
            if ($quantityRequested > 10000) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Quantity looks too large. Please enter a realistic amount.']);
                exit;
            }

            // The part must exist
            $partStmt = $pdo->prepare('SELECT SparePartID, PartName, Quantity FROM spareparts WHERE SparePartID = ?');
            $partStmt->execute([$sparePartId]);
            $part = $partStmt->fetch(PDO::FETCH_ASSOC);
            if (!$part) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'That spare part no longer exists.']);
                exit;
            }

            // If a job was chosen it must be one of this mechanic's own jobs
            if ($jobId) {
                $jobStmt = $pdo->prepare('SELECT JobID FROM repairjobs WHERE JobID = ? AND MechanicID = ?');
                $jobStmt->execute([$jobId, $mechanicId]);
                if (!$jobStmt->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'That job is not assigned to you.']);
                    exit;
                }
            }

            // Block an exact duplicate pending request (double-click / double-submit)
            $dupStmt = $pdo->prepare(
                'SELECT RequestID FROM sparepartrequests
                 WHERE MechanicID = ? AND SparePartID = ? AND (JobID <=> ?) AND Status = "Pending" LIMIT 1'
            );
            $dupStmt->execute([$mechanicId, $sparePartId, $jobId]);
            if ($dupStmt->fetch()) {
                http_response_code(409);
                echo json_encode([
                    'success' => false,
                    'message' => 'You already have a pending request for "' . $part['PartName'] . '" on this job.'
                ]);
                exit;
            }

            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare(
                    'INSERT INTO sparepartrequests (MechanicID, SparePartID, QuantityRequested, Reason, JobID) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$mechanicId, $sparePartId, $quantityRequested, $reason, $jobId]);
                $requestId = (int) $pdo->lastInsertId();

                // Notify all Stock Managers
                $stockManagers = $pdo->query("SELECT UserID FROM users WHERE Role = 'Stock Manager' AND Status = 'Active'")->fetchAll(PDO::FETCH_COLUMN);
                $notifStmt = $pdo->prepare('INSERT INTO notifications (UserID, Type, Message, Link) VALUES (?, ?, ?, ?)');
                foreach ($stockManagers as $stockManagerId) {
                    $notifStmt->execute([
                        $stockManagerId,
                        'part_request',
                        "New spare part request #{$requestId} for {$part['PartName']} (x{$quantityRequested})",
                        '#requests'
                    ]);
                }
                $pdo->commit();

                // Return the fully-joined row so the mechanic's table can show
                // the new request immediately, without a page refresh.
                $rowStmt = $pdo->prepare(
                    "SELECT spr.*, m.FullName AS MechanicName, sp.PartName AS SparePartName,
                            sp.Quantity AS CurrentStock, rj.JobID, v.PlateNumber,
                            u.FullName AS DecidedByName
                     FROM sparepartrequests spr
                     LEFT JOIN mechanics m ON m.MechanicID = spr.MechanicID
                     LEFT JOIN spareparts sp ON sp.SparePartID = spr.SparePartID
                     LEFT JOIN repairjobs rj ON rj.JobID = spr.JobID
                     LEFT JOIN vehicles v ON v.VehicleID = rj.VehicleID
                     LEFT JOIN users u ON u.UserID = spr.DecidedByUserID
                     WHERE spr.RequestID = ?"
                );
                $rowStmt->execute([$requestId]);

                echo json_encode([
                    'success' => true,
                    'id'      => $requestId,
                    'data'    => $rowStmt->fetch(PDO::FETCH_ASSOC),
                    'message' => 'Request submitted successfully.'
                ]);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Spare part request create failed: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Could not submit your request. Please try again.']);
            }
            break;

        case 'PUT':
            if (!in_array($currentRole, ['Admin', 'Stock Manager'], true)) {
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

            if ($action === 'approve') {
                handle_sparepartrequest_approve($pdo, $id, $currentUserId);
            } elseif ($action === 'reject') {
                $body = json_decode(file_get_contents('php://input'), true) ?? [];
                handle_sparepartrequest_reject($pdo, $id, $currentUserId, trim($body['reason'] ?? ''));
            } else {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Invalid action. Use ?action=approve or ?action=reject']);
            }
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing request id.']);
                exit;
            }

            // Allow mechanic to delete their own pending requests, or Admin/Stock Manager to delete any
            if ($currentRole === 'Mechanic' && $mechanicId) {
                $stmt = $pdo->prepare('DELETE FROM sparepartrequests WHERE RequestID = ? AND MechanicID = ? AND Status = "Pending"');
                $stmt->execute([$id, $mechanicId]);
                if ($stmt->rowCount() > 0) {
                    reindex_table_ids($pdo, 'sparepartrequests', 'RequestID');
                    echo json_encode(['success' => true, 'message' => 'Request cancelled.']);
                } else {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: request not found, not pending, or not yours.']);
                }
            } elseif (in_array($currentRole, ['Admin', 'Stock Manager'], true)) {
                try {
                    $stmt = $pdo->prepare('DELETE FROM sparepartrequests WHERE RequestID = ?');
                    $stmt->execute([$id]);
                    reindex_table_ids($pdo, 'sparepartrequests', 'RequestID');
                    echo json_encode(['success' => true, 'message' => 'Request deleted.']);
                } catch (PDOException $e) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'message' => 'Database error.']);
                }
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    }
    exit;
}


// PURCHASES

if ($resource === 'purchases') {
    // Single purchase row in the exact shape the Purchases table renders,
    // including its line items, so the UI can append it instantly.
    $fetchPurchase = function (int $id) use ($pdo) {
        $s = $pdo->prepare(
            "SELECT p.PurchaseID, p.PurchaseDate, p.TotalAmount, p.Status,
                    p.SupplierID, s.CompanyName AS SupplierName, u.Username AS UserName,
                    (SELECT COUNT(*) FROM stocktransactions st WHERE st.PurchaseID = p.PurchaseID) AS ItemCount
             FROM purchases p
             LEFT JOIN suppliers s ON s.SupplierID = p.SupplierID
             LEFT JOIN users u ON u.UserID = p.UserID
             WHERE p.PurchaseID = ?"
        );
        $s->execute([$id]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $items = $pdo->prepare(
            'SELECT st.TransactionID, st.SparePartID, sp.PartName, st.Quantity, st.UnitPrice
             FROM stocktransactions st
             LEFT JOIN spareparts sp ON sp.SparePartID = st.SparePartID
             WHERE st.PurchaseID = ?'
        );
        $items->execute([$id]);
        $row['Items'] = $items->fetchAll(PDO::FETCH_ASSOC);
        return $row;
    };

    switch ($method) {
        case 'GET':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id) {
                $row = $fetchPurchase($id);
                if (!$row) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Purchase not found.']);
                    exit;
                }
                echo json_encode(['success' => true, 'data' => $row]);
                break;
            }
            // LEFT JOINs (was INNER JOIN): a purchase whose supplier or creating
            // user was later removed must still be listed, otherwise rows
            // silently disappear from the table.
            $sql = "SELECT p.PurchaseID, p.PurchaseDate, p.TotalAmount, p.Status,
                           p.SupplierID,
                           COALESCE(s.CompanyName, 'Unknown supplier') AS SupplierName,
                           COALESCE(u.Username, 'system') AS UserName,
                           (SELECT COUNT(*) FROM stocktransactions st WHERE st.PurchaseID = p.PurchaseID) AS ItemCount
                    FROM purchases p
                    LEFT JOIN suppliers s ON s.SupplierID = p.SupplierID
                    LEFT JOIN users u ON u.UserID = p.UserID
                    ORDER BY p.PurchaseDate DESC, p.PurchaseID DESC";
            echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
            break;

        case 'POST':
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Stock Manager'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $supplierId = (int) ($body['supplier_id'] ?? 0);
            $userId = (int) ($_SESSION['user']['id'] ?? 0);
            $items = is_array($body['items'] ?? null) ? $body['items'] : [];

            if (!$supplierId) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Please choose a supplier.']);
                exit;
            }
            $supCheck = $pdo->prepare('SELECT SupplierID FROM suppliers WHERE SupplierID = ?');
            $supCheck->execute([$supplierId]);
            if (!$supCheck->fetch()) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'That supplier no longer exists.']);
                exit;
            }

            // Validate every line up-front and compute the total server-side so
            // the stored amount can never disagree with the recorded items.
            $cleanItems = [];
            $computedTotal = 0.0;
            foreach ($items as $index => $item) {
                $itemSparePartId = (int) ($item['spare_part_id'] ?? 0);
                $itemQuantity    = (int) ($item['quantity'] ?? 0);
                $itemUnitPrice   = (float) ($item['unit_price'] ?? 0);
                $line = 'Line ' . ($index + 1) . ': ';

                if (!$itemSparePartId) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => $line . 'please choose a spare part.']);
                    exit;
                }
                $partCheck = $pdo->prepare('SELECT SparePartID, UnitPrice FROM spareparts WHERE SparePartID = ?');
                $partCheck->execute([$itemSparePartId]);
                $partRow = $partCheck->fetch(PDO::FETCH_ASSOC);
                if (!$partRow) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => $line . 'that spare part no longer exists.']);
                    exit;
                }
                if ($itemQuantity <= 0) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => $line . 'quantity must be at least 1.']);
                    exit;
                }
                if ($itemUnitPrice < 0) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => $line . 'unit price cannot be negative.']);
                    exit;
                }
                if ($itemUnitPrice == 0.0) {
                    $itemUnitPrice = (float) $partRow['UnitPrice'];
                }
                $computedTotal += $itemQuantity * $itemUnitPrice;
                $cleanItems[] = [$itemSparePartId, $itemQuantity, $itemUnitPrice];
            }

            // Fall back to the submitted total for a header-only purchase.
            $totalAmount = $cleanItems ? round($computedTotal, 2) : round((float) ($body['total_amount'] ?? 0), 2);
            if ($totalAmount < 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Total amount cannot be negative.']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                // Create purchase with Pending status - NO stock update yet
                $stmt = $pdo->prepare(
                    'INSERT INTO purchases (SupplierID, UserID, PurchaseDate, TotalAmount, Status) VALUES (?, ?, CURDATE(), ?, "Pending")'
                );
                $stmt->execute([$supplierId, $userId, $totalAmount]);
                $purchaseId = (int) $pdo->lastInsertId();

                // Park each line as a 'Pending' stock transaction; approving the
                // purchase converts them to 'Purchase' and moves the stock.
                $transStmt = $pdo->prepare(
                    'INSERT INTO stocktransactions (SparePartID, TransactionType, Quantity, TransactionDate, PurchaseID, UnitPrice, BeforeQty, AfterQty, UserID) VALUES (?, "Pending", ?, CURDATE(), ?, ?, ?, ?, ?)'
                );
                foreach ($cleanItems as [$sid, $qty, $price]) {
                    // Get current stock before transaction
                    $beforeStock = $pdo->prepare('SELECT Quantity FROM spareparts WHERE SparePartID = ?');
                    $beforeStock->execute([$sid]);
                    $beforeQty = (int) $beforeStock->fetchColumn();
                    // For pending transactions, after quantity is same as before (stock not moved yet)
                    $transStmt->execute([$sid, $qty, $purchaseId, $price, $beforeQty, $beforeQty, $userId]);
                }

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'id'      => $purchaseId,
                    'data'    => $fetchPurchase($purchaseId),
                    'message' => 'Purchase recorded (Pending approval).'
                ]);
            } catch (Exception $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Purchase create failed: ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Could not record the purchase. Please try again.']);
            }
            break;

        case 'PUT':
            $action = $_GET['action'] ?? '';
            if ($action === 'approve') {
                if (!in_array($_SESSION['user']['role'], ['Admin', 'Stock Manager'], true)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                    exit;
                }
                $id = (int) ($_GET['id'] ?? 0);
                if (!$id) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Missing purchase id.']);
                    exit;
                }

                $pdo->beginTransaction();
                try {
                    // Check if purchase is Pending and not already approved
                    $stmt = $pdo->prepare('SELECT * FROM purchases WHERE PurchaseID = ? FOR UPDATE');
                    $stmt->execute([$id]);
                    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);

                    if (!$purchase) {
                        $pdo->rollBack();
                        http_response_code(404);
                        echo json_encode(['success' => false, 'message' => 'Purchase not found.']);
                        exit;
                    }

                    if ($purchase['Status'] !== 'Pending') {
                        $pdo->rollBack();
                        http_response_code(422);
                        echo json_encode(['success' => false, 'message' => 'Purchase is not in Pending status.']);
                        exit;
                    }

                    // Update purchase status to Approved
                    $updateStmt = $pdo->prepare('UPDATE purchases SET Status = "Approved" WHERE PurchaseID = ?');
                    $updateStmt->execute([$id]);

                    // Update stock for all pending transactions related to this purchase
                    $transStmt = $pdo->prepare(
                        'SELECT * FROM stocktransactions WHERE PurchaseID = ? AND TransactionType = "Pending"'
                    );
                    $transStmt->execute([$id]);
                    $transactions = $transStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($transactions as $trans) {
                        // Get current stock before transaction
                        $beforeStock = $pdo->prepare('SELECT Quantity FROM spareparts WHERE SparePartID = ?');
                        $beforeStock->execute([$trans['SparePartID']]);
                        $beforeQty = (int) $beforeStock->fetchColumn();

                        // Update spare parts stock
                        $stockUpdate = $pdo->prepare(
                            'UPDATE spareparts SET Quantity = Quantity + ? WHERE SparePartID = ?'
                        );
                        $stockUpdate->execute([$trans['Quantity'], $trans['SparePartID']]);

                        // Get new stock after transaction
                        $afterQty = $beforeQty + $trans['Quantity'];

                        // Update transaction type to Purchase (processed) and set accurate before/after quantities
                        $transUpdate = $pdo->prepare(
                            'UPDATE stocktransactions SET TransactionType = "Purchase", BeforeQty = ?, AfterQty = ?, UserID = ? WHERE TransactionID = ?'
                        );
                        $transUpdate->execute([$beforeQty, $afterQty, $_SESSION['user']['id'], $trans['TransactionID']]);
                    }

                    // Update purchase status to Processed after stock updates
                    $finalUpdate = $pdo->prepare('UPDATE purchases SET Status = "Processed" WHERE PurchaseID = ?');
                    $finalUpdate->execute([$id]);

                    $pdo->commit();
                    write_audit_log(
                        $pdo, (int) ($_SESSION['user']['id'] ?? 0), $_SESSION['user']['role'] ?? '', 'approve', 'purchase', $id,
                        'Pending', 'Processed', null
                    );
                    echo json_encode([
                        'success' => true,
                        'id'      => $id,
                        'data'    => $fetchPurchase($id),
                        'message' => 'Purchase approved and stock updated.'
                    ]);
                } catch (Exception $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    http_response_code(500);
                    error_log('Approval failed: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Approval failed. Please try again.']);
                }
                exit;
            }
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing purchase id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Stock Manager'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            
            // Check if purchase is already processed
            $checkStmt = $pdo->prepare('SELECT Status FROM purchases WHERE PurchaseID = ?');
            $checkStmt->execute([$id]);
            $purchase = $checkStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($purchase && $purchase['Status'] === 'Processed') {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Cannot delete a processed purchase.']);
                exit;
            }
            
            if (!$purchase) {
                http_response_code(404);
                echo json_encode(['success' => false, 'message' => 'Purchase not found or already deleted.']);
                exit;
            }

            try {
                $pdo->beginTransaction();
                // Remove the parked (un-approved) lines first so the foreign key
                // on stocktransactions cannot block the delete.
                $pdo->prepare('DELETE FROM stocktransactions WHERE PurchaseID = ?')->execute([$id]);
                reindex_table_ids($pdo, 'stocktransactions', 'TransactionID');
                $pdo->prepare('DELETE FROM purchases WHERE PurchaseID = ?')->execute([$id]);
                reindex_table_ids($pdo, 'purchases', 'PurchaseID');
                $pdo->commit();
                echo json_encode(['success' => true, 'id' => $id, 'message' => 'Purchase deleted.']);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Purchase delete failed: ' . $e->getMessage());
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Could not delete this purchase.']);
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
    'message' => 'Unknown resource. Use ?resource=categories|suppliers|spareparts|sparepartrequests|purchases',
]);
