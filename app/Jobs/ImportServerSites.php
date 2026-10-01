<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\SiteImporter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ImportServerSites implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    /** @param list<int>|null $siteIds null = every not-yet-imported site */
    public function __construct(public int $serverId, public ?array $siteIds = null, public array $options = []) {}

    public function uniqueId(): string
    {
        return $this->serverId.':'.md5(json_encode([$this->siteIds, $this->options]));
    }

    public function handle(SiteImporter $importer): void
    {
        $server = Server::with('user')->find($this->serverId);
        if (! $server) {
            return;
        }

        app()->setLocale($server->user->locale ?? config('app.locale'));
        $sites = $server->sites()->where('ignored', false)
            ->when($this->siteIds !== null, fn ($q) => $q->whereIn('id', $this->siteIds), fn ($q) => $q->whereNull('monitor_group_id'))
            ->get();

        $stats = $importer->import($server, $sites, $this->options);
        $server->forceFill(['settings' => array_merge($server->settings ?? [], ['last_import' => $stats + ['at' => now()->toIso8601String()]])])->save();
    }
}
