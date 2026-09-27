<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckFailed;
use App\Services\Checks\CheckResult;

/** POP3 availability, login test and mailbox size (STAT). */
class Pop3Checker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $security = $this->security($monitor, $port);
        $start = microtime(true);
        $client = $this->client($monitor, $host, $port, $security === 'ssl');

        $greeting = $client->readLine();
        if (! str_starts_with($greeting, '+OK')) {
            return CheckResult::down(__('Unexpected POP3 greeting: :g', ['g' => mb_substr($greeting, 0, 150)]), $this->elapsed($start));
        }

        if ($security === 'starttls') {
            $client->write("STLS\r\n");
            if (str_starts_with($client->readLine(), '+OK')) {
                $client->enableTls();
            }
        }

        $details = ['greeting' => mb_substr($greeting, 0, 200), 'tls' => $client->tlsProtocol, 'certificate' => $client->certificate];

        if ($user = $monitor->credential('username')) {
            $client->write("USER {$user}\r\n");
            $client->readLine();
            $client->write('PASS '.$monitor->credential('password')."\r\n");
            $ok = str_starts_with($client->readLine(), '+OK');
            $details['auth'] = $ok ? 'success' : 'failed';

            if (! $ok) {
                return CheckResult::down(__('POP3 login failed for :u', ['u' => $user]), $this->elapsed($start), $details, $this->tlsMeta($client));
            }

            $client->write("STAT\r\n");
            if (preg_match('/^\+OK (\d+) (\d+)/', $client->readLine(), $m)) {
                $details['messages'] = (int) $m[1];
                $details['mailbox_mb'] = round($m[2] / 1048576, 2);
            }
        }

        try {
            $client->write("QUIT\r\n");
            $client->readLine();
        } catch (CheckFailed) {
        }

        $meta = $this->tlsMeta($client) + array_intersect_key($details, array_flip(['messages', 'mailbox_mb']));
        $result = CheckResult::up($this->elapsed($start), $user ? __('POP3 login OK') : __('POP3 ready'), $details, $meta);

        return $this->applySslWarning($monitor, $result, $client);
    }
}
