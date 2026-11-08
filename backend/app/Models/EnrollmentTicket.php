<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class EnrollmentTicket extends Model
{
    protected $fillable = ['employee_id', 'token_hash', 'created_by', 'expires_at', 'consumed_at'];
    protected $hidden = ['token_hash'];
    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
