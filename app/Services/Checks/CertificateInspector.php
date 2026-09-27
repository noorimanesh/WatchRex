<?php

namespace App\Services\Checks;

use Carbon\CarbonImmutable;

class CertificateInspector
{
    /** @param  \OpenSSLCertificate|resource|string  $cert */
    public static function summarize($cert): ?array
    {
        $parsed = @openssl_x509_parse($cert);

        if (! $parsed) {
            return null;
        }

        $san = [];
        foreach (explode(',', $parsed['extensions']['subjectAltName'] ?? '') as $entry) {
            $entry = trim($entry);
            if (str_starts_with($entry, 'DNS:')) {
                $san[] = substr($entry, 4);
            }
        }

        $validTo = CarbonImmutable::createFromTimestampUTC($parsed['validTo_time_t']);
        $fingerprint = @openssl_x509_fingerprint($cert, 'sha256') ?: null;

        return [
            'subject' => $parsed['subject']['CN'] ?? null,
            'issuer' => $parsed['issuer']['O'] ?? $parsed['issuer']['CN'] ?? null,
            'issuer_cn' => $parsed['issuer']['CN'] ?? null,
            'valid_from' => CarbonImmutable::createFromTimestampUTC($parsed['validFrom_time_t'])->toIso8601String(),
            'valid_to' => $validTo->toIso8601String(),
            'days_left' => (int) floor(now()->diffInDays($validTo, false)),
            'san' => array_slice($san, 0, 100),
            'serial' => $parsed['serialNumberHex'] ?? null,
            'signature' => $parsed['signatureTypeSN'] ?? null,
            'fingerprint' => $fingerprint,
            'self_signed' => ($parsed['subject'] ?? null) == ($parsed['issuer'] ?? null),
        ];
    }

    public static function matchesHost(array $summary, string $host): bool
    {
        $names = array_filter(array_merge($summary['san'] ?? [], [$summary['subject'] ?? '']));
        foreach ($names as $name) {
            $name = strtolower($name);
            if ($name === strtolower($host)) {
                return true;
            }
            if (str_starts_with($name, '*.') && str_ends_with(strtolower($host), substr($name, 1)) && substr_count($host, '.') === substr_count($name, '.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Connect to host:port over TLS and inspect the certificate. When the chain
     * does not validate we reconnect without verification to still report details.
     */
    public static function fetch(string $host, int $port = 443, int $timeout = 10): array
    {
        try {
            $client = (new SocketClient($host, $port, $timeout, true, true))->connect();
            $valid = true;
            $error = null;
        } catch (CheckFailed $e) {
            $error = $e->getMessage();
            $client = (new SocketClient($host, $port, $timeout, true, false))->connect();
            $valid = false;
        }

        $result = [
            'valid_chain' => $valid,
            'error' => $error,
            'protocol' => $client->tlsProtocol,
            'cipher' => $client->tlsCipher,
            'handshake_ms' => (int) round($client->connectMs),
            'certificate' => $client->certificate,
        ];
        $client->close();

        return $result;
    }
}
