<?php

namespace App\Console\Commands;

use App\Models\Monitor;
use App\Services\Checks\CheckerFactory;
use App\Services\MonitorRunner;
use Illuminate\Console\Command;

class CheckMonitor extends Command
{
    protected $signature = 'watchrex:check {monitor : Monitor ID} {--dry : Do not store the result}';

    protected $description = 'Run a single monitor check and print the diagnostics';

    public function handle(MonitorRunner $runner): int
    {
        $monitor = Monitor::findOrFail($this->argument('monitor'));
        $result = $this->option('dry')
            ? CheckerFactory::for($monitor->type)->check($monitor)
            : $runner->run($monitor);

        $this->line(sprintf('<fg=%s>%s</> %s · %s ms', $result->isDown() ? 'red' : 'green', strtoupper($result->status->value), $result->message, $result->responseMs ?? '—'));
        $this->line(json_encode($result->details, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result->isDown() ? self::FAILURE : self::SUCCESS;
    }
}
