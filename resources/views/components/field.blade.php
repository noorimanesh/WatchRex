@props(['name', 'label' => null, 'help' => null, 'type' => 'text', 'value' => null, 'dotName' => null])
@php $key = $dotName ?? str_replace(['[', ']'], ['.', ''], $name); @endphp
<div class="field">
    @if ($label)<label for="f-{{ $key }}">{{ $label }}</label>@endif
    @if ($type === 'textarea')
        <textarea id="f-{{ $key }}" name="{{ $name }}" {{ $attributes->class(['input', 'is-invalid' => $errors->has($key)]) }}>{{ old($key, $value) }}</textarea>
    @else
        <input id="f-{{ $key }}" type="{{ $type }}" name="{{ $name }}" value="{{ $type === 'password' ? '' : old($key, $value) }}" {{ $attributes->class(['input', 'is-invalid' => $errors->has($key)]) }}>
    @endif
    @if ($help)<span class="help">{{ $help }}</span>@endif
    @error($key)<span class="error">{{ $message }}</span>@enderror
</div>
