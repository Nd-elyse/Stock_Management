<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\JobHistory;
use App\Models\RepairJob;
use App\Models\SparePartRequest;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class TrackRepairController extends Controller
{
    /**
     * Public repair-status lookup for customers. Requires BOTH the vehicle's
     * plate number AND the owner's full name to match (not the plate alone),
     * so this can't be used to enumerate other customers' repair records.
     */
    public function lookup(Request $request)
    {
        $data = $request->validate([
            'full_name' => 'required|string|min:2',
            'plate_number' => 'required|string|min:2',
        ]);

        // Forgiving match: case-insensitive, ignores surrounding whitespace,
        // and ignores spaces inside the plate ("RAB123A" vs "RAB 123 A").
        $name = trim($data['full_name']);
        $plate = strtolower(str_replace(' ', '', $data['plate_number']));

        $vehicle = Vehicle::with('customer')
            ->get()
            ->first(function ($v) use ($plate) {
                return strtolower(str_replace(' ', '', $v->PlateNumber ?? '')) === $plate;
            });

        if (!$vehicle || !$vehicle->customer || strtolower(trim($vehicle->customer->FullName ?? '')) !== strtolower($name)) {
            return response()->json([
                'success' => false,
                'message' => 'No matching repair record found. Please check the name and plate number and try again.',
            ], 404);
        }

        // Data source #1: every repair job ever opened for this vehicle.
        $jobs = RepairJob::with('mechanic')
            ->where('VehicleID', $vehicle->VehicleID)
            ->orderByDesc('JobID')
            ->get();

        if ($jobs->isEmpty()) {
            return response()->json([
                'success' => true,
                'data' => [
                    'vehicle' => $this->vehicleSummary($vehicle),
                    'job' => null,
                    'jobs' => [],
                    'summary' => null,
                    'message' => 'This vehicle has no repair jobs on record yet.',
                ],
            ]);
        }

        // Data source #2 (diagnostics) + #3 (job history) + #4 (spare part
        // requests) + #5 (invoices/payments) are pulled per job below and
        // folded into both the per-job detail and the cross-job summary.
        $jobPayloads = $jobs->map(fn ($job) => $this->jobSummary($job));

        $totalSpent = $jobPayloads->sum(fn ($j) => $j['estimate']['total_amount'] ?? 0);
        $totalPaid = $jobPayloads->sum(fn ($j) => $j['estimate']['total_paid'] ?? 0);
        $startDates = $jobs->pluck('StartDate')->filter()->sort();
        $endDates = $jobs->pluck('EndDate')->filter()->sort();

        $summary = [
            'total_jobs' => $jobs->count(),
            'completed_jobs' => $jobs->whereIn('Status', ['Delivered', 'Ready', 'Completed'])->count(),
            'in_progress_jobs' => $jobs->whereIn('Status', ['Pending', 'Diagnosed', 'In Progress', 'Awaiting Parts'])->count(),
            'total_spent' => round($totalSpent, 2),
            'total_paid' => round($totalPaid, 2),
            'balance_due' => round(max(0, $totalSpent - $totalPaid), 2),
            'first_service_date' => $startDates->first(),
            'last_service_date' => $endDates->last() ?: $startDates->last(),
            'distinct_mechanics' => $jobs->pluck('mechanic.FullName')->filter()->unique()->values(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'vehicle' => $this->vehicleSummary($vehicle),
                // "job" kept as the latest job for backward compatibility with
                // any existing consumer expecting a single current job.
                'job' => $jobPayloads->first()['job'],
                'history' => $jobPayloads->first()['history'],
                'parts_used' => $jobPayloads->first()['parts_used'],
                'estimate' => $jobPayloads->first()['estimate'],
                // Full multi-job detail, newest first, for the final-result view.
                'jobs' => $jobPayloads->values(),
                'summary' => $summary,
            ],
        ]);
    }

    /** Builds the full detail payload (job + diagnostics + history + parts + invoice) for one job. */
    private function jobSummary(RepairJob $job): array
    {
        $diagnostic = $job->diagnostics()->orderByDesc('DiagnosticID')->first();

        $history = JobHistory::where('JobID', $job->JobID)->orderBy('ChangedAt')->get()->map(fn ($h) => [
            'previous_status' => $h->PreviousStatus,
            'new_status' => $h->NewStatus,
            'mechanic_name' => $h->MechanicName,
            'changed_at' => $h->ChangedAt,
        ]);

        $partsUsed = SparePartRequest::with('sparePart')
            ->where('JobID', $job->JobID)
            ->where('Status', 'Fulfilled')
            ->get()
            ->map(fn ($r) => [
                'part_name' => $r->sparePart->PartName ?? 'Unknown part',
                'quantity' => $r->QuantityRequested,
            ]);

        $invoice = Invoice::with('items.sparePart')->where('JobID', $job->JobID)->orderByDesc('InvoiceID')->first();
        $estimate = null;
        if ($invoice) {
            $totalPaid = (float) $invoice->payments()->sum('Amount');
            $latestPayment = $invoice->payments()->orderByDesc('PaymentDate')->orderByDesc('PaymentID')->first();
            $estimate = [
                'invoice_id' => $invoice->InvoiceID,
                'invoice_date' => $invoice->InvoiceDate,
                'labour_charges' => (float) $invoice->LabourCharges,
                'spare_parts_cost' => (float) $invoice->SparePartsCost,
                'tax_rate' => (float) $invoice->TaxRate,
                'discount_rate' => (float) $invoice->DiscountRate,
                'taxes' => (float) $invoice->Taxes,
                'discounts' => (float) $invoice->Discounts,
                'total_amount' => (float) $invoice->TotalAmount,
                'total_paid' => $totalPaid,
                'payment_method' => $latestPayment->PaymentMethod ?? null,
                'payment_status' => $totalPaid <= 0 ? 'Pending' : ($totalPaid + 0.01 < $invoice->TotalAmount ? 'Partial' : 'Paid'),
                'items' => $invoice->items->map(fn ($i) => [
                    'part_name' => $i->sparePart->PartName ?? 'Unknown part',
                    'quantity' => $i->Quantity,
                    'price' => (float) $i->Price,
                ]),
            ];
        }

        return [
            'job' => [
                'job_id' => $job->JobID,
                'status' => $job->Status,
                'start_date' => $job->StartDate,
                'end_date' => $job->EndDate,
                'mechanic_name' => $job->mechanic->FullName ?? 'Not yet assigned',
                'diagnostic_notes' => $diagnostic->Notes ?? null,
                'diagnostic_recommendation' => $diagnostic->Recommendation ?? null,
                'diagnostic_date' => $diagnostic->DiagnosticDate ?? null,
            ],
            'history' => $history,
            'parts_used' => $partsUsed,
            'estimate' => $estimate,
        ];
    }

    private function vehicleSummary(Vehicle $vehicle): array
    {
        return [
            'plate_number' => $vehicle->PlateNumber,
            'manufacturer' => $vehicle->Manufacturer,
            'model' => $vehicle->Model,
            'year' => $vehicle->Year,
            'owner_name' => $vehicle->customer->FullName ?? null,
            'owner_phone' => $vehicle->customer->Phone ?? null,
            'owner_email' => $vehicle->customer->Email ?? null,
        ];
    }
}
