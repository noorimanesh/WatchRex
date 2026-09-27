<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locales = array_keys(config('watchrex.locales'));

        if ($request->has('lang') && in_array($request->query('lang'), $locales, true)) {
            $request->session()->put('locale', $request->query('lang'));
        }

        $locale = $request->user()?->locale ?? $request->session()->get('locale') ?? config('app.locale');

        app()->setLocale(in_array($locale, $locales, true) ? $locale : 'en');

        return $next($request);
    }
}
