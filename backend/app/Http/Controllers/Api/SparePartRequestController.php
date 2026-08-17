<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SparePart;
use App\Models\SparePartRequest;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SparePartRequestController extends Controller
{
    public function index()
    {
        $user = auth()->user();
        $query = SparePartRequest::with(['mechanic', 'sparePart', 'job.vehicle']);
        if ($user && $user->Role === 'Mechanic' && $user->MechanicID) {
            $query->where('MechanicID', $user->MechanicID);
        }
        $rows = $query->orderByDesc('RequestID')->get()->map(function ($r) {
            $r->MechanicName = $r->mechanic->FullName ?? null;
            $r->SparePartName = $r->sparePart->PartName ?? null;
            $r->JobPlate = $r->job->vehicle->PlateNumber ?? null;
            return $r;
        });
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request)
    {
        $user = auth()->user();
        if (!$user->MechanicID) {
            return response()->json(['success' => false, 'message' => 'Only mechanics can request parts.'], 403);
        }
        $data = $request->validate([
            'spare_part_id' => 'required|integer|exists:spareparts,SparePartID',
            'quantity_requested' => 'required|integer|min:1',
            'reason' => 'required|string|min:5',
            'job_id' => 'nullable|integer|exists:repairjobs,JobID',
        ]);
        $reqRow = SparePartRequest::create([
            'MechanicID' => $user->MechanicID,
            'JobID' => $data['job_id'] ?? null,
            'SparePartID' => $data['spare_part_id'],
            'QuantityRequested' => $data['quantity_requested'],
            'Reason' => $data['reason'],
            'Status' => 'Pending',
        ]);
        $partName = SparePart::find($data['spare_part_id'])->PartName ?? 'a part';
        $this->notifyRole('Stock Manager', 'part_request', "New spare part request #{$reqRow->RequestID} for {$partName} (x{$data['quantity_requested']})", '#requests');
        return response()->json(['success' => true, 'message' => 'Request submitted successfully.', 'data' => $reqRow]);
    }

    public function approve(int $id)
    {
        $reqRow = SparePartRequest::with('sparePart')->find($id);
        if (!$reqRow) return response()->json(['success' => false, 'message' => 'Request not found.'], 404);
        if ($reqRow->Status !== 'Pending') return response()->json(['success' => false, 'message' => 'Request already processed.'], 422);

        $part = $reqRow->sparePart;
        if (!$part || $part->Quantity < $reqRow->QuantityRequested) {
            return response()->json(['success' => false, 'message' => 'Not enough stock to fulfil this request.'], 422);
        }

        return DB::transaction(function () use ($reqRow, $part) {
            $before = $part->Quantity;
            $after = $before - $reqRow->QuantityRequested;
            $part->Quantity = $after;
            $part->save();

            StockTransaction::create([
                'SparePartID' => $part->SparePartID,
                'UserID' => auth()->id(),
                'TransactionType' => 'Usage',
                'Quantity' => $reqRow->QuantityRequested,
                'TransactionDate' => now()->toDateString(),
                'UnitPrice' => $part->UnitPrice,
                'BeforeQty' => $before,
                'AfterQty' => $after,
            ]);

            $reqRow->Status = 'Fulfilled';
            $reqRow->DecidedAt = now()->toDateString();
            $reqRow->DecidedByUserID = auth()->id();
            $reqRow->save();

            $mechanicUserId = \App\Models\User::where('MechanicID', $reqRow->MechanicID)->value('UserID');
            $this->notifyUser($mechanicUserId, 'part_request', "Your spare part request #{$reqRow->RequestID} has been approved and fulfilled.", '#requests');

            return response()->json(['success' => true, 'message' => 'Request approved and stock updated.', 'data' => $reqRow]);
        });
    }

    public function reject(Request $request, int $id)
    {
        $reqRow = SparePartRequest::find($id);
        if (!$reqRow) return response()->json(['success' => false, 'message' => 'Request not found.'], 404);
        $data = $request->validate(['reason' => 'nullable|string']);
        $reqRow->Status = 'Rejected';
        if (!empty($data['reason'])) {
            $reqRow->Reason = $reqRow->Reason . ' | Rejected: ' . $data['reason'];
        }
        $reqRow->DecidedAt = now()->toDateString();
        $reqRow->DecidedByUserID = auth()->id();
        $reqRow->save();

        $mechanicUserId = \App\Models\User::where('MechanicID', $reqRow->MechanicID)->value('UserID');
        $reasonText = !empty($data['reason']) ? " Reason: {$data['reason']}" : '';
        $this->notifyUser($mechanicUserId, 'part_request', "Your spare part request #{$reqRow->RequestID} has been rejected.{$reasonText}", '#requests');

        return response()->json(['success' => true, 'message' => 'Request rejected.', 'data' => $reqRow]);
    }

    public function destroy(int $id)
    {
        $reqRow = SparePartRequest::find($id);
        if (!$reqRow) return response()->json(['success' => false, 'message' => 'Request not found.'], 404);

        $user = auth()->user();
        if ($user && $user->Role === 'Mechanic' && (int) $reqRow->MechanicID !== (int) $user->MechanicID) {
            return response()->json(['success' => false, 'message' => 'You can only cancel your own requests.'], 403);
        }

        return $this->safeDelete($reqRow, 'request');
    }
}
