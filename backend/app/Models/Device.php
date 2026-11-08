<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class Device extends Model
{
    use HasUuids;
    public $incrementing = false;
    protected $keyType = 'string';
    protected $fillable = ['name', 'direction', 'token_hash', 'active', 'last_seen_at'];
    protected $hidden = ['token_hash'];
    protected $casts = ['active' => 'boolean', 'last_seen_at' => 'datetime'];
}
