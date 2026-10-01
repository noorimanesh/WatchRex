<?php

namespace App\Services;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Incident;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\MonitorGroup;
use App\Models\StatusPage;
use App\Support\Svg;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds everything a public status page shows: one section per attached group
 * (members of nested sub-groups included) with aggregated uptime bars, website
 * details (SSL / domain expiry) and the full mail checklist for mail groups,
 * plus a section for individually attached monitors. A fixed number of queries
 * regardless of the number of groups.
 */
class StatusPageData
{
    private const MAIL_TYPES = [MonitorType::Smtp, MonitorType::Imap, MonitorType::Pop3];

    public static function build(StatusPage $page): array
    {
        $days = in_array((int) $page->option('history_days'), [30, 60, 90], true) ? (int) $page->option('history_days') : 90;
        $pageGroups = $page->groups()->get();
        $loose = $page->monitors()->get();

        // Group tree of the page owner(s) → descendants per attached group.
        $tree = $pageGroups->isEmpty() ? collect() : MonitorGroup::whereIn('user_id', $pageGroups->pluck('user_id')->unique())
            ->get(['id', 'user_id', 'parent_id', 'name', 'kind', 'domain']);
        $children = $tree->groupBy('parent_id');
        $members = $tree->isEmpty() ? collect() : DB::table('group_monitor')->whereIn('monitor_group_id', $tree->pluck('id'))->get()
            ->groupBy('monitor_group_id')->map(fn ($rows) => $rows->pluck('monitor_id')->all());

        $descendants = function (int $id) use ($children): array {
            $ids = [$id];
            $frontier = [$id];
            while ($frontier) {
                $next = [];
                foreach ($frontier as $f) {
                    foreach ($children->get($f, collect()) as $c) {
                        if (! in_array($c->id, $ids, true)) {
                            $ids[] = $c->id;
                            $next[] = $c->id;
                        }
                    }
                }
                $frontier = $next;
            }

            return $ids;
        };

        $groupMonitorIds = [];
        $groupTree = [];
        foreach ($pageGroups as $g) {
            $groupTree[$g->id] = $descendants($g->id);
            $groupMonitorIds[$g->id] = array_values(array_unique(array_merge([], ...array_map(fn ($id) => $members[$id] ?? [], $groupTree[$g->id]))));
        }

        $allIds = array_values(array_unique(array_merge($loose->pluck('id')->all(), ...array_values($groupMonitorIds))));
        $monitors = Monitor::whereIn('id', $allIds ?: [0])->get()->keyBy('id');
        $last24 = Uptime::last24h($allIds);
        $bars = Uptime::dailyBarsMany($allIds, $days);
        $looseNames = $loose->mapWithKeys(fn ($m) => [$m->id => $m->pivot->display_name]);

        foreach ($monitors as $m) {
            $m->public_name = $looseNames[$m->id] ?? $m->name;
            $m->public_status = $m->is_active === false ? MonitorStatus::Paused->value : $m->status->value;
            $m->uptime_24h = $last24[$m->id]['uptime'] ?? null;
            $m->bars = $bars[$m->id];
            $m->uptime_period = Uptime::fromBars($m->bars);
            $m->uptime_90d = $m->uptime_period;
        }

        $sections = [];
        $covered = [];
        foreach ($pageGroups as $g) {
            $ids = $groupMonitorIds[$g->id];
            $covered = array_merge($covered, $ids);
            $list = self::sorted(collect($ids)->map(fn ($id) => $monitors[$id] ?? null)->filter());
            $groupBars = Uptime::groupBars($ids, $days);
            $treeGroups = $tree->whereIn('id', $groupTree[$g->id]);

            $sections[] = [
                'key' => 'g'.$g->id,
                'id' => $g->id,
                'name' => $g->pivot->display_name ?: $g->name,
                'kind' => $g->kind,
                'icon' => $g->icon(),
                'domain' => $g->domain,
                'expanded' => (bool) $g->pivot->expanded,
                'status' => self::worst($list),
                'counts' => self::counts($list),
                'uptime' => Uptime::fromBars($groupBars),
                'uptime_24h' => self::avg($list->pluck('uptime_24h')),
                'avg_ms' => self::avg($list->filter(fn ($m) => $m->public_status !== 'down')->pluck('last_response_ms')),
                'bars' => $groupBars,
                'chart' => $page->option('show_chart') ? Svg::sparkline($groupBars->pluck('avg')->all(), 160, 34) : '',
                'details' => $page->option('show_details') ? self::details($g, $list) : [],
                'mail' => $page->option('show_mail_health') ? self::mail($g, $treeGroups, $list) : null,
                'monitors' => $list,
                'subgroups' => $treeGroups->where('id', '!=', $g->id)->map(fn ($s) => ['name' => $s->name, 'kind' => $s->kind])->values()->all(),
            ];
        }

        $rest = self::sorted($loose->map(fn ($m) => $monitors[$m->id])->reject(fn ($m) => in_array($m->id, $covered, true))->values(), false);
        if ($rest->isNotEmpty()) {
            $restBars = Uptime::groupBars($rest->pluck('id')->all(), $days);
            $sections[] = [
                'key' => 'services', 'id' => null, 'name' => $pageGroups->isEmpty() ? __('Services') : __('Other services'), 'kind' => 'service', 'icon' => '⚙️', 'domain' => null,
                'expanded' => true, 'status' => self::worst($rest), 'counts' => self::counts($rest), 'uptime' => Uptime::fromBars($restBars),
                'uptime_24h' => self::avg($rest->pluck('uptime_24h')), 'avg_ms' => self::avg($rest->pluck('last_response_ms')), 'bars' => $restBars,
                'chart' => '', 'details' => [], 'mail' => null, 'monitors' => $rest, 'subgroups' => [], 'loose' => true,
            ];
        }

        $all = $monitors->values();
        $overallBars = Uptime::groupBars($allIds, $days);

        return [
            'sections' => $sections,
            'monitors' => $all,
            'overall' => self::overall($all),
            'counts' => self::counts($all),
            'uptime' => Uptime::fromBars($overallBars),
            'uptime_24h' => self::avg($all->pluck('uptime_24h')),
            'historyDays' => $days,
            'updatedAt' => now(),
            'incidents' => Incident::whereIn('monitor_id', $allIds ?: [0])->where('severity', 'critical')
                ->where('started_at', '>=', now()->subDays(max(1, min(90, (int) $page->option('incident_days')))))
                ->with(['updates' => fn ($q) => $q->whereIn('type', ['down', 'recovered', 'public', 'resolved'])])->latest('started_at')->limit(30)->get(),
            'maintenance' => MaintenanceWindow::whereHas('monitors', fn ($q) => $q->whereIn('monitors.id', $allIds ?: [0]))
                ->where('ends_at', '>=', now())->orderBy('starts_at')->limit(5)->get(),
        ];
    }

    /** Overall page status: major outage when at least half of the services are down. */
    public static function overall(Collection $monitors): string
    {
        $active = $monitors->reject(fn ($m) => $m->public_status === 'paused');
        $down = $active->where('public_status', 'down')->count();

        return match (true) {
            $down > 0 && $down >= max(1, (int) ceil($active->count() / 2)) => 'major_outage',
            $down > 0 => 'partial_outage',
            $active->where('public_status', 'warning')->isNotEmpty() => 'degraded',
            $active->where('public_status', 'maintenance')->isNotEmpty() => 'maintenance',
            default => 'operational',
        };
    }

    private static function sorted(Collection $list, bool $byStatus = true): Collection
    {
        $order = ['down' => 0, 'warning' => 1, 'maintenance' => 2, 'pending' => 3, 'up' => 4, 'paused' => 5];

        return $byStatus
            ? $list->sortBy([fn ($a, $b) => ($order[$a->public_status] ?? 9) <=> ($order[$b->public_status] ?? 9), fn ($a, $b) => strnatcasecmp($a->public_name, $b->public_name)])->values()
            : $list;
    }

    private static function worst(Collection $list): string
    {
        foreach (['down', 'warning', 'maintenance', 'pending', 'up'] as $s) {
            if ($list->contains('public_status', $s)) {
                return $s;
            }
        }

        return $list->isEmpty() ? 'pending' : 'paused';
    }

    private static function counts(Collection $list): array
    {
        return ['total' => $list->count(), 'up' => $list->where('public_status', 'up')->count(), 'down' => $list->where('public_status', 'down')->count(), 'warning' => $list->where('public_status', 'warning')->count()];
    }

    private static function avg(Collection $values): float|int|null
    {
        $values = $values->filter(fn ($v) => $v !== null);

        return $values->isEmpty() ? null : round($values->avg(), 3);
    }

    /** Website facts: SSL and domain expiry, from the domain record or the HTTP monitors. */
    private static function details(MonitorGroup $group, Collection $list): array
    {
        $domain = $group->domainRecord();
        $ssl = $domain?->sslDaysLeft();
        if ($ssl === null) {
            $certs = $list->filter(fn ($m) => $m->type->usesUrl())->map(fn ($m) => $m->metaValue('ssl.days_left'))->filter(fn ($d) => $d !== null);
            $ssl = $certs->isEmpty() ? null : (int) $certs->min();
        }
        $expiry = $domain?->daysUntilExpiry();

        $rows = [];
        if ($ssl !== null) {
            $rows[] = ['key' => 'ssl', 'label' => __('SSL certificate'), 'value' => trans_choice(':count day left|:count days left', $ssl), 'status' => $ssl < 0 ? 'down' : ($ssl <= 14 ? 'warning' : 'up')];
        }
        if ($expiry !== null) {
            $rows[] = ['key' => 'domain', 'label' => __('Domain registration'), 'value' => trans_choice(':count day left|:count days left', $expiry), 'status' => $expiry < 0 ? 'down' : ($expiry <= 30 ? 'warning' : 'up')];
        }
        $http = $list->first(fn ($m) => $m->type->usesUrl() && $m->last_response_ms !== null);
        if ($http) {
            $rows[] = ['key' => 'response', 'label' => __('Response time'), 'value' => Format::ms($http->last_response_ms), 'status' => $http->public_status === 'down' ? 'down' : 'up'];
        }

        return $rows;
    }

    /** Mail checklist when the group is (or contains) a mail group or mail monitors. */
    private static function mail(MonitorGroup $group, Collection $treeGroups, Collection $list): ?array
    {
        $mailGroups = $treeGroups->where('kind', 'mail');
        $mailMonitors = $list->filter(fn ($m) => in_array($m->type, self::MAIL_TYPES, true));
        if ($mailGroups->isEmpty() && $mailMonitors->isEmpty()) {
            return null;
        }

        $domain = $group->domainRecord();
        foreach ($mailGroups as $mg) {
            $domain ??= $mg->domainRecord();
        }

        // Public view: no raw error messages from the checks.
        $components = array_map(fn ($c) => array_diff_key($c, ['message' => true]), MailHealth::components($mailMonitors, $domain));
        if (! $components) {
            return null;
        }

        return ['components' => $components, 'overall' => MailHealth::overall($components), 'score' => MailHealth::score($components)];
    }
}
