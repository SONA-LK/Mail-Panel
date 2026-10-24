@extends('layouts.app')
@section('title', isset($reset) ? 'Password reset' : 'Mailbox created')
@section('content')
<h1>{{ isset($reset) ? 'Password reset' : 'Mailbox created' }}: {{ $mailbox->address }}</h1>
<div class="card">
    <p><strong>Password (shown once, never stored):</strong></p>
    <div class="secret">{{ $password }}</div>
    <p class="muted small">Copy it now. Reloading this page will not show it again. Do not reload or resubmit the form.</p>
    @if($mailSent)<p class="flash ok">Client settings were emailed (without the password).</p>@endif
</div>
@if($settings)
    @include('mailboxes.partials.settings', ['settings' => $settings])
@endif
<p><a class="btn" href="{{ route('mailboxes.show', $mailbox) }}">Open mailbox</a></p>
@endsection
