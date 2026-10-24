@extends('layouts.app')
@section('title', 'Appearance')
@section('content')
<h1>Appearance</h1>
<p class="muted">Only you see these choices. They follow you to every device you sign in on. The company colours and logo are set by a super admin.</p>
<form method="post" action="{{ route('appearance.update') }}" class="card">@csrf @method('PUT')
    <h2>Mode</h2>
    <div class="opts">
        @foreach(['system' => 'Match my device', 'light' => 'Light', 'dark' => 'Dark'] as $value => $label)
            <label class="opt"><input type="radio" name="mode" value="{{ $value }}" @checked($current['mode'] === $value)> {{ $label }}</label>
        @endforeach
    </div>

    <h2>Spacing</h2>
    <div class="opts">
        @foreach(['comfortable' => 'Comfortable', 'compact' => 'Compact (more rows on screen)'] as $value => $label)
            <label class="opt"><input type="radio" name="density" value="{{ $value }}" @checked($current['density'] === $value)> {{ $label }}</label>
        @endforeach
    </div>

    @if($brand['allow_palettes'])
        <h2>Accent colour</h2>
        <div class="opts">
            <label class="opt"><input type="radio" name="palette" value="" @checked($current['palette'] === '')> Company colour <input type="color" value="{{ $brand['primary'] }}" disabled aria-hidden="true"></label>
            @foreach($palettes as $key => $palette)
                <label class="opt"><input type="radio" name="palette" value="{{ $key }}" @checked($current['palette'] === $key)> {{ $palette['label'] }} <input type="color" value="{{ $palette['primary'] }}" disabled aria-hidden="true"></label>
            @endforeach
        </div>
    @endif

    <button type="submit">Save</button>
</form>
@endsection
