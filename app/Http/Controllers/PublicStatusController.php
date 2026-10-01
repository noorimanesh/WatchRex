<?php

namespace App\Http\Controllers;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Services\Format;
use App\Services\StatusPageData;
use App\Services\Uptime;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ViewErrorBag;

/** Public, cache-friendly status pages (also served on custom domains). */
class PublicStatusController extends Controller
{
    public function show(string $slug)
    {
        return $this->render($this->find($slug));
    }

    public function json(string $slug)
    {
        return $this->renderJson($this->find($slug));
    }

    public function rss(string $slug)
    {
        return $this->renderRss($this->find($slug));
    }

    public function render(StatusPage $page)
    {
        $data = $this->data($page);

        // Custom-domain requests are served before the session middleware runs.
        $errors = request()->hasSession() ? request()->session()->get('errors', new ViewErrorBag) : new ViewErrorBag;

        return response()->view('status.show', $data + ['page' => $page, 'errors' => $errors])
            ->header('Cache-Control', 'public, max-age=30');
    }

    public function renderJson(StatusPage $page)
    {
        $d = $this->data($page);

        $monitor = fn ($m) => [
            'name' => $m->public_name,
            'type' => $m->type->value,
            'status' => $m->public_status,
            'uptime_24h' => $page->show_uptime ? $m->uptime_24h : null,
            'uptime' => $page->show_uptime ? $m->uptime_period : null,
            'response_ms' => $page->show_response ? $m->last_response_ms : null,
        ];

        return response()->json([
            'page' => ['title' => $page->title, 'description' => $page->description, 'history_days' => $d['historyDays']],
            'status' => $d['overall'],
            'uptime' => $page->show_uptime ? $d['uptime'] : null,
            'groups' => collect($d['sections'])->map(fn ($s) => [
                'name' => $s['name'],
                'kind' => $s['kind'],
                'status' => $s['status'],
                'uptime' => $page->show_uptime ? $s['uptime'] : null,
                'details' => $s['details'],
                'mail' => $s['mail'] ? ['status' => $s['mail']['overall'], 'score' => $s['mail']['score'], 'components' => collect($s['mail']['components'])->map(fn ($c) => ['key' => $c['key'], 'status' => $c['status']])->values()] : null,
                'monitors' => $s['monitors']->map($monitor)->values(),
            ])->values(),
            'monitors' => $d['monitors']->map($monitor)->values(),
            'incidents' => $d['incidents']->map(fn ($i) => ['title' => $i->title, 'severity' => $i->severity, 'started_at' => $i->started_at, 'resolved_at' => $i->resolved_at]),
            'maintenance' => $d['maintenance']->map(fn ($w) => ['title' => $w->title, 'starts_at' => $w->starts_at, 'ends_at' => $w->ends_at]),
        ])->header('Access-Control-Allow-Origin', '*')->header('Cache-Control', 'public, max-age=30');
    }

    public function renderRss(StatusPage $page)
    {
        $d = $this->data($page);

        return response()->view('status.rss', $d + ['page' => $page])->header('Content-Type', 'application/rss+xml; charset=UTF-8');
    }

    private function find(string $slug): StatusPage
    {
        $page = StatusPage::where('slug', $slug)->firstOrFail();
        abort_unless($page->is_public || auth()->user()?->isAdmin() || auth()->id() === $page->user_id, 404);

        return $page;
    }

    private function data(StatusPage $page): array
    {
        $key = "status-page:{$page->id}:".app()->getLocale().':'.$page->updated_at?->timestamp;

        return Cache::remember($key, 30, fn () => StatusPageData::build($page));
    }

    /** Embeddable SVG badges: /badge/{uuid}/status.svg, /badge/{uuid}/uptime.svg */
    public function badge(string $uuid, string $kind)
    {
        $monitor = Monitor::where('uuid', $uuid)->firstOrFail();

        [$label, $value, $color] = match ($kind) {
            'uptime' => (function () use ($monitor) {
                $u = Uptime::periods($monitor)['30d'];

                return ['uptime 30d', $u === null ? 'n/a' : Format::uptime($u), $u === null ? '#9ca3af' : ($u >= 99.9 ? '#10b981' : ($u >= 98 ? '#f59e0b' : '#ef4444'))];
            })(),
            'response' => ['response', Format::ms($monitor->last_response_ms), '#6366f1'],
            default => ['status', $monitor->is_active ? $monitor->status->value : 'paused', match ($monitor->status) {
                MonitorStatus::Up => '#10b981', MonitorStatus::Down => '#ef4444', MonitorStatus::Warning => '#f59e0b', default => '#9ca3af',
            }],
        };

        $lw = 8 + strlen($label) * 6.5;
        $vw = 10 + strlen($value) * 7;

        return response()->view('status.badge', compact('label', 'value', 'color', 'lw', 'vw'))
            ->header('Content-Type', 'image/svg+xml')
            ->header('Cache-Control', 'public, max-age=60');
    }
}
