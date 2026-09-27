<?php

namespace App\Http\Controllers;

use App\Enums\MonitorStatus;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Server;
use App\Services\MonitorList;
use App\Services\Uptime;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $all = Monitor::visibleTo($user)->get(['id', 'status', 'is_active', 'last_response_ms', 'meta', 'name', 'type', 'target', 'port']);
        $active = $all->where('is_active', true);

        $stats = [
            'total' => $all->count(),
            'up' => $active->where('status', MonitorStatus::Up)->count(),
            'down' => $active->where('status', MonitorStatus::Down)->count(),
            'warning' => $active->where('status', MonitorStatus::Warning)->count(),
            'paused' => $all->where('is_active', false)->count(),
            'maintenance' => $active->where('status', MonitorStatus::Maintenance)->count(),
            'uptime_30d' => Uptime::overall($all->pluck('id')->all(), 30),
            'avg_response' => ($r = $active->whereNotNull('last_response_ms')->avg('last_response_ms')) ? (int) round($r) : null,
            'incidents_open' => Incident::visibleTo($user)->whereNull('resolved_at')->count(),
            'incidents_30d' => Incident::visibleTo($user)->where('started_at', '>=', now()->subDays(30))->count(),
        ];

        $monitors = MonitorList::decorate(MonitorList::query($user, $request)->where('is_active', true)->limit(200)->get());

        $servers = Server::visibleTo($user)->orderBy('name')->get();
        $incidents = Incident::visibleTo($user)->with('monitor:id,name')->latest('started_at')->limit(8)->get();

        $expiring = collect();
        foreach (Domain::visibleTo($user)->get() as $d) {
            if (($days = $d->daysUntilExpiry()) !== null && $days <= 45) {
                $expiring->push(['kind' => 'domain', 'name' => $d->name, 'days' => $days, 'url' => route('domains.show', $d)]);
            }
            if (($days = $d->sslDaysLeft()) !== null && $days <= 21) {
                $expiring->push(['kind' => 'ssl', 'name' => $d->name, 'days' => $days, 'url' => route('domains.show', $d)]);
            }
        }
        foreach ($all as $m) {
            $days = data_get($m->meta, 'ssl.days_left');
            if ($days !== null && $days <= 21) {
                $expiring->push(['kind' => 'ssl', 'name' => $m->name, 'days' => $days, 'url' => route('monitors.show', $m->id)]);
            }
        }

        return view('dashboard', [
            'stats' => $stats,
            'monitors' => $monitors,
            'servers' => $servers,
            'incidents' => $incidents,
            'expiring' => $expiring->sortBy('days')->unique(fn ($e) => $e['kind'].$e['name'])->take(10),
        ]);
    }

    /** Lightweight partial used by the auto-refresh script (no full page reload). */
    public function live(Request $request)
    {
        $monitors = MonitorList::decorate(MonitorList::query($request->user(), $request)->where('is_active', true)->limit(200)->get());

        return view('monitors._grid', ['monitors' => $monitors]);
    }
}
