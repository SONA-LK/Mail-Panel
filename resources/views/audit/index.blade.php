@extends('layouts.app')
@section('title', 'Audit log')
@section('content')
<h1>Audit log</h1>
<form method="get" class="filters">
    @if($users->isNotEmpty())
        <div><label>User</label><select name="user_id"><option value="">Anyone</option>
            @foreach($users as $u)<option value="{{ $u->id }}" @selected(request('user_id') == $u->id)>{{ $u->email }}</option>@endforeach</select></div>
    @endif
    <div><label>Domain</label><select name="domain_id"><option value="">All</option>
        @foreach($domains as $d)<option value="{{ $d->id }}" @selected(request('domain_id') == $d->id)>{{ $d->name }}</option>@endforeach</select></div>
    <div><label>Action starts with</label><input type="text" name="action" value="{{ request('action') }}" placeholder="mailbox."></div>
    <div><label>Target contains</label><input type="text" name="q" value="{{ request('q') }}"></div>
    <div><label>Result</label><select name="result"><option value="">Any</option>
        @foreach(['success', 'failure', 'denied'] as $r)<option @selected(request('result') === $r)>{{ $r }}</option>@endforeach</select></div>
    <div><label>From</label><input type="date" name="from" value="{{ request('from') }}"></div>
    <div><label>To</label><input type="date" name="to" value="{{ request('to') }}"></div>
    <button type="submit">Filter</button>
    <a class="btn sec" href="{{ route('audit.export', request()->query()) }}">Export CSV</a>
</form>

<table>
    <tr><th>Time</th><th>User</th><th>Action</th><th>Target</th><th>Domain</th><th>Result</th><th>IP</th></tr>
    @forelse($entries as $e)
        <tr title="{{ json_encode($e->details) }}">
            <td class="small">{{ $e->created_at->format('Y-m-d H:i:s') }}</td>
            <td>{{ $e->user_email ?? $e->user?->email ?? 'system' }}</td>
            <td>{{ $e->action }}</td><td class="small">{{ $e->target }}</td><td>{{ $e->domain?->name }}</td>
            <td><span class="badge {{ $e->result === 'success' ? 'ok' : 'bad' }}">{{ $e->result }}</span></td>
            <td class="small muted">{{ $e->ip }}</td>
        </tr>
    @empty<tr><td colspan="7" class="muted">No entries.</td></tr>@endforelse
</table>
<div class="pager">{{ $entries->links() }}</div>
@endsection
