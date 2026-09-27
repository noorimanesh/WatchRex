@extends('layouts.app')
@section('title', __('Users'))
@section('content')
<div class="page-head"><div><h1>{{ __('Users') }}</h1><div class="sub">{{ __('Every user sees only their own monitors, servers, domains and status pages.') }}</div></div>
    <a class="btn primary" href="{{ route('admin.users.create') }}"><x-icon name="plus"/>{{ __('New user') }}</a></div>
<form class="filters"><input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Name or e-mail…') }}"><button class="btn">{{ __('Search') }}</button></form>
<div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>{{ __('User') }}</th><th>{{ __('Role') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Monitors') }}</th><th>{{ __('Servers') }}</th><th>{{ __('Domains') }}</th><th>2FA</th><th>{{ __('Last login') }}</th><th></th></tr></thead>
    <tbody>
    @foreach ($users as $u)
        <tr class="{{ $u->is_active ? '' : 'faint' }}">
            <td><b>{{ $u->name }}</b><div class="small muted ltr" style="text-align:start">{{ $u->email }}</div></td>
            <td><span class="badge {{ $u->isAdmin() ? 'info' : '' }}">{{ $u->role->label() }}</span></td>
            <td>{{ config('watchrex.plans.'.$u->plan.'.label', $u->plan) }}</td>
            <td><a href="{{ route('monitors.index', ['owner' => $u->id]) }}">{{ $u->monitors_count }}</a> / {{ $u->limit('max_monitors') ?? '∞' }}</td>
            <td>{{ $u->servers_count }}</td><td>{{ $u->domains_count }}</td>
            <td>{!! $u->hasTwoFactor() ? '<span class="text-up">✓</span>' : '<span class="faint">—</span>' !!}</td>
            <td class="small muted">{{ $u->last_login_at?->diffForHumans() ?? '—' }}<div class="ltr">{{ $u->last_login_ip }}</div></td>
            <td class="nowrap"><a class="btn sm" href="{{ route('admin.users.edit', $u) }}">{{ __('Edit') }}</a>
                @unless ($u->is(auth()->user()))<form class="inline" method="POST" action="{{ route('admin.users.destroy', $u) }}" data-confirm="{{ __('Delete this user and ALL their data?') }}">@csrf @method('DELETE')<button class="btn sm danger">{{ __('Delete') }}</button></form>@endunless</td>
        </tr>
    @endforeach
    </tbody></table></div></div>
{{ $users->links('partials.pagination') }}
@endsection
