<?php

namespace App\Services;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use App\Models\MonitorGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates the health of groups (including nested sub-groups) in a handful of
 * queries: worst status, member counts, 24h uptime and average response.
 */
class GroupHealth
{
    private const SEVERITY = ['down' => 4, 'warning' => 3, 'pending' => 2, 'maintenance' => 1, 'up' => 0, 'paused' => -1];

    /**
     * @param  Collection<int, MonitorGroup>  $groups
     * @return array<int, array{status:string, total:int, up:int, down:int, warning:int, uptime:?float, avg:?int, monitor_ids:list<int>}>
     */
    public static function for(Collection $groups): array
    {
        if ($groups->isEmpty()) {
            return [];
        }

        // Parent → children map for every group of the involved owners (to include nested groups).
        $all = MonitorGroup::whereIn('user_id', $groups->pluck('user_id')->unique())->get(['id', 'parent_id']);
        $children = [];
        foreach ($all as $g) {
            if ($g->parent_id) {
                $children[$g->parent_id][] = $g->id;
            }
        }

        $members = DB::table('group_monitor')->whereIn('monitor_group_id', $all->pluck('id'))->get()
            ->groupBy('monitor_group_id')->map(fn ($rows) => $rows->pluck('monitor_id')->all());

        $collect = function (int $id, array $seen = []) use (&$collect, $children, $members): array {
            if (isset($seen[$id])) {
                return [];
            }
            $seen[$id] = true;
            $ids = $members[$id] ?? [];
            foreach ($children[$id] ?? [] as $kid) {
                $ids = array_merge($ids, $collect($kid, $seen));
            }

            return array_values(array_unique($ids));
        };

        $byGroup = [];
        foreach ($groups as $group) {
            $byGroup[$group->id] = $collect($group->id);
        }

        $allIds = array_values(array_unique(array_merge([], ...array_values($byGroup))));
        $monitors = Monitor::whereIn('id', $allIds)->get(['id', 'status', 'is_active', 'last_response_ms'])->keyBy('id');
        $uptime = Uptime::last24h($allIds);

        $out = [];
        foreach ($byGroup as $groupId => $ids) {
            $status = 'up';
            $counts = ['up' => 0, 'down' => 0, 'warning' => 0];
            $up = [];
            $resp = [];
            foreach ($ids as $id) {
                $m = $monitors[$id] ?? null;
                if (! $m) {
                    continue;
                }
                $s = $m->is_active === false ? 'paused' : $m->status->value;
                if ((self::SEVERITY[$s] ?? 0) > (self::SEVERITY[$status] ?? 0)) {
                    $status = $s;
                }
                if (isset($counts[$s])) {
                    $counts[$s]++;
                }
                if (isset($uptime[$id]['uptime'])) {
                    $up[] = $uptime[$id]['uptime'];
                }
                if ($m->last_response_ms !== null && $s !== 'down') {
                    $resp[] = $m->last_response_ms;
                }
            }

            $out[$groupId] = [
                'status' => $ids ? $status : 'pending',
                'total' => count($ids),
                ...$counts,
                'uptime' => $up ? round(array_sum($up) / count($up), 3) : null,
                'avg' => $resp ? (int) round(array_sum($resp) / count($resp)) : null,
                'monitor_ids' => $ids,
            ];
        }

        return $out;
    }

    public static function statusEnum(string $status): MonitorStatus
    {
        return MonitorStatus::tryFrom($status) ?? MonitorStatus::Pending;
    }
}
