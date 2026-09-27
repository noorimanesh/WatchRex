<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\Checker;
use App\Services\Checks\CheckFailed;
use App\Services\Checks\CheckResult;
use App\Services\Checks\SocketClient;
use App\Services\Checks\TargetGuard;

abstract class NetworkChecker implements Checker
{
    /** Ports that speak TLS from the first byte. */
    protected const IMPLICIT_TLS_PORTS = [443, 465, 636, 993, 995, 990, 6380];

    abstract protected function run(Monitor $monitor, string $host, int $port): CheckResult;

    public function check(Monitor $monitor): CheckResult
    {
        $host = (string) $monitor->host();
        $port = (int) $monitor->effectivePort();

        if ($host === '') {
            return CheckResult::down(__('No host configured.'));
        }

        try {
            TargetGuard::resolve($host, TargetGuard::enabledFor($monitor));

            return $this->run($monitor, $host, $port);
        } catch (CheckFailed $e) {
            return CheckResult::down($e->getMessage());
        }
    }

    protected function security(Monitor $monitor, int $port): string
    {
        $security = (string) $monitor->setting('security', 'auto');

        if ($security === 'auto') {
            return in_array($port, self::IMPLICIT_TLS_PORTS, true) ? 'ssl' : 'starttls';
        }

        return $security;
    }

    protected function client(Monitor $monitor, string $host, int $port, bool $tls): SocketClient
    {
        return (new SocketClient($host, $port, $monitor->timeout, $tls, (bool) $monitor->setting('verify_ssl', true)))->connect();
    }

    protected function elapsed(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    /** Shared TLS metadata for the monitor (lets mail servers show certificate expiry too). */
    protected function tlsMeta(SocketClient $client): array
    {
        return $client->certificate ? ['ssl' => $client->certificate + ['protocol' => $client->tlsProtocol]] : [];
    }

    protected function applySslWarning(Monitor $monitor, CheckResult $result, SocketClient $client): CheckResult
    {
        $days = $client->certificate['days_left'] ?? null;
        if ($days !== null && $days <= (int) $monitor->setting('ssl_warn_days', config('watchrex.defaults.ssl_warn_days'))) {
            $result->withWarning(__('TLS certificate expires in :d days', ['d' => $days]));
        }

        return $result;
    }
}
