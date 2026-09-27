<?php

namespace App\Services\Checks;

/**
 * Thin cURL wrapper that exposes the full timing breakdown
 * (DNS / connect / TLS / TTFB / download), caps the body size,
 * pins DNS resolution and validates every redirect hop (SSRF safe).
 */
class HttpProbe
{
    public function __construct(
        private int $timeout = 10,
        private bool $verifySsl = true,
        private bool $followRedirects = true,
        private int $maxRedirects = 5,
        private bool $guard = false,
        private ?int $maxBody = null,
    ) {
        $this->maxBody ??= (int) config('watchrex.defaults.max_body_bytes');
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{status:int, body:string, headers:array<string,string>, timings:array<string,int>, ip:?string, url:string, redirects:int, http_version:?string, certificate:?array, censored:bool}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?array $basicAuth = null): array
    {
        $redirects = 0;
        $totalMs = 0;
        $method = strtoupper($method);

        while (true) {
            $response = $this->single($method, $url, $headers, $body, $basicAuth);
            $totalMs += $response['timings']['total'];

            if ($response['censored']) {
                break;
            }

            $location = $response['headers']['location'] ?? null;
            if (! $this->followRedirects || ! $location || $response['status'] < 300 || $response['status'] >= 400) {
                break;
            }

            if (++$redirects > $this->maxRedirects) {
                throw new CheckFailed(__('Too many redirects (> :n).', ['n' => $this->maxRedirects]));
            }

            $url = $this->absoluteUrl($url, $location);
            if (in_array($response['status'], [301, 302, 303], true) && $method !== 'HEAD') {
                $method = 'GET';
                $body = null;
            }
        }

        $response['redirects'] = $redirects;
        $response['timings']['total_with_redirects'] = $totalMs;

        return $response;
    }

    private function single(string $method, string $url, array $headers, ?string $body, ?array $basicAuth): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? null;

        if (! in_array($scheme, ['http', 'https'], true) || ! $host) {
            throw new CheckFailed(__('Invalid URL: :url', ['url' => $url]));
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $ips = TargetGuard::resolve($host, $this->guard);

        foreach ($ips as $ip) {
            if (TargetGuard::isCensorshipIp($ip)) {
                return $this->censoredResponse($url, $ip);
            }
        }

        $received = '';
        $responseHeaders = [];
        $ch = curl_init($url);

        $headerLines = ['User-Agent: '.config('watchrex.defaults.user_agent'), 'Accept: */*'];
        foreach ($headers as $k => $v) {
            $headerLines[] = "{$k}: {$v}";
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min($this->timeout, 10),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_CERTINFO => $scheme === 'https',
            CURLOPT_ENCODING => '',
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $responseHeaders[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) use (&$received) {
                $received .= $chunk;

                // Returning a shorter length aborts the transfer once the cap is reached.
                return strlen($received) > $this->maxBody ? 0 : strlen($chunk);
            },
        ]);

        if ($this->guard) {
            // Pin the validated IP so a DNS rebind cannot redirect us internally.
            curl_setopt($ch, CURLOPT_RESOLVE, ["{$host}:{$port}:".(str_contains($ips[0], ':') ? "[{$ips[0]}]" : $ips[0])]);
        }

        if ($body !== null && $body !== '' && ! in_array($method, ['GET', 'HEAD'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if ($basicAuth) {
            curl_setopt($ch, CURLOPT_USERPWD, $basicAuth[0].':'.$basicAuth[1]);
        }

        curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);

        // Error 23 = our own write callback aborting on the size cap: not a failure.
        if ($errno && $errno !== CURLE_WRITE_ERROR) {
            throw new CheckFailed(self::describeCurlError($errno, $error, $this->timeout), (int) $errno);
        }

        $dns = (float) curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME);
        $connect = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $tls = (float) curl_getinfo($ch, CURLINFO_APPCONNECT_TIME) ?: $connect;
        $ttfb = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
        $total = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);

        $censored = false;
        foreach (config('watchrex.censorship.markers', []) as $marker) {
            if ($received !== '' && strlen($received) < 20000 && str_contains($received, $marker)) {
                $censored = true;
            }
        }

        return [
            'status' => (int) $info['http_code'],
            'body' => $received,
            'headers' => $responseHeaders,
            'ip' => $info['primary_ip'] ?: ($ips[0] ?? null),
            'url' => $url,
            'http_version' => $this->httpVersion($info['http_version'] ?? 0),
            'size' => (int) ($info['size_download'] ?: strlen($received)),
            'certificate' => $this->certificate($info['certinfo'] ?? []),
            'censored' => $censored,
            'timings' => [
                'dns' => (int) round($dns * 1000),
                'connect' => (int) round(max(0, $connect - $dns) * 1000),
                'tls' => (int) round(max(0, $tls - $connect) * 1000),
                'server' => (int) round(max(0, $ttfb - $tls) * 1000),
                'ttfb' => (int) round($ttfb * 1000),
                'download' => (int) round(max(0, $total - $ttfb) * 1000),
                'total' => (int) round($total * 1000),
            ],
        ];
    }

    private function censoredResponse(string $url, string $ip): array
    {
        return [
            'status' => 403, 'body' => '', 'headers' => [], 'ip' => $ip, 'url' => $url,
            'http_version' => null, 'size' => 0, 'certificate' => null, 'censored' => true,
            'timings' => ['dns' => 0, 'connect' => 0, 'tls' => 0, 'server' => 0, 'ttfb' => 0, 'download' => 0, 'total' => 0],
        ];
    }

    private function certificate(array $chain): ?array
    {
        $leaf = $chain[0] ?? null;
        if (! $leaf) {
            return null;
        }

        $expires = isset($leaf['Expire date']) ? strtotime($leaf['Expire date']) : false;
        preg_match('/O\s*=\s*([^,\/]+)/', (string) ($leaf['Issuer'] ?? ''), $issuer);
        preg_match('/CN\s*=\s*([^,\/]+)/', (string) ($leaf['Subject'] ?? ''), $subject);

        return [
            'subject' => isset($subject[1]) ? trim($subject[1]) : null,
            'issuer' => isset($issuer[1]) ? trim($issuer[1]) : null,
            'valid_to' => $expires ? date(DATE_ATOM, $expires) : null,
            'days_left' => $expires ? (int) floor(($expires - time()) / 86400) : null,
            'chain_length' => count($chain),
        ];
    }

    private function httpVersion(int $v): ?string
    {
        return match ($v) {
            CURL_HTTP_VERSION_1_0 => '1.0',
            CURL_HTTP_VERSION_1_1 => '1.1',
            CURL_HTTP_VERSION_2_0 => '2',
            30 => '3',
            default => null,
        };
    }

    private function absoluteUrl(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $p = parse_url($base);
        $origin = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');

        if (str_starts_with($location, '//')) {
            return $p['scheme'].':'.$location;
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $dir = rtrim(dirname($p['path'] ?? '/'), '/');

        return $origin.$dir.'/'.$location;
    }

    public static function describeCurlError(int $errno, string $error, int $timeout): string
    {
        return match ($errno) {
            CURLE_COULDNT_RESOLVE_HOST => __('DNS resolution failed — the domain does not resolve.'),
            CURLE_COULDNT_CONNECT => __('Connection refused — web server is not listening or a firewall blocks the port.'),
            CURLE_OPERATION_TIMEDOUT => __('Request timed out after :s seconds.', ['s' => $timeout]),
            CURLE_SSL_CONNECT_ERROR => __('SSL handshake failed: :e', ['e' => $error]),
            51, 60 => __('SSL certificate is invalid or untrusted: :e', ['e' => $error]),
            CURLE_GOT_NOTHING => __('Server closed the connection without sending a response.'),
            CURLE_RECV_ERROR, CURLE_SEND_ERROR => __('Network error while talking to the server: :e', ['e' => $error]),
            default => "cURL #{$errno}: {$error}",
        };
    }
}
