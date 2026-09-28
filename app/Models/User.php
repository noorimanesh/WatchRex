<?php

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'plan', 'plan_expires_at', 'max_monitors', 'min_interval', 'is_active', 'locale', 'timezone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @var array<int, string>|null per-request cache of team memberships */
    private ?array $teamRoleCache = null;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'plan_expires_at' => 'datetime',
            'billing_notices' => 'array',
        ];
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function channels(): HasMany
    {
        return $this->hasMany(NotificationChannel::class);
    }

    public function statusPages(): HasMany
    {
        return $this->hasMany(StatusPage::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function maintenanceWindows(): HasMany
    {
        return $this->hasMany(MaintenanceWindow::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)->withPivot('role')->withTimestamps();
    }

    /** @return list<int> */
    public function teamIds(): array
    {
        return array_keys($this->teamRoles());
    }

    /** @return list<int> */
    public function manageableTeamIds(): array
    {
        return array_keys(array_filter($this->teamRoles(), fn ($role) => Team::ROLES[$role][1] ?? false));
    }

    /** @return array<int, string> team id => role */
    public function teamRoles(): array
    {
        return $this->teamRoleCache ??= $this->teams()->get(['teams.id'])
            ->mapWithKeys(fn ($t) => [(int) $t->id => $t->pivot->role])->all();
    }

    public function flushTeamCache(): void
    {
        $this->teamRoleCache = null;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function canWrite(): bool
    {
        return $this->role !== UserRole::Viewer;
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /** Plan limit lookup; per-user overrides win, admins are unlimited. */
    public function limit(string $key): ?int
    {
        if ($this->isAdmin()) {
            return $key === 'min_interval' ? 10 : null;
        }

        if ($key === 'max_monitors' && $this->max_monitors !== null) {
            return $this->max_monitors;
        }

        if ($key === 'min_interval' && $this->min_interval !== null) {
            return $this->min_interval;
        }

        $plans = config('watchrex.plans');

        return ($plans[$this->plan] ?? $plans[config('watchrex.default_plan')])[$key] ?? null;
    }

    public function withinLimit(string $key, int $current): bool
    {
        $limit = $this->limit($key);

        return $limit === null || $current < $limit;
    }
}
