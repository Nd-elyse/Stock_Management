<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Purchase;
use App\Models\SparePart;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseController extends Controller
{
    private function withDetails()
    {
        return Purchase::with(['supplier', 'user'])->orderByDesc('PurchaseID')->get()->map(function ($p) {
            $p->SupplierName = $p->supplier->CompanyName ?? null;
            $p->UserName = $p->user->FullName ?? $p->user->Username ?? null;
            // The order form is single-part-per-purchase, so pull the one
            // linked stocktransactions row back in for the table/print view.
            $txn = StockTransaction::where('PurchaseID', $p->PurchaseID)->first();
            $p->SparePartID = $txn->SparePartID ?? null;
            $p->Quantity = $txn->Quantity ?? null;
            $p->UnitPrice = $txn->UnitPrice ?? null;
            return $p;
        });
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => $this->withDetails()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|integer|exists:suppliers,SupplierID',
            'spare_part_id' => 'required|integer|exists:spareparts,SparePartID',
            'quantity' => 'required|integer|min:1',
            'unit_price' => 'required|numeric|min:0',
            'purchase_date' => 'nullable|date',
        ]);

        $part = SparePart::find($data['spare_part_id']);
        if (!$part) {
            return response()->json(['success' => false, 'message' => 'Spare part not found.'], 404);
        }
        $totalAmount = $data['quantity'] * $data['unit_price'];

        return DB::transaction(function () use ($data, $part, $totalAmount) {
            $purchase = Purchase::create([
                'SupplierID' => $data['supplier_id'],
                'UserID' => auth()->id(),
                'PurchaseDate' => $data['purchase_date'] ?? now()->toDateString(),
                'TotalAmount' => $totalAmount,
                'Status' => 'Received',
            ]);

            $before = $part->Quantity;
            $after = $before + $data['quantity'];
            $part->Quantity = $after;
            $part->save();

            StockTransaction::create([
                'SparePartID' => $part->SparePartID,
                'UserID' => auth()->id(),
                'TransactionType' => 'Purchase',
                'Quantity' => $data['quantity'],
                'TransactionDate' => $data['purchase_date'] ?? now()->toDateString(),
                'PurchaseID' => $purchase->PurchaseID,
                'UnitPrice' => $data['unit_price'],
                'BeforeQty' => $before,
                'AfterQty' => $after,
            ]);

            $purchase->SparePartID = $part->SparePartID;
            $purchase->Quantity = $data['quantity'];
            $purchase->UnitPrice = $data['unit_price'];

            return response()->json(['success' => true, 'message' => 'Purchase recorded and stock updated.', 'data' => $purchase]);
        });
    }

    public function destroy(int $id)
    {
        $purchase = Purchase::find($id);
        if (!$purchase) return response()->json(['success' => false, 'message' => 'Purchase not found.'], 404);
        return $this->safeDelete($purchase, 'purchase');
    }
}
