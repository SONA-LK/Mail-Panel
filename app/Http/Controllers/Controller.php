<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\Audit;
use App\Services\CpanelException;
use Illuminate\Http\RedirectResponse;

abstract class Controller
{
    /** Every request re-checks domain access on the server; the browser is never trusted. */
    protected function canView(Domain $domain): void
    {
        if (! auth()->user()->canView($domain)) {
            $this->deny($domain);
        }
    }

    protected function canManage(Domain $domain): void
    {
        if (! auth()->user()->canManage($domain)) {
            $this->deny($domain);
        }
    }

    private function deny(Domain $domain): never
    {
        Audit::record(auth()->user(), 'access.denied', request()->path(), $domain, [], 'denied');
        abort(403, 'You do not have access to this domain or action.');
    }

    /** @return list<int> */
    protected function visibleDomainIds(): array
    {
        return auth()->user()->visibleDomains()->pluck('domains.id')->all();
    }

    protected function failed(CpanelException $e): RedirectResponse
    {
        return back()->withInput()->withErrors(['cpanel' => $e->getMessage()]);
    }
}
