<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';
    protected $primaryKey = 'CustomerID';
    public $timestamps = false;
    protected $fillable = ['FullName', 'Phone', 'Email', 'Address', 'RegistrationDate'];

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class, 'CustomerID', 'CustomerID');
    }
}
