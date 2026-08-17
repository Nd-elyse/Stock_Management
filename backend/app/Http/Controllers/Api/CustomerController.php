<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;

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
            'phone' => ['required', 'string', 'regex:/^\d{10}$/', 'not_regex:/^(072|073|078|079)/'],
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
        ]);
        $customer = Customer::create([
            'FullName' => $data['full_name'],
            'Phone' => $data['phone'],
            'Email' => $data['email'] ?? null,
            'Address' => $data['address'] ?? null,
            'RegistrationDate' => now()->toDateString(),
        ]);
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
            'phone' => ['sometimes', 'string', 'regex:/^\d{10}$/', 'not_regex:/^(072|073|078|079)/'],
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
