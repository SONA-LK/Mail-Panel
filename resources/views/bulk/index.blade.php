@extends('layouts.app')
@section('title', 'Bulk import')
@section('content')
<h1>Bulk import</h1>
<div class="card">
    <p>Upload a CSV of mailboxes and forwarders (up to {{ $maxRows }} rows). You see a dry-run first; nothing changes until you confirm.</p>
    <p><a href="{{ route('bulk.template') }}">Download the template</a>. Columns: <code>type,address,quota_mb,password,forward_to</code>. A blank password is generated. Separate several destinations with <code>;</code>.</p>
    <form method="post" action="{{ route('bulk.store') }}" enctype="multipart/form-data">
        @csrf
        <input type="file" name="file" accept=".csv,text/csv" required>
        <p><button type="submit">Upload and check</button></p>
    </form>
</div>

<h2>Recent imports</h2>
<table>
    <tr><th>#</th><th>File</th><th>By</th><th>Status</th><th>Rows</th><th>Done</th><th>Failed</th><th>When</th></tr>
    @forelse($batches as $b)
        <tr>
            <td><a href="{{ route('bulk.show', $b) }}">{{ $b->id }}</a></td><td>{{ $b->original_name }}</td><td>{{ $b->user->email }}</td>
            <td><span class="badge">{{ $b->status }}</span></td><td>{{ $b->total_rows }}</td><td>{{ $b->done_rows }}</td><td>{{ $b->failed_rows }}</td>
            <td class="muted small">{{ $b->created_at->diffForHumans() }}</td>
        </tr>
    @empty<tr><td colspan="8" class="muted">No imports yet.</td></tr>@endforelse
</table>
@endsection
