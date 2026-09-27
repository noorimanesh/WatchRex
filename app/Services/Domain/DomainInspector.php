<?php

namespace App\Services\Domain;

use App\Models\Domain;
use App\Services\Alerting\Notifier;
use App\Services\Checks\CertificateInspector;
use App\Services\Checks\TargetGuard;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Domain intelligence: registration/expiry (RDAP → WHOIS fallback), DNS records
 * with change detection, e-mail security posture (SPF/DKIM/DMARC/MTA-STS),
 * hosting IP + geo location, SSL, subdomain discovery and blacklist status.
 */
class DomainInspector
{
    private const SECOND_LEVEL = ['co.uk', 'org.uk', 'ac.uk', 'com.au', 'net.au', 'co.ir', 'ac.ir', 'org.ir', 'net.ir', 'gov.ir', 'id.ir', 'sch.ir', 'co.jp', 'com.br', 'com.tr', 'co.in', 'com.cn'];

    private const COMMON_SUBDOMAINS = ['www', 'mail', 'webmail', 'smtp', 'imap', 'pop', 'cpanel', 'whm', 'webdisk', 'autodiscover', 'autoconfig', 'ftp', 'api', 'app', 'admin', 'panel', 'portal', 'blog', 'shop', 'dev', 'staging', 'test', 'm', 'ns1', 'ns2', 'vpn', 'git', 'status', 'cdn', 'static', 'crm', 'erp'];

    public function __construct(private Notifier $notifier) {}

    private bool $guard = false;

    public function refresh(Domain $domain, bool $withSubdomains = true): Domain
    {
        $name = strtolower($domain->name);
        $this->guard = config('watchrex.block_private_targets') && ! $domain->user?->isAdmin();
        $errors = [];

        foreach ([
            'registration' => fn () => $this->registration($domain, $name),
            'dns' => fn () => $this->dns($domain, $name),
            'email' => fn () => $domain->email_security = $this->emailSecurity($name, $domain->dns ?? []),
            'network' => fn () => $domain->network = $this->network($name, $domain->network ?? []),
            'ssl' => fn () => $this->ssl($domain, $name),
            'subdomains' => fn () => $withSubdomains ? $domain->subdomains = $this->subdomains($name) : null,
            'blacklists' => fn () => $domain->blacklists = $this->blacklists($domain),
        ] as $step => $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                $errors[] = "{$step}: ".mb_substr($e->getMessage(), 0, 150);
            }
        }

        $domain->last_checked_at = now();
        $domain->last_error = $errors ? mb_substr(implode(' | ', $errors), 0, 500) : null;
        $domain->save();

        $this->alerts($domain);

        return $domain;
    }

    public static function registrable(string $host): string
    {
        $labels = explode('.', strtolower(trim($host, '.')));
        $n = count($labels);
        if ($n <= 2) {
            return implode('.', $labels);
        }
        $lastTwo = $labels[$n - 2].'.'.$labels[$n - 1];

        return in_array($lastTwo, self::SECOND_LEVEL, true) ? implode('.', array_slice($labels, -3)) : $lastTwo;
    }

    // ── Registration ────────────────────────────────────────────────────────

    private function registration(Domain $domain, string $name): void
    {
        $root = self::registrable($name);
        $data = $this->rdap($root) ?? $this->whois($root);

        if (! $data) {
            throw new \RuntimeException('RDAP/WHOIS lookup returned no data');
        }

        $domain->registrar = $data['registrar'] ? mb_substr($data['registrar'], 0, 255) : $domain->registrar;
        $domain->registered_at = $data['created'] ?? $domain->registered_at;
        $domain->expires_at = $data['expires'] ?? $domain->expires_at;
        if (! empty($data['nameservers'])) {
            $domain->nameservers = array_values(array_unique(array_map('strtolower', $data['nameservers'])));
        }
    }

    private function rdap(string $root): ?array
    {
        if (str_ends_with($root, '.ir')) {
            return null; // IRNIC has no RDAP service.
        }

        try {
            $response = Http::timeout(15)->acceptJson()->get('https://rdap.org/domain/'.$root);
        } catch (Throwable) {
            return null;
        }

        if (! $response->ok()) {
            return null;
        }

        $json = $response->json();
        $events = collect($json['events'] ?? [])->keyBy('eventAction');
        $registrar = null;
        foreach ($json['entities'] ?? [] as $entity) {
            if (in_array('registrar', $entity['roles'] ?? [], true)) {
                foreach ($entity['vcardArray'][1] ?? [] as $v) {
                    if (($v[0] ?? null) === 'fn') {
                        $registrar = $v[3];
                    }
                }
            }
        }

        return [
            'registrar' => $registrar,
            'created' => isset($events['registration']) ? Carbon::parse($events['registration']['eventDate']) : null,
            'expires' => isset($events['expiration']) ? Carbon::parse($events['expiration']['eventDate']) : null,
            'nameservers' => array_column($json['nameservers'] ?? [], 'ldhName'),
        ];
    }

    private function whois(string $root): ?array
    {
        $tld = substr(strrchr($root, '.'), 1);
        $server = $tld === 'ir' ? 'whois.nic.ir' : Cache::remember("whois-server:{$tld}", 86400 * 7, function () use ($tld) {
            preg_match('/^(?:refer|whois):\s*(\S+)/mi', (string) $this->whoisQuery('whois.iana.org', $tld), $m);

            return $m[1] ?? null;
        });

        if (! $server || ! ($raw = $this->whoisQuery($server, $root))) {
            return null;
        }

        $date = function (string $pattern) use ($raw): ?Carbon {
            if (preg_match($pattern, $raw, $m)) {
                try {
                    return Carbon::parse(trim(str_replace(['T', 'Z'], [' ', ''], preg_replace('/\s*\(.*\)$/', '', $m[1]))));
                } catch (Throwable) {
                    return null;
                }
            }

            return null;
        };

        preg_match('/^\s*(?:Registrar|registrar name|Sponsoring Registrar|source):\s*(.+)$/mi', $raw, $registrar);
        preg_match_all('/^\s*(?:Name Server|nserver|Nameservers?):\s*(\S+)/mi', $raw, $ns);

        return [
            'registrar' => isset($registrar[1]) ? trim($registrar[1]) : null,
            'created' => $date('/^\s*(?:Creation Date|created|Registered on|Registration Time|Domain Registration Date):\s*(.+)$/mi'),
            'expires' => $date('/^\s*(?:Registry Expiry Date|Registrar Registration Expiration Date|Expiration Date|Expiry Date|expire-date|expires|paid-till|Expires On|Expiration Time):\s*(.+)$/mi'),
            'nameservers' => array_map(fn ($n) => rtrim($n, '.'), $ns[1] ?? []),
        ];
    }

    private function whoisQuery(string $server, string $query): ?string
    {
        $fp = @fsockopen($server, 43, $errno, $errstr, 10);
        if (! $fp) {
            return null;
        }
        stream_set_timeout($fp, 10);
        fwrite($fp, $query."\r\n");
        $out = '';
        while (! feof($fp) && strlen($out) < 200000) {
            $chunk = fread($fp, 8192);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $out .= $chunk;
        }
        fclose($fp);

        return $out ?: null;
    }

    // ── DNS ─────────────────────────────────────────────────────────────────

    private function dns(Domain $domain, string $name): void
    {
        $records = [];
        foreach (['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SOA', 'CAA'] as $type) {
            $values = array_column(DnsLookup::records($name, $type), 'value');
            if ($values) {
                $records[$type] = $values;
            }
        }
        $records['_dmarc'] = array_column(DnsLookup::records('_dmarc.'.$name, 'TXT'), 'value');

        $hashable = $records;
        unset($hashable['SOA']);
        $hash = hash('sha256', json_encode($hashable));

        if ($domain->dns_hash && $domain->dns_hash !== $hash) {
            $domain->dns_changed_at = now();
            $changes = $this->diff($domain->dns ?? [], $records);
            $domain->alerts_sent = array_merge($domain->alerts_sent ?? [], ['dns_change' => $changes]);
            $this->notifier->userEvent($domain->user_id, 'dns', $name, __('DNS records changed:')."\n".implode("\n", array_slice($changes, 0, 15)), route('domains.show', $domain));
        }

        if (empty($domain->nameservers) && ! empty($records['NS'])) {
            $domain->nameservers = $records['NS'];
        }

        $domain->dns = $records;
        $domain->dns_hash = $hash;
    }

    private function diff(array $old, array $new): array
    {
        $changes = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $type) {
            if ($type === 'SOA') {
                continue;
            }
            foreach (array_diff($new[$type] ?? [], $old[$type] ?? []) as $v) {
                $changes[] = "+ {$type} {$v}";
            }
            foreach (array_diff($old[$type] ?? [], $new[$type] ?? []) as $v) {
                $changes[] = "- {$type} {$v}";
            }
        }

        return $changes;
    }

    // ── E-mail security ─────────────────────────────────────────────────────

    public function emailSecurity(string $name, array $dns): array
    {
        $txt = $dns['TXT'] ?? array_column(DnsLookup::records($name, 'TXT'), 'value');
        $spf = array_values(array_filter($txt, fn ($t) => stripos($t, 'v=spf1') === 0));
        $dmarc = array_values(array_filter($dns['_dmarc'] ?? array_column(DnsLookup::records('_dmarc.'.$name, 'TXT'), 'value'), fn ($t) => stripos($t, 'v=DMARC1') === 0));

        $issues = [];
        $score = 100;

        if (! $spf) {
            $issues[] = ['critical', __('No SPF record — anyone can spoof mail from this domain.')];
            $score -= 30;
        } elseif (count($spf) > 1) {
            $issues[] = ['critical', __('Multiple SPF records (invalid — only one is allowed).')];
            $score -= 25;
        } elseif (str_contains($spf[0], '+all')) {
            $issues[] = ['critical', __('SPF uses +all which allows every server to send.')];
            $score -= 25;
        } elseif (str_contains($spf[0], '?all')) {
            $issues[] = ['warning', __('SPF ends with ?all (neutral) — use ~all or -all.')];
            $score -= 10;
        }

        $policy = null;
        if (! $dmarc) {
            $issues[] = ['critical', __('No DMARC record.')];
            $score -= 25;
        } else {
            preg_match('/p=(\w+)/i', $dmarc[0], $p);
            $policy = strtolower($p[1] ?? 'none');
            if ($policy === 'none') {
                $issues[] = ['warning', __('DMARC policy is p=none (monitoring only).')];
                $score -= 10;
            }
        }

        $dkim = [];
        foreach (config('watchrex.domains.dkim_selectors') as $selector) {
            $rec = DnsLookup::records("{$selector}._domainkey.{$name}", 'TXT');
            if ($rec && str_contains(strtolower($rec[0]['value']), 'p=')) {
                $dkim[] = $selector;
            }
        }
        if (! $dkim) {
            $issues[] = ['warning', __('No DKIM key found on common selectors.')];
            $score -= 15;
        }

        $mx = [];
        foreach (DnsLookup::records($name, 'MX') as $record) {
            $host = rtrim(explode(' ', $record['value'], 2)[1] ?? '', '.');
            if ($host === '') {
                continue;
            }
            $ip = DnsLookup::firstIp($host);
            $ptr = $ip ? DnsLookup::ptr($ip) : null;
            $mx[] = ['host' => $host, 'priority' => (int) explode(' ', $record['value'])[0], 'ip' => $ip, 'ptr' => $ptr, 'ptr_ok' => $ptr !== null];
            if ($ip && ! $ptr) {
                $issues[] = ['warning', __('MX :h (:ip) has no reverse DNS (PTR).', ['h' => $host, 'ip' => $ip])];
                $score -= 5;
            }
        }
        if (! $mx) {
            $issues[] = ['info', __('No MX record — this domain cannot receive e-mail.')];
        }

        $mtaSts = (bool) array_filter(array_column(DnsLookup::records('_mta-sts.'.$name, 'TXT'), 'value'), fn ($t) => stripos($t, 'v=STSv1') === 0);
        $tlsRpt = (bool) DnsLookup::records('_smtp._tls.'.$name, 'TXT');
        $bimi = (bool) DnsLookup::records('default._bimi.'.$name, 'TXT');

        return [
            'score' => max(0, $score),
            'spf' => $spf[0] ?? null,
            'dmarc' => $dmarc[0] ?? null,
            'dmarc_policy' => $policy,
            'dkim_selectors' => $dkim,
            'mx' => $mx,
            'mta_sts' => $mtaSts,
            'tls_rpt' => $tlsRpt,
            'bimi' => $bimi,
            'issues' => $issues,
        ];
    }

    // ── Network / geo ───────────────────────────────────────────────────────

    private function network(string $name, array $previous): array
    {
        $ip = DnsLookup::firstIp($name);
        $ipv6 = DnsLookup::records($name, 'AAAA')[0]['value'] ?? null;

        $network = [
            'ip' => $ip,
            'ipv6' => $ipv6,
            'ptr' => $ip ? DnsLookup::ptr($ip) : null,
            'geo' => $ip ? self::geo($ip) : null,
            'first_seen' => $previous['first_seen'] ?? $this->firstArchived($name),
        ];

        // Keep a short IP history to reveal hosting migrations.
        $history = $previous['ip_history'] ?? [];
        if ($ip && (($history[0]['ip'] ?? null) !== $ip)) {
            array_unshift($history, ['ip' => $ip, 'since' => now()->toDateString(), 'org' => $network['geo']['org'] ?? null]);
        }
        $network['ip_history'] = array_slice($history, 0, 10);

        return $network;
    }

    public static function geo(string $ip): ?array
    {
        return Cache::remember("geo:{$ip}", 86400, function () use ($ip) {
            try {
                $json = Http::timeout(8)->get(str_replace('{ip}', $ip, config('watchrex.geoip_url')))->json();
            } catch (Throwable) {
                return null;
            }

            if (! is_array($json) || ($json['success'] ?? true) === false) {
                return null;
            }

            return [
                'country' => $json['country'] ?? null,
                'country_code' => $json['country_code'] ?? $json['countryCode'] ?? null,
                'region' => $json['region'] ?? $json['regionName'] ?? null,
                'city' => $json['city'] ?? null,
                'lat' => $json['latitude'] ?? $json['lat'] ?? null,
                'lon' => $json['longitude'] ?? $json['lon'] ?? null,
                'asn' => $json['connection']['asn'] ?? $json['as'] ?? null,
                'org' => $json['connection']['org'] ?? $json['org'] ?? null,
                'isp' => $json['connection']['isp'] ?? $json['isp'] ?? null,
            ];
        });
    }

    /** Earliest Wayback Machine snapshot: a cheap "online since" signal. */
    private function firstArchived(string $name): ?string
    {
        try {
            $json = Http::timeout(10)->get('https://archive.org/wayback/available', ['url' => $name, 'timestamp' => '19960101'])->json();
            $ts = $json['archived_snapshots']['closest']['timestamp'] ?? null;

            return $ts ? substr($ts, 0, 4).'-'.substr($ts, 4, 2).'-'.substr($ts, 6, 2) : null;
        } catch (Throwable) {
            return null;
        }
    }

    // ── SSL ─────────────────────────────────────────────────────────────────

    private function ssl(Domain $domain, string $name): void
    {
        TargetGuard::resolve($name, $this->guard);
        $info = CertificateInspector::fetch($name, 443, 10);
        $cert = $info['certificate'];
        $domain->ssl = $info + ['host_match' => $cert ? CertificateInspector::matchesHost($cert, $name) : false];
        $domain->ssl_expires_at = isset($cert['valid_to']) ? Carbon::parse($cert['valid_to']) : null;
    }

    // ── Subdomains ──────────────────────────────────────────────────────────

    public function subdomains(string $name): array
    {
        $found = [];

        // 1. Certificate Transparency logs (crt.sh).
        try {
            $response = Http::timeout(30)->acceptJson()->get('https://crt.sh/', ['q' => '%.'.$name, 'output' => 'json']);
            foreach ((array) $response->json() as $row) {
                foreach (explode("\n", strtolower($row['name_value'] ?? '')) as $sub) {
                    $sub = ltrim(trim($sub), '*.');
                    if ($sub !== $name && str_ends_with($sub, '.'.$name) && preg_match('/^[a-z0-9.-]+$/', $sub)) {
                        $found[$sub] = 'ct';
                    }
                }
            }
        } catch (Throwable) {
            // CT log unavailable — fall back to DNS probing only.
        }

        // 2. Common names via DNS.
        foreach (self::COMMON_SUBDOMAINS as $label) {
            $found[$label.'.'.$name] ??= 'dns';
        }

        $max = (int) config('watchrex.domains.max_subdomains');
        $sslBudget = (int) config('watchrex.domains.subdomain_ssl_checks');
        $result = [];

        foreach ($found as $sub => $source) {
            if (count($result) >= $max) {
                break;
            }
            $ip = DnsLookup::firstIp($sub);
            $cname = $ip ? null : (DnsLookup::records($sub, 'CNAME')[0]['value'] ?? null);
            if (! $ip && ! $cname) {
                if ($source === 'ct') {
                    $result[] = ['name' => $sub, 'ip' => null, 'source' => $source, 'resolves' => false];
                }

                continue;
            }

            $entry = ['name' => $sub, 'ip' => $ip, 'cname' => $cname, 'source' => $source, 'resolves' => true];

            if ($ip && ($this->guard && ! TargetGuard::isPublic($ip))) {
                $entry['ssl_valid'] = null;
            } elseif ($ip && $sslBudget-- > 0) {
                try {
                    $ssl = CertificateInspector::fetch($sub, 443, 4);
                    $entry['ssl_days'] = $ssl['certificate']['days_left'] ?? null;
                    $entry['ssl_issuer'] = $ssl['certificate']['issuer'] ?? null;
                    $entry['ssl_valid'] = $ssl['valid_chain'] && $ssl['certificate'] && CertificateInspector::matchesHost($ssl['certificate'], $sub);
                } catch (Throwable) {
                    $entry['ssl_valid'] = null;
                }
            }

            $result[] = $entry;
        }

        usort($result, fn ($a, $b) => [! $a['resolves'], $a['name']] <=> [! $b['resolves'], $b['name']]);

        return $result;
    }

    // ── Blacklists ──────────────────────────────────────────────────────────

    private function blacklists(Domain $domain): array
    {
        $ips = array_filter(array_unique(array_merge(
            [$domain->network['ip'] ?? null],
            array_column($domain->email_security['mx'] ?? [], 'ip'),
        )));

        $out = [];
        foreach (array_slice($ips, 0, 5) as $ip) {
            $out[$ip] = Rbl::listed($ip);
        }

        return $out;
    }

    // ── Alerts ──────────────────────────────────────────────────────────────

    private function alerts(Domain $domain): void
    {
        $sent = $domain->alerts_sent ?? [];
        $dirty = false;

        foreach ([
            'domain' => [$domain->daysUntilExpiry(), $domain->expires_at, __('Domain :d expires in :n days (:date)')],
            'ssl' => [$domain->sslDaysLeft(), $domain->ssl_expires_at, __('SSL certificate of :d expires in :n days (:date)')],
        ] as $kind => [$days, $date, $template]) {
            if ($days === null || $days > max($domain->warn_days, 30)) {
                continue;
            }
            $bucket = collect([30, 14, 7, 3, 1, 0])->first(fn ($b) => $days >= $b) ?? 0;
            if ($bucket > $domain->warn_days && $kind === 'domain') {
                continue;
            }
            $key = "{$kind}:{$date->toDateString()}:{$bucket}";
            if (! in_array($key, $sent[$kind] ?? [], true)) {
                $this->notifier->userEvent($domain->user_id, $kind, $domain->name, strtr($template, [':d' => $domain->name, ':n' => $days, ':date' => $date->toDateString()]), route('domains.show', $domain));
                $sent[$kind][] = $key;
                $dirty = true;
            }
        }

        if ($dirty) {
            $domain->forceFill(['alerts_sent' => $sent])->saveQuietly();
        }
    }
}
