<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SparePart;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SparePartController extends Controller
{
    private function withNames()
    {
        return SparePart::with(['category', 'supplier'])->orderBy('SparePartID')->get()->map(function ($p) {
            $p->CategoryName = $p->category->CategoryName ?? null;
            $p->SupplierName = $p->supplier->CompanyName ?? null;
            return $p;
        });
    }

    public function index()
    {
        return response()->json(['success' => true, 'data' => $this->withNames()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'part_name' => 'required|string|max:255',
            'category_id' => 'required|integer|exists:categories,CategoryID',
            'supplier_id' => 'nullable|integer|exists:suppliers,SupplierID',
            'unit_price' => 'required|numeric|min:0',
            'quantity' => 'required|integer|min:0',
            'reorder_level' => 'required|integer|min:0',
        ]);

        return DB::transaction(function () use ($data) {
            $part = SparePart::create([
                'PartName' => $data['part_name'],
                'CategoryID' => $data['category_id'],
                'SupplierID' => $data['supplier_id'] ?? null,
                'UnitPrice' => $data['unit_price'],
                'Quantity' => $data['quantity'],
                'ReorderLevel' => $data['reorder_level'],
            ]);
            if ($data['quantity'] > 0) {
                StockTransaction::create([
                    'SparePartID' => $part->SparePartID,
                    'UserID' => auth()->id(),
                    'TransactionType' => 'Purchase',
                    'Quantity' => $data['quantity'],
                    'BeforeQty' => 0,
                    'AfterQty' => $data['quantity'],
                    'Reference' => 'Initial stock',
                ]);
            }
            return response()->json(['success' => true, 'message' => 'Spare part added.', 'data' => $part]);
        });
    }

    public function update(Request $request, int $id)
    {
        $part = SparePart::find($id);
        if (!$part) {
            return response()->json(['success' => false, 'message' => 'Spare part not found.'], 404);
        }
        $data = $request->validate([
            'part_name' => 'sometimes|string|max:255',
            'category_id' => 'sometimes|integer|exists:categories,CategoryID',
            'supplier_id' => 'nullable|integer|exists:suppliers,SupplierID',
            'unit_price' => 'sometimes|numeric|min:0',
            'reorder_level' => 'sometimes|integer|min:0',
        ]);
        $part->fill(array_filter([
            'PartName' => $data['part_name'] ?? null,
            'CategoryID' => $data['category_id'] ?? null,
            'SupplierID' => array_key_exists('supplier_id', $data) ? $data['supplier_id'] : null,
            'UnitPrice' => $data['unit_price'] ?? null,
            'ReorderLevel' => $data['reorder_level'] ?? null,
        ], fn ($v) => $v !== null));
        $part->save();
        return response()->json(['success' => true, 'message' => 'Spare part updated.', 'data' => $part]);
    }

    public function destroy(int $id)
    {
        $part = SparePart::find($id);
        if (!$part) {
            return response()->json(['success' => false, 'message' => 'Spare part not found.'], 404);
        }
        $part->delete();
        return response()->json(['success' => true, 'message' => 'Spare part deleted.']);
    }

    /** Stock in / out / manual adjustment - keeps spareparts.Quantity and the stocktransactions ledger in sync. */
    public function adjust(Request $request, int $id)
    {
        $part = SparePart::find($id);
        if (!$part) {
            return response()->json(['success' => false, 'message' => 'Spare part not found.'], 404);
        }
        $data = $request->validate([
            'type' => 'required|string|in:Purchase,Usage,Adjustment,Sale',
            'quantity' => 'required|integer|min:1',
            'reference' => 'nullable|string|max:255',
        ]);

        $before = $part->Quantity;
        $increases = in_array($data['type'], ['Purchase', 'Adjustment'], true);
        $after = $increases ? $before + $data['quantity'] : $before - $data['quantity'];
        if ($after < 0) {
            return response()->json(['success' => false, 'message' => 'Not enough stock for this adjustment.'], 422);
        }

        return DB::transaction(function () use ($part, $data, $before, $after) {
            $part->Quantity = $after;
            $part->save();

            $txn = StockTransaction::create([
                'SparePartID' => $part->SparePartID,
                'UserID' => auth()->id(),
                'TransactionType' => $data['type'],
                'Quantity' => $data['quantity'],
                'BeforeQty' => $before,
                'AfterQty' => $after,
                'Reference' => $data['reference'] ?? null,
            ]);

            return response()->json(['success' => true, 'message' => 'Stock updated.', 'data' => ['part' => $part, 'transaction' => $txn]]);
        });
    }
}
