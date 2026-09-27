<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitorDailyStat extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function monitor(): BelongsTo
    {
        return $this->belongsTo(Monitor::class);
    }

    public function uptime(): ?float
    {
        $counted = $this->up + $this->down + $this->warning;

        return $counted > 0 ? round(($this->up + $this->warning) / $counted * 100, 3) : null;
    }

    public function avgResponse(): ?int
    {
        return $this->response_count > 0 ? (int) round($this->response_sum / $this->response_count) : null;
    }
}
