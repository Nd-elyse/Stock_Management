<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $table = 'suppliers';
    protected $primaryKey = 'SupplierID';
    public $timestamps = false;
    protected $fillable = ['CompanyName', 'Phone', 'Email', 'Address'];

    public function spareParts()
    {
        return $this->hasMany(SparePart::class, 'SupplierID', 'SupplierID');
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class, 'SupplierID', 'SupplierID');
    }
}
