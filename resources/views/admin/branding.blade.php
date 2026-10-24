@extends('layouts.app')
@section('title', 'Branding')
@if($previewing)
    @push('head')<link rel="stylesheet" href="{{ route('admin.branding.preview', ['primary' => $brand['primary'], 'header' => $brand['header'], 'font' => $brand['font']]) }}">@endpush
@endif
@section('content')
<h1>Company branding</h1>
<p class="muted">Applies to everyone and to the sign-in page. Colours are checked for you: link and text colours are adjusted automatically so they stay readable, in light and dark mode. Every save is kept as a version you can bring back.</p>

@if($previewing)
    <div class="flash warn">Preview only. This whole page is showing the colours below, but nothing is saved until you press Save branding.</div>
@endif

<form method="get" action="{{ route('admin.branding') }}" class="card">
    <h2>Preview colours and font</h2>
    <div class="swatches">
        <div><label for="p-primary">Button and link colour</label><input id="p-primary" type="color" name="primary" value="{{ $brand['primary'] }}"></div>
        <div><label for="p-header">Top bar colour</label><input id="p-header" type="color" name="header" value="{{ $brand['header'] }}"></div>
        <div><label for="p-font">Font</label>
            <select id="p-font" name="font" class="auto">
                @foreach($fonts as $key => $font)<option value="{{ $key }}" @selected($brand['font'] === $key)>{{ $font['label'] }}</option>@endforeach
            </select></div>
    </div>
    <p><button type="submit" class="sec">Preview</button></p>
</form>

<form method="post" action="{{ route('admin.branding.update') }}" enctype="multipart/form-data" class="card">@csrf @method('PUT')
    <h2>Save branding</h2>
    <label for="b-name">Product name <span class="muted small">(shown in the top bar and on the sign-in page; leave empty for "{{ config('app.name') }}")</span></label>
    <input id="b-name" type="text" name="name" maxlength="60" value="{{ old('name', $brand['name']) }}">

    <div class="swatches">
        <div><label for="b-primary">Button and link colour</label><input id="b-primary" type="color" name="primary" value="{{ old('primary', $brand['primary']) }}"></div>
        <div><label for="b-header">Top bar colour</label><input id="b-header" type="color" name="header" value="{{ old('header', $brand['header']) }}"></div>
        <div><label for="b-font">Font</label>
            <select id="b-font" name="font" class="auto">
                @foreach($fonts as $key => $font)<option value="{{ $key }}" @selected(old('font', $brand['font']) === $key)>{{ $font['label'] }}</option>@endforeach
            </select></div>
    </div>

    <label for="b-logo">Logo <span class="muted small">(PNG, JPG or WebP, up to 1 MB. It is rebuilt as a PNG and scaled to fit the top bar. SVG is not accepted.)</span></label>
    <input id="b-logo" type="file" name="logo" accept="image/png,image/jpeg,image/webp">
    @if($currentId && \App\Models\BrandVersion::find($currentId)?->logo_path)
        <label class="check"><input type="checkbox" name="remove_logo" value="1"> Remove the current logo</label>
    @endif

    <label class="check"><input type="checkbox" name="allow_palettes" value="1" @checked(old('allow_palettes', $brand['allow_palettes']))> Let each person pick their own accent colour (light and dark mode are always personal)</label>

    <p><button type="submit">Save branding</button></p>
</form>

<h2>Versions</h2>
<table>
    <tr><th>#</th><th>Saved</th><th>By</th><th>Name</th><th>Colours</th><th>Font</th><th>Note</th><th></th></tr>
    @foreach($versions as $v)
        <tr>
            <td>{{ $v->id }} @if($v->id === $currentId)<span class="badge ok">live</span>@endif</td>
            <td class="small muted">{{ $v->created_at->diffForHumans() }}</td>
            <td class="small">{{ $v->author?->email ?? 'removed user' }}</td>
            <td>{{ $v->name ?? '—' }}</td>
            <td><span class="swatches"><input type="color" value="{{ $v->primary }}" disabled aria-label="Button colour {{ $v->primary }}"><input type="color" value="{{ $v->header }}" disabled aria-label="Top bar colour {{ $v->header }}"></span></td>
            <td class="small">{{ $fonts[$v->font]['label'] ?? $v->font }}</td>
            <td class="small muted">{{ $v->note }}</td>
            <td class="right">@if($v->id !== $currentId)<form method="post" class="inline" action="{{ route('admin.branding.restore', $v) }}">@csrf<button class="sm sec" type="submit">Restore</button></form>@endif</td>
        </tr>
    @endforeach
    @if($versions->isEmpty())<tr><td colspan="8" class="muted">No branding saved yet. The built-in look is in use.</td></tr>@endif
</table>
@endsection
