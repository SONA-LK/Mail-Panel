<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\User;
use App\Models\ApprovalRequest;
use App\Services\Approvals;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users.index', ['users' => User::with('domains')->withExists('passkeys')->orderBy('email')->get()]);
    }

    public function create()
    {
        return view('admin.users.form', ['user' => new User(['role' => User::ROLE_READONLY, 'active' => true]), 'domains' => Domain::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $user = User::create(collect($data)->except('domains')->all());
        $user->domains()->sync($data['domains'] ?? []);
        Audit::record($request->user(), 'user.create', $user->email, null, ['role' => $user->role, 'domains' => $data['domains'] ?? []]);

        return redirect()->route('admin.users.index')->with('status', "{$user->email} can now sign in with Google.");
    }

    public function edit(User $user)
    {
        return view('admin.users.form', ['user' => $user->load('domains'), 'domains' => Domain::orderBy('name')->get()]);
    }

    public function update(Request $request, User $user, Approvals $approvals)
    {
        $data = $this->validated($request, $user);

        // Never leave the panel without an active super admin.
        $stillSuper = $data['role'] === User::ROLE_SUPER && ($data['active'] ?? false);
        if ($user->isSuperAdmin() && ! $stillSuper && ! $this->otherActiveSuperAdmin($user)) {
            return back()->withInput()->withErrors(['role' => 'This is the last active super admin. Add another one first.']);
        }

        // Switching a super admin off is as hard to take back as removing one, and would otherwise be a way around
        // the four-eyes rule (one admin disables the other, then removes domains alone).
        if ($user->isSuperAdmin() && ! $stillSuper && $approvals->required()) {
            $approvals->request($request->user(), ApprovalRequest::USER_DEMOTE, $user->email, ['user_id' => $user->id, 'role' => $data['role'], 'active' => (bool) $data['active']]);

            return redirect()->route('admin.users.index')->with('status', "Taking super admin rights from, or disabling, {$user->email} needs a second super admin to approve it (Approvals menu). Nothing was changed yet.");
        }

        $changedAccess = $user->role !== $data['role'] || ($user->active && ! $data['active']);
        $user->update(collect($data)->except('domains')->all());
        // A changed role or a disabled account must not keep working in a browser that is already signed in.
        if ($changedAccess && $user->id !== $request->user()->id) {
            $user->revokeSessions();
        }
        $user->domains()->sync($data['domains'] ?? []);
        Audit::record($request->user(), 'user.update', $user->email, null, ['role' => $user->role, 'active' => $user->active, 'domains' => $data['domains'] ?? []]);

        return redirect()->route('admin.users.index')->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user, Approvals $approvals)
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }
        if ($user->isSuperAdmin() && ! $this->otherActiveSuperAdmin($user)) {
            return back()->withErrors(['user' => 'This is the last active super admin.']);
        }

        if ($user->isSuperAdmin() && $approvals->required()) {
            $approvals->request($request->user(), ApprovalRequest::USER_DELETE, $user->email, ['user_id' => $user->id]);

            return redirect()->route('admin.users.index')->with('status', "Removing the super admin {$user->email} needs a second super admin to approve it (Approvals menu). Nothing was removed yet.");
        }

        Audit::record($request->user(), 'user.delete', $user->email);
        $user->revokeSessions();
        $user->delete();

        return redirect()->route('admin.users.index')->with('status', 'User removed.');
    }

    /** For someone who lost their phone and recovery codes. They must set up a second step again on next sign-in. */
    public function resetTwoFactor(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'Use the Security page for your own sign-in steps.']);
        }

        $user->resetTwoFactor();
        Audit::record($request->user(), 'user.two_factor_reset', $user->email);

        return back()->with('status', "Second sign-in steps for {$user->email} were removed and they were signed out everywhere.");
    }

    private function otherActiveSuperAdmin(User $user): bool
    {
        return User::where('role', User::ROLE_SUPER)->where('active', true)->where('id', '!=', $user->id)->exists();
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254', Rule::unique('users', 'email')->ignore($user?->id)],
            'role' => ['required', Rule::in(User::ROLES)],
            'active' => ['nullable', 'boolean'],
            'domains' => ['nullable', 'array'],
            'domains.*' => ['integer', 'exists:domains,id'],
        ]);

        $data['email'] = strtolower($data['email']);
        $data['active'] = $request->boolean('active');
        // Super admins see every domain; assigning domains to them would be meaningless.
        if ($data['role'] === User::ROLE_SUPER) {
            $data['domains'] = [];
        }

        return $data;
    }
}
