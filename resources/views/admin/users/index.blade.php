@extends('layouts.app')
@section('title', 'Users')
@section('content')
<h1>Panel users</h1>
<p class="muted">Everyone signs in with Google. Add a person's Google email here first; any other account is refused and logged.</p>
<p><a class="btn" href="{{ route('admin.users.create') }}">Add user</a></p>
<table>
    <tr><th>Name</th><th>Google email</th><th>Role</th><th>Domains</th><th>Active</th><th>2-step</th><th>Last login</th><th></th></tr>
    @foreach($users as $u)
        <tr>
            <td>{{ $u->name }}</td><td>{{ $u->email }}</td><td>{{ $u->roleLabel() }}</td>
            <td class="small">{{ $u->isSuperAdmin() ? 'All' : $u->domains->pluck('name')->implode(', ') }}</td>
            <td><span class="badge {{ $u->active ? 'ok' : 'bad' }}">{{ $u->active ? 'yes' : 'no' }}</span></td>
            <td>@if($u->hasTwoFactor())<span class="badge ok">on</span>@else<span class="badge {{ $u->requiresTwoFactor() ? 'bad' : '' }}">{{ $u->requiresTwoFactor() ? 'missing' : 'off' }}</span>@endif</td>
            <td class="small muted">{{ $u->last_login_at?->diffForHumans() ?? 'never' }}</td>
            <td class="right"><a class="btn sm sec" href="{{ route('admin.users.edit', $u) }}">Edit</a>
                @if($u->id !== auth()->id() && $u->hasTwoFactor())<form method="post" class="inline" action="{{ route('admin.users.reset-two-factor', $u) }}">@csrf<button class="sm sec" type="submit">Reset 2-step</button></form>@endif
                @if($u->id !== auth()->id())<form method="post" class="inline" action="{{ route('admin.users.destroy', $u) }}">@csrf @method('DELETE')<button class="sm danger" type="submit">Remove</button></form>@endif</td>
        </tr>
    @endforeach
</table>
@endsection
