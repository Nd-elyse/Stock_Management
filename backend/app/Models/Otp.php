<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Otp extends Model
{
    protected $table = 'otps';
    public $timestamps = false;
    protected $fillable = ['UserID', 'Purpose', 'CodeHash', 'Attempts', 'Consumed', 'VerifiedAt', 'ExpiresAt'];

    public function user()
    {
        return $this->belongsTo(User::class, 'UserID', 'UserID');
    }
}
