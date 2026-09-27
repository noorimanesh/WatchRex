<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class TwoFactorController extends Controller
{
    public function show(Request $request)
    {
        abort_unless($request->session()->has('2fa:user'), 403);

        return view('auth.two-factor');
    }

    public function verify(Request $request, LoginController $login)
    {
        $userId = $request->session()->get('2fa:user');
        abort_unless($userId, 403);

        $request->validate(['code' => ['required', 'string', 'max:64']]);
        $key = '2fa:'.$userId.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => __('Too many attempts. Try again in :s seconds.', ['s' => RateLimiter::availableIn($key)])]);
        }

        $user = User::findOrFail($userId);
        $code = trim($request->input('code'));
        $ok = Totp::verify($user->two_factor_secret, $code, $user->id);

        if (! $ok) {
            $codes = $user->two_factor_recovery_codes ?? [];
            $index = array_search(strtolower($code), $codes, true);
            if ($index !== false) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                AuditLog::record('auth.recovery_code_used', $user, [], $user->id);
                $ok = true;
            }
        }

        if (! $ok) {
            RateLimiter::hit($key, 300);
            AuditLog::record('auth.2fa_failed', $user, [], $user->id);

            throw ValidationException::withMessages(['code' => __('Invalid authentication code.')]);
        }

        RateLimiter::clear($key);
        $remember = (bool) $request->session()->pull('2fa:remember');
        $request->session()->forget('2fa:user');

        return $login->complete($request, $user, $remember);
    }
}
