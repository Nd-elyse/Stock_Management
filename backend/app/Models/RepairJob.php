<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairJob extends Model
{
    public const WORKFLOW_STATUSES = ['Pending', 'Diagnosed', 'InProgress', 'AwaitingParts', 'Ready', 'Delivered'];
    public const CANCELLED_STATUS = 'Cancelled';

    public const LEGACY_STATUS_MAP = [
        'In Progress' => 'InProgress',
        'Awaiting Parts' => 'AwaitingParts',
        'Completed' => 'Ready',
    ];

    protected $table = 'repairjobs';
    protected $primaryKey = 'JobID';
    public $timestamps = false;
    protected $fillable = ['VehicleID', 'MechanicID', 'UserID', 'Description', 'StartDate', 'EndDate', 'Status'];

    public static function normalizeStatus(?string $status): ?string
    {
        return self::LEGACY_STATUS_MAP[$status] ?? $status;
    }

    public static function allStatuses(): array
    {
        return [...self::WORKFLOW_STATUSES, self::CANCELLED_STATUS];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'VehicleID', 'VehicleID');
    }

    public function mechanic()
    {
        return $this->belongsTo(Mechanic::class, 'MechanicID', 'MechanicID');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'UserID', 'UserID');
    }

    public function diagnostics()
    {
        return $this->hasMany(Diagnostic::class, 'JobID', 'JobID');
    }
}
