<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Audit;
use Illuminate\Console\Command;

class MakeAdmin extends Command
{
    protected $signature = 'panel:make-admin {email : Google account email} {name? : Display name}';

    protected $description = 'Create (or promote) a super admin who can then sign in with Google. Use this for the first user.';

    public function handle(): int
    {
        $email = strtolower($this->argument('email'));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->fill([
            'name' => $user->name ?: ($this->argument('name') ?: $email),
            'role' => User::ROLE_SUPER,
            'active' => true,
        ])->save();

        Audit::record(null, 'user.make_admin', $email, null, ['via' => 'cli']);
        $this->info("$email is now a super admin and can sign in with Google.");

        return self::SUCCESS;
    }
}
