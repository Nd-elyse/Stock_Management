<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactMessage extends Model
{
    protected $table = 'contactmessages';
    protected $primaryKey = 'MessageID';
    public $timestamps = false;
    protected $fillable = ['FullName', 'Email', 'Subject', 'Message', 'IsRead'];
    protected $casts = ['IsRead' => 'boolean'];
}
