<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class FaceTemplate extends Model
{
    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo { return $this->belongsTo(Employee::class); }
    protected $fillable = ['employee_id', 'embedding', 'model_id', 'model_version', 'active', 'enrolled_at'];
    protected $hidden = ['embedding'];
    protected $casts = ['embedding' => 'encrypted:array', 'active' => 'boolean', 'enrolled_at' => 'datetime'];
}
