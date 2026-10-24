@extends('layouts.app')
@section('title', 'Import #'.$batch->id)
@section('content')
<h1>Import #{{ $batch->id }} <span class="badge">{{ $batch->status }}</span></h1>
<p class="muted">{{ $batch->original_name }} · {{ $batch->total_rows }} rows</p>

<div class="grid">
    @foreach(['valid' => 'ok', 'invalid' => 'bad', 'queued' => '', 'done' => 'ok', 'failed' => 'bad'] as $s => $cls)
        @if(($counts[$s] ?? 0) > 0)<div class="card"><span class="badge {{ $cls }}">{{ $s }}</span> <span class="stat">{{ $counts[$s] }}</span></div>@endif
    @endforeach
</div>

@if($batch->status === 'validated')
    <div class="card">
        <strong>Dry-run finished. Nothing has been changed in cPanel yet.</strong>
        <form method="post" action="{{ route('bulk.confirm', $batch) }}">
            @csrf
            @if($hasWarnings)
                <label class="check"><input type="checkbox" name="confirm_external" value="1"> Some rows forward to external addresses (marked below). I understand the risk and want to continue.</label>
            @endif
            <p><button type="submit" @disabled(($counts['valid'] ?? 0) === 0)>Confirm and queue {{ $counts['valid'] ?? 0 }} valid row(s)</button>
            </p>
        </form>
        <form method="post" action="{{ route('bulk.cancel', $batch) }}">@csrf<button class="sec" type="submit">Cancel import</button></form>
        <p class="muted small">Invalid rows are skipped and stay in the report.</p>
    </div>
@elseif($batch->status === 'queued')
    <div class="flash warn">Processing in the background (about {{ config('cpanel.bulk_per_run') }} rows per minute). You can close this page; reload to see progress. {{ $batch->done_rows }} done, {{ $batch->failed_rows }} failed.</div>
@endif

@if(in_array($batch->status, ['queued', 'done', 'partial']))
    <p><a class="btn" href="{{ route('bulk.result', $batch) }}">Download result CSV</a>
        @if($secrets)<span class="muted small">Generated passwords are included once and wiped after this download.</span>@endif</p>
@endif

<table>
    <tr><th>Row</th><th>Type</th><th>Address</th><th>Status</th><th>Message</th></tr>
    @foreach($rows as $r)
        @php($p = $r->payload ?? [])
        <tr>
            <td>{{ $r->row_number }}</td><td>{{ $p['type'] ?? '' }}</td><td>{{ $p['address'] ?? '' }}</td>
            <td><span class="badge {{ in_array($r->status, ['valid', 'done']) ? 'ok' : (in_array($r->status, ['invalid', 'failed']) ? 'bad' : '') }}">{{ $r->status }}</span></td>
            <td class="small">{{ $r->error_message }} @if($r->warnings)<span class="badge warn">{{ $r->warnings }}</span>@endif</td>
        </tr>
    @endforeach
</table>
<div class="pager">{{ $rows->links() }}</div>
@endsection
