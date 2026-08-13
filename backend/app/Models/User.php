<?php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';
    protected $primaryKey = 'UserID';
    public $timestamps = false;

    protected $fillable = [
        'Username', 'Password', 'Role', 'FullName', 'Email', 'Phone', 'Status', 'MechanicID', 'LastActivity',
    ];

    protected $hidden = ['Password'];

    protected function casts(): array
    {
        return [
            'Password' => 'hashed',
        ];
    }

    // Override so Laravel's password broker / Auth helpers use our column.
    public function getAuthPassword()
    {
        return $this->Password;
    }

    public function mechanic()
    {
        return $this->belongsTo(Mechanic::class, 'MechanicID', 'MechanicID');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class, 'UserID', 'UserID');
    }
}
