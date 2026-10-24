<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Mail panel') · {{ config('app.name') }}</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
@auth
    @php($u = auth()->user())
    <header class="top">
        <span class="brand">{{ config('app.name') }}</span>
        <nav>
            <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'on' : '' }}">Dashboard</a>
            <a href="{{ route('mailboxes.index') }}" class="{{ request()->routeIs('mailboxes.*') ? 'on' : '' }}">Mailboxes</a>
            <a href="{{ route('forwarders.index') }}" class="{{ request()->routeIs('forwarders.*') ? 'on' : '' }}">Forwarders</a>
            <a href="{{ route('catchall.index') }}" class="{{ request()->routeIs('catchall.*') ? 'on' : '' }}">Catch-all</a>
            @if($u->role !== 'read_only')<a href="{{ route('bulk.index') }}" class="{{ request()->routeIs('bulk.*') ? 'on' : '' }}">Bulk import</a>@endif
            <a href="{{ route('health.index') }}" class="{{ request()->routeIs('health.*') ? 'on' : '' }}">Domain health</a>
            <a href="{{ route('trace.index') }}" class="{{ request()->routeIs('trace.*') ? 'on' : '' }}">Delivery trace</a>
            <a href="{{ route('audit.index') }}" class="{{ request()->routeIs('audit.*') ? 'on' : '' }}">Audit log</a>
            @if($u->isSuperAdmin())
                <a href="{{ route('admin.users.index') }}" class="{{ request()->routeIs('admin.users.*') ? 'on' : '' }}">Users</a>
                <a href="{{ route('admin.domains.index') }}" class="{{ request()->routeIs('admin.domains.*') ? 'on' : '' }}">Domains</a>
                <a href="{{ route('admin.settings') }}" class="{{ request()->routeIs('admin.settings*') ? 'on' : '' }}">Settings</a>
            @endif
        </nav>
        <div class="who">
            <span>{{ $u->email }} · {{ $u->roleLabel() }}</span>
            <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Sign out</button></form>
        </div>
    </header>
@endauth
<main>
    @if(session('status'))<div class="flash ok">{{ session('status') }}</div>@endif
    @if(session('warning'))<div class="flash warn">{{ session('warning') }}</div>@endif
    @if($errors->any())
        <div class="flash err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif
    @yield('content')
</main>
</body>
</html>
