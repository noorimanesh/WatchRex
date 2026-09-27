<?php

namespace App\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Cache;

/** RFC 6238 TOTP (30s, 6 digits, SHA1) — compatible with Google Authenticator, Authy, 1Password… */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $length = 32): string
    {
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= self::ALPHABET[random_int(0, 31)];
        }

        return $secret;
    }

    public static function code(string $secret, ?int $timeSlice = null): string
    {
        $timeSlice ??= intdiv(time(), 30);
        $hash = hash_hmac('sha1', pack('N*', 0, $timeSlice), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** Verifies a code (±1 step drift) and blocks replay of an already used code. */
    public static function verify(string $secret, string $code, ?int $userId = null): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return false;
        }

        $now = intdiv(time(), 30);
        for ($i = -1; $i <= 1; $i++) {
            if (hash_equals(self::code($secret, $now + $i), $code)) {
                if ($userId !== null && ! Cache::add("totp-used:{$userId}:".($now + $i), true, 120)) {
                    return false;
                }

                return true;
            }
        }

        return false;
    }

    public static function uri(string $secret, string $account, string $issuer = 'WatchRex'): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    public static function qrSvg(string $uri): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd));

        return $writer->writeString($uri);
    }

    /** @return list<string> */
    public static function recoveryCodes(int $count = 8): array
    {
        return array_map(fn () => strtolower(bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(4))), range(1, $count));
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(rtrim($secret, '='));
        $bits = '';
        foreach (str_split($secret) as $char) {
            $pos = strpos(self::ALPHABET, $char);
            if ($pos !== false) {
                $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
            }
        }

        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
