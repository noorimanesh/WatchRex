@extends('layouts.app')
@section('title', __('Audit log'))
@section('content')
<div class="page-head"><div><h1>{{ __('Audit log') }}</h1><div class="sub">{{ __('Sign-ins, failures and every configuration change.') }}</div></div></div>
<form class="filters"><input class="input" name="action" value="{{ request('action') }}" placeholder="auth. / monitor. / admin."><button class="btn">{{ __('Filter') }}</button></form>
<div class="card"><div class="table-wrap"><table class="table">
    <thead><tr><th>{{ __('Time') }}</th><th>{{ __('User') }}</th><th>{{ __('Action') }}</th><th>{{ __('Subject') }}</th><th>IP</th></tr></thead>
    <tbody>
    @foreach ($logs as $l)
        <tr><td class="small nowrap">{{ Fmt::date($l->created_at) }}</td><td class="small">{{ $l->user->name ?? '—' }}</td>
            <td><span class="badge {{ str_contains($l->action, 'failed') ? 'down' : '' }}">{{ $l->action }}</span></td>
            <td class="small muted">{{ $l->subject_type }} {{ $l->subject_id ? '#'.$l->subject_id : '' }} {{ $l->meta ? json_encode($l->meta, JSON_UNESCAPED_UNICODE) : '' }}</td>
            <td class="small ltr">{{ $l->ip }}</td></tr>
    @endforeach
    </tbody></table></div></div>
{{ $logs->links('partials.pagination') }}
@endsection
