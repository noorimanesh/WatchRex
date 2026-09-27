<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Viewer accounts are read-only: block every state-changing request. */
class EnsureCanWrite
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && $request->user() && ! $request->user()->canWrite()
            && ! $request->routeIs('logout', 'profile.*')) {
            abort(403, __('Your account is read-only.'));
        }

        return $next($request);
    }
}
