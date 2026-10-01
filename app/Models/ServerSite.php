<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerSite extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ignored' => 'boolean', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(MonitorGroup::class, 'monitor_group_id');
    }
}
