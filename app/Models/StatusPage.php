<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['team_id', 'slug', 'title', 'description', 'custom_domain', 'logo_url', 'accent', 'footer_text', 'is_public', 'show_uptime', 'show_response', 'hide_branding', 'allow_subscribers'])]
class StatusPage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'show_uptime' => 'boolean',
            'show_response' => 'boolean',
            'hide_branding' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class)->withPivot(['sort', 'display_name'])->orderByPivot('sort');
    }

    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(MonitorGroup::class, 'monitor_group_status_page')->withPivot(['sort', 'display_name', 'expanded'])->orderByPivot('sort');
    }

    /** Page option with default (layout, theme, history_days, show_details, show_filters…). */
    public function option(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings ?? [], $key, $default ?? (self::DEFAULTS[$key] ?? null));
    }

    public const DEFAULTS = [
        'layout' => 'list',          // list | cards
        'theme' => 'auto',           // auto | light | dark
        'history_days' => 90,        // 30 | 60 | 90
        'incident_days' => 14,
        'show_details' => true,      // SSL / domain expiry per website group
        'show_mail_health' => true,  // full mail component checks for mail groups
        'show_filters' => true,      // group / status filter chips
        'show_chart' => true,        // response sparkline per group
        'announcement' => null,
        'announcement_level' => 'info',
        'support_url' => null,
    ];

    public function subscribers(): HasMany
    {
        return $this->hasMany(StatusPageSubscriber::class);
    }

    public function publicUrl(): string
    {
        return $this->custom_domain ? 'https://'.$this->custom_domain : route('status.show', $this->slug);
    }
}
