<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class Device extends Model
{
    use HasUuids;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = [
        'branch_id',
        'name',
        'hardware_id',
        'device_model',
        'direction',
        'token_hash',
        'active',
        'approval_status',
        'last_seen_at',
        'requested_at',
    ];
    protected $hidden = ['token_hash'];
    protected $casts = [
        'active' => 'boolean',
        'last_seen_at' => 'datetime',
        'requested_at' => 'datetime',
    ];
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
}
