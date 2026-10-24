@extends('layouts.app')
@section('title', 'Import domains')
@section('content')
<h1>Import domains from cPanel</h1>
<p class="muted">Domains and subdomains that cPanel can host mail for and the panel does not manage yet. Importing only adds them here; nothing changes in cPanel. Mailboxes, forwarders and the catch-all are loaded by a sync right after.</p>

@if(empty($available))
    <div class="card">Every mail domain in cPanel is already in the panel.</div>
@else
<form method="post" action="{{ route('admin.domains.import.store') }}">
    @csrf
    <div class="card">
        @foreach($available as $name)
            <label class="rowlabel"><input type="checkbox" name="domains[]" value="{{ $name }}" checked> {{ $name }}</label>
        @endforeach
    </div>
    <p><button type="submit">Import selected</button> <a class="btn sec" href="{{ route('admin.domains.index') }}">Cancel</a></p>
</form>
@endif
@endsection
