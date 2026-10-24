<?php

namespace App\Http\Controllers;

use App\Models\BulkBatch;
use App\Services\Audit;
use App\Services\BulkImportService;
use Illuminate\Http\Request;
use InvalidArgumentException;

class BulkController extends Controller
{
    public function __construct(private BulkImportService $bulk)
    {
    }

    /** Only people who can write somewhere may import. */
    private function mayImport(): void
    {
        $user = auth()->user();
        abort_unless($user->isSuperAdmin() || $user->role === 'domain_manager', 403);
    }

    private function own(BulkBatch $batch): BulkBatch
    {
        $this->mayImport();
        abort_unless($batch->user_id === auth()->id() || auth()->user()->isSuperAdmin(), 403);

        return $batch;
    }

    public function index()
    {
        $this->mayImport();

        return view('bulk.index', [
            'batches' => BulkBatch::with('user')->when(! auth()->user()->isSuperAdmin(), fn ($q) => $q->where('user_id', auth()->id()))->latest()->limit(20)->get(),
            'maxRows' => config('cpanel.csv_max_rows'),
        ]);
    }

    public function template()
    {
        $this->mayImport();

        return response(BulkImportService::template(), 200, [
            'Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="bulk-template.csv"',
        ]);
    }

    public function store(Request $request)
    {
        $this->mayImport();
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:'.config('cpanel.csv_max_kb')]]);

        try {
            $batch = $this->bulk->parse($request->user(), $request->file('file'));
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $this->bulk->validate($batch);
        Audit::record($request->user(), 'bulk.upload', "batch #{$batch->id}", null, ['rows' => $batch->total_rows, 'file' => $batch->original_name]);

        return redirect()->route('bulk.show', $batch);
    }

    public function show(BulkBatch $batch)
    {
        $this->own($batch);

        return view('bulk.show', [
            'batch' => $batch,
            'rows' => $batch->rows()->orderBy('row_number')->paginate(100),
            'counts' => $batch->rows()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
            'hasWarnings' => $this->bulk->hasWarnings($batch),
            'secrets' => $batch->rows()->whereNotNull('result_secret')->exists(),
        ]);
    }

    public function confirm(Request $request, BulkBatch $batch)
    {
        $this->own($batch);

        if ($this->bulk->hasWarnings($batch) && ! $request->boolean('confirm_external')) {
            return back()->withErrors(['confirm_external' => 'Some rows forward to external addresses. Tick the confirmation to continue.']);
        }

        try {
            $n = $this->bulk->confirm($batch);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['batch' => $e->getMessage()]);
        }

        return redirect()->route('bulk.show', $batch)->with('status', "$n rows queued. Cron processes them in the background.");
    }

    public function cancel(BulkBatch $batch)
    {
        $this->own($batch);
        abort_unless(in_array($batch->status, ['parsed', 'validated'], true), 422);

        // Passwords supplied in the file must not outlive a cancelled import.
        $batch->rows()->delete();
        $batch->update(['status' => 'cancelled']);
        Audit::record(auth()->user(), 'bulk.cancel', "batch #{$batch->id}");

        return redirect()->route('bulk.index')->with('status', 'Import cancelled.');
    }

    public function result(BulkBatch $batch)
    {
        $this->own($batch);
        abort_unless(in_array($batch->status, ['queued', 'done', 'partial'], true), 404);

        $csv = $this->bulk->resultCsv($batch);

        return response($csv, 200, [
            'Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="bulk-result-'.$batch->id.'.csv"',
        ]);
    }
}
