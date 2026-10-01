<?php

namespace App\Services;

use App\Enums\MonitorType;
use App\Jobs\RefreshDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Monitor;
use App\Models\MonitorGroup;
use App\Models\Server;
use App\Models\ServerSite;
use App\Services\Domain\DnsLookup;
use App\Services\Domain\DomainInspector;
use Illuminate\Support\Collection;

/**
 * Turns sites discovered on a server into monitoring:
 *   🖥️ server group
 *     └ 🌐 website group (per site) — HTTP(S) monitor (+ domain analysis)
 *         └ 📬 mail group — SMTP / IMAP / POP3 on the site's MX (shared between sites)
 */
class SiteImporter
{
    public const DEFAULT_OPTIONS = ['website' => true, 'domain' => true, 'mail' => true, 'interval' => 300];

    /** @var (callable(string): ?string)|null test hook to resolve the primary MX host */
    public static $mxResolver = null;

    /** @var array<string, Collection<int, Monitor>> owner's monitors per type, loaded once */
    private array $existing = [];

    private array $stats = ['sites' => 0, 'monitors_created' => 0, 'monitors_reused' => 0, 'groups' => 0, 'domains' => 0, 'skipped_limit' => 0, 'no_mx' => 0];

    /** @param Collection<int, ServerSite> $sites */
    public function import(Server $server, Collection $sites, array $options = []): array
    {
        $opt = array_merge(self::DEFAULT_OPTIONS, array_intersect_key($options, self::DEFAULT_OPTIONS));
        $owner = $server->user;
        $interval = max((int) $opt['interval'], (int) ($owner->limit('min_interval') ?? 20));

        $serverGroup = $this->group($owner->id, ['kind' => 'server', 'server_id' => $server->id], ['name' => $server->name, 'team_id' => $server->team_id]);
        $serverMonitor = $server->monitors()->where('type', MonitorType::Server->value)->first();

        foreach ($sites->where('ignored', false) as $site) {
            $domain = strtolower($site->domain);
            $this->stats['sites']++;

            $siteGroup = $this->group($owner->id, ['kind' => 'website', 'domain' => $domain], [
                'name' => $domain, 'parent_id' => $serverGroup->id, 'server_id' => $server->id, 'team_id' => $server->team_id,
            ]);

            if ($opt['website']) {
                $monitor = $this->monitor($owner, MonitorType::Http, 'https://'.$domain, null, [
                    'name' => $domain, 'interval' => $interval, 'parent_id' => $serverMonitor?->id, 'team_id' => $server->team_id,
                    'settings' => ['verify_ssl' => true, 'follow_redirects' => true, 'expected_status' => '200-399', 'anomaly' => true, 'notify_warning' => true],
                ], fn (Monitor $m) => strtolower((string) $m->host()) === $domain);
                $monitor && $siteGroup->monitors()->syncWithoutDetaching([$monitor->id]);
            }

            $isRoot = DomainInspector::registrable($domain) === $domain;

            if ($opt['domain'] && $isRoot && $site->kind !== 'sub') {
                $this->domain($owner, $domain, $server->team_id);
            }

            if ($opt['mail'] && $site->kind !== 'sub') {
                $this->mail($owner, $domain, $siteGroup, $interval, $server->team_id);
            }

            $site->forceFill(['monitor_group_id' => $siteGroup->id])->save();
        }

        AuditLog::record('server.sites_imported', $server, $this->stats, $owner->id);

        return $this->stats;
    }

    private function mail($owner, string $domain, MonitorGroup $siteGroup, int $interval, ?int $teamId): void
    {
        $host = self::$mxResolver ? (self::$mxResolver)($domain) : self::primaryMx($domain);

        if (! $host) {
            $this->stats['no_mx']++;

            return;
        }

        $host = strtolower($host);
        $mailGroup = $this->group($owner->id, ['kind' => 'mail', 'domain' => $domain], ['name' => 'Mail · '.$domain, 'parent_id' => $siteGroup->id, 'team_id' => $teamId]);

        // Hosted providers (Google, Microsoft…) are monitored once, just like a shared server MX.
        foreach ([[MonitorType::Smtp, 587], [MonitorType::Imap, 993], [MonitorType::Pop3, 995]] as [$type, $port]) {
            $monitor = $this->monitor($owner, $type, $host, $port, [
                'name' => strtoupper($type->value).' '.$host, 'interval' => $interval, 'team_id' => $teamId,
                'settings' => ['security' => 'auto', 'require_tls' => true, 'rbl_check' => $type === MonitorType::Smtp, 'notify_warning' => true, 'anomaly' => false],
            ], fn (Monitor $m) => strtolower((string) $m->target) === $host && (int) $m->effectivePort() === $port);

            $monitor && $mailGroup->monitors()->syncWithoutDetaching([$monitor->id]);
        }
    }

    public static function primaryMx(string $domain): ?string
    {
        $mx = collect(DnsLookup::records($domain, 'MX'))
            ->map(fn ($r) => ['pri' => (int) explode(' ', $r['value'])[0], 'host' => rtrim((string) (explode(' ', $r['value'], 2)[1] ?? ''), '.')])
            ->filter(fn ($r) => $r['host'] !== '' && $r['host'] !== '.')
            ->sortBy('pri')->first();

        return $mx['host'] ?? null;
    }

    /** Reuses an equivalent monitor of the owner, otherwise creates one (respecting the plan limit). */
    private function monitor($owner, MonitorType $type, string $target, ?int $port, array $attributes, callable $same): ?Monitor
    {
        $this->existing[$type->value] ??= Monitor::where('user_id', $owner->id)->where('type', $type->value)->get();
        $existing = $this->existing[$type->value]->first($same);
        if ($existing) {
            $this->stats['monitors_reused']++;

            return $existing;
        }

        if (! $owner->withinLimit('max_monitors', $owner->monitors()->count())) {
            $this->stats['skipped_limit']++;

            return null;
        }

        $monitor = new Monitor(array_merge(['type' => $type, 'target' => $target, 'port' => $port, 'timeout' => 10, 'retries' => 1, 'is_active' => true], $attributes));
        $monitor->user_id = $owner->id;
        $monitor->next_check_at = now()->addSeconds(random_int(5, 120)); // spread the first checks
        $monitor->save();
        $this->existing[$type->value]->push($monitor);
        $this->stats['monitors_created']++;

        return $monitor;
    }

    private function domain($owner, string $name, ?int $teamId): void
    {
        if (Domain::where('user_id', $owner->id)->where('name', $name)->exists() || ! $owner->withinLimit('max_domains', $owner->domains()->count())) {
            return;
        }

        $domain = new Domain(['name' => $name, 'warn_days' => config('watchrex.defaults.domain_warn_days'), 'team_id' => $teamId]);
        $domain->user_id = $owner->id;
        $domain->save();
        RefreshDomain::dispatch($domain->id)->onQueue(config('watchrex.queues.domains'));
        $this->stats['domains']++;
    }

    private function group(int $userId, array $match, array $values): MonitorGroup
    {
        $exists = MonitorGroup::where('user_id', $userId)->where($match)->exists();
        $group = MonitorGroup::findOrCreateFor($userId, $match, $values);
        if (! $exists) {
            $this->stats['groups']++;
        }

        return $group;
    }
}
