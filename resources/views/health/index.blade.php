@extends('layouts.app')
@section('title', 'Domain health')
@section('content')
<h1>Domain health</h1>
<p class="muted">SPF, DKIM, DMARC and MX read from public DNS. Fix red items in cPanel, Email Deliverability.</p>

@foreach($items as $i)
    @php($d = $i['domain'])
    @php($r = $i['result'])
    <div class="card">
        <strong>{{ $d->name }}</strong>
        @if($r)<span class="badge {{ $r['worst'] }}">{{ $r['worst'] }}</span> <span class="muted small">checked {{ $r['checked_at'] }}</span>@endif
        <form method="post" class="inline" action="{{ route('health.run', $d) }}">@csrf <button class="sm sec" type="submit">Check now</button></form>
        @if($r)
            <table>
                <tr><th>Check</th><th>Status</th><th>Detail</th><th>What to do</th></tr>
                @foreach($r['checks'] as $c)
                    <tr><td>{{ $c['name'] }}</td><td><span class="badge {{ $c['status'] }}">{{ $c['status'] }}</span></td>
                        <td class="small" style="word-break:break-all">{{ $c['detail'] }}</td><td class="small">{{ $c['hint'] }}</td></tr>
                @endforeach
            </table>
        @else<p class="muted">Not checked yet.</p>@endif
    </div>
@endforeach
@endsection
