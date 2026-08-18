<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Mechanic;
use App\Models\RepairJob;
use App\Models\SparePart;
use App\Models\User;
use App\Models\Vehicle;

class StatsController extends Controller
{
    /** Public - powers the marketing Home page counters. */
    public function publicStats()
    {
        $completedStatuses = ['Delivered', 'Ready', 'Completed'];

        return response()->json(['success' => true, 'data' => [
            'customers' => Customer::count(),
            'vehicles_serviced' => RepairJob::whereRaw('"Status"::text IN (?, ?, ?)', $completedStatuses)->count(),
            'spare_parts' => SparePart::count(),
            'mechanics' => Mechanic::count(),
        ]]);
    }

    /** Authenticated - role-aware summary, mirrors each PHP dashboard's stat cards. */
    public function dashboard()
    {
        $user = auth()->user();

        $base = [
            'total_users' => User::count(),
            'total_mechanics' => Mechanic::count(),
            'total_customers' => Customer::count(),
            'total_vehicles' => Vehicle::count(),
            'total_jobs' => RepairJob::count(),
            'total_parts' => SparePart::count(),
            'low_stock' => SparePart::whereColumn('Quantity', '<=', 'ReorderLevel')->count(),
        ];

        if ($user->Role === 'Mechanic' && $user->MechanicID) {
            $base['my_active_jobs'] = RepairJob::where('MechanicID', $user->MechanicID)
                ->whereRaw('"Status"::text IN (?, ?, ?)', ['Pending', 'Diagnosed', 'In Progress'])->count();
            $base['my_awaiting_parts'] = RepairJob::where('MechanicID', $user->MechanicID)
                ->whereRaw('"Status"::text = ?', ['Awaiting Parts'])->count();
            $base['my_completed'] = RepairJob::where('MechanicID', $user->MechanicID)
                ->whereRaw('"Status"::text IN (?, ?, ?)', ['Delivered', 'Ready', 'Completed'])->count();
        }

        return response()->json(['success' => true, 'data' => $base]);
    }
}
