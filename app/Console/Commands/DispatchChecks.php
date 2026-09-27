<?php

namespace App\Console\Commands;

use App\Jobs\RunMonitorCheck;
use App\Models\Monitor;
use Illuminate\Console\Command;

class DispatchChecks extends Command
{
    protected $signature = 'watchrex:dispatch {--sync : Run checks inline instead of queueing them}';

    protected $description = 'Queue checks for every monitor that is due';

    public function handle(): int
    {
        $now = now();
        $count = 0;

        Monitor::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now))
            ->select(['id', 'interval'])
            ->chunkById(500, function ($monitors) use ($now, &$count) {
                foreach ($monitors as $monitor) {
                    // Claim the slot first so overlapping dispatchers never double-run a monitor.
                    $claimed = Monitor::whereKey($monitor->id)
                        ->where(fn ($q) => $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', $now))
                        ->update(['next_check_at' => $now->copy()->addSeconds(max(10, $monitor->interval))]);

                    if ($claimed) {
                        $this->option('sync')
                            ? RunMonitorCheck::dispatchSync($monitor->id)
                            : RunMonitorCheck::dispatch($monitor->id)->onQueue(config('watchrex.queues.checks'));
                        $count++;
                    }
                }
            });

        cache()->forever('watchrex:scheduler_heartbeat', $now->timestamp);

        if ($this->output->isVerbose()) {
            $this->info("Dispatched {$count} check(s).");
        }

        return self::SUCCESS;
    }
}
