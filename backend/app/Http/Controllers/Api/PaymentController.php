<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
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

    /** Pending / Partial / Paid, derived from what's actually been paid so far - never trust the client's chosen status. */
    private function deriveStatus(float $totalPaidAfter, float $invoiceTotal): string
    {
        if ($totalPaidAfter <= 0) return 'Pending';
        if ($totalPaidAfter + 0.01 < $invoiceTotal) return 'Partial';
        return 'Paid';
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'invoice_id' => 'required|integer|exists:invoices,InvoiceID',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string',
            'payment_date' => 'required|date',
        ]);

        $invoice = Invoice::find($data['invoice_id']);
        if (!$invoice) {
            return response()->json(['success' => false, 'message' => 'Invoice not found.'], 404);
        }

        // Block an exact duplicate (same invoice/amount/method/day) - guards
        // against a double-click or resubmitted request creating two payments.
        $dup = Payment::where('InvoiceID', $data['invoice_id'])
            ->where('Amount', $data['amount'])
            ->where('PaymentMethod', $data['payment_method'])
            ->where('PaymentDate', $data['payment_date'])
            ->exists();
        if ($dup) {
            return response()->json([
                'success' => false,
                'message' => 'An identical payment for this invoice was just recorded. If this is a separate payment, please confirm the amount.',
            ], 409);
        }

        $alreadyPaid = (float) Payment::where('InvoiceID', $data['invoice_id'])->sum('Amount');
        $status = $this->deriveStatus($alreadyPaid + (float) $data['amount'], (float) $invoice->TotalAmount);

        $payment = Payment::create([
            'InvoiceID' => $data['invoice_id'],
            'Amount' => $data['amount'],
            'PaymentMethod' => $data['payment_method'],
            'PaymentStatus' => $status,
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
            'payment_date' => 'sometimes|date',
        ]);
        $payment->fill(array_filter([
            'Amount' => $data['amount'] ?? null,
            'PaymentMethod' => $data['payment_method'] ?? null,
            'PaymentDate' => $data['payment_date'] ?? null,
        ], fn ($v) => $v !== null));

        // Re-derive status for this invoice's payments after the edit.
        $invoice = $payment->invoice;
        if ($invoice) {
            $totalPaid = (float) Payment::where('InvoiceID', $payment->InvoiceID)
                ->where('PaymentID', '!=', $payment->PaymentID)
                ->sum('Amount') + (float) $payment->Amount;
            $payment->PaymentStatus = $this->deriveStatus($totalPaid, (float) $invoice->TotalAmount);
        }

        $payment->save();
        return response()->json(['success' => true, 'message' => 'Payment updated.', 'data' => $payment]);
    }

    public function destroy(int $id)
    {
        $payment = Payment::find($id);
        if (!$payment) return response()->json(['success' => false, 'message' => 'Payment not found.'], 404);
        return $this->safeDelete($payment, 'payment');
    }
}
