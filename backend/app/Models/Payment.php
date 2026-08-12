<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $table = 'payments';
    protected $primaryKey = 'PaymentID';
    public $timestamps = false;
    protected $fillable = ['InvoiceID', 'Amount', 'PaymentMethod', 'PaymentStatus', 'PaymentDate'];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class, 'InvoiceID', 'InvoiceID');
    }
}
