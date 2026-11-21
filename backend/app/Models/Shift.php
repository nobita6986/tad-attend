<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Shift extends Model
{
    protected $fillable = ['branch_id', 'code', 'name', 'starts_at', 'ends_at', 'break_minutes', 'grace_minutes', 'weekdays'];
    protected $casts = ['weekdays' => 'array'];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
