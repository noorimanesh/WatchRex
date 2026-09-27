<?php

namespace App\Http\Controllers;

use App\Enums\MonitorStatus;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\StatusPage;
use App\Services\Format;
use App\Services\Uptime;
use Illuminate\Support\Facades\Cache;

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

        return response()->view('status.show', $data + ['page' => $page])
            ->header('Cache-Control', 'public, max-age=30');
    }

    public function renderJson(StatusPage $page)
    {
        $d = $this->data($page);

        return response()->json([
            'page' => ['title' => $page->title, 'description' => $page->description],
            'status' => $d['overall'],
            'monitors' => $d['monitors']->map(fn ($m) => [
                'name' => $m->pivot->display_name ?: $m->name,
                'status' => $m->status->value,
                'uptime_24h' => $page->show_uptime ? $m->uptime_24h : null,
                'uptime_90d' => $page->show_uptime ? $m->uptime_90d : null,
                'response_ms' => $page->show_response ? $m->last_response_ms : null,
            ])->values(),
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
        return Cache::remember("status-page:{$page->id}:".app()->getLocale(), 30, function () use ($page) {
            $monitors = $page->monitors()->get();
            $ids = $monitors->pluck('id')->all();
            $uptime = Uptime::last24h($ids);

            foreach ($monitors as $m) {
                $m->uptime_24h = $uptime[$m->id]['uptime'] ?? null;
                $m->bars = Uptime::dailyBars($m, 90);
                $values = $m->bars->pluck('uptime')->filter(fn ($v) => $v !== null);
                $m->uptime_90d = $values->count() ? round($values->avg(), 3) : null;
            }

            $down = $monitors->where('status', MonitorStatus::Down)->count();
            $degraded = $monitors->whereIn('status', [MonitorStatus::Warning])->count();
            $maint = $monitors->where('status', MonitorStatus::Maintenance)->count();

            $overall = match (true) {
                $down > 0 && $down >= max(1, (int) ceil($monitors->count() / 2)) => 'major_outage',
                $down > 0 => 'partial_outage',
                $degraded > 0 => 'degraded',
                $maint > 0 => 'maintenance',
                default => 'operational',
            };

            return [
                'monitors' => $monitors,
                'overall' => $overall,
                'incidents' => Incident::whereIn('monitor_id', $ids)->where('severity', 'critical')->where('started_at', '>=', now()->subDays(14))
                    ->with(['updates' => fn ($q) => $q->whereIn('type', ['down', 'recovered', 'public', 'resolved'])])->latest('started_at')->limit(20)->get(),
                'maintenance' => MaintenanceWindow::whereHas('monitors', fn ($q) => $q->whereIn('monitors.id', $ids))
                    ->where('ends_at', '>=', now())->orderBy('starts_at')->limit(5)->get(),
            ];
        });
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
