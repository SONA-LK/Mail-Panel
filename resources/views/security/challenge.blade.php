@extends('layouts.app')
@section('title', $context === 'login' ? 'Second step' : 'Confirm it is you')
@push('head')<script src="{{ asset('js/passkey.js') }}" defer></script>@endpush
@section('content')
<div class="card login">
    <h1>{{ $context === 'login' ? 'One more step' : 'Confirm it is you' }}</h1>
    <p class="muted">
        @if($context === 'login')
            Google accepted {{ $user->email }}. Now prove it is really you.
        @else
            This change is sensitive. Confirm it is you, then repeat what you were doing.
        @endif
    </p>

    @if($hasPasskey)
        <div data-passkey-only hidden>
            <p><button type="button" data-passkey-use data-options-url="{{ $optionsUrl }}" data-verify-url="{{ $verifyUrl }}">Use my passkey</button></p>
            <div class="err-text" data-passkey-error role="alert"></div>
        </div>
    @endif

    @if($hasTotp || $hasPasskey)
        <form method="post" action="{{ $action }}">@csrf
            <label for="code">{{ $hasTotp ? 'Code from your authenticator app, or a recovery code' : 'Recovery code (if you cannot use your passkey)' }}</label>
            <input id="code" class="code-input" type="text" name="code" autocomplete="one-time-code" maxlength="32" required @unless($hasPasskey) autofocus @endunless>
            <p><button type="submit">Continue</button></p>
        </form>
    @else
        <p><a class="btn" href="{{ route('confirm.google') }}">Sign in with Google again</a></p>
    @endif

    @if($context === 'login')
        <p class="muted small"><a href="{{ route('login') }}">Start over</a></p>
    @else
        <p class="muted small"><a href="{{ route('dashboard') }}">Cancel</a></p>
    @endif
</div>
@endsection
