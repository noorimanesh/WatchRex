<?php

namespace App\Services\Checks;

/**
 * Turns raw HTTP measurements into a human readable "possible cause",
 * so the dashboard can say *why* a site is slow or broken, not only that it is.
 */
class Diagnoser
{
    /** @return list<string> */
    public static function http(?int $status, array $timings = [], ?string $error = null): array
    {
        $causes = [];

        if ($error !== null) {
            $e = strtolower($error);
            $causes[] = match (true) {
                str_contains($e, 'dns') => __('DNS problem: check nameservers, zone records or domain expiry.'),
                str_contains($e, 'refused') => __('Web server (Nginx/Apache/LiteSpeed) is stopped or the firewall (CSF/iptables) is blocking the port.'),
                str_contains($e, 'timed out') => __('Server is overloaded, unreachable or packets are dropped (network/ISP/firewall).'),
                str_contains($e, 'ssl') || str_contains($e, 'certificate') => __('SSL certificate is expired, self-signed, has a missing intermediate chain or does not match the hostname.'),
                str_contains($e, 'filter') => __('Blocked by national filtering / ISP.'),
                default => __('Network level failure before an HTTP response was received.'),
            };

            return $causes;
        }

        if ($status !== null) {
            $hint = match (true) {
                $status === 500 => __('Application error (PHP fatal error, exception or misconfiguration). Check the application error_log.'),
                $status === 502 => __('Bad gateway: PHP-FPM / upstream application is down or crashed.'),
                $status === 503 => __('Service unavailable: server overloaded, maintenance mode or resource limits (CloudLinux LVE) reached.'),
                $status === 504 => __('Gateway timeout: upstream (PHP / database) is too slow to respond.'),
                $status === 508 => __('Resource limit reached (CloudLinux entry processes / LVE).'),
                $status === 403 => __('Forbidden: WAF (ModSecurity / Imunify360 / Cloudflare) or file permissions.'),
                $status === 404 => __('Page not found: wrong document root, deleted page or rewrite rules.'),
                $status === 429 => __('Rate limited by the server or CDN.'),
                in_array($status, [520, 521, 522, 523, 524, 525, 526], true) => __('Cloudflare cannot reach the origin server (origin down, firewall or SSL mismatch).'),
                $status >= 500 => __('Server-side error.'),
                default => null,
            };
            if ($hint) {
                $causes[] = $hint;
            }
        }

        if (($timings['dns'] ?? 0) > 1000) {
            $causes[] = __('Slow DNS lookup (:ms ms): nameservers are slow or far away.', ['ms' => $timings['dns']]);
        }
        if (($timings['connect'] ?? 0) > 1000) {
            $causes[] = __('Slow TCP connect (:ms ms): network latency, packet loss or an overloaded server.', ['ms' => $timings['connect']]);
        }
        if (($timings['tls'] ?? 0) > 1000) {
            $causes[] = __('Slow TLS handshake (:ms ms): CPU pressure or OCSP / chain issues.', ['ms' => $timings['tls']]);
        }
        if (($timings['server'] ?? 0) > 1500) {
            $causes[] = __('Slow server processing (:ms ms): application / PHP / database latency.', ['ms' => $timings['server']]);
        }
        if (($timings['download'] ?? 0) > 2000) {
            $causes[] = __('Slow content download (:ms ms): large page or limited bandwidth.', ['ms' => $timings['download']]);
        }

        return $causes;
    }
}
