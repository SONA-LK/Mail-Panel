@extends('layouts.app')
@section('title', $mailbox->address)
@section('content')
<h1>{{ $mailbox->address }} <span class="badge {{ $mailbox->isSuspended() ? 'warn' : 'ok' }}">{{ $mailbox->statusLabel() }}</span></h1>
<p>
    <a href="{{ $webmail }}" target="_blank" rel="noopener noreferrer" class="btn sec sm">Open webmail</a>
    <a href="{{ route('mailboxes.settings', $mailbox) }}" class="btn sec sm">Client settings</a>
    <a href="{{ route('audit.index', ['q' => $mailbox->address]) }}" class="btn sec sm">Activity</a>
</p>

<div class="grid">
    <div class="card">
        <strong>Quota</strong>
        <p>{{ $mailbox->used_mb }} MB used of {{ $mailbox->quota_mb ? $mailbox->quota_mb.' MB' : 'unlimited' }}
            @if($mailbox->usagePercent() !== null) ({{ $mailbox->usagePercent() }}%)@endif</p>
        @if($canManage)
        <form method="post" action="{{ route('mailboxes.quota', $mailbox) }}">@csrf
            <input type="number" name="quota_mb" min="1" max="{{ config('cpanel.max_quota_mb') }}" value="{{ $mailbox->quota_mb }}" required class="w140">
            <button class="sm" type="submit">Change quota</button>
        </form>
        @endif
    </div>

    @if($canManage)
    <div class="card">
        <strong>Password</strong>
        <form method="post" action="{{ route('mailboxes.password', $mailbox) }}">@csrf
            <input type="text" name="password" placeholder="leave empty to generate" autocomplete="off" minlength="8">
            <p><button class="sm" type="submit">Reset password</button></p>
        </form>
    </div>

    <div class="card">
        <strong>Suspend or delete</strong>
        @if($pending)<p class="small">Suspended {{ $pending->suspended_at->diffForHumans() }}. Deletion allowed from <strong>{{ $pending->delete_after->format('Y-m-d') }}</strong>.</p>@endif
        @if($mailbox->isSuspended())
            <form method="post" action="{{ route('mailboxes.unsuspend', $mailbox) }}" class="inline">@csrf<button class="sm sec" type="submit">Re-enable</button></form>
        @else
            <form method="post" action="{{ route('mailboxes.suspend', $mailbox) }}" class="inline">@csrf<button class="sm sec" type="submit">Suspend (keeps mail)</button></form>
        @endif
        <a class="btn danger sm" href="{{ route('mailboxes.confirm-delete', $mailbox) }}">Delete…</a>
        @if($deleteBlocked)<p class="small muted">{{ $deleteBlocked }}</p>@endif
    </div>
    @endif
</div>

<h2>Forwarders from this address</h2>
<table>
    <tr><th>Destination</th><th></th></tr>
    @forelse($forwardersFrom as $f)
        <tr><td>{{ $f->destination }}</td><td class="right">
            @if($canManage && $f->type === 'address')<form method="post" class="inline" action="{{ route('forwarders.destroy', $f) }}">@csrf @method('DELETE')<button class="sm danger" type="submit">Remove</button></form>@endif</td></tr>
    @empty<tr><td colspan="2" class="muted">None.</td></tr>@endforelse
</table>
@if($canManage)<p><a href="{{ route('forwarders.create') }}">Add a forwarder</a></p>@endif

<h2>Forwarders that point here</h2>
<table>
    <tr><th>Source</th><th>Type</th></tr>
    @forelse($forwardersTo as $f)<tr><td>{{ $f->source }}</td><td>{{ $f->type }}</td></tr>@empty<tr><td colspan="2" class="muted">None.</td></tr>@endforelse
</table>

<h2>Autoresponder</h2>
<div class="card">
@if($autoresponder)
    <p>Active: <strong>{{ $autoresponder->subject }}</strong>
        @if($autoresponder->start_at) from {{ $autoresponder->start_at->format('Y-m-d') }}@endif
        @if($autoresponder->stop_at) until {{ $autoresponder->stop_at->format('Y-m-d') }}@endif</p>
    @if($canManage)<form method="post" action="{{ route('mailboxes.autoresponder.destroy', $mailbox) }}">@csrf @method('DELETE')<button class="sm danger" type="submit">Remove autoresponder</button></form>@endif
@elseif($canManage)
    <form method="post" action="{{ route('mailboxes.autoresponder', $mailbox) }}">@csrf
        <label>Subject</label><input type="text" name="subject" required maxlength="200">
        <label>Message</label><textarea name="body" required maxlength="5000"></textarea>
        <label>Start date</label><input type="date" name="start">
        <label>Stop date</label><input type="date" name="stop">
        <p><button class="sm" type="submit">Save autoresponder</button></p>
    </form>
@else<span class="muted">None.</span>@endif
</div>

<h2>Filters</h2>
<table>
    <tr><th>Name</th><th>Rule</th><th></th></tr>
    @forelse($filters as $f)
        <tr><td>{{ $f->name }}</td><td class="small">{{ $f->rule_summary }}</td><td class="right">
            @if($canManage)<form method="post" class="inline" action="{{ route('mailboxes.filters.destroy', [$mailbox, $f]) }}">@csrf @method('DELETE')<button class="sm danger" type="submit">Remove</button></form>@endif</td></tr>
    @empty<tr><td colspan="3" class="muted">None.</td></tr>@endforelse
</table>
@if($canManage)
<div class="card">
    <strong>Add a filter</strong>
    <form method="post" action="{{ route('mailboxes.filters.store', $mailbox) }}">@csrf
        <label>Name</label><input type="text" name="name" required maxlength="80">
        <label>If</label>
        <select name="field" class="auto"><option value="from">From</option><option value="to">To</option><option value="subject">Subject</option></select>
        <select name="match" class="auto"><option value="contains">contains</option><option value="equals">equals</option><option value="begins">begins with</option><option value="ends">ends with</option></select>
        <input type="text" name="value" required maxlength="200">
        <label>Then</label>
        <select name="action" class="auto"><option value="discard">Discard</option><option value="redirect">Redirect to address</option><option value="move">Move to folder</option></select>
        <input type="text" name="destination" placeholder="address or folder name (not needed for discard)" maxlength="200">
        <p><button class="sm" type="submit">Save filter</button></p>
    </form>
</div>
@endif
@endsection
