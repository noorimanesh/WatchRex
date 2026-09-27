@extends('layouts.app')
@section('title', $team->name)
@section('content')
<div class="page-head">
    <div><h1>👥 {{ $team->name }}</h1><div class="sub">{{ __('Resources assigned to this team are visible to all members.') }}</div></div>
    <div class="actions">
        @if ($role && $role !== 'owner')<form method="POST" action="{{ route('teams.members.destroy', [$team, auth()->user()]) }}" data-confirm="{{ __('Leave this team?') }}">@csrf @method('DELETE')<button class="btn">{{ __('Leave team') }}</button></form>@endif
        @if (auth()->user()->isAdmin() || $team->owner_id === auth()->id())<form method="POST" action="{{ route('teams.destroy', $team) }}" data-confirm="{{ __('Delete this team?') }}">@csrf @method('DELETE')<button class="btn danger">{{ __('Delete team') }}</button></form>@endif
    </div>
</div>
<div class="grid g-main">
    <div class="card"><div class="table-wrap"><table class="table">
        <thead><tr><th>{{ __('Member') }}</th><th>{{ __('Role') }}</th><th></th></tr></thead>
        <tbody>
        @foreach ($team->members as $m)
            <tr><td><b>{{ $m->name }}</b><div class="small muted ltr" style="text-align:start">{{ $m->email }}</div></td>
                <td>
                    @if ($canManage && $m->id !== $team->owner_id)
                        <form method="POST" action="{{ route('teams.members.update', [$team, $m]) }}" class="row">@csrf @method('PUT')
                            <select name="role" class="input" style="width:auto;padding:5px 8px" data-autosubmit>@foreach (['admin', 'developer', 'viewer'] as $r)<option value="{{ $r }}" @selected($m->pivot->role === $r)>{{ __(\App\Models\Team::ROLES[$r][0]) }}</option>@endforeach</select>
                        </form>
                    @else
                        <span class="chip">{{ __(\App\Models\Team::ROLES[$m->pivot->role][0]) }}</span>
                    @endif
                </td>
                <td>@if ($canManage && $m->id !== $team->owner_id)<form method="POST" action="{{ route('teams.members.destroy', [$team, $m]) }}" data-confirm="{{ __('Remove this member?') }}">@csrf @method('DELETE')<button class="btn sm danger">{{ __('Remove') }}</button></form>@endif</td></tr>
        @endforeach
        </tbody>
    </table></div></div>
    @if ($canManage)
    <div class="stack">
        <form method="POST" action="{{ route('teams.members.store', $team) }}" class="card card-pad">
            @csrf
            <h2 class="mb">{{ __('Add member') }}</h2>
            <x-field name="email" type="email" :label="__('E-mail of an existing user')" required class="ltr" />
            <div class="field"><label>{{ __('Role') }}</label><select name="role" class="input">@foreach (['developer', 'viewer', 'admin'] as $r)<option value="{{ $r }}">{{ __(\App\Models\Team::ROLES[$r][0]) }}</option>@endforeach</select></div>
            <button class="btn primary">{{ __('Add') }}</button>
        </form>
        <form method="POST" action="{{ route('teams.update', $team) }}" class="card card-pad">
            @csrf @method('PUT')
            <x-field name="name" :label="__('Team name')" :value="$team->name" required />
            <button class="btn">{{ __('Save') }}</button>
        </form>
    </div>
    @endif
</div>
@endsection
