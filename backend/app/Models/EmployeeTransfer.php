<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeTransfer extends Model
{
    protected $fillable = [
        'employee_id',
        'from_branch_id',
        'to_branch_id',
        'from_shift_id',
        'to_shift_id',
        'effective_date',
        'note',
        'transferred_by',
    ];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function fromShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'from_shift_id');
    }

    public function toShift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'to_shift_id');
    }

    public function transferredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }
}
