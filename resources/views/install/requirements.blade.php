@extends('install.layout')
@section('title', 'Server check')
@section('content')
<div class="card">
    <h2>Server check</h2>
    <table>
        @foreach($req['checks'] as $c)
            <tr>
                <td>{{ $c['label'] }}</td>
                <td><span class="badge {{ $c['ok'] ? 'ok' : ($c['required'] ? 'bad' : 'warn') }}">{{ $c['ok'] ? 'OK' : ($c['required'] ? 'Fix' : 'Advice') }}</span></td>
                <td class="muted small">{{ $c['hint'] }}</td>
            </tr>
        @endforeach
    </table>
    @if($req['ok'])
        <p><a class="btn" href="{{ route('install.database') }}">Continue</a></p>
    @else
        <p class="flash err">Fix the items marked "Fix", then <a href="{{ route('install.start') }}">check again</a>.</p>
    @endif
</div>
@endsection
