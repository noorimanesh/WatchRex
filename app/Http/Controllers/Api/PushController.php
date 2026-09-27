<?php

namespace App\Http\Controllers\Api;

use App\Enums\MonitorType;
use App\Http\Controllers\Controller;
use App\Models\Monitor;
use App\Services\Checks\CheckResult;
use App\Services\MonitorRunner;
use Illuminate\Http\Request;

/**
 * Heartbeat endpoint for cron jobs, backups and scripts:
 *   curl -fsS "https://watch.example.com/api/push/<token>?status=up&msg=OK&ping=120"
 */
class PushController extends Controller
{
    public function __invoke(Request $request, string $token, MonitorRunner $runner)
    {
        $monitor = Monitor::where('push_token', $token)->where('type', MonitorType::Push->value)->first();

        if (! $monitor) {
            return response()->json(['ok' => false, 'message' => 'Unknown push token'], 404);
        }

        if (! $monitor->is_active) {
            return response()->json(['ok' => false, 'message' => 'Monitor is paused'], 409);
        }

        $status = $request->input('status', 'up') === 'down' ? 'down' : 'up';
        $message = mb_substr(strip_tags((string) $request->input('msg', 'OK')), 0, 250);
        $ping = is_numeric($request->input('ping')) ? (int) $request->input('ping') : null;

        $monitor->forceFill([
            'last_push_at' => now(),
            'meta' => array_merge($monitor->meta ?? [], ['push_status' => $status, 'push_msg' => $message, 'push_ping' => $ping]),
            'next_check_at' => now()->addSeconds($monitor->interval + (int) $monitor->setting('grace', 60)),
        ])->save();

        $runner->process($monitor, $status === 'down' ? CheckResult::down($message ?: 'Job reported failure', $ping) : CheckResult::up($ping, $message ?: 'OK'));

        return response()->json(['ok' => true]);
    }
}
