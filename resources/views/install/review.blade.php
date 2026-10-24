@extends('install.layout')
@section('title', 'Install')
@section('content')
<div class="card">
    <h2>Ready to install</h2>
    <table>
        <tr><th>Panel address</th><td>{{ $a['url'] }}</td></tr>
        <tr><th>Database</th><td>{{ $db['driver'] === 'sqlite' ? 'SQLite file' : $db['username'].'@'.$db['host'].' / '.$db['database'] }}</td></tr>
        <tr><th>cPanel</th><td>{{ $cp['user'] }} on {{ $cp['host'] }}:{{ $cp['port'] }} (token saved, hidden)</td></tr>
        <tr><th>First admin</th><td>{{ $a['admin_email'] }}</td></tr>
        <tr><th>Domains</th><td>{{ ($a['import_domains'] ?? false) ? 'Import all from cPanel' : 'Add later' }}</td></tr>
    </table>
    <p class="muted">Installing creates the database tables, your admin and a <code>.env</code> file. Nothing is changed in cPanel.</p>
    <form method="post" action="{{ route('install.run') }}">
        @csrf
        <button type="submit">Install now</button> <a class="btn sec" href="{{ route('install.admin') }}">Back</a>
    </form>
</div>
@endsection
