<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Admin\ImpersonationController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Blocks password, 2FA and API-token changes while an admin is signed in as someone else. */
class EnsureNotImpersonating
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if($request->hasSession() && $request->session()->has(ImpersonationController::SESSION_KEY), 403, __('Not allowed while impersonating.'));

        return $next($request);
    }
}
