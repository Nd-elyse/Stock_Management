<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Diagnostic;
use App\Models\RepairJob;
use Illuminate\Http\Request;

class RepairJobController extends Controller
{
    private function normalizeJob(RepairJob $job): RepairJob
    {
        $job->Status = RepairJob::normalizeStatus($job->Status);
        return $job;
    }

    private function withNames()
    {
        return RepairJob::with(['vehicle.customer', 'mechanic'])->orderByDesc('JobID')->get()->map(function ($j) {
            $j->CustomerName = $j->vehicle->customer->FullName ?? null;
            $j->PlateNumber = $j->vehicle->PlateNumber ?? null;
            $j->MechanicName = $j->mechanic->FullName ?? null;
            return $this->normalizeJob($j);
        });
    }

    public function index(Request $request)
    {
        if ($request->filled('id')) {
            return $this->show((int) $request->query('id'));
        }

        $user = auth()->user();
        if ($user && $user->Role === 'Mechanic' && $user->MechanicID) {
            $jobs = RepairJob::with(['vehicle.customer', 'mechanic'])
                ->where('MechanicID', $user->MechanicID)
                ->orderByDesc('JobID')->get()
                ->map(function ($j) {
                    $j->CustomerName = $j->vehicle->customer->FullName ?? null;
                    $j->PlateNumber = $j->vehicle->PlateNumber ?? null;
                    return $this->normalizeJob($j);
                });
            return response()->json(['success' => true, 'data' => $jobs]);
        }

        return response()->json(['success' => true, 'data' => $this->withNames()]);
    }

    public function history()
    {
        $user = auth()->user();
        $query = \App\Models\JobHistory::query()->with('mechanic')->orderByDesc('ChangedAt');
        if ($user && $user->Role === 'Mechanic' && $user->MechanicID) {
            $query->whereHas('job', fn ($jobQuery) => $jobQuery->where('MechanicID', $user->MechanicID));
        }

        $records = $query->get()->map(function ($record) {
            $job = RepairJob::with('vehicle')->find($record->JobID);
            return [
                'HistoryID' => $record->HistoryID,
                'JobID' => $record->JobID,
                'VehicleID' => $job?->VehicleID,
                'PreviousStatus' => RepairJob::normalizeStatus($record->PreviousStatus),
                'NewStatus' => RepairJob::normalizeStatus($record->NewStatus),
                'MechanicName' => $record->MechanicName,
                'ChangedAt' => $record->ChangedAt,
                'Notes' => $record->Notes ?: "Status changed to {$record->NewStatus}.",
            ];
        });

        return response()->json(['success' => true, 'data' => $records]);
    }

    public function destroyHistory(int $id)
    {
        $record = \App\Models\JobHistory::find($id);
        if (!$record) {
            return response()->json(['success' => false, 'message' => 'History record not found.'], 404);
        }

        $user = auth()->user();
        $isAdmin = $user && $user->Role === 'Admin';
        $ownsAssignedJob = $user && $user->Role === 'Mechanic'
            && $user->MechanicID
            && RepairJob::where('JobID', $record->JobID)->where('MechanicID', $user->MechanicID)->exists();

        if (!$isAdmin && !$ownsAssignedJob) {
            return response()->json(['success' => false, 'message' => 'You are not authorized to delete this history record.'], 403);
        }

        return $this->safeDelete($record, 'history record');
    }

    public function show(int $id)
    {
        if ($id <= 0) {
            return response()->json(['success' => false, 'message' => 'Invalid job ID.'], 422);
        }

        $job = RepairJob::with(['vehicle.customer', 'mechanic', 'diagnostics'])->find($id);
        if (!$job) {
            return response()->json(['success' => false, 'message' => 'Job not found.'], 404);
        }

        $job->CustomerName = $job->vehicle?->customer?->FullName ?? null;
        $job->PlateNumber = $job->vehicle?->PlateNumber ?? null;
        $job->MechanicName = $job->mechanic?->FullName ?? null;

        return response()->json(['success' => true, 'data' => $this->normalizeJob($job)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'vehicle_id' => 'required|integer|exists:vehicles,VehicleID',
            'mechanic_id' => 'nullable|integer|exists:mechanics,MechanicID',
            'description' => 'nullable|string|max:2000',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'status' => ['nullable', 'string', 'in:' . implode(',', [...RepairJob::allStatuses(), ...array_keys(RepairJob::LEGACY_STATUS_MAP)])],
        ]);

        $requestedStatus = RepairJob::normalizeStatus($data['status'] ?? 'Pending');
        if (!in_array($requestedStatus, RepairJob::allStatuses(), true)) {
            return response()->json(['success' => false, 'message' => 'Invalid repair job status.'], 422);
        }

        $openStatuses = [...array_slice(RepairJob::WORKFLOW_STATUSES, 0, -2), 'In Progress', 'Awaiting Parts'];
        $existingOpenJob = RepairJob::where('VehicleID', $data['vehicle_id'])
            ->whereIn('Status', $openStatuses)
            ->first();
        if ($existingOpenJob) {
            return response()->json([
                'success' => false,
                'message' => "This vehicle already has an open repair job (#{$existingOpenJob->JobID}, status: {$existingOpenJob->Status}). Complete or cancel it before creating a new one.",
            ], 422);
        }

        $job = RepairJob::create([
            'VehicleID' => $data['vehicle_id'],
            'MechanicID' => $data['mechanic_id'] ?? null,
            'UserID' => auth()->id(),
            'Description' => $data['description'] ?? null,
            'StartDate' => $data['start_date'] ?? now()->toDateString(),
            'EndDate' => $data['end_date'] ?? null,
            'Status' => $requestedStatus,
        ]);
        $mechanic = $job->MechanicID ? \App\Models\Mechanic::find($job->MechanicID) : null;
        \App\Models\JobHistory::log($job->JobID, null, $job->Status, $job->MechanicID, $mechanic->FullName ?? null, auth()->id(), $job->Description ?: 'Repair job created.');
        return response()->json(['success' => true, 'message' => 'Repair job created.', 'data' => $this->normalizeJob($job)]);
    }

    public function update(Request $request, int $id)
    {
        $job = RepairJob::find($id);
        if (!$job) {
            return response()->json(['success' => false, 'message' => 'Job not found.'], 404);
        }
        $data = $request->validate([
            'vehicle_id' => 'sometimes|integer|exists:vehicles,VehicleID',
            'mechanic_id' => 'nullable|integer|exists:mechanics,MechanicID',
            'description' => 'sometimes|nullable|string|max:2000',
            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date',
            'status' => ['sometimes', 'string'],
        ]);
        if (array_key_exists('status', $data)) {
            $data['status'] = RepairJob::normalizeStatus($data['status']);
            if (!in_array($data['status'], RepairJob::allStatuses(), true)) {
                return response()->json(['success' => false, 'message' => 'Invalid repair job status.'], 422);
            }
        }
        $previousStatus = $job->Status;
        $job->fill(array_filter([
            'VehicleID' => $data['vehicle_id'] ?? null,
            'MechanicID' => array_key_exists('mechanic_id', $data) ? $data['mechanic_id'] : null,
            'Description' => array_key_exists('description', $data) ? $data['description'] : null,
            'StartDate' => $data['start_date'] ?? null,
            'EndDate' => array_key_exists('end_date', $data) ? $data['end_date'] : null,
            'Status' => $data['status'] ?? null,
        ], fn ($v) => $v !== null));
        $job->save();

        if (isset($data['status']) && $data['status'] !== $previousStatus) {
            $mechanic = $job->MechanicID ? \App\Models\Mechanic::find($job->MechanicID) : null;
            \App\Models\JobHistory::log($job->JobID, $previousStatus, $data['status'], $job->MechanicID, $mechanic->FullName ?? null, auth()->id(), $job->Description ?: "Status updated to {$data['status']}.");
        }

        return response()->json(['success' => true, 'message' => 'Job updated.', 'data' => $this->normalizeJob($job)]);
    }

    public function next(int $id)
    {
        $job = RepairJob::find($id);
        if (!$job) return response()->json(['success' => false, 'message' => 'Job not found.'], 404);

        $current = RepairJob::normalizeStatus($job->Status);
        $index = array_search($current, RepairJob::WORKFLOW_STATUSES, true);
        if ($current === RepairJob::CANCELLED_STATUS) {
            return response()->json(['success' => false, 'message' => 'Cancelled jobs cannot continue through the workflow.'], 422);
        }
        if ($index === false || $index === count(RepairJob::WORKFLOW_STATUSES) - 1) {
            return response()->json(['success' => false, 'message' => 'This job is already at the final workflow status.'], 422);
        }

        return $this->setStatus($job, RepairJob::WORKFLOW_STATUSES[$index + 1], 'Job advanced to the next status.');
    }

    public function cancel(int $id)
    {
        $job = RepairJob::find($id);
        if (!$job) return response()->json(['success' => false, 'message' => 'Job not found.'], 404);
        if (RepairJob::normalizeStatus($job->Status) === RepairJob::CANCELLED_STATUS) {
            return response()->json(['success' => false, 'message' => 'This job is already cancelled.'], 422);
        }
        return $this->setStatus($job, RepairJob::CANCELLED_STATUS, 'Repair job cancelled.');
    }

    private function setStatus(RepairJob $job, string $status, string $message)
    {
        $previousStatus = RepairJob::normalizeStatus($job->Status);
        $job->Status = $status;
        $job->save();
        $mechanic = $job->MechanicID ? \App\Models\Mechanic::find($job->MechanicID) : null;
        \App\Models\JobHistory::log($job->JobID, $previousStatus, $status, $job->MechanicID, $mechanic->FullName ?? null, auth()->id(), $message);
        return response()->json(['success' => true, 'message' => $message, 'data' => $this->normalizeJob($job)]);
    }

    public function destroy(int $id)
    {
        $job = RepairJob::find($id);
        if (!$job) {
            return response()->json(['success' => false, 'message' => 'Job not found.'], 404);
        }
        return $this->safeDelete($job, 'job');
    }

    public function diagnostics(Request $request, int $jobId)
    {
        if ($request->isMethod('get')) {
            $diag = Diagnostic::with('mechanic')->where('JobID', $jobId)->orderByDesc('DiagnosticID')->first();
            return response()->json(['success' => true, 'data' => $diag]);
        }

        $data = $request->validate(['notes' => 'required|string|min:5']);
        $job = RepairJob::find($jobId);
        if (!$job) {
            return response()->json(['success' => false, 'message' => 'Job not found.'], 404);
        }

        $user = auth()->user();
        if (!$user->MechanicID) {
            return response()->json(['success' => false, 'message' => 'Only mechanics can record diagnostics.'], 403);
        }
        if ((int) $job->MechanicID !== (int) $user->MechanicID) {
            return response()->json(['success' => false, 'message' => 'This job is not assigned to you.'], 403);
        }

        $diagnostic = Diagnostic::create([
            'JobID' => $jobId,
            'MechanicID' => $user->MechanicID,
            'DiagnosticDate' => now()->toDateString(),
            'Notes' => $data['notes'],
        ]);
        return response()->json(['success' => true, 'message' => 'Diagnostics saved successfully.', 'data' => $diagnostic]);
    }
}
