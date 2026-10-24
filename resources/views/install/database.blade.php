@extends('install.layout')
@section('title', 'Database')
@section('content')
<form method="post" action="{{ route('install.database') }}" class="card">
    @csrf
    <h2>Database</h2>
    <p class="muted">The panel stores users, a cache of your mailboxes and its audit log here. Create an empty MySQL database and user in cPanel, MySQL Databases, and add the user to the database with all privileges. No database access? SQLite works too.</p>
    @php($d = old() ?: $db)
    <label class="check"><input type="radio" name="driver" value="mysql" @checked(($d['driver'] ?? 'mysql') === 'mysql')> MySQL / MariaDB (recommended)</label>
    <label class="check"><input type="radio" name="driver" value="sqlite" @checked(($d['driver'] ?? '') === 'sqlite')> SQLite (a single file, fine for small setups)</label>
    <label for="host">Host</label><input type="text" id="host" name="host" value="{{ $d['host'] ?? 'localhost' }}">
    <label for="port">Port</label><input type="number" id="port" name="port" value="{{ $d['port'] ?? 3306 }}">
    <label for="database">Database name</label><input type="text" id="database" name="database" value="{{ $d['database'] ?? '' }}" placeholder="cpaneluser_mailpanel">
    <label for="username">Database user</label><input type="text" id="username" name="username" value="{{ $d['username'] ?? '' }}" autocomplete="off">
    <label for="password">Password</label><input type="password" id="password" name="password" autocomplete="new-password" placeholder="{{ !empty($db['password']) ? 'saved, leave blank to keep' : '' }}">
    <p class="hint">Ignore host, name, user and password when you choose SQLite.</p>
    <p><button type="submit">Test and continue</button> <a class="btn sec" href="{{ route('install.start') }}">Back</a></p>
</form>
@endsection
