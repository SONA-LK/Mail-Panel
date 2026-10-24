<?php

namespace Tests\Feature;

use App\Mail\SecurityAlert;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit;
use App\Services\SecurityAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PanelFixtures;
use Tests\TestCase;

class SecurityAlertsTest extends TestCase
{
    use PanelFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['panel.security.alerts' => true]);
        Mail::fake();
    }

    public function test_super_admins_are_told_about_branding_changes_but_nobody_else(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $off = User::factory()->superAdmin()->create(['active' => false]);
        $reader = User::factory()->create();

        Audit::record($a, 'brand.update', 'brand #1', null, ['primary' => '#000000']);

        Mail::assertQueued(SecurityAlert::class, 2);
        Mail::assertQueued(SecurityAlert::class, fn ($m) => $m->hasTo($a->email) && $m->hasTo($b->email) === false);
        Mail::assertQueued(SecurityAlert::class, fn ($m) => $m->hasTo($b->email));
        Mail::assertNotQueued(SecurityAlert::class, fn ($m) => $m->hasTo($off->email) || $m->hasTo($reader->email));
    }

    public function test_the_same_event_is_not_mailed_twice_in_a_row(): void
    {
        $a = $this->admin();

        Audit::record($a, 'domain.remove', 'x.test');
        Audit::record($a, 'domain.remove', 'x.test');

        Mail::assertQueued(SecurityAlert::class, 1);
    }

    public function test_changing_your_own_sign_in_methods_also_tells_you(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        Audit::record($user, 'security.passkey_added', $user->email, null, ['name' => 'Phone']);

        Mail::assertQueued(SecurityAlert::class, fn ($m) => $m->hasTo($user->email));
        Mail::assertQueued(SecurityAlert::class, fn ($m) => $m->hasTo($admin->email));
    }

    public function test_adding_or_changing_a_super_admin_alerts(): void
    {
        $admin = $this->admin();

        Audit::record($admin, 'user.create', 'new@example.com', null, ['role' => 'super_admin']);
        Mail::assertQueued(SecurityAlert::class, fn ($m) => str_contains($m->headline, 'super admin was added'));

        Audit::record($admin, 'user.create', 'reader@example.com', null, ['role' => 'read_only']);
        Mail::assertQueued(SecurityAlert::class, 1);
    }

    public function test_a_burst_of_denied_sign_ins_raises_one_alert(): void
    {
        $this->admin();

        foreach (range(1, 4) as $i) {
            Audit::record(null, 'auth.failed', "x{$i}@example.com", null, [], 'denied', "x{$i}@example.com");
        }
        Mail::assertNothingQueued();

        foreach (range(5, 9) as $i) {
            Audit::record(null, 'auth.failed', "x{$i}@example.com", null, [], 'denied', "x{$i}@example.com");
        }
        Mail::assertQueued(SecurityAlert::class, 1);
        Mail::assertQueued(SecurityAlert::class, fn ($m) => str_contains($m->headline, 'denied sign-ins'));
    }

    private function login(User $user, string $ip, string $when = 'now'): AuditLog
    {
        return AuditLog::create(['user_id' => $user->id, 'user_email' => $user->email, 'action' => 'auth.login', 'result' => 'success', 'ip' => $ip, 'created_at' => now()->modify($when)]);
    }

    public function test_a_sign_in_from_a_new_address_alerts_the_person(): void
    {
        $user = User::factory()->create();
        $this->login($user, '198.51.100.1', '-3 days');

        SecurityAlerts::consider($this->login($user, '203.0.113.7'));

        Mail::assertQueued(SecurityAlert::class, 1);
        Mail::assertQueued(SecurityAlert::class, fn ($m) => $m->hasTo($user->email) && $m->headline === 'Sign-in from a new address');
    }

    public function test_the_first_sign_in_and_known_addresses_do_not_alert(): void
    {
        $user = User::factory()->create();

        SecurityAlerts::consider($this->login($user, '203.0.113.7'));          // first ever
        $this->login($user, '198.51.100.1', '-3 days');
        SecurityAlerts::consider($this->login($user, '198.51.100.1'));        // known
        $this->login($user, '192.0.2.4', '-200 days');
        SecurityAlerts::consider($this->login($user, '192.0.2.4'));           // only seen long ago, but there was a recent login elsewhere

        Mail::assertQueued(SecurityAlert::class, 1);   // only the long-unused address counts as new
    }

    public function test_a_new_address_for_a_super_admin_also_tells_the_other_admins(): void
    {
        $a = $this->admin();
        $b = $this->admin();
        $this->login($a, '198.51.100.1', '-1 day');

        SecurityAlerts::consider($this->login($a, '203.0.113.7'));

        Mail::assertQueued(SecurityAlert::class, fn ($m) => $m->hasTo($a->email));
        Mail::assertQueued(SecurityAlert::class, fn ($m) => $m->hasTo($b->email));
    }

    public function test_alerts_can_be_switched_off(): void
    {
        config(['panel.security.alerts' => false]);
        $a = $this->admin();

        Audit::record($a, 'brand.update', 'brand #1');

        Mail::assertNothingQueued();
    }

    public function test_a_mail_problem_never_breaks_the_audited_action(): void
    {
        $a = $this->admin();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $log = Audit::record($a, 'brand.update', 'brand #1');

        $this->assertNotNull($log->id);
    }

    public function test_the_alert_email_is_plain_text_and_keeps_special_characters(): void
    {
        $mail = new SecurityAlert('Tom & Jerry <test>', ['Event' => 'x', 'Details' => '{"a":"b & c"}'], 'https://panel.test/audit');

        $text = $mail->render();

        $this->assertStringContainsString('Tom & Jerry <test>', $text);
        $this->assertStringContainsString('{"a":"b & c"}', $text);
        $this->assertStringContainsString('https://panel.test/audit', $text);
        $this->assertStringContainsString('If it was not you', $text);
    }
}
