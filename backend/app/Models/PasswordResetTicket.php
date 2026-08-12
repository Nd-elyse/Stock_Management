<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetTicket extends Model
{
    protected $table = 'password_reset_tickets';
    protected $primaryKey = 'RequestID';
    public $timestamps = false;
    protected $fillable = ['Username', 'Note', 'Status', 'ResolvedAt'];
}
