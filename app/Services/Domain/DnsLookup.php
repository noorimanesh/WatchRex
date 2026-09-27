<?php

namespace App\Services\Domain;

class DnsLookup
{
    public const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SOA', 'CAA', 'SRV', 'PTR'];

    /** @return list<array{type:string, value:string, ttl:int|null}> */
    public static function records(string $host, string $type): array
    {
        $constant = match (strtoupper($type)) {
            'A' => DNS_A,
            'AAAA' => DNS_AAAA,
            'CNAME' => DNS_CNAME,
            'MX' => DNS_MX,
            'NS' => DNS_NS,
            'TXT' => DNS_TXT,
            'SOA' => DNS_SOA,
            'CAA' => defined('DNS_CAA') ? DNS_CAA : DNS_ANY,
            'SRV' => DNS_SRV,
            'PTR' => DNS_PTR,
            default => DNS_A,
        };

        if (strtoupper($type) === 'PTR' && filter_var($host, FILTER_VALIDATE_IP)) {
            $host = self::reverseName($host);
        }

        $raw = @dns_get_record($host, $constant);
        $out = [];

        foreach ((array) $raw as $r) {
            $value = match ($r['type'] ?? '') {
                'A' => $r['ip'] ?? null,
                'AAAA' => $r['ipv6'] ?? null,
                'CNAME', 'NS', 'PTR' => $r['target'] ?? null,
                'MX' => ($r['pri'] ?? 0).' '.($r['target'] ?? ''),
                'TXT' => isset($r['entries']) ? implode('', $r['entries']) : ($r['txt'] ?? null),
                'SOA' => sprintf('%s %s %s', $r['mname'] ?? '', $r['rname'] ?? '', $r['serial'] ?? ''),
                'CAA' => sprintf('%s %s "%s"', $r['flags'] ?? 0, $r['tag'] ?? '', $r['value'] ?? ''),
                'SRV' => sprintf('%s %s %s %s', $r['pri'] ?? 0, $r['weight'] ?? 0, $r['port'] ?? 0, $r['target'] ?? ''),
                default => null,
            };

            if ($value !== null && ($r['type'] ?? '') === strtoupper($type)) {
                $out[] = ['type' => $r['type'], 'value' => $value, 'ttl' => $r['ttl'] ?? null];
            }
        }

        usort($out, fn ($a, $b) => strcmp($a['value'], $b['value']));

        return $out;
    }

    public static function reverseName(string $ip): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $hex = bin2hex(inet_pton($ip));

            return implode('.', array_reverse(str_split($hex))).'.ip6.arpa';
        }

        return implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
    }

    public static function ptr(string $ip): ?string
    {
        $name = @gethostbyaddr($ip);

        return $name && $name !== $ip ? $name : null;
    }

    public static function firstIp(string $host): ?string
    {
        $records = self::records($host, 'A');

        return $records[0]['value'] ?? null;
    }
}
