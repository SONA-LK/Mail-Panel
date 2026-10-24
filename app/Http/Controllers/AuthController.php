<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\SignIn;
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
        // Someone already signed in coming back from Google is confirming it is them, not signing in.
        if ($request->user()) {
            return $this->confirmed($request);
        }

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
            'name' => $user->name ?: ($google->getName() ?: $email),
        ])->save();

        // Google alone is not enough for someone who has a second factor: park them until they prove it.
        if ($user->hasTwoFactor()) {
            $request->session()->regenerate();
            $request->session()->put('two_factor', ['id' => $user->id, 'at' => now()->timestamp]);

            return redirect()->route('two-factor.challenge');
        }

        SignIn::complete($request, $user, 'google');

        return redirect()->intended(route('dashboard'));
    }

    /** Google confirmed the already signed-in person again (step-up for people without a second factor). */
    private function confirmed(Request $request): RedirectResponse
    {
        $user = $request->user();
        $asked = (int) $request->session()->pull('reauth', 0);

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            $google = null;
        }

        $same = $google && strtolower((string) $google->getEmail()) === $user->email && (! $user->google_id || $user->google_id === $google->getId());
        // Only a confirmation we asked for, a few minutes ago, counts.
        if (! $same || ! $asked || now()->timestamp - $asked > 600) {
            Audit::record($user, 'auth.confirm', $user->email, null, ['method' => 'google'], 'denied');

            return redirect()->route('confirm.show')->withErrors(['code' => 'Google could not confirm it is you. Try again.']);
        }

        $request->session()->put('confirmed_at', now()->timestamp);
        Audit::record($user, 'auth.confirm', $user->email, null, ['method' => 'google']);

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
