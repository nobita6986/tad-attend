<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceEvent extends Model
{
    protected $fillable = ['event_uuid', 'employee_id', 'device_id', 'branch_id', 'captured_at', 'received_at', 'direction', 'review_state', 'is_offline', 'match_score', 'liveness_method', 'model_id', 'model_version', 'image_path', 'latitude', 'longitude', 'gps_accuracy_meters', 'distance_meters'];
    protected $casts = ['captured_at' => 'datetime', 'received_at' => 'datetime', 'is_offline' => 'boolean', 'match_score' => 'float', 'latitude' => 'float', 'longitude' => 'float', 'gps_accuracy_meters' => 'float', 'distance_meters' => 'float'];
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
}
