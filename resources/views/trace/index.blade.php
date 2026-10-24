@extends('layouts.app')
@section('title', 'Delivery trace')
@section('content')
<h1>Delivery trace</h1>
<p class="muted">See how mail to an address was routed, to find out why a message did not arrive.</p>
<form method="get" class="filters card">
    <div><label>Recipient address</label><input type="email" name="recipient" value="{{ request('recipient') }}" required></div>
    <button type="submit">Trace</button>
</form>

@if($error)<div class="flash warn">{{ $error }}</div>@endif
@if($result !== null)
    <div class="card"><pre class="pre">{{ is_string($result) ? $result : json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></div>
@endif
@endsection
