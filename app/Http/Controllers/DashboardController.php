<?php

namespace App\Http\Controllers;

use App\Models\Forwarder;
use App\Models\Mailbox;
use App\Models\PendingDeletion;
use App\Models\SyncRun;
use App\Services\HealthService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardController extends Controller
{
    public function __invoke(HealthService $health)
    {
        $domains = auth()->user()->visibleDomains()->orderBy('name')->get();
        $ids = $domains->pluck('id');
        $warn = config('cpanel.quota_warn_pct');

        $rows = $domains->map(function ($d) use ($health, $warn) {
            $nearQuota = Mailbox::where('domain_id', $d->id)->whereNotNull('quota_mb')->where('quota_mb', '>', 0)
                ->whereRaw('used_mb * 100 >= quota_mb * ?', [$warn])->count();

            return [
                'domain' => $d,
                'mailboxes' => Mailbox::where('domain_id', $d->id)->count(),
                'forwarders' => Forwarder::where('domain_id', $d->id)->where('type', Forwarder::TYPE_ADDRESS)->count(),
                'near_quota' => $nearQuota,
                'health' => $health->cached($d),
                'last_sync' => SyncRun::where('domain_id', $d->id)->latest('started_at')->first(),
            ];
        });

        $nearQuotaBoxes = Mailbox::with('domain')->whereIn('domain_id', $ids)->whereNotNull('quota_mb')->where('quota_mb', '>', 0)
            ->whereRaw('used_mb * 100 >= quota_mb * ?', [$warn])->orderByRaw('used_mb / quota_mb desc')->limit(10)->get();

        return view('dashboard', [
            'rows' => $rows,
            'nearQuotaBoxes' => $nearQuotaBoxes,
            'failedJobs' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'queued' => Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0,
            'syncErrors' => SyncRun::whereIn('domain_id', $ids)->whereNotNull('errors')->where('started_at', '>=', now()->subDay())->latest('started_at')->limit(5)->get(),
            'eligible' => PendingDeletion::with('domain')->whereIn('domain_id', $ids)->where('delete_after', '<=', now())->get(),
            'warnPct' => $warn,
        ]);
    }
}
