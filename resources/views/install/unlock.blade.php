@extends('install.layout')
@section('title', 'Unlock setup')
@section('content')
<div class="card">
    <h2>Welcome</h2>
    <p>This wizard connects the panel to your cPanel account, creates its database and your first admin. It takes about five minutes.</p>
    @if($codeMissing)
        <div class="flash err">The setup code could not be created. Make the folder <code>storage/app</code> writable (chmod 755 or 775), reload this page, then read the code from <code>{{ $codeFile }}</code>.</div>
    @else
        <p>To make sure only you can run the setup, open the file <code>{{ $codeFile }}</code> in the panel folder on the server (cPanel File Manager or SSH) and type the code it contains.</p>
        <form method="post" action="{{ route('install.unlock') }}">
            @csrf
            <label for="code">Setup code</label>
            <input type="text" id="code" name="code" autocomplete="off" autofocus placeholder="XXXX-XXXX-XXXX">
            <p><button type="submit">Continue</button></p>
        </form>
    @endif
</div>
@endsection
