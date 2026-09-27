<?php

namespace App\Http\Controllers;

use App\Enums\MonitorType;
use App\Models\AuditLog;
use App\Models\Monitor;
use App\Models\Server;
use App\Services\ServerHealth;
use App\Support\Svg;
use Illuminate\Http\Request;

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
        ]);
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
