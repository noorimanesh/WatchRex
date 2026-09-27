<?php

namespace App\Services;

use App\Models\Probe;

/** Human labels (with flag) for check locations: this server + every probe. */
class LocationNames
{
    private static ?array $cache = null;

    /** @return array<string, string> location slug => "🇮🇷 Tehran" */
    public static function map(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $map = [config('watchrex.location') => Probe::flagFor(config('watchrex.location_country')).' '.config('watchrex.location_label')];
        foreach (Probe::all(['location', 'name', 'country_code']) as $probe) {
            $map[$probe->location] = $probe->flag().' '.$probe->name;
        }

        return self::$cache = $map;
    }

    public static function label(?string $location): string
    {
        return self::map()[$location] ?? (string) $location;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
