<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckResult;

class UdpChecker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $payload = (string) $monitor->setting('payload', "\0");
        if (str_starts_with($payload, 'hex:')) {
            $payload = (string) hex2bin(substr($payload, 4));
        }

        $start = microtime(true);
        $socket = @stream_socket_client("udp://{$host}:{$port}", $errno, $errstr, $monitor->timeout);
        if (! $socket) {
            return CheckResult::down("UDP: {$errstr}");
        }

        stream_set_timeout($socket, $monitor->timeout);
        @fwrite($socket, $payload);
        $response = @fread($socket, 2048);
        $meta = stream_get_meta_data($socket);
        fclose($socket);
        $ms = $this->elapsed($start);

        if ($response !== false && $response !== '') {
            return CheckResult::up($ms, __('UDP response received (:b bytes)', ['b' => strlen($response)]));
        }

        if ($monitor->setting('expect_response', false) || ! ($meta['timed_out'] ?? false)) {
            return CheckResult::down(__('No UDP response (port closed or filtered).'), $ms);
        }

        return CheckResult::up(null, __('No ICMP port-unreachable received (open|filtered).'));
    }
}
