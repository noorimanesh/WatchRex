<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\Request;

/** Receives reports from the WatchRex server agent (bash, no dependencies). */
class AgentController extends Controller
{
    public function report(Request $request)
    {
        $server = Server::findByToken($request->bearerToken());
        if (! $server) {
            return response()->json(['message' => 'Invalid agent token'], 401);
        }

        $p = $request->json()->all();
        if (! is_array($p) || ! $p) {
            return response()->json(['message' => 'Empty report'], 422);
        }

        $num = fn ($v, $max = 1e15) => is_numeric($v) ? max(0, min((float) $v, $max)) : null;
        $int = fn ($v) => is_numeric($v) ? (int) max(0, min((float) $v, 4294967295)) : null;
        $str = fn ($v, $len = 120) => is_scalar($v) ? mb_substr(strip_tags((string) $v), 0, $len) : null;

        $mail = (array) ($p['mail'] ?? []);
        $latest = [
            'hostname' => $str($p['hostname'] ?? null),
            'os' => $str($p['os'] ?? null),
            'kernel' => $str($p['kernel'] ?? null),
            'uptime' => $int($p['uptime'] ?? null),
            'cores' => $int($p['cores'] ?? null),
            'cpu' => $num($p['cpu'] ?? null, 100),
            'ram' => $num($p['ram'] ?? null, 100),
            'mem_total' => $num($p['mem_total'] ?? null),
            'mem_used' => $num($p['mem_used'] ?? null),
            'swap' => $num($p['swap'] ?? null, 100),
            'disk' => $num($p['disk'] ?? null, 100),
            'load' => array_map(fn ($v) => $num($v, 10000), array_slice((array) ($p['load'] ?? []), 0, 3)),
            'procs' => $int($p['procs'] ?? null),
            'temp' => $num($p['temp'] ?? null, 200),
            'iowait' => $num($p['iowait'] ?? null, 100),
            'net' => ['rx_rate' => $num($p['net']['rx_rate'] ?? null), 'tx_rate' => $num($p['net']['tx_rate'] ?? null)],
            'disks' => array_map(fn ($d) => [
                'mount' => $str($d['mount'] ?? '?', 100), 'fs' => $str($d['fs'] ?? null, 60),
                'total' => $num($d['total'] ?? null), 'used' => $num($d['used'] ?? null), 'percent' => $num($d['percent'] ?? null, 100),
                'inodes' => $num($d['inodes'] ?? null, 100),
            ], array_slice((array) ($p['disks'] ?? []), 0, 20)),
            'services' => collect((array) ($p['services'] ?? []))->take(40)->mapWithKeys(fn ($v, $k) => [$str($k, 40) => $str($v, 20) ?? 'unknown'])->all(),
            'containers' => array_map(fn ($c) => [
                'name' => $str($c['name'] ?? null, 80), 'image' => $str($c['image'] ?? null, 120), 'state' => $str($c['state'] ?? null, 20),
                'status' => $str($c['status'] ?? null, 80), 'cpu' => $num($c['cpu'] ?? null, 10000), 'mem' => $str($c['mem'] ?? null, 40),
                'restarts' => $int($c['restarts'] ?? null), 'health' => $str($c['health'] ?? null, 20),
            ], array_slice((array) ($p['containers'] ?? []), 0, 100)),
            'top' => array_map(fn ($t) => ['cmd' => $str($t['cmd'] ?? null, 80), 'user' => $str($t['user'] ?? null, 32), 'cpu' => $num($t['cpu'] ?? null, 10000), 'mem' => $num($t['mem'] ?? null, 100)], array_slice((array) ($p['top'] ?? []), 0, 10)),
            'mail' => [
                'mta' => $str($mail['mta'] ?? null, 20),
                'queue' => $int($mail['queue'] ?? null),
                'sent' => $int($mail['sent'] ?? null),
                'received' => $int($mail['received'] ?? null),
                'bounced' => $int($mail['bounced'] ?? null),
                'deferred' => $int($mail['deferred'] ?? null),
                'rejected' => $int($mail['rejected'] ?? null),
                'login_ok' => $int($mail['login_ok'] ?? null),
                'login_failed' => $int($mail['login_failed'] ?? null),
                'failed_ips' => array_map(fn ($r) => ['ip' => $str($r['ip'] ?? null, 45), 'count' => $int($r['count'] ?? 0)], array_slice((array) ($mail['failed_ips'] ?? []), 0, 15)),
                'failed_users' => array_map(fn ($r) => ['user' => $str($r['user'] ?? null, 120), 'count' => $int($r['count'] ?? 0)], array_slice((array) ($mail['failed_users'] ?? []), 0, 15)),
                'recent_bounces' => array_map(fn ($l) => $str($l, 300), array_slice((array) ($mail['recent_bounces'] ?? []), 0, 15)),
            ],
            'ssh_failed' => $int($p['ssh_failed'] ?? null),
            'accounts' => array_map(fn ($a) => [
                'user' => $str($a['user'] ?? null, 64), 'domain' => $str($a['domain'] ?? null, 255),
                'disk_used_mb' => $num($a['disk_used_mb'] ?? null), 'disk_limit_mb' => $num($a['disk_limit_mb'] ?? null),
                'suspended' => (bool) ($a['suspended'] ?? false), 'plan' => $str($a['plan'] ?? null, 64),
            ], array_slice((array) ($p['accounts'] ?? []), 0, 2000)),
            'reported_at' => now()->toIso8601String(),
        ];

        // Account lists are expensive to build; the agent only sends them every ~30 min.
        if (! isset($p['accounts']) && $server->stat('accounts')) {
            $latest['accounts'] = $server->stat('accounts');
        }

        $server->forceFill([
            'hostname' => $latest['hostname'],
            'os' => $latest['os'],
            'panel' => $str($p['panel'] ?? null, 24),
            'agent_version' => $str($p['version'] ?? null, 16),
            'ip' => $request->ip(),
            'latest' => $latest,
            'status' => 'online',
            'last_seen_at' => now(),
        ])->save();

        $server->metrics()->create([
            'cpu' => $latest['cpu'], 'ram' => $latest['ram'], 'disk' => $latest['disk'], 'swap' => $latest['swap'],
            'load1' => $latest['load'][0] ?? null,
            'net_rx' => $latest['net']['rx_rate'], 'net_tx' => $latest['net']['tx_rate'],
            'mail_queue' => $latest['mail']['queue'],
            'mail_sent' => $latest['mail']['sent'],
            'mail_received' => $latest['mail']['received'],
            'mail_bounced' => $latest['mail']['bounced'],
            'mail_deferred' => $latest['mail']['deferred'],
            'mail_rejected' => $latest['mail']['rejected'],
            'login_ok' => $latest['mail']['login_ok'],
            'login_failed' => $latest['mail']['login_failed'],
        ]);

        return response()->json(['ok' => true, 'interval' => $server->report_interval, 'accounts_every' => 1800]);
    }
}
