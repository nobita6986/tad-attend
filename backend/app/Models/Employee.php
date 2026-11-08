<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    protected $fillable = ['code', 'full_name', 'gender', 'date_of_birth', 'employed_from', 'employed_until', 'address', 'department', 'shift_id', 'active', 'consent_at', 'consent_method'];
    protected $casts = ['active' => 'boolean', 'date_of_birth' => 'date', 'employed_from' => 'date', 'employed_until' => 'date', 'consent_at' => 'datetime'];
    public function shift(): BelongsTo { return $this->belongsTo(Shift::class); }
    public function shiftAssignments(): HasMany { return $this->hasMany(ShiftAssignment::class); }
    public function templates(): HasMany { return $this->hasMany(FaceTemplate::class); }
    public function events(): HasMany { return $this->hasMany(AttendanceEvent::class); }
}
