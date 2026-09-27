<?php

namespace App\Console\Commands;

use App\Models\Monitor;
use App\Models\User;
use App\Services\Checks\CheckerFactory;
use App\Services\Checks\CheckResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Remote probe daemon. Runs on another server/location with only:
 *   WATCHREX_HUB_URL, WATCHREX_PROBE_TOKEN, APP_KEY, CACHE_STORE=file
 * No database needed: jobs are pulled from the hub, results pushed back.
 */
class RunProbe extends Command
{
    protected $signature = 'watchrex:probe {--once : Run due checks once and exit}';

    protected $description = 'Run as a remote WatchRex probe (multi-location monitoring)';

    private array $jobs = [];

    private int $fetchedAt = 0;

    private array $lastRun = [];

    public function handle(): int
    {
        $hub = rtrim((string) config('watchrex.probe.hub_url'), '/');
        $token = (string) config('watchrex.probe.token');

        if ($hub === '' || $token === '') {
            $this->error('Set WATCHREX_HUB_URL and WATCHREX_PROBE_TOKEN in .env');

            return self::FAILURE;
        }

        $http = fn () => Http::withToken($token)->acceptJson()->timeout(30)
            ->withHeaders(['X-WatchRex-Version' => config('watchrex.version')])
            ->withUserAgent(config('watchrex.defaults.user_agent'));

        $this->info("🦖 WatchRex probe → {$hub}");

        while (true) {
            if (time() - $this->fetchedAt >= 60) {
                try {
                    $response = $http()->get($hub.'/api/probe/jobs')->throw()->json();
                    $this->jobs = $response['jobs'] ?? [];
                    $this->fetchedAt = time();
                    $this->line(sprintf('[%s] %s: %d monitor(s)', date('H:i:s'), $response['location'] ?? '?', count($this->jobs)));
                } catch (Throwable $e) {
                    $this->warn('Cannot fetch jobs: '.$e->getMessage());
                }
            }

            $due = array_values(array_filter($this->jobs, fn ($j) => time() - ($this->lastRun[$j['id']] ?? 0) >= max(20, (int) $j['interval'])));
            foreach ($due as $job) {
                $this->lastRun[$job['id']] = time();
            }

            if ($due) {
                $results = $this->runAll($due);
                try {
                    $http()->post($hub.'/api/probe/results', ['results' => $results])->throw();
                } catch (Throwable $e) {
                    $this->warn('Cannot push results: '.$e->getMessage());
                }
            }

            if ($this->option('once')) {
                return self::SUCCESS;
            }

            sleep(5);
        }
    }

    /** Runs checks, in parallel worker processes when pcntl is available. */
    private function runAll(array $jobs): array
    {
        $workers = max(1, (int) config('watchrex.probe.concurrency'));
        if ($workers === 1 || count($jobs) === 1 || ! function_exists('pcntl_fork')) {
            return array_map(fn ($job) => $this->runOne($job), $jobs);
        }

        $chunks = array_chunk($jobs, (int) ceil(count($jobs) / $workers));
        $children = [];
        foreach ($chunks as $i => $chunk) {
            $file = tempnam(sys_get_temp_dir(), 'wrx');
            $pid = pcntl_fork();
            if ($pid === 0) {
                file_put_contents($file, serialize(array_map(fn ($job) => $this->runOne($job), $chunk)));
                exit(0);
            }
            $children[$pid] = $file;
        }

        $results = [];
        foreach ($children as $pid => $file) {
            pcntl_waitpid($pid, $status);
            $results = array_merge($results, (array) @unserialize((string) @file_get_contents($file), ['allowed_classes' => false]));
            @unlink($file);
        }

        return $results;
    }

    private function runOne(array $job): array
    {
        $monitor = new Monitor;
        $monitor->forceFill([
            'id' => $job['id'], 'name' => $job['name'], 'type' => $job['type'], 'target' => $job['target'],
            'port' => $job['port'], 'method' => $job['method'], 'interval' => $job['interval'],
            'timeout' => $job['timeout'], 'retries' => 0, 'settings' => $job['settings'] ?? [],
        ]);
        $monitor->credentials = $job['credentials'] ?: null;
        $monitor->setRelation('user', (new User)->forceFill(['role' => $job['guard'] ? 'user' : 'admin']));

        try {
            $result = CheckerFactory::for($monitor->type)->check($monitor);
        } catch (Throwable $e) {
            $result = CheckResult::down('Probe error: '.mb_substr($e->getMessage(), 0, 300));
        }

        unset($result->details['_content']);

        return [
            'monitor_id' => $job['id'],
            'status' => $result->status->value,
            'response_ms' => $result->responseMs,
            'message' => mb_substr($result->message, 0, 500),
            'details' => array_intersect_key($result->details, array_flip(['status_code', 'timings', 'causes', 'steps', 'error', 'ip', 'http_version', 'redirects', 'size'])),
            'meta' => array_intersect_key($result->meta, ['ip' => 1]),
        ];
    }
}
