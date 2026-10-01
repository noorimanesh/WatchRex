<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['team_id', 'name', 'thresholds', 'report_interval'])]
class Server extends Model
{
    use BelongsToTenant;

    public const DEFAULT_THRESHOLDS = [
        'cpu' => 90,
        'ram' => 90,
        'disk' => 90,
        'load' => null,
        'mail_queue' => 500,
        'login_failed' => 100,
    ];

    protected function casts(): array
    {
        return [
            'thresholds' => 'array',
            'settings' => 'array',
            'latest' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function metrics(): HasMany
    {
        return $this->hasMany(ServerMetric::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(ServerSite::class);
    }

    public function monitors(): HasMany
    {
        return $this->hasMany(Monitor::class);
    }

    /** Generate a fresh agent token; returns the plain token (shown once). */
    public function rotateToken(): string
    {
        $plain = 'wrx_'.Str::random(48);
        $this->token_hash = hash('sha256', $plain);

        return $plain;
    }

    public static function findByToken(?string $plain): ?self
    {
        return $plain ? static::where('token_hash', hash('sha256', $plain))->first() : null;
    }

    public function threshold(string $key): ?float
    {
        $value = ($this->thresholds ?? [])[$key] ?? self::DEFAULT_THRESHOLDS[$key] ?? null;

        return $value === null || $value === '' ? null : (float) $value;
    }

    public function isOnline(): bool
    {
        return $this->last_seen_at !== null
            && $this->last_seen_at->gt(now()->subSeconds(max(180, $this->report_interval * 3)));
    }

    public function stat(string $key, mixed $default = null): mixed
    {
        return data_get($this->latest ?? [], $key, $default);
    }

    public function panelLabel(): string
    {
        return match ($this->panel) {
            'cpanel' => 'cPanel / WHM',
            'directadmin' => 'DirectAdmin',
            'plesk' => 'Plesk',
            'cyberpanel' => 'CyberPanel',
            'aapanel' => 'aaPanel',
            'webmin' => 'Webmin / Virtualmin',
            'solidcp' => 'SolidCP',
            default => __('None'),
        };
    }
}
