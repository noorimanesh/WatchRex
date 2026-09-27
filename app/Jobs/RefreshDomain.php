<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Services\Domain\DomainInspector;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RefreshDomain implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $domainId, public bool $withSubdomains = true) {}

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    public function handle(DomainInspector $inspector): void
    {
        if ($domain = Domain::with('user')->find($this->domainId)) {
            app()->setLocale($domain->user->locale ?? config('app.locale'));
            $inspector->refresh($domain, $this->withSubdomains);
        }
    }
}
