<?php

namespace App\Jobs;

use App\Models\Monitor;
use App\Services\ContentWatcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CaptureScreenshot implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 150;

    public int $tries = 1;

    public int $uniqueFor = 600;

    public function __construct(public int $monitorId) {}

    public function uniqueId(): string
    {
        return (string) $this->monitorId;
    }

    public function handle(ContentWatcher $watcher): void
    {
        if ($monitor = Monitor::with('user')->find($this->monitorId)) {
            $watcher->capture($monitor);
        }
    }
}
