@extends('layouts.app')
@section('title', __('Users'))
@section('content')
<div class="page-head"><div><h1>{{ __('Users') }}</h1><div class="sub">{{ __('Every user sees only their own monitors, servers, domains and status pages.') }}</div></div>
    <a class="btn primary" href="{{ route('admin.users.create') }}"><x-icon name="plus"/>{{ __('New user') }}</a></div>
<form class="filters">
    <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Name or e-mail…') }}">
    <select class="input" name="role" data-autosubmit><option value="">{{ __('All roles') }}</option>@foreach ($roles as $r)<option value="{{ $r->value }}" @selected(request('role') === $r->value)>{{ $r->label() }}</option>@endforeach</select>
    <select class="input" name="plan" data-autosubmit><option value="">{{ __('All plans') }}</option>@foreach (config('watchrex.plans') as $k => $p)<option value="{{ $k }}" @selected(request('plan') === $k)>{{ __($p['label']) }}</option>@endforeach</select>
    <select class="input" name="state" data-autosubmit>@foreach (['' => __('Any status'), 'active' => __('Active'), 'inactive' => __('Disabled'), 'expiring' => __('Plan expiring (7 days)'), 'no2fa' => __('Without 2FA')] as $k => $v)<option value="{{ $k }}" @selected(request('state', '') === $k)>{{ $v }}</option>@endforeach</select>
    <select class="input" name="sort" data-autosubmit>@foreach (['' => __('Sort: name'), 'newest' => __('Sort: newest'), 'login' => __('Sort: last login'), 'monitors' => __('Sort: monitors')] as $k => $v)<option value="{{ $k }}" @selected(request('sort', '') === $k)>{{ $v }}</option>@endforeach</select>
    <button class="btn">{{ __('Search') }}</button>
    <span class="muted small">{{ trans_choice(':count user|:count users', $users->total()) }}</span>
</form>
<div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>{{ __('User') }}</th><th>{{ __('Role') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Monitors') }}</th><th>{{ __('Servers') }}</th><th>{{ __('Domains') }}</th><th>{{ __('Status pages') }}</th><th>2FA</th><th>{{ __('Last login') }}</th><th></th></tr></thead>
    <tbody>
    @foreach ($users as $u)
        <tr class="{{ $u->is_active ? '' : 'faint' }}">
            <td><b>{{ $u->name }}</b><div class="small muted ltr" style="text-align:start">{{ $u->email }}</div></td>
            <td><span class="badge {{ $u->isAdmin() ? 'info' : '' }}">{{ $u->role->label() }}</span></td>
            <td>{{ __(config('watchrex.plans.'.$u->plan.'.label', $u->plan)) }}@if ($u->plan_expires_at)<div class="small {{ $u->plan_expires_at->isPast() ? 'text-down' : ($u->plan_expires_at->lt(now()->addDays(7)) ? 'text-warn' : 'muted') }}">{{ Fmt::date($u->plan_expires_at, false) }}</div>@endif</td>
            <td><a href="{{ route('monitors.index', ['owner' => $u->id]) }}">{{ $u->monitors_count }}</a> / {{ $u->limit('max_monitors') ?? '∞' }}@if ($u->down_count)<div class="small text-down">{{ __(':n down', ['n' => $u->down_count]) }}</div>@endif</td>
            <td>{{ $u->servers_count }}</td><td>{{ $u->domains_count }}</td><td>{{ $u->status_pages_count }}</td>
            <td>{!! $u->hasTwoFactor() ? '<span class="text-up">✓</span>' : '<span class="faint">—</span>' !!}</td>
            <td class="small muted">{{ $u->last_login_at?->diffForHumans() ?? '—' }}<div class="ltr">{{ $u->last_login_ip }}</div></td>
            <td class="nowrap"><a class="btn sm" href="{{ route('admin.users.edit', $u) }}">{{ __('Edit') }}</a>
                @unless ($u->is(auth()->user()))
                    <form class="inline" method="POST" action="{{ route('admin.users.toggle', $u) }}">@csrf<button class="btn sm">{{ $u->is_active ? __('Disable') : __('Enable') }}</button></form>
                    @if (! $u->isAdmin() && $u->is_active)<form class="inline" method="POST" action="{{ route('admin.users.impersonate', $u) }}">@csrf<button class="btn sm" data-confirm="{{ __('Sign in as this user? The action is audited.') }}" title="{{ __('Sign in as') }}">🕵</button></form>@endif
                    <form class="inline" method="POST" action="{{ route('admin.users.destroy', $u) }}" data-confirm="{{ __('Delete this user and ALL their data?') }}">@csrf @method('DELETE')<button class="btn sm danger">{{ __('Delete') }}</button></form>@endunless</td>
        </tr>
    @endforeach
    </tbody></table></div></div>
{{ $users->links('partials.pagination') }}
@endsection
