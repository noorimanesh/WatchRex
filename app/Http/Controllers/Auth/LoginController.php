<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    private const DUMMY_HASH = '$2y$12$P.BK1/BmTC84976LkaGiq.motBPtI6EC2QkbCvfAYLb86I1gYaeeW';

    public function show()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $key = 'login:'.Str::lower($data['email']).'|'.$request->ip();
        $maxAttempts = (int) config('watchrex.security.login_attempts');

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw ValidationException::withMessages([
                'email' => __('Too many login attempts. Try again in :s seconds.', ['s' => RateLimiter::availableIn($key)]),
            ]);
        }

        $user = User::where('email', Str::lower($data['email']))->first();

        // Constant-time path: always run a hash check so response timing does not reveal valid e-mails.
        $valid = $user ? Hash::check($data['password'], $user->password) : Hash::check($data['password'], self::DUMMY_HASH);

        if (! $valid || ! $user->is_active) {
            RateLimiter::hit($key, 300);
            AuditLog::record('auth.failed', $user, ['email' => $data['email']], $user?->id);

            throw ValidationException::withMessages(['email' => __('These credentials do not match our records.')]);
        }

        RateLimiter::clear($key);

        // With SSO enforced, only administrators keep password access (break-glass).
        if (config('watchrex.sso.enabled') && config('watchrex.sso.disable_password_login') && ! $user->isAdmin()) {
            throw ValidationException::withMessages(['email' => __('Please sign in with :p.', ['p' => config('watchrex.sso.label')])]);
        }

        if ($user->hasTwoFactor()) {
            $request->session()->put('2fa:user', $user->id);
            $request->session()->put('2fa:remember', $request->boolean('remember'));

            return redirect()->route('two-factor.challenge');
        }

        return $this->complete($request, $user, $request->boolean('remember'));
    }

    public function complete(Request $request, User $user, bool $remember)
    {
        Auth::login($user, $remember);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        AuditLog::record('auth.login', $user);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request)
    {
        AuditLog::record('auth.logout');
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
