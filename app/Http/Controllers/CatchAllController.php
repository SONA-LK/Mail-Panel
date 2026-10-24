<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Forwarder;
use App\Services\CpanelException;
use App\Services\ForwarderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CatchAllController extends Controller
{
    public function index()
    {
        $domains = auth()->user()->visibleDomains()->orderBy('name')->get();
        $current = Forwarder::where('type', Forwarder::TYPE_CATCHALL)->whereIn('domain_id', $domains->pluck('id'))->get()->keyBy('domain_id');

        return view('catchall.index', ['domains' => $domains, 'current' => $current]);
    }

    public function update(Request $request, Domain $domain, ForwarderService $forwarders)
    {
        $this->canManage($domain);
        $data = $request->validate([
            'policy' => ['required', Rule::in(array_keys(Domain::CATCHALL))],
            'target' => ['nullable', 'email', 'required_if:policy,forward'],
        ]);

        try {
            $forwarders->setCatchAll($request->user(), $domain, $data['policy'], $data['target'] ?? null);
        } catch (CpanelException $e) {
            return $this->failed($e);
        }

        return back()->with('status', "Catch-all for {$domain->name} updated.");
    }
}
