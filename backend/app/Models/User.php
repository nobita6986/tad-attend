<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_admin', 'role', 'active'];
    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function effectiveRole(): string
    {
        if (in_array($this->role, ['admin', 'hr'], true)) {
            return $this->role;
        }

        return $this->is_admin ? 'admin' : 'viewer';
    }

    public function isActiveAccount(): bool
    {
        return $this->active !== false;
    }

    public function canAccessPortal(): bool
    {
        return $this->isActiveAccount() && in_array($this->effectiveRole(), ['admin', 'hr'], true);
    }

    public function isSystemAdmin(): bool
    {
        return $this->isActiveAccount() && $this->effectiveRole() === 'admin';
    }
}
