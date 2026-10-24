@extends('layouts.app')
@section('title', 'Approvals')
@section('content')
<h1>Approvals</h1>
<p class="muted">Removing a domain or a super admin needs a second super admin to agree. You cannot approve your own request. Requests expire after {{ config('panel.security.four_eyes_hours') }} hours.</p>

<h2>Waiting</h2>
<table>
    <tr><th>Change</th><th>What</th><th>Asked by</th><th>When</th><th>Expires</th><th></th></tr>
    @forelse($pending as $a)
        <tr>
            <td>{{ $a->label() }}</td>
            <td><strong>{{ $a->target }}</strong></td>
            <td class="small">{{ $a->requested_by_email }}</td>
            <td class="small muted">{{ $a->created_at->diffForHumans() }}</td>
            <td class="small muted">{{ $a->expires_at->diffForHumans() }}</td>
            <td class="right">
                @if($a->requested_by === $me)
                    <span class="muted small">your request</span>
                @else
                    <form method="post" class="inline" action="{{ route('admin.approvals.approve', $a) }}">@csrf<button class="sm danger" type="submit">Approve and do it</button></form>
                @endif
                <form method="post" class="inline" action="{{ route('admin.approvals.reject', $a) }}">@csrf<button class="sm sec" type="submit">Reject</button></form>
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">Nothing is waiting.</td></tr>
    @endforelse
</table>

<h2>Recent decisions</h2>
<table>
    <tr><th>Change</th><th>What</th><th>Asked by</th><th>Result</th><th>When</th></tr>
    @forelse($recent as $a)
        <tr>
            <td>{{ $a->label() }}</td><td>{{ $a->target }}</td><td class="small">{{ $a->requested_by_email }}</td>
            <td><span class="badge {{ $a->status === 'approved' ? 'ok' : 'warn' }}">{{ $a->status }}</span></td>
            <td class="small muted">{{ ($a->decided_at ?? $a->expires_at)->diffForHumans() }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No decisions yet.</td></tr>
    @endforelse
</table>
@endsection
