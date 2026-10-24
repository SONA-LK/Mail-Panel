@extends('layouts.app')
@section('title', 'Client settings')
@section('content')
<h1>Client settings: {{ $mailbox->address }}</h1>
@include('mailboxes.partials.settings', ['settings' => $settings])
<p><a href="{{ route('mailboxes.show', $mailbox) }}">&larr; Back to mailbox</a></p>
@endsection
