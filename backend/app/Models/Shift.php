<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Shift extends Model
{
    protected $fillable = ['code', 'name', 'starts_at', 'ends_at', 'break_minutes', 'grace_minutes', 'weekdays'];
    protected $casts = ['weekdays' => 'array'];
}
