<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApprovalRequest;
use App\Services\Approvals;
use Illuminate\Http\Request;
use RuntimeException;

/** Changes that wait for a second super admin. */
class ApprovalController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.approvals', [
            'pending' => ApprovalRequest::pending()->latest('id')->get(),
            'recent' => ApprovalRequest::where('status', '!=', 'pending')->latest('id')->limit(15)->get(),
            'me' => $request->user()->id,
        ]);
    }

    public function approve(Request $request, ApprovalRequest $approval, Approvals $approvals)
    {
        try {
            $approvals->approve($request->user(), $approval);
        } catch (RuntimeException $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        }

        return back()->with('status', "Approved and done: {$approval->label()} ({$approval->target}).");
    }

    public function reject(Request $request, ApprovalRequest $approval, Approvals $approvals)
    {
        try {
            $approvals->reject($request->user(), $approval);
        } catch (RuntimeException $e) {
            return back()->withErrors(['approval' => $e->getMessage()]);
        }

        return back()->with('status', 'Rejected. Nothing was changed.');
    }
}
