<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        if ($request->filled('id')) {
            $v = Vehicle::with('customer')->find($request->query('id'));
            if (!$v) return response()->json(['success' => false, 'message' => 'Vehicle not found.'], 404);
            $v->OwnerName = $v->customer->FullName ?? null;
            return response()->json(['success' => true, 'data' => $v]);
        }
        $vehicles = Vehicle::with('customer')->orderBy('VehicleID')->get()->map(function ($v) {
            $v->OwnerName = $v->customer->FullName ?? null;
            return $v;
        });
        return response()->json(['success' => true, 'data' => $vehicles]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,CustomerID',
            'plate_number' => 'required|string|max:50|unique:vehicles,PlateNumber',
            'manufacturer' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'year' => 'required|max:4',
            'chassis_number' => 'nullable|string|max:100',
            'engine_number' => 'nullable|string|max:100',
            'fuel_type' => 'nullable|string',
            'transmission' => 'nullable|string',
            'mileage' => 'nullable|numeric',
        ]);
        $vehicle = Vehicle::create([
            'CustomerID' => $data['customer_id'],
            'PlateNumber' => $data['plate_number'],
            'Manufacturer' => $data['manufacturer'],
            'Model' => $data['model'],
            'Year' => $data['year'],
            'ChassisNumber' => $data['chassis_number'] ?? null,
            'EngineNumber' => $data['engine_number'] ?? null,
            'FuelType' => $data['fuel_type'] ?? 'Petrol',
            'Transmission' => $data['transmission'] ?? 'Manual',
            'Mileage' => $data['mileage'] ?? null,
        ]);
        return response()->json(['success' => true, 'message' => 'Vehicle added.', 'data' => $vehicle]);
    }

    public function update(Request $request, int $id)
    {
        $vehicle = Vehicle::find($id);
        if (!$vehicle) {
            return response()->json(['success' => false, 'message' => 'Vehicle not found.'], 404);
        }
        $data = $request->validate([
            'customer_id' => 'sometimes|integer|exists:customers,CustomerID',
            'plate_number' => 'sometimes|string|max:50|unique:vehicles,PlateNumber,' . $id . ',VehicleID',
            'manufacturer' => 'sometimes|string|max:255',
            'model' => 'sometimes|string|max:255',
            'year' => 'sometimes|max:4',
            'chassis_number' => 'nullable|string|max:100',
            'engine_number' => 'nullable|string|max:100',
            'fuel_type' => 'nullable|string',
            'transmission' => 'nullable|string',
            'mileage' => 'nullable|numeric',
        ]);
        $vehicle->fill(array_filter([
            'CustomerID' => $data['customer_id'] ?? null,
            'PlateNumber' => $data['plate_number'] ?? null,
            'Manufacturer' => $data['manufacturer'] ?? null,
            'Model' => $data['model'] ?? null,
            'Year' => $data['year'] ?? null,
            'ChassisNumber' => $data['chassis_number'] ?? null,
            'EngineNumber' => $data['engine_number'] ?? null,
            'FuelType' => $data['fuel_type'] ?? null,
            'Transmission' => $data['transmission'] ?? null,
            'Mileage' => $data['mileage'] ?? null,
        ], fn ($v) => $v !== null));
        $vehicle->save();
        return response()->json(['success' => true, 'message' => 'Vehicle updated.', 'data' => $vehicle]);
    }

    public function destroy(int $id)
    {
        $vehicle = Vehicle::find($id);
        if (!$vehicle) {
            return response()->json(['success' => false, 'message' => 'Vehicle not found.'], 404);
        }
        return $this->safeDelete($vehicle, 'vehicle');
    }
}
