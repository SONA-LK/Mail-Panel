@extends('install.layout')
@section('title', 'cPanel')
@section('content')
<form method="post" action="{{ route('install.cpanel') }}" class="card">
    @csrf
    <h2>cPanel connection</h2>
    <p class="muted">The panel manages mail through the cPanel API, so it needs an API token. In cPanel open <strong>Security, Manage API Tokens, Create</strong>, give it a name and paste the token below. It is shown only once. The token is stored in <code>.env</code> on this server and never displayed again.</p>
    @php($c = old() ?: $cp)
    <label for="host">cPanel server host</label>
    <input type="text" id="host" name="host" value="{{ $c['host'] ?? '' }}" placeholder="server.yourhost.com">
    <p class="hint">The host name from your cPanel address, for example <code>server.yourhost.com</code> from <code>https://server.yourhost.com:2083</code>. A pasted URL also works.</p>
    <label for="port">Port</label><input type="number" id="port" name="port" value="{{ $c['port'] ?? 2083 }}">
    <label for="user">cPanel username</label><input type="text" id="user" name="user" value="{{ $c['user'] ?? '' }}" autocomplete="off">
    <label for="token">API token</label><input type="password" id="token" name="token" autocomplete="new-password" placeholder="{{ !empty($cp['token']) ? 'saved, leave blank to keep' : '' }}">
    <label class="check"><input type="checkbox" name="verify_ssl" value="1" @checked($c['verify_ssl'] ?? true)> Verify the server certificate (leave on; turn off only if the test fails with a certificate error)</label>
    <p><button type="submit">Test and continue</button> <a class="btn sec" href="{{ route('install.database') }}">Back</a></p>
</form>
@endsection
