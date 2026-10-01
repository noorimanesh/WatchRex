<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\Server;
use App\Services\Uptime;
use Illuminate\Http\Request;

/** Read API (Bearer token). Write tokens may also pause/resume monitors. */
class V1Controller extends Controller
{
    public function summary(Request $request)
    {
        $monitors = Monitor::visibleTo($request->user())->get(['id', 'status', 'is_active']);

        return [
            'monitors' => $monitors->count(),
            'up' => $monitors->where('status.value', 'up')->count(),
            'down' => $monitors->where('status.value', 'down')->count(),
            'warning' => $monitors->where('status.value', 'warning')->count(),
            'paused' => $monitors->where('is_active', false)->count(),
            'uptime_30d' => Uptime::overall($monitors->pluck('id')->all(), 30),
            'open_incidents' => Incident::visibleTo($request->user())->whereNull('resolved_at')->count(),
        ];
    }

    public function monitors(Request $request)
    {
        $monitors = Monitor::visibleTo($request->user())->orderBy('name')->get();
        $uptime = Uptime::last24h($monitors->pluck('id')->all());

        return ['data' => $monitors->map(fn (Monitor $m) => $this->present($m) + ['uptime_24h' => $uptime[$m->id]['uptime'] ?? null])];
    }

    public function monitor(Request $request, Monitor $monitor)
    {
        $this->authorizeOwner($monitor);

        return ['data' => $this->present($monitor) + ['uptime' => Uptime::periods($monitor), 'last_details' => $monitor->metaValue('last_details')]];
    }

    public function heartbeats(Request $request, Monitor $monitor)
    {
        $this->authorizeOwner($monitor);
        $limit = min(1000, max(1, (int) $request->query('limit', 100)));

        return ['data' => $monitor->heartbeats()->latest('id')->limit($limit)->get(['status', 'response_ms', 'message', 'created_at'])];
    }

    public function toggle(Request $request, Monitor $monitor)
    {
        $this->authorizeOwner($monitor);
        $active = $request->boolean('active');
        $monitor->forceFill(['is_active' => $active, 'status' => $active ? 'pending' : 'paused', 'next_check_at' => now()])->save();

        return ['data' => $this->present($monitor)];
    }

    public function incidents(Request $request)
    {
        return ['data' => Incident::visibleTo($request->user())->with('monitor:id,name')->latest('started_at')->limit(100)->get()];
    }

    public function servers(Request $request)
    {
        return ['data' => Server::visibleTo($request->user())->get()->map(fn (Server $s) => [
            'id' => $s->id, 'name' => $s->name, 'hostname' => $s->hostname, 'panel' => $s->panel,
            'online' => $s->isOnline(), 'last_seen_at' => $s->last_seen_at,
            'cpu' => $s->stat('cpu'), 'ram' => $s->stat('ram'), 'disk' => $s->stat('disk'), 'load' => $s->stat('load'),
            'mail_queue' => $s->stat('mail.queue'),
        ])];
    }

    private function present(Monitor $m): array
    {
        return [
            'id' => $m->id, 'uuid' => $m->uuid, 'name' => $m->name, 'type' => $m->type->value,
            'target' => $m->displayTarget(), 'status' => $m->status->value, 'active' => $m->is_active,
            'interval' => $m->interval, 'response_ms' => $m->last_response_ms, 'message' => $m->last_message,
            'groups' => $m->relationLoaded('groups') ? $m->groups->pluck('name') : $m->groups()->pluck('name'), 'tags' => $m->tags ?? [], 'last_checked_at' => $m->last_checked_at,
            'ssl_days_left' => $m->metaValue('ssl.days_left'),
        ];
    }
}
