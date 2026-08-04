<?php
// api/customers.php - customer & vehicle records

// Pick a resource with ?resource=customers|vehicles

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

// CUSTOMERS

if ($resource === 'customers') {
    switch ($method) {
        case 'GET':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id) {
                $stmt = $pdo->prepare(
                    "SELECT c.*, COUNT(v.VehicleID) AS VehicleCount
                     FROM customers c
                     LEFT JOIN vehicles v ON v.CustomerID = c.CustomerID
                     WHERE c.CustomerID = ?
                     GROUP BY c.CustomerID"
                );
                $stmt->execute([$id]);
                $customer = $stmt->fetch();

                if (!$customer) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Customer not found.']);
                    exit;
                }

                echo json_encode(['success' => true, 'data' => $customer]);
                break;
            }

            $sql = "SELECT c.*, COUNT(v.VehicleID) AS VehicleCount
                    FROM customers c
                    LEFT JOIN vehicles v ON v.CustomerID = c.CustomerID
                    GROUP BY c.CustomerID
                    ORDER BY c.CustomerID";
            echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
            break;

        case 'POST':
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $fullName = trim($body['full_name'] ?? '');
            $phone = trim($body['phone'] ?? '');
            $email = trim($body['email'] ?? '');
            $address = trim($body['address'] ?? '');

            $errors = [];
            if ($error = validate_name_field($fullName)) {
                $errors[] = $error;
            }
            if ($email && $error = validate_email_field($email, false)) {
                $errors[] = $error;
            }
            if ($phone && $error = validate_phone_rwanda_field($phone, false)) {
                $errors[] = $error;
            }
            if ($address && $error = validate_text_field($address, 'Address', null, 200, false)) {
                $errors[] = $error;
            }

            if (!empty($errors)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
                exit;
            }

            // Check for duplicate email
            if ($email) {
                $emailCheck = $pdo->prepare('SELECT CustomerID FROM customers WHERE Email = ?');
                $emailCheck->execute([$email]);
                if ($emailCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Email already exists.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare(
                'INSERT INTO customers (FullName, Phone, Email, Address, RegistrationDate) VALUES (?, ?, ?, ?, CURDATE())'
            );
            $stmt->execute([$fullName, $phone, $email, $address]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Customer created.']);
            break;

        case 'PUT':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing customer id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }

            // Validate customer exists
            $customerCheck = $pdo->prepare('SELECT CustomerID FROM customers WHERE CustomerID = ?');
            $customerCheck->execute([$id]);
            if (!$customerCheck->fetch()) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Customer not found.']);
                exit;
            }

            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $fullName = trim($body['full_name'] ?? '');
            $phone = trim($body['phone'] ?? '');
            $email = trim($body['email'] ?? '');
            $address = trim($body['address'] ?? '');

            $errors = [];
            if ($fullName && $error = validate_name_field($fullName, false)) {
                $errors[] = $error;
            }
            if ($email && $error = validate_email_field($email, false)) {
                $errors[] = $error;
            }
            if ($phone && $error = validate_phone_rwanda_field($phone, false)) {
                $errors[] = $error;
            }
            if ($address && $error = validate_text_field($address, 'Address', null, 200, false)) {
                $errors[] = $error;
            }

            if (!empty($errors)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
                exit;
            }

            // Check for duplicate email (excluding current customer)
            if ($email) {
                $emailCheck = $pdo->prepare('SELECT CustomerID FROM customers WHERE Email = ? AND CustomerID != ?');
                $emailCheck->execute([$email, $id]);
                if ($emailCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Email already exists for another customer.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE customers SET FullName=?, Phone=?, Email=?, Address=? WHERE CustomerID=?'
            );
            $stmt->execute([$fullName, $phone, $email, $address, $id]);
            echo json_encode(['success' => true, 'message' => 'Customer updated.']);
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing customer id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            try {
                $stmt = $pdo->prepare('DELETE FROM customers WHERE CustomerID = ?');
                $stmt->execute([$id]);
                reindex_table_ids($pdo, 'customers', 'CustomerID');
                echo json_encode(['success' => true, 'message' => 'Customer deleted.']);
            } catch (PDOException $e) {
                http_response_code(409);
                if ((int) $e->errorInfo[1] === 1451) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: this customer has linked vehicles, jobs, or invoices. Remove those first.']);
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

// VEHICLES

if ($resource === 'vehicles') {
    switch ($method) {
        case 'GET':
            $id = (int) ($_GET['id'] ?? 0);
            if ($id) {
                $stmt = $pdo->prepare(
                    "SELECT v.*, c.FullName AS OwnerName
                     FROM vehicles v
                     LEFT JOIN customers c ON c.CustomerID = v.CustomerID
                     WHERE v.VehicleID = ?"
                );
                $stmt->execute([$id]);
                $vehicle = $stmt->fetch();

                if (!$vehicle) {
                    http_response_code(404);
                    echo json_encode(['success' => false, 'message' => 'Vehicle not found.']);
                    exit;
                }

                echo json_encode(['success' => true, 'data' => $vehicle]);
                break;
            }

            $sql = "SELECT v.*, c.FullName AS OwnerName
                    FROM vehicles v
                    LEFT JOIN customers c ON c.CustomerID = v.CustomerID
                    ORDER BY v.VehicleID";
            echo json_encode(['success' => true, 'data' => $pdo->query($sql)->fetchAll()]);
            break;

        case 'POST':
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $customerId = (int) ($body['customer_id'] ?? 0) ?: null;
            $plateNumber = trim($body['plate_number'] ?? '');
            $manufacturer = trim($body['manufacturer'] ?? '');
            $model = trim($body['model'] ?? '');
            $year = (int) ($body['year'] ?? 0) ?: null;
            $chassisNumber = trim($body['chassis_number'] ?? '');
            $engineNumber = trim($body['engine_number'] ?? '');
            $fuelType = trim($body['fuel_type'] ?? '');
            $transmission = trim($body['transmission'] ?? '');
            $mileage = (int) ($body['mileage'] ?? 0) ?: null;

            if (!$plateNumber || !$manufacturer || !$model) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Plate number, manufacturer and model are required.']);
                exit;
            }

            if (strlen($plateNumber) > 20) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Plate number must not exceed 20 characters.']);
                exit;
            }

            if (strlen($manufacturer) > 50) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Manufacturer must not exceed 50 characters.']);
                exit;
            }

            if (strlen($model) > 50) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Model must not exceed 50 characters.']);
                exit;
            }

            if ($year && ($year < 1900 || $year > 2100)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Year must be between 1900 and 2100.']);
                exit;
            }

            if ($chassisNumber && strlen($chassisNumber) > 50) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Chassis number must not exceed 50 characters.']);
                exit;
            }

            if ($engineNumber && strlen($engineNumber) > 50) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Engine number must not exceed 50 characters.']);
                exit;
            }

            if ($fuelType && strlen($fuelType) > 30) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Fuel type must not exceed 30 characters.']);
                exit;
            }

            if ($transmission && strlen($transmission) > 30) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Transmission must not exceed 30 characters.']);
                exit;
            }

            if ($mileage < 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Mileage cannot be negative.']);
                exit;
            }

            // Validate customer exists if provided
            if ($customerId) {
                $customerCheck = $pdo->prepare('SELECT CustomerID FROM customers WHERE CustomerID = ?');
                $customerCheck->execute([$customerId]);
                if (!$customerCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Customer not found.']);
                    exit;
                }
            }

            // Check for duplicate plate number
            $plateCheck = $pdo->prepare('SELECT VehicleID FROM vehicles WHERE PlateNumber = ?');
            $plateCheck->execute([$plateNumber]);
            if ($plateCheck->fetch()) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Plate number already exists.']);
                exit;
            }

            $stmt = $pdo->prepare(
                'INSERT INTO vehicles (CustomerID, PlateNumber, Manufacturer, Model, Year, ChassisNumber, EngineNumber, FuelType, Transmission, Mileage) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$customerId, $plateNumber, $manufacturer, $model, $year, $chassisNumber, $engineNumber, $fuelType, $transmission, $mileage]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'message' => 'Vehicle created.']);
            break;

        case 'PUT':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing vehicle id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }

            // Validate vehicle exists
            $vehicleCheck = $pdo->prepare('SELECT VehicleID FROM vehicles WHERE VehicleID = ?');
            $vehicleCheck->execute([$id]);
            if (!$vehicleCheck->fetch()) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Vehicle not found.']);
                exit;
            }

            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $customerId = (int) ($body['customer_id'] ?? 0) ?: null;
            $plateNumber = trim($body['plate_number'] ?? '');
            $manufacturer = trim($body['manufacturer'] ?? '');
            $model = trim($body['model'] ?? '');
            $year = (int) ($body['year'] ?? 0) ?: null;
            $chassisNumber = trim($body['chassis_number'] ?? '');
            $engineNumber = trim($body['engine_number'] ?? '');
            $fuelType = trim($body['fuel_type'] ?? '');
            $transmission = trim($body['transmission'] ?? '');
            $mileage = (int) ($body['mileage'] ?? 0) ?: null;

            if ($plateNumber && strlen($plateNumber) > 20) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Plate number must not exceed 20 characters.']);
                exit;
            }

            if ($manufacturer && strlen($manufacturer) > 50) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Manufacturer must not exceed 50 characters.']);
                exit;
            }

            if ($model && strlen($model) > 50) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Model must not exceed 50 characters.']);
                exit;
            }

            if ($year && ($year < 1900 || $year > 2100)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Year must be between 1900 and 2100.']);
                exit;
            }

            if ($chassisNumber && strlen($chassisNumber) > 50) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Chassis number must not exceed 50 characters.']);
                exit;
            }

            if ($engineNumber && strlen($engineNumber) > 50) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Engine number must not exceed 50 characters.']);
                exit;
            }

            if ($fuelType && strlen($fuelType) > 30) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Fuel type must not exceed 30 characters.']);
                exit;
            }

            if ($transmission && strlen($transmission) > 30) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Transmission must not exceed 30 characters.']);
                exit;
            }

            if ($mileage < 0) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Mileage cannot be negative.']);
                exit;
            }

            // Validate customer exists if provided
            if ($customerId) {
                $customerCheck = $pdo->prepare('SELECT CustomerID FROM customers WHERE CustomerID = ?');
                $customerCheck->execute([$customerId]);
                if (!$customerCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Customer not found.']);
                    exit;
                }
            }

            // Check for duplicate plate number (excluding current vehicle)
            if ($plateNumber) {
                $plateCheck = $pdo->prepare('SELECT VehicleID FROM vehicles WHERE PlateNumber = ? AND VehicleID != ?');
                $plateCheck->execute([$plateNumber, $id]);
                if ($plateCheck->fetch()) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'message' => 'Plate number already exists for another vehicle.']);
                    exit;
                }
            }

            $stmt = $pdo->prepare(
                'UPDATE vehicles SET CustomerID=?, PlateNumber=?, Manufacturer=?, Model=?, Year=?, ChassisNumber=?, EngineNumber=?, FuelType=?, Transmission=?, Mileage=? WHERE VehicleID=?'
            );
            $stmt->execute([$customerId, $plateNumber, $manufacturer, $model, $year, $chassisNumber, $engineNumber, $fuelType, $transmission, $mileage, $id]);
            echo json_encode(['success' => true, 'message' => 'Vehicle updated.']);
            break;

        case 'DELETE':
            $id = (int) ($_GET['id'] ?? 0);
            if (!$id) {
                http_response_code(422);
                echo json_encode(['success' => false, 'message' => 'Missing vehicle id.']);
                exit;
            }
            if (!in_array($_SESSION['user']['role'], ['Admin', 'Receptionist'], true)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Not authorized.']);
                exit;
            }
            try {
                $stmt = $pdo->prepare('DELETE FROM vehicles WHERE VehicleID = ?');
                $stmt->execute([$id]);
                reindex_table_ids($pdo, 'vehicles', 'VehicleID');
                echo json_encode(['success' => true, 'message' => 'Vehicle deleted.']);
            } catch (PDOException $e) {
                http_response_code(409);
                if ((int) $e->errorInfo[1] === 1451) {
                    echo json_encode(['success' => false, 'message' => 'Cannot delete: this vehicle has linked repair jobs or invoices. Remove those first.']);
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
    'message' => 'Unknown resource. Use ?resource=customers|vehicles',
]);
