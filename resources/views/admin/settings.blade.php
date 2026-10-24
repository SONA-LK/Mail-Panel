@extends('layouts.app')
@section('title', 'Settings')
@section('content')
<h1>Settings</h1>

<div class="card">
    <strong>cPanel connection</strong>
    @if($configured)
        <p>Host <code>{{ $host }}</code> · cPanel user <code>{{ $cpanelUser }}</code> · certificate check {{ $verifySsl ? 'on' : 'OFF' }}</p>
        @unless($verifySsl)<div class="flash warn">Certificate verification is off. Turn it on (CPANEL_VERIFY_SSL=true) outside of testing.</div>@endunless
        <p class="muted small">The API token lives in the server's .env file only and is never shown here. To rotate it, create a new token in cPanel, edit .env, run <code>php artisan config:cache</code>, then revoke the old token.</p>
        <form method="post" action="{{ route('admin.settings.test') }}">@csrf<button type="submit">Test connection</button></form>
    @else
        <div class="flash err">cPanel is not configured. Set CPANEL_HOST, CPANEL_USER and CPANEL_TOKEN in .env.</div>
    @endif
</div>

<div class="card">
    <strong>Sync with cPanel</strong>
    <p class="muted small">Runs automatically every night. A manual sync is queued and starts within a minute.</p>
    @if($domains->isEmpty())
        <div class="flash warn">The panel has no domains yet, so there is nothing to sync. <a href="{{ route('admin.domains.import') }}">Import your domains from cPanel</a> first; a sync is queued for each one automatically.</div>
    @endif
    <form method="post" action="{{ route('admin.settings.sync-all') }}" class="inline">@csrf<button type="submit">Sync all domains</button></form>
    @foreach($domains as $d)
        <form method="post" action="{{ route('admin.settings.sync', $d) }}" class="inline">@csrf<button class="sec sm" type="submit">{{ $d->name }}</button></form>
    @endforeach
</div>

<div class="card">
    <strong>Background worker</strong>
    <p class="muted small">Sync buttons and bulk imports only queue work. The cron job picks it up every minute.</p>
    @if($worker['running'])
        <div class="flash ok">Running. Last heartbeat {{ \Illuminate\Support\Carbon::createFromTimestamp($worker['last'])->diffForHumans() }}.</div>
    @else
        <div class="flash err">
            Not running. {{ $worker['last'] ? 'The cron job last ran '.\Illuminate\Support\Carbon::createFromTimestamp($worker['last'])->diffForHumans().'.' : 'The cron job has never run since this feature was installed.' }}
            Queued syncs and bulk imports will not start until it does.
        </div>
        <p class="small"><strong>What to check:</strong></p>
        <ol class="small">
            <li>cPanel, Cron Jobs: an entry that runs <code>cd {{ base_path() }} &amp;&amp; php artisan schedule:run</code> every minute.</li>
            <li>The <code>php</code> in a cron job can be an older PHP than your website uses, or miss extensions, and then fails every minute without a word. Use the full path of the same PHP the site runs, for example <code>/opt/alt/php84/usr/bin/php</code> (or <code>/usr/local/bin/php</code>), and send errors to a file by ending the line with <code>&gt;/dev/null 2&gt;&gt;storage/logs/cron-errors.log</code>.</li>
            <li>In cPanel, Terminal (or any SSH), run <code>cd {{ base_path() }} &amp;&amp; php artisan panel:doctor</code> with the same <code>php</code> as the cron line. It prints the PHP version, missing extensions, the database, cPanel and the queue.</li>
        </ol>
    @endif
    <p>Waiting jobs: <strong>{{ $worker['pending'] }}</strong>@if($worker['oldest'] !== null) (oldest waiting {{ \Illuminate\Support\Carbon::now()->subSeconds($worker['oldest'])->diffForHumans(null, true) }})@endif · Failed jobs: <strong>{{ $worker['failed'] }}</strong>@if($worker['failed']) <span class="muted small">(see <code>storage/logs/laravel.log</code>)</span>@endif</p>
</div>

<h2>Recent sync runs</h2>
<table>
    <tr><th>Started</th><th>Domain</th><th>Trigger</th><th>Added</th><th>Changed</th><th>Removed</th><th>Errors</th></tr>
    @forelse($runs as $r)
        <tr><td class="small">{{ $r->started_at->format('Y-m-d H:i') }}</td><td>{{ $r->domain?->name }}</td><td>{{ $r->trigger }}</td>
            <td>{{ $r->added }}</td><td>{{ $r->changed }}</td><td>{{ $r->removed }}</td><td class="small">{{ $r->errors }}</td></tr>
    @empty<tr><td colspan="7" class="muted">No runs yet.</td></tr>@endforelse
</table>
@endsection
