<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $table = 'categories';
    protected $primaryKey = 'CategoryID';
    public $timestamps = false;
    protected $fillable = ['CategoryName', 'Description'];

    public function spareParts()
    {
        return $this->hasMany(SparePart::class, 'CategoryID', 'CategoryID');
    }
}
