@extends('layouts.app')
@section('title', 'New forwarder')
@section('content')
<h1>New forwarder</h1>

<form method="post" action="{{ route('forwarders.store') }}" class="card">
    @csrf
    <strong>Address forwarder</strong>
    <label>Domain</label>
    <select name="domain_id" required>@foreach($domains as $d)<option value="{{ $d->id }}" @selected(old('domain_id') == $d->id)>{{ $d->name }} ({{ $d->forwarding_policy }})</option>@endforeach</select>
    <label>Forward mail sent to (name before the @)</label>
    <input type="text" name="local_part" value="{{ old('local_part') }}" required maxlength="64">
    <label>To (one or more addresses, separated by commas, spaces or new lines)</label>
    <textarea name="destinations" required>{{ old('destinations') }}</textarea>
    <label class="check"><input type="checkbox" name="keep_copy" value="1" @checked(old('keep_copy'))> Also keep a copy in this address's own mailbox (if it has one)</label>
    @if(session('external_warning') || old('confirm_external'))
        <label class="check"><input type="checkbox" name="confirm_external" value="1"> I understand that forwarding to external providers can get this server's mail blocked, and I want to continue.</label>
    @endif
    <p><button type="submit">Save forwarder</button></p>
</form>

<form method="post" action="{{ route('forwarders.domain.store') }}" class="card">
    @csrf
    <strong>Domain forwarder</strong> <span class="muted small">All mail for one domain goes to the same name at another domain.</span>
    <label>From domain</label>
    <select name="domain_id" required>@foreach($domains as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach</select>
    <label>To domain</label>
    <input type="text" name="dest_domain" required placeholder="newdomain.com">
    <label class="check"><input type="checkbox" name="confirm_external" value="1"> This domain is not managed here; I confirm forwarding to it.</label>
    <p><button type="submit">Save domain forwarder</button></p>
</form>
@endsection
