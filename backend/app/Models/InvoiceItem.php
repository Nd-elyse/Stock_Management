<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $table = 'invoiceitems';
    protected $primaryKey = 'InvoiceItemID';
    public $timestamps = false;
    protected $fillable = ['InvoiceID', 'SparePartID', 'Quantity', 'Price'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'InvoiceID', 'InvoiceID');
    }

    public function sparePart()
    {
        return $this->belongsTo(SparePart::class, 'SparePartID', 'SparePartID');
    }
}
