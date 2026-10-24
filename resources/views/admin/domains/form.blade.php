@extends('layouts.app')
@section('title', $domain->exists ? 'Edit domain' : 'Add domain')
@section('content')
<h1>{{ $domain->exists ? 'Settings for '.$domain->name : 'Add domain' }}</h1>
<form method="post" class="card" action="{{ $domain->exists ? route('admin.domains.update', $domain) : route('admin.domains.store') }}">
    @csrf
    @if($domain->exists)@method('PUT')@endif
    <label>Domain name</label><input type="text" name="name" value="{{ old('name', $domain->name) }}" required>
    <label>Purpose</label><input type="text" name="purpose" value="{{ old('purpose', $domain->purpose) }}" placeholder="company, shop, personal, client…">
    <label>Default mailbox quota (MB)</label><input type="number" name="default_quota_mb" min="1" value="{{ old('default_quota_mb', $domain->default_quota_mb) }}" required>
    <label>Naming pattern</label>
    <select name="naming_pattern">@foreach(\App\Models\Domain::NAMING as $k => $v)<option value="{{ $k }}" @selected(old('naming_pattern', $domain->naming_pattern) === $k)>{{ $v }}</option>@endforeach</select>
    <label>Catch-all policy</label>
    <select name="catchall_policy">@foreach(\App\Models\Domain::CATCHALL as $k => $v)<option value="{{ $k }}" @selected(old('catchall_policy', $domain->catchall_policy) === $k)>{{ $v }}</option>@endforeach</select>
    <label>Catch-all forward address <span class="muted small">(when forwarding)</span></label>
    <input type="email" name="catchall_target" value="{{ old('catchall_target', $domain->catchall_target) }}">
    <label>Allowed forwarding destinations</label>
    <select name="forwarding_policy">@foreach(\App\Models\Domain::FORWARDING as $k => $v)<option value="{{ $k }}" @selected(old('forwarding_policy', $domain->forwarding_policy) === $k)>{{ $v }}</option>@endforeach</select>
    <label>Approved list <span class="muted small">(addresses or domains, one per line; used by "Approved list only")</span></label>
    <textarea name="forwarding_allowlist">{{ old('forwarding_allowlist', $domain->forwarding_allowlist) }}</textarea>
    <label class="check"><input type="checkbox" name="welcome_enabled" value="1" @checked(old('welcome_enabled', $domain->welcome_enabled))> Offer welcome email with client settings</label>
    <label>Welcome template <span class="muted small">(placeholders: {address} {webmail} {imap_host} {imap_port} {pop_port} {smtp_host} {smtp_port} {smtp_starttls_port}; empty = default)</span></label>
    <textarea name="welcome_template">{{ old('welcome_template', $domain->welcome_template) }}</textarea>
    <label>Days a mailbox stays suspended before deletion is allowed</label>
    <input type="number" name="suspend_days" min="0" value="{{ old('suspend_days', $domain->suspend_days) }}" required>
    <label class="check"><input type="checkbox" name="require_suspend_first" value="1" @checked(old('require_suspend_first', $domain->require_suspend_first))> Require suspend-first: a mailbox cannot be deleted until the wait is over</label>
    <label class="check"><input type="checkbox" name="auto_delete_suspended" value="1" @checked(old('auto_delete_suspended', $domain->auto_delete_suspended))> Delete automatically when the wait is over (off = only warn on the dashboard)</label>
    <p><button type="submit">Save</button> <a class="btn sec" href="{{ route('admin.domains.index') }}">Cancel</a></p>
</form>
@if($domain->exists)
<form method="post" action="{{ route('admin.domains.destroy', $domain) }}" class="card">
    @csrf @method('DELETE')
    <strong>Remove from the panel</strong>
    <p class="muted small">Deletes this panel's record and cached data for the domain. Nothing is deleted in cPanel.</p>
    <button class="danger sm" type="submit">Remove domain from panel</button>
</form>
@endif
@endsection
