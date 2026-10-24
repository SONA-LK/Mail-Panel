@extends('layouts.app')
@section('title', $user->exists ? 'Edit user' : 'Add user')
@section('content')
<h1>{{ $user->exists ? 'Edit '.$user->email : 'Add user' }}</h1>
<form method="post" class="card" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}">
    @csrf
    @if($user->exists)@method('PUT')@endif
    <label>Name</label><input type="text" name="name" value="{{ old('name', $user->name) }}" required>
    <label>Google email</label><input type="email" name="email" value="{{ old('email', $user->email) }}" required>
    <label>Role</label>
    <select name="role">
        @foreach(['super_admin' => 'Super admin', 'domain_manager' => 'Domain manager', 'read_only' => 'Read-only'] as $k => $v)
            <option value="{{ $k }}" @selected(old('role', $user->role) === $k)>{{ $v }}</option>
        @endforeach
    </select>
    <label>Domains <span class="muted small">(managers and read-only users; super admins see all)</span></label>
    @php($assigned = old('domains', $user->exists ? $user->domains->pluck('id')->all() : []))
    @foreach($domains as $d)
        <label class="check"><input type="checkbox" name="domains[]" value="{{ $d->id }}" @checked(in_array($d->id, $assigned))> {{ $d->name }}</label>
    @endforeach
    <label class="check"><input type="checkbox" name="active" value="1" @checked(old('active', $user->active))> Active</label>
    <p><button type="submit">Save</button> <a class="btn sec" href="{{ route('admin.users.index') }}">Cancel</a></p>
</form>
@endsection
