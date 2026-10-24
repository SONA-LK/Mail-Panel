@extends('layouts.app')
@section('title', 'Forwarders')
@section('content')
<h1>Forwarders</h1>
<form method="get" class="filters">
    <div><label>Search</label><input type="text" name="q" value="{{ request('q') }}"></div>
    <div><label>Domain</label>
        <select name="domain_id"><option value="">All</option>
            @foreach($domains as $d)<option value="{{ $d->id }}" @selected(request('domain_id') == $d->id)>{{ $d->name }}</option>@endforeach</select></div>
    <div><label>Type</label>
        <select name="type"><option value="">All</option>
            @foreach(['address' => 'Address', 'domain' => 'Domain', 'catchall' => 'Catch-all'] as $k => $v)<option value="{{ $k }}" @selected(request('type') === $k)>{{ $v }}</option>@endforeach</select></div>
    <button type="submit">Filter</button>
    <a class="btn sec" href="{{ route('forwarders.export', request()->query()) }}">Export CSV</a>
    @if(auth()->user()->role !== 'read_only')<a class="btn" href="{{ route('forwarders.create') }}">New forwarder</a>@endif
</form>

<form method="get" class="filters card">
    <div><label>Reverse lookup: which forwarders point to this address?</label>
        <input type="email" name="points_to" value="{{ request('points_to') }}" placeholder="anna@example.com"></div>
    <button class="sec" type="submit">Look up</button>
</form>
@if($reverse !== null)
    <div class="card"><strong>Pointing to {{ request('points_to') }}:</strong>
        @forelse($reverse as $f)<div>{{ $f->source }} <span class="muted small">({{ $f->type }})</span></div>@empty<div class="muted">Nothing forwards to this address.</div>@endforelse</div>
@endif

<table>
    <tr><th>Source</th><th>Destination</th><th>Type</th><th>Domain</th><th></th></tr>
    @forelse($forwarders as $f)
        <tr>
            <td>{{ $f->source }}</td><td>{{ $f->destination }}</td>
            <td><span class="badge">{{ $f->type }}</span></td><td>{{ $f->domain->name }}</td>
            <td class="right">
                @if(auth()->user()->canManage($f->domain) && $f->type === 'address')
                    <form method="post" class="inline" action="{{ route('forwarders.destroy', $f) }}">@csrf @method('DELETE')<button class="sm danger" type="submit">Remove</button></form>
                @elseif(auth()->user()->canManage($f->domain) && $f->type === 'domain')
                    <form method="post" class="inline" action="{{ route('forwarders.domain.destroy', $f) }}">@csrf @method('DELETE')<button class="sm danger" type="submit">Remove</button></form>
                @endif
            </td>
        </tr>
    @empty<tr><td colspan="5" class="muted">No forwarders.</td></tr>@endforelse
</table>
<div class="pager">{{ $forwarders->links() }}</div>
@endsection
