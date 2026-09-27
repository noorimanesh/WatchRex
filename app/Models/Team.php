<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class Team extends Model
{
    /** role => [label, can manage resources, can manage members] */
    public const ROLES = [
        'owner' => ['Owner', true, true],
        'admin' => ['Admin', true, true],
        'developer' => ['Developer', true, false],
        'viewer' => ['Viewer', false, false],
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    public function roleOf(User $user): ?string
    {
        return $this->members->firstWhere('id', $user->id)?->pivot->role;
    }

    public function canManageMembers(User $user): bool
    {
        return $user->isAdmin() || (self::ROLES[$this->roleOf($user)][2] ?? false);
    }
}
