<?php

namespace App\Models;

use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['team_id',
    'name', 'type', 'target', 'port', 'method', 'interval', 'timeout', 'retries',
    'settings', 'credentials', 'group', 'tags', 'is_active', 'parent_id', 'server_id',
])]
class Monitor extends Model
{
    use BelongsToTenant;

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'type' => MonitorType::class,
            'status' => MonitorStatus::class,
            'settings' => 'array',
            'credentials' => 'encrypted:array',
            'tags' => 'array',
            'meta' => 'array',
            'is_active' => 'boolean',
            'last_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'last_push_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Monitor $monitor) {
            $monitor->uuid ??= (string) Str::uuid();
            if ($monitor->type === MonitorType::Push && ! $monitor->push_token) {
                $monitor->push_token = Str::random(40);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Monitor::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Monitor::class, 'parent_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function heartbeats(): HasMany
    {
        return $this->hasMany(Heartbeat::class);
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(MonitorDailyStat::class);
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(NotificationChannel::class);
    }

    public function statusPages(): BelongsToMany
    {
        return $this->belongsToMany(StatusPage::class);
    }

    public function probes(): BelongsToMany
    {
        return $this->belongsToMany(Probe::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(ContentSnapshot::class);
    }

    public function maintenanceWindows(): BelongsToMany
    {
        return $this->belongsToMany(MaintenanceWindow::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default);
    }

    public function credential(string $key): ?string
    {
        $value = data_get($this->credentials ?? [], $key);

        return $value === '' ? null : $value;
    }

    public function metaValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->meta ?? [], $key, $default);
    }

    public function host(): ?string
    {
        if ($this->type->usesUrl()) {
            return parse_url((string) $this->target, PHP_URL_HOST) ?: null;
        }

        return $this->target;
    }

    public function effectivePort(): ?int
    {
        return $this->port ?: $this->type->defaultPort();
    }

    public function displayTarget(): string
    {
        return match (true) {
            $this->type === MonitorType::Push => __('Push endpoint'),
            $this->type === MonitorType::Server => $this->server?->name ?? '—',
            $this->type->usesPort() && $this->effectivePort() => $this->target.':'.$this->effectivePort(),
            default => (string) $this->target,
        };
    }

    public function inMaintenance(): bool
    {
        return $this->maintenanceWindows()
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>=', now())
            ->exists();
    }
}
