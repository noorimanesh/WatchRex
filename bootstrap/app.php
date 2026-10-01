<?php

use App\Http\Middleware\AuthenticateApiToken;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureCanWrite;
use App\Http\Middleware\EnsureNotImpersonating;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\ResolveCustomDomain;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '127.0.0.1'));
        $middleware->append(SecurityHeaders::class);
        $middleware->web(prepend: [ResolveCustomDomain::class], append: [
            SetLocale::class,
            EnsureUserIsActive::class,
            EnsureCanWrite::class,
        ]);
        // Subscriptions may be posted from custom status-page domains (no shared session).
        $middleware->validateCsrfTokens(except: ['status/*/subscribe']);
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'api.token' => AuthenticateApiToken::class,
            'not-impersonating' => EnsureNotImpersonating::class,
        ]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('dashboard'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
