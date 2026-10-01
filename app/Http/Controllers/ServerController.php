<?php

namespace App\Http\Controllers;

use App\Enums\MonitorType;
use App\Jobs\ImportServerSites;
use App\Models\AuditLog;
use App\Models\Monitor;
use App\Models\MonitorGroup;
use App\Models\Server;
use App\Services\GroupHealth;
use App\Services\ServerHealth;
use App\Support\Svg;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ServerController extends Controller
{
    public function index(Request $request)
    {
        $servers = Server::visibleTo($request->user())->with('user:id,name')->orderBy('name')->get();

        return view('servers.index', ['servers' => $servers]);
    }

    public function create()
    {
        return view('servers.form', ['server' => new Server(['report_interval' => 60, 'thresholds' => Server::DEFAULT_THRESHOLDS]), 'teams' => $this->assignableTeams()]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        if (! $user->withinLimit('max_servers', $user->servers()->count())) {
            return back()->withInput()->withErrors(['name' => __('Your plan server limit has been reached.')]);
        }

        $server = new Server($this->validated($request));
        $server->user_id = $user->id;
        $token = $server->rotateToken();
        $server->save();

        // Every server gets a monitor so agent silence and threshold breaches flow through normal alerting.
        $monitor = new Monitor([
            'name' => $server->name, 'type' => MonitorType::Server, 'server_id' => $server->id,
            'interval' => 60, 'timeout' => 10, 'retries' => 1, 'is_active' => true,
            'settings' => ['notify_warning' => true, 'anomaly' => false],
        ]);
        $monitor->user_id = $user->id;
        $monitor->team_id = $server->team_id;
        $monitor->next_check_at = now()->addMinutes(3);
        $monitor->save();

        AuditLog::record('server.created', $server, ['name' => $server->name]);

        return redirect()->route('servers.show', $server)->with('agent_token', $token);
    }

    public function show(Request $request, Server $server)
    {
        $this->authorizeOwner($server);

        $hours = in_array((int) $request->query('hours'), [1, 6, 24, 72, 168], true) ? (int) $request->query('hours') : 24;
        $from = now()->subHours($hours);
        $metrics = $server->metrics()->where('created_at', '>=', $from)->orderBy('id')->toBase()->get();

        $series = function (string $column) use ($metrics, $from) {
            $points = $metrics->map(fn ($m) => [strtotime($m->created_at), $m->{$column} !== null ? (float) $m->{$column} : null, 1])->all();
            $points = count($points) > 240 ? Svg::bucket($points, $from->timestamp, time(), 180) : $points;

            return Svg::lineChart($points, 800, 140, $from->timestamp, time());
        };

        $mail = [
            'sent' => (int) $metrics->sum('mail_sent'),
            'received' => (int) $metrics->sum('mail_received'),
            'bounced' => (int) $metrics->sum('mail_bounced'),
            'deferred' => (int) $metrics->sum('mail_deferred'),
            'rejected' => (int) $metrics->sum('mail_rejected'),
            'login_ok' => (int) $metrics->sum('login_ok'),
            'login_failed' => (int) $metrics->sum('login_failed'),
        ];

        return view('servers.show', [
            'server' => $server,
            'hours' => $hours,
            'charts' => ['cpu' => $series('cpu'), 'ram' => $series('ram'), 'disk' => $series('disk'), 'load1' => $series('load1'), 'mail_queue' => $series('mail_queue')],
            'mail' => $mail,
            'problems' => ServerHealth::problems($server),
            'monitor' => $server->monitors()->where('type', MonitorType::Server->value)->first(),
            'token' => session('agent_token'),
            'sites' => $this->sites($request, $server),
            'siteCounts' => [
                'total' => $server->sites()->count(),
                'monitored' => $server->sites()->whereNotNull('monitor_group_id')->count(),
                'ignored' => $server->sites()->where('ignored', true)->count(),
            ],
            'siteHealth' => GroupHealth::for(MonitorGroup::whereIn('id', $server->sites()->whereNotNull('monitor_group_id')->pluck('monitor_group_id'))->get()),
        ]);
    }

    /** Bulk actions on discovered sites: import (monitor), ignore, un-ignore. */
    public function sitesAction(Request $request, Server $server)
    {
        $this->authorizeOwner($server);
        $data = $request->validate([
            'action' => ['required', Rule::in(['import', 'import_all', 'ignore', 'unignore'])],
            'sites' => ['nullable', 'array'],
            'sites.*' => ['integer'],
            'website' => ['nullable', 'boolean'],
            'domain' => ['nullable', 'boolean'],
            'mail' => ['nullable', 'boolean'],
            'interval' => ['nullable', 'integer', 'between:20,86400'],
        ]);
        $ids = $server->sites()->whereIn('id', $data['sites'] ?? [])->pluck('id')->all();
        $options = [
            'website' => $request->boolean('website'), 'domain' => $request->boolean('domain'),
            'mail' => $request->boolean('mail'), 'interval' => (int) ($data['interval'] ?? 300),
        ];

        switch ($data['action']) {
            case 'ignore':
            case 'unignore':
                $server->sites()->whereIn('id', $ids)->update(['ignored' => $data['action'] === 'ignore']);

                return back()->with('success', __(':n site(s) updated.', ['n' => count($ids)]));
            case 'import':
                if (! $ids) {
                    return back()->with('error', __('Select at least one site.'));
                }
                ImportServerSites::dispatch($server->id, $ids, $options)->onQueue(config('watchrex.queues.domains'));
                break;
            default:
                ImportServerSites::dispatch($server->id, null, $options)->onQueue(config('watchrex.queues.domains'));
        }

        AuditLog::record('server.sites_import_requested', $server, ['sites' => $data['action'] === 'import' ? count($ids) : 'all'] + $options);

        return back()->with('success', __('Import started. Groups and monitors appear within a minute.'));
    }

    public function siteSettings(Request $request, Server $server)
    {
        $this->authorizeOwner($server);
        $server->forceFill(['settings' => array_merge($server->settings ?? [], [
            'auto_import' => $request->boolean('auto_import'),
            'import_options' => [
                'website' => $request->boolean('website'), 'domain' => $request->boolean('domain'),
                'mail' => $request->boolean('mail'), 'interval' => max(20, (int) $request->input('interval', 300)),
            ],
        ])])->save();

        return back()->with('success', __('Saved.'));
    }

    private function sites(Request $request, Server $server)
    {
        $filter = $request->query('sites');

        return $server->sites()->with('group:id,name')
            ->when($request->query('site_q'), fn ($q, $t) => $q->where(fn ($q) => $q->where('domain', 'like', "%{$t}%")->orWhere('account', 'like', "%{$t}%")))
            ->when($filter === 'monitored', fn ($q) => $q->whereNotNull('monitor_group_id'))
            ->when($filter === 'new', fn ($q) => $q->whereNull('monitor_group_id')->where('ignored', false))
            ->when($filter === 'ignored', fn ($q) => $q->where('ignored', true))
            ->orderBy('account')->orderByRaw("CASE kind WHEN 'main' THEN 0 WHEN 'addon' THEN 1 WHEN 'sub' THEN 2 ELSE 3 END")->orderBy('domain')
            ->paginate(100, ['*'], 'sites_page')->withQueryString();
    }

    public function edit(Server $server)
    {
        $this->authorizeOwner($server);

        return view('servers.form', ['server' => $server, 'teams' => $this->assignableTeams()]);
    }

    public function update(Request $request, Server $server)
    {
        $this->authorizeOwner($server);
        $server->update($this->validated($request));
        $server->monitors()->where('type', MonitorType::Server->value)->update(['name' => $server->name, 'team_id' => $server->team_id]);
        AuditLog::record('server.updated', $server);

        return redirect()->route('servers.show', $server)->with('success', __('Server updated.'));
    }

    public function destroy(Server $server)
    {
        $this->authorizeOwner($server);
        AuditLog::record('server.deleted', $server, ['name' => $server->name]);
        $server->monitors()->where('type', MonitorType::Server->value)->delete();
        $server->delete();

        return redirect()->route('servers.index')->with('success', __('Server removed.'));
    }

    public function rotateToken(Server $server)
    {
        $this->authorizeOwner($server);
        $token = $server->rotateToken();
        $server->save();
        AuditLog::record('server.token_rotated', $server);

        return redirect()->route('servers.show', $server)->with('agent_token', $token);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'report_interval' => ['required', 'integer', 'between:30,3600'],
            'team_id' => $this->teamRule(),
            'thresholds' => ['array'],
            'thresholds.*' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ]);
        $data['thresholds'] = array_intersect_key($data['thresholds'] ?? [], Server::DEFAULT_THRESHOLDS);

        return $data;
    }
}
