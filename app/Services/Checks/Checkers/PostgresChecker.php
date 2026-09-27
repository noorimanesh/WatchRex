<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckResult;
use PDO;
use Throwable;

class PostgresChecker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $start = microtime(true);
        $client = $this->client($monitor, $host, $port, false);
        // SSLRequest packet: length 8 + magic 80877103. Server answers 'S' or 'N'.
        $client->write(pack('NN', 8, 80877103));
        $answer = $client->read(1);
        $client->close();
        $ms = $this->elapsed($start);

        if (! in_array($answer, ['S', 'N'], true)) {
            return CheckResult::down(__('No PostgreSQL response.'), $ms);
        }

        $details = ['ssl_supported' => $answer === 'S'];

        if ($user = $monitor->credential('username')) {
            try {
                $pdo = new PDO("pgsql:host={$host};port={$port};dbname=".($monitor->setting('database') ?: 'postgres').";connect_timeout={$monitor->timeout}", $user, (string) $monitor->credential('password'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $details['version'] = $pdo->query('SHOW server_version')->fetchColumn();
                $details['connections'] = (int) $pdo->query('SELECT count(*) FROM pg_stat_activity')->fetchColumn();
            } catch (Throwable $e) {
                return CheckResult::down(__('PostgreSQL login failed: :e', ['e' => mb_substr($e->getMessage(), 0, 200)]), $ms, $details);
            }

            return CheckResult::up($this->elapsed($start), 'PostgreSQL '.$details['version'], $details);
        }

        return CheckResult::up($ms, __('PostgreSQL accepting connections'), $details);
    }
}
