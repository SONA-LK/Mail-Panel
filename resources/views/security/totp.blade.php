@extends('layouts.app')
@section('title', 'Authenticator app')
@section('content')
<h1>Set up an authenticator app</h1>
<div class="card">
    <ol>
        <li>Install an authenticator app on your phone (Google Authenticator, Microsoft Authenticator, 1Password, Authy).</li>
        <li>Scan this picture, or choose "enter a setup key" and type the key below it.</li>
        <li>Type the 6-digit code the app shows now.</li>
    </ol>
    <div class="qr"><img src="{{ $qr }}" alt="Setup QR code for your authenticator app"></div>
    <p class="muted small">Setup key: <code>{{ $secret }}</code> (time based, 6 digits)</p>
    <form method="post" action="{{ route('security.totp.confirm') }}">@csrf
        <label for="code">6-digit code</label>
        <input id="code" class="code-input" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" required autofocus>
        <p><button type="submit">Turn on</button> <a class="btn sec" href="{{ route('security.index') }}">Cancel</a></p>
    </form>
</div>
@endsection
