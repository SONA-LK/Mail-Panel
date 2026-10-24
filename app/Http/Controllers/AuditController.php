<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\Csv;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    private function query(Request $r)
    {
        $user = auth()->user();

        return AuditLog::with(['user', 'domain'])
            // Domain managers and read-only users see only entries for their own domains.
            ->when(! $user->isSuperAdmin(), fn ($q) => $q->whereIn('domain_id', $this->visibleDomainIds()))
            ->when($r->filled('user_id'), fn ($q) => $q->where('user_id', (int) $r->input('user_id')))
            ->when($r->filled('domain_id'), fn ($q) => $q->where('domain_id', (int) $r->input('domain_id')))
            ->when($r->filled('action'), fn ($q) => $q->where('action', 'like', addcslashes($r->input('action'), '%_\\').'%'))
            ->when($r->filled('q'), fn ($q) => $q->where('target', 'like', '%'.addcslashes($r->input('q'), '%_\\').'%'))
            ->when($r->filled('result'), fn ($q) => $q->where('result', $r->input('result')))
            ->when($r->filled('from'), fn ($q) => $q->where('created_at', '>=', $r->date('from')->startOfDay()))
            ->when($r->filled('to'), fn ($q) => $q->where('created_at', '<=', $r->date('to')->endOfDay()))
            ->orderByDesc('id');
    }

    public function index(Request $request)
    {
        return view('audit.index', [
            'entries' => $this->query($request)->paginate(50)->withQueryString(),
            'domains' => auth()->user()->visibleDomains()->orderBy('name')->get(),
            'users' => auth()->user()->isSuperAdmin() ? User::orderBy('email')->get() : collect(),
        ]);
    }

    public function export(Request $request)
    {
        $rows = (function () use ($request) {
            foreach ($this->query($request)->limit(20000)->cursor() as $e) {
                yield [$e->created_at->toDateTimeString(), $e->user_email ?? $e->user?->email ?? '', $e->action, $e->target ?? '',
                    $e->domain?->name ?? '', $e->result, $e->ip ?? '', json_encode($e->details)];
            }
        })();

        return Csv::download('audit-'.now()->format('Ymd').'.csv', ['time', 'user', 'action', 'target', 'domain', 'result', 'ip', 'details'], $rows);
    }
}
