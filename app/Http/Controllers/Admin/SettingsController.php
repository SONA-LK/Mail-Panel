<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncDomainJob;
use App\Models\Domain;
use App\Models\SyncRun;
use App\Services\Audit;
use App\Services\CpanelClient;
use App\Services\CpanelException;
use App\Services\WorkerStatus;
use Illuminate\Http\Request;

/** Sync controls and the cPanel connection test. The API token itself is never displayed. */
class SettingsController extends Controller
{
    public function index(CpanelClient $cpanel)
    {
        return view('admin.settings', [
            'configured' => $cpanel->configured(),
            'host' => $cpanel->configured() ? $cpanel->host() : null,
            'cpanelUser' => config('cpanel.user'),
            'verifySsl' => config('cpanel.verify_ssl'),
            'domains' => Domain::orderBy('name')->get(),
            'runs' => SyncRun::with('domain')->latest('started_at')->limit(20)->get(),
            'worker' => app(WorkerStatus::class)->summary(),
        ]);
    }

    public function test(Request $request, CpanelClient $cpanel)
    {
        try {
            $data = $cpanel->call('Email', 'list_pops');
            Audit::record($request->user(), 'cpanel.test', null, null, ['mailboxes' => count($data ?? [])]);

            return back()->with('status', 'Connection works. cPanel returned '.count($data ?? []).' mailbox(es).');
        } catch (CpanelException $e) {
            Audit::record($request->user(), 'cpanel.test', null, null, ['error' => $e->getMessage()], 'failure');

            return back()->withErrors(['cpanel' => $e->getMessage()]);
        }
    }

    public function sync(Request $request, Domain $domain)
    {
        SyncDomainJob::dispatch($domain->id);
        Audit::record($request->user(), 'sync.request', $domain->name, $domain);

        return $this->queued("Sync for {$domain->name} queued. It starts within a minute.");
    }

    public function syncAll(Request $request)
    {
        if (! Domain::exists()) {
            return back()->withErrors(['sync' => 'There are no domains in the panel yet, so there is nothing to sync. Open Domains, then Import from cPanel, first.']);
        }

        foreach (Domain::pluck('id') as $id) {
            SyncDomainJob::dispatch($id);
        }
        Audit::record($request->user(), 'sync.request', 'all domains');

        return $this->queued('Sync queued for all domains.');
    }

    /** Queueing always works; whether anything then happens depends on cron, so say so when it is not running. */
    private function queued(string $message)
    {
        $back = back()->with('status', $message);

        return app(WorkerStatus::class)->running()
            ? $back
            : $back->with('warning', 'The background worker is not running, so nothing will sync yet. See Background worker below for what to check.');
    }
}
