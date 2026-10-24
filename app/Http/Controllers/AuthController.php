<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/** Google is the only way in. A person must be added by a super admin first. */
class AuthController extends Controller
{
    public function login()
    {
        return view('auth.login');
    }

    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')->with(['prompt' => 'select_account'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            Audit::record(null, 'auth.failed', null, null, ['reason' => 'google_error'], 'denied');

            return redirect()->route('login')->withErrors(['login' => 'Google sign-in failed. Please try again.']);
        }

        $email = strtolower((string) $google->getEmail());
        $raw = (array) ($google->user ?? []);
        $verified = filter_var($raw['email_verified'] ?? $raw['verified_email'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $allowedDomain = config('services.google.allowed_domain');

        $user = User::where('email', $email)->first();

        $reason = match (true) {
            $email === '' || ! $verified => 'unverified_email',
            $allowedDomain && ! str_ends_with($email, '@'.strtolower($allowedDomain)) => 'wrong_workspace_domain',
            ! $user => 'not_approved',
            ! $user->active => 'inactive',
            $user->google_id && $user->google_id !== $google->getId() => 'google_id_mismatch',
            default => null,
        };

        if ($reason) {
            Audit::record($user, 'auth.failed', $email ?: null, null, ['reason' => $reason], 'denied', $email);

            // One message for every case, so the page does not reveal who is on the approved list.
            return redirect()->route('login')->withErrors(['login' => 'This Google account is not allowed to use the panel.']);
        }

        $user->forceFill([
            'google_id' => $google->getId(),
            'last_login_at' => now(),
            'name' => $user->name ?: ($google->getName() ?: $email),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();
        Audit::record($user, 'auth.login', $email);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Audit::record($request->user(), 'auth.logout', $request->user()?->email);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
