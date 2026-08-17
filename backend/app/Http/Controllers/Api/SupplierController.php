<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index()
    {
        $suppliers = Supplier::withCount('purchases as PurchaseCount')->orderBy('SupplierID')->get();
        return response()->json(['success' => true, 'data' => $suppliers]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'company_name' => 'required|string|max:255',
            'phone' => ['nullable', 'string', 'regex:/^\d{10}$/', 'not_regex:/^(072|073|078|079)/'],
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
        ]);
        $supplier = Supplier::create([
            'CompanyName' => $data['company_name'],
            'Phone' => $data['phone'] ?? null,
            'Email' => $data['email'] ?? null,
            'Address' => $data['address'] ?? null,
        ]);
        return response()->json(['success' => true, 'message' => 'Supplier added.', 'data' => $supplier]);
    }

    public function update(Request $request, int $id)
    {
        $supplier = Supplier::find($id);
        if (!$supplier) return response()->json(['success' => false, 'message' => 'Supplier not found.'], 404);
        $data = $request->validate([
            'company_name' => 'sometimes|string|max:255',
            'phone' => ['nullable', 'string', 'regex:/^\d{10}$/', 'not_regex:/^(072|073|078|079)/'],
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
        ]);
        $supplier->fill(array_filter([
            'CompanyName' => $data['company_name'] ?? null,
            'Phone' => $data['phone'] ?? null,
            'Email' => $data['email'] ?? null,
            'Address' => $data['address'] ?? null,
        ], fn ($v) => $v !== null));
        $supplier->save();
        return response()->json(['success' => true, 'message' => 'Supplier updated.', 'data' => $supplier]);
    }

    public function destroy(int $id)
    {
        $supplier = Supplier::find($id);
        if (!$supplier) return response()->json(['success' => false, 'message' => 'Supplier not found.'], 404);
        return $this->safeDelete($supplier, 'supplier');
    }
}
