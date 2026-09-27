<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'monitor_id', 'title', 'severity', 'status', 'cause', 'started_at', 'resolved_at', 'acknowledged_at', 'duration'])]
class Incident extends Model
{
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderBy('created_at');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->where('user_id', $user->id);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function durationSeconds(): int
    {
        return $this->duration ?? (int) $this->started_at->diffInSeconds($this->resolved_at ?? now());
    }

    public function timeline(string $type, string $message, ?int $userId = null): IncidentUpdate
    {
        return $this->updates()->create(['type' => $type, 'message' => $message, 'user_id' => $userId]);
    }
}
