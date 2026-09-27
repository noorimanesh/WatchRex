@props(['teams', 'value' => null])
@if ($teams->isNotEmpty())
<div class="field">
    <label for="f-team_id">{{ __('Share with team') }}</label>
    <select id="f-team_id" name="team_id" class="input">
        <option value="">{{ __('Only me') }}</option>
        @foreach ($teams as $t)<option value="{{ $t->id }}" @selected((int) old('team_id', $value) === $t->id)>👥 {{ $t->name }}</option>@endforeach
    </select>
    @error('team_id')<span class="error">{{ $message }}</span>@enderror
</div>
@endif
