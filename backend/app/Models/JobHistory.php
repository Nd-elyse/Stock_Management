<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class JobHistory extends Model
{
    protected $table = 'jobhistory';
    protected $primaryKey = 'HistoryID';
    public $incrementing = false;
    public $timestamps = false;
    protected $fillable = ['HistoryID', 'JobID', 'PreviousStatus', 'NewStatus', 'MechanicID', 'MechanicName', 'ChangedByUserID', 'ChangedAt', 'Notes'];

    public function mechanic()
    {
        return $this->belongsTo(Mechanic::class, 'MechanicID', 'MechanicID');
    }

    public function job()
    {
        return $this->belongsTo(RepairJob::class, 'JobID', 'JobID');
    }

    /** HistoryID has no DB-level auto-increment, so assign the next one ourselves. */
    public static function log(int $jobId, ?string $previousStatus, string $newStatus, ?int $mechanicId, ?string $mechanicName, ?int $changedByUserId, ?string $notes = null): self
    {
        $nextId = (int) (DB::table('jobhistory')->max('HistoryID') ?? 0) + 1;
        return self::create([
            'HistoryID' => $nextId,
            'JobID' => $jobId,
            'PreviousStatus' => $previousStatus,
            'NewStatus' => $newStatus,
            'MechanicID' => $mechanicId,
            'MechanicName' => $mechanicName,
            'ChangedByUserID' => $changedByUserId,
            'ChangedAt' => now(),
            'Notes' => $notes ?: "Status changed to {$newStatus}.",
        ]);
    }
}
