<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit;
use App\Services\Passkeys;
use App\Services\RecoveryCodes;
use App\Services\SignIn;
use App\Services\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Facades\Socialite;
use RuntimeException;

/**
 * The second step. Two contexts share one set of checks:
 *  - "login":   Google accepted this person, they now prove the second factor to get in.
 *  - "confirm": already signed in, proving it again before a sensitive change.
 * Wrong answers are counted per person, so guessing codes from many addresses does not help.
 */
class TwoFactorController extends Controller
{
    public function show(Request $request)
    {
        $context = $this->context($request);
        $user = $this->subject($request, $context);
        if (! $user) {
            return redirect()->route('login');
        }

        return view('security.challenge', [
            'context' => $context,
            'user' => $user,
            'hasTotp' => $user->hasTotp(),
            'hasPasskey' => $user->passkeys()->exists(),
            'action' => route($context === 'login' ? 'two-factor.code' : 'confirm.code'),
            'optionsUrl' => route($context === 'login' ? 'two-factor.passkey.options' : 'confirm.passkey.options'),
            'verifyUrl' => route($context === 'login' ? 'two-factor.passkey.verify' : 'confirm.passkey.verify'),
        ]);
    }

    public function code(Request $request, Totp $totp, RecoveryCodes $recovery): RedirectResponse
    {
        $context = $this->context($request);
        $user = $this->subject($request, $context);
        if (! $user) {
            return redirect()->route('login');
        }
        if ($locked = $this->lockedOut($user)) {
            return back()->withErrors(['code' => $locked]);
        }

        // Recovery codes only stand in for a second factor that exists. Without one there is nothing to recover.
        if (! $user->hasTwoFactor()) {
            return back()->withErrors(['code' => 'No second sign-in step is set up for this account.']);
        }

        $input = trim((string) $request->validate(['code' => ['required', 'string', 'max:32']])['code']);

        // A secret that can no longer be decrypted (APP_KEY changed) counts as a wrong code, never a server error.
        $secret = $user->hasTotp() ? rescue(fn () => $user->totp_secret, null, false) : null;
        if ($secret && ($step = $totp->verify($secret, $input, $user->totp_last_step)) !== null && $this->claimStep($user, $step)) {
            return $this->succeed($request, $context, $user, 'totp');
        }

        if (($left = $recovery->consume($user, $input)) !== null) {
            Audit::record($user, 'auth.recovery_code_used', $user->email, null, ['remaining' => $left]);
            $request->session()->flash('warning', $left === 0
                ? 'That was your last recovery code. Make new ones on the Security page now.'
                : "Recovery code accepted. {$left} left. Make new ones on the Security page if you are running low.");

            return $this->succeed($request, $context, $user, 'recovery_code');
        }

        return $this->wrong($user, 'code', 'That code is not right. Check the time on your phone and try the newest code.');
    }

    public function passkeyOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        $context = $this->context($request);
        $user = $this->subject($request, $context);
        if (! $user || ! $user->passkeys()->exists()) {
            return response()->json(['error' => 'No passkey is registered for this account.'], 422);
        }

        $challenge = $passkeys->newChallenge();
        $request->session()->put("webauthn.challenge.{$context}", $challenge);

        return response()->json(json_decode($passkeys->requestOptionsJson($user, $challenge)));
    }

    public function passkeyVerify(Request $request, Passkeys $passkeys): JsonResponse
    {
        $context = $this->context($request);
        $user = $this->subject($request, $context);
        // The challenge is single use: taken out of the session whether or not the answer is right.
        $challenge = $request->session()->pull("webauthn.challenge.{$context}");
        if (! $user || ! $challenge) {
            return response()->json(['error' => 'This sign-in step expired. Start again.'], 422);
        }
        if ($locked = $this->lockedOut($user)) {
            return response()->json(['error' => $locked], 429);
        }

        try {
            $passkeys->assert($user, $request->getContent(), $challenge);
        } catch (RuntimeException $e) {
            $this->wrong($user, 'passkey', $e->getMessage());

            return response()->json(['error' => $e->getMessage()], 422);
        }

        $redirect = $this->succeed($request, $context, $user, 'passkey');

        return response()->json(['ok' => true, 'redirect' => $redirect->getTargetUrl()]);
    }

    /** People without a second factor confirm by signing in with Google again. */
    public function google(Request $request): RedirectResponse
    {
        $request->session()->put('reauth', now()->timestamp);

        return Socialite::driver('google')->with(['prompt' => 'login', 'login_hint' => $request->user()->email])->redirect();
    }

    private function context(Request $request): string
    {
        return $request->routeIs('confirm.*') ? 'confirm' : 'login';
    }

    private function subject(Request $request, string $context): ?User
    {
        if ($context === 'confirm') {
            return $request->user();
        }

        $pending = $request->session()->get('two_factor');
        if (! $pending || now()->timestamp - $pending['at'] > config('panel.security.two_factor_pending_minutes') * 60) {
            $request->session()->forget('two_factor');

            return null;
        }

        $user = User::find($pending['id']);

        return $user?->active ? $user : null;
    }

    /** One atomic update decides who used a code step first, so two parallel requests cannot both pass with it. */
    private function claimStep(User $user, int $step): bool
    {
        return User::whereKey($user->id)
            ->where(fn ($q) => $q->whereNull('totp_last_step')->orWhere('totp_last_step', '<', $step))
            ->update(['totp_last_step' => $step]) === 1;
    }

    private function succeed(Request $request, string $context, User $user, string $method): RedirectResponse
    {
        foreach ($this->keys($user) as $key) {
            RateLimiter::clear($key);
        }

        if ($context === 'login') {
            SignIn::complete($request, $user, $method);

            return redirect()->intended(route('dashboard'));
        }

        $request->session()->put('confirmed_at', now()->timestamp);
        Audit::record($user, 'auth.confirm', $user->email, null, ['method' => $method]);

        return redirect()->intended(route('dashboard'));
    }

    private function wrong(User $user, string $field, string $message): RedirectResponse
    {
        [$here, $all] = $this->keys($user);
        RateLimiter::hit($here, config('panel.security.two_factor_lockout_minutes') * 60);
        RateLimiter::hit($all, 3600);
        Audit::record($user, 'auth.second_factor_failed', $user->email, null, ['via' => $field], 'denied');

        return back()->withErrors([$field => $message]);
    }

    private function lockedOut(User $user): ?string
    {
        [$here, $all] = $this->keys($user);
        $key = match (true) {
            RateLimiter::tooManyAttempts($here, config('panel.security.two_factor_max_failures')) => $here,
            RateLimiter::tooManyAttempts($all, config('panel.security.two_factor_total_failures')) => $all,
            default => null,
        };
        if ($key === null) {
            return null;
        }
        Audit::record($user, 'auth.second_factor_locked', $user->email, null, [], 'denied');

        return 'Too many wrong answers. Wait '.ceil(RateLimiter::availableIn($key) / 60).' minute(s), or ask a super admin to help.';
    }

    /**
     * Failures are counted per person and address, so someone guessing from their own address locks themselves
     * out and not the real owner. A much higher total per person (all addresses together) still stops a spread-out
     * guessing attempt.
     *
     * @return array{string,string}
     */
    private function keys(User $user): array
    {
        return ['second-factor:'.$user->id.':'.request()->ip(), 'second-factor-all:'.$user->id];
    }
}
