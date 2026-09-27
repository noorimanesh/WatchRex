<?php

namespace App\Services;

use App\Enums\MonitorStatus;
use App\Models\Heartbeat;
use App\Models\Incident;
use App\Models\Monitor;
use App\Models\MonitorDailyStat;
use App\Services\Alerting\Notifier;
use App\Services\Checks\CheckerFactory;
use App\Services\Checks\CheckResult;
use Illuminate\Support\Arr;
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

    public function process(Monitor $monitor, CheckResult $result): void
    {
        if ($monitor->user?->locale) {
            app()->setLocale($monitor->user->locale);
        }
        $previous = $monitor->status;
        $meta = $monitor->meta ?? [];
        $inMaintenance = $monitor->inMaintenance();

        // 1. Response-time threshold + anomaly detection (only for successful checks).
        if (! $result->isDown() && $result->responseMs !== null) {
            $warnMs = (int) $monitor->setting('response_warn_ms', 0);
            if ($warnMs > 0 && $result->responseMs > $warnMs) {
                $result->withWarning(__('Slow response: :ms ms (threshold :t ms)', ['ms' => $result->responseMs, 't' => $warnMs]));
            }

            [$meta['baseline'], $anomaly] = $this->baseline($meta['baseline'] ?? null, $result->responseMs);
            if ($anomaly && $monitor->setting('anomaly', true)) {
                $result->withWarning(__('Performance anomaly: response :ms ms is :x× higher than normal (:avg ms).', [
                    'ms' => $result->responseMs, 'x' => $anomaly, 'avg' => (int) $meta['baseline']['mean'],
                ]));
                $result->details['anomaly'] = $anomaly;
            }
        }

        // 2. Retries: a failure only becomes "down" after N consecutive failed checks.
        $failures = $result->isDown() ? $monitor->consecutive_failures + 1 : 0;
        $confirmedDown = $result->isDown() && $failures > $monitor->retries;

        $newStatus = match (true) {
            $inMaintenance => MonitorStatus::Maintenance,
            $confirmedDown => MonitorStatus::Down,
            $result->isDown() => in_array($previous, [MonitorStatus::Up, MonitorStatus::Warning], true) ? $previous : MonitorStatus::Pending,
            default => $result->status,
        };

        $message = $result->message;
        if ($result->isDown() && ! $confirmedDown && ! $inMaintenance) {
            $message = __('Retrying (:n/:m): :msg', ['n' => $failures, 'm' => $monitor->retries + 1, 'msg' => $message]);
        }

        // 3. Dependency awareness: children of a down parent do not page anyone.
        $suppressed = false;
        if ($confirmedDown && $monitor->parent && $monitor->parent->status === MonitorStatus::Down) {
            $suppressed = true;
            $message = __('Affected by dependency ":p" being down. :msg', ['p' => $monitor->parent->name, 'msg' => $message]);
        }

        $heartbeatCode = $inMaintenance ? MonitorStatus::HB_MAINTENANCE : $result->status->heartbeatCode();
        $meta = array_merge($meta, $result->meta, [
            'last_details' => Arr::except($result->details, ['certificate.san']),
            'causes' => $result->details['causes'] ?? [],
        ]);

        DB::transaction(function () use ($monitor, $result, $heartbeatCode, $message, $newStatus, $previous, $failures, $meta) {
            Heartbeat::create([
                'monitor_id' => $monitor->id,
                'status' => $heartbeatCode,
                'response_ms' => $result->responseMs,
                'message' => mb_substr($message, 0, 500),
                // Keep the table lean: full diagnostics only for failures/warnings.
                'details' => $heartbeatCode === MonitorStatus::HB_UP ? null : Arr::only($result->details, ['status_code', 'timings', 'causes', 'steps', 'error', 'anomaly']),
                'location' => config('watchrex.location'),
            ]);

            $this->recordDaily($monitor->id, $heartbeatCode, $result->responseMs);

            $monitor->forceFill([
                'status' => $newStatus,
                'consecutive_failures' => $failures,
                'last_response_ms' => $result->responseMs,
                'last_message' => mb_substr($message, 0, 500),
                'last_checked_at' => now(),
                'meta' => $meta,
                'status_changed_at' => $newStatus !== $previous ? now() : $monitor->status_changed_at,
            ])->save();
        });

        if (! $inMaintenance) {
            $this->handleTransition($monitor, $previous, $newStatus, $message, $suppressed);
        }
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
            $notifier->monitorEvent($monitor, 'recovered', __(':name is reachable again (degraded): :m', ['name' => $monitor->name, 'm' => $message]), $open);

            return;
        }

        if ($to === MonitorStatus::Up && in_array($from, [MonitorStatus::Down, MonitorStatus::Warning], true) && $open) {
            $duration = (int) $open->started_at->diffInSeconds(now());
            $open->update(['resolved_at' => now(), 'status' => 'resolved', 'duration' => $duration]);
            $open->timeline('recovered', __('Recovered after :d', ['d' => Format::duration($duration)]));

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
