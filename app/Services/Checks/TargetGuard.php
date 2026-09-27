<?php

namespace App\Services\Checks;

use App\Models\Monitor;

/**
 * SSRF protection: prevents non-admin tenants from probing private,
 * loopback, link-local or cloud-metadata addresses.
 */
class TargetGuard
{
    public static function enabledFor(Monitor $monitor): bool
    {
        return config('watchrex.block_private_targets') && ! ($monitor->user?->isAdmin());
    }

    /** Resolve host and return the list of IPs, throwing if any is forbidden. */
    public static function resolve(string $host, bool $enforce): array
    {
        $host = trim($host, '[]');

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $ips = [];
            foreach ((array) @dns_get_record($host, DNS_A) as $r) {
                $ips[] = $r['ip'];
            }
            foreach ((array) @dns_get_record($host, DNS_AAAA) as $r) {
                $ips[] = $r['ipv6'];
            }
            if (! $ips) {
                $fallback = gethostbyname($host);
                if ($fallback !== $host) {
                    $ips[] = $fallback;
                }
            }
        }

        if (! $ips) {
            throw new CheckFailed(__('DNS resolution failed for :host', ['host' => $host]));
        }

        if ($enforce) {
            foreach ($ips as $ip) {
                if (self::isCensorshipIp($ip)) {
                    continue;
                }
                if (! self::isPublic($ip)) {
                    throw new CheckFailed(__('Target :host resolves to a private or reserved address (:ip) which is not allowed.', ['host' => $host, 'ip' => $ip]));
                }
            }
        }

        return array_values(array_unique($ips));
    }

    public static function isPublic(string $ip): bool
    {
        return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && ! str_starts_with($ip, '100.64.')
            && ! str_starts_with($ip, '169.254.')
            && $ip !== '0.0.0.0';
    }

    public static function isCensorshipIp(string $ip): bool
    {
        return in_array($ip, config('watchrex.censorship.ips', []), true);
    }
}
