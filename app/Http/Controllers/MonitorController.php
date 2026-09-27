<?php

namespace App\Http\Controllers;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Http\Requests\MonitorRequest;
use App\Jobs\CaptureScreenshot;
use App\Models\AuditLog;
use App\Models\ContentSnapshot;
use App\Models\Monitor;
use App\Models\NotificationChannel;
use App\Models\Probe;
use App\Models\Server;
use App\Models\User;
use App\Services\ContentWatcher;
use App\Services\DependencyMap;
use App\Services\LocationNames;
use App\Services\MonitorList;
use App\Services\MonitorRunner;
use App\Services\Uptime;
use App\Support\Svg;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MonitorController extends Controller
{
    public const RANGES = ['1h' => 3600, '24h' => 86400, '7d' => 604800, '30d' => 2592000];

    public function index(Request $request)
    {
        $user = $request->user();
        $monitors = MonitorList::query($user, $request)->paginate(50)->withQueryString();
        MonitorList::decorate($monitors->getCollection());

        return view('monitors.index', [
            'monitors' => $monitors,
            'facets' => MonitorList::facets($user),
            'owners' => $user->isAdmin() ? User::orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    public function create(Request $request)
    {
        $monitor = new Monitor([
            'type' => MonitorType::tryFrom((string) $request->query('type')) ?? MonitorType::Http,
            'target' => $request->query('target'),
            'name' => $request->query('name'),
            'interval' => max(60, $request->user()->limit('min_interval') ?? 60),
            'timeout' => config('watchrex.defaults.timeout'),
            'retries' => config('watchrex.defaults.retries'),
            'is_active' => true,
            'settings' => ['verify_ssl' => true, 'follow_redirects' => true, 'anomaly' => true, 'notify_warning' => true, 'rbl_check' => true, 'require_tls' => true, 'alert_on_change' => true],
        ]);

        return view('monitors.form', $this->formData($request, $monitor));
    }

    public function store(MonitorRequest $request)
    {
        $user = $request->user();

        if (! $user->withinLimit('max_monitors', $user->monitors()->count())) {
            return back()->withInput()->withErrors(['name' => __('Your plan allows :n monitors. Upgrade to add more.', ['n' => $user->limit('max_monitors')])]);
        }

        $monitor = new Monitor($request->monitorData());
        $monitor->user_id = $user->id;
        $monitor->next_check_at = now();
        $monitor->save();
        $this->syncChannels($request, $monitor);

        AuditLog::record('monitor.created', $monitor, ['name' => $monitor->name]);

        return redirect()->route('monitors.show', $monitor)->with('success', __('Monitor created. The first check runs within a few seconds.'));
    }

    public function show(Request $request, Monitor $monitor)
    {
        $this->authorizeOwner($monitor);
        $monitor->load(['parent', 'children', 'server', 'channels', 'statusPages']);

        $range = array_key_exists($request->query('range'), self::RANGES) ? $request->query('range') : '24h';
        $to = time();
        $from = $to - self::RANGES[$range];

        $raw = $monitor->heartbeats()
            ->where('created_at', '>=', date('Y-m-d H:i:s', $from))
            ->orderBy('id')
            ->toBase()
            ->get(['created_at', 'response_ms', 'status'])
            ->map(fn ($h) => [strtotime($h->created_at), $h->response_ms !== null ? (int) $h->response_ms : null, (int) $h->status])
            ->all();

        $points = count($raw) > 240 ? Svg::bucket($raw, $from, $to, 180) : $raw;
        $values = array_filter(array_column($raw, 1), fn ($v) => $v !== null);
        sort($values);

        return view('monitors.show', [
            'monitor' => $monitor,
            'range' => $range,
            'chart' => Svg::lineChart($points, 800, 180, $from, $to),
            'responseStats' => [
                'avg' => $values ? (int) round(array_sum($values) / count($values)) : null,
                'min' => $values[0] ?? null,
                'max' => $values ? end($values) : null,
                'p95' => $values ? $values[(int) floor(count($values) * 0.95) - (count($values) > 1 ? 1 : 0)] : null,
            ],
            'periods' => Uptime::periods($monitor),
            'bars' => Uptime::dailyBars($monitor, 90),
            'events' => $monitor->heartbeats()->where('status', '!=', MonitorStatus::HB_UP)->latest('id')->limit(25)->get(),
            'beats' => MonitorList::recentBeats([$monitor->id], 60)->get($monitor->id, collect()),
            'incidents' => $monitor->incidents()->latest('started_at')->limit(10)->get(),
            'locations' => $this->locations($monitor),
            'depMap' => $monitor->parent_id || $monitor->children->isNotEmpty()
                ? DependencyMap::build(Monitor::visibleTo($request->user())->get(['id', 'name', 'type', 'status', 'is_active', 'parent_id', 'last_response_ms', 'last_message']), $monitor->id)
                : null,
            'textChanges' => $monitor->snapshots()->where('kind', 'text')->where('change_percent', '>', 0)->latest('id')->limit(5)->get(['id', 'change_percent', 'diff', 'created_at']),
            'visuals' => $monitor->snapshots()->where('kind', 'visual')->latest('id')->limit(2)->get(),
        ]);
    }

    public function edit(Request $request, Monitor $monitor)
    {
        $this->authorizeOwner($monitor);

        return view('monitors.form', $this->formData($request, $monitor));
    }

    public function update(MonitorRequest $request, Monitor $monitor)
    {
        $this->authorizeOwner($monitor);

        $monitor->fill($request->monitorData($monitor));
        if ($monitor->isDirty(['type', 'target', 'port', 'interval'])) {
            $monitor->next_check_at = now();
        }
        if ($monitor->type === MonitorType::Push && ! $monitor->push_token) {
            $monitor->push_token = Str::random(40);
        }
        $monitor->save();
        $this->syncChannels($request, $monitor);

        AuditLog::record('monitor.updated', $monitor, ['name' => $monitor->name]);

        return redirect()->route('monitors.show', $monitor)->with('success', __('Monitor updated.'));
    }

    public function destroy(Monitor $monitor)
    {
        $this->authorizeOwner($monitor);
        AuditLog::record('monitor.deleted', $monitor, ['name' => $monitor->name]);
        $monitor->delete();

        return redirect()->route('monitors.index')->with('success', __('Monitor deleted.'));
    }

    public function toggle(Monitor $monitor)
    {
        $this->authorizeOwner($monitor);

        $monitor->is_active = ! $monitor->is_active;
        $monitor->status = $monitor->is_active ? MonitorStatus::Pending : MonitorStatus::Paused;
        $monitor->next_check_at = now();
        $monitor->consecutive_failures = 0;
        $monitor->save();

        AuditLog::record($monitor->is_active ? 'monitor.resumed' : 'monitor.paused', $monitor);

        return back()->with('success', $monitor->is_active ? __('Monitoring resumed.') : __('Monitoring paused.'));
    }

    public function checkNow(Monitor $monitor, MonitorRunner $runner)
    {
        $this->authorizeOwner($monitor);

        $result = $runner->run($monitor);
        $monitor->forceFill(['next_check_at' => now()->addSeconds($monitor->interval)])->save();

        return back()->with($result->isDown() ? 'error' : 'success', strtoupper($result->status->value).' — '.$result->message);
    }

    public function snapshot(Monitor $monitor, ContentSnapshot $snapshot)
    {
        $this->authorizeOwner($monitor);
        abort_unless($snapshot->monitor_id === $monitor->id && $snapshot->path && Storage::disk('local')->exists($snapshot->path), 404);

        return response()->file(Storage::disk('local')->path($snapshot->path), ['Content-Type' => 'image/png', 'Cache-Control' => 'private, max-age=86400']);
    }

    public function screenshot(Monitor $monitor)
    {
        $this->authorizeOwner($monitor);
        abort_unless($monitor->type->usesUrl() && ContentWatcher::screenshotsAllowed($monitor->loadMissing('user')), 403);
        CaptureScreenshot::dispatch($monitor->id)->onQueue(config('watchrex.queues.domains'));

        return back()->with('success', __('Screenshot requested. It appears here within a minute.'));
    }

    /** Latest result per location, freshest first, with labels. */
    private function locations(Monitor $monitor): array
    {
        $rows = [];
        foreach ((array) $monitor->metaValue('locations', []) as $key => $row) {
            $rows[] = $row + ['key' => $key, 'label' => LocationNames::label($key), 'stale' => ($row['at'] ?? 0) < time() - max(180, $monitor->interval * 3)];
        }
        usort($rows, fn ($a, $b) => [$a['stale'], $a['label']] <=> [$b['stale'], $b['label']]);

        return $rows;
    }

    public function regenerateToken(Monitor $monitor)
    {
        $this->authorizeOwner($monitor);
        abort_unless($monitor->type === MonitorType::Push, 404);

        $monitor->forceFill(['push_token' => Str::random(40)])->save();
        AuditLog::record('monitor.push_token_rotated', $monitor);

        return back()->with('success', __('Push URL regenerated. Update your cron jobs.'));
    }

    private function formData(Request $request, Monitor $monitor): array
    {
        $user = $request->user();

        return [
            'monitor' => $monitor,
            'types' => MonitorType::cases(),
            'channels' => NotificationChannel::visibleTo($user)->orderBy('name')->get(),
            'servers' => Server::visibleTo($user)->orderBy('name')->get(['id', 'name']),
            'parents' => Monitor::visibleTo($user)->when($monitor->exists, fn ($q) => $q->whereKeyNot($monitor->id))->orderBy('name')->get(['id', 'name']),
            'groups' => MonitorList::facets($user)['groups'],
            'minInterval' => $user->limit('min_interval') ?? 20,
            'teams' => $this->assignableTeams(),
            'probes' => Probe::where('is_active', true)->orderBy('name')->get(),
            'selectedProbes' => $monitor->exists ? $monitor->probes()->pluck('probes.id')->all() : [],
            'screenshots' => (bool) config('watchrex.screenshots.chrome') && ($user->isAdmin() || config('watchrex.screenshots.tenants')),
        ];
    }

    private function syncChannels(Request $request, Monitor $monitor): void
    {
        $ids = NotificationChannel::visibleTo($request->user())
            ->whereIn('id', (array) $request->input('channels', []))
            ->pluck('id');

        $monitor->channels()->sync($ids);

        if (! $monitor->type->isPassive()) {
            $monitor->probes()->sync(Probe::where('is_active', true)->whereIn('id', (array) $request->input('probes', []))->pluck('id'));
        }
    }
}
