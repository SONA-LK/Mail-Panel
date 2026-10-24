<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Forwarder;
use App\Services\AddressRules;
use App\Services\CpanelException;
use App\Services\ForwarderService;
use App\Services\ForwardingGuard;
use App\Support\Csv;
use Illuminate\Http\Request;

class ForwarderController extends Controller
{
    public function __construct(private ForwarderService $forwarders, private ForwardingGuard $guard)
    {
    }

    private function query(Request $r)
    {
        return Forwarder::with('domain')
            ->whereIn('domain_id', $this->visibleDomainIds())
            ->when($r->filled('domain_id'), fn ($q) => $q->where('domain_id', (int) $r->input('domain_id')))
            ->when($r->filled('type'), fn ($q) => $q->where('type', $r->input('type')))
            ->when($r->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->where('source', 'like', '%'.addcslashes($r->input('q'), '%_\\').'%')
                ->orWhere('destination', 'like', '%'.addcslashes($r->input('q'), '%_\\').'%')))
            ->orderBy('source')->orderBy('destination');
    }

    public function index(Request $request)
    {
        $reverse = null;
        if ($request->filled('points_to')) {
            $reverse = Forwarder::whereIn('domain_id', $this->visibleDomainIds())
                ->where('destination', AddressRules::normalize($request->input('points_to')))->get();
        }

        return view('forwarders.index', [
            'forwarders' => $this->query($request)->paginate(50)->withQueryString(),
            'domains' => auth()->user()->visibleDomains()->orderBy('name')->get(),
            'reverse' => $reverse,
        ]);
    }

    public function export(Request $request)
    {
        $rows = $this->query($request)->get()->map(fn ($f) => [$f->source, $f->destination, $f->type, $f->domain->name]);

        return Csv::download('forwarders-'.now()->format('Ymd').'.csv', ['source', 'destination', 'type', 'domain'], $rows);
    }

    public function create(Request $request)
    {
        return view('forwarders.create', [
            'domains' => auth()->user()->visibleDomains()->orderBy('name')->get()->filter(fn ($d) => auth()->user()->canManage($d))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'domain_id' => ['required', 'integer', 'exists:domains,id'],
            'local_part' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._+-]+$/'],
            'destinations' => ['required', 'string', 'max:2000'],
            'keep_copy' => ['nullable', 'boolean'],
            'confirm_external' => ['nullable', 'boolean'],
        ]);

        $domain = Domain::findOrFail($data['domain_id']);
        $this->canManage($domain);

        $source = AddressRules::normalize($data['local_part']).'@'.strtolower($domain->name);
        $dests = array_values(array_unique(array_filter(array_map(
            fn ($d) => AddressRules::normalize($d), preg_split('/[\s,;]+/', $data['destinations'])
        ))));

        $check = $this->guard->check($domain, $source, $dests);
        if ($check['errors']) {
            return back()->withInput()->withErrors(['destinations' => implode(' ', $check['errors'])]);
        }
        if ($check['external'] && ! $request->boolean('confirm_external')) {
            return back()->withInput()->with('external_warning', $check['external'])
                ->withErrors(['destinations' => 'External destinations need your confirmation: '.implode(', ', $check['external']).'. Mail forwarded to Gmail, Outlook and similar providers can get this server blocked if it carries spam.']);
        }

        if ($request->boolean('keep_copy')) {
            $dests[] = $source; // forwarding to itself keeps a copy in the mailbox
        }

        try {
            $this->forwarders->add($request->user(), $domain, $source, $dests);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return redirect()->route('forwarders.index', ['domain_id' => $domain->id])->with('status', "Forwarder $source saved.");
    }

    public function destroy(Request $request, Forwarder $forwarder)
    {
        $forwarder->load('domain');
        $this->canManage($forwarder->domain);
        abort_unless($forwarder->type === Forwarder::TYPE_ADDRESS, 404);

        try {
            $this->forwarders->delete($request->user(), $forwarder);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return back()->with('status', 'Forwarder removed.');
    }

    public function storeDomain(Request $request)
    {
        $data = $request->validate([
            'domain_id' => ['required', 'integer', 'exists:domains,id'],
            'dest_domain' => ['required', 'string', 'max:253', 'regex:/^([a-z0-9-]+\.)+[a-z]{2,}$/i'],
        ]);

        $domain = Domain::findOrFail($data['domain_id']);
        $this->canManage($domain);

        $external = ! Domain::where('name', strtolower($data['dest_domain']))->exists();
        if ($domain->forwarding_policy === 'internal' && $external) {
            return back()->withInput()->withErrors(['dest_domain' => "{$domain->name} only allows forwarding to managed domains."]);
        }
        if ($domain->forwarding_policy === 'list' && ! in_array(strtolower($data['dest_domain']), $domain->allowlist(), true) && ! in_array('@'.strtolower($data['dest_domain']), $domain->allowlist(), true)) {
            return back()->withInput()->withErrors(['dest_domain' => 'That domain is not on the approved list.']);
        }
        if ($external && ! $request->boolean('confirm_external')) {
            return back()->withInput()->withErrors(['dest_domain' => 'This is an external domain. Tick the confirmation box to continue.']);
        }

        try {
            $this->forwarders->addDomainForwarder($request->user(), $domain, $data['dest_domain']);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return redirect()->route('forwarders.index', ['domain_id' => $domain->id, 'type' => 'domain'])->with('status', 'Domain forwarder saved.');
    }

    public function destroyDomain(Request $request, Forwarder $forwarder)
    {
        $forwarder->load('domain');
        $this->canManage($forwarder->domain);
        abort_unless($forwarder->type === Forwarder::TYPE_DOMAIN, 404);

        try {
            $this->forwarders->deleteDomainForwarder($request->user(), $forwarder);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return back()->with('status', 'Domain forwarder removed.');
    }
}
