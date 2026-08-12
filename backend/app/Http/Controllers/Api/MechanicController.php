<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mechanic;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MechanicController extends Controller
{
    public function index(Request $request)
    {
        if ($request->filled('id')) {
            $m = Mechanic::find($request->query('id'));
            return response()->json($m ? ['success' => true, 'data' => $m] : ['success' => false, 'message' => 'Mechanic not found.'], $m ? 200 : 404);
        }

        $mechanics = Mechanic::select('mechanics.*')
            ->selectRaw('(SELECT COUNT(*) FROM repairjobs WHERE repairjobs."MechanicID" = mechanics."MechanicID") AS "AssignedJobs"')
            ->selectRaw('COALESCE((SELECT u."Status" FROM users u WHERE u."MechanicID" = mechanics."MechanicID" LIMIT 1), \'Inactive\') AS "Status"')
            ->orderBy('MechanicID')
            ->get();

        return response()->json(['success' => true, 'data' => $mechanics]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'specialization' => 'nullable|string|max:255',
            'salary' => 'required|numeric|min:0',
        ]);
        $mechanic = Mechanic::create([
            'FullName' => $data['full_name'],
            'Phone' => $data['phone'] ?? null,
            'Specialization' => $data['specialization'] ?? null,
            'Salary' => $data['salary'],
        ]);
        return response()->json(['success' => true, 'message' => 'Mechanic added.', 'data' => $mechanic]);
    }

    public function update(Request $request, int $id)
    {
        $mechanic = Mechanic::find($id);
        if (!$mechanic) {
            return response()->json(['success' => false, 'message' => 'Mechanic not found.'], 404);
        }
        $data = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'specialization' => 'nullable|string|max:255',
            'salary' => 'sometimes|numeric|min:0',
        ]);
        $mechanic->fill(array_filter([
            'FullName' => $data['full_name'] ?? null,
            'Phone' => $data['phone'] ?? null,
            'Specialization' => $data['specialization'] ?? null,
            'Salary' => $data['salary'] ?? null,
        ], fn ($v) => $v !== null));
        $mechanic->save();
        return response()->json(['success' => true, 'message' => 'Mechanic updated.', 'data' => $mechanic]);
    }

    public function destroy(int $id)
    {
        $mechanic = Mechanic::find($id);
        if (!$mechanic) {
            return response()->json(['success' => false, 'message' => 'Mechanic not found.'], 404);
        }
        DB::transaction(function () use ($mechanic) {
            \App\Models\User::where('MechanicID', $mechanic->MechanicID)->update(['MechanicID' => null]);
            $mechanic->delete();
        });
        return response()->json(['success' => true, 'message' => 'Mechanic deleted.']);
    }
}
