@extends('layouts.app')
@section('title', __('Maintenance window'))
@section('content')
@php $tz = auth()->user()->timezone ?: config('app.timezone'); @endphp
<div class="page-head"><div><h1>{{ __('Maintenance window') }}</h1><div class="sub">{{ __('Times are in your timezone (:tz).', ['tz' => $tz]) }}</div></div></div>
<form method="POST" action="{{ $window->exists ? route('maintenance.update', $window) : route('maintenance.store') }}" class="grid g-main">
    @csrf @if ($window->exists) @method('PUT') @endif
    <div class="card card-pad">
        <x-field name="title" :label="__('Title')" :value="$window->title" required />
        <x-field name="description" type="textarea" :label="__('Description (shown on status pages)')" :value="$window->description" rows="3" style="font-family:var(--font)" />
        <div class="grid g-2">
            <x-field name="starts_at" type="datetime-local" :label="__('Starts')" :value="$window->starts_at?->setTimezone($tz)->format('Y-m-d\TH:i')" required />
            <x-field name="ends_at" type="datetime-local" :label="__('Ends')" :value="$window->ends_at?->setTimezone($tz)->format('Y-m-d\TH:i')" required />
        </div>
        <button class="btn primary">{{ __('Save') }}</button>
    </div>
    <div class="card card-pad" style="max-height:520px;overflow:auto">
        <div class="label mb">{{ __('Affected monitors') }}</div>
        @error('monitors')<div class="error mb">{{ $message }}</div>@enderror
        @foreach ($monitors as $m)
            <label class="check"><input type="checkbox" name="monitors[]" value="{{ $m->id }}" @checked(in_array($m->id, old('monitors', $selected)))> {{ $m->name }}</label>
        @endforeach
    </div>
</form>
@endsection
