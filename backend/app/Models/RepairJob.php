<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RepairJob extends Model
{
    protected $table = 'repairjobs';
    protected $primaryKey = 'JobID';
    public $timestamps = false;
    protected $fillable = ['VehicleID', 'MechanicID', 'UserID', 'Description', 'StartDate', 'EndDate', 'Status'];

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
