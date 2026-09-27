<?php

namespace App\Http\Controllers\Api;

use App\Enums\MonitorStatus;
use App\Http\Controllers\Controller;
use App\Models\Monitor;
use App\Models\Probe;
use App\Services\Checks\CheckResult;
use App\Services\Checks\TargetGuard;
use App\Services\MonitorRunner;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/** Hub endpoints used by remote probes (`php artisan watchrex:probe`). */
class ProbeController extends Controller
{
    public function jobs(Request $request)
    {
        $probe = $this->probe($request);

        $jobs = $probe->monitors()->where('is_active', true)->with('user')->get()
            ->reject(fn (Monitor $m) => $m->type->isPassive())
            ->map(fn (Monitor $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'type' => $m->type->value,
                'target' => $m->target,
                'port' => $m->port,
                'method' => $m->method,
                'interval' => $m->interval,
                'timeout' => $m->timeout,
                'settings' => Arr::except($m->settings ?? [], ['visual']),
                'credentials' => $m->credentials,
                'guard' => TargetGuard::enabledFor($m),
            ])->values();

        return response()->json(['location' => $probe->location, 'jobs' => $jobs])->header('Cache-Control', 'no-store');
    }

    public function results(Request $request, MonitorRunner $runner)
    {
        $probe = $this->probe($request);
        $data = $request->validate([
            'results' => ['required', 'array', 'max:1000'],
            'results.*.monitor_id' => ['required', 'integer'],
            'results.*.status' => ['required', 'in:up,down,warning'],
            'results.*.response_ms' => ['nullable', 'integer', 'min:0', 'max:600000'],
            'results.*.message' => ['nullable', 'string', 'max:500'],
            'results.*.details' => ['nullable', 'array'],
            'results.*.meta' => ['nullable', 'array'],
        ]);

        $allowed = $probe->monitors()->where('is_active', true)->pluck('monitors.id')->flip();
        $accepted = 0;

        foreach ($data['results'] as $r) {
            if (! isset($allowed[$r['monitor_id']]) || ! ($monitor = Monitor::find($r['monitor_id']))) {
                continue;
            }

            $result = new CheckResult(
                MonitorStatus::from($r['status']),
                $r['response_ms'] ?? null,
                strip_tags((string) ($r['message'] ?? '')),
                Arr::only($r['details'] ?? [], ['status_code', 'timings', 'causes', 'steps', 'error', 'ip', 'certificate', 'http_version', 'redirects', 'size']),
                Arr::only($r['meta'] ?? [], ['ip']),
            );
            $runner->process($monitor, $result, $probe->location);
            $accepted++;
        }

        return response()->json(['ok' => true, 'accepted' => $accepted]);
    }

    private function probe(Request $request): Probe
    {
        $probe = Probe::findByToken($request->bearerToken());
        abort_unless($probe, 401, 'Invalid probe token');

        $probe->forceFill([
            'last_seen_at' => now(),
            'ip' => $request->ip(),
            'version' => mb_substr((string) $request->header('X-WatchRex-Version'), 0, 16) ?: null,
        ])->save();

        return $probe;
    }
}
