<?php

namespace App\Services\Checks\Checkers;

use App\Models\Monitor;
use App\Services\Checks\CertificateInspector;
use App\Services\Checks\CheckResult;

class SslChecker extends NetworkChecker
{
    protected function run(Monitor $monitor, string $host, int $port): CheckResult
    {
        $info = CertificateInspector::fetch($host, $port ?: 443, $monitor->timeout);
        $cert = $info['certificate'];

        if (! $cert) {
            return CheckResult::down(__('No certificate presented.'), $info['handshake_ms'], $info);
        }

        $meta = ['ssl' => $cert + ['protocol' => $info['protocol']]];
        $days = $cert['days_left'];
        $warnDays = (int) $monitor->setting('ssl_warn_days', config('watchrex.defaults.ssl_warn_days'));
        $details = $info + ['host_match' => CertificateInspector::matchesHost($cert, $host)];

        if ($days < 0) {
            return CheckResult::down(__('Certificate expired :d days ago', ['d' => abs($days)]), $info['handshake_ms'], $details, $meta);
        }
        if (! $info['valid_chain']) {
            return CheckResult::down(__('Certificate chain invalid: :e', ['e' => $info['error']]), $info['handshake_ms'], $details, $meta);
        }
        if (! $details['host_match']) {
            return CheckResult::down(__('Certificate does not cover :h', ['h' => $host]), $info['handshake_ms'], $details, $meta);
        }

        $result = CheckResult::up($info['handshake_ms'], __('Valid, :d days left (:i)', ['d' => $days, 'i' => $cert['issuer']]), $details, $meta);

        if ($days <= $warnDays) {
            $result->withWarning(__('Certificate expires in :d days', ['d' => $days]));
        } elseif (in_array($info['protocol'], ['TLSv1', 'TLSv1.1', 'SSLv3'], true)) {
            $result->withWarning(__('Outdated protocol :p negotiated', ['p' => $info['protocol']]));
        } elseif (($old = $monitor->metaValue('ssl.fingerprint')) && $old !== $cert['fingerprint']) {
            $result->withWarning(__('Certificate changed (new issuer: :i, expires :e)', ['i' => $cert['issuer'], 'e' => substr($cert['valid_to'], 0, 10)]));
        }

        return $result;
    }
}
