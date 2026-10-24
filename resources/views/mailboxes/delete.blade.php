@extends('layouts.app')
@section('title', 'Delete mailbox')
@section('content')
<h1>Delete {{ $mailbox->address }}</h1>
<div class="flash err">Deleting removes the mailbox <strong>and all of its mail</strong>. This cannot be undone. Consider suspending it first.</div>

@if($dangling->isNotEmpty())
    <div class="flash warn">These forwarders point at this address and will stop working. Remove or redirect them afterwards:
        <ul>@foreach($dangling as $f)<li>{{ $f->source }}</li>@endforeach</ul></div>
@endif

@if($blocked)
    <div class="flash warn">{{ $blocked }}</div>
    <a class="btn sec" href="{{ route('mailboxes.show', $mailbox) }}">Back</a>
@else
    <form method="post" action="{{ route('mailboxes.destroy', $mailbox) }}" class="card">
        @csrf @method('DELETE')
        <label>Type <code>{{ $mailbox->address }}</code> to confirm</label>
        <input type="text" name="confirm" autocomplete="off" required>
        <p><button class="danger" type="submit">Delete permanently</button>
            <a class="btn sec" href="{{ route('mailboxes.show', $mailbox) }}">Cancel</a></p>
    </form>
@endif
@endsection
