<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\Audit;
use App\Services\CpanelClient;
use App\Services\CpanelException;
use Illuminate\Http\Request;

/** Why did a message not arrive? Uses cPanel's delivery trace for a recipient on a domain you can see. */
class TraceController extends Controller
{
    public function index(Request $request, CpanelClient $cpanel)
    {
        $result = null;
        $error = null;

        if ($request->filled('recipient')) {
            $request->validate(['recipient' => ['required', 'email', 'max:254']]);
            $recipient = strtolower($request->input('recipient'));
            $domain = Domain::forAddress($recipient);

            if (! $domain) {
                $error = 'That domain is not managed by this panel.';
            } else {
                $this->canView($domain);
                try {
                    $result = $cpanel->call('Email', 'trace_delivery', ['recipient' => $recipient]);
                    Audit::record($request->user(), 'trace.run', $recipient, $domain);
                } catch (CpanelException $e) {
                    $error = $e->getMessage().' (This host may not support delivery trace through the API; use cPanel, Email Deliverability or Track Delivery.)';
                }
            }
        }

        return view('trace.index', ['result' => $result, 'error' => $error]);
    }
}
