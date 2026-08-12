<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = Payment::with('invoice.customer');
        if ($request->filled('id')) {
            $p = $query->find($request->query('id'));
            if (!$p) return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
            $p->CustomerName = $p->invoice->customer->FullName ?? null;
            return response()->json(['success' => true, 'data' => $p]);
        }
        $payments = $query->orderByDesc('PaymentID')->get()->map(function ($p) {
            $p->CustomerName = $p->invoice->customer->FullName ?? null;
            return $p;
        });
        return response()->json(['success' => true, 'data' => $payments]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'invoice_id' => 'required|integer|exists:invoices,InvoiceID',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'payment_status' => 'required|string|in:Paid,Pending,Partial',
            'payment_date' => 'required|date',
        ]);
        $payment = Payment::create([
            'InvoiceID' => $data['invoice_id'],
            'Amount' => $data['amount'],
            'PaymentMethod' => $data['payment_method'],
            'PaymentStatus' => $data['payment_status'],
            'PaymentDate' => $data['payment_date'],
        ]);
        return response()->json(['success' => true, 'message' => 'Payment recorded.', 'data' => $payment]);
    }

    public function update(Request $request, int $id)
    {
        $payment = Payment::find($id);
        if (!$payment) return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        $data = $request->validate([
            'amount' => 'sometimes|numeric|min:0.01',
            'payment_method' => 'sometimes|string',
            'payment_status' => 'sometimes|string|in:Paid,Pending,Partial',
            'payment_date' => 'sometimes|date',
        ]);
        $payment->fill(array_filter([
            'Amount' => $data['amount'] ?? null,
            'PaymentMethod' => $data['payment_method'] ?? null,
            'PaymentStatus' => $data['payment_status'] ?? null,
            'PaymentDate' => $data['payment_date'] ?? null,
        ], fn ($v) => $v !== null));
        $payment->save();
        return response()->json(['success' => true, 'message' => 'Payment updated.', 'data' => $payment]);
    }

    public function destroy(int $id)
    {
        $payment = Payment::find($id);
        if (!$payment) return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        $payment->delete();
        return response()->json(['success' => true, 'message' => 'Payment deleted.']);
    }
}
