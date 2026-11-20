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

    /**
     * Suggest the next unique employee code based on the company's existing numbering pattern
     * (e.g. 00001 -> 00002, NV009 -> NV010, TAD-001 -> TAD-002), falling back to NV001.
     */
    public static function suggestNextCode(): string
    {
        $codes = self::orderByDesc('id')->pluck('code')->all();

        $prefix = 'NV';
        $padLength = 3;
        $foundPattern = false;

        foreach ($codes as $existing) {
            $trimmed = trim((string) $existing);
            if (preg_match('/^(.*?)(\d+)$/', $trimmed, $m) === 1) {
                $prefix = $m[1];
                $padLength = max(strlen($m[2]), 1);
                $foundPattern = true;
                break;
            }
        }

        $maxNum = 0;
        if ($foundPattern) {
            $regex = '/^' . preg_quote($prefix, '/') . '(\d+)$/';
            foreach ($codes as $existing) {
                if (preg_match($regex, trim((string) $existing), $m) === 1) {
                    $num = (int) $m[1];
                    if ($num > $maxNum) {
                        $maxNum = $num;
                    }
                }
            }
        }

        $existingSet = array_flip(array_map(fn ($c) => strtoupper(trim((string) $c)), $codes));
        $next = $maxNum + 1;
        do {
            $candidate = $prefix . str_pad((string) $next, $padLength, '0', STR_PAD_LEFT);
            $next++;
        } while (isset($existingSet[strtoupper($candidate)]));

        return $candidate;
    }
}
