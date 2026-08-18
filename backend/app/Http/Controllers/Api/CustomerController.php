<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        if ($request->filled('id')) {
            $c = Customer::withCount('vehicles as VehicleCount')->find($request->query('id'));
            return response()->json($c ? ['success' => true, 'data' => $c] : ['success' => false, 'message' => 'Customer not found.'], $c ? 200 : 404);
        }
        $customers = Customer::withCount('vehicles as VehicleCount')->orderBy('CustomerID')->get();
        return response()->json(['success' => true, 'data' => $customers]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:255',
            'phone' => ['required', 'string', 'regex:/^(072|073|078|079)\d{7}$/'],
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'vehicle' => 'sometimes|array',
            'vehicle.plate_number' => ['nullable', 'required_with:vehicle', 'string', 'max:50', 'unique:vehicles,PlateNumber'],
            'vehicle.manufacturer' => ['nullable', 'required_with:vehicle', 'string', 'max:255'],
            'vehicle.model' => ['nullable', 'required_with:vehicle', 'string', 'max:255'],
            'vehicle.year' => ['nullable', 'required_with:vehicle', 'max:4'],
            'vehicle.chassis_number' => 'nullable|string|max:100',
            'vehicle.engine_number' => 'nullable|string|max:100',
            'vehicle.fuel_type' => 'nullable|string',
            'vehicle.transmission' => 'nullable|string',
            'vehicle.mileage' => 'nullable|numeric',
        ]);

        $customer = DB::transaction(function () use ($data) {
            $customer = Customer::create([
                'FullName' => $data['full_name'],
                'Phone' => $data['phone'],
                'Email' => $data['email'] ?? null,
                'Address' => $data['address'] ?? null,
                'RegistrationDate' => now()->toDateString(),
            ]);

            $vehicleInput = $data['vehicle'] ?? [];
            if (!empty($vehicleInput)) {
                $vehicle = Vehicle::create([
                    'CustomerID' => $customer->CustomerID,
                    'PlateNumber' => $vehicleInput['plate_number'] ?? null,
                    'Manufacturer' => $vehicleInput['manufacturer'] ?? null,
                    'Model' => $vehicleInput['model'] ?? null,
                    'Year' => $vehicleInput['year'] ?? null,
                    'ChassisNumber' => $vehicleInput['chassis_number'] ?? null,
                    'EngineNumber' => $vehicleInput['engine_number'] ?? null,
                    'FuelType' => $vehicleInput['fuel_type'] ?? 'Petrol',
                    'Transmission' => $vehicleInput['transmission'] ?? 'Manual',
                    'Mileage' => $vehicleInput['mileage'] ?? null,
                ]);
                $customer->setRelation('vehicle', $vehicle);
            }

            return $customer;
        });

        return response()->json(['success' => true, 'message' => 'Customer added.', 'data' => $customer]);
    }

    public function update(Request $request, int $id)
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }
        $data = $request->validate([
            'full_name' => 'sometimes|string|max:255',
            'phone' => ['sometimes', 'string', 'regex:/^(072|073|078|079)\d{7}$/'],
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
        ]);
        $customer->fill(array_filter([
            'FullName' => $data['full_name'] ?? null,
            'Phone' => $data['phone'] ?? null,
            'Email' => $data['email'] ?? null,
            'Address' => $data['address'] ?? null,
        ], fn ($v) => $v !== null));
        $customer->save();
        return response()->json(['success' => true, 'message' => 'Customer updated.', 'data' => $customer]);
    }

    public function destroy(int $id)
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found.'], 404);
        }
        return $this->safeDelete($customer, 'customer');
    }
}
