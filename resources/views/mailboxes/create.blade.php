@extends('layouts.app')
@section('title', 'New mailbox')
@section('content')
<h1>New mailbox</h1>
<form method="post" action="{{ route('mailboxes.store') }}" class="card">
    @csrf
    <label>Domain</label>
    <select name="domain_id" required>
        @foreach($domains as $d)
            <option value="{{ $d->id }}" @selected(old('domain_id', $selected) == $d->id)>{{ $d->name }} (default {{ $d->default_quota_mb }} MB, pattern: {{ $d->naming_pattern }})</option>
        @endforeach
    </select>
    <label>Name before the @</label>
    <input type="text" name="local_part" value="{{ old('local_part') }}" required maxlength="64" autocomplete="off">
    <label>Quota in MB <span class="muted small">(leave empty for the domain default)</span></label>
    <input type="number" name="quota_mb" value="{{ old('quota_mb') }}" min="1" max="{{ config('cpanel.max_quota_mb') }}">
    <label>Password <span class="muted small">(leave empty to generate a strong one)</span></label>
    <input type="text" name="password" autocomplete="off" minlength="8">
    <label>Send client settings to <span class="muted small">(optional, only if the domain has welcome emails on; the password is never emailed)</span></label>
    <input type="email" name="welcome_to" value="{{ old('welcome_to') }}">
    <p><button type="submit">Create mailbox</button></p>
</form>
@endsection
