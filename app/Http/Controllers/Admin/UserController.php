<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index()
    {
        return view('admin.users.index', ['users' => User::with('domains')->orderBy('email')->get()]);
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

    public function update(Request $request, User $user)
    {
        $data = $this->validated($request, $user);

        // Never leave the panel without an active super admin.
        $stillSuper = $data['role'] === User::ROLE_SUPER && ($data['active'] ?? false);
        if ($user->isSuperAdmin() && ! $stillSuper && ! $this->otherActiveSuperAdmin($user)) {
            return back()->withInput()->withErrors(['role' => 'This is the last active super admin. Add another one first.']);
        }

        $user->update(collect($data)->except('domains')->all());
        $user->domains()->sync($data['domains'] ?? []);
        Audit::record($request->user(), 'user.update', $user->email, null, ['role' => $user->role, 'active' => $user->active, 'domains' => $data['domains'] ?? []]);

        return redirect()->route('admin.users.index')->with('status', 'User updated.');
    }

    public function destroy(Request $request, User $user)
    {
        if ($user->id === $request->user()->id) {
            return back()->withErrors(['user' => 'You cannot delete your own account.']);
        }
        if ($user->isSuperAdmin() && ! $this->otherActiveSuperAdmin($user)) {
            return back()->withErrors(['user' => 'This is the last active super admin.']);
        }

        Audit::record($request->user(), 'user.delete', $user->email);
        $user->delete();

        return redirect()->route('admin.users.index')->with('status', 'User removed.');
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
