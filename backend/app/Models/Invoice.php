<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $table = 'invoices';
    protected $primaryKey = 'InvoiceID';
    public $timestamps = false;
    protected $fillable = [
        'CustomerID', 'VehicleID', 'JobID', 'InvoiceDate',
        'LabourCharges', 'SparePartsCost', 'Taxes', 'Discounts', 'TaxRate', 'DiscountRate', 'TotalAmount',
    ];

    public function items()
    {
        return $this->hasMany(InvoiceItem::class, 'InvoiceID', 'InvoiceID');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class, 'CustomerID', 'CustomerID');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class, 'VehicleID', 'VehicleID');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'InvoiceID', 'InvoiceID');
    }
}
