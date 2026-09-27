<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\MonitorRunner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunMonitorCheck implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $monitorId) {}

    public function uniqueId(): string
    {
        return (string) $this->monitorId;
    }

    public int $uniqueFor = 300;

    public function handle(MonitorRunner $runner): void
    {
        $monitor = Monitor::find($this->monitorId);

        // Monitors checked only from remote probes are skipped on the hub.
        if ($monitor && $monitor->is_active && ($monitor->setting('check_local', true) || ! $monitor->probes()->exists())) {
            $runner->run($monitor);
        }
    }
}
