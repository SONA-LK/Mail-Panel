@extends('layouts.app')
@section('title', 'Domains')
@section('content')
<h1>Domains</h1>
<p><a class="btn" href="{{ route('admin.domains.import') }}">Import from cPanel</a> <a class="btn sec" href="{{ route('admin.domains.create') }}">Add domain manually</a></p>
<table>
    <tr><th>Domain</th><th>Purpose</th><th>Quota</th><th>Naming</th><th>Catch-all</th><th>Forwarding</th><th>Mailboxes</th><th>Forwarders</th><th></th></tr>
    @foreach($domains as $d)
        <tr>
            <td>{{ $d->name }}</td><td>{{ $d->purpose }}</td><td>{{ $d->default_quota_mb }} MB</td><td>{{ $d->naming_pattern }}</td>
            <td>{{ $d->catchall_policy }}</td><td>{{ $d->forwarding_policy }}</td><td>{{ $d->mailboxes_count }}</td><td>{{ $d->forwarders_count }}</td>
            <td class="right"><a class="btn sm sec" href="{{ route('admin.domains.edit', $d) }}">Edit</a></td>
        </tr>
    @endforeach
</table>
@endsection
