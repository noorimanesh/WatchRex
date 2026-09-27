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
        ];
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class)->withPivot(['sort', 'display_name'])->orderByPivot('sort');
    }

    public function subscribers(): HasMany
    {
        return $this->hasMany(StatusPageSubscriber::class);
    }

    public function publicUrl(): string
    {
        return $this->custom_domain ? 'https://'.$this->custom_domain : route('status.show', $this->slug);
    }
}
