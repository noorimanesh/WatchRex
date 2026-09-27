<?php

namespace App\Http\Controllers;

use App\Enums\MonitorType;
use App\Jobs\RefreshDomain;
use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Monitor;
use App\Services\Uptime;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainController extends Controller
{
    public function index(Request $request)
    {
        $domains = Domain::visibleTo($request->user())
            ->when($request->query('q'), fn ($q, $t) => $q->where('name', 'like', "%{$t}%"))
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')
            ->get();

        return view('domains.index', ['domains' => $domains, 'teams' => $this->assignableTeams()]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        $request->merge(['name' => self::normalize((string) $request->input('name'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:253', 'regex:/^(?!-)[a-z0-9-]{1,63}(?<!-)(\.[a-z0-9-]{1,63})+$/', Rule::unique('domains')->where('user_id', $user->id)],
            'warn_days' => ['nullable', 'integer', 'between:1,120'],
            'team_id' => $this->teamRule(),
        ]);

        if (! $user->withinLimit('max_domains', $user->domains()->count())) {
            return back()->withErrors(['name' => __('Your plan domain limit has been reached.')]);
        }

        $domain = $user->domains()->create(['name' => $data['name'], 'team_id' => $data['team_id'] ?? null, 'warn_days' => $data['warn_days'] ?? config('watchrex.defaults.domain_warn_days')]);
        RefreshDomain::dispatch($domain->id)->onQueue(config('watchrex.queues.domains'));
        AuditLog::record('domain.created', $domain, ['name' => $domain->name]);

        return redirect()->route('domains.show', $domain)->with('success', __('Domain added. Analysis is running in the background.'));
    }

    public function show(Domain $domain)
    {
        $this->authorizeOwner($domain);

        $monitors = $domain->relatedMonitors();
        $uptime = Uptime::last24h($monitors->pluck('id')->all());

        return view('domains.show', [
            'domain' => $domain,
            'monitors' => $monitors,
            'uptime' => $uptime,
            'monitoredHosts' => $monitors->map(fn ($m) => strtolower((string) $m->host()))->unique()->all(),
        ]);
    }

    public function update(Request $request, Domain $domain)
    {
        $this->authorizeOwner($domain);
        $domain->update($request->validate(['warn_days' => ['required', 'integer', 'between:1,120']]));

        return back()->with('success', __('Saved.'));
    }

    public function refresh(Domain $domain)
    {
        $this->authorizeOwner($domain);
        RefreshDomain::dispatch($domain->id)->onQueue(config('watchrex.queues.domains'));

        return back()->with('success', __('Refresh queued.'));
    }

    public function destroy(Domain $domain)
    {
        $this->authorizeOwner($domain);
        AuditLog::record('domain.deleted', $domain, ['name' => $domain->name]);
        $domain->delete();

        return redirect()->route('domains.index')->with('success', __('Domain removed.'));
    }

    /** One-click: create HTTP + SSL monitors for a discovered (sub)domain. */
    public function monitor(Request $request, Domain $domain)
    {
        $this->authorizeOwner($domain);
        $host = strtolower((string) $request->input('host'));
        abort_unless($host === $domain->name || str_ends_with($host, '.'.$domain->name), 422);

        $user = $domain->user;
        if (! $user->withinLimit('max_monitors', $user->monitors()->count())) {
            return back()->withErrors(['host' => __('Monitor limit reached for this plan.')]);
        }

        $monitor = new Monitor([
            'name' => $host, 'type' => MonitorType::Http, 'target' => 'https://'.$host,
            'interval' => max(60, $user->limit('min_interval') ?? 60), 'timeout' => 10, 'retries' => 1, 'is_active' => true,
            'group' => $domain->name,
            'settings' => ['verify_ssl' => true, 'follow_redirects' => true, 'anomaly' => true, 'notify_warning' => true, 'expected_status' => '200-399'],
        ]);
        $monitor->user_id = $user->id;
        $monitor->team_id = $domain->team_id;
        $monitor->next_check_at = now();
        $monitor->save();

        return back()->with('success', __('Monitor created for :h', ['h' => $host]));
    }

    public static function normalize(string $input): string
    {
        $input = strtolower(trim($input));
        $input = preg_replace('#^[a-z]+://#', '', $input);
        $input = explode('/', $input)[0];
        $input = explode(':', $input)[0];
        $input = preg_replace('/^www\./', '', trim($input, '.'));

        if (function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7E]/', $input)) {
            $input = idn_to_ascii($input, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: $input;
        }

        return $input;
    }
}
