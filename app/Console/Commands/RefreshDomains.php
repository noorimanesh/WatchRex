<?php

namespace App\Console\Commands;

use App\Jobs\RefreshDomain;
use App\Models\Domain;
use Illuminate\Console\Command;

class RefreshDomains extends Command
{
    protected $signature = 'watchrex:domains {--all : Refresh every domain regardless of age}';

    protected $description = 'Refresh WHOIS, DNS, SSL and e-mail security data of stale domains';

    public function handle(): int
    {
        $hours = (int) config('watchrex.domains.refresh_hours');

        Domain::query()
            ->when(! $this->option('all'), fn ($q) => $q->where(fn ($q) => $q->whereNull('last_checked_at')->orWhere('last_checked_at', '<', now()->subHours($hours))))
            ->pluck('id')
            ->each(fn ($id) => RefreshDomain::dispatch($id, true)->onQueue(config('watchrex.queues.domains')));

        return self::SUCCESS;
    }
}
