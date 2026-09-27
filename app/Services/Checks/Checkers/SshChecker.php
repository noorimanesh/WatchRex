<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckResult;

class SshChecker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $start = microtime(true);
        $client = $this->client($monitor, $host, $port, false);
        $banner = $client->readLine();
        $client->close();

        if (! str_starts_with($banner, 'SSH-')) {
            return CheckResult::down(__('Unexpected SSH banner: :b', ['b' => mb_substr($banner, 0, 150)]), $this->elapsed($start));
        }

        $result = CheckResult::up($this->elapsed($start), mb_substr($banner, 0, 120), ['banner' => $banner]);

        if (str_starts_with($banner, 'SSH-1.')) {
            $result->withWarning(__('Insecure SSH protocol version 1 offered.'));
        }

        return $result;
    }
}
