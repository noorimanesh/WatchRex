<?php

namespace App\Http\Controllers;

use App\Enums\MonitorStatus;
use App\Models\Domain;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorGroup;
use App\Models\Server;
use App\Models\StatusPage;
use App\Services\GroupHealth;
use App\Services\MonitorList;
use App\Services\Uptime;
use App\Support\Svg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

        // Groups: worst-first health of top-level groups, plus website / mail service counters.
        $groups = MonitorGroup::visibleTo($user)->get(['id', 'user_id', 'parent_id', 'name', 'kind', 'domain']);
        $health = GroupHealth::for($groups);
        $severity = ['down' => 0, 'warning' => 1, 'maintenance' => 2, 'pending' => 3, 'up' => 4, 'paused' => 5];
        $kindStats = fn (string $kind) => [
            'total' => $groups->where('kind', $kind)->count(),
            'issues' => $groups->where('kind', $kind)->filter(fn ($g) => in_array($health[$g->id]['status'] ?? 'pending', ['down', 'warning'], true))->count(),
        ];
        $resolved = Incident::visibleTo($user)->whereNotNull('resolved_at')->where('started_at', '>=', now()->subDays(30))->get(['started_at', 'resolved_at']);
        $onlineServers = $servers->filter(fn ($s) => $s->isOnline())->count();

        $stats += [
            'servers' => $servers->count(),
            'servers_online' => $onlineServers,
            'websites' => $kindStats('website'),
            'mail' => $kindStats('mail'),
            'incidents_today' => Incident::visibleTo($user)->where('started_at', '>=', now()->startOfDay())->count(),
            'mttr' => $resolved->isEmpty() ? null : (int) round($resolved->avg(fn ($i) => $i->resolved_at->getTimestamp() - $i->started_at->getTimestamp())),
            'status_pages' => StatusPage::visibleTo($user)->count(),
        ];

        $issues = $active->whereIn('status', [MonitorStatus::Down, MonitorStatus::Warning])
            ->sortBy(fn ($m) => [$m->status === MonitorStatus::Down ? 0 : 1, $m->name])->take(8);
        $slowest = $active->where('status', MonitorStatus::Up)->whereNotNull('last_response_ms')->sortByDesc('last_response_ms')->take(5);

        return view('dashboard', [
            'stats' => $stats,
            'trend' => $this->trend($user, $active->pluck('id')->all()),
            'groupRows' => $groups->whereNull('parent_id')
                ->sortBy(fn ($g) => [$severity[$health[$g->id]['status'] ?? 'pending'] ?? 9, $g->name])
                ->take(10)->map(fn ($g) => ['group' => $g, 'health' => $health[$g->id] ?? null]),
            'issues' => $issues,
            'slowest' => $slowest,
            'monitors' => $monitors,
            'servers' => $servers,
            'incidents' => $incidents,
            'expiring' => $expiring->sortBy('days')->unique(fn ($e) => $e['kind'].$e['name'])->take(10),
        ]);
    }

    /** Hourly average response time and failed checks over the last 24h (cached for a minute). */
    private function trend($user, array $ids): array
    {
        return Cache::remember('dash-trend:'.$user->id.':'.md5(implode(',', $ids)), 60, function () use ($ids) {
            $to = time();
            $from = $to - 86400;
            if (! $ids) {
                return ['chart' => Svg::lineChart([], 800, 140, $from, $to), 'checks' => 0, 'failed' => 0];
            }

            $hour = match (DB::connection()->getDriverName()) {
                'mysql', 'mariadb' => "DATE_FORMAT(created_at, '%Y-%m-%d %H')",
                'pgsql' => "to_char(created_at, 'YYYY-MM-DD HH24')",
                'sqlsrv' => 'FORMAT(created_at, \'yyyy-MM-dd HH\')',
                default => "strftime('%Y-%m-%d %H', created_at)",
            };

            $rows = DB::table('heartbeats')->whereIn('monitor_id', $ids)->where('created_at', '>=', date('Y-m-d H:i:s', $from))
                ->groupBy(DB::raw($hour))
                ->selectRaw("$hour as h, AVG(CASE WHEN status IN (1,2) THEN response_ms END) as avg_ms, COUNT(*) as n, SUM(CASE WHEN status = 0 THEN 1 ELSE 0 END) as failed")
                ->get();

            $points = $rows->map(fn ($r) => [strtotime($r->h.':30:00'), $r->avg_ms !== null ? (int) round($r->avg_ms) : null, $r->failed > 0 ? MonitorStatus::HB_DOWN : MonitorStatus::HB_UP])
                ->sortBy(0)->values()->all();

            return ['chart' => Svg::lineChart($points, 800, 140, $from, $to), 'checks' => (int) $rows->sum('n'), 'failed' => (int) $rows->sum('failed')];
        });
    }

    /** Lightweight partial used by the auto-refresh script (no full page reload). */
    public function live(Request $request)
    {
        $monitors = MonitorList::decorate(MonitorList::query($request->user(), $request)->where('is_active', true)->limit(200)->get());

        return view('monitors._grid', ['monitors' => $monitors]);
    }
}
