<?php

namespace App\Http\Controllers;

use App\Models\Autoresponder;
use App\Models\Domain;
use App\Models\Forwarder;
use App\Models\Mailbox;
use App\Models\MailFilter;
use App\Models\PendingDeletion;
use App\Services\AddressRules;
use App\Services\CpanelClient;
use App\Services\CpanelException;
use App\Services\MailboxService;
use App\Services\MailRulesService;
use App\Services\WelcomeMailer;
use App\Support\Csv;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MailboxController extends Controller
{
    public function __construct(private MailboxService $mailboxes, private MailRulesService $rules)
    {
    }

    private function query(Request $r)
    {
        $q = Mailbox::query()->with('domain')
            ->whereIn('domain_id', $this->visibleDomainIds())
            ->addSelect(['forwarder_count' => Forwarder::selectRaw('count(*)')
                ->whereColumn('forwarders_cache.source', 'mailboxes_cache.address')->where('type', Forwarder::TYPE_ADDRESS)])
            ->addSelect('mailboxes_cache.*');

        $q->when($r->filled('domain_id'), fn ($q) => $q->where('domain_id', (int) $r->input('domain_id')))
            ->when($r->filled('q'), fn ($q) => $q->where('address', 'like', '%'.addcslashes($r->input('q'), '%_\\').'%'))
            ->when($r->input('status') === 'active', fn ($q) => $q->where('suspended_login', false)->where('suspended_incoming', false))
            ->when($r->input('status') === 'suspended', fn ($q) => $q->where(fn ($w) => $w->where('suspended_login', true)->orWhere('suspended_incoming', true)))
            ->when($r->input('quota') === 'near', fn ($q) => $q->whereNotNull('quota_mb')->where('quota_mb', '>', 0)
                ->whereRaw('used_mb * 100 >= quota_mb * ?', [config('cpanel.quota_warn_pct')]))
            ->when($r->input('forwarders') === 'with', fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('forwarders_cache')
                ->whereColumn('forwarders_cache.source', 'mailboxes_cache.address')->where('type', Forwarder::TYPE_ADDRESS)));

        return $q->orderBy('address');
    }

    public function index(Request $request)
    {
        return view('mailboxes.index', [
            'mailboxes' => $this->query($request)->paginate(50)->withQueryString(),
            'domains' => auth()->user()->visibleDomains()->orderBy('name')->get(),
            'warnPct' => config('cpanel.quota_warn_pct'),
        ]);
    }

    public function export(Request $request)
    {
        $rows = $this->query($request)->get()->map(fn ($m) => [
            $m->address, $m->domain->name, $m->quota_mb ?? 'unlimited', $m->used_mb, $m->statusLabel(), (int) $m->forwarder_count,
        ]);

        return Csv::download('mailboxes-'.now()->format('Ymd').'.csv', ['address', 'domain', 'quota_mb', 'used_mb', 'status', 'forwarders'], $rows);
    }

    public function create(Request $request)
    {
        return view('mailboxes.create', [
            'domains' => auth()->user()->visibleDomains()->orderBy('name')->get()->filter(fn ($d) => auth()->user()->canManage($d))->values(),
            'selected' => $request->integer('domain_id'),
        ]);
    }

    public function store(Request $request, WelcomeMailer $welcome)
    {
        $data = $request->validate([
            'domain_id' => ['required', 'integer', 'exists:domains,id'],
            'local_part' => ['required', 'string', 'max:64'],
            'quota_mb' => ['nullable', 'integer', 'min:1', 'max:'.config('cpanel.max_quota_mb')],
            'password' => ['nullable', 'string', 'min:8', 'max:128'],
            'welcome_to' => ['nullable', 'email'],
        ]);

        $domain = Domain::findOrFail($data['domain_id']);
        $this->canManage($domain);

        $local = AddressRules::normalize($data['local_part']);
        if ($msg = AddressRules::localPartError($local, $domain)) {
            return back()->withInput()->withErrors(['local_part' => $msg]);
        }
        if (Mailbox::where('address', "$local@{$domain->name}")->exists()) {
            return back()->withInput()->withErrors(['local_part' => 'That address already exists.']);
        }

        try {
            [$mailbox, $password] = $this->mailboxes->create(
                $request->user(), $domain, $local, (int) ($data['quota_mb'] ?? $domain->default_quota_mb), $data['password'] ?? null
            );
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        $mailSent = false;
        if ($domain->welcome_enabled && ! empty($data['welcome_to'])) {
            try {
                $welcome->send($domain, $mailbox->address, $data['welcome_to']);
                $mailSent = true;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // Rendered directly (not redirected or flashed) so the password is never written to the session store.
        return response()->view('mailboxes.created', [
            'mailbox' => $mailbox, 'password' => $password, 'mailSent' => $mailSent,
            'settings' => $welcome->settings($domain, $mailbox->address),
        ]);
    }

    public function show(Mailbox $mailbox, CpanelClient $cpanel)
    {
        $mailbox->load('domain');
        $this->canView($mailbox->domain);

        return view('mailboxes.show', [
            'mailbox' => $mailbox,
            'forwardersFrom' => Forwarder::where('source', $mailbox->address)->get(),
            'forwardersTo' => Forwarder::where('destination', $mailbox->address)->get(),
            'autoresponder' => Autoresponder::where('address', $mailbox->address)->first(),
            'filters' => MailFilter::where('address', $mailbox->address)->get(),
            'pending' => PendingDeletion::where('address', $mailbox->address)->first(),
            'canManage' => auth()->user()->canManage($mailbox->domain),
            'webmail' => $cpanel->webmailUrl(),
            'deleteBlocked' => $this->mailboxes->deletionBlockedReason($mailbox),
        ]);
    }

    public function settings(Mailbox $mailbox, WelcomeMailer $welcome)
    {
        $mailbox->load('domain');
        $this->canView($mailbox->domain);

        return view('mailboxes.settings', ['mailbox' => $mailbox, 'settings' => $welcome->settings($mailbox->domain, $mailbox->address)]);
    }

    public function quota(Request $request, Mailbox $mailbox)
    {
        $mailbox->load('domain');
        $this->canManage($mailbox->domain);
        $data = $request->validate(['quota_mb' => ['required', 'integer', 'min:1', 'max:'.config('cpanel.max_quota_mb')]]);

        try {
            $this->mailboxes->changeQuota($request->user(), $mailbox, (int) $data['quota_mb']);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return back()->with('status', 'Quota updated.');
    }

    public function password(Request $request, Mailbox $mailbox)
    {
        $mailbox->load('domain');
        $this->canManage($mailbox->domain);
        $data = $request->validate(['password' => ['nullable', 'string', 'min:8', 'max:128']]);

        try {
            $password = $this->mailboxes->resetPassword($request->user(), $mailbox, $data['password'] ?? null);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return response()->view('mailboxes.created', ['mailbox' => $mailbox, 'password' => $password, 'mailSent' => false, 'settings' => null, 'reset' => true]);
    }

    public function suspend(Request $request, Mailbox $mailbox)
    {
        $mailbox->load('domain');
        $this->canManage($mailbox->domain);

        try {
            $pending = $this->mailboxes->suspend($request->user(), $mailbox);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return back()->with('status', 'Suspended. Deletion becomes allowed on '.$pending->delete_after->format('Y-m-d').'.');
    }

    public function unsuspend(Request $request, Mailbox $mailbox)
    {
        $mailbox->load('domain');
        $this->canManage($mailbox->domain);

        try {
            $this->mailboxes->unsuspend($request->user(), $mailbox);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return back()->with('status', 'Mailbox re-enabled.');
    }

    public function confirmDelete(Mailbox $mailbox)
    {
        $mailbox->load('domain');
        $this->canManage($mailbox->domain);

        return view('mailboxes.delete', [
            'mailbox' => $mailbox,
            'dangling' => Forwarder::where('destination', $mailbox->address)->get(),
            'blocked' => $this->mailboxes->deletionBlockedReason($mailbox),
        ]);
    }

    public function destroy(Request $request, Mailbox $mailbox)
    {
        $mailbox->load('domain');
        $this->canManage($mailbox->domain);

        $request->validate(['confirm' => ['required', Rule::in([$mailbox->address])]], [
            'confirm.in' => 'Type the full address exactly to confirm.',
        ]);

        try {
            $dangling = $this->mailboxes->delete($request->user(), $mailbox);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        $msg = "Deleted {$mailbox->address}.";
        if ($dangling->isNotEmpty()) {
            $msg .= ' These forwarders still point to it: '.$dangling->pluck('source')->implode(', ').'.';
        }

        return redirect()->route('mailboxes.index')->with('status', $msg);
    }

    public function autoresponder(Request $request, Mailbox $mailbox)
    {
        $mailbox->load('domain');
        $this->canManage($mailbox->domain);
        $data = $request->validate([
            'from' => ['nullable', 'email'], 'subject' => ['required', 'string', 'max:200'], 'body' => ['required', 'string', 'max:5000'],
            'start' => ['nullable', 'date'], 'stop' => ['nullable', 'date', 'after_or_equal:start'],
        ]);

        try {
            $this->rules->addAutoresponder($request->user(), $mailbox, $data);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return back()->with('status', 'Autoresponder saved.');
    }

    public function autoresponderDestroy(Request $request, Mailbox $mailbox)
    {
        $mailbox->load('domain');
        $this->canManage($mailbox->domain);

        try {
            $this->rules->deleteAutoresponder($request->user(), $mailbox);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return back()->with('status', 'Autoresponder removed.');
    }

    public function filter(Request $request, Mailbox $mailbox)
    {
        $mailbox->load('domain');
        $this->canManage($mailbox->domain);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'regex:/^[\w .-]+$/'],
            'field' => ['required', Rule::in(['from', 'to', 'subject'])],
            'match' => ['required', Rule::in(['contains', 'equals', 'begins', 'ends'])],
            'value' => ['required', 'string', 'max:200'],
            'action' => ['required', Rule::in(['discard', 'redirect', 'move'])],
            'destination' => ['required_if:action,redirect,move', 'nullable', 'string', 'max:200'],
        ]);

        if ($data['action'] === 'redirect' && ! AddressRules::isEmail($data['destination'])) {
            return back()->withInput()->withErrors(['destination' => 'Enter a valid address to redirect to.']);
        }
        // A dot is the folder separator, so it would create a nested folder; INBOX itself is not a target.
        if ($data['action'] === 'move' && (! preg_match('/^[\w -]+$/', $data['destination']) || strcasecmp(trim($data['destination']), 'inbox') === 0)) {
            return back()->withInput()->withErrors(['destination' => 'Enter a plain folder name (letters, numbers, spaces and dashes; no dots).']);
        }

        try {
            $this->rules->addFilter($request->user(), $mailbox, $data);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return back()->with('status', 'Filter saved.');
    }

    public function filterDestroy(Request $request, Mailbox $mailbox, MailFilter $filter)
    {
        $mailbox->load('domain');
        $this->canManage($mailbox->domain);
        abort_unless($filter->address === $mailbox->address, 404);

        try {
            $this->rules->deleteFilter($request->user(), $filter);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return back()->with('status', 'Filter removed.');
    }
}
