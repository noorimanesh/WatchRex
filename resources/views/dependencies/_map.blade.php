@foreach ($map['impacts'] as $impact)
    <div class="alert error small">🔗 <b>{{ $impact['monitor']->name }}</b> {{ __('is down and affects :n dependent service(s):', ['n' => $impact['affected']->count()]) }}
        {{ $impact['affected']->pluck('name')->implode('، ') }}</div>
@endforeach
<div class="card card-pad"><x-dependency-map :map="$map" /></div>
@if ($map['nodes'])<p class="small faint mt-s">{{ __(':n monitor(s) without dependencies are not shown.', ['n' => $map['independent']]) }}</p>@endif
