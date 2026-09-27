<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['team_id', 'title', 'description', 'starts_at', 'ends_at'])]
class MaintenanceWindow extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function monitors(): BelongsToMany
    {
        return $this->belongsToMany(Monitor::class);
    }

    public function state(): string
    {
        return match (true) {
            now()->lt($this->starts_at) => 'scheduled',
            now()->gt($this->ends_at) => 'completed',
            default => 'active',
        };
    }
}
