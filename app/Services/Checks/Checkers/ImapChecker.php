<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckFailed;
use App\Services\Checks\CheckResult;
use App\Services\Checks\SocketClient;

/** IMAP availability, login test, mailbox size (STATUS) and quota usage (GETQUOTAROOT). */
class ImapChecker extends NetworkChecker
{
    private int $tag = 0;

    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $security = $this->security($monitor, $port);
        $start = microtime(true);
        $client = $this->client($monitor, $host, $port, $security === 'ssl');

        $greeting = $client->readLine();
        if (! str_starts_with($greeting, '* OK') && ! str_starts_with($greeting, '* PREAUTH')) {
            return CheckResult::down(__('Unexpected IMAP greeting: :g', ['g' => mb_substr($greeting, 0, 150)]), $this->elapsed($start));
        }

        $caps = $this->capabilities($client);
        if ($security === 'starttls' && in_array('STARTTLS', $caps, true)) {
            $this->cmd($client, 'STARTTLS');
            $client->enableTls();
            $caps = $this->capabilities($client);
        }

        $details = ['greeting' => mb_substr($greeting, 0, 200), 'capabilities' => array_slice($caps, 0, 30), 'tls' => $client->tlsProtocol, 'certificate' => $client->certificate];

        if ($user = $monitor->credential('username')) {
            [$ok, $lines] = $this->cmd($client, sprintf('LOGIN %s %s', $this->quote($user), $this->quote((string) $monitor->credential('password'))));
            $details['auth'] = $ok ? 'success' : 'failed';
            if (! $ok) {
                return CheckResult::down(__('IMAP login failed for :u', ['u' => $user]), $this->elapsed($start), $details, $this->tlsMeta($client));
            }

            [, $status] = $this->cmd($client, 'STATUS INBOX (MESSAGES UNSEEN)');
            foreach ($status as $line) {
                if (preg_match('/MESSAGES (\d+)/', $line, $m)) {
                    $details['messages'] = (int) $m[1];
                }
                if (preg_match('/UNSEEN (\d+)/', $line, $m)) {
                    $details['unseen'] = (int) $m[1];
                }
            }

            if (in_array('QUOTA', $caps, true)) {
                [, $quota] = $this->cmd($client, 'GETQUOTAROOT INBOX');
                foreach ($quota as $line) {
                    if (preg_match('/STORAGE (\d+) (\d+)/', $line, $m)) {
                        $details['quota_used_mb'] = round($m[1] / 1024, 1);
                        $details['quota_limit_mb'] = round($m[2] / 1024, 1);
                        $details['quota_percent'] = $m[2] > 0 ? round($m[1] / $m[2] * 100, 1) : null;
                    }
                }
            }
        }

        try {
            $this->cmd($client, 'LOGOUT');
        } catch (CheckFailed) {
        }

        $meta = $this->tlsMeta($client) + array_intersect_key($details, array_flip(['messages', 'unseen', 'quota_used_mb', 'quota_limit_mb', 'quota_percent']));
        $result = CheckResult::up($this->elapsed($start), $user ? __('IMAP login OK') : __('IMAP ready'), $details, $meta);
        $this->applySslWarning($monitor, $result, $client);

        if (($details['quota_percent'] ?? 0) >= (float) $monitor->setting('quota_warn', 90)) {
            $result->withWarning(__('Mailbox quota :p% used', ['p' => $details['quota_percent']]));
        }

        return $result;
    }

    private function capabilities(SocketClient $client): array
    {
        [, $lines] = $this->cmd($client, 'CAPABILITY');
        foreach ($lines as $line) {
            if (str_starts_with($line, '* CAPABILITY')) {
                return array_map('strtoupper', explode(' ', trim(substr($line, 13))));
            }
        }

        return [];
    }

    /** @return array{0: bool, 1: list<string>} */
    private function cmd(SocketClient $client, string $command): array
    {
        $tag = 'W'.(++$this->tag);
        $client->write("{$tag} {$command}\r\n");
        $lines = [];
        for ($i = 0; $i < 200; $i++) {
            $line = $client->readLine();
            if (str_starts_with($line, $tag.' ')) {
                return [str_starts_with($line, $tag.' OK'), $lines];
            }
            $lines[] = $line;
        }

        return [false, $lines];
    }

    private function quote(string $value): string
    {
        return '"'.addcslashes($value, '"\\').'"';
    }
}
