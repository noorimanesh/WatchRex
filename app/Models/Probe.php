<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/** A remote check location (e.g. iran-tehran, de-falkenstein) running `php artisan watchrex:probe`. */
#[Fillable(['name', 'location', 'country_code', 'is_active'])]
class Probe extends Model
{
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'last_seen_at' => 'datetime'];
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class);
    }

    public function rotateToken(): string
    {
        $plain = 'wrx_probe_'.Str::random(48);
        $this->token_hash = hash('sha256', $plain);

        return $plain;
    }

    public static function findByToken(?string $plain): ?self
    {
        return $plain ? static::where('token_hash', hash('sha256', $plain))->where('is_active', true)->first() : null;
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gt(now()->subMinutes(3));
    }

    public function flag(): string
    {
        return self::flagFor($this->country_code);
    }

    public static function flagFor(?string $cc): string
    {
        if (! $cc || strlen($cc) !== 2) {
            return '🌐';
        }

        return implode('', array_map(fn ($c) => mb_chr(127397 + ord($c)), str_split(strtoupper($cc))));
    }
}
