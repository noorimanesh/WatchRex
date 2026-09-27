<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckResult;
use PDO;
use Throwable;

/**
 * Reads the MySQL/MariaDB handshake packet (no credentials required) and,
 * when credentials are set, performs a real login with a few health metrics.
 */
class MysqlChecker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $start = microtime(true);
        $client = $this->client($monitor, $host, $port, false);
        $header = $client->read(4);
        $length = strlen($header) === 4 ? unpack('V', substr($header, 0, 3)."\0")[1] : 0;
        $payload = $length > 0 ? $client->read(min($length, 1024)) : '';
        $client->close();
        $ms = $this->elapsed($start);

        if ($payload === '') {
            return CheckResult::down(__('No MySQL handshake received.'), $ms);
        }

        if ($payload[0] === "\xFF") {
            $message = mb_substr(substr($payload, 3), 0, 200);

            return CheckResult::up($ms, __('Server alive, but refuses this host: :m', ['m' => $message]), ['error' => $message]);
        }

        $version = strstr(substr($payload, 1), "\0", true) ?: 'unknown';
        $details = ['version' => $version, 'protocol' => ord($payload[0])];

        if ($user = $monitor->credential('username')) {
            try {
                $pdo = new PDO("mysql:host={$host};port={$port};dbname=".($monitor->setting('database') ?: ''), $user, (string) $monitor->credential('password'), [
                    PDO::ATTR_TIMEOUT => $monitor->timeout,
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);
                $status = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Uptime','Slow_queries','Questions','Max_used_connections')")->fetchAll(PDO::FETCH_KEY_PAIR);
                $max = $pdo->query("SHOW VARIABLES LIKE 'max_connections'")->fetch(PDO::FETCH_NUM);
                $details += array_change_key_case($status) + ['max_connections' => (int) ($max[1] ?? 0)];
            } catch (Throwable $e) {
                return CheckResult::down(__('MySQL login failed: :e', ['e' => mb_substr($e->getMessage(), 0, 200)]), $ms, $details);
            }

            $ms = $this->elapsed($start);
            $usage = ($details['max_connections'] ?? 0) > 0 ? ($details['threads_connected'] ?? 0) / $details['max_connections'] * 100 : 0;
            $result = CheckResult::up($ms, "MySQL {$version} · ".($details['threads_connected'] ?? '?').' '.__('connections'), $details, ['db' => $details]);

            return $usage > 85 ? $result->withWarning(__('Connection usage :p%', ['p' => round($usage)])) : $result;
        }

        return CheckResult::up($ms, "MySQL {$version}", $details);
    }
}
