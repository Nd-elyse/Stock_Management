<?php
/**
 * Manual Repair Jobs CRUD Test
 * This script validates the entire repair jobs lifecycle without relying on test framework
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\RepairJob;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Boot the application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Repair Jobs CRUD Manual Test ===\n\n";

// Test 1: Verify Schema
echo "1. Checking database schema...\n";
if (Schema::hasColumn('repairjobs', 'Description')) {
    echo "   ✓ Description column exists\n";
} else {
    echo "   ✗ FAIL: Description column missing!\n";
    exit(1);
}

// Test 2: Create test data
echo "\n2. Creating test data...\n";
$customer = Customer::firstOrCreate(
    ['FullName' => 'Test Customer'],
    ['Phone' => '0712345678', 'Email' => 'test@test.com', 'Address' => '123 Test St']
);
echo "   ✓ Customer created (ID: {$customer->CustomerID})\n";

$vehicle = Vehicle::firstOrCreate(
    ['PlateNumber' => 'TEST001'],
    [
        'CustomerID' => $customer->CustomerID,
        'Manufacturer' => 'Toyota',
        'Model' => 'Corolla',
        'Year' => 2020,
    ]
);
echo "   ✓ Vehicle created (ID: {$vehicle->VehicleID}, Plate: {$vehicle->PlateNumber})\n";

$mechanic = Mechanic::firstOrCreate(
    ['FullName' => 'Test Mechanic'],
    ['Phone' => '0787654321', 'Specialty' => 'General', 'Status' => 'Active']
);
echo "   ✓ Mechanic created (ID: {$mechanic->MechanicID})\n";

$user = User::firstOrCreate(
    ['Username' => 'admin'],
    [
        'Password' => bcrypt('password'),
        'Role' => 'Admin',
        'FullName' => 'Admin',
        'Email' => 'admin@test.com',
        'Status' => 'Active',
    ]
);
echo "   ✓ User created (ID: {$user->UserID})\n";

// Test 3: CREATE repair job
echo "\n3. Creating repair job...\n";
$job = RepairJob::create([
    'VehicleID' => $vehicle->VehicleID,
    'MechanicID' => $mechanic->MechanicID,
    'UserID' => $user->UserID,
    'Description' => 'Front brake pads replacement needed',
    'StartDate' => now()->toDateString(),
    'Status' => 'Pending',
]);
echo "   ✓ Job created (ID: {$job->JobID})\n";
echo "   Description: {$job->Description}\n";

// Test 4: READ repair job
echo "\n4. Reading repair job...\n";
$readJob = RepairJob::find($job->JobID);
if ($readJob && $readJob->Description === 'Front brake pads replacement needed') {
    echo "   ✓ Job retrieved correctly\n";
    echo "   Description: {$readJob->Description}\n";
} else {
    echo "   ✗ FAIL: Job not found or Description mismatch!\n";
    exit(1);
}

// Test 5: UPDATE repair job
echo "\n5. Updating repair job...\n";
$readJob->Description = 'Updated: Engine noise investigation and brake pad replacement';
$readJob->Status = 'In Progress';
$readJob->save();
echo "   ✓ Job updated\n";
echo "   New Description: {$readJob->Description}\n";
echo "   New Status: {$readJob->Status}\n";

// Test 6: Verify UPDATE was persisted
echo "\n6. Verifying update persisted...\n";
$verifyJob = RepairJob::find($job->JobID);
if ($verifyJob->Description === 'Updated: Engine noise investigation and brake pad replacement' &&
    $verifyJob->Status === 'In Progress') {
    echo "   ✓ Update verified in database\n";
} else {
    echo "   ✗ FAIL: Update not persisted correctly!\n";
    exit(1);
}

// Test 7: LIST repair jobs
echo "\n7. Listing all repair jobs...\n";
$allJobs = RepairJob::orderByDesc('JobID')->get();
echo "   ✓ Found " . count($allJobs) . " job(s)\n";
foreach ($allJobs as $j) {
    echo "   - Job #{$j->JobID}: {$j->Status} - {$j->Description}\n";
}

// Test 8: DELETE repair job
echo "\n8. Deleting repair job...\n";
$deleteId = $job->JobID;
$job->delete();
$deletedJob = RepairJob::find($deleteId);
if ($deletedJob === null) {
    echo "   ✓ Job deleted successfully\n";
} else {
    echo "   ✗ FAIL: Job still exists after delete!\n";
    exit(1);
}

// Test 9: Verify fillable array includes Description
echo "\n9. Verifying model configuration...\n";
$testModel = new RepairJob();
$fillable = $testModel->getFillable();
if (in_array('Description', $fillable)) {
    echo "   ✓ Description in model fillable array: " . implode(', ', $fillable) . "\n";
} else {
    echo "   ✗ FAIL: Description not in fillable array!\n";
    exit(1);
}

echo "\n=== All Tests Passed! ===\n";
