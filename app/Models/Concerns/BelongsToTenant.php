<?php

namespace App\Models\Concerns;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenancy rules shared by every owned resource: a record belongs to a user and
 * may optionally be shared with a team. Admins see everything.
 */
trait BelongsToTenant
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $teams = $user->teamIds();

        return $query->where(function (Builder $q) use ($user, $teams) {
            $q->where($this->qualifyColumn('user_id'), $user->id);
            if ($teams) {
                $q->orWhereIn($this->qualifyColumn('team_id'), $teams);
            }
        });
    }

    /** Resources the user may edit (own records + teams where they are owner/admin/developer). */
    public function scopeManageableBy(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $teams = $user->manageableTeamIds();

        return $query->where(function (Builder $q) use ($user, $teams) {
            $q->where($this->qualifyColumn('user_id'), $user->id);
            if ($teams) {
                $q->orWhereIn($this->qualifyColumn('team_id'), $teams);
            }
        });
    }

    public function viewableBy(User $user): bool
    {
        return $user->isAdmin()
            || (int) $this->user_id === $user->id
            || ($this->team_id && in_array((int) $this->team_id, $user->teamIds(), true));
    }

    public function manageableBy(User $user): bool
    {
        if (! $user->canWrite()) {
            return false;
        }

        return $user->isAdmin()
            || (int) $this->user_id === $user->id
            || ($this->team_id && in_array((int) $this->team_id, $user->manageableTeamIds(), true));
    }
}
