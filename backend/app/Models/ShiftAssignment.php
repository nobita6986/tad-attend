<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class ShiftAssignment extends Model
{
    protected $fillable = ['employee_id', 'shift_id', 'effective_from', 'effective_to'];
    protected $casts = ['effective_from' => 'date', 'effective_to' => 'date'];
    public function shift(): BelongsTo { return $this->belongsTo(Shift::class); }
}
