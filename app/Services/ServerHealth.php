<?php

namespace App\Services;

use App\Models\Server;

class ServerHealth
{
    /** @return list<string> threshold violations for the latest agent report */
    public static function problems(Server $server): array
    {
        $problems = [];
        $checks = [
            'cpu' => ['CPU', '%'],
            'ram' => ['RAM', '%'],
            'disk' => [__('Disk'), '%'],
            'load' => ['Load', ''],
            'mail_queue' => [__('Mail queue'), ''],
            'login_failed' => [__('Failed logins'), ''],
        ];

        foreach ($checks as $key => [$label, $unit]) {
            $limit = $server->threshold($key);
            $value = $key === 'load' ? $server->stat('load.0') : $server->stat($key === 'login_failed' ? 'mail.login_failed' : ($key === 'mail_queue' ? 'mail.queue' : $key));
            if ($limit !== null && $value !== null && (float) $value >= $limit) {
                $problems[] = "{$label} {$value}{$unit} ≥ {$limit}{$unit}";
            }
        }

        foreach ((array) $server->stat('disks', []) as $disk) {
            $limit = $server->threshold('disk');
            if ($limit !== null && ($disk['mount'] ?? '/') !== '/' && (float) ($disk['percent'] ?? 0) >= $limit) {
                $problems[] = __('Disk :m :p%', ['m' => $disk['mount'], 'p' => $disk['percent']]);
            }
        }

        foreach ((array) $server->stat('services', []) as $name => $state) {
            if (in_array($state, ['failed', 'inactive', 'dead'], true)) {
                $problems[] = __('Service :s is :state', ['s' => $name, 'state' => $state]);
            }
        }

        foreach ((array) $server->stat('containers', []) as $c) {
            if (isset($c['state']) && $c['state'] !== 'running' && ($c['watch'] ?? true)) {
                $problems[] = __('Container :c is :s', ['c' => $c['name'] ?? '?', 's' => $c['state']]);
            }
        }

        return $problems;
    }
}
