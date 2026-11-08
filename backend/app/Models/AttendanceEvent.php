<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class AttendanceEvent extends Model
{
    protected $fillable = ['event_uuid', 'employee_id', 'device_id', 'captured_at', 'received_at', 'direction', 'review_state', 'is_offline', 'match_score', 'liveness_method', 'model_id', 'model_version', 'image_path'];
    protected $casts = ['captured_at' => 'datetime', 'received_at' => 'datetime', 'is_offline' => 'boolean', 'match_score' => 'float'];
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function device(): BelongsTo { return $this->belongsTo(Device::class); }
}
