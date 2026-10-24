@extends('layouts.app')
@section('title', 'Catch-all')
@section('content')
<h1>Catch-all</h1>
<p class="muted">What happens to mail sent to an address that does not exist.</p>

@foreach($domains as $d)
    @php($c = $current->get($d->id))
    <div class="card">
        <strong>{{ $d->name }}</strong>
        <p class="small">Current in cPanel: <code>{{ $c?->destination ?? 'unknown (run a sync)' }}</code> · Policy for this domain: <span class="badge">{{ $d->catchall_policy }}</span>
            @if($d->catchall_policy === 'forward') → {{ $d->catchall_target }}@endif</p>
        @if(auth()->user()->canManage($d))
        <form method="post" action="{{ route('catchall.update', $d) }}" class="filters">
            @csrf @method('PUT')
            <div><label>Policy</label>
                <select name="policy">@foreach(\App\Models\Domain::CATCHALL as $k => $v)<option value="{{ $k }}" @selected($d->catchall_policy === $k)>{{ $v }}</option>@endforeach</select></div>
            <div><label>Forward to</label><input type="email" name="target" value="{{ $d->catchall_target }}"></div>
            <button type="submit">Apply</button>
        </form>
        @endif
    </div>
@endforeach
@endsection
