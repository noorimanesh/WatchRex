<?php

namespace App\Services\Domain;

/** DNS based blacklist (RBL / DNSBL) lookups for mail server IPs. */
class Rbl
{
    /** @return list<string> the lists the IP is present on */
    public static function listed(?string $ip): array
    {
        if (! $ip || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return [];
        }

        $reversed = implode('.', array_reverse(explode('.', $ip)));
        $listed = [];

        foreach (config('watchrex.domains.rbl', []) as $zone) {
            $answer = @gethostbyname("{$reversed}.{$zone}.");
            // 127.0.0.x answers mean "listed"; 127.255.255.x are resolver/quota errors (e.g. Spamhaus public resolver block).
            if (str_starts_with($answer, '127.') && ! str_starts_with($answer, '127.255.')) {
                $listed[] = $zone;
            }
        }

        return $listed;
    }
}
