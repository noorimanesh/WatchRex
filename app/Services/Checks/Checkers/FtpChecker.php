<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckFailed;
use App\Services\Checks\CheckResult;

class FtpChecker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $start = microtime(true);
        $client = $this->client($monitor, $host, $port, $port === 990);
        $banner = $client->readReply();

        if ($banner['code'] !== 220) {
            return CheckResult::down(__('Unexpected FTP banner: :b', ['b' => mb_substr($banner['text'], 0, 150)]), $this->elapsed($start));
        }

        $details = ['banner' => mb_substr($banner['lines'][0], 0, 200)];

        if ($monitor->setting('security', 'auto') === 'starttls') {
            if ($client->command('AUTH TLS')['code'] === 234) {
                $client->enableTls();
                $details['tls'] = $client->tlsProtocol;
            }
        }

        if ($user = $monitor->credential('username')) {
            $client->command("USER {$user}");
            $pass = $client->command('PASS '.$monitor->credential('password'));
            $details['auth'] = $pass['code'] === 230 ? 'success' : 'failed';
            if ($pass['code'] !== 230) {
                return CheckResult::down(__('FTP login failed for :u', ['u' => $user]), $this->elapsed($start), $details);
            }
        }

        try {
            $client->command('QUIT');
        } catch (CheckFailed) {
        }

        return CheckResult::up($this->elapsed($start), $user ? __('FTP login OK') : __('FTP ready'), $details, $this->tlsMeta($client));
    }
}
