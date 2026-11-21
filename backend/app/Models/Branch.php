<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    public const REGIONS = [
        'north' => 'Miền Bắc',
        'central' => 'Miền Trung',
        'south' => 'Miền Nam',
    ];

    protected $fillable = [
        'code',
        'name',
        'region',
        'address',
        'phone',
        'manager_name',
        'manager_phone',
        'default_shift_id',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function regionLabel(): string
    {
        return self::REGIONS[$this->region] ?? $this->region;
    }

    public function defaultShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'default_shift_id');
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function attendanceEvents(): HasMany
    {
        return $this->hasMany(AttendanceEvent::class);
    }

    public function accountants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branches');
    }
}
