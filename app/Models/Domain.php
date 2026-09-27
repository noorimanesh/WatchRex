<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['team_id', 'name', 'warn_days'])]
class Domain extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'expires_at' => 'datetime',
            'ssl_expires_at' => 'datetime',
            'dns_changed_at' => 'datetime',
            'last_checked_at' => 'datetime',
            'nameservers' => 'array',
            'dns' => 'array',
            'ssl' => 'array',
            'email_security' => 'array',
            'subdomains' => 'array',
            'network' => 'array',
            'blacklists' => 'array',
            'alerts_sent' => 'array',
        ];
    }

    public function daysUntilExpiry(): ?int
    {
        return $this->expires_at ? (int) floor(now()->diffInDays($this->expires_at, false)) : null;
    }

    public function sslDaysLeft(): ?int
    {
        return $this->ssl_expires_at ? (int) floor(now()->diffInDays($this->ssl_expires_at, false)) : null;
    }

    /** Monitors belonging to the same owner that target this domain or its subdomains. */
    public function relatedMonitors()
    {
        $name = $this->name;

        return Monitor::where(fn ($q) => $q->where('user_id', $this->user_id)->when($this->team_id, fn ($q) => $q->orWhere('team_id', $this->team_id)))->get()->filter(function (Monitor $m) use ($name) {
            $host = strtolower((string) $m->host());

            return $host === $name || str_ends_with($host, '.'.$name);
        });
    }
}
