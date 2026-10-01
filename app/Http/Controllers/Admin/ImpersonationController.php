<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * "Sign in as" for support. Only admins may start it, never towards another
 * admin or a disabled account; the original admin id is kept in the session,
 * both directions are audited and sensitive account actions are blocked while
 * impersonating (see EnsureNotImpersonating).
 */
class ImpersonationController extends Controller
{
    public const SESSION_KEY = 'impersonator_id';

    public function start(Request $request, User $user)
    {
        $admin = $request->user();
        abort_if($request->session()->has(self::SESSION_KEY), 422, __('Stop the current impersonation first.'));
        abort_if($user->is($admin) || $user->isAdmin() || ! $user->is_active, 403, __('This account cannot be impersonated.'));

        AuditLog::record('admin.impersonation_started', $user, ['email' => $user->email]);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $admin->id);
        AuditLog::record('auth.impersonated', $user, ['by' => $admin->id], $user->id);

        return redirect()->route('dashboard')->with('success', __('You are now signed in as :name.', ['name' => $user->name]));
    }

    public function stop(Request $request)
    {
        $adminId = $request->session()->pull(self::SESSION_KEY);
        $admin = $adminId ? User::find($adminId) : null;
        abort_unless($admin && $admin->isAdmin() && $admin->is_active, 403);

        $user = $request->user();
        Auth::login($admin);
        $request->session()->regenerate();
        AuditLog::record('admin.impersonation_stopped', $user, ['email' => $user?->email], $admin->id);

        return redirect()->route('admin.users.index')->with('success', __('Welcome back, :name.', ['name' => $admin->name]));
    }
}
