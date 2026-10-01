@extends('layouts.app')
@section('title', $monitor->exists ? __('Edit monitor') : __('New monitor'))
@section('content')
@php
    $s = fn ($key, $default = null) => old('settings.'.$key, $monitor->setting($key, $default));
    $checked = fn ($key, $default = false) => (bool) old('settings.'.$key, $monitor->exists || ! old() ? $monitor->setting($key, $default) : false);
    $urlTypes = 'http keyword api websocket';
    $hostTypes = 'ping tcp udp dns ssl smtp imap pop3 ftp ssh mysql postgres redis';
    $portTypes = 'tcp udp ssl smtp imap pop3 ftp ssh mysql postgres redis';
    $httpTypes = 'http keyword api';
    $credTypes = 'http keyword api smtp imap pop3 ftp mysql postgres redis';
    $activeTypes = 'http keyword api ping tcp udp dns ssl smtp imap pop3 ftp ssh mysql postgres redis websocket';
    $o = str_repeat('{', 2); $c = str_repeat('}', 2);
    $stepsExample = '[{"name":"login","method":"POST","url":"https://api.example.com/login","body":{"user":"'.$o.'username'.$c.'","pass":"'.$o.'password'.$c.'"},"expect_status":"200","extract":{"token":"data.token"}},'."\n".' {"name":"profile","url":"https://api.example.com/me","headers":{"Authorization":"Bearer '.$o.'token'.$c.'"},"expect":{"success":"true"},"max_ms":800}]';
    $stepsHelp = __('JSON array of steps. Values captured with "extract" can be reused as :var in later steps; credentials are available as :creds.', ['var' => $o.'name'.$c, 'creds' => $o.'username'.$c.', '.$o.'password'.$c.', '.$o.'token'.$c]);
    $categories = ['web' => __('Web & API'), 'mail' => __('Mail'), 'database' => __('Databases'), 'network' => __('Network'), 'passive' => __('Agents & push')];
@endphp
<div class="page-head">
    <div><h1>{{ $monitor->exists ? __('Edit monitor') : __('New monitor') }}</h1><div class="sub">{{ __('Choose what to watch and how WatchRex should react.') }}</div></div>
</div>

@if ($errors->any())<div class="alert error">{{ __('Please fix the highlighted fields.') }} {{ $errors->first() }}</div>@endif

<form method="POST" action="{{ $monitor->exists ? route('monitors.update', $monitor) : route('monitors.store') }}">
    @csrf
    @if ($monitor->exists) @method('PUT') @endif

    <div class="grid g-main">
        <div>
            <fieldset>
                <legend>{{ __('Monitor type') }}</legend>
                @foreach ($categories as $cat => $catLabel)
                    <div class="label" style="margin:6px 0">{{ $catLabel }}</div>
                    <div class="type-grid" style="margin-bottom:10px">
                        @foreach ($types as $type)
                            @continue($type->category() !== $cat)
                            <label><input type="radio" name="type" value="{{ $type->value }}" data-port="{{ $type->defaultPort() }}" @checked(old('type', $monitor->type?->value) === $type->value)> {{ $type->label() }}</label>
                        @endforeach
                    </div>
                @endforeach
            </fieldset>

            <fieldset>
                <legend>{{ __('Target') }}</legend>
                <x-field name="name" :label="__('Display name')" :value="$monitor->name" required maxlength="120" />
                <div data-show-for="{{ $urlTypes }}">
                    <x-field name="target" :label="__('URL')" :value="$monitor->target" placeholder="https://example.com/health" class="ltr" :help="__('For WebSocket use ws:// or wss://')" />
                </div>
                <div data-show-for="{{ $hostTypes }}">
                    <x-field name="target" :label="__('Hostname or IP')" :value="$monitor->target" placeholder="mail.example.com" class="ltr" />
                </div>
                <div data-show-for="{{ $portTypes }}">
                    <x-field name="port" type="number" :label="__('Port')" :value="$monitor->port" min="1" max="65535" class="ltr" :help="__('Leave empty to use the protocol default.')" />
                </div>
                <div data-show-for="server" class="field">
                    <label>{{ __('Server') }}</label>
                    <select name="server_id" class="input">
                        <option value="">—</option>
                        @foreach ($servers as $srv)<option value="{{ $srv->id }}" @selected(old('server_id', $monitor->server_id) == $srv->id)>{{ $srv->name }}</option>@endforeach
                    </select>
                    <span class="help">{{ __('Servers get a monitor automatically when added. Use this for additional ones.') }}</span>
                </div>
                <div data-show-for="push"><div class="alert info small">{{ __('A unique push URL is generated after saving. Your cron job/script calls it; if it stays silent longer than the interval + grace period, the monitor goes down.') }}</div>
                    <x-field name="settings[grace]" dot-name="settings.grace" type="number" :label="__('Grace period (seconds)')" :value="$s('grace', 60)" min="0" />
                </div>
            </fieldset>

            <fieldset data-show-for="{{ $httpTypes }}">
                <legend>{{ __('HTTP request') }}</legend>
                <div class="grid g-2">
                    <div class="field"><label>{{ __('Method') }}</label>
                        <select name="method" class="input">@foreach (['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $m)<option @selected(old('method', $monitor->method) === $m)>{{ $m }}</option>@endforeach</select></div>
                    <x-field name="settings[expected_status]" dot-name="settings.expected_status" :label="__('Accepted status codes')" :value="$s('expected_status', '200-399')" class="ltr" :help="__('e.g. 200-299,301')" />
                </div>
                <x-field name="settings[headers]" dot-name="settings.headers" type="textarea" :label="__('Custom headers')" :value="$s('headers')" placeholder="Accept-Language: fa&#10;X-Api-Key: …" rows="3" />
                <x-field name="settings[body]" dot-name="settings.body" type="textarea" :label="__('Request body')" :value="$s('body')" rows="3" />
                <label class="check"><input type="checkbox" name="settings[verify_ssl]" value="1" @checked($checked('verify_ssl', true))> {{ __('Verify SSL certificate') }}</label>
                <label class="check"><input type="checkbox" name="settings[follow_redirects]" value="1" @checked($checked('follow_redirects', true))> {{ __('Follow redirects') }}</label>
            </fieldset>

            <fieldset data-show-for="{{ $httpTypes }}">
                <legend>{{ __('Content checks') }}</legend>
                <x-field name="settings[keyword]" dot-name="settings.keyword" :label="__('Keyword')" :value="$s('keyword')" :help="__('The page must contain this text (e.g. «در حال حاضر فعال است»).')" />
                <label class="check"><input type="checkbox" name="settings[keyword_invert]" value="1" @checked($checked('keyword_invert'))> {{ __('Alert when the keyword IS present (e.g. "Fatal error")') }}</label>
                <label class="check"><input type="checkbox" name="settings[keyword_case]" value="1" @checked($checked('keyword_case'))> {{ __('Case sensitive') }}</label>
                <div class="grid g-2 mt-s">
                    <x-field name="settings[json_path]" dot-name="settings.json_path" :label="__('JSON path')" :value="$s('json_path')" placeholder="data.status" class="ltr" />
                    <x-field name="settings[json_expected]" dot-name="settings.json_expected" :label="__('Expected value')" :value="$s('json_expected')" placeholder="ok" class="ltr" />
                </div>
            </fieldset>

            <fieldset data-show-for="http keyword">
                <legend>🔍 {{ __('Change detection (defacement)') }}</legend>
                <label class="check"><input type="checkbox" name="settings[detect_changes]" value="1" @checked($checked('detect_changes'))> {{ __('Alert when the visible page text changes') }}</label>
                <div class="grid g-2">
                    <x-field name="settings[change_threshold]" dot-name="settings.change_threshold" type="number" step="0.1" :label="__('Alert when changed by at least (%)')" :value="$s('change_threshold', 5)" min="0.1" max="100" />
                    <x-field name="settings[ignore_pattern]" dot-name="settings.ignore_pattern" :label="__('Ignore text matching (regex)')" :value="$s('ignore_pattern')" placeholder="\d{2}:\d{2}|Visitors: \d+" class="ltr" />
                </div>
                @if ($screenshots)
                    <label class="check"><input type="checkbox" name="settings[visual]" value="1" @checked($checked('visual'))> {{ __('Visual comparison with screenshots (headless Chrome)') }}</label>
                    <x-field name="settings[visual_hours]" dot-name="settings.visual_hours" type="number" :label="__('Screenshot every (hours)')" :value="$s('visual_hours', 6)" min="1" max="168" />
                @endif
            </fieldset>

            <fieldset data-show-for="api">
                <legend>{{ __('Synthetic steps (optional)') }}</legend>
                <x-field name="settings[steps]" dot-name="settings.steps" type="textarea" :value="is_array($s('steps')) ? json_encode($s('steps'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $s('steps')" rows="10" class="ltr" :placeholder="$stepsExample" :help="$stepsHelp" />
            </fieldset>

            <fieldset data-show-for="dns">
                <legend>DNS</legend>
                <div class="grid g-2">
                    <div class="field"><label>{{ __('Record type') }}</label>
                        <select name="settings[record_type]" class="input">@foreach (\App\Services\Domain\DnsLookup::TYPES as $rt)<option @selected($s('record_type', 'A') === $rt)>{{ $rt }}</option>@endforeach</select></div>
                    <x-field name="settings[expected]" dot-name="settings.expected" :label="__('Expected value (optional)')" :value="$s('expected')" class="ltr" :help="__('Comma separated; matched as substring.')" />
                </div>
                <label class="check"><input type="checkbox" name="settings[alert_on_change]" value="1" @checked($checked('alert_on_change', true))> {{ __('Warn when records change') }}</label>
            </fieldset>

            <fieldset data-show-for="smtp imap pop3 ftp redis">
                <legend>{{ __('Encryption & mail options') }}</legend>
                <div class="field"><label>{{ __('Security') }}</label>
                    <select name="settings[security]" class="input">
                        @foreach (['auto' => __('Automatic (by port)'), 'ssl' => __('Implicit SSL/TLS'), 'starttls' => 'STARTTLS', 'none' => __('None')] as $v => $l)<option value="{{ $v }}" @selected($s('security', 'auto') === $v)>{{ $l }}</option>@endforeach
                    </select></div>
                <div data-show-for="smtp">
                    <label class="check"><input type="checkbox" name="settings[require_tls]" value="1" @checked($checked('require_tls', true))> {{ __('Warn if STARTTLS is not offered') }}</label>
                    <label class="check"><input type="checkbox" name="settings[rbl_check]" value="1" @checked($checked('rbl_check', true))> {{ __('Check IP against spam blacklists (RBL, hourly)') }}</label>
                    <label class="check"><input type="checkbox" name="settings[open_relay_test]" value="1" @checked($checked('open_relay_test'))> {{ __('Open relay test (only without credentials)') }}</label>
                </div>
                <div data-show-for="imap"><x-field name="settings[quota_warn]" dot-name="settings.quota_warn" type="number" :label="__('Warn when mailbox quota exceeds (%)')" :value="$s('quota_warn', 90)" min="1" max="100" /></div>
            </fieldset>

            <fieldset data-show-for="mysql postgres"><legend>{{ __('Database') }}</legend>
                <x-field name="settings[database]" dot-name="settings.database" :label="__('Database name (optional)')" :value="$s('database')" class="ltr" />
            </fieldset>
            <fieldset data-show-for="udp"><legend>UDP</legend>
                <x-field name="settings[payload]" dot-name="settings.payload" :label="__('Payload')" :value="$s('payload')" class="ltr" :help="__('Text, or hex:… for binary.')" />
                <label class="check"><input type="checkbox" name="settings[expect_response]" value="1" @checked($checked('expect_response'))> {{ __('Require a response') }}</label>
            </fieldset>
            <fieldset data-show-for="ping"><legend>Ping</legend>
                <x-field name="settings[count]" dot-name="settings.count" type="number" :label="__('Packets per check')" :value="$s('count', 3)" min="1" max="5" />
            </fieldset>

            <fieldset data-show-for="{{ $credTypes }}">
                <legend>{{ __('Credentials (encrypted)') }}</legend>
                <p class="small muted">{{ __('Used for login tests (SMTP AUTH, IMAP/POP3 login, FTP, database) and HTTP basic auth. Leave blank to keep the stored values.') }}</p>
                <div class="grid g-2">
                    <x-field name="credentials[username]" dot-name="credentials.username" :label="__('Username')" :value="$monitor->credential('username')" autocomplete="off" class="ltr" />
                    <x-field name="credentials[password]" dot-name="credentials.password" type="password" :label="__('Password')" autocomplete="new-password" :placeholder="$monitor->credential('password') ? '••••••••' : ''" class="ltr" />
                </div>
                <div data-show-for="{{ $httpTypes }}"><x-field name="credentials[bearer_token]" dot-name="credentials.bearer_token" type="password" :label="__('Bearer token')" autocomplete="off" :placeholder="$monitor->credential('bearer_token') ? '••••••••' : ''" class="ltr" /></div>
                @if ($monitor->credentials)<label class="check"><input type="checkbox" name="clear_credentials" value="1"> {{ __('Remove stored credentials') }}</label>@endif
            </fieldset>
        </div>

        <div>
            <fieldset>
                <legend>{{ __('Schedule') }}</legend>
                <x-field name="interval" type="number" :label="__('Check interval (seconds)')" :value="$monitor->interval" :min="$minInterval" required :help="__('Minimum for your plan: :s s', ['s' => $minInterval])" />
                <div class="grid g-2">
                    <x-field name="timeout" type="number" :label="__('Timeout (s)')" :value="$monitor->timeout" min="1" max="60" required />
                    <x-field name="retries" type="number" :label="__('Retries before down')" :value="$monitor->retries" min="0" max="10" required />
                </div>
                <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $monitor->is_active ?? true))> {{ __('Active') }}</label>
            </fieldset>

            @if ($probes->isNotEmpty())
            <fieldset data-show-for="{{ $activeTypes }}">
                <legend>🌍 {{ __('Check locations') }}</legend>
                <label class="check"><input type="checkbox" name="settings[check_local]" value="1" @checked($checked('check_local', true))> {{ \App\Services\LocationNames::label(config('watchrex.location')) }} <span class="chip">{{ __('this server') }}</span></label>
                @foreach ($probes as $probe)
                    <label class="check"><input type="checkbox" name="probes[]" value="{{ $probe->id }}" @checked(in_array($probe->id, old('probes', $selectedProbes)))> {{ $probe->flag() }} {{ $probe->name }} @unless ($probe->isOnline())<span class="badge down">{{ __('Offline') }}</span>@endunless</label>
                @endforeach
                <div class="field mt-s"><label>{{ __('Mark as down when it fails from') }}</label>
                    <select name="settings[quorum]" class="input">
                        @foreach (['majority' => __('Most locations (recommended)'), 'any' => __('Any location'), 'all' => __('All locations')] as $v => $l)<option value="{{ $v }}" @selected($s('quorum', 'majority') === $v)>{{ $l }}</option>@endforeach
                    </select>
                    <span class="help">{{ __('Failures from only some locations are reported as degraded with a network/geo hint.') }}</span></div>
            </fieldset>
            @endif

            <fieldset data-show-for="{{ $activeTypes }}">
                <legend>{{ __('Thresholds') }}</legend>
                <x-field name="settings[response_warn_ms]" dot-name="settings.response_warn_ms" type="number" :label="__('Degraded above (ms)')" :value="$s('response_warn_ms')" min="0" placeholder="2000" />
                <div data-show-for="http keyword api ssl smtp imap pop3"><x-field name="settings[ssl_warn_days]" dot-name="settings.ssl_warn_days" type="number" :label="__('Warn days before SSL expiry')" :value="$s('ssl_warn_days', 14)" min="1" max="120" /></div>
                <label class="check"><input type="checkbox" name="settings[anomaly]" value="1" @checked($checked('anomaly', true))> {{ __('Anomaly detection (learns normal response time)') }}</label>
            </fieldset>

            <fieldset>
                <legend>{{ __('Alerts') }}</legend>
                @forelse ($channels as $c)
                    <label class="check"><input type="checkbox" name="channels[]" value="{{ $c->id }}" @checked(in_array($c->id, old('channels', $monitor->exists ? $monitor->channels->pluck('id')->all() : [])))> {{ $c->name }} <span class="chip">{{ \App\Models\NotificationChannel::TYPES[$c->type] ?? $c->type }}</span></label>
                @empty
                    <p class="small muted">{{ __('No channels yet.') }} <a href="{{ route('channels.create') }}" class="text-up">{{ __('Add one') }}</a></p>
                @endforelse
                <p class="small faint">{{ __('If none is selected, your default channels are used.') }}</p>
                <label class="check"><input type="checkbox" name="settings[notify_warning]" value="1" @checked($checked('notify_warning', true))> {{ __('Also alert on degraded/warnings') }}</label>
                <x-field name="settings[remind_minutes]" dot-name="settings.remind_minutes" type="number" :label="__('Repeat alert while down every (min)')" :value="$s('remind_minutes')" min="0" placeholder="0 = never" />
            </fieldset>

            <fieldset>
                <legend>{{ __('Organisation') }}</legend>
                <x-team-select :teams="$teams" :value="$monitor->team_id" />
                <div class="field"><label>{{ __('Groups') }}</label>
                    <div style="max-height:180px;overflow:auto;border:1px solid var(--border-2);border-radius:10px;padding:8px 10px">
                        @forelse ($groups as $g)<label class="check" style="margin-bottom:4px"><input type="checkbox" name="groups[]" value="{{ $g->id }}" @checked(in_array($g->id, old('groups', $selectedGroups)))> {{ $g->icon() }} {{ $g->name }}</label>
                        @empty<span class="small muted">{{ __('No groups yet.') }}</span>@endforelse
                    </div>
                </div>
                <x-field name="new_group" :label="__('…or create a new group')" placeholder="example.com" :help="__('A domain name creates a website group.')" />
                <x-field name="tags" :label="__('Tags')" :value="implode(', ', $monitor->tags ?? [])" placeholder="production, cpanel-01" />
                <div class="field"><label>{{ __('Depends on') }}</label>
                    <select name="parent_id" class="input"><option value="">—</option>@foreach ($parents as $p)<option value="{{ $p->id }}" @selected(old('parent_id', $monitor->parent_id) == $p->id)>{{ $p->name }}</option>@endforeach</select>
                    <span class="help">{{ __('Alerts are suppressed while the parent (e.g. the server or database) is down.') }}</span></div>
            </fieldset>

            <div class="row">
                <button class="btn primary">{{ $monitor->exists ? __('Save changes') : __('Create monitor') }}</button>
                <a class="btn ghost" href="{{ $monitor->exists ? route('monitors.show', $monitor) : route('monitors.index') }}">{{ __('Cancel') }}</a>
            </div>
        </div>
    </div>
</form>
@endsection
