<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransaction extends Model
{
    protected $table = 'stocktransactions';
    protected $primaryKey = 'TransactionID';
    public $timestamps = false;
    protected $fillable = [
        'SparePartID', 'TransactionType', 'Quantity', 'TransactionDate',
        'PurchaseID', 'UnitPrice', 'BeforeQty', 'AfterQty', 'UserID',
    ];

    public function sparePart()
    {
        return $this->belongsTo(SparePart::class, 'SparePartID', 'SparePartID');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class, 'PurchaseID', 'PurchaseID');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'UserID', 'UserID');
    }
}
