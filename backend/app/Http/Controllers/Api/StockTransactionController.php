<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockTransaction;

class StockTransactionController extends Controller
{
    public function index()
    {
        $rows = StockTransaction::with(['sparePart', 'user'])->orderByDesc('TransactionID')->limit(50)->get()->map(function ($t) {
            $t->PartName = $t->sparePart->PartName ?? null;
            $t->UserName = $t->user->FullName ?? 'System';
            return $t;
        });
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function destroy(int $id)
    {
        $txn = StockTransaction::find($id);
        if (!$txn) return response()->json(['success' => false, 'message' => 'Transaction not found.'], 404);
        $txn->delete();
        return response()->json(['success' => true, 'message' => 'Transaction removed.']);
    }
}
