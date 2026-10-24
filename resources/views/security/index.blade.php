@extends('layouts.app')
@section('title', 'Security')
@push('head')<script src="{{ asset('js/passkey.js') }}" defer></script>@endpush
@section('content')
<h1>My sign-in security</h1>
<p class="muted">Google proves who you are. A second step proves it is really you holding the account, even if someone learns your Google password. @if($required)<strong>Your role must have at least one.</strong>@else Recommended for everyone, required for super admins.@endif</p>

@if($newCodes)
    <div class="card">
        <h2>Your recovery codes</h2>
        <div class="flash warn">Save these now, in a password manager or on paper. They are shown only once. Each works one time if you lose your phone or key.</div>
        <div class="codes">@foreach($newCodes as $c)<span>{{ $c }}</span>@endforeach</div>
    </div>
@endif

<div class="card">
    <h2>Authenticator app @if($hasTotp)<span class="badge ok">on</span>@else<span class="badge">off</span>@endif</h2>
    <p class="muted small">Google Authenticator, Microsoft Authenticator, 1Password, Authy and similar apps. A new 6-digit code every 30 seconds.</p>
    @if($hasTotp)
        <form method="post" action="{{ route('security.totp.destroy') }}" class="inline">@csrf @method('DELETE')<button class="sm danger" type="submit">Remove authenticator app</button></form>
    @else
        <a class="btn" href="{{ route('security.totp') }}">Set up authenticator app</a>
    @endif
</div>

<div class="card">
    <h2>Passkeys and security keys <span class="badge {{ $passkeys->isNotEmpty() ? 'ok' : '' }}">{{ $passkeys->count() }}</span></h2>
    <p class="muted small">Face or fingerprint on your phone or laptop, or a hardware key such as a YubiKey. The strongest option: it cannot be phished.</p>
    @if($passkeys->isNotEmpty())
        <table>
            <tr><th>Name</th><th>Added</th><th>Last used</th><th></th></tr>
            @foreach($passkeys as $p)
                <tr>
                    <td>{{ $p->name }}</td>
                    <td class="small muted">{{ $p->created_at->diffForHumans() }}</td>
                    <td class="small muted">{{ $p->last_used_at?->diffForHumans() ?? 'never' }}</td>
                    <td class="right"><form method="post" class="inline" action="{{ route('security.passkey.destroy', $p) }}">@csrf @method('DELETE')<button class="sm danger" type="submit">Remove</button></form></td>
                </tr>
            @endforeach
        </table>
    @endif
    <div data-passkey-only hidden>
        <label for="pk-name">Name for the new passkey <span class="muted small">(for example "Work laptop")</span></label>
        <input id="pk-name" type="text" maxlength="60" data-passkey-name placeholder="Passkey {{ $passkeys->count() + 1 }}">
        <p><button type="button" data-passkey-add data-options-url="{{ route('security.passkey.options') }}" data-store-url="{{ route('security.passkey.store') }}">Add a passkey</button></p>
        <div class="err-text" data-passkey-error role="alert"></div>
    </div>
    <p class="muted small">This browser can only add a passkey when JavaScript is on and the site is opened over https.</p>
</div>

<div class="card">
    <h2>Recovery codes <span class="badge {{ $codesLeft > 2 ? 'ok' : ($codesLeft > 0 ? 'warn' : '') }}">{{ $codesLeft }} left</span></h2>
    <p class="muted small">For a lost phone or key. Making new codes cancels all the old ones.</p>
    <form method="post" action="{{ route('security.recovery.regenerate') }}">@csrf<button class="sec" type="submit">Make new recovery codes</button></form>
</div>

<h2>Where you are signed in</h2>
@if(! $sessionsSupported)
    <p class="muted">This installation does not keep sessions in the database, so devices cannot be listed.</p>
@else
    <table>
        <tr><th>Device</th><th>Address</th><th>Last active</th><th></th></tr>
        @foreach($sessions as $s)
            <tr>
                <td>{{ $s['agent'] }} @if($s['current'])<span class="badge ok">this device</span>@endif</td>
                <td class="small">{{ $s['ip'] }}</td>
                <td class="small muted">{{ $s['last']->diffForHumans() }}</td>
                <td class="right">@unless($s['current'])<form method="post" class="inline" action="{{ route('security.sessions.destroy', $s['handle']) }}">@csrf @method('DELETE')<button class="sm sec" type="submit">Sign out</button></form>@endunless</td>
            </tr>
        @endforeach
    </table>
    @if(count($sessions) > 1)
        <p><form method="post" action="{{ route('security.sessions.destroy-others') }}">@csrf @method('DELETE')<button class="sec" type="submit">Sign out every other device</button></form></p>
    @endif
    <p class="muted small">Do not recognise a device or address? Sign it out, then make new recovery codes and tell a super admin.</p>
@endif
@endsection
