<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['slug', 'title', 'description', 'custom_domain', 'logo_url', 'accent', 'footer_text', 'is_public', 'show_uptime', 'show_response', 'hide_branding'])]
class StatusPage extends Model
{
    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'show_uptime' => 'boolean',
            'show_response' => 'boolean',
            'hide_branding' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class)->withPivot(['sort', 'display_name'])->orderByPivot('sort');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('user_id', $user->id);
    }

    public function publicUrl(): string
    {
        return $this->custom_domain ? 'https://'.$this->custom_domain : route('status.show', $this->slug);
    }
}
