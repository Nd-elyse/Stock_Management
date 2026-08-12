<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockTransaction extends Model
{
    protected $table = 'stocktransactions';
    protected $primaryKey = 'TransactionID';
    public $timestamps = false;
    protected $fillable = ['SparePartID', 'UserID', 'TransactionType', 'Quantity', 'BeforeQty', 'AfterQty', 'Reference'];

    public function sparePart()
    {
        return $this->belongsTo(SparePart::class, 'SparePartID', 'SparePartID');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'UserID', 'UserID');
    }
}
