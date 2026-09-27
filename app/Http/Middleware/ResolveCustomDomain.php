<?php

namespace App\Http\Middleware;

use App\Http\Controllers\PublicStatusController;
use App\Models\StatusPage;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/** Serves a public status page when the request arrives on its custom domain (status.company.com). */
class ResolveCustomDomain
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = strtolower($request->getHost());
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));

        if ($host === $appHost || $host === 'localhost' || filter_var($host, FILTER_VALIDATE_IP)) {
            return $next($request);
        }

        $pageId = Cache::remember("status-domain:{$host}", 300, fn () => StatusPage::where('custom_domain', $host)->value('id') ?? 0);

        if (! $pageId) {
            return $next($request);
        }

        $page = StatusPage::find($pageId);
        $controller = app(PublicStatusController::class);

        return match (trim($request->path(), '/')) {
            '' => $controller->render($page),
            'json' => $controller->renderJson($page),
            'rss' => $controller->renderRss($page),
            default => abort(404),
        };
    }
}
