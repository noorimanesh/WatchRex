<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = ApiToken::findValid($request->bearerToken());

        if (! $token) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $request->isMethodSafe() && (! $token->can_write || ! $token->user->canWrite())) {
            return response()->json(['message' => 'This token is read-only.'], 403);
        }

        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);

        return $next($request);
    }
}
