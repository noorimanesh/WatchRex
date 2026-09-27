<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

abstract class Controller
{
    /**
     * Tenancy guard. Reading requires view access (owner, team member or admin);
     * any state-changing request requires manage access. Unknown records → 404.
     */
    protected function authorizeOwner(Model $model): void
    {
        $user = auth()->user();
        abort_unless($user && $model->viewableBy($user), 404);

        if (! request()->isMethodSafe()) {
            abort_unless($model->manageableBy($user), 403);
        }
    }

    /** Validation rule for an optional team_id the user may assign resources to. */
    protected function teamRule(): array
    {
        $user = auth()->user();
        $ids = $user->isAdmin() ? Team::pluck('id')->all() : $user->manageableTeamIds();

        return ['nullable', 'integer', Rule::in($ids)];
    }

    /** Teams offered in resource forms. */
    protected function assignableTeams()
    {
        $user = auth()->user();

        return $user->isAdmin() ? Team::orderBy('name')->get() : Team::whereIn('id', $user->manageableTeamIds())->orderBy('name')->get();
    }
}
