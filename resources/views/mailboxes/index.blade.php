@extends('layouts.app')
@section('title', 'Mailboxes')
@section('content')
<h1>Mailboxes</h1>
<form method="get" class="filters">
    <div><label>Search</label><input type="text" name="q" value="{{ request('q') }}" placeholder="address"></div>
    <div><label>Domain</label>
        <select name="domain_id"><option value="">All</option>
            @foreach($domains as $d)<option value="{{ $d->id }}" @selected(request('domain_id') == $d->id)>{{ $d->name }}</option>@endforeach
        </select></div>
    <div><label>Status</label>
        <select name="status"><option value="">Any</option>
            <option value="active" @selected(request('status') === 'active')>Active</option>
            <option value="suspended" @selected(request('status') === 'suspended')>Suspended</option></select></div>
    <div><label>Quota</label>
        <select name="quota"><option value="">Any</option><option value="near" @selected(request('quota') === 'near')>Near limit ({{ $warnPct }}%+)</option></select></div>
    <div><label>Forwarders</label>
        <select name="forwarders"><option value="">Any</option><option value="with" @selected(request('forwarders') === 'with')>Has forwarders</option></select></div>
    <button type="submit">Filter</button>
    <a class="btn sec" href="{{ route('mailboxes.export', request()->query()) }}">Export CSV</a>
    @if(auth()->user()->role !== 'read_only')<a class="btn" href="{{ route('mailboxes.create') }}">New mailbox</a>@endif
</form>

<table>
    <tr><th>Address</th><th>Quota</th><th>Status</th><th>Forwarders</th><th>Synced</th></tr>
    @forelse($mailboxes as $m)
        @php($pct = $m->usagePercent())
        <tr>
            <td><a href="{{ route('mailboxes.show', $m) }}">{{ $m->address }}</a></td>
            <td>
                @if($pct === null)<span class="muted">{{ $m->used_mb }} MB / unlimited</span>
                @else<span class="bar {{ $pct >= 95 ? 'bad' : ($pct >= $warnPct ? 'warn' : '') }}"><i style="width:{{ min(100, $pct) }}%"></i></span>
                    <span class="small">{{ $m->used_mb }} / {{ $m->quota_mb }} MB ({{ $pct }}%)</span>@endif
            </td>
            <td><span class="badge {{ $m->isSuspended() ? 'warn' : 'ok' }}">{{ $m->statusLabel() }}</span></td>
            <td>{{ $m->forwarder_count }}</td>
            <td class="muted small">{{ $m->last_synced_at?->diffForHumans() ?? 'never' }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No mailboxes match. If this is a new install, run a sync from Settings.</td></tr>
    @endforelse
</table>
<div class="pager">{{ $mailboxes->links() }}</div>
@endsection
