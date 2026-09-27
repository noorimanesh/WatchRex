<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Heartbeat;
use App\Models\MonitorDailyStat;
use App\Models\ServerMetric;
use Illuminate\Console\Command;

class PruneData extends Command
{
    protected $signature = 'watchrex:prune';

    protected $description = 'Delete raw data older than the configured retention';

    public function handle(): int
    {
        $r = config('watchrex.retention');

        $deleted = [
            'heartbeats' => $this->chunkDelete(Heartbeat::class, now()->subDays($r['heartbeats_days'])),
            'server_metrics' => $this->chunkDelete(ServerMetric::class, now()->subDays($r['server_metrics_days'])),
            'audit_logs' => $this->chunkDelete(AuditLog::class, now()->subDays($r['audit_days'])),
            'daily_stats' => MonitorDailyStat::where('date', '<', now()->subDays($r['daily_stats_days'])->toDateString())->delete(),
        ];

        foreach ($deleted as $table => $n) {
            $this->line("{$table}: {$n}");
        }

        return self::SUCCESS;
    }

    /** Delete in small batches to avoid long table locks on busy installs. */
    private function chunkDelete(string $model, $before): int
    {
        $total = 0;
        do {
            $ids = $model::where('created_at', '<', $before)->limit(5000)->pluck('id');
            $total += $ids->isEmpty() ? 0 : $model::whereIn('id', $ids)->delete();
        } while ($ids->count() === 5000);

        return $total;
    }
}
