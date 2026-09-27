<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;

abstract class Controller
{
    /** Tenancy guard: users only reach their own records, admins reach everything. */
    protected function authorizeOwner(Model $model): void
    {
        $user = auth()->user();

        abort_unless($user && ($user->isAdmin() || (int) $model->user_id === (int) $user->id), 404);
    }
}
