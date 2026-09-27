<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\Checker;
use App\Services\Checks\CheckFailed;
use App\Services\Checks\CheckResult;
use App\Services\Checks\SocketClient;
use App\Services\Checks\TargetGuard;

class WebSocketChecker implements Checker
{
    public function check(Monitor $monitor): CheckResult
    {
        $parts = parse_url((string) $monitor->target);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? null;

        if (! in_array($scheme, ['ws', 'wss'], true) || ! $host) {
            return CheckResult::down(__('WebSocket URL must start with ws:// or wss://'));
        }

        $port = $parts['port'] ?? ($scheme === 'wss' ? 443 : 80);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        try {
            TargetGuard::resolve($host, TargetGuard::enabledFor($monitor));
            $start = microtime(true);
            $client = (new SocketClient($host, $port, $monitor->timeout, $scheme === 'wss', (bool) $monitor->setting('verify_ssl', true)))->connect();
            $key = base64_encode(random_bytes(16));
            $client->write("GET {$path} HTTP/1.1\r\nHost: {$host}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\nUser-Agent: ".config('watchrex.defaults.user_agent')."\r\n\r\n");
            $status = $client->readLine();
            $client->close();
        } catch (CheckFailed $e) {
            return CheckResult::down($e->getMessage());
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        return preg_match('#^HTTP/\d(\.\d)? 101#', $status)
            ? CheckResult::up($ms, __('WebSocket upgrade accepted'))
            : CheckResult::down(__('Upgrade refused: :s', ['s' => mb_substr($status, 0, 120)]), $ms);
    }
}
