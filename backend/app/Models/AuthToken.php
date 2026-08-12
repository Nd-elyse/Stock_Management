<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuthToken extends Model
{
    protected $table = 'auth_tokens';
    public $timestamps = false;
    protected $fillable = ['UserID', 'TokenHash', 'LastUsedAt', 'ExpiresAt'];

    public function user()
    {
        return $this->belongsTo(User::class, 'UserID', 'UserID');
    }
}
