@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')
<h1>Dashboard</h1>

@if($failedJobs > 0)<div class="flash err">{{ $failedJobs }} background job(s) failed. Check the bulk import reports and the failed_jobs table.</div>@endif
@foreach($syncErrors as $run)
    <div class="flash warn">Sync problem on {{ $run->domain?->name }} ({{ $run->started_at->diffForHumans() }}): {{ \Illuminate\Support\Str::limit($run->errors, 200) }}</div>
@endforeach
@foreach($eligible as $p)
    <div class="flash warn">{{ $p->address }} has been suspended long enough to be deleted (since {{ $p->delete_after->format('Y-m-d') }}). <a href="{{ route('mailboxes.index', ['q' => $p->address]) }}">Review</a></div>
@endforeach

@if($security)
<div class="card">
    <h2>Security</h2>
    <table>
        @foreach($security as $s)
            <tr><td>{{ $s['label'] }}</td><td><span class="badge {{ $s['level'] === 'ok' ? 'ok' : ($s['level'] === 'warn' ? 'warn' : 'bad') }}">{{ $s['value'] }}</span></td><td class="small muted">{{ $s['hint'] }}</td></tr>
        @endforeach
    </table>
</div>
@endif

<div class="grid">
@forelse($rows as $r)
    @php($d = $r['domain'])
    <div class="card">
        <strong><a href="{{ route('mailboxes.index', ['domain_id' => $d->id]) }}">{{ $d->name }}</a></strong>
        <div class="muted small">{{ $d->purpose }}</div>
        <p><span class="stat">{{ $r['mailboxes'] }}</span> mailboxes · <span class="stat">{{ $r['forwarders'] }}</span> forwarders</p>
        <div>
            Near quota: <span class="badge {{ $r['near_quota'] ? 'warn' : 'ok' }}">{{ $r['near_quota'] }}</span>
            Health:
            @if($r['health'])<span class="badge {{ $r['health']['worst'] }}">{{ $r['health']['worst'] }}</span>@else<span class="badge">not checked</span>@endif
        </div>
        <div class="muted small">Last sync:
            @if($r['last_sync']){{ $r['last_sync']->started_at->diffForHumans() }}@if($r['last_sync']->errors) <span class="badge bad">errors</span>@endif @else never @endif
        </div>
    </div>
@empty
    <div class="card">No domains yet. @if(auth()->user()->isSuperAdmin())<a href="{{ route('admin.domains.create') }}">Add one</a>.@else Ask a super admin to give you access.@endif</div>
@endforelse
</div>

<h2>Mailboxes at {{ $warnPct }}% of quota or more</h2>
<table>
    <tr><th>Address</th><th>Used</th><th>Quota</th><th></th></tr>
    @forelse($nearQuotaBoxes as $m)
        <tr><td><a href="{{ route('mailboxes.show', $m) }}">{{ $m->address }}</a></td><td>{{ $m->used_mb }} MB</td><td>{{ $m->quota_mb }} MB</td><td>{{ $m->usagePercent() }}%</td></tr>
    @empty
        <tr><td colspan="4" class="muted">None.</td></tr>
    @endforelse
</table>
<p class="muted small">{{ $queued }} job(s) waiting in the queue.</p>
@endsection
