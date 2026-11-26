<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
/** Human editorial identity. Administrator is a separate privilege from curator role.
 * Active status is rechecked on actions so disabled accounts lose write access. */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['active' => true, 'is_admin' => false];

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->active ?? true;
    }

    public function isCurator(): bool
    {
        return ($this->active ?? true) && ($this->role === UserRole::Curator || $this->is_admin);
    }

    public function canManageAccounts(): bool
    {
        return ($this->active ?? true) && (bool) $this->is_admin;
    }

    public function accessLabel(): string
    {
        return $this->is_admin ? 'Administrator' : ($this->role === UserRole::Curator ? 'Redaktionsleitung' : 'Autor');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'active' => 'boolean', 'is_admin' => 'boolean',
        ];
    }
}
