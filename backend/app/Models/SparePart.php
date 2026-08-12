<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SparePart extends Model
{
    protected $table = 'spareparts';
    protected $primaryKey = 'SparePartID';
    public $timestamps = false;
    protected $fillable = ['PartName', 'UnitPrice', 'Quantity', 'ReorderLevel', 'CategoryID', 'SupplierID'];

    public function category()
    {
        return $this->belongsTo(Category::class, 'CategoryID', 'CategoryID');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class, 'SupplierID', 'SupplierID');
    }
}
