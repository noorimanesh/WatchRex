<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckResult;

class RedisChecker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $start = microtime(true);
        $client = $this->client($monitor, $host, $port, $port === 6380 || $monitor->setting('security') === 'ssl');

        if ($password = $monitor->credential('password')) {
            $user = $monitor->credential('username');
            $client->write($this->resp($user ? ['AUTH', $user, $password] : ['AUTH', $password]));
            $auth = $client->readLine();
            if (! str_starts_with($auth, '+OK')) {
                return CheckResult::down(__('Redis AUTH failed: :r', ['r' => $auth]), $this->elapsed($start));
            }
        }

        $client->write($this->resp(['PING']));
        $pong = $client->readLine();

        if (str_starts_with($pong, '-NOAUTH')) {
            return CheckResult::up($this->elapsed($start), __('Redis alive (authentication required)'));
        }
        if ($pong !== '+PONG') {
            return CheckResult::down(__('Unexpected Redis reply: :r', ['r' => mb_substr($pong, 0, 120)]), $this->elapsed($start));
        }

        $ms = $this->elapsed($start);
        $client->write($this->resp(['INFO']));
        $sizeLine = $client->readLine();
        $info = [];
        if (str_starts_with($sizeLine, '$')) {
            foreach (explode("\n", $client->read((int) substr($sizeLine, 1) + 2)) as $line) {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', trim($line), 2);
                    $info[$k] = $v;
                }
            }
        }
        $client->close();

        $details = array_intersect_key($info, array_flip(['redis_version', 'used_memory_human', 'maxmemory_human', 'connected_clients', 'uptime_in_days', 'role', 'rejected_connections', 'evicted_keys']));

        return CheckResult::up($ms, 'PONG'.(isset($details['used_memory_human']) ? ' · '.$details['used_memory_human'] : ''), $details, ['redis' => $details]);
    }

    private function resp(array $args): string
    {
        $out = '*'.count($args)."\r\n";
        foreach ($args as $arg) {
            $out .= '$'.strlen($arg)."\r\n".$arg."\r\n";
        }

        return $out;
    }
}
