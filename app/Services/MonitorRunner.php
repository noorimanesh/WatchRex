<?php

namespace App\Services;

use App\Enums\MonitorStatus;
use App\Jobs\NotifySubscribers;
use App\Models\Heartbeat;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorDailyStat;
use App\Services\Alerting\Notifier;
use App\Services\Checks\CheckerFactory;
use App\Services\Checks\CheckResult;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class MonitorRunner
{
    public function run(Monitor $monitor): CheckResult
    {
        $monitor->loadMissing('user', 'parent', 'server');
        // Messages, diagnoses and alerts are written in the owner's language.
        if ($monitor->user?->locale) {
            app()->setLocale($monitor->user->locale);
        }

        try {
            $result = CheckerFactory::for($monitor->type)->check($monitor);
        } catch (Throwable $e) {
            report($e);
            $result = CheckResult::down(__('Checker error: :e', ['e' => mb_substr($e->getMessage(), 0, 300)]));
        }

        $this->process($monitor, $result);

        return $result;
    }

    /**
     * Turns one check result (from this server or a remote probe) into state:
     * heartbeat, daily aggregate, status transition, incident and alerts.
     */
    public function process(Monitor $monitor, CheckResult $result, ?string $location = null): void
    {
        $location ??= config('watchrex.location');

        // Hub checks and probe reports may arrive at the same time for one monitor.
        $wantsScreenshot = Cache::lock("monitor-process:{$monitor->id}", 30)->block(10, function () use ($monitor, $result, $location) {
            $monitor->refresh();

            return $this->processLocked($monitor, $result, $location);
        });

        // Outside the lock: the capture job takes the same lock to store its result.
        if ($wantsScreenshot) {
            app(ContentWatcher::class)->scheduleScreenshot($monitor);
        }
    }

    /** @return bool whether a visual snapshot should be scheduled */
    private function processLocked(Monitor $monitor, CheckResult $result, string $location): bool
    {
        $monitor->loadMissing('user', 'parent');
        if ($monitor->user?->locale) {
            app()->setLocale($monitor->user->locale);
        }
        $previous = $monitor->status;
        $meta = $monitor->meta ?? [];
        $inMaintenance = $monitor->inMaintenance();
        $isLocal = $location === config('watchrex.location');

        // 0. Content / visual change detection happens on the hub only.
        $content = $result->details['_content'] ?? null;
        unset($result->details['_content']);
        if ($content !== null && ! $result->isDown() && $monitor->setting('detect_changes')) {
            app(ContentWatcher::class)->compare($monitor, $content, $result);
        }

        // 1. Response-time threshold + anomaly detection (baseline learned per location).
        if (! $result->isDown() && $result->responseMs !== null) {
            $warnMs = (int) $monitor->setting('response_warn_ms', 0);
            if ($warnMs > 0 && $result->responseMs > $warnMs) {
                $result->withWarning(__('Slow response: :ms ms (threshold :t ms)', ['ms' => $result->responseMs, 't' => $warnMs]));
            }

            $key = $isLocal ? 'baseline' : "baselines.{$location}";
            [$baseline, $anomaly] = $this->baseline(data_get($meta, $key), $result->responseMs);
            data_set($meta, $key, $baseline);
            if ($anomaly && $monitor->setting('anomaly', true)) {
                $result->withWarning(__('Performance anomaly: response :ms ms is :x× higher than normal (:avg ms).', [
                    'ms' => $result->responseMs, 'x' => $anomaly, 'avg' => (int) $baseline['mean'],
                ]));
                $result->details['anomaly'] = $anomaly;
            }
        }

        // 2. Multi-location: combine the latest result of every location by quorum.
        $state = $result;
        if ($monitor->probes()->exists()) {
            $meta['locations'][$location] = [
                'status' => $result->status->value, 'ms' => $result->responseMs,
                'message' => mb_substr($result->message, 0, 200), 'at' => time(),
            ];
            $state = $this->aggregate($monitor, $meta['locations'], $result);
        }

        // 3. Retries: a failure only becomes "down" after N consecutive failed evaluations.
        $failures = $state->isDown() ? $monitor->consecutive_failures + 1 : 0;
        $confirmedDown = $state->isDown() && $failures > $monitor->retries;

        $newStatus = match (true) {
            $inMaintenance => MonitorStatus::Maintenance,
            $confirmedDown => MonitorStatus::Down,
            $state->isDown() => in_array($previous, [MonitorStatus::Up, MonitorStatus::Warning], true) ? $previous : MonitorStatus::Pending,
            default => $state->status,
        };

        $message = $state->message;
        if ($state->isDown() && ! $confirmedDown && ! $inMaintenance) {
            $message = __('Retrying (:n/:m): :msg', ['n' => $failures, 'm' => $monitor->retries + 1, 'msg' => $message]);
        }

        // 4. Dependency awareness: children of a down parent do not page anyone.
        $suppressed = false;
        if ($confirmedDown && $monitor->parent && $monitor->parent->status === MonitorStatus::Down) {
            $suppressed = true;
            $message = __('Affected by dependency ":p" being down. :msg', ['p' => $monitor->parent->name, 'msg' => $message]);
        }

        $rawCode = $inMaintenance ? MonitorStatus::HB_MAINTENANCE : $result->status->heartbeatCode();
        $stateCode = $inMaintenance ? MonitorStatus::HB_MAINTENANCE : $state->status->heartbeatCode();

        // Local diagnostics win; remote ones are used when this server does not check itself.
        if ($isLocal || ! $monitor->setting('check_local', true)) {
            $meta = array_merge($meta, $result->meta, [
                'last_details' => Arr::except($result->details, ['certificate.san']),
                'causes' => $result->details['causes'] ?? [],
            ]);
        }

        DB::transaction(function () use ($monitor, $result, $rawCode, $stateCode, $message, $newStatus, $previous, $failures, $meta, $location, $isLocal) {
            Heartbeat::create([
                'monitor_id' => $monitor->id,
                'status' => $rawCode,
                'response_ms' => $result->responseMs,
                'message' => mb_substr($isLocal ? $message : $result->message, 0, 500),
                // Keep the table lean: full diagnostics only for failures/warnings.
                'details' => $rawCode === MonitorStatus::HB_UP ? null : Arr::only($result->details, ['status_code', 'timings', 'causes', 'steps', 'error', 'anomaly', 'changes']),
                'location' => $location,
            ]);

            $this->recordDaily($monitor->id, $stateCode, $result->responseMs);

            $monitor->forceFill([
                'status' => $newStatus,
                'consecutive_failures' => $failures,
                'last_response_ms' => $isLocal || $monitor->last_response_ms === null ? $result->responseMs : $monitor->last_response_ms,
                'last_message' => mb_substr($message, 0, 500),
                'last_checked_at' => now(),
                'meta' => $meta,
                'status_changed_at' => $newStatus !== $previous ? now() : $monitor->status_changed_at,
            ])->save();
        });

        if (! $inMaintenance) {
            $this->handleTransition($monitor, $previous, $newStatus, $message, $suppressed);
        }

        return $isLocal && ! $result->isDown() && (bool) $monitor->setting('visual');
    }

    /** Quorum over fresh per-location results: e.g. "down from Iran only → likely ISP/geo issue". */
    private function aggregate(Monitor $monitor, array $locations, CheckResult $current): CheckResult
    {
        $fresh = array_filter($locations, fn ($l) => ($l['at'] ?? 0) >= time() - max(180, $monitor->interval * 3));
        if (! $monitor->setting('check_local', true)) {
            unset($fresh[config('watchrex.location')]);
        }
        if (! $fresh) {
            return $current;
        }

        $labels = LocationNames::map();
        $down = array_keys(array_filter($fresh, fn ($l) => $l['status'] === 'down'));
        $up = array_diff(array_keys($fresh), $down);
        $name = fn (array $keys) => implode(', ', array_map(fn ($k) => $labels[$k] ?? $k, $keys));

        $needed = match ($monitor->setting('quorum', 'majority')) {
            'any' => 1,
            'all' => count($fresh),
            default => intdiv(count($fresh), 2) + 1,
        };

        if (count($down) >= $needed) {
            $msg = $current->isDown() ? $current->message : ($fresh[$down[0]]['message'] ?? '');

            return new CheckResult(MonitorStatus::Down, $current->responseMs, __('Down from :locs: :msg', ['locs' => $name($down), 'msg' => $msg]), $current->details, $current->meta);
        }

        if ($down) {
            return new CheckResult(MonitorStatus::Warning, $current->responseMs,
                __('Unreachable from :down but up from :up — likely a network, ISP or geo-specific issue.', ['down' => $name($down), 'up' => $name($up)]),
                $current->details, $current->meta);
        }

        $degraded = array_keys(array_filter($fresh, fn ($l) => $l['status'] === 'warning'));
        if ($current->status === MonitorStatus::Up && $degraded) {
            $first = $fresh[$degraded[0]];

            return new CheckResult(MonitorStatus::Warning, $current->responseMs, $name([$degraded[0]]).': '.$first['message'], $current->details, $current->meta);
        }

        return $current;
    }

    /**
     * EWMA baseline (mean + variance) — O(1) memory per monitor.
     *
     * @return array{0: array, 1: float|null} new baseline and anomaly factor (null if normal)
     */
    private function baseline(?array $baseline, int $ms): array
    {
        $cfg = config('watchrex.anomaly');
        $alpha = 0.05;
        $baseline ??= ['mean' => $ms, 'var' => 0, 'n' => 0];

        $mean = (float) $baseline['mean'];
        $sd = sqrt(max(0, (float) $baseline['var']));
        $anomaly = null;

        if ($cfg['enabled'] && $baseline['n'] >= $cfg['min_samples'] && $ms > $cfg['min_ms']
            && $ms > $mean * $cfg['min_factor'] && $ms > $mean + $cfg['sigma'] * $sd) {
            $anomaly = round($ms / max(1, $mean), 1);
        }

        // Anomalies are absorbed slowly so a sustained regression becomes the new normal.
        $a = $anomaly ? $alpha / 5 : $alpha;
        $diff = $ms - $mean;
        $newMean = $mean + $a * $diff;
        $newVar = (1 - $a) * ((float) $baseline['var'] + $a * $diff * $diff);

        return [['mean' => round($newMean, 2), 'var' => round($newVar, 2), 'n' => min(100000, $baseline['n'] + 1)], $anomaly];
    }

    private function recordDaily(int $monitorId, int $code, ?int $ms): void
    {
        $date = now()->toDateString();
        $column = match ($code) {
            MonitorStatus::HB_UP => 'up',
            MonitorStatus::HB_WARNING => 'warning',
            MonitorStatus::HB_DOWN => 'down',
            default => null,
        };

        $updates = ['checks' => DB::raw('checks + 1')];
        if ($column) {
            $updates[$column] = DB::raw("{$column} + 1");
        }
        if ($ms !== null && $code !== MonitorStatus::HB_DOWN) {
            $updates['response_sum'] = DB::raw('response_sum + '.(int) $ms);
            $updates['response_count'] = DB::raw('response_count + 1');
            $updates['min_ms'] = DB::raw('CASE WHEN min_ms IS NULL OR min_ms > '.(int) $ms.' THEN '.(int) $ms.' ELSE min_ms END');
            $updates['max_ms'] = DB::raw('CASE WHEN max_ms IS NULL OR max_ms < '.(int) $ms.' THEN '.(int) $ms.' ELSE max_ms END');
        }

        $affected = MonitorDailyStat::where('monitor_id', $monitorId)->where('date', $date)->update($updates);

        if ($affected === 0) {
            MonitorDailyStat::insertOrIgnore([
                'monitor_id' => $monitorId, 'date' => $date, 'checks' => 0, 'up' => 0, 'down' => 0, 'warning' => 0,
                'response_sum' => 0, 'response_count' => 0,
            ]);
            MonitorDailyStat::where('monitor_id', $monitorId)->where('date', $date)->update($updates);
        }
    }

    private function handleTransition(Monitor $monitor, MonitorStatus $from, MonitorStatus $to, string $message, bool $suppressed): void
    {
        $open = Incident::where('monitor_id', $monitor->id)->whereNull('resolved_at')->latest('id')->first();
        $notifier = app(Notifier::class);

        if ($to === MonitorStatus::Down && $from !== MonitorStatus::Down) {
            if ($open) {
                $open->update(['severity' => 'critical', 'cause' => mb_substr($message, 0, 500)]);
                $open->timeline('escalated', __('Service DOWN: :m', ['m' => $message]));
            } else {
                $open = Incident::create([
                    'user_id' => $monitor->user_id, 'monitor_id' => $monitor->id,
                    'title' => __(':name is down', ['name' => $monitor->name]),
                    'severity' => 'critical', 'status' => 'open',
                    'cause' => mb_substr($message, 0, 500), 'started_at' => now(),
                ]);
                $open->timeline('down', $message);
            }
            if (! $suppressed) {
                $notifier->monitorEvent($monitor, 'down', $message, $open);
                NotifySubscribers::dispatch($open->id, 'down')->onQueue(config('watchrex.queues.alerts'));
            }
            $monitor->forceFill(['meta' => array_merge($monitor->meta ?? [], ['last_alert_at' => time()])])->save();

            return;
        }

        if ($to === MonitorStatus::Warning && $from === MonitorStatus::Up) {
            if (! $open) {
                $open = Incident::create([
                    'user_id' => $monitor->user_id, 'monitor_id' => $monitor->id,
                    'title' => __(':name is degraded', ['name' => $monitor->name]),
                    'severity' => 'warning', 'status' => 'open',
                    'cause' => mb_substr($message, 0, 500), 'started_at' => now(),
                ]);
                $open->timeline('warning', $message);
            }
            if ($monitor->setting('notify_warning', true)) {
                $notifier->monitorEvent($monitor, 'warning', $message, $open);
            }

            return;
        }

        if ($to === MonitorStatus::Warning && $from === MonitorStatus::Down && $open) {
            $open->update(['severity' => 'warning']);
            $open->timeline('partial', __('Reachable again but degraded: :m', ['m' => $message]));
            NotifySubscribers::dispatch($open->id, 'recovered')->onQueue(config('watchrex.queues.alerts'));
            $notifier->monitorEvent($monitor, 'recovered', __(':name is reachable again (degraded): :m', ['name' => $monitor->name, 'm' => $message]), $open);

            return;
        }

        if ($to === MonitorStatus::Up && in_array($from, [MonitorStatus::Down, MonitorStatus::Warning], true) && $open) {
            $duration = (int) $open->started_at->diffInSeconds(now());
            $open->update(['resolved_at' => now(), 'status' => 'resolved', 'duration' => $duration]);
            $open->timeline('recovered', __('Recovered after :d', ['d' => Format::duration($duration)]));

            if ($open->severity === 'critical') {
                NotifySubscribers::dispatch($open->id, 'recovered')->onQueue(config('watchrex.queues.alerts'));
            }

            if ($from === MonitorStatus::Down || $monitor->setting('notify_warning', true)) {
                $notifier->monitorEvent($monitor, 'recovered', __(':name recovered after :d', ['name' => $monitor->name, 'd' => Format::duration($duration)]), $open);
            }

            return;
        }

        // Still down: optional reminders every N minutes.
        $remind = (int) $monitor->setting('remind_minutes', 0);
        if ($to === MonitorStatus::Down && $remind > 0 && ! $suppressed) {
            $last = (int) $monitor->metaValue('last_alert_at', 0);
            if (time() - $last >= $remind * 60) {
                $notifier->monitorEvent($monitor, 'reminder', __('Still down: :m', ['m' => $message]), $open);
                $monitor->forceFill(['meta' => array_merge($monitor->meta ?? [], ['last_alert_at' => time()])])->save();
            }
        }
    }
}
