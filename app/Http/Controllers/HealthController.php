<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Services\Audit;
use App\Services\HealthService;

class HealthController extends Controller
{
    public function index(HealthService $health)
    {
        $domains = auth()->user()->visibleDomains()->orderBy('name')->get();

        return view('health.index', [
            'items' => $domains->map(fn ($d) => ['domain' => $d, 'result' => $health->cached($d)]),
        ]);
    }

    public function run(Domain $domain, HealthService $health)
    {
        $this->canView($domain);
        $result = $health->check($domain);
        Audit::record(auth()->user(), 'health.check', $domain->name, $domain, ['worst' => $result['worst']]);

        return redirect()->route('health.index')->with('status', "Checked {$domain->name}.");
    }
}
