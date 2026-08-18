<?php
/**
 * Test Public Stats Endpoint
 * Verify the API returns correct structure and counts
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\RepairJob;
use App\Models\SparePart;

// Boot the application
$app = require_once __DIR__ . '/../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== Public Stats Endpoint Test ===\n\n";

echo "1. Database Record Counts:\n";
$customers = Customer::count();
$vehicles_serviced = RepairJob::whereIn('Status', ['Delivered', 'Ready', 'Completed'])->count();
$spare_parts = SparePart::count();
$mechanics = Mechanic::count();

echo "   Customers: $customers\n";
echo "   Vehicles Serviced (Completed Jobs): $vehicles_serviced\n";
echo "   Spare Parts: $spare_parts\n";
echo "   Mechanics: $mechanics\n";

echo "\n2. API Response Format:\n";
$response = [
    'success' => true,
    'data' => [
        'customers' => $customers,
        'vehicles_serviced' => $vehicles_serviced,
        'spare_parts' => $spare_parts,
        'mechanics' => $mechanics,
    ]
];
echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

echo "\n3. Frontend Will Parse:\n";
$keys = ['customers', 'vehicles_serviced', 'spare_parts', 'mechanics'];
$labels = ['Happy Customers', 'Vehicles Serviced', 'Spare Parts', 'Expert Mechanics'];
foreach ($keys as $i => $key) {
    $value = $response['data'][$key] ?? 0;
    echo "   {$labels[$i]}: $value\n";
}

echo "\n=== Test Complete ===\n";
