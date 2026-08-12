<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function index()
    {
        $purchases = Purchase::with(['supplier', 'user'])->orderByDesc('PurchaseID')->get()->map(function ($p) {
            $p->SupplierName = $p->supplier->CompanyName ?? null;
            $p->UserName = $p->user->Username ?? null;
            return $p;
        });
        return response()->json(['success' => true, 'data' => $purchases]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,SupplierID',
            'total_amount' => 'required|numeric|min:0',
        ]);
        $purchase = Purchase::create([
            'SupplierID' => $data['supplier_id'],
            'UserID' => auth()->id(),
            'PurchaseDate' => now()->toDateString(),
            'TotalAmount' => $data['total_amount'],
            'Status' => 'Received',
        ]);
        return response()->json(['success' => true, 'message' => 'Purchase recorded.', 'data' => $purchase]);
    }

    public function destroy(int $id)
    {
        $purchase = Purchase::find($id);
        if (!$purchase) return response()->json(['success' => false, 'message' => 'Purchase not found.'], 404);
        $purchase->delete();
        return response()->json(['success' => true, 'message' => 'Purchase deleted.']);
    }
}
