<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    public const PORTAL_ROLES = ['admin', 'hr', 'regional_accountant', 'branch_accountant'];

    public const ROLE_LABELS = [
        'admin' => 'Quản trị hệ thống',
        'hr' => 'Nhân sự toàn hệ thống',
        'regional_accountant' => 'Kế toán toàn miền',
        'branch_accountant' => 'Kế toán chi nhánh',
        'viewer' => 'Không có quyền',
    ];

    protected $fillable = ['name', 'email', 'password', 'is_admin', 'role', 'managed_regions', 'active'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'active' => 'boolean',
            'managed_regions' => 'array',
        ];
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branches')->orderBy('code');
    }

    public function effectiveRole(): string
    {
        if (in_array($this->role, self::PORTAL_ROLES, true)) {
            return $this->role;
        }

        return $this->is_admin ? 'admin' : 'viewer';
    }

    public function roleLabel(): string
    {
        return self::ROLE_LABELS[$this->effectiveRole()] ?? $this->effectiveRole();
    }

    public function isActiveAccount(): bool
    {
        return $this->active !== false;
    }

    public function canAccessPortal(): bool
    {
        return $this->isActiveAccount() && in_array($this->effectiveRole(), self::PORTAL_ROLES, true);
    }

    public function isSystemAdmin(): bool
    {
        return $this->isActiveAccount() && $this->effectiveRole() === 'admin';
    }

    public function hasGlobalBranchAccess(): bool
    {
        return $this->isActiveAccount() && in_array($this->effectiveRole(), ['admin', 'hr'], true);
    }

    /**
     * Returns null when user has global access across all branches (including unassigned legacy records),
     * or an explicit array of permitted branch IDs for regional/branch accountants.
     */
    public function accessibleBranchIds(): ?array
    {
        if ($this->hasGlobalBranchAccess()) {
            return null;
        }

        $role = $this->effectiveRole();
        if ($role === 'regional_accountant') {
            $regions = array_values(array_intersect(
                is_array($this->managed_regions) ? $this->managed_regions : [],
                array_keys(Branch::REGIONS)
            ));
            if ($regions === []) {
                return [];
            }

            return Branch::whereIn('region', $regions)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        if ($role === 'branch_accountant') {
            return $this->branches()->pluck('branches.id')->map(fn ($id) => (int) $id)->all();
        }

        return [];
    }

    public function canAccessBranch(?int $branchId): bool
    {
        $allowed = $this->accessibleBranchIds();
        if ($allowed === null) {
            return true;
        }

        return $branchId !== null && in_array((int) $branchId, $allowed, true);
    }

    public function accessibleBranchesQuery()
    {
        $query = Branch::query()->orderBy('region')->orderBy('code');
        $allowed = $this->accessibleBranchIds();
        if ($allowed !== null) {
            $query->whereIn('id', $allowed === [] ? [-1] : $allowed);
        }

        return $query;
    }

    public function scopeLabel(): string
    {
        if ($this->hasGlobalBranchAccess()) {
            return 'Toàn hệ thống';
        }
        if ($this->effectiveRole() === 'regional_accountant') {
            $regions = is_array($this->managed_regions) ? $this->managed_regions : [];
            $labels = array_map(fn ($r) => Branch::REGIONS[$r] ?? $r, $regions);

            return $labels !== [] ? implode(', ', $labels) : 'Chưa gán miền';
        }
        if ($this->effectiveRole() === 'branch_accountant') {
            $names = $this->relationLoaded('branches')
                ? $this->branches->pluck('code')->all()
                : $this->branches()->pluck('code')->all();

            return $names !== [] ? 'CN: ' . implode(', ', $names) : 'Chưa gán chi nhánh';
        }

        return '—';
    }
}
