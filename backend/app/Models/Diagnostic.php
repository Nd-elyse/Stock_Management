<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Diagnostic extends Model
{
    protected $table = 'diagnostics';
    protected $primaryKey = 'DiagnosticID';
    public $timestamps = false;
    protected $fillable = ['JobID', 'Notes', 'Recommendation', 'EstimatedCost'];

    public function job()
    {
        return $this->belongsTo(RepairJob::class, 'JobID', 'JobID');
    }
}
