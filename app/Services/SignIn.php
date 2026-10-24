<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The one place a person becomes signed in, whether Google alone was enough or a second step followed. */
class SignIn
{
    public static function complete(Request $request, User $user, string $method): void
    {
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('two_factor');

        $now = now()->timestamp;
        $request->session()->put('login_at', $now);
        // Signing in counts as a fresh confirmation for sensitive changes.
        $request->session()->put('confirmed_at', $now);

        $user->forceFill(['last_login_at' => now()])->save();
        Audit::record($user, 'auth.login', $user->email, null, ['method' => $method]);
    }
}
