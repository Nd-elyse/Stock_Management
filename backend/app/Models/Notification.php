<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $table = 'notifications';
    protected $primaryKey = 'NotificationID';
    public $timestamps = false;
    protected $fillable = ['UserID', 'Type', 'Message', 'Link', 'IsRead'];
    protected $casts = ['IsRead' => 'boolean'];

    public function user()
    {
        return $this->belongsTo(User::class, 'UserID', 'UserID');
    }
}
