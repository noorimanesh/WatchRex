<?php

namespace App\Http\Controllers\Admin;

use App\Enums\MonitorStatus;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Heartbeat;
use App\Models\Monitor;
use App\Models\Order;
use App\Models\Server;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** Administration home: platform-wide health, usage, revenue and attention items. */
class OverviewController extends Controller
{
    public function index()
    {
        $active = Monitor::where('is_active', true);
        $monitors = (object) [
            'total' => Monitor::count(),
            'active' => (clone $active)->count(),
            'down' => (clone $active)->where('status', MonitorStatus::Down)->count(),
            'warning' => (clone $active)->where('status', MonitorStatus::Warning)->count(),
        ];

        $servers = Server::get(['id', 'last_seen_at', 'report_interval']);
        $queue = config('queue.default');
        $pending = $failed = null;
        try {
            $pending = $queue === 'database' ? DB::table(config('queue.connections.database.table', 'jobs'))->count() : null;
            $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : null;
        } catch (Throwable) {
        }
        $scheduler = cache('watchrex:scheduler_heartbeat');

        $plans = User::groupBy('plan')->selectRaw('plan, COUNT(*) as n')->pluck('n', 'plan');

        // Users at ≥ 80 % of their monitor quota, or whose paid plan ends within 7 days.
        $nearLimit = User::withCount('monitors')->where('is_active', true)->get()
            ->filter(fn (User $u) => ($max = $u->limit('max_monitors')) && $u->monitors_count >= 0.8 * $max)->take(8);
        $expiring = User::where('is_active', true)->whereNotNull('plan_expires_at')
            ->whereBetween('plan_expires_at', [now()->subDays(3), now()->addDays(7)])->orderBy('plan_expires_at')->limit(8)->get();

        return view('admin.overview', [
            'stats' => [
                'users' => User::count(),
                'users_active' => User::where('is_active', true)->count(),
                'admins' => User::where('role', UserRole::Admin->value)->count(),
                'signups_7d' => User::where('created_at', '>=', now()->subDays(7))->count(),
                'active_7d' => User::where('last_login_at', '>=', now()->subDays(7))->count(),
                'monitors' => (int) $monitors->total,
                'monitors_active' => (int) $monitors->active,
                'monitors_down' => (int) $monitors->down,
                'monitors_warning' => (int) $monitors->warning,
                'servers' => $servers->count(),
                'servers_online' => $servers->filter(fn ($s) => $s->isOnline())->count(),
                'status_pages' => StatusPage::count(),
                'checks_24h' => Heartbeat::where('created_at', '>=', now()->subDay())->count(),
                'revenue_month' => Schema::hasTable('orders') ? (int) Order::where('status', 'paid')->where('paid_at', '>=', now()->startOfMonth())->sum('amount') : 0,
                'orders_pending' => Schema::hasTable('orders') ? Order::where('status', 'pending')->count() : 0,
            ],
            'system' => [
                'scheduler' => $scheduler && $scheduler > time() - 120,
                'queue_driver' => $queue,
                'queue_pending' => $pending,
                'queue_failed' => $failed,
                'debug' => (bool) config('app.debug'),
                'https' => str_starts_with((string) config('app.url'), 'https://'),
            ],
            'plans' => $plans,
            'recentUsers' => User::latest()->limit(6)->get(['id', 'name', 'email', 'plan', 'created_at', 'is_active']),
            'topUsers' => User::withCount('monitors')->orderByDesc('monitors_count')->limit(6)->get(['id', 'name', 'email', 'plan']),
            'nearLimit' => $nearLimit,
            'expiring' => $expiring,
            'audit' => AuditLog::with('user:id,name')->latest('id')->limit(10)->get(),
        ]);
    }
}
