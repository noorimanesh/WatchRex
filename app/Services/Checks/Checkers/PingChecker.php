<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckFailed;
use App\Services\Checks\CheckResult;
use App\Services\Checks\SocketClient;

class PingChecker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $count = max(1, min(5, (int) $monitor->setting('count', 3)));

        if (function_exists('exec') && ! in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            $binary = str_contains($host, ':') ? 'ping -6' : 'ping';
            $cmd = sprintf('%s -n -c %d -W %d -i 0.2 %s 2>&1', $binary, $count, max(1, $monitor->timeout), escapeshellarg($host));
            @exec($cmd, $output, $code);
            $text = implode("\n", $output ?? []);

            if (preg_match('/(\d+(?:\.\d+)?)% packet loss/', $text, $loss) && preg_match('#= [\d.]+/([\d.]+)/([\d.]+)/#', $text, $rtt)) {
                $avg = (int) round((float) $rtt[1]);
                $details = ['packet_loss' => (float) $loss[1], 'avg_ms' => (float) $rtt[1], 'max_ms' => (float) $rtt[2], 'count' => $count];

                if ((float) $loss[1] >= 100) {
                    return CheckResult::down(__('Host unreachable (100% packet loss).'), null, $details);
                }

                $result = CheckResult::up($avg, __('Ping OK, :l% loss', ['l' => $loss[1]]), $details);

                return (float) $loss[1] > 0 ? $result->withWarning(__('Packet loss :l%', ['l' => $loss[1]])) : $result;
            }

            if ($code !== 127 && str_contains($text, 'packet loss')) {
                return CheckResult::down(__('Host unreachable (100% packet loss).'));
            }
        }

        // ICMP unavailable (shared hosting / container): fall back to TCP reachability.
        foreach ([443, 80, 22] as $fallbackPort) {
            try {
                $client = (new SocketClient($host, $fallbackPort, min($monitor->timeout, 5)))->connect();
                $ms = (int) round($client->connectMs);
                $client->close();

                return CheckResult::up($ms, __('Reachable via TCP :p (ICMP unavailable)', ['p' => $fallbackPort]));
            } catch (CheckFailed) {
                continue;
            }
        }

        return CheckResult::down(__('Host unreachable.'));
    }
}
