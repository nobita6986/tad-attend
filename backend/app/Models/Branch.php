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
        'latitude',
        'longitude',
        'allowed_radius_meters',
        'enforce_geofence',
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
            'enforce_geofence' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'allowed_radius_meters' => 'integer',
        ];
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    public function hasEnforcedGeofence(): bool
    {
        return $this->enforce_geofence && $this->hasCoordinates() && (int) $this->allowed_radius_meters > 0;
    }

    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1.0 - $a)));

        return round($earthRadius * $c, 2);
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
