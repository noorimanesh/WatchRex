@php $imp = $server->settings['import_options'] ?? \App\Services\SiteImporter::DEFAULT_OPTIONS; $last = $server->settings['last_import'] ?? null; @endphp
<div class="card mt" id="sites">
    <div class="card-head">
        <h2>🌐 {{ __('Sites on this server') }} <span class="chip">{{ $siteCounts['total'] }}</span></h2>
        <div class="seg">
            @foreach (['' => __('All'), 'new' => __('Not monitored'), 'monitored' => __('Monitored'), 'ignored' => __('Ignored')] as $k => $l)
                <a href="?sites={{ $k }}#sites" class="{{ request('sites', '') === $k ? 'active' : '' }}">{{ $l }}</a>
            @endforeach
        </div>
    </div>

    @if ($siteCounts['total'] === 0)
        <div class="card-body"><p class="muted small">{{ __('The agent lists every hosted domain (cPanel, DirectAdmin, Plesk, Nginx/Apache vhosts, IIS) every 30 minutes. Update the agent to version 1.1 if nothing appears.') }}</p></div>
    @else
        <div class="card-body small muted" style="border-bottom:1px solid var(--border)">
            {{ __(':m of :t sites are monitored, :i ignored.', ['m' => $siteCounts['monitored'], 't' => $siteCounts['total'], 'i' => $siteCounts['ignored']]) }}
            @if ($last) · {{ __('Last import') }}: {{ __(':c monitors created, :r reused, :g groups, :d domains', ['c' => $last['monitors_created'], 'r' => $last['monitors_reused'], 'g' => $last['groups'], 'd' => $last['domains']]) }}@if ($last['skipped_limit']) · <span class="text-warn">{{ __(':n skipped (plan limit)', ['n' => $last['skipped_limit']]) }}</span>@endif @endif
        </div>

        <form method="POST" action="{{ route('servers.sites', $server) }}" id="sites-form">
            @csrf
            <div class="card-body row wrap" style="gap:12px;border-bottom:1px solid var(--border)">
                <input class="input" type="search" form="site-search" name="site_q" value="{{ request('site_q') }}" placeholder="{{ __('Domain or account…') }}" style="width:220px">
                <span class="small muted">{{ __('Monitor') }}:</span>
                <label class="check" style="margin:0"><input type="checkbox" name="website" value="1" @checked($imp['website'] ?? true)> {{ __('Website (HTTP + SSL)') }}</label>
                <label class="check" style="margin:0"><input type="checkbox" name="mail" value="1" @checked($imp['mail'] ?? true)> {{ __('Mail (SMTP/IMAP/POP3)') }}</label>
                <label class="check" style="margin:0"><input type="checkbox" name="domain" value="1" @checked($imp['domain'] ?? true)> {{ __('Domain, DNS & e-mail security') }}</label>
                <label class="small muted row" style="gap:6px">{{ __('every') }} <input class="input" type="number" name="interval" value="{{ $imp['interval'] ?? 300 }}" min="20" style="width:80px;padding:5px 8px"> s</label>
                <span style="flex:1"></span>
                <button class="btn primary sm" name="action" value="import">{{ __('Monitor selected') }}</button>
                <button class="btn sm" name="action" value="import_all" data-confirm="{{ __('Create monitoring for every site that is not monitored yet?') }}">{{ __('Monitor all new') }}</button>
                <button class="btn ghost sm" name="action" value="ignore">{{ __('Ignore') }}</button>
                @if (request('sites') === 'ignored')<button class="btn ghost sm" name="action" value="unignore">{{ __('Un-ignore') }}</button>@endif
            </div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th style="width:32px"><input type="checkbox" data-check-all="sites[]"></th><th>{{ __('Domain') }}</th><th>{{ __('Account') }}</th><th>{{ __('Type') }}</th><th>{{ __('Status') }}</th><th>{{ __('Uptime 24h') }}</th><th>{{ __('Last seen') }}</th></tr></thead>
                <tbody>
                @forelse ($sites as $site)
                    @php $h = $site->monitor_group_id ? ($siteHealth[$site->monitor_group_id] ?? null) : null; @endphp
                    <tr class="{{ $site->ignored ? 'faint' : '' }}">
                        <td><input type="checkbox" name="sites[]" value="{{ $site->id }}"></td>
                        <td class="ltr"><b>{{ $site->domain }}</b></td>
                        <td class="mono small">{{ $site->account ?? '—' }}</td>
                        <td><span class="chip">{{ __(['main' => 'Main', 'addon' => 'Addon', 'sub' => 'Subdomain', 'alias' => 'Alias'][$site->kind] ?? $site->kind) }}</span></td>
                        <td>
                            @if ($h)<a href="{{ route('groups.show', $site->monitor_group_id) }}"><x-status :status="\App\Services\GroupHealth::statusEnum($h['status'])" /></a>
                            @elseif ($site->ignored)<span class="badge">{{ __('Ignored') }}</span>
                            @else<span class="badge warning">{{ __('Not monitored') }}</span>@endif
                        </td>
                        <td>{{ $h ? Fmt::uptime($h['uptime']) : '—' }}</td>
                        <td class="small muted">{{ $site->last_seen_at?->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="muted">{{ __('No sites match.') }}</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </form>
        <form id="site-search" method="GET" action="#sites"><input type="hidden" name="sites" value="{{ request('sites') }}"></form>
        {{ $sites->fragment('sites')->links('partials.pagination') }}

        <details class="card-body" style="border-top:1px solid var(--border)">
            <summary class="small"><b>⚙️ {{ __('Automatic import') }}</b> — {{ ($server->settings['auto_import'] ?? false) ? __('enabled') : __('disabled') }}</summary>
            <form method="POST" action="{{ route('servers.sites.settings', $server) }}" class="mt-s">
                @csrf @method('PUT')
                <label class="check"><input type="checkbox" name="auto_import" value="1" @checked($server->settings['auto_import'] ?? false)> {{ __('Automatically monitor new sites as soon as the agent discovers them') }}</label>
                <div class="row wrap">
                    <label class="check"><input type="checkbox" name="website" value="1" @checked($imp['website'] ?? true)> {{ __('Website') }}</label>
                    <label class="check"><input type="checkbox" name="mail" value="1" @checked($imp['mail'] ?? true)> {{ __('Mail') }}</label>
                    <label class="check"><input type="checkbox" name="domain" value="1" @checked($imp['domain'] ?? true)> {{ __('Domain') }}</label>
                    <input class="input" type="number" name="interval" value="{{ $imp['interval'] ?? 300 }}" min="20" style="width:90px">
                    <button class="btn sm">{{ __('Save') }}</button>
                </div>
            </form>
        </details>
    @endif
</div>
