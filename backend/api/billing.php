<?php
// api/billing.php - invoices & payments
// Pick a resource with ?resource=invoices|payments

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

// Mechanics have no legitimate need to browse customer/vehicle/financial
// records directly; their dashboard only ever needs their own assigned jobs,
// diagnostics, and spare-part requests (served by dedicated endpoints).
if ($_SESSION['user']['role'] === 'Mechanic') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized.']);
    exit;
}

// INVOICES

if ($resource === 'invoices') {
    switch ($method) {
        case 'GET':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id) {
                // Get single invoice with items for printing/viewing
                $stmt = $pdo->prepare(
                    "SELECT i.*, 
                            c.FullName AS CustomerName, c.Phone AS CustomerPhone, c.Email AS CustomerEmail, c.Address AS CustomerAddress,
                            v.PlateNumber, v.Model AS VehicleModel, v.Manufacturer AS VehicleManufacturer, v.Year AS VehicleYear,
                            p.PaymentStatus, p.PaymentMethod, p.PaymentID
                     FROM invoices i
                     LEFT JOIN customers c ON c.CustomerID = i.CustomerID
                     LEFT JOIN vehicles v ON v.VehicleID = i.VehicleID
                     LEFT JOIN payments p ON p.InvoiceID = i.InvoiceID
                     WHERE i.InvoiceID = ?"
                );
                $stmt->execute([$id]);
                $invoice = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$invoice) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
                    exit;
                }

                // Get invoice items
                $itemsStmt = $pdo->prepare(
                    "SELECT ii.*,
                            sp.PartName AS SparePartName
                     FROM invoiceitems ii
                     LEFT JOIN spareparts sp ON sp.SparePartID = ii.SparePartID
                     WHERE ii.InvoiceID = ?"
                );
                $itemsStmt->execute([$id]);
                $invoice['items'] = $itemsStmt->fetchAll();

                echo json_encode(['success' => true, 'data' => $invoice]);
            } else {
                // Get all invoices
                $sql = "SELECT i.*, 
                        c.FullName AS CustomerName,
                        v.PlateNumber,
                        v.Model AS VehicleModel,
                        p.PaymentStatus, 
                        p.PaymentMethod,
                        p.PaymentID
                        FROM invoices i
                        LEFT JOIN customers c ON c.CustomerID = i.CustomerID
                        LEFT JOIN vehicles v ON v.VehicleID = i.VehicleID
                        LEFT JOIN payments p ON p.InvoiceID = i.InvoiceID
                        ORDER BY i.InvoiceID DESC";
                echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
            }
            break;

        case 'POST':
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $customerId = (int) ($body['customer_id'] ?? 0);
            $jobId = (int) ($body['job_id'] ?? 0) ?: null;
            $vehicleId = (int) ($body['vehicle_id'] ?? 0) ?: null;
            $labourCharges = (float) ($body['labour_charges'] ?? 0);
            $sparePartsCost = (float) ($body['spare_parts_cost'] ?? 0);
            // Bug fix: the frontend collects an "Invoice Date" field and sends it as
            // invoice_date, but this endpoint used to ignore it entirely and always
            // hard-code CURDATE() below -- the date picker was a dead input. Now we
            // honor a submitted date (defaulting to today when omitted) and, per the
            // date-validation rule for the whole system, reject it if it's in the future.
            $invoiceDate = trim($body['invoice_date'] ?? '') ?: date('Y-m-d');

            // Validate required fields
            if (!$customerId) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Customer is required.']);
                exit;
            }

            if ($error = validate_date_not_future_field($invoiceDate, 'Invoice date')) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            // Validate numeric ranges
            if ($error = validate_non_negative_numeric_field($labourCharges, 'Labour charges')) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }
            if ($error = validate_non_negative_numeric_field($sparePartsCost, 'Spare parts cost')) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            // Validate customer exists
            $customerCheck = $pdo->prepare('SELECT CustomerID FROM customers WHERE CustomerID = ?');
            $customerCheck->execute([$customerId]);
            if (!$customerCheck->fetch()) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Customer not found.']);
                exit;
            }

            // Validate job exists if provided
            if ($jobId) {
                $jobCheck = $pdo->prepare('SELECT JobID FROM repairjobs WHERE JobID = ?');
                $jobCheck->execute([$jobId]);
                if (!$jobCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Job not found.']);
                    exit;
                }
            }

            // Validate vehicle exists if provided
            if ($vehicleId) {
                $vehicleCheck = $pdo->prepare('SELECT VehicleID FROM vehicles WHERE VehicleID = ?');
                $vehicleCheck->execute([$vehicleId]);
                if (!$vehicleCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Vehicle not found.']);
                    exit;
                }
            }

            // Support both fixed amounts and percentage-based calculations
            $taxRate = (float) ($body['tax_rate'] ?? 0); // e.g., 18 for 18%
            if ($taxRate < 0 || $taxRate > 100) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Tax rate must be between 0 and 100.']);
                exit;
            }
            $taxAmount = $body['tax_amount'] !== null ? (float) $body['tax_amount'] : ($labourCharges + $sparePartsCost) * ($taxRate / 100);
            if ($taxAmount < 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Tax amount cannot be negative.']);
                exit;
            }

            $discountRate = (float) ($body['discount_rate'] ?? 0); // e.g., 10 for 10%
            if ($discountRate < 0 || $discountRate > 100) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Discount rate must be between 0 and 100.']);
                exit;
            }
            $discountAmount = $body['discount_amount'] !== null ? (float) $body['discount_amount'] : ($labourCharges + $sparePartsCost + $taxAmount) * ($discountRate / 100);
            if ($discountAmount < 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Discount amount cannot be negative.']);
                exit;
            }

            $totalAmount = $labourCharges + $sparePartsCost + $taxAmount - $discountAmount;

            // Validate invoice items if provided
            if (!empty($body['items'])) {
                foreach ($body['items'] as $index => $item) {
                    $sparePartId = (int) ($item['spare_part_id'] ?? 0);
                    $quantity = (int) ($item['quantity'] ?? 1);
                    $price = (float) ($item['price'] ?? 0);

                    if (!$sparePartId) {
                        http_response_code(422);
                        echo json_encode(['success' => false, 'message' => 'Item ' . ($index + 1) . ' must have a spare part.']);
                        exit;
                    }

                    if ($quantity <= 0) {
                        http_response_code(422);
                        echo json_encode(['success' => false, 'message' => 'Item ' . ($index + 1) . ' quantity must be positive.']);
                        exit;
                    }

                    if ($price < 0) {
                        http_response_code(422);
                        echo json_encode(['success' => false, 'message' => 'Item ' . ($index + 1) . ' price cannot be negative.']);
                        exit;
                    }

                    // Validate spare part exists if provided
                    if ($sparePartId) {
                        $partCheck = $pdo->prepare('SELECT SparePartID, Quantity FROM spareparts WHERE SparePartID = ?');
                        $partCheck->execute([$sparePartId]);
                        $part = $partCheck->fetch();
                        if (!$part) {
                            http_response_code(422);
                            echo json_encode(['success' => false, 'message' => 'Item ' . ($index + 1) . ' spare part not found.']);
                            exit;
                        }
                        if ($part['Quantity'] < $quantity) {
                            http_response_code(422);
                            echo json_encode(['success' => false, 'message' => 'Item ' . ($index + 1) . ' insufficient stock. Available: ' . $part['Quantity']]);
                            exit;
                        }
                    }

                }
            }

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO invoices (CustomerID, JobID, VehicleID, InvoiceDate, LabourCharges, SparePartsCost, Taxes, Discounts, TotalAmount, TaxRate, DiscountRate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([$customerId, $jobId, $vehicleId, $invoiceDate, $labourCharges, $sparePartsCost, $taxAmount, $discountAmount, $totalAmount, $taxRate, $discountRate]);
                $invoiceId = $pdo->lastInsertId();

                // Add invoice items and deduct stock for spare parts
                if (!empty($body['items'])) {
                    foreach ($body['items'] as $item) {
                        $itemStmt = $pdo->prepare(
                            'INSERT INTO invoiceitems (InvoiceID, SparePartID, Quantity, Price) VALUES (?, ?, ?, ?)'
                        );
                        $itemStmt->execute([
                            $invoiceId,
                            (int) ($item['spare_part_id'] ?? 0) ?: null,
                            (int) ($item['quantity'] ?? 1),
                            (float) ($item['price'] ?? 0)
                        ]);

                        // Deduct stock if spare part
                        $sparePartId = (int) ($item['spare_part_id'] ?? 0);
                        if ($sparePartId) {
                            $qty = (int) ($item['quantity'] ?? 1);
                            // Get current stock before transaction
                            $beforeStock = $pdo->prepare('SELECT Quantity FROM spareparts WHERE SparePartID = ?');
                            $beforeStock->execute([$sparePartId]);
                            $beforeQty = (int) $beforeStock->fetchColumn();

                            $pdo->prepare('UPDATE spareparts SET Quantity = Quantity - ? WHERE SparePartID = ?')
                                ->execute([$qty, $sparePartId]);

                            // Get new stock after transaction
                            $afterQty = $beforeQty - $qty;

                            // Log stock transaction with accurate before/after quantities
                            $pdo->prepare(
                                'INSERT INTO stocktransactions (SparePartID, TransactionType, Quantity, TransactionDate, BeforeQty, AfterQty, UserID) VALUES (?, ?, ?, CURDATE(), ?, ?, ?)'
                            )->execute([$sparePartId, 'Sale', $qty, $beforeQty, $afterQty, $_SESSION['user']['id']]);
                        }
                    }
                }

                $pdo->commit();
                echo json_encode(['success' => true, 'id' => $invoiceId, 'message' => 'Invoice created.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                error_log('Failed to create invoice: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to create invoice. Please try again.']);
            }
            break;

        case 'PUT':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing invoice id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }

            // Validate invoice exists
            $invoiceCheck = $pdo->prepare('SELECT InvoiceID FROM invoices WHERE InvoiceID = ?');
            $invoiceCheck->execute([$id]);
            if (!$invoiceCheck->fetch()) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
                exit;
            }

            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $labourCharges = (float) ($body['labour_charges'] ?? 0);
            $sparePartsCost = (float) ($body['spare_parts_cost'] ?? 0);
            // Same fix as on create: honor an edited invoice_date instead of
            // silently keeping whatever was stored before, and reject future dates.
            $invoiceDate = trim($body['invoice_date'] ?? '');
            if ($invoiceDate && ($error = validate_date_not_future_field($invoiceDate, 'Invoice date'))) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            // Validate numeric ranges
            if ($labourCharges < 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Labour charges cannot be negative.']);
                exit;
            }
            if ($sparePartsCost < 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Spare parts cost cannot be negative.']);
                exit;
            }

            // Support both fixed amounts and percentage-based calculations
            $taxRate = (float) ($body['tax_rate'] ?? 0);
            if ($taxRate < 0 || $taxRate > 100) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Tax rate must be between 0 and 100.']);
                exit;
            }
            $taxAmount = $body['tax_amount'] !== null ? (float) $body['tax_amount'] : ($labourCharges + $sparePartsCost) * ($taxRate / 100);
            if ($taxAmount < 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Tax amount cannot be negative.']);
                exit;
            }

            $discountRate = (float) ($body['discount_rate'] ?? 0);
            if ($discountRate < 0 || $discountRate > 100) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Discount rate must be between 0 and 100.']);
                exit;
            }
            $discountAmount = $body['discount_amount'] !== null ? (float) $body['discount_amount'] : ($labourCharges + $sparePartsCost + $taxAmount) * ($discountRate / 100);
            if ($discountAmount < 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Discount amount cannot be negative.']);
                exit;
            }

            $totalAmount = $labourCharges + $sparePartsCost + $taxAmount - $discountAmount;

            $pdo->beginTransaction();
            try {
                if ($invoiceDate) {
                    $stmt = $pdo->prepare(
                        'UPDATE invoices SET LabourCharges=?, SparePartsCost=?, Taxes=?, Discounts=?, TotalAmount=?, TaxRate=?, DiscountRate=?, InvoiceDate=? WHERE InvoiceID=?'
                    );
                    $stmt->execute([$labourCharges, $sparePartsCost, $taxAmount, $discountAmount, $totalAmount, $taxRate, $discountRate, $invoiceDate, $id]);
                } else {
                    $stmt = $pdo->prepare(
                        'UPDATE invoices SET LabourCharges=?, SparePartsCost=?, Taxes=?, Discounts=?, TotalAmount=?, TaxRate=?, DiscountRate=? WHERE InvoiceID=?'
                    );
                    $stmt->execute([$labourCharges, $sparePartsCost, $taxAmount, $discountAmount, $totalAmount, $taxRate, $discountRate, $id]);
                }
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Invoice updated.']);
            } catch (Exception $e) {
                $pdo->rollBack();
                http_response_code(500);
                error_log('Failed to update invoice: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to update invoice. Please try again.']);
            }
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing invoice id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            try {
                $pdo->beginTransaction();

                // Get invoice items to restore stock before deletion
                $itemsStmt = $pdo->prepare('SELECT SparePartID, Quantity FROM invoiceitems WHERE InvoiceID = ?');
                $itemsStmt->execute([$id]);
                $items = $itemsStmt->fetchAll();

                // Restore stock for each spare part item
                foreach ($items as $item) {
                    if ($item['SparePartID']) {
                        // Get current stock before transaction
                        $beforeStock = $pdo->prepare('SELECT Quantity FROM spareparts WHERE SparePartID = ?');
                        $beforeStock->execute([$item['SparePartID']]);
                        $beforeQty = (int) $beforeStock->fetchColumn();

                        $pdo->prepare('UPDATE spareparts SET Quantity = Quantity + ? WHERE SparePartID = ?')
                            ->execute([$item['Quantity'], $item['SparePartID']]);

                        // Get new stock after transaction
                        $afterQty = $beforeQty + $item['Quantity'];

                        // Log stock restoration transaction with accurate before/after quantities
                        $pdo->prepare(
                            'INSERT INTO stocktransactions (SparePartID, TransactionType, Quantity, TransactionDate, BeforeQty, AfterQty, UserID) VALUES (?, ?, ?, CURDATE(), ?, ?, ?)'
                        )->execute([$item['SparePartID'], 'Restoration', $item['Quantity'], $beforeQty, $afterQty, $_SESSION['user']['id']]);
                    }
                }

                // Delete invoice items first (foreign key constraint)
                $pdo->prepare('DELETE FROM invoiceitems WHERE InvoiceID = ?')->execute([$id]);
                reindex_table_ids($pdo, 'invoiceitems', 'InvoiceItemID');

                // Delete invoice
                $stmt = $pdo->prepare('DELETE FROM invoices WHERE InvoiceID = ?');
                $stmt->execute([$id]);
                reindex_table_ids($pdo, 'invoices', 'InvoiceID');

                $pdo->commit();
                write_audit_log(
                    $pdo, (int) ($_SESSION['user']['id'] ?? 0), $_SESSION['user']['role'] ?? '', 'delete', 'invoice', $id,
                    null, null, 'Stock restored for ' . count($items) . ' item(s)'
                );
                echo json_encode(['success' => true, 'message' => 'Invoice deleted and stock restored.']);
            } catch (PDOException $e) {
                $pdo->rollBack();
                http_response_code(409);
                if ((int) $e->errorInfo[1] === 1451) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: this invoice has linked payments. Remove payments first.']);
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

// PAYMENTS

if ($resource === 'payments') {
    switch ($method) {
        case 'GET':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id) {
                $stmt = $pdo->prepare(
                    "SELECT p.*, c.FullName AS CustomerName
                     FROM payments p
                     JOIN invoices i ON i.InvoiceID = p.InvoiceID
                     JOIN customers c ON c.CustomerID = i.CustomerID
                     WHERE p.PaymentID = ?"
                );
                $stmt->execute([$id]);
                $payment = $stmt->fetch();

                if (!$payment) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Payment not found.']);
                    exit;
                }

                echo json_encode(['success' => true, 'data' => $payment]);
                break;
            }

            $sql = "SELECT p.*, c.FullName AS CustomerName
                    FROM payments p
                    JOIN invoices i ON i.InvoiceID = p.InvoiceID
                    JOIN customers c ON c.CustomerID = i.CustomerID
                    ORDER BY p.PaymentID DESC";
            echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
            break;

        case 'POST':
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $invoiceId = (int) ($body['invoice_id'] ?? 0);
            $amount = (float) ($body['amount'] ?? 0);
            $paymentMethod = trim($body['payment_method'] ?? '');
            // Bug fix: PaymentStatus used to be whatever the client sent, with no
            // relation to the actual amount paid -- see the deriveInvoicePaymentStatus
            // computation below, which now decides this instead of trusting the request.
            // Bug fix: same issue as invoice_date -- the Payment Date field on the
            // frontend was captured and sent as payment_date but silently discarded;
            // PaymentDate was always hard-coded to CURDATE(). Honor it, defaulting to
            // today, and reject a future date per the system-wide date rule.
            $paymentDate = trim($body['payment_date'] ?? '') ?: date('Y-m-d');

            $errors = [];
            if ($error = validate_date_not_future_field($paymentDate, 'Payment date')) {
                $errors[] = $error;
            }
            if (!validate_integer($invoiceId, 1)) {
                $errors[] = 'Invoice is required.';
            }
            if (!validate_numeric($amount, 0, null)) {
                $errors[] = 'Amount must be a non-negative number.';
            }
            if ($paymentMethod === '') {
                $errors[] = 'Payment method is required.';
            }
            if ($errors) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
                exit;
            }

            if ($error = validate_enum_field($paymentMethod, 'Payment method', ['Cash', 'Mobile Money', 'Bank Transfer'])) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            // Bug fix: the invoice was never checked for existence before this
            // insert, so a bad invoice_id used to hit the DB's foreign-key
            // constraint directly as an uncaught PDOException instead of a
            // clean validation error.
            $invoiceStmt = $pdo->prepare('SELECT TotalAmount FROM invoices WHERE InvoiceID = ?');
            $invoiceStmt->execute([$invoiceId]);
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Invoice not found.']);
                exit;
            }

            // Bug fix: derive PaymentStatus from what's actually been paid instead
            // of trusting the client's chosen status -- otherwise a $1 payment
            // could be marked "Paid" on a much larger invoice.
            $alreadyPaidStmt = $pdo->prepare('SELECT COALESCE(SUM(Amount), 0) FROM payments WHERE InvoiceID = ?');
            $alreadyPaidStmt->execute([$invoiceId]);
            $totalPaidAfter = (float) $alreadyPaidStmt->fetchColumn() + $amount;
            $invoiceTotal = (float) $invoice['TotalAmount'];
            if ($totalPaidAfter <= 0) {
                $paymentStatus = 'Pending';
            } elseif ($totalPaidAfter + 0.01 < $invoiceTotal) {
                $paymentStatus = 'Partial';
            } else {
                $paymentStatus = 'Paid';
            }

            // Bug fix: nothing stopped a double-click (or a resubmitted request)
            // from creating two identical payments. Block an exact duplicate
            // (same invoice/amount/method/day) -- the payments table only stores
            // a date, not a timestamp, so this is a same-day check rather than a
            // precise few-seconds window; it will occasionally require a second
            // confirmation for a genuinely repeated same-day payment, which is a
            // safer failure mode than silently allowing an accidental duplicate.
            $dupPaymentStmt = $pdo->prepare(
                'SELECT PaymentID FROM payments
                 WHERE InvoiceID = ? AND Amount = ? AND PaymentMethod = ?
                   AND PaymentDate = CURDATE()
                 ORDER BY PaymentID DESC LIMIT 1'
            );
            $dupPaymentStmt->execute([$invoiceId, $amount, $paymentMethod]);
            if ($dupPaymentStmt->fetch()) {
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'An identical payment for this invoice was just recorded. If this is a separate payment, please confirm the amount.']);
                exit;
            }

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO payments (InvoiceID, Amount, PaymentMethod, PaymentStatus, PaymentDate) VALUES (?, ?, ?, ?, ?)'
                );
                $stmt->execute([$invoiceId, $amount, $paymentMethod, $paymentStatus, $paymentDate]);
                echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Payment recorded.', 'payment_status' => $paymentStatus]);
            } catch (PDOException $e) {
                http_response_code(500);
                error_log('Failed to record payment: ' . $e->getMessage());
                echo json_encode(['success' => false, 'message' => 'Failed to record payment. Please try again.']);
            }
            break;

        case 'PUT':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing payment id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $amount = (float) ($body['amount'] ?? 0);
            $paymentMethod = trim($body['payment_method'] ?? '');
            $paymentDate = trim($body['payment_date'] ?? '');
            if ($paymentDate && ($error = validate_date_not_future_field($paymentDate, 'Payment date'))) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            if (!validate_numeric($amount, 0, null)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Amount must be a non-negative number.']);
                exit;
            }
            if ($paymentMethod === '') {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Payment method is required.']);
                exit;
            }
            if ($error = validate_enum_field($paymentMethod, 'Payment method', ['Cash', 'Mobile Money', 'Bank Transfer'])) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => $error]);
                exit;
            }

            // Bug fix: verify the payment (and its invoice) exist before updating,
            // same class of gap as the missing invoice check on create.
            $existingPaymentStmt = $pdo->prepare('SELECT InvoiceID, Amount FROM payments WHERE PaymentID = ?');
            $existingPaymentStmt->execute([$id]);
            $existingPayment = $existingPaymentStmt->fetch(PDO::FETCH_ASSOC);
            if (!$existingPayment) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Payment not found.']);
                exit;
            }

            // Bug fix: derive PaymentStatus from actual amounts, same reasoning as
            // on create -- don't let the client just declare a payment "Paid".
            $invoiceStmt = $pdo->prepare('SELECT TotalAmount FROM invoices WHERE InvoiceID = ?');
            $invoiceStmt->execute([$existingPayment['InvoiceID']]);
            $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
            $otherPaidStmt = $pdo->prepare('SELECT COALESCE(SUM(Amount), 0) FROM payments WHERE InvoiceID = ? AND PaymentID != ?');
            $otherPaidStmt->execute([$existingPayment['InvoiceID'], $id]);
            $totalPaidAfter = (float) $otherPaidStmt->fetchColumn() + $amount;
            $invoiceTotal = $invoice ? (float) $invoice['TotalAmount'] : 0.0;
            if ($totalPaidAfter <= 0) {
                $paymentStatus = 'Pending';
            } elseif ($totalPaidAfter + 0.01 < $invoiceTotal) {
                $paymentStatus = 'Partial';
            } else {
                $paymentStatus = 'Paid';
            }

            if ($paymentDate) {
                $stmt = $pdo->prepare(
                    'UPDATE payments SET Amount=?, PaymentMethod=?, PaymentStatus=?, PaymentDate=? WHERE PaymentID=?'
                );
                $stmt->execute([$amount, $paymentMethod, $paymentStatus, $paymentDate, $id]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE payments SET Amount=?, PaymentMethod=?, PaymentStatus=? WHERE PaymentID=?'
                );
                $stmt->execute([$amount, $paymentMethod, $paymentStatus, $id]);
            }
            echo json_encode(['success' => true, 'message' => 'Payment updated.']);
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing payment id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            try {
                $stmt = $pdo->prepare('DELETE FROM payments WHERE PaymentID = ?');
                $stmt->execute([$id]);
                reindex_table_ids($pdo, 'payments', 'PaymentID');
                echo json_encode(['success' => true, 'message' => 'Payment deleted.']);
            } catch (PDOException $e) {
                http_response_code(409);
                if ((int) $e->errorInfo[1] === 1451) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: this payment is linked to an invoice. Remove the invoice first.']);
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
    'message' => 'Unknown resource. Use ?resource=invoices|payments',
]);
