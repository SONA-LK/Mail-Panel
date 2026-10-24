@extends('install.layout')
@section('title', 'Google and admin')
@section('content')
<form method="post" action="{{ route('install.admin') }}" class="card">
    @csrf
    @php($v = old() ?: $a)
    <h2>Panel address</h2>
    <label for="url">Address</label>
    <input type="url" id="url" name="url" value="{{ $v['url'] ?? '' }}">
    <p class="hint">Where people open this panel. It must be <code>https://</code> for Google sign-in to work.</p>
    <label for="app_name">Name shown in the panel</label><input type="text" id="app_name" name="app_name" value="{{ $v['app_name'] ?? 'Mail Panel' }}">

    <h2>Google sign-in</h2>
    <p class="muted">Nobody types a password into this panel; people sign in with an approved Google account. In <strong>Google Cloud Console, APIs and Services, Credentials, Create credentials, OAuth client ID, Web application</strong>, add this exact redirect URI, then paste the client ID and secret.</p>
    <p>Redirect URI: <code>{{ rtrim($v['url'] ?? '', '/') }}/auth/google/callback</code></p>
    <label for="google_id">Client ID</label><input type="text" id="google_id" name="google_id" value="{{ $v['google_id'] ?? '' }}" autocomplete="off">
    <label for="google_secret">Client secret</label><input type="password" id="google_secret" name="google_secret" autocomplete="new-password" placeholder="{{ !empty($a['google_secret']) ? 'saved, leave blank to keep' : '' }}">
    <label for="allowed_domain">Only allow Google accounts from this domain (optional, Google Workspace)</label>
    <input type="text" id="allowed_domain" name="allowed_domain" value="{{ $v['allowed_domain'] ?? '' }}" placeholder="yourcompany.com">

    <h2>First admin</h2>
    <label for="admin_email">Your Google account email</label><input type="email" id="admin_email" name="admin_email" value="{{ $v['admin_email'] ?? '' }}">
    <label for="admin_name">Your name</label><input type="text" id="admin_name" name="admin_name" value="{{ $v['admin_name'] ?? '' }}">
    <label class="check"><input type="checkbox" name="import_domains" value="1" @checked($v['import_domains'] ?? true)> Add every domain that exists in cPanel to the panel (you can remove some later)</label>
    <p><button type="submit">Continue</button> <a class="btn sec" href="{{ route('install.cpanel') }}">Back</a></p>
</form>
@endsection
