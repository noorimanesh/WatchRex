<?php

namespace App\Services;

use Carbon\CarbonInterface;

class Format
{
    /** Left-to-right isolate so "734.9 MB" is not reordered inside RTL text. */
    private static function ltr(string $value): string
    {
        return app()->bound('translator') && app()->getLocale() === 'fa' ? "\u{2066}{$value}\u{2069}" : $value;
    }

    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return self::ltr($seconds.'s');
        }

        $parts = [];
        foreach (['d' => 86400, 'h' => 3600, 'm' => 60, 's' => 1] as $unit => $size) {
            if ($seconds >= $size) {
                $parts[] = intdiv($seconds, $size).$unit;
                $seconds %= $size;
            }
            if (count($parts) === 2) {
                break;
            }
        }

        return self::ltr(implode(' ', $parts));
    }

    public static function bytes(int|float|null $bytes, int $precision = 1): string
    {
        if ($bytes === null) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return self::ltr(round($bytes, $precision).' '.$units[$i]);
    }

    public static function ms(?int $ms): string
    {
        if ($ms === null) {
            return '—';
        }

        return self::ltr($ms >= 1000 ? round($ms / 1000, 2).'s' : $ms.'ms');
    }

    public static function uptime(?float $percent): string
    {
        if ($percent === null) {
            return '—';
        }

        return ($percent >= 99.995 ? '100' : rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.')).'%';
    }

    /** Locale-aware date: Jalali (Solar Hijri) for Persian, Gregorian otherwise. */
    public static function date(?CarbonInterface $date, bool $withTime = true): string
    {
        if (! $date) {
            return '—';
        }

        $tz = auth()->user()?->timezone ?? config('app.timezone');
        $date = $date->copy()->setTimezone($tz);

        if (app()->getLocale() === 'fa') {
            [$y, $m, $d] = self::toJalali((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
            $out = sprintf('%04d/%02d/%02d', $y, $m, $d);

            return $withTime ? $out.' '.$date->format('H:i') : $out;
        }

        return $date->format($withTime ? 'Y-m-d H:i' : 'Y-m-d');
    }

    /** Gregorian → Jalali conversion (algorithm by Roozbeh Pournader & Mohammad Toossi). */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $gdm[$gm - 1];
        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $jm = $days < 186 ? 1 + intdiv($days, 31) : 7 + intdiv($days - 186, 30);
        $jd = 1 + ($days < 186 ? $days % 31 : ($days - 186) % 30);

        return [$jy, $jm, $jd];
    }
}
