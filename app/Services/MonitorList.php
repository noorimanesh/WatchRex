<?php

namespace App\Services;

use App\Enums\MonitorType;
use App\Models\Heartbeat;
use App\Models\Monitor;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Filtered monitor listing shared by the dashboard, the monitor index and the live refresh endpoint. */
class MonitorList
{
    public const FILTERS = ['q', 'status', 'type', 'category', 'group', 'tag', 'owner'];

    public static function query(User $user, Request $request)
    {
        $f = $request->only(self::FILTERS);

        return Monitor::visibleTo($user)
            ->with(['user:id,name', 'server:id,name'])
            ->when($f['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->orWhere('target', 'like', "%{$term}%")))
            ->when($f['status'] ?? null, fn ($q, $s) => $s === 'paused' ? $q->where('is_active', false) : $q->where('is_active', true)->where('status', $s))
            ->when($f['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($f['category'] ?? null, fn ($q, $c) => $q->whereIn('type', collect(MonitorType::cases())->filter(fn ($t) => $t->category() === $c)->map->value->all()))
            ->when($f['group'] ?? null, fn ($q, $g) => $q->where('group', $g))
            ->when($f['tag'] ?? null, fn ($q, $t) => $q->where('tags', 'like', '%"'.str_replace(['%', '_', '"'], '', $t).'"%'))
            ->when($user->isAdmin() && ($f['owner'] ?? null), fn ($q) => $q->where('user_id', (int) $f['owner']))
            ->orderByRaw("CASE status WHEN 'down' THEN 0 WHEN 'warning' THEN 1 WHEN 'pending' THEN 2 ELSE 3 END")
            ->orderBy('group')
            ->orderBy('name');
    }

    /** Attaches 24h uptime, avg response and the last beats (for the mini status bar) in 2 queries. */
    public static function decorate(Collection $monitors, int $beats = 30): Collection
    {
        $ids = $monitors->pluck('id')->all();
        $uptime = Uptime::last24h($ids);
        $recent = self::recentBeats($ids, $beats);

        return $monitors->each(function (Monitor $m) use ($uptime, $recent) {
            $m->uptime_24h = $uptime[$m->id]['uptime'] ?? null;
            $m->avg_24h = $uptime[$m->id]['avg'] ?? null;
            $m->beats = $recent[$m->id] ?? collect();
        });
    }

    public static function recentBeats(array $ids, int $limit = 30): Collection
    {
        if (! $ids) {
            return collect();
        }

        $sub = Heartbeat::query()
            ->select('monitor_id', 'status', 'response_ms', 'message', 'created_at')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY monitor_id ORDER BY id DESC) as rn')
            ->whereIn('monitor_id', $ids)
            ->where('created_at', '>=', now()->subDays(2));

        return Heartbeat::query()->fromSub($sub, 'h')->where('rn', '<=', $limit)
            ->get()
            ->groupBy('monitor_id')
            ->map(fn ($rows) => $rows->sortBy('created_at')->values());
    }

    /** Distinct groups and tags for filter dropdowns. */
    public static function facets(User $user): array
    {
        $rows = Monitor::visibleTo($user)->get(['group', 'tags']);

        return [
            'groups' => $rows->pluck('group')->filter()->unique()->sort()->values(),
            'tags' => $rows->pluck('tags')->flatten()->filter()->unique()->sort()->values(),
        ];
    }
}
