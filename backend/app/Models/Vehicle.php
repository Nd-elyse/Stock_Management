<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Vehicle extends Model
{
    protected $table = 'vehicles';
    protected $primaryKey = 'VehicleID';
    public $timestamps = false;
    protected $fillable = [
        'CustomerID', 'PlateNumber', 'Manufacturer', 'Model', 'Year',
        'ChassisNumber', 'EngineNumber', 'FuelType', 'Transmission', 'Mileage',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'CustomerID', 'CustomerID');
    }

    public function repairJobs()
    {
        return $this->hasMany(RepairJob::class, 'VehicleID', 'VehicleID');
    }
}
