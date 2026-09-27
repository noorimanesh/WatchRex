<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckResult;

class TcpChecker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        if ($port < 1) {
            return CheckResult::down(__('No port configured.'));
        }

        $client = $this->client($monitor, $host, $port, false);
        $ms = (int) round($client->connectMs);
        $client->close();

        return CheckResult::up($ms, __('Port :p open', ['p' => $port]), ['connect_ms' => $ms]);
    }
}
