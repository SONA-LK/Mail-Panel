<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Services\Audit;
use App\Services\CpanelException;
use App\Services\DomainImporter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DomainController extends Controller
{
    public function index()
    {
        return view('admin.domains.index', ['domains' => Domain::withCount(['mailboxes', 'forwarders'])->orderBy('name')->get()]);
    }

    public function create()
    {
        return view('admin.domains.form', ['domain' => new Domain(['default_quota_mb' => 1024, 'suspend_days' => 30, 'naming_pattern' => 'free', 'catchall_policy' => 'discard', 'forwarding_policy' => 'any'])]);
    }

    public function importForm(DomainImporter $importer)
    {
        try {
            $available = $importer->available();
        } catch (CpanelException $e) {
            return redirect()->route('admin.domains.index')->withErrors(['cpanel' => $e->getMessage()]);
        }

        return view('admin.domains.import', ['available' => $available]);
    }

    public function importStore(Request $request, DomainImporter $importer)
    {
        $data = $request->validate(['domains' => ['required', 'array', 'min:1'], 'domains.*' => ['string', 'max:253']]);

        try {
            $created = $importer->import($request->user(), $data['domains']);
        } catch (CpanelException $e) {
            return back()->withErrors(['cpanel' => $e->getMessage()]);
        }

        return redirect()->route('admin.domains.index')
            ->with('status', $created->count().' domain(s) imported. Mailboxes, forwarders and catch-all load within a minute (sync queued).');
    }

    public function store(Request $request)
    {
        $domain = Domain::create($this->validated($request));
        Audit::record($request->user(), 'domain.create', $domain->name, $domain);

        return redirect()->route('admin.domains.index')->with('status', "{$domain->name} added. Run a sync to load its mailboxes.");
    }

    public function edit(Domain $domain)
    {
        return view('admin.domains.form', ['domain' => $domain]);
    }

    public function update(Request $request, Domain $domain)
    {
        $domain->update($this->validated($request, $domain));
        Audit::record($request->user(), 'domain.update', $domain->name, $domain);

        return redirect()->route('admin.domains.index')->with('status', 'Domain settings saved.');
    }

    public function destroy(Request $request, Domain $domain)
    {
        // Removes only the panel's record and cache. Nothing is deleted in cPanel.
        Audit::record($request->user(), 'domain.remove', $domain->name, $domain);
        $domain->delete();

        return redirect()->route('admin.domains.index')->with('status', 'Domain removed from the panel. Mailboxes in cPanel are untouched.');
    }

    private function validated(Request $request, ?Domain $domain = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:253', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i', Rule::unique('domains', 'name')->ignore($domain?->id)],
            'purpose' => ['nullable', 'string', 'max:120'],
            'default_quota_mb' => ['required', 'integer', 'min:1', 'max:'.config('cpanel.max_quota_mb')],
            'naming_pattern' => ['required', Rule::in(array_keys(Domain::NAMING))],
            'catchall_policy' => ['required', Rule::in(array_keys(Domain::CATCHALL))],
            'catchall_target' => ['nullable', 'email', 'required_if:catchall_policy,forward'],
            'forwarding_policy' => ['required', Rule::in(array_keys(Domain::FORWARDING))],
            'forwarding_allowlist' => ['nullable', 'string', 'max:5000'],
            'welcome_enabled' => ['nullable', 'boolean'],
            'welcome_template' => ['nullable', 'string', 'max:5000'],
            'suspend_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'require_suspend_first' => ['nullable', 'boolean'],
            'auto_delete_suspended' => ['nullable', 'boolean'],
        ]);

        $data['name'] = strtolower($data['name']);
        foreach (['welcome_enabled', 'require_suspend_first', 'auto_delete_suspended'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        return $data;
    }
}
