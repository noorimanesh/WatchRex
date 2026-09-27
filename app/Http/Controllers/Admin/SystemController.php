<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Heartbeat;
use App\Models\Monitor;
use App\Models\Server;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SystemController extends Controller
{
    public function index()
    {
        $scheduler = cache('watchrex:scheduler_heartbeat');
        $queue = config('queue.default');

        $pending = null;
        $failed = null;
        try {
            $pending = $queue === 'database' ? DB::table(config('queue.connections.database.table', 'jobs'))->count() : null;
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        } catch (Throwable) {
        }

        $dbSize = null;
        try {
            $driver = DB::connection()->getDriverName();
            $dbSize = match ($driver) {
                'sqlite' => @filesize(DB::connection()->getDatabaseName()) ?: null,
                'mysql', 'mariadb' => (int) DB::selectOne('SELECT SUM(data_length + index_length) AS s FROM information_schema.tables WHERE table_schema = DATABASE()')->s,
                'pgsql' => (int) DB::selectOne('SELECT pg_database_size(current_database()) AS s')->s,
                default => null,
            };
        } catch (Throwable) {
        }

        return view('admin.system', [
            'checks' => [
                'scheduler' => $scheduler && $scheduler > time() - 120,
                'scheduler_last' => $scheduler,
                'queue_driver' => $queue,
                'queue_pending' => $pending,
                'queue_failed' => $failed,
                'php' => PHP_VERSION,
                'laravel' => app()->version(),
                'db_driver' => DB::connection()->getDriverName(),
                'db_size' => $dbSize,
                'debug' => config('app.debug'),
                'https' => str_starts_with((string) config('app.url'), 'https://'),
                'session_encrypt' => config('session.encrypt'),
                'exec' => function_exists('exec'),
                'opcache' => function_exists('opcache_get_status') && (@opcache_get_status(false)['opcache_enabled'] ?? false),
                'memory' => memory_get_peak_usage(true),
            ],
            'counts' => [
                'users' => User::count(),
                'monitors' => Monitor::count(),
                'active_monitors' => Monitor::where('is_active', true)->count(),
                'checks_per_minute' => (int) round(Monitor::where('is_active', true)->get(['interval'])->sum(fn ($m) => 60 / max(10, $m->interval))),
                'servers' => Server::count(),
                'domains' => Domain::count(),
                'heartbeats' => Heartbeat::count(),
            ],
        ]);
    }

    public function audit(Request $request)
    {
        $logs = AuditLog::with('user:id,name,email')
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', 'like', "{$a}%"))
            ->when($request->query('user'), fn ($q, $u) => $q->where('user_id', (int) $u))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return view('admin.audit', ['logs' => $logs]);
    }
}
