<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\AuditLog;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        $user = $request->user();
        $pendingSecret = $request->session()->get('2fa:pending');

        return view('profile.edit', [
            'user' => $user,
            'tokens' => $user->apiTokens()->latest()->get(),
            'qr' => $pendingSecret ? Totp::qrSvg(Totp::uri($pendingSecret, $user->email)) : null,
            'pendingSecret' => $pendingSecret,
            'recoveryCodes' => session('recovery_codes'),
            'newToken' => session('new_token'),
            'logins' => AuditLog::where('user_id', $user->id)->whereIn('action', ['auth.login', 'auth.failed', 'auth.2fa_failed'])->latest('id')->limit(10)->get(),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'locale' => ['required', Rule::in(array_keys(config('watchrex.locales')))],
            'timezone' => ['required', 'timezone:all'],
        ]);
        $data['email'] = strtolower($data['email']);
        $user->update($data);
        AuditLog::record('profile.updated');

        return back()->with('success', __('Profile saved.'));
    }

    public function password(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(10)->mixedCase()->numbers()],
        ]);

        $request->user()->update(['password' => $request->input('password')]);
        Auth::logoutOtherDevices($request->input('password'));
        AuditLog::record('profile.password_changed');

        return back()->with('success', __('Password changed. Other sessions were signed out.'));
    }

    public function enableTwoFactor(Request $request)
    {
        $request->session()->put('2fa:pending', Totp::generateSecret());

        return redirect()->route('profile.edit')->withFragment('security');
    }

    public function confirmTwoFactor(Request $request)
    {
        $request->validate(['code' => ['required', 'string', 'max:10']]);
        $secret = $request->session()->get('2fa:pending');

        if (! $secret || ! Totp::verify($secret, $request->input('code'))) {
            return back()->withErrors(['code' => __('Invalid authentication code.')]);
        }

        $codes = Totp::recoveryCodes();
        $request->user()->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();
        $request->session()->forget('2fa:pending');
        AuditLog::record('auth.2fa_enabled');

        return redirect()->route('profile.edit')->with('recovery_codes', $codes)->with('success', __('Two-factor authentication enabled.'));
    }

    public function disableTwoFactor(Request $request)
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $request->user()->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_recovery_codes' => null])->save();
        AuditLog::record('auth.2fa_disabled');

        return back()->with('success', __('Two-factor authentication disabled.'));
    }

    public function createToken(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'can_write' => ['nullable', 'boolean'],
            'expires_days' => ['nullable', 'integer', 'between:1,3650'],
        ]);

        [$token, $plain] = ApiToken::issue($request->user(), $data['name'], $request->boolean('can_write') && $request->user()->canWrite(), isset($data['expires_days']) ? now()->addDays((int) $data['expires_days']) : null);
        AuditLog::record('api_token.created', $token, ['name' => $token->name]);

        return back()->with('new_token', $plain)->withFragment('api');
    }

    public function deleteToken(Request $request, ApiToken $token)
    {
        abort_unless($token->user_id === $request->user()->id, 404);
        AuditLog::record('api_token.revoked', $token, ['name' => $token->name]);
        $token->delete();

        return back()->with('success', __('Token revoked.'))->withFragment('api');
    }
}
