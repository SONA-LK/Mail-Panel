<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Console\Command;

class ResetTwoFactor extends Command
{
    protected $signature = 'panel:2fa-reset {email : Google account email}';

    protected $description = 'Remove every second sign-in step (authenticator app, passkeys, recovery codes) for one person and sign them out. Use when the last super admin lost their phone and codes.';

    public function handle(): int
    {
        $user = User::where('email', strtolower($this->argument('email')))->first();

        if (! $user) {
            $this->error('No panel user has that email.');

            return self::FAILURE;
        }

        $user->resetTwoFactor();
        Audit::record(null, 'user.two_factor_reset', $user->email, null, ['via' => 'cli']);
        $this->info("{$user->email} was signed out everywhere and must set up a second step again at next sign-in.");

        return self::SUCCESS;
    }
}
