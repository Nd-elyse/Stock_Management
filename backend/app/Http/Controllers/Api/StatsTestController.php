<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\RepairJob;
use App\Models\SparePart;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

class StatsTestController extends Controller
{
    /**
     * Debug endpoint to verify stats are correctly connected to database.
     * NOT for production - remove before deploying.
     * Accessible via GET /api/stats/test
     */
    public function test()
    {
        $customersCount = Customer::count();
        $vehiclesServicedCount = RepairJob::whereRaw('"Status"::text IN (?, ?, ?)', ['Delivered', 'Ready', 'Completed'])->count();
        $sparePartsCount = SparePart::count();
        $mechanicsCount = Mechanic::count();

        return response()->json([
            'success' => true,
            'message' => 'Stats verification - live database counts',
            'timestamp' => now()->toIso8601String(),
            'database' => [
                'connection' => config('database.default'),
                'database_name' => DB::connection()->getDatabaseName(),
                'customer_table_count' => $customersCount,
            ],
            'data' => [
                'customers' => [
                    'count' => $customersCount,
                    'query' => 'SELECT COUNT(*) FROM customers',
                ],
                'vehicles_serviced' => [
                    'count' => $vehiclesServicedCount,
                    'query' => "SELECT COUNT(*) FROM repairjobs WHERE Status IN ('Delivered', 'Ready', 'Completed')",
                ],
                'spare_parts' => [
                    'count' => $sparePartsCount,
                    'query' => 'SELECT COUNT(*) FROM spareparts',
                ],
                'mechanics' => [
                    'count' => $mechanicsCount,
                    'query' => 'SELECT COUNT(*) FROM mechanics',
                ],
                'api_response' => [
                    'public_stats_endpoint' => '/api/stats/public',
                    'expected_format' => [
                        'success' => true,
                        'data' => [
                            'customers' => $customersCount,
                            'vehicles_serviced' => $vehiclesServicedCount,
                            'spare_parts' => $sparePartsCount,
                            'mechanics' => $mechanicsCount,
                        ]
                    ]
                ]
            ],
            'tables_status' => [
                'customers' => [
                    'exists' => true,
                    'count' => $customersCount,
                    'empty' => $customersCount === 0,
                ],
                'vehicles' => [
                    'exists' => true,
                    'count' => Vehicle::count(),
                    'empty' => Vehicle::count() === 0,
                ],
                'spareparts' => [
                    'exists' => true,
                    'count' => $sparePartsCount,
                    'empty' => $sparePartsCount === 0,
                ],
                'mechanics' => [
                    'exists' => true,
                    'count' => $mechanicsCount,
                    'empty' => $mechanicsCount === 0,
                ],
                'repairjobs' => [
                    'exists' => true,
                    'total_count' => RepairJob::count(),
                    'completed_count' => $vehiclesServicedCount,
                    'empty' => RepairJob::count() === 0,
                ],
            ]
        ]);
    }
}
