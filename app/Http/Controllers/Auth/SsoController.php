<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * OpenID Connect login (authorization code flow + PKCE, state and nonce).
 * Works with Keycloak, Microsoft Entra ID, Google Workspace, Authentik, Okta…
 *
 * The ID token is received directly from the token endpoint over TLS, which lets
 * us rely on TLS for its authenticity (OIDC Core §3.1.3.7); iss/aud/nonce/exp are
 * still validated and the identity comes from the userinfo endpoint.
 */
class SsoController extends Controller
{
    public function redirect(Request $request)
    {
        $cfg = $this->config();
        $discovery = $this->discovery($cfg['issuer']);

        $state = Str::random(40);
        $nonce = Str::random(40);
        $verifier = Str::random(64);
        $request->session()->put('sso', compact('state', 'nonce', 'verifier') + ['remember' => $request->boolean('remember')]);

        return redirect()->away($discovery['authorization_endpoint'].'?'.http_build_query([
            'response_type' => 'code',
            'client_id' => $cfg['client_id'],
            'redirect_uri' => route('sso.callback'),
            'scope' => $cfg['scopes'],
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ]));
    }

    public function callback(Request $request, LoginController $login)
    {
        $cfg = $this->config();
        $flow = $request->session()->pull('sso');

        if (! $flow || ! hash_equals($flow['state'], (string) $request->query('state'))) {
            return $this->fail(__('SSO session expired or invalid state. Please try again.'));
        }
        if ($request->filled('error')) {
            return $this->fail(__('Identity provider returned an error: :e', ['e' => mb_substr((string) $request->query('error_description', $request->query('error')), 0, 200)]));
        }

        try {
            $discovery = $this->discovery($cfg['issuer']);
            $token = Http::asForm()->timeout(15)->post($discovery['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => (string) $request->query('code'),
                'redirect_uri' => route('sso.callback'),
                'client_id' => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'code_verifier' => $flow['verifier'],
            ])->throw()->json();

            $claims = $this->claims((string) ($token['id_token'] ?? ''));
            $audience = (array) ($claims['aud'] ?? []);
            if (rtrim((string) ($claims['iss'] ?? ''), '/') !== rtrim($discovery['issuer'], '/')
                || ! in_array($cfg['client_id'], $audience, true)
                || ! hash_equals($flow['nonce'], (string) ($claims['nonce'] ?? ''))
                || ($claims['exp'] ?? 0) < time() - 60) {
                return $this->fail(__('The identity token could not be validated.'));
            }

            $info = Http::withToken((string) $token['access_token'])->timeout(15)->get($discovery['userinfo_endpoint'])->throw()->json();
        } catch (Throwable $e) {
            report($e);

            return $this->fail(__('Could not complete sign-in with the identity provider.'));
        }

        $sub = (string) ($info['sub'] ?? '');
        $email = strtolower((string) ($info['email'] ?? $info['preferred_username'] ?? ''));
        if ($sub === '' || $sub !== (string) ($claims['sub'] ?? '') || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail(__('The identity provider did not return a verified e-mail address.'));
        }
        if (array_key_exists('email_verified', $info) && ! filter_var($info['email_verified'], FILTER_VALIDATE_BOOLEAN)) {
            return $this->fail(__('Your e-mail address is not verified at the identity provider.'));
        }

        $domain = substr(strrchr($email, '@'), 1);
        if ($cfg['allowed_domains'] && ! in_array($domain, $cfg['allowed_domains'], true)) {
            return $this->fail(__('Accounts from :d are not allowed.', ['d' => $domain]));
        }

        $user = User::where('oidc_sub', $sub)->first() ?? User::where('email', $email)->first();

        if (! $user) {
            if (! $cfg['auto_create']) {
                return $this->fail(__('No WatchRex account exists for :e. Ask an administrator to create it.', ['e' => $email]));
            }
            $user = User::create([
                'name' => (string) ($info['name'] ?? Str::before($email, '@')),
                'email' => $email,
                'password' => Str::password(40),
                'role' => in_array($cfg['default_role'], ['user', 'viewer'], true) ? $cfg['default_role'] : 'user',
                'plan' => config('watchrex.default_plan'),
                'locale' => config('app.locale'),
                'is_active' => true,
            ]);
            AuditLog::record('auth.sso_provisioned', $user, ['email' => $email], $user->id);
        }

        if (! $user->is_active) {
            return $this->fail(__('Your account has been disabled.'));
        }

        if ($user->oidc_sub !== $sub) {
            // An existing sub must never be silently re-bound to another identity.
            if ($user->oidc_sub !== null) {
                return $this->fail(__('This account is linked to a different SSO identity.'));
            }
            $user->forceFill(['oidc_sub' => $sub])->save();
        }

        AuditLog::record('auth.sso', $user, ['email' => $email], $user->id);

        return $login->complete($request, $user, (bool) ($flow['remember'] ?? false));
    }

    private function config(): array
    {
        $cfg = config('watchrex.sso');
        abort_unless($cfg['enabled'] && $cfg['issuer'] && $cfg['client_id'], 404);

        return $cfg;
    }

    private function discovery(string $issuer): array
    {
        return Cache::remember('oidc-discovery:'.md5($issuer), 3600, fn () => Http::timeout(10)
            ->get(rtrim($issuer, '/').'/.well-known/openid-configuration')->throw()->json());
    }

    private function claims(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return [];
        }

        return (array) json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    }

    private function fail(string $message)
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
