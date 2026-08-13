<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Diagnostic extends Model
{
    protected $table = 'diagnostics';
    protected $primaryKey = 'DiagnosticID';
    public $timestamps = false;
    protected $fillable = ['JobID', 'MechanicID', 'DiagnosticDate', 'Notes', 'Recommendation', 'EstimatedCost'];

    public function job()
    {
        return $this->belongsTo(RepairJob::class, 'JobID', 'JobID');
    }

    public function mechanic()
    {
        return $this->belongsTo(Mechanic::class, 'MechanicID', 'MechanicID');
    }
}
