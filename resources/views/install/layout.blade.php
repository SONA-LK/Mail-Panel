<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Install') · Mail Panel</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<main class="wizard">
    <h1>Mail Panel setup</h1>
    @isset($step)
        <ol class="steps">
            @foreach([1 => 'Server check', 2 => 'Database', 3 => 'cPanel', 4 => 'Google and admin', 5 => 'Install'] as $n => $label)
                <li class="{{ $n === $step ? 'on' : ($n < $step ? 'done' : '') }}">{{ $n }}. {{ $label }}</li>
            @endforeach
        </ol>
    @endisset
    @if(session('status'))<div class="flash ok">{{ session('status') }}</div>@endif
    @if($errors->any())
        <div class="flash err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
    @endif
    @yield('content')
</main>
</body>
</html>
