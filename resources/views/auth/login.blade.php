@extends('layouts.app')
@section('title', 'Sign in')
@section('content')
<div class="card login">
    <h1>{{ config('app.name') }}</h1>
    <p class="muted">Sign in with your approved Google account. Ask a super admin to add you if you cannot get in.</p>
    <p><a class="btn" href="{{ route('auth.google') }}">Sign in with Google</a></p>
</div>
@endsection
