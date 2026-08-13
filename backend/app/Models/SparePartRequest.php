<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SparePartRequest extends Model
{
    protected $table = 'sparepartrequests';
    protected $primaryKey = 'RequestID';
    public $timestamps = false;
    protected $fillable = ['MechanicID', 'JobID', 'SparePartID', 'QuantityRequested', 'Reason', 'Status', 'DecidedAt', 'DecidedByUserID'];

    public function mechanic()
    {
        return $this->belongsTo(Mechanic::class, 'MechanicID', 'MechanicID');
    }

    public function sparePart()
    {
        return $this->belongsTo(SparePart::class, 'SparePartID', 'SparePartID');
    }

    public function job()
    {
        return $this->belongsTo(RepairJob::class, 'JobID', 'JobID');
    }
}
