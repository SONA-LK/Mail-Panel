<?php

namespace App\Http\Controllers;

use App\Models\Passkey;
use App\Services\Audit;
use App\Services\Passkeys;
use App\Services\RecoveryCodes;
use App\Services\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** A person's own sign-in security: authenticator app, passkeys, recovery codes and where they are signed in. */
class SecurityController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('security.index', [
            'user' => $user,
            'hasTotp' => $user->hasTotp(),
            'passkeys' => $user->passkeys()->orderBy('id')->get(),
            'codesLeft' => app(RecoveryCodes::class)->remaining($user),
            'required' => $user->requiresTwoFactor(),
            'sessions' => $this->sessions($request),
            'sessionsSupported' => config('session.driver') === 'database',
            'newCodes' => session('recovery_codes'),
        ]);
    }

    // --- Authenticator app ---------------------------------------------------------------

    public function totpSetup(Request $request, Totp $totp)
    {
        $user = $request->user();
        if ($user->hasTotp()) {
            return redirect()->route('security.index');
        }

        $secret = $request->session()->get('totp.setup') ?: tap($totp->generateSecret(), fn ($s) => $request->session()->put('totp.setup', $s));
        $uri = $totp->uri($secret, $user->email, config('app.name'));

        return view('security.totp', ['secret' => trim(chunk_split($secret, 4, ' ')), 'qr' => $totp->qrDataUri($uri)]);
    }

    public function totpConfirm(Request $request, Totp $totp, RecoveryCodes $recovery)
    {
        $secret = $request->session()->get('totp.setup');
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:12']])['code'];

        if (! $secret || ($step = $totp->verify($secret, $code)) === null) {
            return back()->withErrors(['code' => 'That code did not match. Check the time on your phone and try the newest code.']);
        }

        $user = $request->user();
        $first = ! $user->hasTwoFactor();
        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now(), 'totp_last_step' => $step])->save();
        $request->session()->forget('totp.setup');
        Audit::record($user, 'security.totp_enabled', $user->email);

        $redirect = redirect()->route('security.index')->with('status', 'Authenticator app is on.');

        return $first ? $redirect->with('recovery_codes', $this->newCodes($user, $recovery)) : $redirect;
    }

    public function totpDestroy(Request $request)
    {
        $user = $request->user();
        if ($error = $this->cannotRemoveLast($user, ! $user->passkeys()->exists())) {
            return back()->withErrors(['totp' => $error]);
        }

        $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null])->save();
        $this->dropCodesWithoutFactor($user);
        Audit::record($user, 'security.totp_removed', $user->email);

        return back()->with('status', 'Authenticator app removed.');
    }

    // --- Passkeys ------------------------------------------------------------------------

    public function passkeyOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        $challenge = $passkeys->newChallenge();
        $request->session()->put('webauthn.challenge.register', $challenge);

        return response()->json(json_decode($passkeys->creationOptionsJson($request->user(), $challenge)));
    }

    public function passkeyStore(Request $request, Passkeys $passkeys, RecoveryCodes $recovery): JsonResponse
    {
        $user = $request->user();
        $challenge = $request->session()->pull('webauthn.challenge.register');
        $name = trim((string) $request->query('name', '')) ?: 'Passkey '.($user->passkeys()->count() + 1);

        if (! $challenge) {
            return response()->json(['error' => 'This step expired. Start again.'], 422);
        }
        if (mb_strlen($name) > 60 || preg_match('/[\x00-\x1f<>]/u', $name)) {
            return response()->json(['error' => 'Use a shorter name without special characters.'], 422);
        }

        $first = ! $user->hasTwoFactor();
        try {
            $passkey = $passkeys->register($user, $name, $request->getContent(), $challenge);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        Audit::record($user, 'security.passkey_added', $user->email, null, ['name' => $passkey->name]);
        $request->session()->flash('status', "Passkey \"{$passkey->name}\" added.");
        if ($first) {
            $request->session()->flash('recovery_codes', $this->newCodes($user, $recovery));
        }

        return response()->json(['ok' => true, 'redirect' => route('security.index')]);
    }

    public function passkeyDestroy(Request $request, Passkey $passkey)
    {
        $user = $request->user();
        abort_unless($passkey->user_id === $user->id, 404);

        $others = $user->passkeys()->whereKeyNot($passkey->id)->exists();
        if ($error = $this->cannotRemoveLast($user, ! $others && ! $user->hasTotp())) {
            return back()->withErrors(['passkey' => $error]);
        }

        $passkey->delete();
        $this->dropCodesWithoutFactor($user->fresh());
        Audit::record($user, 'security.passkey_removed', $user->email, null, ['name' => $passkey->name]);

        return back()->with('status', 'Passkey removed.');
    }

    // --- Recovery codes ------------------------------------------------------------------

    public function recoveryRegenerate(Request $request, RecoveryCodes $recovery)
    {
        $user = $request->user();
        if (! $user->hasTwoFactor()) {
            return back()->withErrors(['codes' => 'Turn on an authenticator app or a passkey first.']);
        }

        return redirect()->route('security.index')->with('recovery_codes', $this->newCodes($user, $recovery));
    }

    // --- Sessions ------------------------------------------------------------------------

    public function sessionDestroy(Request $request, string $handle)
    {
        $row = collect($this->sessions($request))->firstWhere('handle', $handle);
        abort_unless($row && ! $row['current'], 404);

        DB::table(config('session.table', 'sessions'))->where('id', $row['id'])->where('user_id', $request->user()->id)->delete();
        Audit::record($request->user(), 'security.session_revoked', $request->user()->email, null, ['ip' => $row['ip']]);

        return back()->with('status', 'That device was signed out.');
    }

    public function sessionsDestroyOthers(Request $request)
    {
        if (config('session.driver') === 'database') {
            $n = DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)
                ->where('id', '!=', $request->session()->getId())->delete();
            Audit::record($request->user(), 'security.sessions_revoked', $request->user()->email, null, ['count' => $n]);
        }

        return back()->with('status', 'Every other device was signed out.');
    }

    // --- helpers -------------------------------------------------------------------------

    private function newCodes($user, RecoveryCodes $recovery): array
    {
        Audit::record($user, 'security.recovery_codes_made', $user->email);

        return $recovery->generate($user);
    }

    /** Recovery codes only make sense next to a factor; once the last one is gone they would be a way in without one. */
    private function dropCodesWithoutFactor($user): void
    {
        if (! $user->hasTwoFactor()) {
            $user->recoveryCodes()->delete();
        }
    }

    /** A role that must have a second factor may not remove its last one. */
    private function cannotRemoveLast($user, bool $isLast): ?string
    {
        return $isLast && $user->requiresTwoFactor()
            ? 'Your role must keep a second sign-in step. Add another one before you remove this.'
            : null;
    }

    /** @return array<int, array{id:string,handle:string,ip:?string,agent:string,last:\Illuminate\Support\Carbon,current:bool}> */
    private function sessions(Request $request): array
    {
        if (config('session.driver') !== 'database') {
            return [];
        }

        return DB::table(config('session.table', 'sessions'))->where('user_id', $request->user()->id)->orderByDesc('last_activity')->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                // Opaque handle: the real session id is the login token and never goes into a page.
                'handle' => hash_hmac('sha256', $s->id, (string) config('app.key')),
                'ip' => $s->ip_address,
                'agent' => $this->describeAgent((string) $s->user_agent),
                'last' => \Illuminate\Support\Carbon::createFromTimestamp($s->last_activity),
                'current' => $s->id === $request->session()->getId(),
            ])->all();
    }

    private function describeAgent(string $ua): string
    {
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Browser',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'unknown system',
        };

        return "$browser on $os";
    }
}
