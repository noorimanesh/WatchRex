<?php

namespace App\Services;

use App\Enums\MonitorStatus;
use App\Models\Monitor;
use Illuminate\Support\Collection;

/**
 * Lays out monitor dependencies (parent → children) as a left-to-right tree
 * for server-side SVG rendering, and computes the impact of failing nodes.
 */
class DependencyMap
{
    public const NODE_W = 210;

    public const NODE_H = 56;

    private const GAP_X = 80;

    private const GAP_Y = 18;

    /** @param Collection<int, Monitor> $monitors all monitors the viewer may see */
    public static function build(Collection $monitors, ?int $focusId = null): array
    {
        $byId = $monitors->keyBy('id');
        $children = [];
        foreach ($monitors as $m) {
            if ($m->parent_id && $byId->has($m->parent_id)) {
                $children[$m->parent_id][] = $m->id;
            }
        }

        // Only monitors that take part in a dependency are drawn.
        $inGraph = fn (Monitor $m) => isset($children[$m->id]) || ($m->parent_id && $byId->has($m->parent_id));
        $roots = $monitors->filter(fn (Monitor $m) => $inGraph($m) && ! ($m->parent_id && $byId->has($m->parent_id)));

        if ($focusId && $byId->has($focusId)) {
            // Focus mode: only the tree that contains this monitor.
            $top = $byId[$focusId];
            $seen = [];
            while ($top->parent_id && $byId->has($top->parent_id) && ! isset($seen[$top->id])) {
                $seen[$top->id] = true;
                $top = $byId[$top->parent_id];
            }
            $roots = collect([$top])->filter($inGraph);
        }

        $nodes = [];
        $edges = [];
        $row = 0;
        $visited = [];

        $place = function (int $id, int $depth) use (&$place, &$nodes, &$edges, &$row, &$visited, $children, $byId): float {
            if (isset($visited[$id])) {
                return $nodes[$id]['y'] ?? 0; // cycle guard
            }
            $visited[$id] = true;

            $kids = $children[$id] ?? [];
            $ys = [];
            foreach ($kids as $kid) {
                $ys[] = $place($kid, $depth + 1);
                $edges[] = ['from' => $id, 'to' => $kid];
            }

            $y = $ys ? (min($ys) + max($ys)) / 2 : $row++ * (self::NODE_H + self::GAP_Y);
            $nodes[$id] = ['x' => $depth * (self::NODE_W + self::GAP_X), 'y' => $y, 'monitor' => $byId[$id], 'depth' => $depth];

            return $y;
        };

        foreach ($roots->sortBy('name') as $root) {
            $place($root->id, 0);
            $row++; // breathing room between trees
        }

        // Impact: every descendant of a down node is affected.
        $impacts = [];
        foreach ($nodes as $id => $node) {
            if ($node['monitor']->status === MonitorStatus::Down) {
                $affected = self::descendants($id, $children);
                if ($affected) {
                    $impacts[] = ['monitor' => $node['monitor'], 'affected' => collect($affected)->map(fn ($a) => $byId[$a])->values()];
                }
            }
        }
        $impacted = collect($impacts)->flatMap(fn ($i) => $i['affected']->pluck('id'))->flip();

        foreach ($edges as &$edge) {
            $from = $nodes[$edge['from']];
            $to = $nodes[$edge['to']];
            $x1 = $from['x'] + self::NODE_W;
            $y1 = $from['y'] + self::NODE_H / 2;
            $x2 = $to['x'];
            $y2 = $to['y'] + self::NODE_H / 2;
            $mid = ($x1 + $x2) / 2;
            $edge['path'] = "M{$x1} {$y1} C{$mid} {$y1} {$mid} {$y2} {$x2} {$y2}";
            $edge['broken'] = $from['monitor']->status === MonitorStatus::Down;
        }
        unset($edge);

        foreach ($nodes as $id => &$node) {
            $node['impacted'] = isset($impacted[$id]);
        }
        unset($node);

        $maxX = $nodes ? max(array_column($nodes, 'x')) + self::NODE_W : 0;
        $maxY = $nodes ? max(array_column($nodes, 'y')) + self::NODE_H : 0;

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'impacts' => $impacts,
            'width' => $maxX + 20,
            'height' => $maxY + 20,
            'independent' => $monitors->count() - count($nodes),
        ];
    }

    /** @return list<int> */
    public static function descendants(int $id, array $children, array $seen = []): array
    {
        $out = [];
        foreach ($children[$id] ?? [] as $kid) {
            if (isset($seen[$kid])) {
                continue;
            }
            $seen[$kid] = true;
            $out[] = $kid;
            $out = array_merge($out, self::descendants($kid, $children, $seen));
        }

        return array_values(array_unique($out));
    }

    /** True if making $parentId the parent of $monitorId would create a loop. */
    public static function createsCycle(int $monitorId, ?int $parentId): bool
    {
        $seen = [];
        while ($parentId) {
            if ($parentId === $monitorId || isset($seen[$parentId])) {
                return true;
            }
            $seen[$parentId] = true;
            $parentId = Monitor::whereKey($parentId)->value('parent_id');
        }

        return false;
    }
}
