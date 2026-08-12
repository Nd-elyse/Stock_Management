<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\SparePart;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InvoiceController extends Controller
{
    private function decorate($inv)
    {
        $totalPaid = (float) $inv->payments->sum('Amount');
        $inv->CustomerName = $inv->customer->FullName ?? null;
        $inv->CustomerPhone = $inv->customer->Phone ?? null;
        $inv->CustomerEmail = $inv->customer->Email ?? null;
        $inv->CustomerAddress = $inv->customer->Address ?? null;
        $inv->PlateNumber = $inv->vehicle->PlateNumber ?? null;
        $inv->VehicleModel = $inv->vehicle->Model ?? null;
        $inv->VehicleManufacturer = $inv->vehicle->Manufacturer ?? null;
        $inv->VehicleYear = $inv->vehicle->Year ?? null;
        $inv->TotalPaid = $totalPaid;
        $total = (float) $inv->TotalAmount;
        $inv->PaymentStatus = $totalPaid <= 0 ? 'Pending' : ($totalPaid + 0.01 < $total ? 'Partial' : 'Paid');
        return $inv;
    }

    public function index(Request $request)
    {
        $query = Invoice::with(['customer', 'vehicle', 'payments']);
        if ($request->filled('id')) {
            $inv = $query->with('items.sparePart')->find($request->query('id'));
            if (!$inv) return response()->json(['success' => false, 'message' => 'Invoice not found.'], 404);
            return response()->json(['success' => true, 'data' => $this->decorate($inv)]);
        }
        $invoices = $query->orderByDesc('InvoiceID')->get()->map(fn ($i) => $this->decorate($i));
        return response()->json(['success' => true, 'data' => $invoices]);
    }

    /** Same formula as the original billing.php: rate is used only when an explicit amount isn't sent. */
    private function resolveTaxAndDiscount(array $data, float $labour, float $parts): array
    {
        $taxRate = (float) ($data['tax_rate'] ?? 0);
        $taxAmount = array_key_exists('tax_amount', $data) && $data['tax_amount'] !== null
            ? (float) $data['tax_amount']
            : ($labour + $parts) * ($taxRate / 100);

        $discountRate = (float) ($data['discount_rate'] ?? 0);
        $discountAmount = array_key_exists('discount_amount', $data) && $data['discount_amount'] !== null
            ? (float) $data['discount_amount']
            : ($labour + $parts + $taxAmount) * ($discountRate / 100);

        return [$taxRate, $taxAmount, $discountRate, $discountAmount];
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,CustomerID',
            'vehicle_id' => 'nullable|integer|exists:vehicles,VehicleID',
            'job_id' => 'nullable|integer|exists:repairjobs,JobID',
            'invoice_date' => 'required|date|before_or_equal:today',
            'labour_charges' => 'nullable|numeric|min:0',
            'spare_parts_cost' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_rate' => 'nullable|numeric|min:0|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
            'items' => 'nullable|array',
            'items.*.spare_part_id' => 'required_with:items|integer|exists:spareparts,SparePartID',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.price' => 'nullable|numeric|min:0',
        ]);

        $labour = (float) ($data['labour_charges'] ?? 0);
        $parts = (float) ($data['spare_parts_cost'] ?? 0);
        [$taxRate, $taxAmount, $discountRate, $discountAmount] = $this->resolveTaxAndDiscount($data, $labour, $parts);
        $total = $labour + $parts + $taxAmount - $discountAmount;

        // Check stock up front for every item before touching anything.
        foreach ($data['items'] ?? [] as $i => $item) {
            $part = SparePart::find($item['spare_part_id']);
            if (!$part || $part->Quantity < $item['quantity']) {
                return response()->json(['success' => false, 'message' => 'Item ' . ($i + 1) . ' insufficient stock. Available: ' . ($part->Quantity ?? 0)], 422);
            }
        }

        return DB::transaction(function () use ($data, $labour, $parts, $taxRate, $taxAmount, $discountRate, $discountAmount, $total) {
            $invoice = Invoice::create([
                'CustomerID' => $data['customer_id'],
                'VehicleID' => $data['vehicle_id'] ?? null,
                'JobID' => $data['job_id'] ?? null,
                'InvoiceDate' => $data['invoice_date'],
                'LabourCharges' => $labour,
                'SparePartsCost' => $parts,
                'Taxes' => $taxAmount,
                'Discounts' => $discountAmount,
                'TaxRate' => $taxRate,
                'DiscountRate' => $discountRate,
                'TotalAmount' => $total,
            ]);

            foreach ($data['items'] ?? [] as $item) {
                InvoiceItem::create([
                    'InvoiceID' => $invoice->InvoiceID,
                    'SparePartID' => $item['spare_part_id'],
                    'Quantity' => $item['quantity'],
                    'Price' => $item['price'] ?? 0,
                ]);

                $part = SparePart::find($item['spare_part_id']);
                $before = $part->Quantity;
                $after = $before - $item['quantity'];
                $part->Quantity = $after;
                $part->save();

                StockTransaction::create([
                    'SparePartID' => $part->SparePartID,
                    'UserID' => auth()->id(),
                    'TransactionType' => 'Sale',
                    'Quantity' => $item['quantity'],
                    'BeforeQty' => $before,
                    'AfterQty' => $after,
                    'Reference' => 'Invoice #' . $invoice->InvoiceID,
                ]);
            }

            return response()->json(['success' => true, 'message' => 'Invoice created.', 'data' => $invoice]);
        });
    }

    public function update(Request $request, int $id)
    {
        $invoice = Invoice::find($id);
        if (!$invoice) return response()->json(['success' => false, 'message' => 'Invoice not found.'], 404);

        $data = $request->validate([
            'customer_id' => 'sometimes|integer|exists:customers,CustomerID',
            'vehicle_id' => 'nullable|integer|exists:vehicles,VehicleID',
            'job_id' => 'nullable|integer|exists:repairjobs,JobID',
            'invoice_date' => 'nullable|date|before_or_equal:today',
            'labour_charges' => 'nullable|numeric|min:0',
            'spare_parts_cost' => 'nullable|numeric|min:0',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_amount' => 'nullable|numeric|min:0',
            'discount_rate' => 'nullable|numeric|min:0|max:100',
            'discount_amount' => 'nullable|numeric|min:0',
        ]);

        $labour = array_key_exists('labour_charges', $data) ? (float) $data['labour_charges'] : (float) $invoice->LabourCharges;
        $parts = array_key_exists('spare_parts_cost', $data) ? (float) $data['spare_parts_cost'] : (float) $invoice->SparePartsCost;
        $mergedForRates = array_merge(['tax_rate' => $invoice->TaxRate, 'discount_rate' => $invoice->DiscountRate], $data);
        [$taxRate, $taxAmount, $discountRate, $discountAmount] = $this->resolveTaxAndDiscount($mergedForRates, $labour, $parts);

        $invoice->fill(array_filter([
            'CustomerID' => $data['customer_id'] ?? null,
            'VehicleID' => array_key_exists('vehicle_id', $data) ? $data['vehicle_id'] : null,
            'JobID' => array_key_exists('job_id', $data) ? $data['job_id'] : null,
            'InvoiceDate' => $data['invoice_date'] ?? null,
        ], fn ($v) => $v !== null));

        $invoice->LabourCharges = $labour;
        $invoice->SparePartsCost = $parts;
        $invoice->Taxes = $taxAmount;
        $invoice->Discounts = $discountAmount;
        $invoice->TaxRate = $taxRate;
        $invoice->DiscountRate = $discountRate;
        $invoice->TotalAmount = $labour + $parts + $taxAmount - $discountAmount;
        $invoice->save();

        return response()->json(['success' => true, 'message' => 'Invoice updated.', 'data' => $invoice]);
    }

    public function destroy(int $id)
    {
        $invoice = Invoice::find($id);
        if (!$invoice) return response()->json(['success' => false, 'message' => 'Invoice not found.'], 404);
        $invoice->delete();
        return response()->json(['success' => true, 'message' => 'Invoice deleted.']);
    }
}
