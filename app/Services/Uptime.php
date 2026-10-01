<?php

namespace App\Services;

use App\Enums\MonitorStatus;
use App\Models\Heartbeat;
use App\Models\Monitor;
use App\Models\MonitorDailyStat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Uptime maths. The last 24h are computed from raw heartbeats, longer periods
 * from the pre-aggregated daily table (so yearly uptime costs ~365 rows, not millions).
 */
class Uptime
{
    /** @param  list<int>  $ids  @return array<int, array{uptime: ?float, avg: ?int}> */
    public static function last24h(array $ids): array
    {
        if (! $ids) {
            return [];
        }

        $rows = Heartbeat::query()
            ->whereIn('monitor_id', $ids)
            ->where('created_at', '>=', now()->subDay())
            ->where('status', '!=', MonitorStatus::HB_MAINTENANCE)
            ->groupBy('monitor_id')
            ->select('monitor_id', DB::raw('COUNT(*) as total'), DB::raw('SUM(CASE WHEN status IN (1,2) THEN 1 ELSE 0 END) as ok'), DB::raw('AVG(CASE WHEN status IN (1,2) THEN response_ms END) as avg_ms'))
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row->monitor_id] = [
                'uptime' => $row->total > 0 ? round($row->ok / $row->total * 100, 3) : null,
                'avg' => $row->avg_ms !== null ? (int) round($row->avg_ms) : null,
            ];
        }

        return $out;
    }

    /** @return array<string, ?float> uptime per period: 24h, 7d, 30d, 90d, 365d */
    public static function periods(Monitor $monitor): array
    {
        $out = ['24h' => self::last24h([$monitor->id])[$monitor->id]['uptime'] ?? null];

        foreach ([7 => '7d', 30 => '30d', 90 => '90d', 365 => '365d'] as $days => $key) {
            $row = MonitorDailyStat::where('monitor_id', $monitor->id)
                ->where('date', '>', now()->subDays($days)->toDateString())
                ->selectRaw('SUM(up) as up, SUM(warning) as warning, SUM(down) as down')
                ->first();
            $total = (int) $row?->up + (int) $row?->warning + (int) $row?->down;
            $out[$key] = $total > 0 ? round(((int) $row->up + (int) $row->warning) / $total * 100, 3) : null;
        }

        return $out;
    }

    /** @return Collection<int, array{date: string, uptime: ?float, avg: ?int}> one entry per day, oldest first */
    public static function dailyBars(Monitor $monitor, int $days = 90): Collection
    {
        $stats = MonitorDailyStat::where('monitor_id', $monitor->id)
            ->where('date', '>', now()->subDays($days)->toDateString())
            ->get()
            ->keyBy(fn ($s) => $s->date->toDateString());

        return collect(range($days - 1, 0))->map(function ($ago) use ($stats) {
            $date = now()->subDays($ago)->toDateString();
            $stat = $stats->get($date);

            return ['date' => $date, 'uptime' => $stat?->uptime(), 'avg' => $stat?->avgResponse(), 'down' => $stat?->down ?? 0];
        });
    }

    /**
     * Daily bars for many monitors at once (one query).
     *
     * @param  list<int>  $ids
     * @return array<int, Collection> monitor id => bars (oldest first)
     */
    public static function dailyBarsMany(array $ids, int $days = 90): array
    {
        $stats = MonitorDailyStat::whereIn('monitor_id', $ids ?: [0])
            ->where('date', '>', now()->subDays($days)->toDateString())
            ->get()->groupBy('monitor_id');

        $out = [];
        foreach ($ids as $id) {
            $byDate = ($stats[$id] ?? collect())->keyBy(fn ($s) => $s->date->toDateString());
            $out[$id] = self::bars($days, fn (string $date) => $byDate->get($date));
        }

        return $out;
    }

    /** One bar per day aggregated over a set of monitors (a group). */
    public static function groupBars(array $ids, int $days = 90): Collection
    {
        $rows = MonitorDailyStat::whereIn('monitor_id', $ids ?: [0])
            ->where('date', '>', now()->subDays($days)->toDateString())
            ->groupBy('date')
            ->selectRaw('date, SUM(up) as up, SUM(warning) as warning, SUM(down) as down, SUM(response_sum) as response_sum, SUM(response_count) as response_count')
            ->get()
            ->keyBy(fn ($r) => substr((string) $r->date, 0, 10));

        return self::bars($days, fn (string $date) => $rows->get($date));
    }

    private static function bars(int $days, callable $lookup): Collection
    {
        return collect(range($days - 1, 0))->map(function ($ago) use ($lookup) {
            $date = now()->subDays($ago)->toDateString();
            $s = $lookup($date);
            $counted = $s ? (int) $s->up + (int) $s->warning + (int) $s->down : 0;

            return [
                'date' => $date,
                'uptime' => $counted ? round(((int) $s->up + (int) $s->warning) / $counted * 100, 3) : null,
                'avg' => $s && $s->response_count ? (int) round($s->response_sum / $s->response_count) : null,
                'down' => (int) ($s->down ?? 0),
            ];
        });
    }

    /** Average uptime over the bars that have data. */
    public static function fromBars(Collection $bars): ?float
    {
        $values = $bars->pluck('uptime')->filter(fn ($v) => $v !== null);

        return $values->count() ? round($values->avg(), 3) : null;
    }

    /** Aggregated overall uptime across monitors for the dashboard. */
    public static function overall(array $ids, int $days = 30): ?float
    {
        if (! $ids) {
            return null;
        }

        $row = MonitorDailyStat::whereIn('monitor_id', $ids)
            ->where('date', '>', now()->subDays($days)->toDateString())
            ->selectRaw('SUM(up) as up, SUM(warning) as warning, SUM(down) as down')
            ->first();
        $total = (int) $row?->up + (int) $row?->warning + (int) $row?->down;

        return $total > 0 ? round(((int) $row->up + (int) $row->warning) / $total * 100, 3) : null;
    }

    public static function barClass(?float $uptime): string
    {
        return match (true) {
            $uptime === null => 'nodata',
            $uptime >= 99.9 => 'up',
            $uptime >= 97 => 'warn',
            default => 'down',
        };
    }
}
