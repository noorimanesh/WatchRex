<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CheckFailed;
use App\Services\Checks\CheckResult;
use App\Services\Domain\Rbl;

/**
 * SMTP reachability: banner, EHLO capabilities, STARTTLS + certificate,
 * optional authentication test, open-relay test and RBL listing.
 */
class SmtpChecker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $security = $this->security($monitor, $port);
        $start = microtime(true);
        $client = $this->client($monitor, $host, $port, $security === 'ssl');

        $banner = $client->readReply();
        if ($banner['code'] !== 220) {
            return CheckResult::down(__('Unexpected SMTP banner: :b', ['b' => mb_substr($banner['text'], 0, 200)]), $this->elapsed($start));
        }

        $ehloName = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'watchrex.local';
        $ehlo = $client->command('EHLO '.$ehloName);
        $extensions = $this->extensions($ehlo['lines']);
        $warnings = [];

        if ($security === 'starttls') {
            if (isset($extensions['STARTTLS'])) {
                $reply = $client->command('STARTTLS');
                if ($reply['code'] !== 220) {
                    throw new CheckFailed(__('STARTTLS rejected: :r', ['r' => $reply['text']]));
                }
                $client->enableTls();
                $extensions = $this->extensions($client->command('EHLO '.$ehloName)['lines']);
            } elseif ($monitor->setting('require_tls', true)) {
                $warnings[] = __('Server does not offer STARTTLS — mail is transferred unencrypted.');
            }
        }

        $details = [
            'banner' => mb_substr($banner['lines'][0], 0, 200),
            'extensions' => array_keys($extensions),
            'auth_methods' => $extensions['AUTH'] ?? null,
            'max_size_mb' => isset($extensions['SIZE']) && (int) $extensions['SIZE'] > 0 ? round((int) $extensions['SIZE'] / 1048576, 1) : null,
            'tls' => $client->tlsProtocol,
            'cipher' => $client->tlsCipher,
            'certificate' => $client->certificate,
            'connect_ms' => (int) round($client->connectMs),
        ];

        if ($user = $monitor->credential('username')) {
            $auth = $client->command('AUTH PLAIN '.base64_encode("\0{$user}\0".$monitor->credential('password')));
            $details['auth'] = $auth['code'] === 235 ? 'success' : 'failed';
            if ($auth['code'] !== 235) {
                $client->command('QUIT');

                return CheckResult::down(__('SMTP authentication failed for :u (:r)', ['u' => $user, 'r' => mb_substr($auth['text'], 0, 120)]), $this->elapsed($start), $details, $this->tlsMeta($client));
            }
        } elseif ($monitor->setting('open_relay_test', false)) {
            $client->command('MAIL FROM:<relay-probe@watchrex.invalid>');
            $rcpt = $client->command('RCPT TO:<relay-probe@example.org>');
            $details['open_relay'] = $rcpt['code'] === 250;
            if ($details['open_relay']) {
                $warnings[] = __('OPEN RELAY: server accepts mail for external domains without authentication!');
            }
            $client->command('RSET');
        }

        try {
            $client->command('QUIT');
        } catch (CheckFailed) {
            // Some servers drop the connection immediately after QUIT.
        }

        $ms = $this->elapsed($start);
        $meta = $this->tlsMeta($client);

        if ($monitor->setting('rbl_check', true)) {
            $meta['rbl'] = $this->rbl($monitor, $host);
            if ($meta['rbl']['listed'] ?? []) {
                $warnings[] = __('Mail server IP listed on: :l', ['l' => implode(', ', $meta['rbl']['listed'])]);
            }
        }

        $result = CheckResult::up($ms, __('SMTP ready (:t)', ['t' => $client->tlsProtocol ?: 'plain']), $details, $meta);
        $this->applySslWarning($monitor, $result, $client);

        return $warnings ? $result->withWarning(implode(' ', $warnings)) : $result;
    }

    /** RBL lookups are cached for an hour on the monitor to stay light. */
    private function rbl(Monitor $monitor, string $host): array
    {
        $cached = $monitor->metaValue('rbl');
        if ($cached && isset($cached['checked_at']) && $cached['checked_at'] > time() - 3600) {
            return $cached;
        }

        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

        return ['ip' => $ip, 'listed' => Rbl::listed($ip), 'checked_at' => time()];
    }

    /** @return array<string, string> */
    private function extensions(array $lines): array
    {
        $ext = [];
        foreach (array_slice($lines, 1) as $line) {
            $parts = explode(' ', trim(substr($line, 4)), 2);
            $ext[strtoupper($parts[0])] = $parts[1] ?? '';
        }

        return $ext;
    }
}
