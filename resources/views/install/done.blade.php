@extends('install.layout')
@section('title', 'Installed')
@section('content')
<div class="card">
    <h2>The panel is installed</h2>
    @foreach($r['warnings'] as $w)<div class="flash warn">{{ $w }}</div>@endforeach
    <p><strong>{{ $r['admin'] }}</strong> is the first super admin. {{ $r['imported'] }} domain(s) were added.</p>

    <h3>Two things left to do</h3>
    <ol>
        <li>
            <strong>Add the cron job</strong> (cPanel, Cron Jobs, once per minute). It runs the background queue, the nightly sync, health checks and the first sync of your domains:
            <pre>{{ $r['cron'] }}</pre>
            <span class="hint">Cron often runs a different, older PHP than your website. If nothing syncs after a few minutes, replace <code>php</code> with the full path of the PHP your site uses (for example <code>/opt/alt/php84/usr/bin/php</code> or <code>/usr/local/bin/php</code>), then open Settings in the panel: it shows whether the worker is running. Errors go to <code>storage/logs/cron-errors.log</code>; <code>php artisan panel:doctor</code> explains what the command line PHP sees.</span>
        </li>
        <li>
            <strong>Check the Google redirect URI</strong> is registered exactly as <code>{{ $redirectUri }}</code>, then sign in:
            <p><a class="btn" href="{{ $r['login_url'] }}">Sign in with Google</a></p>
        </li>
    </ol>
    <p class="muted small">The setup wizard is now switched off. To run it again, delete <code>storage/app/installed.lock</code> and <code>.env</code>. Welcome emails to new mailboxes stay off until you set the <code>MAIL_*</code> values in <code>.env</code>.</p>
</div>
@endsection
