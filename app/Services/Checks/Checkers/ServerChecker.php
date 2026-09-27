<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\Checker;
use App\Services\Checks\CheckResult;
use App\Services\ServerHealth;

/** Evaluates the latest report from the WatchRex agent against thresholds. */
class ServerChecker implements Checker
{
    public function check(Monitor $monitor): CheckResult
    {
        $server = $monitor->server;

        if (! $server) {
            return CheckResult::down(__('No server linked.'));
        }

        if (! $server->isOnline()) {
            return CheckResult::down($server->last_seen_at
                ? __('Agent silent since :t', ['t' => $server->last_seen_at->diffForHumans()])
                : __('Agent has not reported yet.'));
        }

        $problems = ServerHealth::problems($server);
        $summary = sprintf('CPU %s%% · RAM %s%% · Disk %s%%', $server->stat('cpu', '?'), $server->stat('ram', '?'), $server->stat('disk', '?'));

        return $problems
            ? CheckResult::warning(implode(' · ', $problems))
            : CheckResult::up(null, $summary);
    }
}
