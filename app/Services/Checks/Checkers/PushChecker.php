<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\Checker;
use App\Services\Checks\CheckResult;

/** Passive monitor: cron jobs / scripts call /api/push/{token}; silence means down. */
class PushChecker implements Checker
{
    public function check(Monitor $monitor): CheckResult
    {
        $grace = (int) $monitor->setting('grace', 60);
        $last = $monitor->last_push_at;

        if (! $last) {
            $since = $monitor->created_at ?? now();

            return $since->lt(now()->subSeconds($monitor->interval + $grace))
                ? CheckResult::down(__('No push received yet.'))
                : CheckResult::up(null, __('Waiting for first push'));
        }

        if ($last->lt(now()->subSeconds($monitor->interval + $grace))) {
            return CheckResult::down(__('No heartbeat received for :t', ['t' => $last->diffForHumans(null, true)]));
        }

        $status = $monitor->metaValue('push_status', 'up');
        $message = (string) $monitor->metaValue('push_msg', 'OK');
        $ms = $monitor->metaValue('push_ping');

        return $status === 'down'
            ? CheckResult::down($message ?: __('Job reported failure'), $ms)
            : CheckResult::up($ms, $message ?: 'OK');
    }
}
