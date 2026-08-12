<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mechanic extends Model
{
    protected $table = 'mechanics';
    protected $primaryKey = 'MechanicID';
    public $timestamps = false;
    protected $fillable = ['FullName', 'Phone', 'Specialization', 'Salary'];

    public function user()
    {
        return $this->hasOne(User::class, 'MechanicID', 'MechanicID');
    }

    public function repairJobs()
    {
        return $this->hasMany(RepairJob::class, 'MechanicID', 'MechanicID');
    }
}
