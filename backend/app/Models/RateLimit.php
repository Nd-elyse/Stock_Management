<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;


class RateLimit extends Model
{
    protected $table = 'rate_limits';
    public $timestamps = false;

    protected $fillable = [
        'identifier', 'endpoint', 'attempt_count', 'first_attempt', 'last_attempt', 'blocked_until',
    ];

    protected function casts(): array
    {
        return [
            'first_attempt' => 'datetime',
            'last_attempt' => 'datetime',
            'blocked_until' => 'datetime',
        ];
    }
}
